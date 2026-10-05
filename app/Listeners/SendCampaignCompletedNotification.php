<?php

namespace App\Listeners;

use App\Events\CampaignCompletedForUser;
use App\Models\Campaign;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationType;

/**
 * إشعار واحد لمستخدم واحد لكل إكمال (صف خفيف متزامن بلا صف انتظار). القرار هنا لا في CampaignCompletionService:
 *  - حملة مرتبطة بموسم **منشور** (واجهتها الفعلية للاعب؛ الموسم طبقة عرض لحملة واحدة): يُرسَل season_completed فقط
 *    (المفتاح season-completed:{user}:{season}، الوجهة seasons.show، يحترم تفضيل "المواسم"). لا يُرسَل campaign_completed
 *    ولا يُستخدَم كبديل إن عطّل المستخدم إشعارات المواسم - قرار مقصود.
 *  - حملة مستقلة أو موسمها غير منشور (صفحته 404 للاعب): campaign_completed كما هو (campaign-completed:{user}:{campaign}،
 *    الوجهة campaigns.show، يحترم تفضيل "الحملات").
 * أي فشل يُلتقَط ولا يصل لمسار التقدّم. معلوماتي فقط: لا XP ولا عملة ولا تقدّم ولا مكافأة.
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

            $season = $campaign->season;

            if ($season !== null && $season->is_published) {
                app(NotificationDispatcher::class)->dispatch(
                    $user,
                    NotificationType::SeasonCompleted,
                    ['season' => $campaign->title], // الموسم بلا عنوان خاص: عنوانه عنوان حملته (كما تعرضه صفحة الموسم)
                    "season-completed:{$user->id}:{$season->id}",
                    ['season_id' => $season->id, 'campaign_id' => $campaign->id],
                    ['season' => $season->slug],
                    'seasons.show',
                );

                return;
            }

            app(NotificationDispatcher::class)->dispatch(
                $user,
                NotificationType::CampaignCompleted,
                ['campaign' => $campaign->title],
                "campaign-completed:{$user->id}:{$campaign->id}",
                ['campaign_id' => $campaign->id],
                ['campaign' => $campaign->slug],
                'campaigns.show',
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
