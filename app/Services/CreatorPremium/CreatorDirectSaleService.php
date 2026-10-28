<?php

namespace App\Services\CreatorPremium;

use App\Models\CreatorDirectSale;
use App\Models\CreatorEarningAccount;
use App\Models\CreatorEarningEntry;
use App\Models\CreatorSeries;
use App\Models\CreatorSeriesSaleOffer;
use App\Models\DigitalPurchase;
use App\Models\User;
use App\Services\CreatorFinance\CreatorFinanceException;
use App\Services\CreatorFinance\CreatorLedgerService;
use App\Services\OperationalAuditService;
use Illuminate\Support\Facades\DB;

/**
 * E43 - البيع المباشر المحاسبي: يربط شراء E41 المؤكَّد بقيد دفتر E39 (direct_sale_credit) وبلقطة CreatorDirectSale الثابتة، وينعكس عند الاسترداد (direct_sale_reversal) **بأمان**.
 *
 * القواعد الثابتة:
 *  - لا يُستدعى record/reverseForPurchase إلا داخل معاملة والطلب والحساب **مقفولان** (معالج المنتج يقفلهما بالترتيب: الطلب ← الحساب ← العرض). الدفتر نفسه يرفض الاستدعاء خارج معاملة.
 *  - الإجمالي = المبلغ **المدفوع فعلًا** في لقطة الطلب (لا سعر العرض الحالي)، والعملة = عملة حساب المبدع حرفيًا (الخيار A؛ لا تحويل).
 *  - قسمة صحيحة بنقاط الأساس بقاعدة creator_floor (CreatorPremiumPolicy::split)، فمجموع الحصتين = الإجمالي دائمًا. حصة المنصة لا تُقيَّد في دفتر المبدع (ليست رصيدًا له).
 *  - مفاتيح الدفتر الدلالية (direct_sale:{purchase_id}:credit|reversal) + قيود فريدة بالقاعدة ⇒ لا قيد مكرر مهما تكرر التحقق/الاسترداد/الـwebhook.
 *  - العكس: إن كان المتاح يكفي حصة المبدع يُقيَّد direct_sale_reversal (إلحاقي، يخصم من المتاح فقط). إن لم يكفِ (دُفع جزء منها أو حُجز أو صُرف) **لا يُنشأ رصيد سالب ولا يُحذف شيء ولا تُصادَر دفعة**:
 *    يُعلَّم البيع needs_finance_review مع سبب وتدقيق، ويبقى استرداد المزوّد معترفًا به كاملًا (لا يُفشَل ولا يُفقد). القرار البشري لاحقًا: إعادة المحاولة (حين يتوفر المتاح) أو تحميل المنصة (write-off).
 *  - لا مكافأة ولا نقاط ولا خبرة ولا سمعة: دفتر الأرباح وحده.
 */
class CreatorDirectSaleService
{
    public function __construct(
        protected CreatorLedgerService $ledger,
        protected CreatorPremiumPolicy $policy,
        protected OperationalAuditService $audit,
    ) {}

    public static function creditKey(int $purchaseId): string
    {
        return 'direct_sale:'.$purchaseId.':credit';
    }

    public static function reversalKey(int $purchaseId): string
    {
        return 'direct_sale:'.$purchaseId.':reversal';
    }

    /**
     * يسجّل البيع والقيد (idempotent: صف قائم لهذا الشراء يُعاد كما هو). المتطلب: داخل معاملة، والطلب والحساب مقفولان.
     *
     * @throws CreatorPremiumException currency_mismatch | share_too_small | share_policy_invalid
     */
    public function record(DigitalPurchase $purchase, CreatorSeries $series, CreatorSeriesSaleOffer $offer, CreatorEarningAccount $lockedAccount): CreatorDirectSale
    {
        $existing = CreatorDirectSale::query()->where('digital_purchase_id', $purchase->getKey())->first();

        if ($existing !== null) {
            return $existing;
        }

        if ((int) $series->creator_profile_id !== (int) $lockedAccount->creator_profile_id || (int) $offer->creator_profile_id !== (int) $lockedAccount->creator_profile_id) {
            throw new CreatorPremiumException('creator_mismatch', 'حساب الأرباح لا يخص مبدع هذه السلسلة.');
        }

        if ((string) $purchase->currency !== (string) $lockedAccount->currency) {
            throw new CreatorPremiumException('currency_mismatch', 'عملة الدفع لا تطابق عملة حساب أرباح المبدع.');
        }

        $split = $this->policy->split((int) $purchase->amount_minor);

        if ($split['creator_minor'] < 1) {
            throw new CreatorPremiumException('share_too_small', 'حصة المبدع من هذا المبلغ أقل من أصغر وحدة.');
        }

        $entry = $this->ledger->post($lockedAccount, CreatorEarningEntry::TYPE_DIRECT_SALE_CREDIT, $split['creator_minor'], 0, 0, self::creditKey((int) $purchase->getKey()));

        $creatorName = (string) User::query()->whereKey($lockedAccount->user_id)->value('name');

        $sale = new CreatorDirectSale;
        $sale->forceFill([
            'digital_purchase_id' => $purchase->getKey(),
            'creator_profile_id' => $lockedAccount->creator_profile_id,
            'user_id' => $lockedAccount->user_id,
            'creator_series_id' => $series->getKey(),
            'creator_series_sale_offer_id' => $offer->getKey(),
            'creator_earning_account_id' => $lockedAccount->getKey(),
            'series_public_id' => $series->public_id,
            'series_title' => mb_substr((string) ($series->published_title ?: $purchase->product_name), 0, 160),
            'creator_display_name' => mb_substr($creatorName, 0, 120),
            'gross_minor' => (int) $purchase->amount_minor,
            'creator_share_minor' => $split['creator_minor'],
            'platform_share_minor' => $split['platform_minor'],
            'currency' => (string) $purchase->currency,
            'creator_bps' => $split['creator_bps'],
            'platform_bps' => $split['platform_bps'],
            'rounding_rule' => $split['rule'],
            'status' => CreatorDirectSale::STATUS_CREDITED,
            'credit_entry_id' => $entry->getKey(),
            'sold_at' => now(),
        ])->save();

        return $sale;
    }

