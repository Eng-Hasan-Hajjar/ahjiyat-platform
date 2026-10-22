<?php

namespace App\Services\Discussions;

use App\Models\DiscussionComment;
use App\Models\DiscussionCommentReport;
use App\Models\DiscussionThread;
use App\Models\User;
use App\Services\Social\BlockService;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * E36 - قراءة النقاش للعرض. **المصدر الوحيد لما يصل إلى Blade**: يحوّل التعليقات إلى مصفوفات عرض بحالة صريحة (live | deleted | hidden) **لا يحمل فيها نصًّا إلا للحيّ**،
 * فلا يمكن لقالب أن يسرّب نص تعليق محذوف أو مخفي حتى لو أخطأ كاتبه (قاعدة 12). الحظر يُطبَّق بدفعة واحدة (قائمة معرّفات المشاهد من BlockService) لا استعلام لكل تعليق.
 *
 * عدد الاستعلامات **ثابت** مهما كثر التعليق (20 جذرًا لا يطلق 20 استعلام مؤلف/إعجاب/حظر/ردود): نقاش، حظر (حيّ مرة واحدة)، جذور (عدّ + صفحة، مع المؤلف)، معاينة الردود
 * باستعلام UNION واحد محدود بـN لكل جذر (لا تحميل آلاف الردود)، عدّ الردود مجمَّعًا، إعجابات المشاهد دفعة، بلاغاته المفتوحة دفعة.
 *
 * ما يُعرض: جذر حيّ؛ أو لحد محايد لجذر محذوف/مخفي **له ردود قابلة للعرض** (يبقى السياق)؛ جذر بلا ردود وغير حيّ يختفي. جذر كاتبه بينه وبين المشاهد حظر يُخفى مع فرعه كله.
 * كاتب التعليق المخفي يرى لحده مع السبب (له وحده)؛ المشرف يرى لحد المخفي ليستعيده؛ الآخرون يرون لحدًا محايدًا إن بقيت ردود.
 * الترتيب: الأحدث (الافتراضي) أو الأقدم فقط (لا ترتيب «الأفضل»: الإعجابات قابلة للتلاعب فلا تصير سلطة). لا كاش (الإخفاء/الحظر/القفل تُقرأ حيّة).
 */
class DiscussionReader
{
    public const SORTS = ['newest', 'oldest'];

