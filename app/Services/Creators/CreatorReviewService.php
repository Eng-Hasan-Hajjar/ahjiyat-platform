<?php

namespace App\Services\Creators;

use App\Models\CreatorProfile;
use App\Models\CreatorPuzzle;
use App\Models\CreatorPuzzleRevision;
use App\Models\User;
use App\Services\Engagement\CreatorPublicationAnnouncer;
use App\Services\OperationalAuditService;
use Illuminate\Support\Facades\DB;

/**
 * E28 - مراجعة الأحجيات المرسلة. المراجع بصلاحية creators.review فقط (RBAC)، ولا يعدّل محتوى المبدع بصمت: الخيارات اعتماد/طلب تعديل/رفض.
 *
 * الاعتماد ذرّي: قفل النسخة والعمل ← إعادة تحقق كاملة من الحمولة ← مادّة الأحجية القانونية بالمحرك + فحص ذاتي بالمدقّق الرسمي ← ربط النسخة بالأحجية ← تحويل لقطة النشر ← تدقيق.
 * أي فشل بأي خطوة يُرجع كل شيء (لا نشر لما لا يُحلّ ولا نشر نصف مكتمل). الإشعار بعد الـcommit ولا يتراجع الاعتماد بفشله.
 * النسخة القديمة المنشورة لا تُحذف ولا تُعدَّل: تبقى أحجيتها القانونية القديمة وكل محاولاتها؛ لقطة العمل فقط تتحول للنسخة الجديدة.
 * ملاحظة: طلب التعديل والرفض يشترطان سببًا مكتوبًا (يظهر للمبدع بالاستوديو وبالإشعار).
 * E29: بعد الـcommit أيضًا يُعلَن النشر لمتابعي المبدع (CreatorPublicationAnnouncer ← دفعات بالطابور)، وفشل الإعلان لا يتراجع عنه الاعتماد.
 */
class CreatorReviewService
{
    public function __construct(
        protected CreatorContent $content,
        protected CreatorPuzzleMaterializer $materializer,
        protected OperationalAuditService $audit,
        protected CreatorNotifier $notifier,
        protected CreatorPublicationAnnouncer $announcer,
    ) {}

    public function approve(CreatorPuzzleRevision $revision, User $actor, ?string $note = null): CreatorPuzzleRevision
    {
        $this->assertReviewer($actor);

        $isUpdate = false;
        $creatorProfileId = 0;

        $approved = DB::transaction(function () use ($revision, $actor, $note, &$isUpdate, &$creatorProfileId) {
            $locked = CreatorPuzzleRevision::query()->lockForUpdate()->findOrFail($revision->id);
            $this->assertPending($locked);

            $work = CreatorPuzzle::query()->lockForUpdate()->findOrFail($locked->creator_puzzle_id);
            $profile = CreatorProfile::query()->with('user')->findOrFail($work->creator_profile_id);

            if (! $profile->isApproved() || (bool) $profile->user?->is_frozen) {
                throw new CreatorException('لا يمكن اعتماد أحجية مبدع معلَّق أو حسابه مقيَّد.');
            }

            $this->content->assertReady($locked);
            $puzzle = $this->materializer->materialize($locked);
            $isUpdate = $work->first_published_at !== null;   // نسخة جديدة لعمل نُشر سابقًا (لا أول نشر)
            $creatorProfileId = (int) $work->creator_profile_id;

            $locked->forceFill([
                'status' => CreatorPuzzleRevision::STATUS_APPROVED,
                'reviewed_at' => now(),
                'reviewed_by' => $actor->id,
                'review_note' => filled($note) ? trim((string) $note) : null,
                'puzzle_id' => $puzzle->id,
            ])->save();

            $work->forceFill([
                'published_revision_id' => $locked->id,
                'published_puzzle_id' => $puzzle->id,
                'published_title' => $locked->title,
                'published_category_id' => $locked->puzzle_category_id,
                'published_difficulty' => $locked->difficulty,
                'published_at' => now(),
                'first_published_at' => $work->first_published_at ?? now(),
            ])->save();

            $this->audit->log('creator_submission_approved', $locked, [
                'work' => $work->public_id,
                'revision' => $locked->revision_number,
                'puzzle_id' => $puzzle->id,
                'title' => $locked->title,
            ], $actor);

            return $locked;
        });

        $this->notifier->submissionApproved($approved->load('work.creator.user'));
        $this->announcer->announce($approved, $creatorProfileId, $isUpdate);

        return $approved;
    }

    public function requestChanges(CreatorPuzzleRevision $revision, User $actor, string $note): CreatorPuzzleRevision
    {
        $revision = $this->close($revision, $actor, $note, CreatorPuzzleRevision::STATUS_CHANGES_REQUESTED, 'creator_submission_changes_requested');
        $this->notifier->changesRequested($revision->load('work.creator.user'));

        return $revision;
    }

    public function reject(CreatorPuzzleRevision $revision, User $actor, string $note): CreatorPuzzleRevision
    {
        $revision = $this->close($revision, $actor, $note, CreatorPuzzleRevision::STATUS_REJECTED, 'creator_submission_rejected');
        $this->notifier->submissionRejected($revision->load('work.creator.user'));

        return $revision;
    }

    protected function close(CreatorPuzzleRevision $revision, User $actor, string $note, string $status, string $auditAction): CreatorPuzzleRevision
    {
        $this->assertReviewer($actor);
        $note = $this->requireNote($note);

        return DB::transaction(function () use ($revision, $actor, $note, $status, $auditAction) {
            $locked = CreatorPuzzleRevision::query()->lockForUpdate()->findOrFail($revision->id);
            $this->assertPending($locked);

            $locked->forceFill([
                'status' => $status,
                'reviewed_at' => now(),
                'reviewed_by' => $actor->id,
                'review_note' => $note,
            ])->save();

            $this->audit->log($auditAction, $locked, ['work' => $locked->work()->value('public_id'), 'revision' => $locked->revision_number, 'title' => $locked->title, 'note' => $note], $actor);

            return $locked;
        });
    }

    protected function assertPending(CreatorPuzzleRevision $revision): void
    {
        if (! $revision->isPendingReview()) {
            throw new CreatorException('هذه النسخة ليست قيد المراجعة (ربما عولجت للتو).');
        }
    }

    protected function assertReviewer(User $actor): void
    {
        abort_unless($actor->can('creators.review'), 403);
    }

    protected function requireNote(string $note): string
    {
        $note = trim($note);
        $min = (int) config('creators.limits.note_min');
        $max = (int) config('creators.limits.note_max');

        if (mb_strlen($note) < $min || mb_strlen($note) > $max) {
            throw new CreatorException("اكتب سببًا واضحًا ({$min}–{$max} حرفًا).");
        }

        return $note;
    }
}
