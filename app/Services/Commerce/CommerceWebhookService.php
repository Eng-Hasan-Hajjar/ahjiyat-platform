<?php

namespace App\Services\Commerce;

use App\Models\CommerceProviderEvent;
use App\Models\DigitalPurchase;
use App\Models\User;
use App\Services\OperationalAuditService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * E41 - معالجة أحداث المزوّد (webhook + مزامنة الحالة). **الطريق الوحيد** الذي يحوّل «المزوّد قال مدفوع» إلى أثر على DigitalPurchase، وبعد:
 *   توقيع المزوّد ⇒ بحث الشراء ⇒ تطابق المرجع ⇒ تطابق المبلغ والعملة تمامًا ⇒ سلامة حالة الشراء ⇒ idempotency الحدث.   (المزوّد لا يصير سلطة أبدًا.)
 *
 * الذرّية: داخل معاملة واحدة: قفل الشراء ⇒ فحص السجل (قراءة قافلة) ⇒ الأثر ⇒ **إدراج صف السجل أخيرًا**. فشل أي خطوة ⇒ تراجع كامل ⇒ المزوّد يعيد الإرسال؛ لا حدث «مُستهلَك» بلا أثر.
 *   السباق: قيد unique(provider, provider_event_id) + قفل صف الشراء. الخاسر يرى duplicate (UniqueConstraintViolation أو الصف الموجود) ولا أثر له.
 *   الاستثناء من الاستثناء: لا نكتب صف سجل عند فشل المعالجة (وإلا ضاع الأثر عند إعادة إرسال المزوّد): التشخيص بسجلّ آمن + عدّاد + تدقيق محدود المعدّل.
 *
 * قواعد الانتقال (حتمية؛ لا تراجع أبدًا verified→submitted ولا refunded→verified بسبب حدث قديم):
 *   paid : awaiting + تطابق تام ⇒ verified (مسار التحقق الموحَّد) | عدم تطابق/تعارض مرجع/منع فتح ⇒ علم مراجعة (needs_review) بلا فتح
 *          verified ⇒ نفس الدفعة: ignored | دفعة مختلفة: duplicate_payment (مراجعة) | cancelled/expired/rejected ⇒ late_paid_* (مراجعة) | refunded ⇒ نفس الدفعة قديمة: ignored | غيرها: مراجعة
 *   failed/cancelled : awaiting ⇒ provider_status فقط (الطلب يبقى قابلًا لإعادة المحاولة) | غير ذلك ⇒ ignored (لا تراجع من verified)
 *   pending : لا تراجع عن حالة أعلى | refunded : verified ⇒ استرداد (سياسة E40 للعناصر المستلَمة) | مكرر ⇒ ignored | علم قائم ⇒ يُحلّ بـrefunded
 *   unknown : ignored بلا أثر مالي.
 * نتائج الحدث: applied | duplicate | ignored | needs_review | rejected (انظر CommerceProviderEvent).
 */
class CommerceWebhookService
{
    public function __construct(
        protected CommercePaymentProviderRegistry $registry,
        protected DigitalPurchaseService $purchases,
        protected CommerceNotifier $notifier,
        protected OperationalAuditService $audit,
    ) {}

    /**
     * مدخل webhook: بوابات المزوّد ⇒ حجم ⇒ توقيع ⇒ معالجة. التوقيع قبل أي أثر مالي.
     *
     * @param  array<string, mixed>  $headers
     * @return array{result: string, detail: ?string}
     *
     * @throws CommerceWebhookException
     */
    public function handle(string $providerKey, string $payload, array $headers): array
    {
        if (! CommerceEnvironment::webhooksEnabled()) {
            throw new CommerceWebhookException('webhooks_disabled', 'غير متاح.', 404);
        }

        try {
            $provider = $this->registry->get($providerKey);
        } catch (CommerceException) {
            throw new CommerceWebhookException('unknown_provider', 'غير متاح.', 404);
        }

        // «معطَّل» هنا = استقبال الأحداث معطَّل (accept_webhooks)، لا إيقاف بدء الدفع (AV): الدفعة التي وصلت بعد تعطيل المزوّد لا تضيع.
        if (! $provider->supportsWebhook() || ! $provider->acceptsWebhooks()) {
            throw new CommerceWebhookException('not_accepting', 'غير متاح.', 404);
        }

        if (strlen($payload) > (int) config('commerce.webhooks.max_body_bytes', 65_536)) {
            $this->rejected($providerKey, 'payload_too_large');

            throw new CommerceWebhookException('payload_too_large', 'الحمولة كبيرة.', 413);
        }

        try {
            $event = $provider->verifyWebhook($payload, $headers);
        } catch (CommerceWebhookException $e) {
            $this->rejected($providerKey, $e->reason);

            throw $e;
        } catch (Throwable) {
            $this->rejected($providerKey, 'verify_error');

            throw new CommerceWebhookException('malformed_payload', 'حمولة غير صالحة.', 400);
        }

        return $this->process($event);
    }

