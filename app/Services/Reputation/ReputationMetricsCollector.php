<?php

namespace App\Services\Reputation;

use App\Models\CreatorProfile;
use App\Models\CreatorSeries;
use App\Models\DiscussionComment;
use App\Models\DiscussionThread;
use App\Models\User;
use App\Services\Collections\CollectionAccess;
use App\Services\CreatorSeries\CreatorSeriesVisibilityService;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * E37 - يقرأ **مقاييس المصدر** من سجلات السلطة القائمة (لا من اللقطة ولا من العدّادات المشتقّة) لدفعة مستخدمين باستعلامات تجميع مجمَّعة (لا استعلامات لكل مستخدم):
 *
 *  - المبدع: أعماله الظاهرة فقط (is_visible)؛ تقييمات/مفضّلات/لاعبون/متابعون **بلا تفاعل ذاتي** (صاحب العمل لا يقيّم/يفضّل/يتابع/يلعب لحسابه).
 *    المحتوى المخفي إشرافيًا أو المؤرشف أو غير المنشور أو لمبدع معلَّق/حساب مجمَّد = غير ظاهر = لا يساهم (يعود بالاستعادة بعد إعادة الحساب).
 *  - المنسّق: المجموعات المُدرَجة علنًا الآن فقط (CollectionAccess::listableQuery بلا مشاهد: عامة ∧ غير مخفية ∧ مالك موثَّق غير مجمَّد ∧ ملف عام ∧ ≥ N أعمال ظاهرة).
 *  - المساهم المفيد: إعجابات فريدة على تعليقات **حيّة ظاهرة** (visible ∧ غير محذوفة) على كيانات ظاهرة الآن؛ لا إعجاب ذاتي ولا عدّ تعليقات خامًا.
 *  - البلاغات والتحذيرات وأعلام الاحتيال والدفع والمحفظة والدعم والإحالات والدردشة والمشتريات **لا تُقرأ هنا أبدًا**.
 * لا كتابة ولا ذاكرة مؤقتة. المخرج مصفوفات عددية بسيطة لكل مستخدم.
 *
 * @phpstan-type Metrics array<string, int|bool|string|null>
 */
class ReputationMetricsCollector
{
    public function __construct(protected ReputationSettings $settings) {}

