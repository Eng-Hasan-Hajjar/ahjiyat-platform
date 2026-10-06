<?php

namespace App\Jobs;

use App\Models\CompetitiveEvent;
use App\Models\CompetitiveEventResult;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationType;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** إشعار نتيجة جاهزة لكل مشارك لديه نتيجة معتمدة، مرة واحدة (competitive-event-result:{event}:{user})، على دفعات بمؤشر معرّف النتيجة. */
class DispatchCompetitiveResultChunk implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public int $eventId, public int $afterResultId = 0) {}

    public function handle(NotificationDispatcher $dispatcher): void
    {
        $event = CompetitiveEvent::query()->find($this->eventId);

        if ($event === null || $event->status !== CompetitiveEvent::STATUS_COMPLETED) {
            return;
        }

        $size = max(1, (int) config('player_notifications.chunk_size', 200));

        $results = CompetitiveEventResult::query()->where('competitive_event_id', $event->id)->where('id', '>', $this->afterResultId)
            ->with('user')->orderBy('id')->limit($size)->get();

        foreach ($results as $result) {
            $user = $result->user;

            if ($user === null || $user->is_frozen || $user->email_verified_at === null) {
                continue;
            }

            $dispatcher->dispatch(
                $user,
                NotificationType::CompetitiveEventResultReady,
                ['title' => $event->title, 'rank' => $result->final_rank],
                "competitive-event-result:{$event->id}:{$user->id}",
                ['competitive_event_id' => $event->id],
                ['event' => $event->slug],
                'competitions.show',
            );
        }

        if ($results->count() === $size) {
            self::dispatch($this->eventId, (int) $results->last()->id);
        }
    }
}
