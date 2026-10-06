<?php

namespace App\Listeners;

use App\Events\CompetitiveEventFinalized;
use App\Jobs\DispatchCompetitiveResultChunk;

/** يطلق إشعارات "نتائج المنافسة جاهزة" على دفعات محدودة (وظيفة بمؤشر تقدّم) لا حلقة واحدة ضخمة. فشله لا يمسّ النتائج. */
class QueueCompetitiveResultNotifications
{
    public function handle(CompetitiveEventFinalized $event): void
    {
        try {
            DispatchCompetitiveResultChunk::dispatch($event->eventId);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
