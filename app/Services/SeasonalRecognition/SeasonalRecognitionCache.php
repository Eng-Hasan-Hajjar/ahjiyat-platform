<?php

namespace App\Services\SeasonalRecognition;

use Closure;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * E44 - كاش لقطات اللوحات العامة وتعريف الباقات. **ليس سلطة كتابة أبدًا**: كل كتابة تحسم من قاعدة البيانات ثم تُبطل الإصدار بعد الـcommit.
 *
 * نمط الإصدار (version key) بدل الوسوم: يعمل على كل مخازن الكاش (array/file/database/redis). المفتاح = مجال + موسم + إصدار؛ زيادة الإصدار تُهمل القديم فينقضي بـTTL.
 * أي فشل في الكاش لا يكسر صفحة ولا كتابة (يُقرأ من المصدر مباشرةً).
 */
final class SeasonalRecognitionCache
{
    public const SCOPE_WRITERS = 'writers';

    public const SCOPE_SUPPORTERS = 'supporters';

    public static function version(int $seasonId, string $scope): string
    {
        try {
            return (string) Cache::get(self::versionKey($seasonId, $scope), '0');
        } catch (Throwable) {
            return '0';
        }
    }

    /** يُستدعى بعد commit تغيير مؤثر على اللوحات العامة. */
    public static function bump(int $seasonId, string $scope): void
    {
        try {
            Cache::forever(self::versionKey($seasonId, $scope), (string) hrtime(true));
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $resolve
     * @return T
     */
    public static function remember(int $seasonId, string $scope, string $name, Closure $resolve, ?int $ttl = null): mixed
    {
        $ttl ??= max(1, (int) config('seasonal_recognition.cache.ttl_seconds', 30));

        try {
            return Cache::remember(
                "seasonal:{$scope}:{$seasonId}:{$name}:".self::version($seasonId, $scope),
                $ttl,
                $resolve,
            );
        } catch (Throwable $e) {
            report($e);

            return $resolve();
        }
    }

    private static function versionKey(int $seasonId, string $scope): string
    {
        return "seasonal:{$scope}:{$seasonId}:version";
    }
}
