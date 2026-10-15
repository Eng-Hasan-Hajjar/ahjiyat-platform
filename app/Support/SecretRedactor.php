<?php

namespace App\Support;

use Illuminate\Support\Arr;

/**
 * E25 - شبكة أمان أخيرة لمخرجات أدوات التشغيل (production:check, release:preflight, release:smoke).
 * الأدوات لا تطبع قيمًا سرية أصلًا (رسائلها تحمل أسماء الإعدادات فقط)؛ هذه الطبقة تضمن ألا يتسرّب سر لو دخل نص خطأ أو قيمة إعداد في رسالة بالخطأ.
 *
 * المصدر: كل قيمة نصية بإعدادات التطبيق الحالية يحوي آخر مقطع من مفتاحها (password|secret|token|api_key|private_key|webhook|dsn|credential) + قيم url/dsn داخل إعدادات القاعدة/السجلات/البريد/الطابور/الكاش (قد تحمل كلمة مرور مضمَّنة) + APP_KEY (وجزؤه base64) + مفاتيح التدوير السابقة.
 * الحد الأدنى للطول 6 كي لا تُحجب كلمات قصيرة شائعة (null/true/false/users…).
 */
final class SecretRedactor
{
    public const MASK = '[redacted]';

    /** الكلمة المفتاحية داخل آخر مقطع من المسار (يشمل webhook_url وapi_token وclient_secret…). */
    private const KEY_PATTERN = '/(password|passwd|secret|token|api_?key|private_?key|webhook|dsn|credential)[a-z0-9_]*$/i';

    /** روابط اتصال قد تُضمِّن كلمة المرور (DATABASE_URL، REDIS_URL، LOG_SLACK_WEBHOOK_URL، MAIL_URL…). */
    private const URL_PATTERN = '/^(database|logging|mail|queue|cache|session|broadcasting)\..*\.(url|uri|dsn)$/i';

    /** @return list<string> */
    public static function secrets(): array
    {
        $values = [];

        $appKey = config('app.key');
        if (is_string($appKey) && $appKey !== '') {
            $values[] = $appKey;
            if (str_starts_with($appKey, 'base64:')) {
                $values[] = substr($appKey, 7);
            }
        }

        foreach ((array) config('app.previous_keys', []) as $previous) {
            if (is_string($previous) && $previous !== '') {
                $values[] = $previous;
                if (str_starts_with($previous, 'base64:')) {
                    $values[] = substr($previous, 7);
                }
            }
        }

        foreach (Arr::dot(config()->all()) as $path => $value) {
            if (is_string($value) && (preg_match(self::KEY_PATTERN, (string) $path) === 1 || preg_match(self::URL_PATTERN, (string) $path) === 1)) {
                $values[] = $value;
            }
        }

        // أسماء الأصناف (Facades\Password، Middleware\ValidateCsrfToken…) ليست أسرارًا وتأتي من مفاتيح تحمل كلمة password/token بالصدفة.
        return array_values(array_unique(array_filter($values, fn ($v) => is_string($v) && strlen($v) >= 6 && preg_match('/^[A-Za-z0-9_]+(\\\\[A-Za-z0-9_]+)+$/', $v) !== 1)));
    }

    /** @param  list<string>|null  $secrets */
    public static function scrub(string $text, ?array $secrets = null): string
    {
        $secrets ??= self::secrets();

        if ($secrets === []) {
            return $text;
        }

        // الأطول أولًا كي لا يترك استبدال الجزء القصير بقايا من القيمة الأطول.
        usort($secrets, fn (string $a, string $b) => strlen($b) <=> strlen($a));

        return str_replace($secrets, self::MASK, $text);
    }
}
