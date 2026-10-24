<?php

namespace App\Services\Commerce;

use App\Models\DigitalPurchase;
use App\Models\DigitalPurchaseEvent;
use App\Models\User;
use App\Services\OperationalAuditService;

/**
 * E41 - نواة انتقالات DigitalPurchase **المقفولة**: المسار الموحَّد الوحيد لـ(التحقق ⇒ فتح المنتج) و(الاسترداد ⇒ سحبه) و(علم المراجعة المالية) و(بيانات المزوّد).
 * يشترك فيه: مراجع التحقق اليدوي (E40) و المزوّد الموثوق (webhook/مزامنة) و استرداد المزوّد؛ **لا نسخة ثانية** من منطق الفتح أو الاسترداد.
 *
 * عقد الاستدعاء: كل دالة *Locked تُستدعى داخل معاملة المتصل والطلب مقفول مسبقًا بـlockForUpdate (الطلب ثم صف الاستحقاق). لا تفتح معاملة ولا ترسل إشعارات (بعد الـcommit مسؤولية المتصل).
 * لا مصدر ثقة هنا غير ما يمرّره المتصل المخوَّل (المراجع بصلاحية، أو خدمة توقيع المزوّد بعد تحققها). المبلغ/العملة لا تُقرأ من العميل بتاتًا.
 */
class DigitalPurchaseService
{
    public function __construct(protected CommerceProductRegistry $products, protected OperationalAuditService $audit) {}

    public function lock(DigitalPurchase|int $purchase): DigitalPurchase
    {
        return DigitalPurchase::query()->whereKey($purchase instanceof DigitalPurchase ? $purchase->getKey() : $purchase)->lockForUpdate()->firstOrFail();
    }

    /** عدد عناصر الاستحقاق المستلَمة سابقًا (سياسة الاسترداد). الطلب مقفول مسبقًا. */
    public function claimedItems(DigitalPurchase $locked): int
    {
        return $this->products->for($locked->product_type)->claimedItemCount($locked);
    }

    public function event(DigitalPurchase $purchase, string $event, ?string $from, ?string $to, ?User $actor, ?string $note): void
    {
        $row = new DigitalPurchaseEvent;
        $row->forceFill([
            'digital_purchase_id' => $purchase->getKey(),
            'event' => $event,
            'from_status' => $from,
            'to_status' => $to,
            'actor_user_id' => $actor?->getKey(),
            'note' => $note === null ? null : mb_substr($note, 0, 300),
        ])->save();
    }

    /**
     * التحقق المالي: يفتح المنتج ويؤكّد الطلب في الخطوة نفسها (ذريّ بمعاملة المتصل). `$source`: manual (مراجع) | provider (توقيع موثَّق) | review (قرار مراجعة لعلم مالي).
     * `$facts` بيانات مزوّد لتُسجَّل (payment_reference/paid_at/session_id) ويصير provider_status=paid.
     *
     * @param  array<string, mixed>  $facts
     *
     * @throws CommerceFulfillmentException منع أعمال لفتح المنتج (لا شيء يُكتب قبله)
     */
    public function verifyLocked(DigitalPurchase $locked, ?User $actor, string $source, ?string $reviewNote = null, array $facts = []): void
    {
        if ($source === 'review') {
            $this->assertNoRefundInFlight($locked); // قرار بشري متزامن مع استرداد جارٍ عند المزوّد: لا يُفتح المميز لدفعة قد تكون استُردّت.
        }

        $this->products->for($locked->product_type)->fulfill($locked);

        $from = $locked->status;
        $attributes = [
            'status' => DigitalPurchase::STATUS_VERIFIED,
            'verified_at' => now(),
            'verified_by' => $actor?->getKey(),
            'review_note' => $reviewNote,
        ];

        if ($locked->needsReview()) {
            $attributes += ['review_resolved_at' => now(), 'review_resolved_by' => $actor?->getKey(), 'review_resolution' => 'verified'];
        }

        $locked->forceFill($attributes)->save();

        if ($facts !== []) {
            $this->recordProviderFacts($locked, $facts + ['state' => CommerceProviderState::Paid]);
        }

        $this->event($locked, 'verified', $from, DigitalPurchase::STATUS_VERIFIED, $actor, $source === 'manual' ? null : $source);
        $this->audit->log('digital_purchase_verified', $locked, ['reference' => $locked->reference_code, 'product' => $locked->product_public_id, 'amount_minor' => $locked->amount_minor, 'currency' => $locked->currency, 'source' => $source], $actor);
    }

