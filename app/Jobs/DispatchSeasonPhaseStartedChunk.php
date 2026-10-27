<?php

namespace App\Jobs;

use App\Models\SeasonPhase;
use App\Models\User;
use App\Services\CampaignProgressPairs;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationType;
use App\Services\SeasonLifecycleService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * E42 - دفعة من إشعارات «بدأت مرحلة جديدة» ثم تُجدوِل التالية بمؤشر id (لا Job ضخمة ولا تحميل لكل المستخدمين). إعادة تشغيل أي دفعة آمنة:
 * المفتاح الدلالي season-phase:{phase}:{user} + القيد الفريد. الجمهور: **مشاركو الحملة الأساسية** (تقدّم فعلي بها) الموثَّقون وغير المجمَّدين فقط - ليس بثًا لكل المستخدمين.
 * التفضيل والمفتاح الشامل يُفحصان لحظة الإرسال داخل NotificationDispatcher. لا XP ولا عملة ولا تقدّم ولا مكافأة.
 */
class DispatchSeasonPhaseStartedChunk implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;
    use \App\Jobs\Concerns\BoundedRetries;

    public int $tries = 3;

    public function __construct(public int $phaseId, public int $afterUserId = 0) {}

    public function handle(NotificationDispatcher $dispatcher, SeasonLifecycleService $lifecycle): void
    {
        $phase = SeasonPhase::query()->with('season.campaign')->find($this->phaseId);

        // حُذفت، أو لم تُحجَز كمبدوءة (استدعاء يدوي خاطئ)، أو الموسم لم يعد مباشرًا: لا إشعار.
        if ($phase === null || $phase->went_live_at === null || $phase->season === null || $lifecycle->state($phase->season) !== SeasonLifecycleService::STATE_LIVE) {
            return;
        }

        $season = $phase->season;
        $size = max(1, (int) config('player_notifications.chunk_size', 200));

        $users = CampaignProgressPairs::usersForCampaignQuery((int) $season->campaign_id)
            ->where('id', '>', $this->afterUserId)
            ->where('is_frozen', false)
            ->whereNotNull('email_verified_at')
            ->orderBy('id')
            ->limit($size)
            ->get();

        foreach ($users as $user) {
            /** @var User $user */
            $dispatcher->dispatch(
                $user,
                NotificationType::SeasonPhaseStarted,
                ['name' => $season->campaign?->title ?? $season->code, 'phase' => $phase->name],
                "season-phase:{$phase->id}:{$user->id}",
                ['season_id' => $season->id, 'season_phase_id' => $phase->id],
                ['season' => $season->slug],
            );
        }

        if ($users->count() === $size) {
            self::dispatch($this->phaseId, (int) $users->last()->id);
        }
    }
}
