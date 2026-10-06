<?php

namespace App\Console\Commands;

use App\Services\Competitive\CompetitiveLifecycleService;
use Illuminate\Console\Command;

class ProcessCompetitiveLifecycle extends Command
{
    protected $signature = 'competitive:process-lifecycle';

    protected $description = 'E17: تجسيد انتهاء التحديات، إشعارات بدء/قرب نهاية المنافسات، واعتماد نتائج المنتهية (ساعي، Idempotent)';

    public function handle(CompetitiveLifecycleService $lifecycle): int
    {
        $stats = $lifecycle->run();

        $this->info(sprintf(
            'التحديات: منتهية %d، مُلغاة %d | بدأت: جديدة %d، سابقة %d | تنتهي قريبًا: جديدة %d، سابقة %d، فوق الميزانية %d | معتمَدة: %d',
            $stats['challenges_expired'], $stats['challenges_cancelled'], $stats['started']['created'], $stats['started']['already'],
            $stats['ending_soon']['created'], $stats['ending_soon']['already'], $stats['ending_soon']['over_budget'], $stats['finalized'],
        ));

        return self::SUCCESS;
    }
}
