<?php

namespace App\Services\CreatorPremium;

use App\Models\CreatorDirectSale;
use App\Models\DigitalPurchase;
use App\Models\User;
use App\Services\Commerce\CommerceException;
use App\Services\Commerce\CommerceNotifier;
use App\Services\Commerce\CommerceProviderState;
use App\Services\Commerce\CommerceRefundService;
use App\Services\Commerce\DigitalPurchaseService;
use App\Services\OperationalAuditService;
use Illuminate\Support\Facades\DB;

/**
 * E43 - عمليات الإدارة على مشتريات السلاسل المميزة. **لا محرك استرداد ثانٍ**: كلها تمرّ عبر نواة E41 (CommerceRefundService / DigitalPurchaseService) التي تستدعي معالج المنتج؛ هذه الخدمة تضيف فقط:
 * الصلاحية (creator-premium.*)، حصر النوع (مشتريات السلاسل فقط)، أسباب/ملاحظة موثَّقة، منع معالجة شراء يخص الإداري نفسه (مشترٍ أو مبدع بائع)، وصياغة رسائل السلسلة.
 *
 * نتيجة الاسترداد المؤكَّد (يحدث داخل معاملة واحدة مع حالة الطلب refunded): سحب الوصول (revoked_at) + عكس حصة المبدع بأمان أو علم needs_finance_review (CreatorDirectSaleService) — دون أن يُفشِل ذلك استرداد المزوّد.
 * استهلاك المشتري بعد الشراء (محاولات على أحجيات السلسلة) يستلزم تأكيدًا صريحًا (سياسة E41 claimedItemCount) ولا يُسحب آليًا في صمت.
 */
class CreatorPremiumRefundService
{
    public function __construct(
        protected CommerceRefundService $refunds,
        protected DigitalPurchaseService $core,
        protected CommerceNotifier $notifier,
        protected OperationalAuditService $audit,
    ) {}

    /** @throws CreatorPremiumException */
    public function refund(User $actor, string $purchasePublicId, string $reason, string $note, bool $confirmClaimed = false): DigitalPurchase
    {
        $this->authorize($actor, 'creator-premium.refund');

        if (! array_key_exists($reason, (array) config('creator_premium.refund.reasons', []))) {
            throw new CreatorPremiumException('invalid_reason', 'سبب الاسترداد غير صالح.');
        }

        $clean = $this->cleanNote($note, 'اكتب ملاحظة الاسترداد (5 أحرف على الأقل) للتوثيق.');
        $purchase = $this->seriesPurchase($purchasePublicId);
        $this->assertNotParty($actor, $purchase);

        $manualPath = false;

        try {
            if ($this->refunds->supports($purchase)) {
                // المزوّد أكّد الاسترداد فعلًا (حدث/مزامنة) والطلب محقَّق بسبب استهلاك: تسجيل محلي بقرار صريح بلا نداء مزوّد ثانٍ.
                $result = $purchase->providerState() === CommerceProviderState::Refunded
                    ? $this->refunds->recordProviderRefunded($actor, $purchase, $reason, $clean, $confirmClaimed)
                    : $this->refunds->refundVerified($actor, $purchase, $reason, $clean, $confirmClaimed);
            } else {
                $manualPath = true;
                $result = DB::transaction(function () use ($actor, $purchase, $reason, $clean, $confirmClaimed) {
                    $locked = $this->core->lock($purchase);
                    $this->core->refundLocked($locked, $actor, $reason, $clean, $confirmClaimed, 'manual');

                    return $locked;
                });
            }
        } catch (CommerceException $e) {
            throw $this->translate($e);
        }

        if ($manualPath && $result->status === DigitalPurchase::STATUS_REFUNDED) {
            $this->notifier->refunded($result);
        }

        $this->audit->log('creator_premium_refund_requested', $purchase, ['reference' => $purchase->reference_code, 'reason' => $reason, 'confirmed' => $confirmClaimed, 'result_status' => $result->status], $actor);

        return $result;
    }

    /** دفعة غير مطابقة/متأخرة (علم مراجعة قائم والطلب ليس محقَّقًا): استرداد عبر المزوّد ثم إغلاق العلم. @throws CreatorPremiumException */
    public function refundUnmatched(User $actor, string $purchasePublicId, string $note): DigitalPurchase
    {
        $this->authorize($actor, 'creator-premium.refund');
        $clean = $this->cleanNote($note, 'اكتب ملاحظة الاسترداد (5 أحرف على الأقل) للتوثيق.');
        $purchase = $this->seriesPurchase($purchasePublicId);
        $this->assertNotParty($actor, $purchase);

        try {
            $result = $this->refunds->refundUnmatched($actor, $purchase, $clean);
        } catch (CommerceException $e) {
            throw $this->translate($e);
        }

        $this->audit->log('creator_premium_refund_unmatched', $purchase, ['reference' => $purchase->reference_code], $actor);

        return $result;
    }

    /** يغلق علم مراجعة مالية بقرار «تجاهل» موثَّق؛ لا يغيّر حالة الدفع ولا يمنح وصولًا. @throws CreatorPremiumException */
    public function dismissReview(User $actor, string $purchasePublicId, string $note): DigitalPurchase
    {
        $this->authorize($actor, 'creator-premium.manage');
        $clean = $this->cleanNote($note, 'اكتب سبب إغلاق المراجعة (5 أحرف على الأقل) للتوثيق.');
        $purchase = $this->seriesPurchase($purchasePublicId);
        $this->assertNotParty($actor, $purchase);

        try {
            return DB::transaction(function () use ($actor, $purchase, $clean) {
                $locked = $this->core->lock($purchase);
                $this->core->resolveLocked($locked, $actor, 'dismissed', $clean);

                return $locked;
            });
        } catch (CommerceException $e) {
            throw $this->translate($e);
        }
    }