    /**
     * يطبّق حدثًا موثَّقًا (من webhook أو مزامنة). idempotent بحدث المزوّد. @return array{result: string, detail: ?string, purchase_id: ?int}
     */
    public function process(CommerceWebhookEvent $event, string $origin = 'webhook'): array
    {
        $after = [];

        try {
            $outcome = DB::transaction(function () use ($event, $origin, &$after) {
                $after = [];

                $located = $this->locate($event);
                $purchase = $located === null ? null : $this->purchases->lock($located);

                // قراءة قافلة: ترى ما أدرجه عامل متزامن بعد لقطتنا (REPEATABLE READ). القيد الفريد شبكة الأمان الثانية.
                if (CommerceProviderEvent::query()->where('provider', $event->provider)->where('provider_event_id', $event->eventId)->lockForUpdate()->value('id') !== null) {
                    return ['result' => CommerceProviderEvent::RESULT_DUPLICATE, 'detail' => null, 'purchase_id' => $purchase?->getKey()];
                }

                [$result, $detail] = $this->apply($event, $purchase, $after);

                $row = new CommerceProviderEvent;
                $row->forceFill([
                    'provider' => $event->provider,
                    'provider_event_id' => $event->eventId,
                    'event_type' => mb_substr($event->eventType, 0, 64),
                    'normalized_state' => $event->state->value,
                    'provider_reference' => $event->sessionId,
                    'digital_purchase_id' => $purchase?->getKey(),
                    'amount_minor' => $event->amountMinor,
                    'currency' => $event->currency,
                    'processing_result' => $result,
                    'result_detail' => $detail,
                    'payload_sha256' => $event->payloadSha256,
                    'occurred_at' => $event->occurredAt,
                    'received_at' => now(),
                    'processed_at' => now(),
                ])->save();

                $this->audit->log($result === CommerceProviderEvent::RESULT_REJECTED ? 'commerce_webhook_rejected' : 'commerce_webhook_accepted', $purchase, [
                    'provider' => $event->provider, 'event_id' => $event->eventId, 'type' => mb_substr($event->eventType, 0, 64),
                    'state' => $event->state->value, 'result' => $result, 'detail' => $detail, 'origin' => $origin,
                ]);

                return ['result' => $result, 'detail' => $detail, 'purchase_id' => $purchase?->getKey()];
            }, 3);
        } catch (UniqueConstraintViolationException $e) {
            // خسر سباق إدراج السجل: الحدث نفسه أُدرج بمعاملة أخرى (تراجعت آثارنا كاملة). نتحقق أنه فعلًا هو، وإلا فهو تعارض آخر يُعاد للمزوّد.
            if (CommerceProviderEvent::query()->where('provider', $event->provider)->where('provider_event_id', $event->eventId)->exists()) {
                return ['result' => CommerceProviderEvent::RESULT_DUPLICATE, 'detail' => null, 'purchase_id' => null];
            }

            $this->failed($event, $e);

            throw $e;
        } catch (Throwable $e) {
            $this->failed($event, $e);

            throw $e;
        }

        if ($outcome['result'] !== CommerceProviderEvent::RESULT_DUPLICATE) {
            foreach ($after as $notify) {
                try {
                    $notify();
                } catch (Throwable $e) {
                    report($e); // الإشعار معزول: لا يمسّ حالة الدفع.
                }
            }
        }

        return $outcome;
    }

    // ───────────────────────── قواعد التطبيق ─────────────────────────

