<?php

namespace App\Services\Advertising;

/**
 * E14 (بند 582/AB): تحقُّق مستوى النطاق (Domain) من أمان رابط الوجهة -
 * يُستدعى من كل مسار كتابة (Filament/Service)، لا من الواجهة فقط. HTTPS
 * مُفضَّل وإلزامي فعليًا (نرفض غير https تمامًا لتبسيط الضمان الأمني ولأن
 * لا سبب مشروع لرابط راعٍ بروتوكوله http قديم).
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

        // صفر نقاط تسلل Userinfo بالرابط (user:pass@host) - غير آمن لوجهة راعٍ.
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
