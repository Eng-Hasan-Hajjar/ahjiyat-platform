<?php

namespace App\Support;

use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * E24: كاش لقطة **للعرض فقط** مع حماية من التدافع (stampede): عند الانتهاء يحسب عملية واحدة فقط (قفل ذرّي) والباقي ينتظر ثوانيَ ثم يقرأ القيمة الجاهزة،
 * بدل أن يشغّل عشرات الطلبات الاستعلام الثقيل نفسه معًا في اللحظة ذاتها.
 *
 * ضماناتها:
 *  - فشل الكاش/القفل بأي شكل (متجر لا يدعم الأقفال، Redis غير متاح…) => يُحسب مباشرةً (fail-open) ولا يفشل الطلب.
 *  - خطأ الحساب نفسه (استعلام فاشل) لا يُبتلَع ولا يُعاد حسابه: يصعد كما كان.
 *  - مدة محدودة دائمًا (TTL > 0)؛ TTL <= 0 يعني بلا كاش.
 *  - لا تُستعمل لأي بيانات سلطة (رصيد، أحقية مكافأة، فائز، صلاحية)، ولا لبيانات تختلف باختلاف المشاهد: على المستدعي أن يُدرج معرّف المستخدم في المفتاح إن كانت خاصة.
 */
final class CachedSnapshot
{
    /**
     * @template T
     *
     * @param  Closure(): T  $compute
     * @return T
     */
    public static function remember(string $key, int $ttl, Closure $compute, int $lockSeconds = 20, int $waitSeconds = 5): mixed
    {
        if ($ttl <= 0) {
            return $compute();
        }

        try {
            $hit = Cache::get($key);

            if ($hit !== null) {
                return $hit;
            }

            $lock = Cache::lock($key.':lock', $lockSeconds);
            $lock->block($waitSeconds);
        } catch (LockTimeoutException) {
            // انتظرنا طويلًا: لا نُفشل الطلب ولا نكدّس الانتظار؛ نحسب مباشرةً (حالة نادرة).
            return $compute();
        } catch (\Throwable) {
            return $compute();
        }

        try {
            try {
                $hit = Cache::get($key);

                if ($hit !== null) {
                    return $hit; // عملية أخرى حسبته أثناء انتظارنا
                }
            } catch (\Throwable) {
                // نكمل بالحساب
            }

            $value = $compute();

            try {
                Cache::put($key, $value, $ttl);
            } catch (\Throwable) {
                // القيمة صحيحة حتى لو تعذّر حفظها
            }

            return $value;
        } finally {
            try {
                $lock->release();
            } catch (\Throwable) {
                // ينتهي القفل تلقائيًا بعد $lockSeconds
            }
        }
    }
}
