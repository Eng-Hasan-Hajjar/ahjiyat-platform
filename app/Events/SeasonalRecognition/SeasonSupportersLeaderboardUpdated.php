<?php

namespace App\Events\SeasonalRecognition;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * E44 - إشارة «تغيّر ترتيب داعمي الموسم» بعد commit منح أو عكس نقاط. **لا تحمل أي بيانات**: لا لاعب ولا نقاط ولا مبالغ ولا بيانات دفع ولا مزوّد؛ العميل يعيد جلب اللوحة العامة (التي تحجب المجهول).
 * قناة عامة عمدًا لأن اللوحة نفسها علنية؛ يُرسَل عبر SeasonalRecognitionBroadcaster الذي يعزل الفشل ويحدّ المعدّل.
 */
class SeasonSupportersLeaderboardUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public int $seasonId) {}

    public function broadcastOn(): array
    {
        return [new Channel('seasonal-supporters.'.$this->seasonId)];
    }

    public function broadcastAs(): string
    {
        return 'leaderboard.updated';
    }

    public function broadcastWith(): array
    {
        return ['season' => $this->seasonId];
    }
}
