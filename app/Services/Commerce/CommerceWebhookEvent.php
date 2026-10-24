<?php

namespace App\Services\Commerce;

use Carbon\CarbonImmutable;

/**
 * E41 - حدث مزوّد **موثَّق التوقيع ومطبَّع** (خرج verifyWebhook). لا يحمل الحمولة الخام: بصمتها فقط.
 * reference = مرجع الشراء الذي أرسلناه للمزوّد عند إنشاء الجلسة (reference_code) ويعيده المزوّد؛ sessionId = معرّف الجلسة عند المزوّد.
 */
final class CommerceWebhookEvent
{
    public function __construct(
        public readonly string $provider,
        public readonly string $eventId,
        public readonly string $eventType,
        public readonly CommerceProviderState $state,
        public readonly ?string $sessionId,
        public readonly ?string $paymentReference,
        public readonly ?string $refundReference,
        public readonly ?string $reference,
        public readonly ?int $amountMinor,
        public readonly ?string $currency,
        public readonly ?CarbonImmutable $occurredAt,
        public readonly ?string $payloadSha256,
    ) {}
}
