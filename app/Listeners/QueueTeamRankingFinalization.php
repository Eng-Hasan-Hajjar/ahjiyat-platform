<?php

namespace App\Listeners;

use App\Events\CompetitiveEventFinalized;
use App\Jobs\ComputeTeamRankings;

/** بعد اعتماد نتائج الأفراد (E17، دون أي تعديل عليه): يُطلق تخزين ترتيب الفرق. أي خطأ يُلتقَط ولا يمسّ الاعتماد؛ والأمر الدوري شبكة أمان. */
class QueueTeamRankingFinalization
{
    public function handle(CompetitiveEventFinalized $event): void
    {
        try {
            ComputeTeamRankings::dispatch($event->eventId);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
