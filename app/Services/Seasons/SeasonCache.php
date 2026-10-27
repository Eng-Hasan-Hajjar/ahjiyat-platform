<?php

namespace App\Services\Seasons;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * E42 - كاش **تعريفات الموسم العامة فقط** (جدول زمني عام مشتقّ من مصادره). لا يُخزَّن فيه أبدًا ما يخص مستخدمًا (تقدّم حملة، ملكية مسار، تسجيل منافسة، إسناد مهام).
 * الإبطال: رقم إصدار يرتفع مع كل حفظ/حذف لموسم أو مرحلة أو رابط أو إعلان (خطافات النماذج + SeasonBuilderService) + TTL قصير كحدّ أعلى. الحالة تُحسب وقت القراءة.
 * فشل الكاش (Redis متوقف مثلًا) لا يكسر حفظًا إداريًا ولا صفحة: يُبلَّغ ويُتجاوز.
 */
final class SeasonCache
{
    private const VERSION_KEY = 'seasons:definition_version';

    public static function version(): int
    {
        try {
            return (int) Cache::get(self::VERSION_KEY, 1);
        } catch (\Throwable $e) {
            report($e);

            return 1;
        }
    }

    public static function flush(): void
    {
        try {
            Cache::forever(self::VERSION_KEY, self::version() + 1);
            \App\Services\LiveOps\Calendar\EventCalendarService::flush(); // لقطة التقويم العام تضم المواسم أيضًا
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public static function ttl(): int
    {
        return max(0, (int) config('seasons.hub.cache_seconds', 60));
    }

    /** @template T @param Closure(): T $build @return T */
    public static function remember(string $name, Closure $build): mixed
    {
        $ttl = self::ttl();

        if ($ttl === 0) {
            return $build();
        }

        try {
            return Cache::remember('seasons:'.$name.':v'.self::version(), $ttl, $build);
        } catch (\Throwable $e) {
            report($e);

            return $build();
        }
    }
}
