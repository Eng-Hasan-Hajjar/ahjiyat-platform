<?php

namespace App\Console\Commands;

use App\Models\Season;
use App\Services\SeasonLifecycleService;
use Illuminate\Console\Command;

/**
 * يلتقط البدء الناتج عن مرور الوقت (starts_at). لا منطق هنا: كل القرار بـSeasonLifecycleService (المصدر الوحيد).
 * مرشَّحوه فقط: مواسم منشورة لم تبدأ بعد (went_live_at null) - استعلام ضيق وصغير.
 */
class SyncSeasonLiveState extends Command
{
    protected $signature = 'seasons:sync-live-state';

    protected $description = 'التقاط بدء المواسم الناتج عن مرور الوقت (SeasonStarted مرة واحدة لكل موسم). Idempotent؛ يُجدوَل كل 5 دقائق.';

    public function handle(SeasonLifecycleService $lifecycle): int
    {
        $checked = 0;
        $started = 0;

        Season::query()->where('is_published', true)->whereNull('went_live_at')->chunkById(100, function ($seasons) use ($lifecycle, &$checked, &$started) {
            foreach ($seasons as $season) {
                $checked++;

                try {
                    $started += $lifecycle->syncLiveState($season) ? 1 : 0;
                } catch (\Throwable $e) {
                    report($e); // فشل موسم واحد لا يوقف البقية.
                }
            }
        });

        $this->info("فُحصت {$checked} مواسم منشورة لم تبدأ، وبدأ منها فعلًا: {$started}.");

        return self::SUCCESS;
    }
}
