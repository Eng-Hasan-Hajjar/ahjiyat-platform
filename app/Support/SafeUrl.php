<?php

namespace App\Support;

/**
 * E30 - تطبيع روابط المواقع الخارجية (موقع داعم). http/https فقط؛ لا بيانات اعتماد داخل الرابط (user:pass@ خدعة تصيّد شائعة)، لا مضيف فارغ ولا IP ولا localhost،
 * لا محارف تحكم أو مسافات، ولا منافذ غير قياسية. النتيجة سلسلة مُعاد بناؤها من أجزاء مُحقَّقة (المضيف بصيغة ASCII/punycode). null = رابط مرفوض.
 * العرض دائمًا مع rel="noopener noreferrer sponsored" وtarget=_blank، والرابط لا يُنفَّذ كـHTML أبدًا (Blade المهرَّب).
 */
final class SafeUrl
{
    public static function normalize(?string $url, int $maxLength = 255): ?string
    {
        $url = trim((string) $url);

        if ($url === '' || mb_strlen($url) > $maxLength || preg_match('/[\x00-\x20\x7F\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', $url) === 1) {
            return null;
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $scheme = strtolower((string) $parts['scheme']);

        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $host = strtolower((string) $parts['host']);

        if (function_exists('idn_to_ascii') && preg_match('/[^\x00-\x7F]/', $host) === 1) {
            $converted = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTF46);
            $host = is_string($converted) ? $converted : '';
        }

        if ($host === '' || $host === 'localhost' || ! str_contains($host, '.') || filter_var($host, FILTER_VALIDATE_IP) !== false
            || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return null;
        }

        if (isset($parts['port']) && ! in_array((int) $parts['port'], [80, 443], true)) {
            return null;
        }

        $rebuilt = $scheme.'://'.$host.(isset($parts['port']) ? ':'.$parts['port'] : '').($parts['path'] ?? '').(isset($parts['query']) ? '?'.$parts['query'] : '');

        return mb_strlen($rebuilt) <= $maxLength ? $rebuilt : null;
    }
}
