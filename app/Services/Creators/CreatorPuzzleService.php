<?php

namespace App\Services\Creators;

use App\Models\CreatorProfile;
use App\Models\CreatorPuzzle;
use App\Models\CreatorPuzzleRevision;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * E28 - دورة تأليف المبدع: مسودة ← إرسال ← (طلب تعديل ↔ إعادة إرسال | اعتماد | رفض)، ثم نسخة جديدة بعد النشر، أرشفة، حذف.
 *
 * كل عملية: (1) المؤهَّل = ملف معتمَد + بريد موثَّق + غير مجمَّد (CreatorEligibility) فالمعلَّق/المجمَّد لا ينشئ ولا يعدّل ولا يرسل،
 * (2) الملكية بالاستعلام نفسه (creator_profile_id) فعمل غيرك = 404 كأنه غير موجود (لا IDOR)، (3) قفل صف النسخة بمعاملة فلا تعارض تزامن،
 * (4) النسخة المنشورة/المرفوضة/المرسَلة لا تُعدَّل أبدًا: التعديل بعد النشر = نسخة جديدة (revise) تبقى القديمة حيّة حتى اعتماد الجديدة.
 */
class CreatorPuzzleService
{
    public const BUCKETS = ['all', 'draft', 'pending', 'changes', 'published', 'rejected', 'archived'];

    public function __construct(
        protected CreatorEligibility $eligibility,
        protected CreatorContent $content,
        protected CreatorMediaService $media,
    ) {}

    // ───────────── القراءة ─────────────

    /** عمل المبدع نفسه فقط (404 لغيره). */
    public function ownedWork(CreatorProfile $profile, string $publicId): CreatorPuzzle
    {
        return CreatorPuzzle::query()->where('creator_profile_id', $profile->id)->where('public_id', $publicId)->firstOrFail();
    }

    /** قائمة أعمال المبدع بتصفية الحالة (مشتقّة من آخر نسخة + الأرشفة) وترقيم؛ بلا أعمدة المحتوى المشفَّر. */
    public function paginate(CreatorProfile $profile, string $bucket = 'all'): LengthAwarePaginator
    {
        $query = CreatorPuzzle::query()
            ->select('creator_puzzles.*')
            ->where('creator_puzzles.creator_profile_id', $profile->id)
            ->leftJoin('creator_puzzle_revisions as lr', 'lr.id', '=', 'creator_puzzles.latest_revision_id');

        match ($bucket) {
            'draft' => $query->whereNull('creator_puzzles.archived_at')->where('lr.status', CreatorPuzzleRevision::STATUS_DRAFT),
            'pending' => $query->whereNull('creator_puzzles.archived_at')->where('lr.status', CreatorPuzzleRevision::STATUS_PENDING_REVIEW),
            'changes' => $query->whereNull('creator_puzzles.archived_at')->where('lr.status', CreatorPuzzleRevision::STATUS_CHANGES_REQUESTED),
            'published' => $query->whereNull('creator_puzzles.archived_at')->whereNotNull('creator_puzzles.published_puzzle_id'),
            'rejected' => $query->whereNull('creator_puzzles.archived_at')->where('lr.status', CreatorPuzzleRevision::STATUS_REJECTED),
            'archived' => $query->whereNotNull('creator_puzzles.archived_at'),
            default => null,
        };

        return $query
            ->with(['latestRevision' => fn ($q) => $q->select('id', 'creator_puzzle_id', 'revision_number', 'status', 'kind', 'title', 'puzzle_category_id', 'difficulty', 'review_note', 'reviewed_at', 'submitted_at'), 'latestRevision.category:id,name'])
            ->orderByDesc('creator_puzzles.id')
            ->paginate((int) config('creators.page_size'))
            ->withQueryString();
    }

    /** آخر أعمال المبدع للوحة (بلا ترقيم، سقف صغير). */
    public function recent(CreatorProfile $profile, int $limit = 5)
    {
        return CreatorPuzzle::query()
            ->where('creator_profile_id', $profile->id)
            ->with(['latestRevision' => fn ($q) => $q->select('id', 'creator_puzzle_id', 'revision_number', 'status', 'kind', 'title', 'puzzle_category_id', 'difficulty', 'review_note', 'reviewed_at', 'submitted_at'), 'latestRevision.category:id,name'])
            ->orderByDesc('id')->limit($limit)->get();
    }

