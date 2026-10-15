<?php

namespace App\Support\Security;

/**
 * E26: بصمات غير قابلة للعكس لاستعمالها في مفاتيح المحدِّدات والسجلات الأمنية بدل القيم الخام (بريد/رمز). لا تُستعمل لأي غرض مصادقة.
 */
final class Fingerprint
{
    /**
     * SHA-256 لبريد مُطبَّع (trim + أحرف صغيرة) مقتطع لـ32 خانة. مفاتيح RateLimiter تُخزَّن بلا تجزئة بالكاش، فالبريد الخام فيها تسريب PII ويسمح بتضخيم
     * المفاتيح بقيم طويلة. المدخل غير النصي/الفارغ يعطي بصمة ثابتة واحدة (لا استثناء).
     */
    public static function email(mixed $email): string
    {
        $normalized = is_string($email) ? mb_strtolower(trim($email)) : '';

        return substr(hash('sha256', $normalized), 0, 32);
    }
}