    /**
     * @param  list<callable>  $after
     * @return array{0: string, 1: ?string}
     */
    protected function apply(CommerceWebhookEvent $e, ?DigitalPurchase $p, array &$after): array
    {
        if ($p === null) {
            if ($e->state === CommerceProviderState::Paid) {
                $this->audit->log('commerce_unmatched_payment', null, ['provider' => $e->provider, 'event_id' => $e->eventId, 'reference_given' => $e->reference !== null || $e->sessionId !== null]);

                return [CommerceProviderEvent::RESULT_NEEDS_REVIEW, 'unknown_purchase'];
            }

            return [CommerceProviderEvent::RESULT_IGNORED, 'unknown_purchase'];
        }

        if ($p->provider !== $e->provider) {
            return [CommerceProviderEvent::RESULT_REJECTED, 'provider_mismatch'];
        }

        $conflict = $this->referenceConflict($e, $p);

        return match ($e->state) {
            CommerceProviderState::Paid => $this->applyPaid($e, $p, $conflict, $after),
            CommerceProviderState::Failed, CommerceProviderState::Cancelled => $this->applyFailed($e, $p, $conflict, $after),
            CommerceProviderState::Pending => $this->applyPending($e, $p, $conflict),
            CommerceProviderState::Refunded => $this->applyRefunded($e, $p, $conflict, $after),
            CommerceProviderState::Unknown => [CommerceProviderEvent::RESULT_IGNORED, 'unknown_state'],
        };
    }

    /** @param list<callable> $after @return array{0: string, 1: ?string} */
    protected function applyPaid(CommerceWebhookEvent $e, DigitalPurchase $p, bool $conflict, array &$after): array
    {
        $facts = $this->facts($e);

        switch ($p->status) {
            case DigitalPurchase::STATUS_VERIFIED:
                $same = ($e->paymentReference !== null && $e->paymentReference === $p->provider_payment_reference)
                    || ($e->paymentReference === null && ! $conflict && $e->sessionId !== null && $e->sessionId === $p->provider_session_id);

                if ($same) {
                    return [CommerceProviderEvent::RESULT_IGNORED, 'already_verified'];
                }

                return $this->review($p, 'duplicate_payment', 'duplicate_payment', $after);

            case DigitalPurchase::STATUS_REFUNDED:
                if (($e->paymentReference !== null && $e->paymentReference === $p->provider_payment_reference) || ($e->paymentReference === null && ! $conflict)) {
                    return [CommerceProviderEvent::RESULT_IGNORED, 'stale_paid_after_refund'];
                }

                return $this->review($p, 'paid_after_refund', 'paid_after_refund', $after);

            case DigitalPurchase::STATUS_CANCELLED:
            case DigitalPurchase::STATUS_EXPIRED:
            case DigitalPurchase::STATUS_REJECTED:
                if (! $conflict) {
                    $this->purchases->recordProviderFacts($p, $facts);
                }

                return $this->review($p, 'late_paid_'.$p->status, 'late_paid', $after);

            case DigitalPurchase::STATUS_AWAITING:
                break;

            default:
                return [CommerceProviderEvent::RESULT_IGNORED, 'invalid_state'];
        }

        if ($p->needsReview()) {
            return [CommerceProviderEvent::RESULT_IGNORED, 'under_review'];
        }

        if ($conflict) {
            return $this->review($p, 'reference_conflict', 'reference_conflict', $after);
        }

        $currencyOk = $e->currency !== null && strtoupper($e->currency) === strtoupper((string) $p->currency);
        $amountOk = $e->amountMinor !== null && $e->amountMinor === (int) $p->amount_minor;

        if (! $currencyOk || ! $amountOk) {
            if ($this->purchases->recordProviderFacts($p, $facts) === 'reference_conflict') {
                return $this->review($p, 'reference_conflict', 'reference_conflict', $after);
            }

            return $this->review($p, ! $currencyOk ? 'currency_mismatch' : 'amount_mismatch', ! $currencyOk ? 'currency_mismatch' : 'amount_mismatch', $after);
        }

        if ($this->purchases->recordProviderFacts($p, $facts) === 'reference_conflict') {
            return $this->review($p, 'reference_conflict', 'reference_conflict', $after);
        }

        try {
            $this->purchases->verifyLocked($p, null, 'provider', null, $facts);
        } catch (CommerceFulfillmentException $exception) {
            return $this->review($p, 'fulfillment_blocked', 'fulfillment_'.mb_substr($exception->reason, 0, 30), $after);
        }

        $after[] = fn () => $this->notifier->verified($p->fresh());

        return [CommerceProviderEvent::RESULT_APPLIED, 'verified'];
    }

