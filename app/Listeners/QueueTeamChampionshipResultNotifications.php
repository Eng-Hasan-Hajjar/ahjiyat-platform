<?php

namespace App\Listeners;

use App\Events\TeamChampionshipFinalized;
use App\Jobs\DispatchTeamChampionshipNotificationsChunk;

/** بعد اعتماد البطولة (بعد commit): توزيع "النتائج جاهزة" على مالكي/مشرفي الفرق الظاهرة بالترتيب، على دفعات بالطابور. أي خطأ يُلتقَط ولا يمسّ الاعتماد. */
class QueueTeamChampionshipResultNotifications
{
    public function handle(TeamChampionshipFinalized $event): void
    {
        try {
            DispatchTeamChampionshipNotificationsChunk::dispatch($event->championshipId, 'result');
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
