<?php

namespace App\Listeners;

use App\Events\TeamJoinRequestAccepted;
use App\Models\TeamJoinRequest;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationType;

/** "قُبل طلب انضمامك إلى فريق Y" للطالب مرة واحدة (team-join-accepted:{id}). الرفض بلا إشعار. */
class SendTeamJoinRequestAcceptedNotification
{
    public function handle(TeamJoinRequestAccepted $event): void
    {
        try {
            $request = TeamJoinRequest::query()->with(['team:id,name,slug', 'user'])->find($event->requestId);

            if ($request === null || $request->status !== TeamJoinRequest::STATUS_ACCEPTED) {
                return;
            }

            app(NotificationDispatcher::class)->dispatch(
                $request->user, NotificationType::TeamJoinRequestAccepted, ['team' => $request->team->name],
                "team-join-accepted:{$request->id}", ['team_join_request_id' => $request->id], ['team' => $request->team->slug], 'teams.show',
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