    /**
     * استرداد كامل مؤكَّد: يسحب الاستحقاق (بسياسة E40: العناصر المستلَمة تبقى ويلزم تأكيد صريح) ويصير الطلب refunded.
     * `$providerRefundReference` يُسجَّل حين يكون المزوّد هو من نفّذ الاسترداد. @return int عدد العناصر المستلَمة التي بقيت
     *
     * @throws CommerceException invalid_state | claimed_requires_confirmation
     */
    public function refundLocked(DigitalPurchase $locked, ?User $actor, string $reason, string $note, bool $confirmClaimed, string $source, ?string $providerRefundReference = null): int
    {
        if ($locked->status !== DigitalPurchase::STATUS_VERIFIED) {
            throw new CommerceException('invalid_state', 'يمكن استرداد الطلبات المؤكَّدة فقط.');
        }

        $handler = $this->products->for($locked->product_type);
        $kept = $handler->claimedItemCount($locked);

        if ($kept > 0 && ! $confirmClaimed) {
            throw new CommerceException('claimed_requires_confirmation', 'استلم المشتري '.$kept.' مكافأة مميزة. الاسترداد لا يسحبها آليًا؛ أكّد المراجعة المالية للمتابعة.', false, [(string) $kept]);
        }

        $handler->reverse($locked);

        $locked->forceFill([
            'status' => DigitalPurchase::STATUS_REFUNDED,
            'refunded_at' => now(),
            'refunded_by' => $actor?->getKey(),
            'refund_reason' => $reason,
            'refund_kept_items' => $kept,
            'review_note' => trim(($locked->review_note ? $locked->review_note."\n" : '').'[استرداد] '.$note),
        ])->save();

        if ($providerRefundReference !== null) {
            $this->recordProviderFacts($locked, ['refund_reference' => $providerRefundReference, 'state' => CommerceProviderState::Refunded]);
        }

        // علم مراجعة قائم يخصّ هذا الاسترداد يُغلق به.
        if ($locked->needsReview() && in_array($locked->review_flag, ['refund_claimed_items', 'refund_unmatched', 'provider_status_conflict'], true)) {
            $locked->forceFill(['review_resolved_at' => now(), 'review_resolved_by' => $actor?->getKey(), 'review_resolution' => 'refunded'])->save();
        }

        $this->event($locked, 'refunded', DigitalPurchase::STATUS_VERIFIED, DigitalPurchase::STATUS_REFUNDED, $actor, $reason);
        $this->audit->log('digital_purchase_refunded', $locked, ['reference' => $locked->reference_code, 'reason' => $reason, 'kept_premium_items' => $kept, 'confirmed' => $confirmClaimed, 'source' => $source], $actor);

        return $kept;
    }

    /**
     * يرفع علم مراجعة مالية (لا يغيّر حالة الدفع). علم قائم لم يُحلّ يبقى كما هو (الأول يفوز). @return bool true إن رُفع الآن
     *
     * @param  array<string, scalar|null>  $context
     */
    public function flagLocked(DigitalPurchase $locked, string $flag, array $context = []): bool
    {
        if (! array_key_exists($flag, DigitalPurchase::REVIEW_FLAGS)) {
            throw new \LogicException("علم مراجعة غير معروف: {$flag}");
        }

        if ($locked->needsReview()) {
            return false;
        }

        $locked->forceFill([
            'review_flag' => $flag,
            'review_flagged_at' => now(),
            'review_resolved_at' => null,
            'review_resolved_by' => null,
            'review_resolution' => null,
        ])->save();

        $this->event($locked, 'review_flagged', $locked->status, $locked->status, null, $flag);
        $this->audit->log('commerce_mismatch_detected', $locked, ['reference' => $locked->reference_code, 'flag' => $flag] + $context);

        return true;
    }

