<?php

namespace App\Listeners;

use App\Events\TeamInvitationAccepted;
use App\Models\TeamInvitation;
use App\Models\TeamMembership;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationType;

/** "انضم X إلى فريق Y" للداعي مرة واحدة (team-invite-accepted:{id}). الرابط لإدارة الفريق إن كان الداعي ما زال مشرفًا، وإلا لصفحة الفريق. */
class SendTeamInvitationAcceptedNotification
{
    public function handle(TeamInvitationAccepted $event): void
    {
        try {
            $invitation = TeamInvitation::query()->with(['team:id,name,slug', 'inviter', 'invitedUser:id,name'])->find($event->invitationId);

            if ($invitation === null || $invitation->status !== TeamInvitation::STATUS_ACCEPTED || $invitation->inviter === null) {
                return;
            }

            $stillManager = TeamMembership::query()->where('team_id', $invitation->team_id)->where('user_id', $invitation->invited_by)->whereIn('role', [TeamMembership::ROLE_OWNER, TeamMembership::ROLE_ADMIN])->exists();

            app(NotificationDispatcher::class)->dispatch(
                $invitation->inviter, NotificationType::TeamInvitationAccepted,
                ['name' => $invitation->invitedUser->name, 'team' => $invitation->team->name],
                "team-invite-accepted:{$invitation->id}", ['team_invitation_id' => $invitation->id], ['team' => $invitation->team->slug],
                $stillManager ? 'teams.manage' : 'teams.show',
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