    /**
     * عند استرداد الشراء (من مسار E41 المركزي، والطلب مقفول): يعكس حصة المبدع إن كان ذلك آمنًا وإلا يعلّم المراجعة المالية. **لا يرمي** أبدًا بسبب الرصيد (استرداد المزوّد حقيقة قائمة).
     *
     * @return 'none'|'reversed'|'needs_review'|'already'
     */
    public function reverseForPurchase(DigitalPurchase $locked, ?User $actor = null): string
    {
        // حساب المبدع يُحدَّد قبل القفل **دون الاعتماد على رؤية لقطة البيع**: القراءة غير القافلة تستعمل لقطة المعاملة (REPEATABLE READ) التي قد تسبق commit بيعٍ متزامن للطلب نفسه
        // (حُسم ترتيبهما بقفل الطلب)، فتبدو «لا بيع» والبيع قائم ⇒ حصة غير معكوسة وبلا علم مراجعة. لذا نشتق الحساب من السلسلة (ثابت لا يتغير) ثم نقرأ البيع **قراءة حالية** قافلة.
        $accountId = CreatorDirectSale::query()->where('digital_purchase_id', $locked->getKey())->value('creator_earning_account_id')
            ?? CreatorEarningAccount::query()->where('creator_profile_id', CreatorSeries::query()->whereKey($locked->product_id)->value('creator_profile_id'))->value('id');

        if ($accountId === null) {
            return 'none';
        }

        // الترتيب: الحساب ← صف البيع (كما في الإنشاء).
        $account = $this->ledger->lockAccount((int) $accountId);
        $sale = CreatorDirectSale::query()->where('digital_purchase_id', $locked->getKey())->lockForUpdate()->first();

        if ($sale === null) {
            return 'none';
        }

        if ($sale->status !== CreatorDirectSale::STATUS_CREDITED) {
            return $sale->needsReview() ? 'needs_review' : 'already';
        }

        try {
            $entry = $this->postReversal($sale, $account, $actor);
        } catch (CreatorFinanceException $e) {
            $sale->forceFill([
                'status' => CreatorDirectSale::STATUS_NEEDS_REVIEW,
                'refunded_at' => now(),
                'review_flagged_at' => now(),
                'review_reason' => CreatorDirectSale::REVIEW_REVERSAL_BLOCKED,
            ])->save();

            $this->audit->log('creator_direct_sale_needs_review', $sale, [
                'purchase_id' => $sale->digital_purchase_id,
                'creator_share_minor' => $sale->creator_share_minor,
                'currency' => $sale->currency,
                'available_minor' => (int) $account->available_minor,
                'reserved_minor' => (int) $account->reserved_minor,
                'reason' => CreatorDirectSale::REVIEW_REVERSAL_BLOCKED,
            ], $actor);

            return 'needs_review';
        }

        $sale->forceFill([
            'status' => CreatorDirectSale::STATUS_REFUNDED,
            'reversal_entry_id' => $entry->getKey(),
            'refunded_at' => now(),
        ])->save();

        $this->audit->log('creator_direct_sale_reversed', $sale, ['purchase_id' => $sale->digital_purchase_id, 'creator_share_minor' => $sale->creator_share_minor, 'currency' => $sale->currency], $actor);

        return 'reversed';
    }

