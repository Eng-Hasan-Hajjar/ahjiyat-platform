<?php

namespace App\Services\Operations;

use App\Support\ReleaseInfo;
use App\Support\SchedulerHeartbeat;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * E25 - ملخص إشارات تشغيلية للقراءة فقط لمركز العمليات (Filament): الإصدار، البيئة، أسماء المشغّلات (driver) فقط، نبض المجدول، الطابور، وجاهزية القاعدة/الكاش.
 *  - لا أسرار ولا مضيفون ولا بيانات اعتماد: أسماء مشغّلات وأرقام وحالات.
 *  - ثقيلة بالقدر الأدنى: استعلام select 1 + قراءة مفتاح كاش + إشارات الطابور، والناتج كله يُخزَّن 30ث في الكاش الافتراضي (يُحسب مباشرة إن تعطل الكاش).
 *  - لا تكتب في الكاش غير مفتاح اللقطة نفسه (لا مفاتيح اختبار): القراءة وحدها تكفي لقياس صحة الكاش هنا.
 */
final class OperationalSignals
{
    public const CACHE_KEY = 'ops:signals:v1';

    public function __construct(
        private readonly SchedulerHeartbeat $heartbeat,
        private readonly QueueSignals $queue,
    ) {}

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        $ttl = (int) config('operations.queue.signals_cache_seconds', 30);

        if ($ttl <= 0) {
            return $this->compute();
        }

        try {
            return Cache::remember(self::CACHE_KEY, $ttl, fn () => $this->compute());
        } catch (Throwable) {
            return $this->compute();
        }
    }

    /** @return array<string, mixed> */
    public function compute(): array
    {
        $queue = $this->queue->snapshot();

        return [
            'release' => ReleaseInfo::short(),
            'environment' => (string) config('app.env'),
            'debug' => (bool) config('app.debug'),
            'maintenance' => app()->isDownForMaintenance(),
            'drivers' => [
                'cache' => (string) config('cache.default'),
                'session' => (string) config('session.driver'),
                'queue' => (string) config('queue.default'),
                'broadcast' => (string) config('broadcasting.default'),
                'mail' => (string) config('mail.default'),
            ],
            'scheduler' => [
                'status' => $this->heartbeat->status(),
                'age_seconds' => $this->heartbeat->ageSeconds(),
            ],
            'queue' => $queue,
            'queue_warnings' => $this->queue->warnings($queue),
            'readiness' => [
                'database' => $this->timed(fn () => DB::select('select 1')),
                'cache' => $this->timed(fn () => Cache::get($this->heartbeat->key())),
            ],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /** @return array{ok: bool, ms: float} */
    private function timed(\Closure $fn): array
    {
        $start = hrtime(true);

        try {
            $fn();
            $ok = true;
        } catch (Throwable) {
            $ok = false;
        }

        return ['ok' => $ok, 'ms' => round((hrtime(true) - $start) / 1e6, 1)];
    }
}
