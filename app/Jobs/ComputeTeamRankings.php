<?php

namespace App\Jobs;

use App\Models\CompetitiveEvent;
use App\Services\Teams\TeamCompetitiveRankingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** يخزّن ترتيب الفرق النهائي لحدث معتمَد (Idempotent بالقاعدة). تكراره أو تشغيله بالتوازي لا يغيّر شيئًا. تعذّره يعالجه الأمر الدوري teams:process-lifecycle. */
class ComputeTeamRankings implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public int $eventId) {}

    public function handle(TeamCompetitiveRankingService $ranking): void
    {
        if (($event = CompetitiveEvent::query()->find($this->eventId)) !== null) {
            $ranking->finalize($event);
        }
    }

    public function failed(\Throwable $e): void
    {
        report($e);
    }
}
