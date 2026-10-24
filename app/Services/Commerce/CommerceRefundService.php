<?php

namespace App\Services\Commerce;

use App\Models\DigitalPurchase;
use App\Models\User;
use App\Services\OperationalAuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * E41 - تنسيق الاسترداد **الكامل** عبر المزوّد (لا جزئي). الصلاحية يفرضها المتصل (PassPremiumService: passes.refund-purchases)؛ هنا القواعد المالية.
 *
 * ثلاث مراحل (لا قفل أثناء الشبكة، ولا «refunded» قبل تأكيد المزوّد — M2):
 *   1) معاملة: قفل الطلب + حارس التزامن + سياسة العناصر المستلَمة + وسم refund_requested_* (يُلتزَم قبل النداء) + حدث + تدقيق.
 *   2) خارج المعاملة: نداء المزوّد بمفتاح ثابت `refund:{public_id}` (تكراره عند المزوّد لا يكرّر الاسترداد — M5).
 *   3) معاملة: succeeded ⇒ التطبيق عبر مسار الاسترداد الموحَّد (سحب المميز، العناصر المستلَمة تبقى — M4) | pending ⇒ ينتظر حدث refunded | failed ⇒ لا تغيير ويُسجَّل الخطأ بكود آمن.
 * اكتمال مسبق بحدث refunded (webhook) يجعل المرحلة 3 no-op idempotent. الاسترداد اليدوي (E40: تسجيل داخلي بعد استرداد خارجي) لا يمرّ من هنا.
 */
class CommerceRefundService
{
    public function __construct(
        protected CommercePaymentProviderRegistry $registry,
        protected DigitalPurchaseService $purchases,
        protected CommerceNotifier $notifier,
        protected OperationalAuditService $audit,
    ) {}

    public function supports(DigitalPurchase $purchase): bool
    {
        try {
            return $purchase->isHosted() && $this->registry->get($purchase->provider)->supportsRefund();
        } catch (CommerceException) {
            return false;
        }
    }

    /**
     * استرداد كامل لشراء محقَّق عبر المزوّد.
     *
     * @throws CommerceException invalid_state | refund_unsupported | refund_in_progress | claimed_requires_confirmation | refund_failed
     */
    public function refundVerified(User $actor, DigitalPurchase $purchase, string $reason, string $note, bool $confirmClaimed): DigitalPurchase
    {
        $provider = $this->registry->get($purchase->provider);

        $this->begin($purchase, $provider, $actor, $reason, $note, $confirmClaimed, DigitalPurchase::STATUS_VERIFIED);

        $result = $this->callProvider($provider, $purchase);

        $applied = DB::transaction(function () use ($purchase, $actor, $reason, $note, $confirmClaimed, $result) {
            $locked = $this->purchases->lock($purchase);

            if ($locked->status !== DigitalPurchase::STATUS_VERIFIED) {
                return $locked; // اكتمل بحدث refunded (أو تغيّرت الحالة): لا شيء نفعله.
            }

            if ($result->isFailed()) {
                $this->recordFailure($locked, $result->errorCode ?? 'refund_failed');

                return $locked;
            }

            if ($result->isPending()) {
                $this->purchases->event($locked, 'refund_pending', $locked->status, $locked->status, $actor, null);

                return $locked;
            }

            try {
                $this->purchases->refundLocked($locked, $actor, $reason, $note, $confirmClaimed, 'provider', $result->refundReference);
            } catch (CommerceException $e) {
                if ($e->reason !== 'claimed_requires_confirmation') {
                    throw $e;
                }

                // استلم المشتري مكافآت بعد إصدار الطلب: المال استُرد فعلًا عند المزوّد ⇒ نسجّل حقيقته ونرفع علم مراجعة (لا سحب صامت).
                $this->purchases->recordProviderFacts($locked, ['refund_reference' => $result->refundReference, 'state' => CommerceProviderState::Refunded]);
                $this->purchases->flagLocked($locked, 'refund_claimed_items', ['origin' => 'refund']);
            }

            return $locked;
        });

        if ($result->isFailed() && $applied->status === DigitalPurchase::STATUS_VERIFIED) {
            throw new CommerceException('refund_failed', 'تعذّر تنفيذ الاسترداد عند مزوّد الدفع؛ لم يُسجَّل أي استرداد. أعد المحاولة أو راجع لوحة المزوّد.', true);
        }

        if ($applied->status === DigitalPurchase::STATUS_REFUNDED) {
            $this->notifier->refunded($applied);
        }

        return $applied->fresh();
    }

