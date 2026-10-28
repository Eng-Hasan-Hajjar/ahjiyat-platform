<?php

namespace App\Services\Discussions;

use App\Models\CreatorPuzzle;
use App\Models\CreatorSeries;
use App\Models\CreatorSeriesSaleOffer;
use App\Models\DiscussionThread;
use App\Models\User;
use App\Models\UserCollection;
use App\Services\Collections\CollectionAccess;
use App\Services\CreatorPremium\CreatorPremiumAccess;
use App\Services\CreatorSeries\CreatorSeriesVisibilityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * E36 - السجل المركزي لمواضيع النقاش وأهليتها: **مصدر الحقيقة الوحيد** لسؤال «هل هذا الكيان موجود وظاهر للمشاهد ومن مالكه وما رابطه العام؟».
 *
 *  - الأنواع قائمة بيضاء مغلقة (DiscussionThread::TYPES): لا اسم صنف PHP ولا جدول من طلب HTTP أبدًا. نوع مجهول = null (404).
 *  - لا قواعد ظهور جديدة هنا: كل نوع يعيد استعمال خدمة ظهوره القائمة (أحجية المجتمع: is_visible؛ السلسلة: CreatorSeriesVisibilityService؛ المجموعة: CollectionAccess).
 *  - المجموعة **العامة فقط** تستضيف نقاشًا: غير المدرجة لا (التعليقات قد تسرّب اكتشافًا لرابط خاص بالمشاركة)، والخاصة لا.
 *  - إخفاء الكيان إشرافيًا (أو تعليق المبدع، أو جعل المجموعة خاصة) يقطع الوصول العام للنقاش فورًا؛ التعليقات المخزَّنة تبقى تاريخيًا وتعود بعودة الكيان.
 *  - official_puzzle غير منفَّذ (الأحجية الرسمية خارج النطاق عمدًا).
 *  - E43: نقاش محتوى مميز (أحجية ضمن سلسلة مقفولة، أو السلسلة المميزة نفسها) يُحلّ فقط للمبدع/المشرف المخوَّل/مالك الوصول (CreatorPremiumAccess، السياسة الوحيدة)؛ لغيرهم null = 404.
 *    هنا النقطة المركزية لكل مسارات النقاش (قراءة/نشر/رد/إعجاب/رابط دائم)، فلا تسريب لنص تعليق قد يحوي الحل.
 */
class DiscussionSubjectRegistry
{
    public function __construct(protected CreatorSeriesVisibilityService $series, protected CollectionAccess $collections) {}

    /** @return list<string> */
    public function types(): array
    {
        return array_keys(DiscussionThread::TYPES);
    }

    public function supports(string $type): bool
    {
        return array_key_exists($type, DiscussionThread::TYPES);
    }

    /** يحلّ الموضوع بمعرّفه العام لهذا المشاهد، أو null (نوع غير مدعوم/غير موجود/غير ظاهر/محظور). */
    public function resolve(string $type, string $publicId, ?User $viewer): ?DiscussionSubject
    {
        if (! $this->supports($type) || ! Str::isUlid($publicId)) {
            return null;
        }

        $publicId = Str::upper($publicId);

        return match ($type) {
            DiscussionThread::TYPE_COMMUNITY_PUZZLE => $this->puzzle($publicId, $viewer),
            DiscussionThread::TYPE_CREATOR_SERIES => $this->seriesBy($publicId, $viewer),
            DiscussionThread::TYPE_PUBLIC_COLLECTION => $this->collection($publicId, $viewer),
            default => null,
        };
    }

    /** يحلّ موضوع نقاش موجود (للإشراف/الإشعارات) بحسب المعرّف الداخلي؛ ظاهر الآن للمشاهد أو null. */
    public function resolveThread(DiscussionThread $thread, ?User $viewer): ?DiscussionSubject
    {
        $publicId = match ($thread->subject_type) {
            DiscussionThread::TYPE_COMMUNITY_PUZZLE => CreatorPuzzle::query()->whereKey($thread->subject_id)->value('public_id'),
            DiscussionThread::TYPE_CREATOR_SERIES => CreatorSeries::query()->whereKey($thread->subject_id)->value('public_id'),
            DiscussionThread::TYPE_PUBLIC_COLLECTION => UserCollection::query()->whereKey($thread->subject_id)->value('public_id'),
            default => null,
        };

        return $publicId === null ? null : $this->resolve((string) $thread->subject_type, (string) $publicId, $viewer);
    }

    /** وصف جاهز من عمل مجتمع **ظاهر محمَّل مسبقًا** (مع creator:id,user_id) بلا استعلام إضافي. المستدعي يضمن الظهور (الصفحة العامة حلّته). */
    public function forWork(CreatorPuzzle $work): DiscussionSubject
    {
        return new DiscussionSubject(
            DiscussionThread::TYPE_COMMUNITY_PUZZLE,
            (int) $work->id,
            (string) $work->public_id,
            $this->creatorUserId($work),
            (string) ($work->published_title ?? ''),
            route('community.puzzles.show', $work->public_id),
        );
    }

    /** وصف جاهز من سلسلة ظاهرة محمَّلة مسبقًا (creator + publishedRevision). */
    public function forSeries(CreatorSeries $series): DiscussionSubject
    {
        return new DiscussionSubject(
            DiscussionThread::TYPE_CREATOR_SERIES,
            (int) $series->id,
            (string) $series->public_id,
            (int) ($series->relationLoaded('creator') && $series->creator !== null ? $series->creator->user_id : \App\Models\CreatorProfile::query()->whereKey($series->creator_profile_id)->value('user_id')),
            (string) ($series->publishedRevision?->title ?? ''),
            route('series.show', $series->public_id),
        );
    }