    /** @param list<callable> $after @return array{0: string, 1: ?string} */
    protected function applyFailed(CommerceWebhookEvent $e, DigitalPurchase $p, bool $conflict, array &$after): array
    {
        if ($p->status !== DigitalPurchase::STATUS_AWAITING) {
            return [CommerceProviderEvent::RESULT_IGNORED, 'stale_state'];
        }

        if ($conflict) {
            return [CommerceProviderEvent::RESULT_IGNORED, 'reference_conflict'];
        }

        $current = $p->providerState();

        if ($current !== null && $current->rank() >= $e->state->rank()) {
            return [CommerceProviderEvent::RESULT_IGNORED, 'stale_state'];
        }

        $this->purchases->recordProviderFacts($p, ['session_id' => $e->sessionId, 'state' => $e->state]);
        $this->purchases->event($p, 'provider_'.$e->state->value, $p->status, $p->status, null, 'provider');

        if ($e->state === CommerceProviderState::Failed) {
            $after[] = fn () => $this->notifier->failed($p->fresh());
        }

        return [CommerceProviderEvent::RESULT_APPLIED, $e->state->value];
    }

    /** @return array{0: string, 1: ?string} */
    protected function applyPending(CommerceWebhookEvent $e, DigitalPurchase $p, bool $conflict): array
    {
        if ($p->status !== DigitalPurchase::STATUS_AWAITING || $conflict || $p->providerState() !== null) {
            return [CommerceProviderEvent::RESULT_IGNORED, 'no_change'];
        }

        $this->purchases->recordProviderFacts($p, ['session_id' => $e->sessionId, 'state' => CommerceProviderState::Pending]);

        return [CommerceProviderEvent::RESULT_APPLIED, 'pending'];
    }

    /** @param list<callable> $after @return array{0: string, 1: ?string} */
    protected function applyRefunded(CommerceWebhookEvent $e, DigitalPurchase $p, bool $conflict, array &$after): array
    {
        if ($p->status === DigitalPurchase::STATUS_REFUNDED) {
            return [CommerceProviderEvent::RESULT_IGNORED, 'duplicate_refund'];
        }

        if ($conflict) {
            return [CommerceProviderEvent::RESULT_IGNORED, 'reference_conflict'];
        }

        if ($p->status === DigitalPurchase::STATUS_VERIFIED) {
            $actor = $p->refund_requested_by !== null ? User::query()->find($p->refund_requested_by) : null;
            $requested = $p->refund_requested_at !== null;

            try {
                $this->purchases->refundLocked(
                    $p,
                    $actor,
                    $requested ? (string) ($p->refund_requested_reason ?: 'other') : 'other',
                    $requested ? (string) ($p->refund_requested_note ?: 'provider') : 'provider_initiated',
                    $requested && (bool) $p->refund_claims_confirmed,
                    'provider',
                    $e->refundReference,
                );
            } catch (CommerceException $exception) {
                if ($exception->reason !== 'claimed_requires_confirmation') {
                    throw $exception;
                }

                $this->purchases->recordProviderFacts($p, ['refund_reference' => $e->refundReference, 'state' => CommerceProviderState::Refunded]);

                return $this->review($p, 'refund_claimed_items', 'refund_claimed_items', $after);
            }

            $after[] = fn () => $this->notifier->refunded($p->fresh());

            return [CommerceProviderEvent::RESULT_APPLIED, $requested ? 'refund_completed' : 'refund_provider_initiated'];
        }

        // أي حالة أخرى: الأثر الوحيد تسجيل أن المزوّد استرد الدفعة، وإغلاق علم المراجعة القائم (دفعة متأخرة/غير مطابقة استُردَّت) إن وُجد.
        $this->purchases->recordProviderFacts($p, ['refund_reference' => $e->refundReference, 'state' => CommerceProviderState::Refunded]);

        if ($p->needsReview()) {
            $this->purchases->resolveLocked($p, null, 'refunded', 'provider');
            $after[] = fn () => $this->notifier->refunded($p->fresh());

            return [CommerceProviderEvent::RESULT_APPLIED, 'review_refunded'];
        }

        return [CommerceProviderEvent::RESULT_IGNORED, 'refunded_before_paid'];
    }

    /**
     * يرفع علم المراجعة (لا فتح للمنتج) ويرتّب إشعار «تحتاج مراجعة».
     *
     * @param  list<callable>  $after
     * @return array{0: string, 1: ?string}
     */
    protected function review(DigitalPurchase $p, string $flag, string $detail, array &$after): array
    {
        $flagged = $this->purchases->flagLocked($p, $flag, ['origin' => 'provider']);

        if ($flagged) {
            $after[] = fn () => $this->notifier->actionRequired($p->fresh());
        }

        return [CommerceProviderEvent::RESULT_NEEDS_REVIEW, $detail];
    }

