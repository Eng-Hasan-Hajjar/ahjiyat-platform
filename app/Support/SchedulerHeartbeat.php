<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * E25 - نبض المجدول. مهمة مجدولة صغيرة (routes/console.php، كل دقيقة) تستدعي beat() فتكتب الطابع الزمني في الكاش الافتراضي.
 * اكتشاف توقف المجدول = عمر آخر نبضة. لا جدول جديد ولا ترحيل: الكاش كافٍ لأن النبضة قيمة تُجدَّد كل دقيقة وفقدانها يعني «غير معروف» لا أكثر.
 *
 * ليس مصدر حقيقة لأي منطق لعب أو اقتصاد: للعرض والتنبيه فقط. أي عطل بالكاش يعطي unknown ولا يرمي استثناءً (fail-open).
 */
final class SchedulerHeartbeat
{
    public const OK = 'ok';

    public const STALE = 'stale';

    public const DOWN = 'down';

    public const UNKNOWN = 'unknown';

    public function key(): string
    {
        return (string) config('operations.scheduler.heartbeat_key', 'ops:scheduler:heartbeat');
    }

    public function beat(): void
    {
        try {
            Cache::put($this->key(), now()->getTimestamp(), (int) config('operations.scheduler.heartbeat_ttl_seconds', 86400));
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** طابع آخر نبضة (ثوانٍ يونكس) أو null. */
    public function lastBeatAt(): ?int
    {
        try {
            $value = Cache::get($this->key());
        } catch (Throwable) {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    public function ageSeconds(): ?int
    {
        $at = $this->lastBeatAt();

        return $at === null ? null : max(0, now()->getTimestamp() - $at);
    }

    public function status(): string
    {
        $age = $this->ageSeconds();

        if ($age === null) {
            return self::UNKNOWN;
        }

        if ($age >= (int) config('operations.scheduler.down_after_seconds', 900)) {
            return self::DOWN;
        }

        if ($age >= (int) config('operations.scheduler.stale_after_seconds', 300)) {
            return self::STALE;
        }

        return self::OK;
    }
}
