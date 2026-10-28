<?php

namespace App\Services\CreatorSeries;

use App\Models\CreatorProfile;
use App\Models\CreatorPuzzle;
use App\Models\CreatorSeries;
use App\Models\CreatorSeriesItem;
use App\Models\CreatorSeriesRevision;
use App\Models\User;
use App\Services\Creators\CreatorEligibility;
use App\Services\Creators\CreatorMediaService;
use App\Services\CreatorPremium\CreatorPremiumPublishGuard;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * E30 - دورة تأليف السلسلة (استوديو المبدع): مسودة ← إرسال ← (طلب تعديل ↔ إعادة إرسال | اعتماد | رفض) ثم نسخة جديدة بعد النشر، أرشفة، حذف.
 * نفس ضمانات E28 للأحجيات حرفيًا:
 *  (1) الأهلية: ملف مبدع معتمَد + بريد موثَّق + حساب غير مجمَّد (CreatorEligibility) فالمعلَّق/المجمَّد لا ينشئ ولا يعدّل ولا يرسل.
 *  (2) الملكية بالاستعلام نفسه (creator_profile_id) فسلسلة غيرك = 404 كأنها غير موجودة (لا IDOR).
 *  (3) العناصر **أعمال المبدع نفسه فقط، منشورة وظاهرة الآن**، بلا تكرار، من 2 إلى 25 (قابل للضبط)، بموضع صريح 1..n. أحجية رسمية/لمبدع آخر ⇒ "غير موجودة" بلا تسريب.
 *  (4) قفل صف النسخة بمعاملة فلا تعارض تزامن؛ النسخة المرسلة/المعتمدة/المرفوضة لا تُعدَّل أبدًا: التعديل بعد النشر = نسخة جديدة تُراجَع، والمنشورة تبقى حيّة حتى اعتماد الجديدة.
 *  (5) نصوص عادية فقط (تنظيف العلامات في الطلب) ولا اقتصاد: لا يوجد هنا أي مرجع للمحافظ/XP/الجوائز.
 */
class CreatorSeriesService
{
    public const BUCKETS = ['all', 'draft', 'pending', 'changes', 'published', 'rejected', 'archived'];

    public function __construct(
        protected CreatorEligibility $eligibility,
        protected CreatorMediaService $media,
        protected CreatorSeriesVisibilityService $visibility,
    ) {}

    // ───────────── القراءة ─────────────

    /** سلسلة المبدع نفسه فقط (404 لغيره). */
    public function ownedSeries(CreatorProfile $profile, string $publicId): CreatorSeries
    {
        return CreatorSeries::query()->where('creator_profile_id', $profile->id)->where('public_id', $publicId)->firstOrFail();
    }

    public function paginate(CreatorProfile $profile, string $bucket = 'all'): LengthAwarePaginator
    {
        $query = CreatorSeries::query()
            ->select('creator_series.*')
            ->where('creator_series.creator_profile_id', $profile->id)
            ->leftJoin('creator_series_revisions as lr', 'lr.id', '=', 'creator_series.latest_revision_id');

        match ($bucket) {
            'draft' => $query->whereNull('creator_series.archived_at')->where('lr.status', CreatorSeriesRevision::STATUS_DRAFT),
            'pending' => $query->whereNull('creator_series.archived_at')->where('lr.status', CreatorSeriesRevision::STATUS_PENDING_REVIEW),
            'changes' => $query->whereNull('creator_series.archived_at')->where('lr.status', CreatorSeriesRevision::STATUS_CHANGES_REQUESTED),
            'published' => $query->whereNull('creator_series.archived_at')->whereNotNull('creator_series.published_revision_id'),
            'rejected' => $query->whereNull('creator_series.archived_at')->where('lr.status', CreatorSeriesRevision::STATUS_REJECTED),
            'archived' => $query->whereNotNull('creator_series.archived_at'),
            default => null,
        };

        return $query
            ->with(['latestRevision' => fn ($q) => $q->select('id', 'creator_series_id', 'revision_number', 'status', 'title', 'review_note', 'reviewed_at', 'submitted_at')])
            ->orderByDesc('creator_series.id')
            ->paginate((int) config('creator_series.studio_page_size'))
            ->withQueryString();
    }

