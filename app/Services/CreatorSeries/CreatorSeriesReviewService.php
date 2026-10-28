<?php

namespace App\Services\CreatorSeries;

use App\Models\CreatorProfile;
use App\Models\CreatorSeries;
use App\Models\CreatorSeriesRevision;
use App\Models\User;
use App\Services\CreatorPremium\CreatorPremiumPublishGuard;
use App\Services\OperationalAuditService;
use Illuminate\Support\Facades\DB;

/**
 * E30 - مراجعة السلاسل المرسلة. المراجع بصلاحية creators.review فقط (RBAC المبدعين نفسها)، ولا يعدّل محتوى المبدع بصمت: اعتماد/طلب تعديل/رفض، وإخفاء/استعادة إشرافيان.
 *
 * الاعتماد ذرّي: قفل النسخة والسلسلة ← إعادة تحقق كاملة من العناصر (أعمال المبدع نفسه، ظاهرة الآن، العدد ضمن الحدود) ← نشر لقطة السلسلة ← تدقيق.
 * أي فشل يُرجع كل شيء. الإشعار والإعلان للمتابعين بعد الـcommit، وفشلهما لا يتراجع عنه الاعتماد.
 * النسخة المنشورة القديمة لا تُحذف ولا تُعدَّل: عناصرها وتاريخها يبقيان؛ لقطة السلسلة وحدها تتحول للنسخة الجديدة.
 * لا اقتصاد: لا يوجد هنا أي مرجع للمحافظ/XP/الجوائز/المهام.
 */
class CreatorSeriesReviewService
{
    public function __construct(
        protected CreatorSeriesService $series,
        protected OperationalAuditService $audit,
        protected CreatorSeriesNotifier $notifier,
        protected CreatorSeriesAnnouncer $announcer,
    ) {}

    /**
     * E43: `$overrideSoldRemoval` تأكيد صريح من المراجع لاعتماد نسخة **تُسقط** أحجيات من سلسلة لها مشترون (يُدقَّق مع ملاحظة إلزامية)؛ بدونه يُرفض الاعتماد ولا يتغير شيء.
     */
    public function approve(CreatorSeriesRevision $revision, User $actor, ?string $note = null, bool $overrideSoldRemoval = false): CreatorSeriesRevision
    {
        $this->assertReviewer($actor);

        $isUpdate = false;
        $creatorProfileId = 0;

        $approved = DB::transaction(function () use ($revision, $actor, $note, $overrideSoldRemoval, &$isUpdate, &$creatorProfileId) {
            $locked = CreatorSeriesRevision::query()->lockForUpdate()->findOrFail($revision->id);
            $this->assertPending($locked);

            $series = CreatorSeries::query()->lockForUpdate()->findOrFail($locked->creator_series_id);
            $profile = CreatorProfile::query()->with('user')->findOrFail($series->creator_profile_id);

            if (! $profile->isApproved() || (bool) $profile->user?->is_frozen) {
                throw new CreatorSeriesException('لا يمكن اعتماد سلسلة مبدع معلَّق أو حسابه مقيَّد.');
            }

            // العناصر لحظة الاعتماد: أعمال المبدع نفسه، ظاهرة الآن، والعدد ضمن الحدود.
            $this->series->assertItemsReady($profile, $locked);

            $removedSold = app(CreatorPremiumPublishGuard::class)->soldItemsRemoved($series, $locked);

            if ($removedSold !== []) {
                if (! $overrideSoldRemoval) {
                    throw new CreatorSeriesException('تُسقط هذه النسخة '.count($removedSold).' أحجية من سلسلة لها مشترون. الإضافة وإعادة الترتيب مسموحتان، أما الحذف فيلزمه تأكيد صريح مع ملاحظة.');
                }

                if (! filled($note)) {
                    throw new CreatorSeriesException('تأكيد حذف أحجيات من سلسلة مباعة يلزمه ملاحظة موثَّقة.');
                }

                $this->audit->log('creator_premium_sold_items_removed', $locked, ['series' => $series->public_id, 'revision' => $locked->revision_number, 'removed_works' => count($removedSold)], $actor);
            }

            $isUpdate = $series->first_published_at !== null;
            $creatorProfileId = (int) $series->creator_profile_id;

            $locked->forceFill([
                'status' => CreatorSeriesRevision::STATUS_APPROVED,
                'reviewed_at' => now(),
                'reviewed_by' => $actor->id,
                'review_note' => filled($note) ? trim((string) $note) : null,
            ])->save();

            $series->forceFill([
                'published_revision_id' => $locked->id,
                'published_title' => $locked->title,
                'published_cover_path' => $locked->cover_path,
                'published_items_count' => (int) DB::table('creator_series_items')->where('creator_series_revision_id', $locked->id)->count(),
                'published_at' => now(),
                'first_published_at' => $series->first_published_at ?? now(),
            ])->save();

            $this->audit->log('creator_series_approved', $locked, [
                'series' => $series->public_id,
                'revision' => $locked->revision_number,
                'title' => $locked->title,
                'items' => (int) $series->published_items_count,
            ], $actor);

            return $locked;
        });

        $this->notifier->approved($approved);
        $this->announcer->announce($approved, $creatorProfileId, $isUpdate);

        return $approved;
    }

