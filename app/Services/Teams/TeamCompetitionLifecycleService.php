<?php

namespace App\Services\Teams;

use App\Jobs\DispatchTeamChampionshipNotificationsChunk;
use App\Models\TeamChallenge;
use App\Models\TeamChampionship;

/**
 * دورة حياة منافسة الفرق الساعية (E20): خدمة واحدة يستدعيها teams:process-lifecycle (لا مجدول ثانٍ). Idempotent كله:
 * تجسيد انتهاء التحدّيات المعلّقة، اعتماد المباريات المستحقة (انتهت مهلة لعبها)، إشعار بدء البطولات الحية مرة واحدة (علامة started_notified_at بتحديث شرطي)، واعتماد البطولات المستحقة.
 */
class TeamCompetitionLifecycleService
{
    public function __construct(protected TeamChallengeService $challenges, protected TeamChallengeFinalizer $finalizer, protected TeamChampionshipService $championships) {}

    /** @return array{expired: int, matches_finalized: int, championships_started: int, championships_finalized: int} */
    public function run(): array
    {
        $stats = ['expired' => $this->challenges->expirePending(), 'matches_finalized' => 0, 'championships_started' => 0, 'championships_finalized' => 0];

        TeamChallenge::query()->where('status', TeamChallenge::STATUS_ACCEPTED)->where('play_ends_at', '<=', now())->orderBy('id')->limit(200)->get()
            ->each(function (TeamChallenge $c) use (&$stats) {
                try {
                    $stats['matches_finalized'] += $this->finalizer->finalize($c) ? 1 : 0;
                } catch (\Throwable $e) {
                    report($e);
                }
            });

        TeamChampionship::query()->where('status', TeamChampionship::STATUS_PUBLISHED)->whereNull('started_notified_at')->where('starts_at', '<=', now())->where('ends_at', '>', now())->get()
            ->each(function (TeamChampionship $c) use (&$stats) {
                $claimed = TeamChampionship::query()->whereKey($c->getKey())->whereNull('started_notified_at')->update(['started_notified_at' => now()]);

                if ($claimed === 1) {
                    try {
                        DispatchTeamChampionshipNotificationsChunk::dispatch($c->getKey(), 'started');
                        $stats['championships_started']++;
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }
            });

        TeamChampionship::query()->where('status', TeamChampionship::STATUS_PUBLISHED)->where('ends_at', '<=', now())->orderBy('id')->limit(50)->get()
            ->each(function (TeamChampionship $c) use (&$stats) {
                try {
                    $stats['championships_finalized'] += $this->championships->finalize(null, $c) ? 1 : 0;
                } catch (\Throwable $e) {
                    report($e);
                }
            });

        return $stats;
    }
}
