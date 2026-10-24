<?php

namespace App\Services\Commerce;

use Carbon\CarbonImmutable;

/** E41 - حالة دفعة كما يعيدها المزوّد عند الاستعلام (مزامنة/مطابقة). حالة مطبَّعة + مراجع + مبلغ؛ لا اختراع حالة إن لم يعرف المزوّد (يعيد null). */
final class CommerceProviderStatus
{
    public function __construct(
        public readonly CommerceProviderState $state,
        public readonly ?string $sessionId = null,
        public readonly ?string $paymentReference = null,
        public readonly ?string $refundReference = null,
        public readonly ?int $amountMinor = null,
        public readonly ?string $currency = null,
        public readonly ?CarbonImmutable $paidAt = null,
    ) {}
}
