<?php

namespace App\Jobs;

use App\Models\CreatorFollow;
use App\Models\CreatorProfile;
use App\Models\CreatorSeries;
use App\Models\CreatorSeriesRevision;
use App\Models\User;
use App\Services\CreatorSeries\CreatorSeriesVisibilityService;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationType;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * E30 - دفعة واحدة من إشعارات «سلسلة جديدة» لمتابعي مبدع، ثم الدفعة التالية بمؤشر id لصف المتابعة (إعادة استعمال نمط E29 بلا اختراع جديد).
 * حجم الدفعة = player_notifications.chunk_size. إعادة تشغيل أي دفعة آمنة: المفتاح creator-series:{revision}:{user} + القيد الفريد بالإشعارات.
 * يُعاد فحص الظهور لحظة التنفيذ عبر CreatorSeriesVisibilityService: سلسلة أُخفيت أو عُلِّق مبدعها أو حلّت محلّها نسخة أحدث قبل أن يعمل العامل لا يُرسَل عنها شيء.
 * الجمهور: متابعون موثَّقون وغير مجمَّدين (التفضيل/المفتاح الشامل يُفحصان داخل NotificationDispatcher). لا XP ولا عملة ولا تقدّم ولا مكافأة.
 */
class DispatchCreatorSeriesChunk implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;
    use \App\Jobs\Concerns\BoundedRetries;

    public int $tries = 3;

    public function __construct(public int $revisionId, public bool $isUpdate = false, public int $afterFollowId = 0) {}

    public function handle(NotificationDispatcher $dispatcher, CreatorSeriesVisibilityService $visibility): void
    {
        $revision = CreatorSeriesRevision::query()->find($this->revisionId, ['id', 'creator_series_id', 'status']);

        if ($revision === null || $revision->status !== CreatorSeriesRevision::STATUS_APPROVED) {
            return;
        }

        $series = $visibility->visibleQuery()
            ->where('creator_series.id', $revision->creator_series_id)->where('creator_series.published_revision_id', $revision->id)
            ->first(['creator_series.id', 'creator_series.public_id', 'creator_series.creator_profile_id', 'creator_series.published_title']);

        if (! $series instanceof CreatorSeries) {
            return;
        }

        $creatorUserId = (int) CreatorProfile::query()->whereKey($series->creator_profile_id)->value('user_id');
        $creatorName = (string) User::query()->whereKey($creatorUserId)->value('name');
        $size = max(1, (int) config('player_notifications.chunk_size', 200));

        $follows = CreatorFollow::query()
            ->where('creator_profile_id', $series->creator_profile_id)->where('id', '>', $this->afterFollowId)
            ->orderBy('id')->limit($size)->get(['id', 'user_id']);

        if ($follows->isEmpty()) {
            return;
        }

        $users = User::query()->whereIn('id', $follows->pluck('user_id'))->where('is_frozen', false)->whereNotNull('email_verified_at')->get()->keyBy('id');

        foreach ($follows as $follow) {
            $user = $users->get($follow->user_id);

            if ($user === null || (int) $user->id === $creatorUserId) {
                continue;
            }

            $dispatcher->dispatch(
                $user,
                NotificationType::CreatorNewSeries,
                ['creator' => $creatorName, 'title' => (string) $series->published_title, 'updated' => $this->isUpdate],
                "creator-series:{$revision->id}:{$user->id}",
                ['creator_series_id' => $series->id],
                ['series' => $series->public_id],
            );
        }

        if ($follows->count() === $size) {
            self::dispatch($this->revisionId, $this->isUpdate, (int) $follows->last()->id);
        }
    }
}