    /**
     * إدارة: إعادة محاولة عكس بيع معلَّق للمراجعة (بعد أن صار المتاح يكفي). فشل الرصيد يُرجَع للمشغّل كخطأ مقروء دون أي تغيير.
     *
     * @throws CreatorPremiumException forbidden | not_found | invalid_state | still_blocked
     */
    public function retryReversal(User $admin, string $salePublicId): CreatorDirectSale
    {
        $this->authorize($admin);

        return DB::transaction(function () use ($admin, $salePublicId): CreatorDirectSale {
            $sale = $this->saleByPublicId($salePublicId);
            $account = $this->ledger->lockAccount((int) $sale->creator_earning_account_id);
            $sale = CreatorDirectSale::query()->whereKey($sale->getKey())->lockForUpdate()->firstOrFail();

            $this->assertNotOwn($admin, $account);

            if (! $sale->needsReview()) {
                throw new CreatorPremiumException('invalid_state', 'هذا البيع ليس بانتظار مراجعة مالية.');
            }

            try {
                $entry = $this->postReversal($sale, $account, $admin);
            } catch (CreatorFinanceException) {
                throw new CreatorPremiumException('still_blocked', 'الرصيد المتاح للمبدع ما زال لا يكفي لعكس الحصة. لم يتغير شيء.');
            }

            $sale->forceFill([
                'status' => CreatorDirectSale::STATUS_REFUNDED,
                'reversal_entry_id' => $entry->getKey(),
                'review_resolved_at' => now(),
                'review_resolved_by' => $admin->getKey(),
                'review_resolution' => 'reversed',
            ])->save();

            $this->audit->log('creator_direct_sale_reversal_retried', $sale, ['purchase_id' => $sale->digital_purchase_id, 'creator_share_minor' => $sale->creator_share_minor, 'currency' => $sale->currency], $admin);

            return $sale;
        });
    }

    /**
     * إدارة: تحميل المنصة قيمة الاسترداد (لا عكس ولا خصم من المبدع). قرار نهائي موثَّق بملاحظة؛ لا حركة دفتر.
     *
     * @throws CreatorPremiumException forbidden | not_found | invalid_state | note_invalid
     */
    public function writeOff(User $admin, string $salePublicId, string $note): CreatorDirectSale
    {
        $this->authorize($admin);

        $note = trim($note);
        $max = (int) config('creator_premium.limits.note_max', 300);

        if (mb_strlen($note) < 5 || mb_strlen($note) > $max) {
            throw new CreatorPremiumException('note_invalid', "الملاحظة مطلوبة (5–{$max} حرفًا).");
        }

        return DB::transaction(function () use ($admin, $salePublicId, $note): CreatorDirectSale {
            $sale = $this->saleByPublicId($salePublicId);
            $account = $this->ledger->lockAccount((int) $sale->creator_earning_account_id);
            $sale = CreatorDirectSale::query()->whereKey($sale->getKey())->lockForUpdate()->firstOrFail();

            $this->assertNotOwn($admin, $account);

            if (! $sale->needsReview()) {
                throw new CreatorPremiumException('invalid_state', 'هذا البيع ليس بانتظار مراجعة مالية.');
            }

            $sale->forceFill([
                'status' => CreatorDirectSale::STATUS_WRITTEN_OFF,
                'review_resolved_at' => now(),
                'review_resolved_by' => $admin->getKey(),
                'review_resolution' => 'written_off',
                'review_note' => $note,
            ])->save();

            $this->audit->log('creator_direct_sale_written_off', $sale, ['purchase_id' => $sale->digital_purchase_id, 'creator_share_minor' => $sale->creator_share_minor, 'currency' => $sale->currency], $admin);

            return $sale;
        });
    }

    /** قيد العكس داخل savepoint: أي فشل (رصيد غير كافٍ) لا يترك أثرًا ولا يُفسد المعاملة الخارجية. @throws CreatorFinanceException */
    protected function postReversal(CreatorDirectSale $sale, CreatorEarningAccount $lockedAccount, ?User $actor): CreatorEarningEntry
    {
        return DB::transaction(fn () => $this->ledger->post(
            $lockedAccount,
            CreatorEarningEntry::TYPE_DIRECT_SALE_REVERSAL,
            -1 * (int) $sale->creator_share_minor,
            0,
            0,
            self::reversalKey((int) $sale->digital_purchase_id),
            ['actor_id' => $actor?->getKey()],
        ));
    }

    protected function saleByPublicId(string $publicId): CreatorDirectSale
    {
        $sale = CreatorDirectSale::query()->where('public_id', $publicId)->first();

        if ($sale === null) {
            throw new CreatorPremiumException('not_found', 'سجل البيع غير موجود.');
        }

        return $sale;
    }

    protected function authorize(User $admin): void
    {
        if (! $admin->can('creator-premium.refund')) {
            throw new CreatorPremiumException('forbidden', 'لا تملك صلاحية تنفيذ هذا الإجراء.');
        }
    }

    /** لا يعالج أحدٌ بيعًا يخص حسابه المالي الخاص (نفس قاعدة E39). */
    protected function assertNotOwn(User $admin, CreatorEarningAccount $account): void
    {
        if ((int) $admin->getKey() === (int) $account->user_id) {
            throw new CreatorPremiumException('own_account', 'لا يجوز لك معالجة حسابك المالي الخاص.');
        }
    }
}
