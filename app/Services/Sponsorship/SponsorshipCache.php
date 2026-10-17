<?php

namespace App\Services\Sponsorship;

use App\Support\CachedSnapshot;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * E30 - كاش العرض العام للرعاية (لوحات الصدارة، شرائح الرعاة، المستويات). **ليس سلطة** أبدًا: لا يُقرأ لتأكيد/استرداد/منح مكافأة (تلك من قاعدة البيانات حصرًا).
 * ما يُخزَّن معرّفات ومجاميع فقط (لا أسماء ولا روابط)، والظهور العام يُعاد فحصه حيًّا بكل عرض. أي تغيير مالي/ظهور يرفع «إصدار» الكاش فتتبدّل المفاتيح فورًا.
 * فشل الكاش = حساب مباشر (CachedSnapshot fail-open). TTL محدود دائمًا.
 */
final class SponsorshipCache
{
    private const VERSION_KEY = 'sponsorship:version';

    public static function version(): string
    {
        try {
            return (string) Cache::get(self::VERSION_KEY, '1');
        } catch (\Throwable) {
            return '0';
        }
    }

    /** يبطل كل لقطات الرعاية فورًا (يُستدعى بعد أي تأكيد/إلغاء/استرداد/تغيير برنامج أو دور أو ملف أو مستوى). */
    public static function bust(): void
    {
        try {
            Cache::forever(self::VERSION_KEY, Str::lower(Str::random(10)));
        } catch (\Throwable) {
            // الكاش غير متاح: لا لقطات أصلًا
        }
    }

    /**
     * @template T
     *
     * @param  \Closure(): T  $compute
     * @return T
     */
    public static function remember(string $name, array $parts, ?int $ttl, \Closure $compute): mixed
    {
        $key = 'sponsorship:v'.self::version().':'.$name.':'.implode(':', array_map('strval', $parts));

        return CachedSnapshot::remember($key, $ttl ?? (int) config('sponsorship.public.cache_ttl', 120), $compute);
    }
}
