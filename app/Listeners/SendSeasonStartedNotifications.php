<?php

namespace App\Listeners;

use App\Events\SeasonStarted;
use App\Jobs\DispatchSeasonStartedChunk;

/**
 * لا حلقة متزامنة داخل طلب الإدارة: يبدأ سلسلة Jobs بدفعات (DispatchSeasonStartedChunk). أي فشل (صف الانتظار
 * مثلًا) يُلتقَط ولا يصل إلى حفظ الموسم/الحملة.
 */
class SendSeasonStartedNotifications
{
    public function handle(SeasonStarted $event): void
    {
        try {
            DispatchSeasonStartedChunk::dispatch($event->season->getKey(), 0);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