    // ───────────────────────── مساعدات ─────────────────────────

    /** حقائق المزوّد التي تُسجَّل على الشراء. @return array<string, mixed> */
    protected function facts(CommerceWebhookEvent $e): array
    {
        return [
            'session_id' => $e->sessionId,
            'payment_reference' => $e->paymentReference,
            'refund_reference' => $e->refundReference,
            'state' => $e->state,
            'paid_at' => $e->occurredAt ?? now(),
        ];
    }

    /** تعارض مرجع: جلسة مختلفة عن جلسة الشراء الحالية، أو مرجع شراء مختلف، أو دفعة مختلفة عن المسجَّلة. */
    protected function referenceConflict(CommerceWebhookEvent $e, DigitalPurchase $p): bool
    {
        if ($e->sessionId !== null && $p->provider_session_id !== null && $e->sessionId !== $p->provider_session_id) {
            return true;
        }

        if ($e->reference !== null && $e->reference !== $p->reference_code) {
            return true;
        }

        return $e->state === CommerceProviderState::Paid && $e->paymentReference !== null && $p->provider_payment_reference !== null && $e->paymentReference !== $p->provider_payment_reference
            && $p->status === DigitalPurchase::STATUS_AWAITING;
    }

    protected function locate(CommerceWebhookEvent $e): ?DigitalPurchase
    {
        $scoped = fn () => DigitalPurchase::query()->where('provider', $e->provider);

        if ($e->sessionId !== null && ($found = $scoped()->where('provider_session_id', $e->sessionId)->first()) !== null) {
            return $found;
        }

        if ($e->paymentReference !== null && ($found = $scoped()->where('provider_payment_reference', $e->paymentReference)->first()) !== null) {
            return $found;
        }

        if ($e->refundReference !== null && ($found = $scoped()->where('provider_refund_reference', $e->refundReference)->first()) !== null) {
            return $found;
        }

        return $e->reference !== null ? DigitalPurchase::query()->where('reference_code', $e->reference)->first() : null;
    }

    /** رفض قبل أي أثر: سجلّ آمن (بلا جسم/ترويسات/أسرار) + عدّاد صحة + تدقيق محدود المعدّل (مرة/دقيقة لكل مزوّد وسبب) كي لا يُغرق المهاجم جدول التدقيق. */
    protected function rejected(string $provider, string $reason): void
    {
        Log::warning('commerce.webhook_rejected', ['provider' => $provider, 'reason' => $reason]);
        $this->countFailure($provider);

        try {
            if (Cache::add('commerce:webhook:audit:'.$provider.':'.$reason.':'.now()->format('YmdHi'), 1, 120)) {
                $this->audit->log('commerce_webhook_rejected', null, ['provider' => $provider, 'reason' => $reason]);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    protected function failed(CommerceWebhookEvent $event, Throwable $e): void
    {
        // لا رسالة استثناء (قد تحمل قيمًا) ولا حمولة: اسم الصنف + هوية الحدث فقط.
        Log::error('commerce.webhook_failed', ['provider' => $event->provider, 'event_id' => $event->eventId, 'type' => mb_substr($event->eventType, 0, 64), 'exception' => $e::class]);
        $this->countFailure($event->provider);

        try {
            if (Cache::add('commerce:webhook:audit:'.$event->provider.':processing_error:'.now()->format('YmdHi'), 1, 120)) {
                $this->audit->log('commerce_webhook_failed', null, ['provider' => $event->provider, 'event_id' => $event->eventId, 'exception' => $e::class]);
            }
        } catch (Throwable $inner) {
            report($inner);
        }
    }

    protected function countFailure(string $provider): void
    {
        $key = 'commerce:webhook:failures:'.$provider.':'.now()->format('YmdH');
        Cache::add($key, 0, now()->addMinutes((int) config('commerce.webhooks.failure_counter_ttl_minutes', 1440)));
        Cache::increment($key);
    }

    /** عدد إخفاقات الاستقبال (توقيع/حجم/معالجة) خلال آخر 24 ساعة — للصحة التشغيلية فقط. */
    public function recentFailures(string $provider, int $hours = 24): int
    {
        $total = 0;

        for ($i = 0; $i < $hours; $i++) {
            $total += (int) Cache::get('commerce:webhook:failures:'.$provider.':'.now()->subHours($i)->format('YmdH'), 0);
        }

        return $total;
    }
}
