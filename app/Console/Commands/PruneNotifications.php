<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;

class PruneNotifications extends Command
{
    protected $signature = 'notifications:prune {--days= : عدد الأيام (الافتراضي من config/player_notifications.php)}';

    protected $description = 'E15: يحذف الإشعارات المقروءة الأقدم من فترة الاحتفاظ. لا يمسّ غير المقروء أبدًا. صحة النظام لا تعتمد عليه.';

    public function handle(): int
    {
        $days = max(1, (int) ($this->option('days') ?: config('player_notifications.retention_days')));
        $cutoff = now()->subDays($days);
        $total = 0;

        do {
            $ids = DatabaseNotification::query()
                ->whereNotNull('read_at')
                ->where('read_at', '<', $cutoff)
                ->limit(500)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $total += DatabaseNotification::query()->whereIn('id', $ids)->delete();
        } while (true);

        $this->info("حُذف {$total} إشعارًا مقروءًا أقدم من {$days} يومًا.");

        return self::SUCCESS;
    }
}
