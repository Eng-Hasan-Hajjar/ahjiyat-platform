<?php

namespace App\Console\Commands;

use App\Services\Notifications\ReEngagementService;
use Illuminate\Console\Command;

class DispatchReEngagementNotifications extends Command
{
    protected $signature = 'notifications:dispatch-reengagement';

    protected $description = 'E15: تذكيرات العودة (تحذير السلسلة + جاهزية مهام اليوم). Idempotent وآمن للتشغيل المتكرر؛ يُجدوَل ساعيًا.';

    public function handle(ReEngagementService $service): int
    {
        try {
            $result = $service->run();
        } catch (\Throwable $e) {
            report($e);
            $this->error('فشل تشغيل تذكيرات العودة: '.$e->getMessage());

            return self::FAILURE;
        }

        foreach ($result as $name => $stats) {
            $this->line(sprintf(
                '%s: candidates=%d created=%d duplicate=%d suppressed=%d failed=%d%s',
                $name, $stats['candidates'], $stats['created'], $stats['duplicate'], $stats['suppressed'], $stats['failed'],
                isset($stats['skipped']) ? " (skipped: {$stats['skipped']})" : '',
            ));
        }

        return self::SUCCESS;
    }
}