    /** عدّادات حاويات الاستوديو باستعلام واحد. @return array<string,int> */
    public function bucketCounts(CreatorProfile $profile): array
    {
        $row = CreatorSeries::query()
            ->leftJoin('creator_series_revisions as lr', 'lr.id', '=', 'creator_series.latest_revision_id')
            ->where('creator_series.creator_profile_id', $profile->id)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN creator_series.archived_at IS NULL AND lr.status = ? THEN 1 ELSE 0 END) as draft', [CreatorSeriesRevision::STATUS_DRAFT])
            ->selectRaw('SUM(CASE WHEN creator_series.archived_at IS NULL AND lr.status = ? THEN 1 ELSE 0 END) as pending', [CreatorSeriesRevision::STATUS_PENDING_REVIEW])
            ->selectRaw('SUM(CASE WHEN creator_series.archived_at IS NULL AND lr.status = ? THEN 1 ELSE 0 END) as changes', [CreatorSeriesRevision::STATUS_CHANGES_REQUESTED])
            ->selectRaw('SUM(CASE WHEN creator_series.archived_at IS NULL AND creator_series.published_revision_id IS NOT NULL THEN 1 ELSE 0 END) as published')
            ->selectRaw('SUM(CASE WHEN creator_series.archived_at IS NULL AND lr.status = ? THEN 1 ELSE 0 END) as rejected', [CreatorSeriesRevision::STATUS_REJECTED])
            ->selectRaw('SUM(CASE WHEN creator_series.archived_at IS NOT NULL THEN 1 ELSE 0 END) as archived')
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

    /** أعمال المبدع المؤهَّلة كعناصر (منشورة وظاهرة الآن) لمنتقي المنشئ. بلا أعمدة المحتوى. */
    public function eligibleWorks(CreatorProfile $profile): Collection
    {
        return CreatorPuzzle::query()->visible()
            ->where('creator_profile_id', $profile->id)
            ->select('id', 'public_id', 'published_title', 'published_difficulty', 'published_category_id')
            ->with('publishedCategory:id,name')
            ->orderByDesc('published_at')->orderByDesc('id')
            ->limit(max(1, (int) config('creators.caps.works', 100)))
            ->get();
    }

    /** عناصر نسخة بترتيبها (للمنشئ والمعاينة): معرّف العمل العام والعنوان الحالي وظهوره. */
    public function revisionItems(CreatorSeriesRevision $revision): Collection
    {
        return CreatorSeriesItem::query()
            ->join('creator_puzzles as w', 'w.id', '=', 'creator_series_items.creator_puzzle_id')
            ->where('creator_series_items.creator_series_revision_id', $revision->id)
            ->orderBy('creator_series_items.position')
            ->get(['creator_series_items.position', 'w.public_id', 'w.published_title', 'w.is_visible']);
    }

    // ───────────── الكتابة ─────────────

    /**
     * @param  array{title:string, description?:?string, items:array<int,string>}  $data  مدخلات مُتحقَّق منها
     */
    public function createDraft(User $user, array $data, ?UploadedFile $cover = null): CreatorSeriesRevision
    {
        $profile = $this->eligibility->studioProfile($user);
        $coverPath = null;

        try {
            return DB::transaction(function () use ($profile, $data, $cover, &$coverPath) {
                CreatorProfile::query()->whereKey($profile->id)->lockForUpdate()->first(); // يسلسل فحص السقوف للمبدع الواحد
                $this->assertCap($profile);
                $works = $this->resolveItems($profile, (array) ($data['items'] ?? []));

                if ($cover !== null && (bool) config('creator_series.cover.enabled', true)) {
                    $coverPath = $this->media->store($cover, $profile);
                }

                $title = $this->title($data['title'] ?? '');

                $series = new CreatorSeries;
                $series->forceFill(['creator_profile_id' => $profile->id, 'slug' => $this->uniqueSlug($title)])->save();

                $revision = new CreatorSeriesRevision;
                $revision->forceFill([
                    'creator_series_id' => $series->id,
                    'revision_number' => 1,
                    'status' => CreatorSeriesRevision::STATUS_DRAFT,
                    'title' => $title,
                    'description' => $this->description($data['description'] ?? null),
                    'cover_path' => $coverPath,
                ])->save();

                $this->replaceItems($revision, $works);
                $series->forceFill(['latest_revision_id' => $revision->id])->save();

                return $revision;
            });
        } catch (\Throwable $e) {
            $this->media->delete($coverPath, $profile);

            throw $e;
        }
    }