    /**
     * استرداد عند المزوّد بدأ ولم يُحسم (وُسم الطلب قبل النداء ولم يُسجَّل فشل ولا تأكيد استرداد من المزوّد): نتيجته مجهولة، فلا يُسمح بتأكيد الدفع ولا بتجاهل المراجعة.
     * الحل المشروع: إعادة محاولة الاسترداد (مفتاح المزوّد ثابت فلا يتكرر) أو مزامنة الحالة من المزوّد. فشل مُسجَّل (refund_error) أو استرداد مؤكَّد من المزوّد لا يمنعان.
     *
     * @throws CommerceException refund_in_progress
     */
    protected function assertNoRefundInFlight(DigitalPurchase $locked): void
    {
        if ($locked->refund_requested_at !== null && $locked->refund_error === null && $locked->status !== DigitalPurchase::STATUS_REFUNDED && $locked->providerState() !== CommerceProviderState::Refunded) {
            throw new CommerceException('refund_in_progress', 'يوجد طلب استرداد عند المزوّد لم يُحسم بعد، فلا يمكن تأكيد الدفع أو تجاهل المراجعة الآن. أعد محاولة الاسترداد أو زامن الحالة من المزوّد.');
        }
    }

    /** يُنهي علم المراجعة بقرار صريح (dismissed | refunded | verified). */
    public function resolveLocked(DigitalPurchase $locked, ?User $actor, string $resolution, ?string $note = null): void
    {
        if (! in_array($resolution, DigitalPurchase::REVIEW_RESOLUTIONS, true)) {
            throw new \LogicException("قرار مراجعة غير معروف: {$resolution}");
        }

        if (! $locked->needsReview()) {
            throw new CommerceException('no_review', 'لا يوجد علم مراجعة قائم لهذا الطلب.');
        }

        if ($resolution === 'dismissed') {
            $this->assertNoRefundInFlight($locked);
        }

        $locked->forceFill([
            'review_resolved_at' => now(),
            'review_resolved_by' => $actor?->getKey(),
            'review_resolution' => $resolution,
            'review_note' => $note === null ? $locked->review_note : trim(($locked->review_note ? $locked->review_note."\n" : '').'[مراجعة] '.$note),
        ])->save();

        $this->event($locked, 'review_resolved', $locked->status, $locked->status, $actor, $resolution);
        $this->audit->log('commerce_review_resolved', $locked, ['reference' => $locked->reference_code, 'flag' => $locked->review_flag, 'resolution' => $resolution], $actor);
    }

    /**
     * يسجّل بيانات المزوّد على الطلب: المراجع تُملأ مرة واحدة (ثابتة بعدها)، وprovider_status تتقدّم صعودًا فقط.
     * يعيد 'reference_conflict' (ولا يكتب شيئًا) إن كان مرجع يخصّ طلبًا آخر لدى المزوّد نفسه؛ وإلا null.
     *
     * @param  array{session_id?: ?string, payment_reference?: ?string, refund_reference?: ?string, state?: ?CommerceProviderState, paid_at?: mixed}  $facts
     */
    public function recordProviderFacts(DigitalPurchase $locked, array $facts): ?string
    {
        $attributes = [];

        foreach (['session_id' => 'provider_session_id', 'payment_reference' => 'provider_payment_reference', 'refund_reference' => 'provider_refund_reference'] as $key => $column) {
            $value = $facts[$key] ?? null;

            if ($value === null || $locked->{$column} !== null || $locked->{$column} === $value) {
                continue;
            }

            $taken = DigitalPurchase::query()->where('provider', $locked->provider)->where($column, $value)->whereKeyNot($locked->getKey())->exists();

            if ($taken) {
                return 'reference_conflict';
            }

            $attributes[$column] = $value;
        }

        $state = $facts['state'] ?? null;

        if ($state instanceof CommerceProviderState && $state !== CommerceProviderState::Unknown) {
            $current = $locked->providerState();

            if ($current === null || $state->rank() > $current->rank()) {
                $attributes['provider_status'] = $state->value;
            }

            if (in_array($state, [CommerceProviderState::Paid, CommerceProviderState::Refunded], true) && $locked->provider_paid_at === null && ($facts['paid_at'] ?? null) !== null) {
                $attributes['provider_paid_at'] = $facts['paid_at'];
            }
        }

        if ($attributes !== []) {
            $locked->forceFill($attributes)->save();
        }

        return null;
    }
}
