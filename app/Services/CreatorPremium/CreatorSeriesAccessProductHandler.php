<?php

namespace App\Services\CreatorPremium;

use App\Models\CreatorDirectSale;
use App\Models\CreatorEarningAccount;
use App\Models\CreatorSeries;
use App\Models\CreatorSeriesAccess;
use App\Models\CreatorSeriesSaleOffer;
use App\Models\DigitalPurchase;
use App\Models\User;
use App\Services\Commerce\CommerceFulfillmentException;
use App\Services\Commerce\CommerceProductHandler;
use App\Services\CreatorFinance\CreatorFinanceException;
use App\Services\CreatorSeries\CreatorSeriesVisibilityService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * E43 - معالج منتج «وصول سلسلة مبدع مميزة» (creator_series_access) في نواة التجارة E41. هذا هو **المنطق الوحيد** الذي يمنح/يسحب الوصول المدفوع ويقيّد حصة المبدع،
 * تستدعيه نواة التجارة (DigitalPurchaseService) بمسار المزوّد الموثوق نفسه الذي يخدم كل المنتجات: لا مسار موازٍ ولا محرك استرداد ثانٍ.
 *
 * معاملة التحقق الواحدة (تحدث مرة واحدة بالضبط): الطلب مقفول (E41) ← حساب المبدع ← العرض، ثم داخل savepoint واحدة: [الوصول + قيد الدفتر direct_sale_credit + لقطة CreatorDirectSale].
 * ثم تثبّت النواة حالة الطلب verified في المعاملة نفسها. أي فشل في أي جزء يُرجع الـsavepoint كلها (لا وصول بلا قيد ولا قيد بلا وصول) ويتحول إلى CommerceFulfillmentException
 * فيعلّم مسار الـwebhook الطلب fulfillment_blocked للمراجعة المالية (المال وصل: لا فتح صامت ولا ضياع).
 *
 * تُمنع (مراجعة بدل منح): سلسلة غير موجودة/غير ظاهرة، مشترٍ مجمَّد، المبدع نفسه، وصول فعّال قائم (دفع مكرر)، تسييل المبدع غير مفعَّل (لا استحقاق يُقيَّد وهو موقوف)،
 * عرض غير نشط (أُوقف/أُغلق بعد إنشاء الطلب)، عملة الدفع لا تطابق عملة حساب المبدع (الخيار A). مفتاح CREATOR_PREMIUM_ENABLED لا يمنع تحقق طلب دُفع بالفعل.
 *
 * الاسترداد: يسحب الوصول (revoked_at، لا حذف) ويعكس حصة المبدع بأمان أو يعلّم needs_finance_review (CreatorDirectSaleService). لا يمنع استرداد المزوّد أبدًا.
 */
class CreatorSeriesAccessProductHandler implements CommerceProductHandler
{
    public function __construct(
        protected CreatorDirectSaleService $sales,
        protected CreatorPremiumPolicy $policy,
        protected CreatorSeriesVisibilityService $visibility,
        protected CreatorPremiumNotifier $notifier,
    ) {}

    public function productType(): string
    {
        return DigitalPurchase::PRODUCT_CREATOR_SERIES;
    }

    public function fulfill(DigitalPurchase $locked): void
    {
        $series = CreatorSeries::query()->with('creator:id,user_id')->find($locked->product_id);
        $buyer = User::query()->find($locked->user_id);

        if ($series === null || $buyer === null || (string) $series->public_id !== (string) $locked->product_public_id) {
            throw new CommerceFulfillmentException('series_missing', 'السلسلة المرتبطة بالطلب غير موجودة.');
        }

        if ($buyer->is_frozen) {
            throw new CommerceFulfillmentException('frozen', 'حساب المشتري مقيَّد؛ لا يُفتح الوصول له. راجع الحالة قبل أي قرار.');
        }

        if ((int) $series->creator->user_id === (int) $buyer->getKey()) {
            throw new CommerceFulfillmentException('own_series', 'المشتري هو مبدع السلسلة نفسه؛ راجع الطلب كخطأ.');
        }

        if (! $this->visibility->isVisible($series)) {
            throw new CommerceFulfillmentException('series_unavailable', 'السلسلة غير ظاهرة حاليًا (إخفاء/أرشفة/إشراف)؛ راجع الطلب قبل أي قرار.');
        }

        // الترتيب الثابت: الطلب (مقفول من النواة) ← حساب المبدع ← العرض.
        $account = CreatorEarningAccount::query()->where('creator_profile_id', $series->creator_profile_id)->lockForUpdate()->first();
        $offer = CreatorSeriesSaleOffer::query()->where('live_series_id', $series->getKey())->lockForUpdate()->first();

        if ($account === null || ! $account->isEnabled()) {
            throw new CommerceFulfillmentException('monetization_off', 'تسييل المبدع غير مفعَّل أو موقوف؛ لا يمكن قيد الحصة. راجع الطلب (استرداد غالبًا).');
        }

        if ($offer === null || ! $offer->isActive()) {
            throw new CommerceFulfillmentException('not_for_sale', 'عرض البيع لم يعد نشطًا؛ راجع الطلب (استرداد غالبًا).');
        }

        if ((string) $locked->currency !== (string) $account->currency) {
            throw new CommerceFulfillmentException('currency_mismatch', 'عملة الدفع لا تطابق عملة حساب أرباح المبدع؛ راجع الطلب.');
        }

        // قراءة حالية قافلة (لا لقطة معاملة قديمة): حساب المبدع مقفول أعلاه فكل منح/عكس لسلاسله متسلسل، فلا تعارض أقفال ولا تفعيل مزدوج لصف مسحوب.
        $existing = CreatorSeriesAccess::query()->where('user_id', $buyer->getKey())->where('creator_series_id', $series->getKey())->lockForUpdate()->first();

        if ($existing !== null && $existing->isActive()) {
            throw new CommerceFulfillmentException('already_owned', 'يملك المشتري الوصول بالفعل؛ راجع الطلب كدفع مكرر.');
        }

        try {
            // savepoint: الوصول + القيد + لقطة البيع كلٌّ أو لا شيء، دون إفساد معاملة النواة الخارجية.
            DB::transaction(function () use ($locked, $series, $offer, $account, $buyer, $existing): void {
                $this->grant($locked, $series, $buyer, $existing);
                $this->sales->record($locked, $series, $offer, $account);
            });
        } catch (CreatorPremiumException $e) {
            throw new CommerceFulfillmentException($e->reason, $e->getMessage());
        } catch (CreatorFinanceException $e) {
            throw new CommerceFulfillmentException('ledger_rejected', $e->getMessage());
        } catch (UniqueConstraintViolationException) {
            throw new CommerceFulfillmentException('already_owned', 'يملك المشتري الوصول بالفعل أو سُجّل هذا الشراء سابقًا؛ راجع الطلب.');
        }
    }

