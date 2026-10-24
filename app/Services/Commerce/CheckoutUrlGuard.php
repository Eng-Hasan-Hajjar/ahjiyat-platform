<?php

namespace App\Services\Commerce;

/**
 * E41 - حارس عنوان صفحة الدفع المستضافة (AA2/AA3): لا إعادة توجيه مفتوحة ولا SSRF. العنوان يأتي من محوّل المزوّد الموثوق فقط، ثم يُتحقق منه **عند التخزين وعند إعادة التوجيه**:
 *  - https فقط (http مسموح لمسار sandbox المحلي على مضيف التطبيق نفسه فقط)، بلا userinfo ولا محارف تحكّم ولا طول مفرط.
 *  - المضيف ضمن قائمة المضيفين المسموحة للمزوّد (commerce.checkout.allowed_hosts[provider]) بالمطابقة التامة. sandbox: مضيف التطبيق ومسار /commerce/sandbox/ فقط.
 * أي شيء آخر ⇒ يرفضه الحارس ولا يُوجَّه إليه المستخدم.
 */
final class CheckoutUrlGuard
{
    public const MAX_LENGTH = 1000;

    public static function allows(string $providerKey, string $url): bool
    {
        if ($url === '' || strlen($url) > self::MAX_LENGTH || preg_match('/[\x00-\x20\x7F\\\\]/', $url) === 1) {
            return false;
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);

        if ($providerKey === SandboxCommercePaymentProvider::KEY) {
            if (! CommerceEnvironment::sandboxAllowed() || ! in_array($scheme, ['http', 'https'], true)) {
                return false;
            }

            $appHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
            $currentHost = strtolower((string) parse_url(url('/'), PHP_URL_HOST));
            $path = (string) ($parts['path'] ?? '');

            return in_array($host, array_filter([$appHost, $currentHost]), true) && str_starts_with($path, '/commerce/sandbox/');
        }

        if ($scheme !== 'https') {
            return false;
        }

        $allowed = array_map('strtolower', (array) (config('commerce.checkout.allowed_hosts')[$providerKey] ?? []));

        return in_array($host, $allowed, true);
    }
}
