<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\User;
use App\Services\CampaignLifecycleService;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationType;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * دفعة واحدة من إشعارات "حملة متاحة" ثم الدفعة التالية بمؤشر id (لا Job ضخمة ولا تحميل لكل المستخدمين). إعادة تشغيل أي
 * دفعة آمنة: المفتاح campaign-available:{campaign}:{user} + القيد الفريد. الجمهور: موثَّقون وغير مجمَّدين. التفضيل
 * والمفتاح الشامل يُفحصان لحظة الإرسال داخل NotificationDispatcher. لا XP ولا عملة ولا تقدّم ولا سلسلة ولا مكافأة.
 *
 * لا إشعار عن حملة لا يصل إليها المستخدم فعلًا: تُفحَص الإتاحة لحظة التنفيذ (الصف قد يتأخر؛ الرابط campaigns.show
 * يعطي 404 لغير المتاحة)، وتُستبعَد الحملة المرتبطة بموسم (يعلنها season_started).
 */
class DispatchCampaignAvailableChunk implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public int $campaignId, public int $afterUserId = 0) {}

    public function handle(NotificationDispatcher $dispatcher, CampaignLifecycleService $lifecycle): void
    {
        $campaign = Campaign::query()->find($this->campaignId);

        if ($campaign === null || $campaign->became_available_at === null || ! $lifecycle->isAvailable($campaign) || $campaign->season()->exists()) {
            return;
        }

        $size = max(1, (int) config('player_notifications.chunk_size', 200));

        $users = User::query()
            ->where('id', '>', $this->afterUserId)
            ->where('is_frozen', false)
            ->whereNotNull('email_verified_at')
            ->orderBy('id')
            ->limit($size)
            ->get();

        foreach ($users as $user) {
            $dispatcher->dispatch(
                $user,
                NotificationType::CampaignAvailable,
                ['name' => $campaign->title],
                "campaign-available:{$campaign->id}:{$user->id}",
                ['campaign_id' => $campaign->id],
                ['campaign' => $campaign->slug],
            );
        }

        if ($users->count() === $size) {
            self::dispatch($this->campaignId, (int) $users->last()->id);
        }
    }
}
