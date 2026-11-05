<?php

namespace App\Services\Cinematic;

use App\Models\Cinematic\CinematicRevision;
use App\Models\Cinematic\CinematicStory;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * CIN-1 - دورة حياة النسخة: مسودة ← قيد المراجعة ← منشورة ← مستبدلة/مسحوبة. نسخة منشورة واحدة لكل قصة (تضمنها unique(story_id, live_slot)).
 * النشر مرفوض إن وُجد أي blocker في فحص الجاهزية (وسائط ناقصة/تفاعل غير جاهز/…): لا نشر عام ناقص أبدًا.
 * السحب (unpublish) لا يحذف شيئًا: التقدّم محفوظ ويعود النشر ممكنًا بعد إعادة الفحص.
 */
class CinematicPublishingService
{
    public function __construct(protected CinematicReadinessChecker $readiness) {}

    public function submitForReview(CinematicRevision $revision): CinematicRevision
    {
        if ($revision->status !== CinematicRevision::STATUS_DRAFT) {
            throw CinematicException::make('revision_state', 'يمكن إرسال المسودات فقط للمراجعة.', 409);
        }

        $revision->forceFill([
            'status' => CinematicRevision::STATUS_IN_REVIEW,
            'readiness' => $this->readiness->check($revision),
            'submitted_at' => now(),
        ])->save();

        return $revision;
    }

    public function requestChanges(CinematicRevision $revision): CinematicRevision
    {
        if ($revision->status !== CinematicRevision::STATUS_IN_REVIEW) {
            throw CinematicException::make('revision_state', 'النسخة ليست قيد المراجعة.', 409);
        }

        $revision->forceFill(['status' => CinematicRevision::STATUS_DRAFT, 'submitted_at' => null])->save();

        return $revision;
    }

    public function publish(CinematicRevision $revision, ?User $by = null): CinematicRevision
    {
        return DB::transaction(function () use ($revision, $by) {
            $story = CinematicStory::query()->whereKey($revision->story_id)->lockForUpdate()->firstOrFail();
            $revision = CinematicRevision::query()->whereKey($revision->id)->lockForUpdate()->firstOrFail();

            if (! in_array($revision->status, [CinematicRevision::STATUS_IN_REVIEW, CinematicRevision::STATUS_UNPUBLISHED], true)) {
                throw CinematicException::make('revision_state', 'النشر يتطلب نسخة قيد المراجعة (أو مسحوبة سبق نشرها).', 409);
            }

            if ($story->archived_at !== null) {
                throw CinematicException::make('story_archived', 'القصة مؤرشفة.', 409);
            }

            $report = $this->readiness->check($revision);

            if (! $report['ok']) {
                $revision->forceFill(['readiness' => $report])->save();

                throw CinematicException::make('not_ready', 'لا يمكن النشر: توجد عناصر ناقصة ('.count($report['blockers']).'). راجع تبويب الجاهزية.', 422);
            }

            CinematicRevision::query()
                ->where('story_id', $story->id)->where('status', CinematicRevision::STATUS_PUBLISHED)->where('id', '!=', $revision->id)
                ->update(['status' => CinematicRevision::STATUS_SUPERSEDED, 'live_slot' => null]);

            $revision->forceFill([
                'status' => CinematicRevision::STATUS_PUBLISHED,
                'live_slot' => 1,
                'readiness' => $report,
                'published_at' => now(),
                'unpublished_at' => null,
                'published_by' => $by?->id,
            ])->save();

            return $revision;
        });
    }

    public function unpublish(CinematicRevision $revision): CinematicRevision
    {
        if ($revision->status !== CinematicRevision::STATUS_PUBLISHED) {
            throw CinematicException::make('revision_state', 'النسخة ليست منشورة.', 409);
        }

        $revision->forceFill(['status' => CinematicRevision::STATUS_UNPUBLISHED, 'live_slot' => null, 'unpublished_at' => now()])->save();

        return $revision;
    }
}
