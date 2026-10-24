<?php

namespace App\Services\Commerce;

use Carbon\CarbonInterface;

/**
 * E41 - أداة توقيع webhook عامة منخفضة المستوى (HMAC-SHA256 على «الطابع.الجسم»)، يعاد استخدامها من محوّلات المزوّدين التي تتبع بروتوكولًا مماثلًا.
 * الترويسة: `t=<unix>,v1=<hex>` (يمكن تكرار v1 لتدوير الأسرار). المقارنة بزمن ثابت (hash_equals). الطابع خارج النافذة ⇒ رفض (replay).
 * **لا تسجّل السر ولا التوقيع ولا الجسم** في أي رسالة خطأ.
 */
final class WebhookSignature
{
    public static function sign(string $secret, int $timestamp, string $payload): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
    }

    public static function header(string $secret, int $timestamp, string $payload): string
    {
        return 't='.$timestamp.',v1='.self::sign($secret, $timestamp, $payload);
    }

    /**
     * @return array{t: int, v1: list<string>}|null
     */
    public static function parse(string $header): ?array
    {
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($key === 't' && ctype_digit($value) && strlen($value) <= 12) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1' && preg_match('/^[a-f0-9]{64}$/', $value) === 1) {
                $signatures[] = $value;
            }
        }

        return ($timestamp === null || $signatures === []) ? null : ['t' => $timestamp, 'v1' => $signatures];
    }

    /**
     * @throws CommerceWebhookException 401 توقيع غائب/خاطئ، 400 طابع خارج النافذة
     */
    public static function verify(?string $header, string $payload, string $secret, int $toleranceSeconds, CarbonInterface $now): int
    {
        if ($secret === '') {
            throw new CommerceWebhookException('not_configured', 'مزوّد غير مهيأ لاستقبال الأحداث.', 404);
        }

        if ($header === null || trim($header) === '') {
            throw new CommerceWebhookException('missing_signature', 'توقيع الحدث غائب.', 401);
        }

        $parsed = self::parse($header);

        if ($parsed === null) {
            throw new CommerceWebhookException('invalid_signature', 'توقيع الحدث غير صالح.', 401);
        }

        $expected = self::sign($secret, $parsed['t'], $payload);
        $valid = false;

        foreach ($parsed['v1'] as $candidate) {
            // لا تقصير مبكر: المقارنة لكل المرشحين بزمن ثابت.
            $valid = hash_equals($expected, $candidate) || $valid;
        }

        if (! $valid) {
            throw new CommerceWebhookException('invalid_signature', 'توقيع الحدث غير صالح.', 401);
        }

        if (abs($now->getTimestamp() - $parsed['t']) > max(1, $toleranceSeconds)) {
            throw new CommerceWebhookException('stale_timestamp', 'الحدث خارج نافذة الصلاحية.', 400);
        }

        return $parsed['t'];
    }
}
