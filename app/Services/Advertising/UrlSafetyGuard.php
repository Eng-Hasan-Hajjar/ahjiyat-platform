<?php

namespace App\Services\Advertising;

/**
 * تحقُّق مستوى النطاق (Domain) من أمان رابط الوجهة - يُستدعى من كل مسار كتابة
 * (Filament/Service)، لا من الواجهة فقط. HTTPS إلزامي فعليًا.
 */
class UrlSafetyGuard
{
    public static function isSafe(?string $url): bool
    {
        if ($url === null || trim($url) === '') {
            return false;
        }

        $parsed = parse_url($url);

        if ($parsed === false || ! isset($parsed['scheme'], $parsed['host'])) {
            return false;
        }

        if (strtolower($parsed['scheme']) !== 'https') {
            return false;
        }

        // رفض Userinfo بالرابط (user:pass@host) - غير آمن لوجهة راعٍ.
        if (isset($parsed['user']) || isset($parsed['pass'])) {
            return false;
        }

        return true;
    }

    public static function assertSafe(?string $url): void
    {
        if (! self::isSafe($url)) {
            throw new \App\Exceptions\AdInvariantViolation('رابط الوجهة غير آمن أو ليس HTTPS صالحًا.');
        }
    }
}