    public function updateDraft(User $user, string $publicId, array $data, ?UploadedFile $cover = null, bool $removeCover = false): CreatorSeriesRevision
    {
        $profile = $this->eligibility->studioProfile($user);
        $series = $this->ownedSeries($profile, $publicId);
        $newPath = null;
        $oldPath = null;

        try {
            $revision = DB::transaction(function () use ($series, $profile, $data, $cover, $removeCover, &$newPath, &$oldPath) {
                $revision = CreatorSeriesRevision::query()->lockForUpdate()->findOrFail($series->latest_revision_id);

                if (! $revision->isEditable()) {
                    throw new CreatorSeriesException($revision->isPendingReview() ? 'السلسلة قيد المراجعة؛ اسحبها أولًا لتعديلها.' : 'لا يمكن تعديل هذه النسخة؛ أنشئ نسخة جديدة.');
                }

                $works = $this->resolveItems($profile, (array) ($data['items'] ?? []));
                $path = $revision->cover_path;

                if ($cover !== null && (bool) config('creator_series.cover.enabled', true)) {
                    $newPath = $this->media->store($cover, $profile);
                    $oldPath = $path;
                    $path = $newPath;
                } elseif ($removeCover) {
                    $oldPath = $path;
                    $path = null;
                }

                $revision->forceFill([
                    'title' => $this->title($data['title'] ?? ''),
                    'description' => $this->description($data['description'] ?? null),
                    'cover_path' => $path,
                ])->save();

                $this->replaceItems($revision, $works);

                return $revision;
            });
        } catch (\Throwable $e) {
            $this->media->delete($newPath, $profile);

            throw $e;
        }

        $this->media->delete($oldPath, $profile); // بعد الـcommit: الغلاف القديم لا يُحذف قبل نجاح الاستبدال

        return $revision;
    }

    public function submit(User $user, string $publicId): CreatorSeriesRevision
    {
        $profile = $this->eligibility->studioProfile($user);
        $series = $this->ownedSeries($profile, $publicId);

        return DB::transaction(function () use ($series, $profile) {
            CreatorProfile::query()->whereKey($profile->id)->lockForUpdate()->first();
            $revision = CreatorSeriesRevision::query()->lockForUpdate()->findOrFail($series->latest_revision_id);

            if (! $revision->isEditable()) {
                throw new CreatorSeriesException('هذه النسخة أُرسلت بالفعل أو لا يمكن إرسالها.');
            }

            // إعادة تحقق لحظة الإرسال: عنصر أُخفي/أُرشف منذ الحفظ يمنع الإرسال بدل أن تُرسَل سلسلة لن تظهر.
            $this->assertItemsReady($profile, $revision);

            $pending = CreatorSeriesRevision::query()
                ->join('creator_series', 'creator_series.id', '=', 'creator_series_revisions.creator_series_id')
                ->where('creator_series.creator_profile_id', $profile->id)
                ->where('creator_series_revisions.status', CreatorSeriesRevision::STATUS_PENDING_REVIEW)
                ->count();

            if ($pending >= (int) config('creator_series.limits.max_pending')) {
                throw new CreatorSeriesException('وصلت للحد الأقصى من السلاسل قيد المراجعة؛ انتظر نتيجة مراجعتها.');
            }

            $revision->forceFill([
                'status' => CreatorSeriesRevision::STATUS_PENDING_REVIEW,
                'submitted_at' => now(),
                'reviewed_at' => null,
                'reviewed_by' => null,
                'review_note' => null,
            ])->save();

            return $revision;
        });
    }

    public function withdraw(User $user, string $publicId): CreatorSeriesRevision
    {
        $profile = $this->eligibility->studioProfile($user);
        $series = $this->ownedSeries($profile, $publicId);

        return DB::transaction(function () use ($series) {
            $revision = CreatorSeriesRevision::query()->lockForUpdate()->findOrFail($series->latest_revision_id);

            if (! $revision->isPendingReview()) {
                throw new CreatorSeriesException('لا يوجد إرسال قيد المراجعة لسحبه.');
            }

            $revision->forceFill(['status' => CreatorSeriesRevision::STATUS_DRAFT, 'submitted_at' => null])->save();

            return $revision;
        });
    }