    /**
     * @param  list<int>  $userIds
     * @return array<int, array<string, int|bool|string|null>> مفتاحها معرّف المستخدم (المستخدمون غير الموجودين لا يظهرون)
     */
    public function collect(array $userIds, ?CarbonInterface $now = null): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $userIds), fn (int $id) => $id > 0)));

        if ($ids === []) {
            return [];
        }

        $now ??= now();
        $windowStart = $now->copy()->startOfDay()->subDays($this->settings->windowDays())->toDateTimeString();

        $out = [];

        foreach (DB::table('users')->whereIn('id', $ids)->get(['id', 'is_frozen', 'email_verified_at', 'profile_visibility']) as $user) {
            $out[(int) $user->id] = $this->blank((int) $user->id, (bool) $user->is_frozen, $user->email_verified_at !== null, $user->profile_visibility === User::VISIBILITY_PUBLIC);
        }

        if ($out === []) {
            return [];
        }

        $present = array_keys($out);

        $this->creators($present, $windowStart, $out);
        $this->curators($present, $windowStart, $out);
        $this->helpers($present, $out);

        return $out;
    }

    /** @return array<string, int|bool|string|null> */
    public function blank(int $userId, bool $frozen = false, bool $verified = true, bool $profilePublic = false): array
    {
        return [
            'user_id' => $userId, 'frozen' => $frozen, 'verified' => $verified, 'profile_public' => $profilePublic,
            'creator_profile_id' => null, 'creator_status' => null, 'creator_active' => false,
            'works' => 0, 'rated_works' => 0, 'series' => 0, 'ratings_count' => 0, 'ratings_sum' => 0, 'raters' => 0,
            'favorites' => 0, 'players' => 0, 'followers' => 0, 'first_published_ts' => null, 'last_published_ts' => null,
            'recent_ratings_count' => 0, 'recent_ratings_sum' => 0, 'recent_favorites' => 0, 'recent_players' => 0, 'recent_followers' => 0,
            'collections' => 0, 'savers' => 0, 'saves' => 0, 'items' => 0, 'fresh' => 0,
            'comments' => 0, 'liked_comments' => 0, 'likers' => 0, 'likes' => 0,
        ];
    }

    /** @param list<int> $ids @param array<int, array<string, mixed>> $out */
    protected function creators(array $ids, string $windowStart, array &$out): void
    {
        $profiles = DB::table('creator_profiles')->whereIn('user_id', $ids)->get(['id', 'user_id', 'status']);

        if ($profiles->isEmpty()) {
            return;
        }

        $byProfile = [];
        $minRated = max(1, $this->settings->int('creator.consistency.rated_work_min_ratings', 3));

        foreach ($profiles as $profile) {
            $uid = (int) $profile->user_id;
            $byProfile[(int) $profile->id] = $uid;
            $out[$uid]['creator_profile_id'] = (int) $profile->id;
            $out[$uid]['creator_status'] = (string) $profile->status;
            $out[$uid]['creator_active'] = $profile->status === CreatorProfile::STATUS_APPROVED && ! $out[$uid]['frozen'];
        }

        $profileIds = array_keys($byProfile);

        // أعمال ظاهرة: العدد + أول نشر + آخر نشر.
        foreach (DB::table('creator_puzzles')->whereIn('creator_profile_id', $profileIds)->where('is_visible', true)->groupBy('creator_profile_id')
            ->selectRaw('creator_profile_id as pid, COUNT(*) as c, MIN(first_published_at) as first_at, MAX(published_at) as last_at')->get() as $row) {
            $uid = $byProfile[(int) $row->pid];
            $out[$uid]['works'] = (int) $row->c;
            $out[$uid]['first_published_ts'] = $row->first_at !== null ? strtotime((string) $row->first_at) : null;
            $out[$uid]['last_published_ts'] = $row->last_at !== null ? strtotime((string) $row->last_at) : null;
        }

        // تقييمات (بلا ذاتي) مجمَّعة لكل عمل: مجموع/عدد كلي + نافذة حديثة. أعمال ≥ min تقييمًا تُعدّ «أعمالًا بعيّنة فعلية».
        foreach ($this->visibleWorksJoin('community_puzzle_ratings as r', 'r.creator_puzzle_id', $profileIds)
            ->where(fn ($q) => $q->whereNull('r.user_id')->orWhereColumn('r.user_id', '!=', 'p.user_id'))
            ->groupBy('w.id', 'w.creator_profile_id')
            ->selectRaw('w.creator_profile_id as pid, w.id as wid, COUNT(*) as c, SUM(r.rating) as s, SUM(CASE WHEN r.created_at >= ? THEN 1 ELSE 0 END) as rc, SUM(CASE WHEN r.created_at >= ? THEN r.rating ELSE 0 END) as rs', [$windowStart, $windowStart])
            ->get() as $row) {
            $uid = $byProfile[(int) $row->pid];
            $out[$uid]['ratings_count'] += (int) $row->c;
            $out[$uid]['ratings_sum'] += (int) $row->s;
            $out[$uid]['recent_ratings_count'] += (int) $row->rc;
            $out[$uid]['recent_ratings_sum'] += (int) $row->rs;
            $out[$uid]['rated_works'] += (int) $row->c >= $minRated ? 1 : 0;
        }

        // مقيِّمون مميَّزون عبر كل أعماله (مقيِّم واحد لعشرة أعمال = مقيِّم واحد).
        foreach ($this->visibleWorksJoin('community_puzzle_ratings as r', 'r.creator_puzzle_id', $profileIds)
            ->where(fn ($q) => $q->whereNull('r.user_id')->orWhereColumn('r.user_id', '!=', 'p.user_id'))
            ->groupBy('w.creator_profile_id')->selectRaw('w.creator_profile_id as pid, COUNT(DISTINCT r.user_id) as raters')->get() as $row) {
            $out[$byProfile[(int) $row->pid]]['raters'] = (int) $row->raters;
        }

        // مفضّلات فريدة (بلا ذاتي).
        foreach ($this->visibleWorksJoin('community_puzzle_favorites as f', 'f.creator_puzzle_id', $profileIds)
            ->whereColumn('f.user_id', '!=', 'p.user_id')
            ->groupBy('w.creator_profile_id')
            ->selectRaw('w.creator_profile_id as pid, COUNT(*) as c, SUM(CASE WHEN f.created_at >= ? THEN 1 ELSE 0 END) as rc', [$windowStart])->get() as $row) {
            $uid = $byProfile[(int) $row->pid];
            $out[$uid]['favorites'] = (int) $row->c;
            $out[$uid]['recent_favorites'] = (int) $row->rc;
        }

        // لاعبون فريدون عبر كل أعماله (لا محاولات خام؛ لا لعب ذاتي). «الحديث» = لاعبون نشطون ضمن النافذة (لا يُكرَّر لاعب بكثرة محاولاته).
        foreach ($this->visibleWorksJoin('community_puzzle_attempts as a', 'a.creator_puzzle_id', $profileIds)
            ->whereColumn('a.user_id', '!=', 'p.user_id')
            ->groupBy('w.creator_profile_id')
            ->selectRaw('w.creator_profile_id as pid, COUNT(DISTINCT a.user_id) as c, COUNT(DISTINCT CASE WHEN a.created_at >= ? THEN a.user_id END) as rc', [$windowStart])->get() as $row) {
            $uid = $byProfile[(int) $row->pid];
            $out[$uid]['players'] = (int) $row->c;
            $out[$uid]['recent_players'] = (int) $row->rc;
        }

        // متابعون فريدون (بلا ذاتي). المتابعة لا تتطلب أعمالًا ظاهرة لكن الوصول يُسقَّف بالصيغة.
        foreach (DB::table('creator_follows as f')->join('creator_profiles as p', 'p.id', '=', 'f.creator_profile_id')
            ->whereIn('f.creator_profile_id', $profileIds)->whereColumn('f.user_id', '!=', 'p.user_id')
            ->groupBy('f.creator_profile_id')
            ->selectRaw('f.creator_profile_id as pid, COUNT(*) as c, SUM(CASE WHEN f.created_at >= ? THEN 1 ELSE 0 END) as rc', [$windowStart])->get() as $row) {
            $uid = $byProfile[(int) $row->pid];
            $out[$uid]['followers'] = (int) $row->c;
            $out[$uid]['recent_followers'] = (int) $row->rc;
        }

        // سلاسل ظاهرة (قاعدة الظهور الحيّة نفسها: عنصر مخفي/مبدع معلَّق/مؤرشفة = غير موجودة). تعطيل الميزة = صفر.
        if ((bool) config('creator_series.enabled', true)) {
            foreach (app(CreatorSeriesVisibilityService::class)->scopeVisible(CreatorSeries::query())
                ->whereIn('creator_series.creator_profile_id', $profileIds)->toBase()->groupBy('creator_series.creator_profile_id')
                ->selectRaw('creator_series.creator_profile_id as pid, COUNT(*) as c')->get() as $row) {
                $out[$byProfile[(int) $row->pid]]['series'] = (int) $row->c;
            }
        }
    }

    /** أساس ضمّ: جدول تفاعل ↔ عمل ظاهر ↔ ملف المبدع (لاستبعاد الذاتي وتجميع كل مبدع). @param list<int> $profileIds */
    protected function visibleWorksJoin(string $table, string $workColumn, array $profileIds): Builder
    {
        return DB::table($table)
            ->join('creator_puzzles as w', 'w.id', '=', $workColumn)
            ->join('creator_profiles as p', 'p.id', '=', 'w.creator_profile_id')
            ->whereIn('w.creator_profile_id', $profileIds)
            ->where('w.is_visible', true);
    }

    /** @param list<int> $ids @param array<int, array<string, mixed>> $out */
    protected function curators(array $ids, string $windowStart, array &$out): void
    {
        $min = max(1, $this->settings->int('curator.min_visible_items', 3));
        $fresh = now()->subDays(max(1, $this->settings->int('curator.fresh_days', 90)))->toDateTimeString();

        $eligible = fn () => app(CollectionAccess::class)->listableQuery(null, $min)
            ->whereIn('user_collections.user_id', $ids)
            ->select('user_collections.id', 'user_collections.user_id as owner_id', 'user_collections.updated_at')
            ->toBase();

        foreach (DB::query()->fromSub($eligible(), 'ec')->groupBy('ec.owner_id')
            ->selectRaw('ec.owner_id as uid, COUNT(*) as c, SUM(CASE WHEN ec.updated_at >= ? THEN 1 ELSE 0 END) as fresh', [$fresh])->get() as $row) {
            $out[(int) $row->uid]['collections'] = (int) $row->c;
            $out[(int) $row->uid]['fresh'] = (int) $row->fresh;
        }

        // حافظ مجمَّد لا يُحتسب (دفاع ضد مزارع الحسابات؛ التجميد قرار إشرافي قائم وليس إشارة سمعة).
        foreach (DB::table('user_collection_saves as s')->joinSub($eligible(), 'ec', 'ec.id', '=', 's.user_collection_id')
            ->join('users as sv', 'sv.id', '=', 's.user_id')->where('sv.is_frozen', false)
            ->whereColumn('s.user_id', '!=', 'ec.owner_id')->groupBy('ec.owner_id')
            ->selectRaw('ec.owner_id as uid, COUNT(*) as saves, COUNT(DISTINCT s.user_id) as savers')->get() as $row) {
            $out[(int) $row->uid]['saves'] = (int) $row->saves;
            $out[(int) $row->uid]['savers'] = (int) $row->savers;
        }

        foreach (DB::table('user_collection_items as i')->joinSub($eligible(), 'ec', 'ec.id', '=', 'i.user_collection_id')
            ->join('creator_puzzles as vw', 'vw.id', '=', 'i.creator_puzzle_id')->where('vw.is_visible', true)
            ->groupBy('ec.owner_id')->selectRaw('ec.owner_id as uid, COUNT(*) as items')->get() as $row) {
            $out[(int) $row->uid]['items'] = (int) $row->items;
        }
    }

    /** @param list<int> $ids @param array<int, array<string, mixed>> $out */
    protected function helpers(array $ids, array &$out): void
    {
        $base = $this->liveVisibleComments($ids);

        foreach ((clone $base)->groupBy('c.user_id')->selectRaw('c.user_id as uid, COUNT(*) as n')->get() as $row) {
            $out[(int) $row->uid]['comments'] = (int) $row->n;
        }

        // معجِب مجمَّد لا يُحتسب؛ لا إعجاب ذاتي.
        foreach ((clone $base)->join('discussion_comment_likes as l', 'l.discussion_comment_id', '=', 'c.id')
            ->join('users as lk', 'lk.id', '=', 'l.user_id')->where('lk.is_frozen', false)
            ->whereColumn('l.user_id', '!=', 'c.user_id')->groupBy('c.user_id')
            ->selectRaw('c.user_id as uid, COUNT(DISTINCT l.user_id) as likers, COUNT(DISTINCT c.id) as liked, COUNT(l.id) as likes')->get() as $row) {
            $out[(int) $row->uid]['likers'] = (int) $row->likers;
            $out[(int) $row->uid]['liked_comments'] = (int) $row->liked;
            $out[(int) $row->uid]['likes'] = (int) $row->likes;
        }
    }

    /**
     * تعليقات حيّة (visible ∧ غير محذوفة) لكتّاب محدَّدين على كيان **ظاهر الآن** (قاعدة ظهور كل نوع موضوع من خدمته القائمة): تعليق على أحجية مخفية أو سلسلة مخفية
     * أو مجموعة غير عامة/مخفية لا يساهم (يعود بعودة الكيان).
     *
     * @param  list<int>  $ids
     */
    protected function liveVisibleComments(array $ids): Builder
    {
        $series = app(CreatorSeriesVisibilityService::class);
        $collections = app(CollectionAccess::class);

        return DB::table('discussion_comments as c')
            ->join('discussion_threads as t', 't.id', '=', 'c.discussion_thread_id')
            ->whereIn('c.user_id', $ids)
            ->where('c.status', DiscussionComment::STATUS_VISIBLE)
            ->whereNull('c.deleted_at')
            ->where(function (Builder $any) use ($series, $collections) {
                $any->where(function (Builder $q) {
                    $q->where('t.subject_type', DiscussionThread::TYPE_COMMUNITY_PUZZLE)
                        ->whereExists(fn (Builder $e) => $e->selectRaw('1')->from('creator_puzzles as sw')->whereColumn('sw.id', 't.subject_id')->where('sw.is_visible', true));
                })->orWhere(function (Builder $q) use ($series) {
                    $q->where('t.subject_type', DiscussionThread::TYPE_CREATOR_SERIES)
                        ->whereExists($series->scopeVisible(CreatorSeries::query())->whereColumn('creator_series.id', 't.subject_id')->selectRaw('1')->toBase());
                })->orWhere(function (Builder $q) use ($collections) {
                    $q->where('t.subject_type', DiscussionThread::TYPE_PUBLIC_COLLECTION)
                        ->whereExists($collections->listableQuery(null, 1)->whereColumn('user_collections.id', 't.subject_id')->selectRaw('1')->toBase());
                });
            });
    }
}
