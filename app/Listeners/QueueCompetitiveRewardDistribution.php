<?php

namespace App\Listeners;

use App\Events\CompetitiveEventFinalized;
use App\Jobs\DistributeCompetitiveRewardsChunk;
use App\Models\CompetitiveRewardRule;

/**
 * بعد اعتماد النتائج (بعد commit، من المنفِّذ) يطلق التوزيع كوظيفة مجزَّأة، فقط لحدث له قواعد فعّالة. اعتماد النتائج نفسه لا يفشل بسبب ما بعده:
 * أي خطأ هنا يُلتقَط. حدث اعتُمد قبل E18 لا قواعد له (القواعد تُقفل قبل البدء) فلا جوائز رجعية.
 */
class QueueCompetitiveRewardDistribution
{
    public function handle(CompetitiveEventFinalized $event): void
    {
        try {
            if (CompetitiveRewardRule::query()->where('competitive_event_id', $event->eventId)->where('is_active', true)->exists()) {
                DistributeCompetitiveRewardsChunk::dispatch($event->eventId);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
