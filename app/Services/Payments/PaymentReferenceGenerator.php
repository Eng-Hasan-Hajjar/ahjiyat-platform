<?php

namespace App\Services\Payments;

use App\Models\SponsorshipPayment;

/**
 * E32 - مرجع التحويل البشري (SUP-XXXXXXXX). أبجدية بلا أحرف ملتبسة وعشوائية قوية (random_int)؛ التفرّد الفعلي بقيد UNIQUE في قاعدة البيانات
 * (هذا الفحص تحسين لتجنّب التصادم فقط، والخدمة تعيد المحاولة عند انتهاك القيد).
 */
final class PaymentReferenceGenerator
{
    public static function generate(): string
    {
        $alphabet = (string) config('payments.request.reference_alphabet', 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789');
        $length = max(6, min(16, (int) config('payments.request.reference_length', 8)));
        $prefix = strtoupper((string) config('payments.request.reference_prefix', 'SUP'));
        $max = strlen($alphabet) - 1;

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $code = '';

            for ($i = 0; $i < $length; $i++) {
                $code .= $alphabet[random_int(0, $max)];
            }

            $reference = $prefix.'-'.$code;

            if (! SponsorshipPayment::query()->where('reference', $reference)->exists()) {
                return $reference;
            }
        }

        return $prefix.'-'.strtoupper(bin2hex(random_bytes(max(4, intdiv($length, 2)))));
    }
}