    public function __construct(
        protected DiscussionThreadService $threads,
        protected BlockService $blocks,
        protected DiscussionReportService $reports,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forSubject(DiscussionSubject $subject, ?User $viewer, ?string $sort = null, int $page = 1): array
    {
        $sort = in_array($sort, self::SORTS, true) ? $sort : 'newest';
        $per = max(1, (int) config('discussions.page.roots_per_page'));
        $thread = $this->threads->findFor($subject);
        $hidden = $viewer !== null ? $this->blocks->hiddenIds($viewer) : [];
        $ctx = $this->context($viewer, $thread, $subject, $hidden);

        if ($thread === null) {
            $roots = new LengthAwarePaginator([], 0, $per, 1, ['path' => LengthAwarePaginator::resolveCurrentPath(), 'pageName' => 'dpage']);

            return $this->envelope($subject, $thread, $ctx, $sort, $roots, 0);
        }

        $paginator = $this->rootsQuery($thread, $ctx, $hidden)
            ->with('author:id,name,public_id,profile_visibility')
            ->orderBy('discussion_comments.created_at', $sort === 'oldest' ? 'asc' : 'desc')->orderBy('discussion_comments.id', $sort === 'oldest' ? 'asc' : 'desc')
            ->paginate($per, ['discussion_comments.*'], 'dpage', max(1, $page));

        $roots = $paginator->getCollection();
        $replyCounts = $this->replyCounts($roots->pluck('id')->all(), $ctx, $hidden);
        $previews = $this->previews($roots->pluck('id')->all(), $ctx, $hidden);
        $all = $roots->concat($previews->flatten(1));
        $this->decorate($ctx, $all);

        $paginator->setCollection($roots->map(function (DiscussionComment $root) use ($ctx, $replyCounts, $previews) {
            $replies = ($previews->get($root->id) ?? collect())->map(fn (DiscussionComment $r) => $this->present($r, $ctx))->values()->all();
            $row = $this->present($root, $ctx);
            $row['reply_count'] = (int) ($replyCounts[$root->id] ?? 0);
            $row['replies'] = $replies;
            $row['more_replies'] = max(0, $row['reply_count'] - count($replies));

            return $row;
        }));

        return $this->envelope($subject, $thread, $ctx, $sort, $paginator->withQueryString()->fragment('discussion'), (int) $thread->comments_count);
    }

    /**
     * صفحة ردود جذر واحد (مرقَّمة، الأقدم أولًا). الجذر نفسه يُعرض (حيًّا أو لحدًا إن كانت له ردود قابلة للعرض).
     *
     * @return array<string, mixed>|null null = لا شيء يُعرض لهذا المشاهد (الجذر غير قابل للعرض ولا ردود)
     */
    public function repliesPage(DiscussionComment $root, DiscussionThread $thread, DiscussionSubject $subject, ?User $viewer, int $page = 1): ?array
    {
        $hidden = $viewer !== null ? $this->blocks->hiddenIds($viewer) : [];
        $ctx = $this->context($viewer, $thread, $subject, $hidden);
        $per = max(1, (int) config('discussions.page.replies_per_page'));

        $paginator = $this->repliesQuery($root->id, $ctx, $hidden)->with('author:id,name,public_id,profile_visibility')
            ->orderBy('discussion_comments.created_at')->orderBy('discussion_comments.id')->paginate($per, ['discussion_comments.*'], 'page', max(1, $page));

        $rootRenderable = $this->isListable($root, $ctx);

        if (! $rootRenderable && $paginator->total() === 0) {
            return null;
        }

        $root->loadMissing('author:id,name,public_id,profile_visibility');
        $all = collect([$root])->concat($paginator->getCollection());
        $this->decorate($ctx, $all);
        $paginator->setCollection($paginator->getCollection()->map(fn (DiscussionComment $r) => $this->present($r, $ctx)));
        $rootRow = $this->present($root, $ctx);
        $rootRow['reply_count'] = $paginator->total();

        return [
            'root' => $rootRow,
            'replies' => $paginator->withQueryString(),
            'can' => $ctx['can'],
            'locked' => (bool) $thread->is_locked,
            'subject' => $this->subjectRow($subject),
            'moderator' => $ctx['moderator'],
            'minutes' => $ctx['minutes'],
            'max' => min(DiscussionBody::HARD_MAX, (int) config('discussions.body.max')),
        ];
    }

    /**
     * موضع تعليق في صفحات النقاش لهذا المشاهد (للرابط الثابت وللإشعارات)، أو null إن لم يكن **قابلًا للعرض له** (محذوف/مخفي عنه/فرع محظور): لا يُسرَّب شيء عن تعليق غير ظاهر.
     * الجذر: صفحة الجذور. الرد: صفحة جذره، وصفحة الردود إن لم يقع ضمن المعاينة المضمَّنة.
     *
     * @return array{root_page: int, replies_page: ?int, root: string}|null
     */
    public function locate(DiscussionComment $comment, DiscussionThread $thread, ?User $viewer, string $sort = 'newest'): ?array
    {
        $hidden = $viewer !== null ? $this->blocks->hiddenIds($viewer) : [];
        $ctx = $this->context($viewer, $thread, null, $hidden);

        if ($comment->parent_id === null) {
            if (! $this->rootsQuery($thread, $ctx, $hidden)->whereKey($comment->id)->exists()) {
                return null;
            }

            return ['root_page' => $this->rootPage($thread, $comment, $ctx, $hidden, $sort), 'replies_page' => null, 'root' => (string) $comment->public_id];
        }

        $root = DiscussionComment::query()->find($comment->parent_id);

        if ($root === null || ! $this->repliesQuery((int) $root->id, $ctx, $hidden)->whereKey($comment->id)->exists()) {
            return null;
        }

        $before = $this->repliesBefore($comment, $ctx, $hidden);
        $preview = max(0, (int) config('discussions.page.replies_preview'));
        $repliesPage = $before < $preview ? null : intdiv($before, max(1, (int) config('discussions.page.replies_per_page'))) + 1;

        return ['root_page' => $this->rootPage($thread, $root, $ctx, $hidden, $sort), 'replies_page' => $repliesPage, 'root' => (string) $root->public_id];
    }

    /** @param  list<int>  $hidden */
    protected function rootPage(DiscussionThread $thread, DiscussionComment $root, array $ctx, array $hidden, string $sort): int
    {
        $query = $this->rootsQuery($thread, $ctx, $hidden);
        $before = $sort === 'oldest'
            ? $query->where(fn ($w) => $w->where('discussion_comments.created_at', '<', $root->created_at)->orWhere(fn ($x) => $x->where('discussion_comments.created_at', $root->created_at)->where('discussion_comments.id', '<', $root->id)))
            : $query->where(fn ($w) => $w->where('discussion_comments.created_at', '>', $root->created_at)->orWhere(fn ($x) => $x->where('discussion_comments.created_at', $root->created_at)->where('discussion_comments.id', '>', $root->id)));

        return intdiv($before->count(), max(1, (int) config('discussions.page.roots_per_page'))) + 1;
    }

    /**
     * عدد الردود القابلة للعرض قبل هذا الرد ضمن جذره (الأقدم أولًا).
     *
     * @param  list<int>  $hidden
     */
    protected function repliesBefore(DiscussionComment $reply, array $ctx, array $hidden): int
    {
        return $this->repliesQuery((int) $reply->parent_id, $ctx, $hidden)
            ->where(fn ($w) => $w->where('discussion_comments.created_at', '<', $reply->created_at)->orWhere(fn ($x) => $x->where('discussion_comments.created_at', $reply->created_at)->where('discussion_comments.id', '<', $reply->id)))->count();
    }

    /**
     * @param  list<int>  $hidden
     * @return array<string, mixed>
     */
    protected function context(?User $viewer, ?DiscussionThread $thread, ?DiscussionSubject $subject, array $hidden): array
    {
        $locked = $thread !== null && (bool) $thread->is_locked;
        $why = null;

        if ($viewer === null) {
            $why = 'guest';
        } elseif ($viewer->email_verified_at === null) {
            $why = 'unverified';
        } elseif ((bool) $viewer->is_frozen) {
            $why = 'frozen';
        } elseif ($subject !== null && $subject->ownerId !== (int) $viewer->id && in_array($subject->ownerId, $hidden, true)) {
            $why = 'blocked';
        } elseif ($locked) {
            $why = 'locked';
        }

        return [
            'viewer' => $viewer,
            'viewerId' => $viewer?->id,
            'moderator' => $viewer !== null && $viewer->can('discussions.moderate'),
            'locked' => $locked,
            'can' => ['comment' => $why === null, 'why' => $why],
            'liked' => [],
            'reported' => [],
            'minutes' => (int) config('discussions.edit_window_minutes'),
        ];
    }

    /** @param  list<int>  $hidden */
    protected function rootsQuery(DiscussionThread $thread, array $ctx, array $hidden): EloquentBuilder
    {
        return DiscussionComment::query()->where('discussion_comments.discussion_thread_id', $thread->id)->whereNull('discussion_comments.parent_id')
            ->where(function ($q) use ($ctx, $hidden) {
                $q->where(fn ($w) => $this->listable($w, 'discussion_comments', $ctx))
                    ->orWhereExists(function ($e) use ($ctx, $hidden) {
                        $e->selectRaw('1')->from('discussion_comments as r')->whereColumn('r.parent_id', 'discussion_comments.id')
                            ->where(fn ($w) => $this->listable($w, 'r', $ctx));

                        if ($hidden !== []) {
                            $e->whereNotIn('r.user_id', $hidden);
                        }
                    });
            })
            ->when($hidden !== [], fn ($q) => $q->where(fn ($w) => $w->whereNotIn('discussion_comments.user_id', $hidden)->orWhereNull('discussion_comments.user_id')));
    }

    /** @param  list<int>  $hidden */
    protected function repliesQuery(int $rootId, array $ctx, array $hidden): EloquentBuilder
    {
        return DiscussionComment::query()->where('discussion_comments.parent_id', $rootId)
            ->where(fn ($w) => $this->listable($w, 'discussion_comments', $ctx))
            ->when($hidden !== [], fn ($q) => $q->whereNotIn('discussion_comments.user_id', $hidden));
    }

    /** يقبل منشئ استعلام عامًّا (Eloquent/Query) ويقيّد بالقابل للعرض: حيّ، أو مخفي لكاتبه/للمشرف (لحد). المحذوف لا يُعرض أبدًا (اللحد للجذر بالرد فقط). */
    protected function listable($w, string $table, array $ctx): void
    {
        $w->where(fn ($x) => $x->where($table.'.status', DiscussionComment::STATUS_VISIBLE)->whereNull($table.'.deleted_at'));

        if ($ctx['moderator']) {
            $w->orWhere(fn ($x) => $x->where($table.'.status', DiscussionComment::STATUS_HIDDEN)->whereNull($table.'.deleted_at'));
        } elseif ($ctx['viewerId'] !== null) {
            $w->orWhere(fn ($x) => $x->where($table.'.status', DiscussionComment::STATUS_HIDDEN)->whereNull($table.'.deleted_at')->where($table.'.user_id', $ctx['viewerId']));
        }
    }

    /** نسخة PHP من listable (لتعليق محمَّل). */
    protected function isListable(DiscussionComment $c, array $ctx): bool
    {
        if ($c->deleted_at !== null) {
            return false;
        }

        if ($c->status === DiscussionComment::STATUS_VISIBLE) {
            return true;
        }

        return $ctx['moderator'] || ($ctx['viewerId'] !== null && (int) $c->user_id === (int) $ctx['viewerId']);
    }

    /**
     * @param  list<int>  $rootIds
     * @param  list<int>  $hidden
     * @return array<int, int>
     */
    protected function replyCounts(array $rootIds, array $ctx, array $hidden): array
    {
        if ($rootIds === []) {
            return [];
        }

        return DiscussionComment::query()->whereIn('discussion_comments.parent_id', $rootIds)
            ->where(fn ($w) => $this->listable($w, 'discussion_comments', $ctx))
            ->when($hidden !== [], fn ($q) => $q->whereNotIn('discussion_comments.user_id', $hidden))
            ->toBase()->selectRaw('discussion_comments.parent_id as parent_id, count(*) as c')->groupBy('discussion_comments.parent_id')
            ->pluck('c', 'parent_id')->map(fn ($c) => (int) $c)->all();
    }

    /**
     * معاينة الردود: أول N (الأقدم) لكل جذر باستعلام UNION واحد؛ مفتاح النتيجة = معرّف الجذر.
     *
     * @param  list<int>  $rootIds
     * @param  list<int>  $hidden
     * @return \Illuminate\Support\Collection<int, \Illuminate\Support\Collection<int, DiscussionComment>>
     */
    protected function previews(array $rootIds, array $ctx, array $hidden)
    {
        $n = max(0, (int) config('discussions.page.replies_preview'));

        if ($n === 0 || $rootIds === []) {
            return collect();
        }

        $union = null;

        foreach ($rootIds as $rootId) {
            $sub = $this->repliesQuery((int) $rootId, $ctx, $hidden)->select('discussion_comments.*')
                ->orderBy('discussion_comments.created_at')->orderBy('discussion_comments.id')->limit($n);
            $union = $union === null ? $sub : $union->unionAll($sub);
        }

        return $union->with('author:id,name,public_id,profile_visibility')->get()->groupBy('parent_id');
    }

    /** يحمّل إعجابات المشاهد وبلاغاته المفتوحة للتعليقات المعروضة دفعةً واحدة لكل منهما. */
    protected function decorate(array &$ctx, $comments): void
    {
        $ids = $comments->pluck('id')->map(fn ($id) => (int) $id)->all();

        if ($ids === [] || $ctx['viewerId'] === null) {
            return;
        }

        $ctx['liked'] = array_fill_keys(DB::table('discussion_comment_likes')->where('user_id', $ctx['viewerId'])->whereIn('discussion_comment_id', $ids)->pluck('discussion_comment_id')->map(fn ($id) => (int) $id)->all(), true);
        $ctx['reported'] = $this->reports->openFor($ctx['viewer'], $ids);
    }

    /** @return array<string, mixed> */
    protected function present(DiscussionComment $c, array $ctx): array
    {
        $mine = $c->user_id !== null && $ctx['viewerId'] !== null && (int) $c->user_id === (int) $ctx['viewerId'];
        $state = $c->deleted_at !== null ? 'deleted' : ($c->status === DiscussionComment::STATUS_VISIBLE ? 'live' : 'hidden');
        $live = $state === 'live';
        $canAct = $ctx['viewerId'] !== null && $ctx['can']['why'] === null;
        $editable = $mine && $live && ! $ctx['locked'] && $ctx['minutes'] > 0 && $c->created_at !== null && $c->created_at->gte(now()->subMinutes($ctx['minutes']));
        $author = $c->relationLoaded('author') ? $c->author : null;

        return [
            'id' => (string) $c->public_id,
            'state' => $state,
            'is_reply' => $c->parent_id !== null,
            // النص للحيّ فقط: المحذوف أُفرغ والمخفي لا يخرج نصه من هنا أبدًا (يراه المشرف في لوحة الإدارة).
            'body' => $live ? (string) $c->body : null,
            'author' => $this->authorRow($author, $ctx['viewer'], $live),
            'created_at' => $c->created_at,
            'edited' => $live && $c->edited_at !== null,
            'likes' => $live ? (int) $c->likes_count : 0,
            'liked' => $live && isset($ctx['liked'][(int) $c->id]),
            'mine' => $mine,
            'reply_count' => 0,
            'replies' => [],
            'more_replies' => 0,
            'hidden_reason' => $state === 'hidden' && $mine ? (DiscussionCommentReport::REASONS[$c->moderation_reason] ?? null) : null,
            'can_edit' => $editable,
            'can_delete' => $mine && $live,
            'can_reply' => $live && $canAct,
            'can_like' => $live && $canAct && ! $mine && ! $ctx['locked'],
            'can_report' => $live && $canAct && ! $mine,
            'reported' => isset($ctx['reported'][(int) $c->id]),
            'can_hide' => $live && $ctx['moderator'],
            'can_restore' => $state === 'hidden' && $ctx['moderator'],
        ];
    }

    /** هوية المؤلف الآمنة: الاسم المعروض فقط (نمط لوحات الصدارة) + رابط الملف إن حقّ للمشاهد رؤيته. لا بريد ولا معرّف داخلي. اللحود لا تحمل هوية. */
    protected function authorRow(?User $author, ?User $viewer, bool $live): ?array
    {
        if (! $live || $author === null) {
            return null;
        }

        return ['name' => (string) $author->name, 'url' => $author->profileViewableBy($viewer) ? route('players.show', $author) : null];
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @return array<string, mixed>
     */
    protected function envelope(DiscussionSubject $subject, ?DiscussionThread $thread, array $ctx, string $sort, $roots, int $count): array
    {
        return [
            'enabled' => (bool) config('discussions.enabled', true),
            'subject' => $this->subjectRow($subject),
            'sort' => $sort,
            'locked' => $ctx['locked'],
            'count' => $count,
            'roots' => $roots,
            'can' => $ctx['can'],
            'moderator' => $ctx['moderator'],
            'minutes' => $ctx['minutes'],
            'max' => min(DiscussionBody::HARD_MAX, (int) config('discussions.body.max')),
        ];
    }

    /** @return array{type: string, id: string, title: string, url: string} */
    protected function subjectRow(DiscussionSubject $subject): array
    {
        return ['type' => $subject->type, 'id' => $subject->publicId, 'title' => $subject->title, 'url' => $subject->url];
    }
}
