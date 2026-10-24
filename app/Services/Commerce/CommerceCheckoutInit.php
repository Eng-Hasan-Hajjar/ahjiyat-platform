<?php

namespace App\Services\Commerce;

use Carbon\CarbonImmutable;

/** E41 - ناتج إنشاء جلسة الدفع: معرّف الجلسة عند المزوّد + عنوان الصفحة المستضافة (من المزوّد حصرًا) + انتهاء الجلسة. */
final class CommerceCheckoutInit
{
    public function __construct(
        public readonly string $sessionId,
        public readonly string $url,
        public readonly CarbonImmutable $expiresAt,
    ) {}
}
