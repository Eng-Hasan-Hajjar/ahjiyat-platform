<?php

namespace App\Services\Notifications;

use Carbon\Carbon;
use Illuminate\Notifications\DatabaseNotification;

/**
 * تحليلات بسيطة مجمَّعة فقط: المُنشأ والمقروء ونسبة القراءة بحسب النوع. لا هوية مستخدم، لا تتبّع نقرات
 * (فلا ندّعي click-through)، لا إسناد تسويقي. واجهة إدارية: مؤجَّلة (الخدمة جاهزة؛ تُضاف صلاحية
 * notifications.analytics.view عند بناء الواجهة فقط حتى لا تكون صلاحية بلا تنفيذ).
 */
class NotificationAnalyticsService
{
    /** @return array{created: int, read: int, read_rate: float, by_type: array<string, array{created: int, read: int, read_rate: float}>} */
    public function overview(?Carbon $from = null, ?Carbon $to = null): array
    {
        $base = DatabaseNotification::query()
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to));

        $rows = (clone $base)
            ->selectRaw('type_key, count(*) as created, sum(case when read_at is not null then 1 else 0 end) as read_count')
            ->groupBy('type_key')
            ->get();

        $created = 0;
        $read = 0;
        $byType = [];

        foreach ($rows as $row) {
            $c = (int) $row->created;
            $r = (int) $row->read_count;
            $created += $c;
            $read += $r;
            $byType[$row->type_key] = ['created' => $c, 'read' => $r, 'read_rate' => $this->rate($r, $c)];
        }

        return ['created' => $created, 'read' => $read, 'read_rate' => $this->rate($read, $created), 'by_type' => $byType];
    }

    protected function rate(int $read, int $created): float
    {
        return $created > 0 ? round($read / $created, 4) : 0.0;
    }
}
