<?php

namespace App\Services\Payments;

/**
 * E32 - حدث موحَّد من مزوّد بعد التحقق من توقيعه (لا جسم خام هنا أبدًا).
 * state: paid | failed | refunded. eventId لمعالجة idempotent (تكرار الحدث لا يُكرّر الأثر).
 */
final class ProviderCallback
{
    public const STATE_PAID = 'paid';

    public const STATE_FAILED = 'failed';

    public const STATE_REFUNDED = 'refunded';

    public function __construct(
        public readonly string $eventId,
        public readonly string $providerReference,
        public readonly string $state,
        public readonly ?int $amountMinor = null,
        public readonly ?string $currency = null,
    ) {}
}
