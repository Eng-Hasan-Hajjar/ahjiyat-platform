<?php

namespace App\Services\Payments;

/**
 * E32 - حارس إعدادات الدفع اليدوي: هذه الحقول **علنية للمحوِّل** فقط (مستفيد/بنك/آيبان/ملاحظات). يرفض بالبناء:
 *  - أي ذكر لبيانات اعتماد أو أسرار (كلمة مرور بنكية/PIN/CVV/مفتاح API/مفتاح خاص/رمز دخول).
 *  - أرقام بطاقات دفع صالحة (16/15 رقمًا ببادئة شبكة معروفة + Luhn) — لا حاجة لها ولا يجوز عرضها.
 * الآيبان/رقم الحساب العاديان (أحرف+أرقام أو أطوال مختلفة) مسموحان. الفحص خادمي في الحفظ، ولا يُسجَّل المحتوى المرفوض.
 */
final class PaymentSettingsGuard
{
    private const SECRET_PATTERN = '/(password|passcode|passwd|pin\s*code|\bpin\b|\bcvv\b|\bcvc\b|api[\s_-]?key|secret|private[\s_-]?key|BEGIN [A-Z ]*PRIVATE KEY|token|كلمة\s*(المرور|السر)|رمز\s*(الدخول|الأمان)|الرقم\s*السري|مفتاح\s*(خاص|API))/iu';

    /**
     * @param  array<string, mixed>  $values
     * @return list<string> مفاتيح الحقول المخالفة (فارغ = سليم)
     */
    public static function violations(array $values): array
    {
        $bad = [];

        foreach ($values as $key => $value) {
            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            if (preg_match(self::SECRET_PATTERN, $value) === 1 || self::containsCardNumber($value)) {
                $bad[] = (string) $key;
            }
        }

        return $bad;
    }

    public static function containsCardNumber(string $text): bool
    {
        if (preg_match_all('/(?<![\w])(?:\d[ -]?){15,16}(?![\w])/u', $text, $matches) < 1) {
            return false;
        }

        foreach ($matches[0] as $candidate) {
            $digits = preg_replace('/\D/', '', $candidate) ?? '';

            if (! in_array(strlen($digits), [15, 16], true) || ! self::hasCardPrefix($digits)) {
                continue;
            }

            if (self::luhn($digits)) {
                return true;
            }
        }

        return false;
    }

    private static function hasCardPrefix(string $digits): bool
    {
        return preg_match('/^(4\d{15}|5[1-5]\d{14}|2(?:2[2-9]\d|[3-6]\d{2}|7[01]\d|720)\d{12}|3[47]\d{13}|6(?:011|5\d{2})\d{12})$/', $digits) === 1;
    }

    private static function luhn(string $digits): bool
    {
        $sum = 0;
        $alt = false;

        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $n = (int) $digits[$i];

            if ($alt) {
                $n *= 2;

                if ($n > 9) {
                    $n -= 9;
                }
            }

            $sum += $n;
            $alt = ! $alt;
        }

        return $sum % 10 === 0;
    }
}