    /** عدّادات حاويات اللوحة باستعلام واحد (المبدع محدود بسقف أعمال، فالتجميع صغير ومفهرس بالمالك). */
    public function bucketCounts(CreatorProfile $profile): array
    {
        $row = CreatorPuzzle::query()
            ->leftJoin('creator_puzzle_revisions as lr', 'lr.id', '=', 'creator_puzzles.latest_revision_id')
            ->where('creator_puzzles.creator_profile_id', $profile->id)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN creator_puzzles.archived_at IS NULL AND lr.status = ? THEN 1 ELSE 0 END) as draft', [CreatorPuzzleRevision::STATUS_DRAFT])
            ->selectRaw('SUM(CASE WHEN creator_puzzles.archived_at IS NULL AND lr.status = ? THEN 1 ELSE 0 END) as pending', [CreatorPuzzleRevision::STATUS_PENDING_REVIEW])
            ->selectRaw('SUM(CASE WHEN creator_puzzles.archived_at IS NULL AND lr.status = ? THEN 1 ELSE 0 END) as changes', [CreatorPuzzleRevision::STATUS_CHANGES_REQUESTED])
            ->selectRaw('SUM(CASE WHEN creator_puzzles.archived_at IS NULL AND creator_puzzles.published_puzzle_id IS NOT NULL THEN 1 ELSE 0 END) as published')
            ->selectRaw('SUM(CASE WHEN creator_puzzles.archived_at IS NULL AND lr.status = ? THEN 1 ELSE 0 END) as rejected', [CreatorPuzzleRevision::STATUS_REJECTED])
            ->selectRaw('SUM(CASE WHEN creator_puzzles.archived_at IS NOT NULL THEN 1 ELSE 0 END) as archived')
            ->first();

