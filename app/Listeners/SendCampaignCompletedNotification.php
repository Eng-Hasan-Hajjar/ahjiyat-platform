<?php

namespace App\Listeners;

use App\Events\CampaignCompletedForUser;
use App\Models\Campaign;
use App\Models\User;
use App\Services\Notifications\CampaignNotificationLink;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationType;

/**
 * إشعار واحد لمستخدم واحد: صف خفيف متزامن بلا صف انتظار. المفتاح campaign-completed:{user}:{campaign}. أي فشل يُلتقَط ولا
 * يصل لمسار التقدّم. معلوماتي فقط: لا XP ولا عملة ولا تقدّم ولا مكافأة، ولا يمسّ أي مكافأة موجودة أصلًا.
 */
class SendCampaignCompletedNotification
{
    public function handle(CampaignCompletedForUser $event): void
    {
        try {
            $user = User::find($event->userId);
            $campaign = Campaign::with('season')->find($event->campaignId);

            if ($user === null || $campaign === null) {
                return;
            }

            [$route, $params] = CampaignNotificationLink::for($campaign);

            app(NotificationDispatcher::class)->dispatch(
                $user,
                NotificationType::CampaignCompleted,
                ['campaign' => $campaign->title],
                "campaign-completed:{$user->id}:{$campaign->id}",
                ['campaign_id' => $campaign->id],
                $params,
                $route,
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
