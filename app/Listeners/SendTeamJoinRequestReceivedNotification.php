<?php

namespace App\Listeners;

use App\Events\TeamJoinRequestCreated;
use App\Models\TeamJoinRequest;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationType;
use App\Services\Social\BlockService;

/** "طلب X الانضمام" لمالك الفريق ومشرفيه، لكل منهم مرة واحدة (team-join-request:{id})، دون من بينه وبين الطالب حظر. فشله لا يمسّ الطلب. */
class SendTeamJoinRequestReceivedNotification
{
    public function handle(TeamJoinRequestCreated $event): void
    {
        try {
            $request = TeamJoinRequest::query()->with(['team:id,name,slug', 'user:id,name'])->find($event->requestId);

            if ($request === null || $request->status !== TeamJoinRequest::STATUS_PENDING) {
                return;
            }

            $managerIds = TeamMembership::query()->where('team_id', $request->team_id)->whereIn('role', [TeamMembership::ROLE_OWNER, TeamMembership::ROLE_ADMIN])->pluck('user_id');
            $blocks = app(BlockService::class);

            foreach (User::query()->whereIn('id', $managerIds)->get() as $manager) {
                if ($blocks->blockedEitherWay($manager, $request->user)) {
                    continue;
                }

                app(NotificationDispatcher::class)->dispatch(
                    $manager, NotificationType::TeamJoinRequestReceived, ['name' => $request->user->name, 'team' => $request->team->name],
                    "team-join-request:{$request->id}", ['team_join_request_id' => $request->id], ['team' => $request->team->slug], 'teams.manage',
                );
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
