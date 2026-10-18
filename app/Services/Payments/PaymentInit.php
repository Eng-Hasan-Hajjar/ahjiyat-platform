<?php

namespace App\Services\Payments;

/** E32 - نتيجة إنشاء الدفع لدى المزوّد: مرجع المزوّد (إن وُجد) ورابط إعادة توجيه (للمزوّدين الإلكترونيين). اليدوي: كلاهما null. */
final class PaymentInit
{
    public function __construct(public readonly ?string $providerReference = null, public readonly ?string $redirectUrl = null) {}
}