    /**
     * استرداد دفعة **غير مطابقة/متأخرة** (علم مراجعة قائم والطلب ليس محقَّقًا) عبر المزوّد، ثم إغلاق العلم بـ«refunded».
     *
     * @throws CommerceException
     */
    public function refundUnmatched(User $actor, DigitalPurchase $purchase, string $note): DigitalPurchase
    {
        $provider = $this->registry->get($purchase->provider);

        $this->begin($purchase, $provider, $actor, 'payment_issue', $note, false, null);

        $result = $this->callProvider($provider, $purchase);

        $applied = DB::transaction(function () use ($purchase, $actor, $note, $result) {
            $locked = $this->purchases->lock($purchase);

            if (! $locked->needsReview()) {
                return $locked; // أُغلق العلم مسبقًا (حدث refunded وصل أولًا).
            }

            if ($result->isFailed()) {
                $this->recordFailure($locked, $result->errorCode ?? 'refund_failed');

                return $locked;
            }

            if ($result->isPending()) {
                $this->purchases->event($locked, 'refund_pending', $locked->status, $locked->status, $actor, null);

                return $locked;
            }

            $this->purchases->recordProviderFacts($locked, ['refund_reference' => $result->refundReference, 'state' => CommerceProviderState::Refunded]);
            $this->purchases->event($locked, 'payment_refunded', $locked->status, $locked->status, $actor, null);
            $this->purchases->resolveLocked($locked, $actor, 'refunded', $note);
            $this->audit->log('commerce_refund_completed', $locked, ['reference' => $locked->reference_code, 'kind' => 'unmatched'], $actor);

            return $locked;
        });

        if ($result->isFailed() && $applied->needsReview()) {
            throw new CommerceException('refund_failed', 'تعذّر تنفيذ الاسترداد عند مزوّد الدفع؛ لم يُسجَّل أي استرداد. أعد المحاولة أو راجع لوحة المزوّد.', true);
        }

        if ($applied->review_resolution === 'refunded') {
            $this->notifier->refunded($applied);
        }

        return $applied->fresh();
    }

    /**
     * المزوّد استردّ فعلًا (حدث/مزامنة) والطلب ما زال محقَّقًا لأن العناصر المستلَمة تستوجب قرارًا ماليًا صريحًا: يُسجَّل الاسترداد محليًا بلا نداء مزوّد ثانٍ.
     *
     * @throws CommerceException
     */
    public function recordProviderRefunded(User $actor, DigitalPurchase $purchase, string $reason, string $note, bool $confirmClaimed): DigitalPurchase
    {
        $applied = DB::transaction(function () use ($purchase, $actor, $reason, $note, $confirmClaimed) {
            $locked = $this->purchases->lock($purchase);

            if ($locked->providerState() !== CommerceProviderState::Refunded) {
                throw new CommerceException('invalid_state', 'لم يؤكّد المزوّد الاسترداد بعد.');
            }

            $this->purchases->refundLocked($locked, $actor, $reason, $note, $confirmClaimed, 'review', $locked->provider_refund_reference);

            return $locked;
        });

        $this->notifier->refunded($applied);

        return $applied->fresh();
    }

    // ───────────────────────── داخلي ─────────────────────────

