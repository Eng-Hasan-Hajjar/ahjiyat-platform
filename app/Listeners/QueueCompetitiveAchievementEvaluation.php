<?php

namespace App\Listeners;

use App\Events\CompetitiveEventFinalized;
use App\Jobs\EvaluateCompetitiveAchievementsChunk;

/** بعد اعتماد النتائج: تقييم إنجازات المنافسة على دفعات. أي خطأ يُلتقَط ولا يمسّ الاعتماد. */
class QueueCompetitiveAchievementEvaluation
{
    public function handle(CompetitiveEventFinalized $event): void
    {
        try {
            EvaluateCompetitiveAchievementsChunk::dispatch($event->eventId);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
