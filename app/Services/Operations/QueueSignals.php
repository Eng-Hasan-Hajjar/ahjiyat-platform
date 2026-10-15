<?php

namespace App\Services\Operations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * E25 - إشارات الطابور للقراءة فقط (لمركز العمليات وproduction:check): العدد المنتظر الآن، عمر أقدم مهمة منتظرة، وعدد المهام الفاشلة.
 * لا تعدّل شيئًا ولا تكشف حمولة المهام أو أسماء الفئات: أرقام فقط. أي فشل بالقراءة يعطي null (غير معروف) ولا يرمي.
 * مع طابور database: استعلامان على جدول jobs (count + min) ويُخزَّن ناتج مركز العمليات 30ث، فلا تتحول الصفحة إلى عنق زجاجة (E24/S3).
 */
final class QueueSignals
{
    /** @return array{driver: string, pending: ?int, oldest_age_seconds: ?int, failed: ?int} */
    public function snapshot(): array
    {
        $driver = (string) config('queue.default');
        $out = ['driver' => $driver, 'pending' => null, 'oldest_age_seconds' => null, 'failed' => $this->failedCount()];

        $driverName = (string) config("queue.connections.{$driver}.driver", $driver);

        if ($driverName === 'database') {
            try {
                $query = DB::connection(config("queue.connections.{$driver}.connection"))
                    ->table((string) config("queue.connections.{$driver}.table", 'jobs'))
                    ->whereNull('reserved_at')
                    ->where('available_at', '<=', now()->getTimestamp());

                $out['pending'] = (clone $query)->count();
                $oldest = (clone $query)->min('available_at');
                $out['oldest_age_seconds'] = $oldest === null ? 0 : max(0, now()->getTimestamp() - (int) $oldest);
            } catch (Throwable) {
                // يبقى null: غير معروف
            }
        } elseif ($driverName === 'redis') {
            try {
                $out['pending'] = (int) Queue::connection($driver)->size();
            } catch (Throwable) {
            }
        }

        return $out;
    }

    /**
     * @param  array{driver: string, pending: ?int, oldest_age_seconds: ?int, failed: ?int}  $snapshot
     * @return list<string> رسائل تنبيه عربية (فارغة = سليم)
     */
    public function warnings(array $snapshot): array
    {
        $warnings = [];

        $maxAge = (int) config('operations.queue.backlog_warn_age_seconds', 900);
        if ($maxAge > 0 && ($snapshot['oldest_age_seconds'] ?? 0) >= $maxAge) {
            $warnings[] = 'تراكم بالطابور: أقدم مهمة منتظرة منذ '.intdiv((int) $snapshot['oldest_age_seconds'], 60).' دقيقة (تحقق من عمل queue:work)';
        }

        $failedWarn = (int) config('operations.queue.failed_jobs_warn', 1);
        if ($failedWarn > 0 && ($snapshot['failed'] ?? 0) >= $failedWarn) {
            $warnings[] = 'يوجد '.$snapshot['failed'].' مهمة فاشلة (راجع queue:failed قبل أي إعادة محاولة، خصوصًا المهام الاقتصادية)';
        }

        return $warnings;
    }

    private function failedCount(): ?int
    {
        if (! in_array(config('queue.failed.driver'), ['database', 'database-uuids'], true)) {
            return null;
        }

        try {
            return DB::connection(config('queue.failed.database'))->table((string) config('queue.failed.table', 'failed_jobs'))->count();
        } catch (Throwable) {
            return null;
        }
    }
}
