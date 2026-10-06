<?php

namespace App\Listeners;

use App\Events\TeamInvitationCreated;
use App\Models\TeamInvitation;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationType;

/** "دعاك X للانضمام إلى فريق Y" للمدعو مرة واحدة (team-invite:{id}) وفقط لدعوة ما زالت معلّقة. فشله لا يمسّ الدعوة. يحترم social_enabled والمفتاح الشامل. */
class SendTeamInvitationReceivedNotification
{
    public function handle(TeamInvitationCreated $event): void
    {
        try {
            $invitation = TeamInvitation::query()->with(['team:id,name,slug', 'inviter:id,name', 'invitedUser'])->find($event->invitationId);

            if ($invitation === null || $invitation->status !== TeamInvitation::STATUS_PENDING) {
                return;
            }

            app(NotificationDispatcher::class)->dispatch(
                $invitation->invitedUser, NotificationType::TeamInvitationReceived,
                ['name' => $invitation->inviter?->name ?? 'أحد المشرفين', 'team' => $invitation->team->name],
                "team-invite:{$invitation->id}", ['team_invitation_id' => $invitation->id], [], 'teams.invitations',
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
