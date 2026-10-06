<?php

namespace App\Jobs;

use App\Models\CompetitiveEvent;
use App\Models\CompetitiveEventResult;
use App\Models\User;
use App\Services\Progression\AchievementService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** بعد اعتماد حدث: يقيّم إنجازات المنافسة لأصحاب نتائجه على دفعات (إنجاز واحد فاشل لا يوقف غيره). الإنجاز نفسه Idempotent بنظامه الحالي. */
class EvaluateCompetitiveAchievementsChunk implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public int $eventId, public int $afterResultId = 0) {}

    public function handle(AchievementService $achievements): void
    {
        $event = CompetitiveEvent::query()->find($this->eventId);

        if ($event === null || $event->status !== CompetitiveEvent::STATUS_COMPLETED) {
            return;
        }

        $size = max(1, (int) config('competitive.rewards.chunk_size', 100));
        $results = CompetitiveEventResult::query()->where('competitive_event_id', $event->id)->where('id', '>', $this->afterResultId)->orderBy('id')->limit($size)->get(['id', 'user_id']);

        foreach (User::query()->whereIn('id', $results->pluck('user_id'))->get() as $user) {
            try {
                $achievements->evaluateForEvent('competitive_event_finalized', $user);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        if ($results->count() === $size) {
            self::dispatch($this->eventId, (int) $results->last()->id);
        }
    }

    public function failed(\Throwable $e): void
    {
        report($e);
    }
}