    /** وصف جاهز من مجموعة **عامة** ظاهرة محمَّلة مسبقًا؛ غير العامة تعيد null (لا نقاش). */
    public function forCollection(UserCollection $collection): ?DiscussionSubject
    {
        if ($collection->visibility !== UserCollection::VISIBILITY_PUBLIC || $collection->is_moderation_hidden) {
            return null;
        }

        return new DiscussionSubject(
            DiscussionThread::TYPE_PUBLIC_COLLECTION,
            (int) $collection->id,
            (string) $collection->public_id,
            (int) $collection->user_id,
            (string) $collection->title,
            route('collections.public.show', $collection->public_id),
        );
    }

    /**
     * وصف موضوعات نقاشات لشاشات الإشراف بدفعة واحدة لكل نوع (لا N+1): thread_id => [title, url (أو null إن لم يعد الكيان عامًا), available].
     * الإشراف لا يرى عنوان مجموعة صارت خاصة/غير مدرجة (خصوصية المالك) ولا يُنتج رابطًا لكيان غير ظاهر.
     *
     * @param  iterable<DiscussionThread>  $threads
     * @return array<int, array{title: string, url: ?string, available: bool}>
     */
    public function describeThreads(iterable $threads): array
    {
        $byType = [];

        foreach ($threads as $thread) {
            $byType[(string) $thread->subject_type][(int) $thread->subject_id][] = (int) $thread->id;
        }

        $out = [];
        $unavailable = ['title' => '— (الكيان غير متاح للعامة)', 'url' => null, 'available' => false];

        foreach ($byType as $type => $subjects) {
            $ids = array_keys($subjects);
            $found = [];

            if ($type === DiscussionThread::TYPE_COMMUNITY_PUZZLE) {
                foreach (CreatorPuzzle::query()->visible()->whereIn('id', $ids)->get(['id', 'public_id', 'published_title']) as $w) {
                    $found[(int) $w->id] = ['title' => (string) $w->published_title, 'url' => route('community.puzzles.show', $w->public_id), 'available' => true];
                }
            } elseif ($type === DiscussionThread::TYPE_CREATOR_SERIES) {
                $rows = $this->series->visibleQuery()->whereIn('creator_series.id', $ids)->with('publishedRevision:id,creator_series_id,title')->get(['creator_series.id', 'creator_series.public_id', 'creator_series.published_revision_id']);

                foreach ($rows as $s) {
                    $found[(int) $s->id] = ['title' => (string) ($s->publishedRevision?->title ?? ''), 'url' => route('series.show', $s->public_id), 'available' => true];
                }
            } elseif ($type === DiscussionThread::TYPE_PUBLIC_COLLECTION) {
                $rows = UserCollection::query()->whereIn('id', $ids)->where('visibility', UserCollection::VISIBILITY_PUBLIC)->where('is_moderation_hidden', false)->get(['id', 'public_id', 'title']);

                foreach ($rows as $c) {
                    $found[(int) $c->id] = ['title' => (string) $c->title, 'url' => route('collections.public.show', $c->public_id), 'available' => true];
                }
            }

            foreach ($subjects as $subjectId => $threadIds) {
                foreach ($threadIds as $threadId) {
                    $out[$threadId] = $found[$subjectId] ?? $unavailable;
                }
            }
        }

        return $out;
    }

    protected function puzzle(string $publicId, ?User $viewer = null): ?DiscussionSubject
    {
        // E43: عمود القفل المشتق مع الاستعلام نفسه (لا استعلام إضافي للأعمال الحرة).
        $work = CreatorPuzzle::query()->visible()->select(['creator_puzzles.id', 'creator_puzzles.public_id', 'creator_puzzles.creator_profile_id', 'creator_puzzles.published_title'])->withPremiumLock()
            ->where('public_id', $publicId)->with('creator:id,user_id')->first();

        if ($work === null) {
            return null;
        }

        return app(CreatorPremiumAccess::class)->decide($viewer, $work, CreatorPremiumAccess::DISCUSS)->allowed ? $this->forWork($work) : null;
    }

    protected function seriesBy(string $publicId, ?User $viewer = null): ?DiscussionSubject
    {
        if (! (bool) config('creator_series.enabled', true)) {
            return null;
        }

        $series = $this->series->visibleQuery()->where('creator_series.public_id', $publicId)
            ->select(['creator_series.id', 'creator_series.public_id', 'creator_series.creator_profile_id', 'creator_series.published_revision_id'])
            ->addSelect(['premium_locked' => DB::table('creator_series_sale_offers as pso')->selectRaw('1')->whereColumn('pso.creator_series_id', 'creator_series.id')->whereIn('pso.status', CreatorSeriesSaleOffer::LOCKING_STATUSES)->limit(1)])
            ->with(['creator:id,user_id', 'publishedRevision:id,creator_series_id,title'])->first();

        if (! $series instanceof CreatorSeries || $series->publishedRevision === null) {
            return null;
        }

        return app(CreatorPremiumAccess::class)->decideSeriesDiscussion($viewer, $series, (bool) ($series->getAttributes()['premium_locked'] ?? false))->allowed ? $this->forSeries($series) : null;
    }

    protected function collection(string $publicId, ?User $viewer): ?DiscussionSubject
    {
        $collection = $this->collections->findViewable($publicId, $viewer);

        return $collection === null ? null : $this->forCollection($collection);
    }

    protected function creatorUserId(CreatorPuzzle $work): int
    {
        if ($work->relationLoaded('creator') && $work->creator !== null) {
            return (int) $work->creator->user_id;
        }

        return (int) \App\Models\CreatorProfile::query()->whereKey($work->creator_profile_id)->value('user_id');
    }
}
