<?php

namespace App\Listeners;

use App\Events\TeamMemberRemoved;
use App\Models\Team;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationType;

/** إشعار معلوماتي محايد للمُزال (لا لوم ولا اسم من أزاله): team-member-removed:{team}:{user}:{membership}. الرابط لدليل الفرق. المغادرة الذاتية لا إشعار لها. */
class SendTeamMemberRemovedNotification
{
    public function handle(TeamMemberRemoved $event): void
    {
        try {
            $team = Team::query()->find($event->teamId);
            $user = User::query()->find($event->userId);

            if ($team === null || $user === null) {
                return;
            }

            app(NotificationDispatcher::class)->dispatch(
                $user, NotificationType::TeamMemberRemoved, ['team' => $team->name],
                "team-member-removed:{$team->id}:{$user->id}:{$event->membershipId}", ['team_id' => $team->id], [], 'teams.index',
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
