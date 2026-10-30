<?php

namespace App\Events\SeasonalRecognition;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * E44 - إشارة «تغيّر ترتيب كتّاب الموسم» بعد commit تغيير نجوم. **حمولة تجميعية آمنة فقط**: معرّف الموسم + معرّف الكاتب العام + مجموعه الحالي.
 * لا هوية ناخب ولا توزيعه ولا حسابات ولا مبالغ. العميل يعيد جلب اللوحة بتأخير قصير (debounce)؛ والاستطلاع احتياطي دائمًا.
 *
 * قناة عامة عمدًا: المعلومة علنية أصلًا (اللوحة العامة)، وبثّها على قناة خاصة يستنزف /broadcasting/auth لكل زائر بلا فائدة.
 * يُرسَل عبر SeasonalRecognitionBroadcaster الذي يعزل أي فشل ويحدّ المعدّل فلا يمسّ فشل البثّ الصوتَ المحفوظ.
 */
class SeasonWriterLeaderboardUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /** @param  array<string, int|string|null>  $payload */
    public function __construct(public int $seasonId, public array $payload) {}

    public function broadcastOn(): array
    {
        return [new Channel('seasonal-writers.'.$this->seasonId)];
    }

    public function broadcastAs(): string
    {
        return 'leaderboard.updated';
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
