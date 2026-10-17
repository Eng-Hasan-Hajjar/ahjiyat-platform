<?php

namespace App\Console\Commands;

use App\Services\Sponsorship\EventRewardPlanService;
use Illuminate\Console\Command;

class LockSponsorshipEventPlans extends Command
{
    protected $signature = 'sponsorship:lock-event-plans';

    protected $description = 'E30: يقفل خطط تعزيز مكافآت المنافسات القريبة من البدء (لقطة التمويل قبل البدء). Idempotent وآمن للتشغيل المتكرر؛ يُجدوَل كل 5 دقائق.';

    public function handle(EventRewardPlanService $plans): int
    {
        try {
            $count = $plans->lockUpcoming();
        } catch (\Throwable $e) {
            report($e);
            $this->error('فشل قفل خطط الرعاية: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line("locked={$count}");

        return self::SUCCESS;
    }
}
