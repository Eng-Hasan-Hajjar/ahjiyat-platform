<?php

namespace App\Jobs;

use App\Models\SeasonAnnouncement;
use App\Models\User;
use App\Services\CampaignProgressPairs;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationType;
use App\Services\SeasonLifecycleService;
use App\Services\TrustSafety\PlainText;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * E42 - دفعة من إشعارات «إعلان جديد في الموسم»، بقرار إداري صريح فقط (notify_audience) وبعد حجز ذري (notified_at). الجمهور: participants (مشاركو الحملة الأساسية)
 * أو all (الموثَّقون غير المجمَّدون). مفتاح idempotency دلالي لكل مستخدم/إعلان فلا تكرار. نص الإشعار عنوان الإعلان مقتطفًا (نص عادي يُهرَّب عند العرض). لا مكافأة ولا تقدّم.
 */
class DispatchSeasonAnnouncementChunk implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;
    use \App\Jobs\Concerns\BoundedRetries;

    public int $tries = 3;

    public function __construct(public int $announcementId, public int $afterUserId = 0) {}

    public function handle(NotificationDispatcher $dispatcher, SeasonLifecycleService $lifecycle): void
    {
        $row = SeasonAnnouncement::query()->with('season.campaign')->find($this->announcementId);

        if ($row === null || $row->notified_at === null || $row->notify_audience === null || $row->season === null
            || ! $row->isVisible() || $lifecycle->state($row->season) !== SeasonLifecycleService::STATE_LIVE) {
            return;
        }

        $season = $row->season;
        $size = max(1, (int) config('player_notifications.chunk_size', 200));

        $query = $row->notify_audience === 'all'
            ? User::query()
            : CampaignProgressPairs::usersForCampaignQuery((int) $season->campaign_id);

        $users = $query->where('id', '>', $this->afterUserId)
            ->where('is_frozen', false)
            ->whereNotNull('email_verified_at')
            ->orderBy('id')
            ->limit($size)
            ->get();

        $title = PlainText::excerpt($row->title, 140);

        foreach ($users as $user) {
            /** @var User $user */
            $dispatcher->dispatch(
                $user,
                NotificationType::SeasonAnnouncementPublished,
                ['name' => $season->campaign?->title ?? $season->code, 'title' => $title],
                "season-announcement:{$row->id}:{$user->id}",
                ['season_id' => $season->id, 'season_announcement_id' => $row->id],
                ['season' => $season->slug],
            );
        }

        if ($users->count() === $size) {
            self::dispatch($this->announcementId, (int) $users->last()->id);
        }
    }
}
