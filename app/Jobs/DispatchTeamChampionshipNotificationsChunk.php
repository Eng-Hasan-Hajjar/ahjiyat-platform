<?php

namespace App\Jobs;

use App\Models\TeamChampionship;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationType;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * توزيع إشعارات البطولة على دفعات (E20-E): 'started' لمالكي/مشرفي الفرق المفعَّلة (القادة وحدهم، لا كل الأعضاء: لا إزعاج)، و'result' لمالكي/مشرفي الفرق التي ظهرت بترتيبها النهائي.
 * كل مستخدم يتلقى مرة واحدة بمفتاح دلالي، فإعادة الوظيفة آمنة. فشل إشعار فرد لا يوقف غيره ولا يمسّ البطولة. مؤشر المستخدم يتقدم بدفعات 200.
 */
class DispatchTeamChampionshipNotificationsChunk implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public int $championshipId, public string $kind, public int $afterUserId = 0) {}

    public function handle(NotificationDispatcher $dispatcher): void
    {
        $championship = TeamChampionship::query()->find($this->championshipId);

        if ($championship === null || ($this->kind === 'started' && $championship->status !== TeamChampionship::STATUS_PUBLISHED)
            || ($this->kind === 'result' && $championship->status !== TeamChampionship::STATUS_COMPLETED)) {
            return;
        }

        $size = 200;
        $query = TeamMembership::query()->join('teams as t', 't.id', '=', 'team_memberships.team_id')->whereIn('team_memberships.role', [TeamMembership::ROLE_OWNER, TeamMembership::ROLE_ADMIN])
            ->where('team_memberships.user_id', '>', $this->afterUserId)->orderBy('team_memberships.user_id')->limit($size);

        $this->kind === 'started'
            ? $query->where('t.is_active', true)
            : $query->whereIn('team_memberships.team_id', $championship->results()->select('team_id'));

        $userIds = $query->pluck('team_memberships.user_id');
        $type = $this->kind === 'started' ? NotificationType::TeamChampionshipStarted : NotificationType::TeamChampionshipResultReady;
        $key = $this->kind === 'started' ? 'team-championship-started' : 'team-championship-result';

        foreach (User::query()->whereIn('id', $userIds)->get() as $user) {
            try {
                $dispatcher->dispatch($user, $type, ['title' => $championship->title], "{$key}:{$championship->id}:{$user->id}", ['team_championship_id' => $championship->id], ['championship' => $championship->slug], 'team-championships.show');
            } catch (\Throwable $e) {
                report($e);
            }
        }

        if ($userIds->count() === $size) {
            self::dispatch($this->championshipId, $this->kind, (int) $userIds->last());
        }
    }

    public function failed(\Throwable $e): void
    {
        report($e);
    }
}
