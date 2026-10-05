<?php

namespace App\Listeners;

use App\Events\StageUnlockedForUser;
use App\Models\Campaign;
use App\Models\CampaignStage;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationType;

/**
 * إشعار واحد لمستخدم واحد: صف خفيف متزامن (كإشعار الإنجاز) بلا صف انتظار. المفتاح stage-unlocked:{user}:{stage}.
 * الوجهة: موسم منشور مرتبط بالحملة (واجهتها الفعلية للاعب) وإلا صفحة الحملة - مسار من سجل النوع حصرًا.
 * أي فشل يُلتقَط ولا يصل لمسار التقدّم. معلوماتي فقط: لا XP ولا عملة ولا تقدّم ولا مكافأة.
 */
class SendStageUnlockedNotification
{
    public function handle(StageUnlockedForUser $event): void
    {
        try {
            $user = User::find($event->userId);
            $stage = CampaignStage::find($event->stageId);
            $campaign = Campaign::with('season')->find($event->campaignId);

            if ($user === null || $stage === null || $campaign === null || $stage->campaign_id !== $campaign->id) {
                return;
            }

            $season = $campaign->season;
            $viaSeason = $season !== null && $season->is_published; // موسم غير منشور: صفحته 404 للاعب

            app(NotificationDispatcher::class)->dispatch(
                $user,
                NotificationType::StageUnlocked,
                ['stage' => $stage->title, 'campaign' => $campaign->title],
                "stage-unlocked:{$user->id}:{$stage->id}",
                ['campaign_id' => $campaign->id, 'stage_id' => $stage->id],
                $viaSeason ? ['season' => $season->slug] : ['campaign' => $campaign->slug],
                $viaSeason ? 'seasons.show' : 'campaigns.show',
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