    /**
     * ما استهلكه المشتري فعلًا: عدد أحجيات السلسلة (نسختها المنشورة) التي بدأ لعبها **بعد** منح هذا الوصول (محاولات مسجَّلة). المحاولات السابقة للشراء لا تُحسب.
     * يُستخدم كسياسة نواة E41 للاسترداد: قيمة > 0 تستلزم تأكيدًا صريحًا من المراجع المالي (لا سحب صامت لما استُهلك). لا كتابة هنا.
     */
    public function claimedItemCount(DigitalPurchase $locked): int
    {
        $access = CreatorSeriesAccess::query()->where('source_digital_purchase_id', $locked->getKey())->first();

        if ($access === null || ! $access->isActive()) {
            return 0;
        }

        return (int) DB::table('creator_series as s')
            ->join('creator_series_items as i', 'i.creator_series_revision_id', '=', 's.published_revision_id')
            ->join('community_puzzle_attempts as a', 'a.creator_puzzle_id', '=', 'i.creator_puzzle_id')
            ->where('s.id', $access->creator_series_id)
            ->where('a.user_id', $access->user_id)
            ->where('a.created_at', '>=', $access->granted_at)
            ->distinct()
            ->count('a.creator_puzzle_id');
    }

    public function reverse(DigitalPurchase $locked): void
    {
        // الحساب ← البيع أولًا (كترتيب الإنشاء)، ثم صف الوصول. لا يرمي بسبب الرصيد (استرداد المزوّد حقيقة قائمة).
        $result = $this->sales->reverseForPurchase($locked);

        $access = CreatorSeriesAccess::query()->where('source_digital_purchase_id', $locked->getKey())->lockForUpdate()->first();

        if ($access !== null && $access->isActive()) {
            $access->forceFill(['revoked_at' => now(), 'revoke_reason' => CreatorSeriesAccess::REVOKE_REFUND])->save();
        }

        if ($result === 'reversed') {
            $saleId = CreatorDirectSale::query()->where('digital_purchase_id', $locked->getKey())->value('id');

            if ($saleId !== null) {
                DB::afterCommit(function () use ($saleId): void {
                    $sale = CreatorDirectSale::query()->find($saleId);

                    if ($sale !== null) {
                        $this->notifier->saleAdjusted($sale);
                    }
                });
            }
        }
    }

    public function notifyVerified(DigitalPurchase $purchase): void
    {
        $this->notifier->accessGranted($purchase);

        $sale = CreatorDirectSale::query()->where('digital_purchase_id', $purchase->getKey())->first();

        if ($sale !== null) {
            $this->notifier->saleRecorded($sale);
        }
    }

    /** منح جديد أو إعادة تفعيل صف مسحوب (بعد استرداد) بمصدر الشراء الجديد: لا صفوف مكررة، granted_at الجديد هو أساس «المستهلَك». */
    protected function grant(DigitalPurchase $purchase, CreatorSeries $series, User $buyer, ?CreatorSeriesAccess $existing): CreatorSeriesAccess
    {
        if ($existing === null) {
            $access = new CreatorSeriesAccess;
            $access->forceFill([
                'user_id' => $buyer->getKey(),
                'creator_series_id' => $series->getKey(),
                'source_digital_purchase_id' => $purchase->getKey(),
                'granted_at' => now(),
                'grant_count' => 1,
            ])->save();

            return $access;
        }

        $access = CreatorSeriesAccess::query()->whereKey($existing->getKey())->lockForUpdate()->firstOrFail();

        if ($access->isActive()) {
            throw new CreatorPremiumException('already_owned', 'يملك المشتري الوصول بالفعل.');
        }

        $access->forceFill([
            'source_digital_purchase_id' => $purchase->getKey(),
            'granted_at' => now(),
            'revoked_at' => null,
            'revoke_reason' => null,
            'grant_count' => (int) $access->grant_count + 1,
        ])->save();

        return $access;
    }
}
