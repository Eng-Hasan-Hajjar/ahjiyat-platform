<?php

namespace App\Services\Commerce;

use App\Models\DigitalPurchase;
use Carbon\CarbonImmutable;

/**
 * E41 - طلب إنشاء جلسة دفع مستضافة. كل شيء من الخادم: المبلغ والعملة من لقطة DigitalPurchase، والروابط تُولَّد بالخادم (لا رابط من العميل)،
 * ومفتاح idempotency ثابت مشتق من المعرّف العام والجيل (لا مفتاح عشوائي لكل محاولة) كي يستطيع المزوّد الحقيقي إزالة التكرار.
 */
final class CommerceCheckoutRequest
{
    public function __construct(
        public readonly DigitalPurchase $purchase,
        public readonly string $idempotencyKey,
        public readonly string $successUrl,
        public readonly string $cancelUrl,
        public readonly CarbonImmutable $expiresAt,
    ) {}
}