    public function requestChanges(CreatorSeriesRevision $revision, User $actor, string $note): CreatorSeriesRevision
    {
        $revision = $this->close($revision, $actor, $note, CreatorSeriesRevision::STATUS_CHANGES_REQUESTED, 'creator_series_changes_requested');
        $this->notifier->changesRequested($revision);

        return $revision;
    }

    public function reject(CreatorSeriesRevision $revision, User $actor, string $note): CreatorSeriesRevision
    {
        $revision = $this->close($revision, $actor, $note, CreatorSeriesRevision::STATUS_REJECTED, 'creator_series_rejected');
        $this->notifier->rejected($revision);

        return $revision;
    }

    /** إخفاء إشرافي فوري لسلسلة منشورة (يُبقي التاريخ كله؛ تستعيده استعادة إشرافية فقط). */
    public function hide(CreatorSeries $series, User $actor, string $note): CreatorSeries
    {
        $this->assertReviewer($actor);
        $note = $this->requireNote($note);

        $series = DB::transaction(function () use ($series, $actor, $note) {
            $series = CreatorSeries::query()->lockForUpdate()->findOrFail($series->id);

            if (! $series->isPublished()) {
                throw new CreatorSeriesException('لا توجد سلسلة منشورة لإخفائها.');
            }

            if ($series->isHidden()) {
                throw new CreatorSeriesException('هذه السلسلة مخفية بالفعل.');
            }

            $series->forceFill([
                'hidden_at' => now(),
                'hidden_by' => $actor->id,
                'hidden_reason' => CreatorSeries::HIDDEN_MODERATION,
                'hidden_note' => $note,
            ])->save();

            $this->audit->log('creator_series_hidden', $series, ['title' => $series->published_title, 'note' => $note], $actor);

            return $series;
        });

        $this->notifier->hidden($series);

        return $series;
    }

    public function restore(CreatorSeries $series, User $actor): CreatorSeries
    {
        $this->assertReviewer($actor);

        return DB::transaction(function () use ($series, $actor) {
            $series = CreatorSeries::query()->lockForUpdate()->findOrFail($series->id);

            if (! $series->isHidden()) {
                throw new CreatorSeriesException('هذه السلسلة غير مخفية.');
            }

            $series->forceFill(['hidden_at' => null, 'hidden_by' => null, 'hidden_reason' => null, 'hidden_note' => null])->save();
            $this->audit->log('creator_series_restored', $series, ['title' => $series->published_title], $actor);

            return $series->refresh();
        });
    }

    protected function close(CreatorSeriesRevision $revision, User $actor, string $note, string $status, string $auditAction): CreatorSeriesRevision
    {
        $this->assertReviewer($actor);
        $note = $this->requireNote($note);

        return DB::transaction(function () use ($revision, $actor, $note, $status, $auditAction) {
            $locked = CreatorSeriesRevision::query()->lockForUpdate()->findOrFail($revision->id);
            $this->assertPending($locked);

            $locked->forceFill([
                'status' => $status,
                'reviewed_at' => now(),
                'reviewed_by' => $actor->id,
                'review_note' => $note,
            ])->save();

            $this->audit->log($auditAction, $locked, [
                'series' => $locked->series()->value('public_id'), 'revision' => $locked->revision_number, 'title' => $locked->title, 'note' => $note,
            ], $actor);

            return $locked;
        });
    }

    protected function assertPending(CreatorSeriesRevision $revision): void
    {
        if (! $revision->isPendingReview()) {
            throw new CreatorSeriesException('هذه النسخة ليست قيد المراجعة (ربما عولجت للتو).');
        }
    }

    protected function assertReviewer(User $actor): void
    {
        abort_unless($actor->can('creators.review'), 403);
    }

    protected function requireNote(string $note): string
    {
        $note = trim($note);
        $min = (int) config('creator_series.limits.note_min');
        $max = (int) config('creator_series.limits.note_max');

        if (mb_strlen($note) < $min || mb_strlen($note) > $max) {
            throw new CreatorSeriesException("اكتب سببًا واضحًا ({$min}–{$max} حرفًا).");
        }

        return $note;
    }
}
