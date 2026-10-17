<?php

namespace App\Jobs;

use App\Models\CreatorFollow;
use App\Models\CreatorProfile;
use App\Models\CreatorPuzzle;
use App\Models\CreatorPuzzleRevision;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationType;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * E29 - دفعة واحدة من إشعارات «نشر جديد» لمتابعي مبدع، ثم الدفعة التالية بمؤشر id لصف المتابعة (لا Job ضخمة ولا تحميل لكل المتابعين).
 * حجم الدفعة = player_notifications.chunk_size (نفس إعداد بقية الإرسال الجماعي E15/E20/E21): 100 متابع = دفعة، 10 آلاف = 50، مليون = 5000 دفعة متسلسلة.
 *
 *  - إعادة تشغيل أي دفعة آمنة: المفتاح creator-publication:{revision}:{user} + القيد الفريد بالإشعارات (تكرار = Duplicate لا إشعار ثانٍ).
 *  - **يُعاد فحص الظهور لحظة التنفيذ** (W6): نشرٌ أُخفي أو عُلِّق مبدعه أو حلّت محلّه نسخة أحدث قبل أن يعمل العامل لا يُرسَل عنه شيء.
 *  - الجمهور: متابعون موثَّقون وغير مجمَّدين. التفضيل (فئة social) والمفتاح الشامل يُفحصان لحظة الإرسال داخل NotificationDispatcher.
 *  - لا XP ولا عملة ولا تقدّم ولا مكافأة. فشل أي إشعار يُلتقَط داخل NotificationDispatcher ولا يُفشل الدفعة.
 */
class DispatchCreatorPublicationChunk implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;
    use \App\Jobs\Concerns\BoundedRetries;

    public int $tries = 3;

    public function __construct(public int $revisionId, public bool $isUpdate = false, public int $afterFollowId = 0) {}

    public function handle(NotificationDispatcher $dispatcher): void
    {
        $revision = CreatorPuzzleRevision::query()->find($this->revisionId, ['id', 'creator_puzzle_id', 'status']);

        if ($revision === null || $revision->status !== CreatorPuzzleRevision::STATUS_APPROVED) {
            return;
        }

        $work = CreatorPuzzle::query()->visible()
            ->where('id', $revision->creator_puzzle_id)->where('published_revision_id', $revision->id)
            ->first(['id', 'public_id', 'creator_profile_id', 'published_title']);

        if ($work === null) {
            return;
        }

        $creatorUserId = (int) CreatorProfile::query()->whereKey($work->creator_profile_id)->value('user_id');
        $creatorName = (string) User::query()->whereKey($creatorUserId)->value('name');
        $size = max(1, (int) config('player_notifications.chunk_size', 200));

        $follows = CreatorFollow::query()
            ->where('creator_profile_id', $work->creator_profile_id)->where('id', '>', $this->afterFollowId)
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
                NotificationType::CreatorNewPublication,
                ['creator' => $creatorName, 'title' => (string) $work->published_title, 'updated' => $this->isUpdate],
                "creator-publication:{$revision->id}:{$user->id}",
                ['creator_puzzle_id' => $work->id],
                ['work' => $work->public_id],
            );
        }

        if ($follows->count() === $size) {
            self::dispatch($this->revisionId, $this->isUpdate, (int) $follows->last()->id);
        }
    }
}