    /** نسخة جديدة مسودة من آخر نسخة نهائية (معتمدة/مرفوضة)؛ المنشورة الحالية تبقى حيّة بلا مساس. */
    public function revise(User $user, string $publicId): CreatorSeriesRevision
    {
        $profile = $this->eligibility->studioProfile($user);
        $series = $this->ownedSeries($profile, $publicId);
        $copiedPath = null;

        try {
            return DB::transaction(function () use ($series, $profile, &$copiedPath) {
                CreatorProfile::query()->whereKey($profile->id)->lockForUpdate()->first();
                $latest = CreatorSeriesRevision::query()->lockForUpdate()->findOrFail($series->latest_revision_id);

                if (! in_array($latest->status, [CreatorSeriesRevision::STATUS_APPROVED, CreatorSeriesRevision::STATUS_REJECTED], true)) {
                    throw new CreatorSeriesException('لديك نسخة مفتوحة بالفعل لهذه السلسلة.');
                }

                $copiedPath = $this->media->copy($latest->cover_path, $profile);

                $revision = new CreatorSeriesRevision;
                $revision->forceFill([
                    'creator_series_id' => $series->id,
                    'revision_number' => (int) CreatorSeriesRevision::query()->where('creator_series_id', $series->id)->max('revision_number') + 1,
                    'status' => CreatorSeriesRevision::STATUS_DRAFT,
                    'title' => $latest->title,
                    'description' => $latest->description,
                    'cover_path' => $copiedPath,
                ])->save();

                $rows = CreatorSeriesItem::query()->where('creator_series_revision_id', $latest->id)->orderBy('position')->get(['creator_puzzle_id', 'position']);
                $now = now();
                CreatorSeriesItem::query()->insert($rows->map(fn ($r) => [
                    'creator_series_revision_id' => $revision->id,
                    'creator_puzzle_id' => $r->creator_puzzle_id,
                    'position' => $r->position,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all());

                $series->forceFill(['latest_revision_id' => $revision->id])->save();

                return $revision;
            });
        } catch (\Throwable $e) {
            $this->media->delete($copiedPath, $profile);

            throw $e;
        }
    }

    /** سلسلة لم تُنشر قط تُحذف كاملة (مع أغلفتها)؛ المنشورة لا تُحذف (الأرشفة) لكن مسودة التعديل المفتوحة عليها تُحذف وحدها؛ قيد المراجعة يُسحب أولًا. */
    public function destroy(User $user, string $publicId): void
    {
        $profile = $this->eligibility->studioProfile($user);
        $series = $this->ownedSeries($profile, $publicId);
        $paths = [];

        DB::transaction(function () use ($series, &$paths) {
            $latest = CreatorSeriesRevision::query()->lockForUpdate()->findOrFail($series->latest_revision_id);

            if ($latest->isPendingReview()) {
                throw new CreatorSeriesException('اسحب السلسلة من المراجعة أولًا.');
            }

            if ($series->isPublished()) {
                if (! $latest->isEditable()) {
                    throw new CreatorSeriesException('السلسلة المنشورة لا تُحذف؛ يمكنك أرشفتها.');
                }

                $paths[] = $latest->cover_path;
                $latest->delete();

                $previous = CreatorSeriesRevision::query()->where('creator_series_id', $series->id)->orderByDesc('revision_number')->first();
                $series->forceFill(['latest_revision_id' => $previous?->id])->save();

                return;
            }

            $paths = CreatorSeriesRevision::query()->where('creator_series_id', $series->id)->pluck('cover_path')->all();
            $series->delete();
        });

        foreach ($paths as $path) {
            $this->media->delete($path, $profile);
        }
    }

    public function archive(User $user, string $publicId): CreatorSeries
    {
        $profile = $this->eligibility->studioProfile($user);
        $series = $this->ownedSeries($profile, $publicId);

        if (! $series->isPublished()) {
            throw new CreatorSeriesException('الأرشفة للسلاسل المنشورة فقط.');
        }

        // E43: سلسلة بعرض بيع نشط/موقوف لا تُؤرشف (يختفي محتواها عن مالكيها ويبقى قفلها): أغلق العرض من «السلاسل المميزة» أولًا.
        if (! $series->isArchived() && app(CreatorPremiumPublishGuard::class)->hasLiveOffer($series)) {
            throw new CreatorSeriesException('أغلق عرض البيع لهذه السلسلة المميزة قبل أرشفتها.');
        }

        if (! $series->isArchived()) {
            $series->forceFill(['archived_at' => now()])->save();
        }

        return $series;
    }

    public function unarchive(User $user, string $publicId): CreatorSeries
    {
        $profile = $this->eligibility->studioProfile($user);
        $series = $this->ownedSeries($profile, $publicId);

        if ($series->isArchived()) {
            $series->forceFill(['archived_at' => null])->save();
        }

        return $series;
    }

    // ───────────── التحقق المشترك ─────────────

    /**
     * يحوّل قائمة معرّفات عامة مرتّبة إلى أعمال المبدع نفسه الظاهرة الآن، أو يرمي برسالة عربية.
     * عمل غير موجود ضمن أعمال هذا المبدع (أحجية رسمية/لمبدع آخر/معرّف مختلق) = نفس الرسالة بلا تسريب وجود.
     *
     * @param  array<int, mixed>  $publicIds
     * @return Collection<int, CreatorPuzzle> بترتيب المدخلات
     */
    public function resolveItems(CreatorProfile $profile, array $publicIds): Collection
    {
        $min = (int) config('creator_series.limits.min_items');
        $max = (int) config('creator_series.limits.max_items');

        $ids = array_values(array_filter($publicIds, 'is_string'));

        if (count($ids) !== count($publicIds)) {
            throw new CreatorSeriesException('قائمة الأحجيات غير صالحة.');
        }

        if (count($ids) < $min) {
            throw new CreatorSeriesException("أضف {$min} أحجيات على الأقل إلى السلسلة.");
        }

        if (count($ids) > $max) {
            throw new CreatorSeriesException("الحد الأقصى {$max} أحجية في السلسلة الواحدة.");
        }

        if (count(array_unique($ids)) !== count($ids)) {
            throw new CreatorSeriesException('لا يمكن تكرار الأحجية نفسها داخل السلسلة.');
        }

        $works = CreatorPuzzle::query()
            ->where('creator_profile_id', $profile->id)->whereIn('public_id', $ids)
            ->get(['id', 'public_id', 'is_visible', 'published_title'])->keyBy('public_id');

        $ordered = new Collection;

        foreach ($ids as $id) {
            $work = $works->get($id);

            if ($work === null) {
                throw new CreatorSeriesException('إحدى الأحجيات المختارة غير موجودة ضمن أعمالك المنشورة.');
            }

            if (! $work->is_visible) {
                throw new CreatorSeriesException('الأحجية «'.$work->published_title.'» غير منشورة أو مخفية حاليًا؛ لا يمكن إدراجها.');
            }

            $ordered->push($work);
        }

        return $ordered;
    }

    /** إعادة تحقق عناصر نسخة محفوظة (لحظة الإرسال والاعتماد): العدد والملكية والظهور الآن. */
    public function assertItemsReady(CreatorProfile $profile, CreatorSeriesRevision $revision): void
    {
        $publicIds = CreatorSeriesItem::query()
            ->join('creator_puzzles as w', 'w.id', '=', 'creator_series_items.creator_puzzle_id')
            ->where('creator_series_items.creator_series_revision_id', $revision->id)
            ->orderBy('creator_series_items.position')
            ->pluck('w.public_id')->all();

        $this->resolveItems($profile, $publicIds);
    }

    protected function replaceItems(CreatorSeriesRevision $revision, Collection $works): void
    {
        CreatorSeriesItem::query()->where('creator_series_revision_id', $revision->id)->delete();

        $now = now();
        $rows = [];

        foreach ($works->values() as $index => $work) {
            $rows[] = [
                'creator_series_revision_id' => $revision->id,
                'creator_puzzle_id' => $work->id,
                'position' => $index + 1,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        CreatorSeriesItem::query()->insert($rows);
    }

    protected function assertCap(CreatorProfile $profile): void
    {
        $count = CreatorSeries::query()->where('creator_profile_id', $profile->id)->whereNull('archived_at')->count();

        if ($count >= (int) config('creator_series.limits.max_per_creator')) {
            throw new CreatorSeriesException('وصلت للحد الأقصى من السلاسل. احذف مسودة أو أرشف سلسلة قديمة.');
        }
    }

    protected function title(mixed $value): string
    {
        $title = trim((string) $value);
        $min = (int) config('creator_series.limits.title_min');
        $max = (int) config('creator_series.limits.title_max');

        if (mb_strlen($title) < $min || mb_strlen($title) > $max) {
            throw new CreatorSeriesException("عنوان السلسلة من {$min} إلى {$max} حرفًا.");
        }

        return $title;
    }

    protected function description(mixed $value): ?string
    {
        $text = trim((string) $value);

        if ($text === '') {
            return null;
        }

        if (mb_strlen($text) > (int) config('creator_series.limits.description_max')) {
            throw new CreatorSeriesException('وصف السلسلة أطول من المسموح.');
        }

        return $text;
    }

    /** رابط ثابت لا يتغير بتغيّر العنوان: أصل من العنوان + لاحقة قصيرة عشوائية من ULID (مضمون الفرادة بالفحص). */
    protected function uniqueSlug(string $title): string
    {
        $base = Str::limit(Str::slug($title) ?: 'series', 60, '');
        $base = trim($base, '-') ?: 'series';

        do {
            $slug = $base.'-'.strtolower(substr((string) Str::ulid(), -8));
        } while (CreatorSeries::query()->where('slug', $slug)->exists());

        return $slug;
    }
}