        return [
            'all' => (int) ($row->total ?? 0),
            'draft' => (int) ($row->draft ?? 0),
            'pending' => (int) ($row->pending ?? 0),
            'changes' => (int) ($row->changes ?? 0),
            'published' => (int) ($row->published ?? 0),
            'rejected' => (int) ($row->rejected ?? 0),
            'archived' => (int) ($row->archived ?? 0),
        ];
    }

    // ───────────── الكتابة ─────────────

    /** @param array<string, mixed> $data مدخلات مُتحقَّق منها (kind, title, puzzle_category_id, difficulty, ...حقول النوع) */
    public function createDraft(User $user, array $data, ?UploadedFile $image = null): CreatorPuzzleRevision
    {
        $profile = $this->eligibility->studioProfile($user);
        $kind = (string) ($data['kind'] ?? '');

        if (! in_array($kind, CreatorContent::kinds(), true)) {
            throw new CreatorException('نوع الأحجية غير مدعوم.');
        }

        $imagePath = null;

        try {
            return DB::transaction(function () use ($profile, $kind, $data, $image, &$imagePath) {
                CreatorProfile::query()->whereKey($profile->id)->lockForUpdate()->first(); // يسلسل فحص السقوف للمبدع الواحد
                $this->assertCaps($profile, newWork: true);

                $work = new CreatorPuzzle;
                $work->forceFill(['creator_profile_id' => $profile->id])->save();

                if ($image !== null && (CreatorContent::kindConfig($kind)['media'] ?? false)) {
                    $imagePath = $this->media->store($image, $profile);
                }

                $revision = new CreatorPuzzleRevision;
                $revision->forceFill([
                    'creator_puzzle_id' => $work->id,
                    'revision_number' => 1,
                    'status' => CreatorPuzzleRevision::STATUS_DRAFT,
                    'kind' => $kind,
                    'title' => trim((string) $data['title']),
                    'puzzle_category_id' => (int) $data['puzzle_category_id'],
                    'difficulty' => (string) $data['difficulty'],
                    'content' => $this->content->normalize($kind, $data),
                    'image_path' => $imagePath,
                ])->save();

                $work->forceFill(['latest_revision_id' => $revision->id])->save();

                return $revision;
            });
        } catch (\Throwable $e) {
            $this->media->delete($imagePath, $profile);

            throw $e;
        }
    }

    public function updateDraft(User $user, string $publicId, array $data, ?UploadedFile $image = null, bool $removeImage = false): CreatorPuzzleRevision
    {
        $profile = $this->eligibility->studioProfile($user);
        $work = $this->ownedWork($profile, $publicId);
        $newPath = null;
        $oldPath = null;

        try {
            $revision = DB::transaction(function () use ($work, $profile, $data, $image, $removeImage, &$newPath, &$oldPath) {
                $revision = CreatorPuzzleRevision::query()->lockForUpdate()->findOrFail($work->latest_revision_id);

                if (! $revision->isEditable()) {
                    throw new CreatorException($revision->isPendingReview() ? 'الأحجية قيد المراجعة؛ اسحبها أولًا لتعديلها.' : 'لا يمكن تعديل هذه النسخة؛ أنشئ نسخة جديدة.');
                }

                $kind = (string) $revision->kind;
                $supportsImage = (bool) (CreatorContent::kindConfig($kind)['media'] ?? false);
                $path = $revision->image_path;

                if ($supportsImage && $image !== null) {
                    $newPath = $this->media->store($image, $profile);
                    $oldPath = $path;
                    $path = $newPath;
                } elseif ($supportsImage && $removeImage) {
                    $oldPath = $path;
                    $path = null;
                }

                $revision->forceFill([
                    'title' => trim((string) $data['title']),
                    'puzzle_category_id' => (int) $data['puzzle_category_id'],
                    'difficulty' => (string) $data['difficulty'],
                    'content' => $this->content->normalize($kind, $data),
                    'image_path' => $path,
                ])->save();

                return $revision;
            });
        } catch (\Throwable $e) {
            $this->media->delete($newPath, $profile);

            throw $e;
        }

        $this->media->delete($oldPath, $profile); // بعد الـcommit: الملف القديم لا يُحذف قبل نجاح الاستبدال

        return $revision;
    }

    /** حذف الصورة وحدها من مسودة (مسار مستقل للتحقق من الملكية). */
    public function removeImage(User $user, string $publicId): CreatorPuzzleRevision
    {
        $profile = $this->eligibility->studioProfile($user);
        $work = $this->ownedWork($profile, $publicId);

        $oldPath = null;

        $revision = DB::transaction(function () use ($work, &$oldPath) {
            $revision = CreatorPuzzleRevision::query()->lockForUpdate()->findOrFail($work->latest_revision_id);

            if (! $revision->isEditable()) {
                throw new CreatorException('لا يمكن تعديل هذه النسخة الآن.');
            }

            $oldPath = $revision->image_path;
            $revision->forceFill(['image_path' => null])->save();

            return $revision;
        });

        $this->media->delete($oldPath, $profile);

        return $revision;
    }

    public function submit(User $user, string $publicId): CreatorPuzzleRevision
    {
        $profile = $this->eligibility->studioProfile($user);
        $work = $this->ownedWork($profile, $publicId);

        return DB::transaction(function () use ($work, $profile) {
            CreatorProfile::query()->whereKey($profile->id)->lockForUpdate()->first();
            $revision = CreatorPuzzleRevision::query()->lockForUpdate()->findOrFail($work->latest_revision_id);

            if (! $revision->isEditable()) {
                throw new CreatorException('هذه النسخة أُرسلت بالفعل أو لا يمكن إرسالها.');
            }

            $this->content->assertReady($revision);

            $pending = CreatorPuzzleRevision::query()
                ->join('creator_puzzles', 'creator_puzzles.id', '=', 'creator_puzzle_revisions.creator_puzzle_id')
                ->where('creator_puzzles.creator_profile_id', $profile->id)
                ->where('creator_puzzle_revisions.status', CreatorPuzzleRevision::STATUS_PENDING_REVIEW)
                ->count();

            if ($pending >= (int) config('creators.caps.pending_review')) {
                throw new CreatorException('وصلت للحد الأقصى من الأحجيات قيد المراجعة؛ انتظر نتيجة مراجعتها.');
            }

            $revision->forceFill([
                'status' => CreatorPuzzleRevision::STATUS_PENDING_REVIEW,
                'submitted_at' => now(),
                'reviewed_at' => null,
                'reviewed_by' => null,
                'review_note' => null,
            ])->save();

            return $revision;
        });
    }

    /** سحب إرسال قيد المراجعة إلى مسودة (يخضع لمحدّد creator-submit كي لا تُغرَق المراجعة بدورات إرسال/سحب). */
    public function withdraw(User $user, string $publicId): CreatorPuzzleRevision
    {
        $profile = $this->eligibility->studioProfile($user);
        $work = $this->ownedWork($profile, $publicId);

        return DB::transaction(function () use ($work) {
            $revision = CreatorPuzzleRevision::query()->lockForUpdate()->findOrFail($work->latest_revision_id);

            if (! $revision->isPendingReview()) {
                throw new CreatorException('لا يوجد إرسال قيد المراجعة لسحبه.');
            }

            $revision->forceFill(['status' => CreatorPuzzleRevision::STATUS_DRAFT, 'submitted_at' => null])->save();

            return $revision;
        });
    }

    /** نسخة جديدة مسودة (تعديل بعد النشر/إعادة بعد الرفض) من آخر نسخة نهائية؛ المنشورة الحالية تبقى حيّة بلا مساس. */
    public function revise(User $user, string $publicId): CreatorPuzzleRevision
    {
        $profile = $this->eligibility->studioProfile($user);
        $work = $this->ownedWork($profile, $publicId);

        $copiedPath = null;

        try {
            return DB::transaction(function () use ($work, $profile, &$copiedPath) {
                CreatorProfile::query()->whereKey($profile->id)->lockForUpdate()->first();
                $latest = CreatorPuzzleRevision::query()->lockForUpdate()->findOrFail($work->latest_revision_id);

                if (! in_array($latest->status, [CreatorPuzzleRevision::STATUS_APPROVED, CreatorPuzzleRevision::STATUS_REJECTED], true)) {
                    throw new CreatorException('لديك نسخة مفتوحة بالفعل لهذه الأحجية.');
                }

                $this->assertCaps($profile, newWork: false);

                $copiedPath = $this->media->copy($latest->image_path, $profile);

                $revision = new CreatorPuzzleRevision;
                $revision->forceFill([
                    'creator_puzzle_id' => $work->id,
                    'revision_number' => (int) CreatorPuzzleRevision::query()->where('creator_puzzle_id', $work->id)->max('revision_number') + 1,
                    'status' => CreatorPuzzleRevision::STATUS_DRAFT,
                    'kind' => $latest->kind,
                    'title' => $latest->title,
                    'puzzle_category_id' => $latest->puzzle_category_id,
                    'difficulty' => $latest->difficulty,
                    'content' => $latest->content,
                    'image_path' => $copiedPath,
                ])->save();

                $work->forceFill(['latest_revision_id' => $revision->id])->save();

                return $revision;
            });
        } catch (\Throwable $e) {
            $this->media->delete($copiedPath, $profile);

            throw $e;
        }
    }

    /**
     * حذف: عمل لم يُنشر قط يُحذف كاملًا (مع ملفاته)؛ عمل منشور لا يُحذف أبدًا (الأرشفة) لكن مسودة التعديل المفتوحة عليه تُحذف وحدها.
     * قيد المراجعة لا يُحذف (يُسحب أولًا).
     */
    public function destroy(User $user, string $publicId): void
    {
        $profile = $this->eligibility->studioProfile($user);
        $work = $this->ownedWork($profile, $publicId);
        $paths = [];

        DB::transaction(function () use ($work, &$paths) {
            $latest = CreatorPuzzleRevision::query()->lockForUpdate()->findOrFail($work->latest_revision_id);

            if ($latest->isPendingReview()) {
                throw new CreatorException('اسحب الأحجية من المراجعة أولًا.');
            }

            if ($work->isPublished()) {
                if (! $latest->isEditable()) {
                    throw new CreatorException('الأحجية المنشورة لا تُحذف؛ يمكنك أرشفتها.');
                }

                $paths[] = $latest->image_path;
                $latest->delete();

                $previous = CreatorPuzzleRevision::query()->where('creator_puzzle_id', $work->id)->orderByDesc('revision_number')->first();
                $work->forceFill(['latest_revision_id' => $previous?->id])->save();

                return;
            }

            $paths = CreatorPuzzleRevision::query()->where('creator_puzzle_id', $work->id)->pluck('image_path')->all();
            $work->delete();
        });

        foreach ($paths as $path) {
            $this->media->delete($path, $profile);
        }
    }

    public function archive(User $user, string $publicId): CreatorPuzzle
    {
        $profile = $this->eligibility->studioProfile($user);
        $work = $this->ownedWork($profile, $publicId);

        if (! $work->isPublished()) {
            throw new CreatorException('الأرشفة للأحجيات المنشورة فقط.');
        }

        if ($work->isArchived()) {
            return $work;
        }

        $work->forceFill(['archived_at' => now()])->save();

        return $work;
    }

    public function unarchive(User $user, string $publicId): CreatorPuzzle
    {
        $profile = $this->eligibility->studioProfile($user);
        $work = $this->ownedWork($profile, $publicId);

        if ($work->isArchived()) {
            $work->forceFill(['archived_at' => null])->save();
        }

        return $work;
    }

    protected function assertCaps(CreatorProfile $profile, bool $newWork): void
    {
        if ($newWork && CreatorPuzzle::query()->where('creator_profile_id', $profile->id)->count() >= (int) config('creators.caps.works')) {
            throw new CreatorException('وصلت للحد الأقصى من الأعمال. احذف مسودة أو أرشف عملًا قديمًا.');
        }

        $open = CreatorPuzzleRevision::query()
            ->join('creator_puzzles', 'creator_puzzles.id', '=', 'creator_puzzle_revisions.creator_puzzle_id')
            ->where('creator_puzzles.creator_profile_id', $profile->id)
            ->whereIn('creator_puzzle_revisions.status', [CreatorPuzzleRevision::STATUS_DRAFT, CreatorPuzzleRevision::STATUS_CHANGES_REQUESTED])
            ->count();

        if ($open >= (int) config('creators.caps.open_drafts')) {
            throw new CreatorException('لديك مسودات كثيرة مفتوحة؛ أرسل بعضها للمراجعة أو احذفها أولًا.');
        }
    }
}