    /** المرحلة 1: التحقق + وسم الطلب بالتزام قبل النداء الخارجي. */
    protected function begin(DigitalPurchase $purchase, CommercePaymentProvider $provider, User $actor, string $reason, string $note, bool $confirmClaimed, ?string $requiredStatus): void
    {
        DB::transaction(function () use ($purchase, $provider, $actor, $reason, $note, $confirmClaimed, $requiredStatus) {
            $locked = $this->purchases->lock($purchase);

            if (! $provider->supportsRefund() || $locked->provider_session_id === null) {
                throw new CommerceException('refund_unsupported', 'هذه الطريقة لا تدعم الاسترداد عبر المزوّد.');
            }

            if ($requiredStatus !== null) {
                if ($locked->status !== $requiredStatus) {
                    throw new CommerceException('invalid_state', 'يمكن استرداد الطلبات المؤكَّدة فقط.');
                }
            } else {
                if (! $locked->needsReview() || $locked->status === DigitalPurchase::STATUS_VERIFIED || $locked->status === DigitalPurchase::STATUS_REFUNDED) {
                    throw new CommerceException('invalid_state', 'لا يوجد علم مراجعة لدفعة غير مطابقة على هذا الطلب.');
                }

                if ($locked->providerState() !== CommerceProviderState::Paid || $locked->provider_refund_reference !== null) {
                    throw new CommerceException('invalid_state', 'لا توجد دفعة مؤكَّدة عند المزوّد لاستردادها آليًا؛ تصرّف من لوحة المزوّد ثم أغلق المراجعة.');
                }
            }

            $inflight = (int) config('commerce.checkout.refund_inflight_seconds', 60);

            if ($locked->refund_requested_at !== null && $locked->refund_error === null && $locked->refund_requested_at->gt(now()->subSeconds($inflight))) {
                throw new CommerceException('refund_in_progress', 'طلب استرداد لهذا الطلب قيد التنفيذ الآن. انتظر لحظات ثم راجع الحالة.');
            }

            if ($requiredStatus === DigitalPurchase::STATUS_VERIFIED) {
                $kept = $this->purchases->claimedItems($locked);

                if ($kept > 0 && ! $confirmClaimed) {
                    throw new CommerceException('claimed_requires_confirmation', 'استلم المشتري '.$kept.' مكافأة مميزة. الاسترداد لا يسحبها آليًا؛ أكّد المراجعة المالية للمتابعة.', false, [(string) $kept]);
                }
            }

            $locked->forceFill([
                'refund_requested_at' => now(), // آخر محاولة (يقيس حارس «قيد التنفيذ»)؛ أول طلب موثَّق بحدث refund_requested.
                'refund_requested_by' => $actor->getKey(),
                'refund_requested_reason' => $reason,
                'refund_requested_note' => mb_substr($note, 0, 300),
                'refund_claims_confirmed' => $confirmClaimed,
                'refund_attempts' => (int) $locked->refund_attempts + 1,
                'refund_error' => null,
            ])->save();

            $this->purchases->event($locked, 'refund_requested', $locked->status, $locked->status, $actor, $reason);
            $this->audit->log('commerce_refund_requested', $locked, ['reference' => $locked->reference_code, 'reason' => $reason, 'attempt' => (int) $locked->refund_attempts, 'confirmed' => $confirmClaimed], $actor);
        });
    }

    /** المرحلة 2: نداء المزوّد بلا أي قفل؛ أي استثناء يُعامل كفشل بكود آمن. */
    protected function callProvider(CommercePaymentProvider $provider, DigitalPurchase $purchase): CommerceRefundResult
    {
        try {
            return $provider->refund($purchase->fresh(), 'refund:'.$purchase->public_id);
        } catch (Throwable $e) {
            report($e);
            Log::warning('commerce.refund_provider_error', ['provider' => $purchase->provider, 'exception' => $e::class]);

            return CommerceRefundResult::failed('provider_error');
        }
    }

    protected function recordFailure(DigitalPurchase $locked, string $code): void
    {
        $locked->forceFill(['refund_error' => mb_substr($code, 0, 48)])->save();
        $this->purchases->event($locked, 'refund_failed', $locked->status, $locked->status, null, mb_substr($code, 0, 48));
        $this->audit->log('commerce_refund_failed', $locked, ['reference' => $locked->reference_code, 'code' => mb_substr($code, 0, 48)]);
    }
}