    /** أعلام المراجعة التي يجوز بعد معالجة سببها التحقق منها (المبلغ والعملة طابقا الطلب). غيرها (مبلغ/عملة/مرجع) لا يقبل إلا الاسترداد أو التجاهل: القسمة المالية تُبنى على المبلغ المطابق فقط. */
    public const VERIFIABLE_FLAGS = ['fulfillment_blocked', 'late_paid_cancelled', 'late_paid_expired', 'late_paid_rejected'];

    /**
     * بعد معالجة سبب المنع (استئناف العرض/تفعيل التسييل…) أو لدفعة متأخرة مطابقة، يُعاد التحقق عبر المعالج نفسه (المنطق الوحيد لمنح الوصول وقيد الحصة). فشل المنع يعود كرسالة مقروءة دون أي كتابة.
     *
     * @throws CreatorPremiumException
     */
    public function verifyReviewed(User $actor, string $purchasePublicId, string $note): DigitalPurchase
    {
        $this->authorize($actor, 'creator-premium.manage');
        $clean = $this->cleanNote($note, 'اكتب سبب قبول هذا الدفع رغم علم المراجعة (5 أحرف على الأقل) للتوثيق.');
        $purchase = $this->seriesPurchase($purchasePublicId);
        $this->assertNotParty($actor, $purchase);

        try {
            $result = DB::transaction(function () use ($actor, $purchase, $clean) {
                $locked = $this->core->lock($purchase);

                if ($locked->status !== DigitalPurchase::STATUS_AWAITING || ! $locked->isHosted() || ! $locked->needsReview()) {
                    throw new CreatorPremiumException('invalid_state', 'لا يمكن التحقق إلا من طلب مستضاف عليه علم مراجعة قائم.');
                }

                if (! in_array($locked->review_flag, self::VERIFIABLE_FLAGS, true)) {
                    throw new CreatorPremiumException('flag_not_verifiable', 'علم المراجعة هذا لا يقبل التحقق (مبلغ/عملة/مرجع غير مطابق): استرد الدفعة أو أغلق المراجعة.');
                }

                $this->core->verifyLocked($locked, $actor, 'review', $clean);

                return $locked;
            });
        } catch (CommerceException $e) {
            throw $this->translate($e);
        }

        $this->notifier->verified($result->fresh());
        $this->audit->log('creator_premium_review_verified', $purchase, ['reference' => $purchase->reference_code], $actor);

        return $result;
    }

    protected function translate(CommerceException $e): CreatorPremiumException
    {
        if ($e->reason === 'claimed_requires_confirmation') {
            $count = (int) ($e->details[0] ?? 0);

            return new CreatorPremiumException($e->reason, 'بدأ المشتري لعب '.$count.' أحجية من السلسلة بعد الشراء. الاسترداد يسحب الوصول ويعكس حصة المبدع؛ أكّد المراجعة المالية للمتابعة.');
        }

        return new CreatorPremiumException($e->reason, $e->getMessage());
    }

    /** @throws CreatorPremiumException not_found */
    protected function seriesPurchase(string $publicId): DigitalPurchase
    {
        $purchase = DigitalPurchase::query()->where('public_id', $publicId)->where('product_type', DigitalPurchase::PRODUCT_CREATOR_SERIES)->first();

        if ($purchase === null) {
            throw new CreatorPremiumException('not_found', 'الطلب غير موجود.');
        }

        return $purchase;
    }

    /** لا يعالج إداري شراءً يخصه كمشترٍ، ولا بيعًا يخصه كمبدع (نفس روح قاعدة E39 «لا معالجة لحسابك»). */
    protected function assertNotParty(User $actor, DigitalPurchase $purchase): void
    {
        $sellerUserId = CreatorDirectSale::query()->where('digital_purchase_id', $purchase->getKey())->value('user_id')
            ?? DB::table('creator_series as s')->join('creator_profiles as p', 'p.id', '=', 's.creator_profile_id')->where('s.id', $purchase->product_id)->value('p.user_id');

        if ((int) $actor->getKey() === (int) $purchase->user_id || (int) $actor->getKey() === (int) $sellerUserId) {
            throw new CreatorPremiumException('own_account', 'لا يجوز لك معالجة شراء يخص حسابك الخاص (مشتريًا أو مبدعًا).');
        }
    }

    protected function authorize(User $actor, string $permission): void
    {
        if (! $actor->can($permission)) {
            throw new CreatorPremiumException('forbidden', 'لا تملك صلاحية تنفيذ هذا الإجراء.');
        }
    }

    protected function cleanNote(string $note, string $message): string
    {
        $clean = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $note))));
        $min = (int) config('creator_premium.refund.note_min', 5);
        $max = (int) config('creator_premium.refund.note_max', 1000);

        if ($clean === '' || mb_strlen($clean) < $min) {
            throw new CreatorPremiumException('note_required', $message);
        }

        return mb_substr($clean, 0, $max);
    }
}
