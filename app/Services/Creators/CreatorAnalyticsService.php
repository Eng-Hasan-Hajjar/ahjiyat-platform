<?php

namespace App\Services\Creators;

use App\Models\CommunityPuzzleAttempt;
use App\Models\CommunityPuzzleReport;
use App\Models\CreatorProfile;
use App\Models\CreatorPuzzle;
use App\Models\CreatorPuzzleRevision;
use App\Services\Engagement\CommunityQualityService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * E28 - تحليلات المبدع (لوحته) + ملخص إداري متواضع. لا أرباح ولا محفظة ولا دفع ولا مرتبة.
 * بلا COUNT لكل بطاقة: المبدع محدود بسقف أعمال، فاستعلامان مجمَّعان فقط (إجماليات + نشاط حديث لكل عمل) على فهرس (creator_puzzle_id, user_id)، ومخزَّنان قصيرًا.
 * لا هوية لاعبين: أرقام مجمَّعة فقط (خصوصية).
 *
 * E29: المتابعون/المفضلة المستلمة/عدد التقييمات/المتوسط/أعلى عمل تقييمًا تُضاف **لنفس الحمولة المخزَّنة** (TTL نفسه، بلا كاش ثانٍ)، من عدّادات الأعمال الظاهرة
 * (استعلام واحد محدود بسقف أعمال المبدع). لا أسماء متابعين ولا مقيِّمين ولا محافظ؛ المتوسط يُخفى تحت الحدّ الأدنى للعيّنة.
 */
class CreatorAnalyticsService
{
    public function __construct(protected CommunityQualityService $quality) {}

    /** @return array{published:int, players:int, attempts:int, completion_rate:int|null, recent_attempts:int, recent_days:int, per_work: array<int, array{recent:int}>, followers:int, favorites_received:int, ratings_count:int, average_rating:float|null, top_rated_work: array{public_id:string, title:string, average:float, count:int}|null} */
    public function forCreator(CreatorProfile $profile): array
    {
        return Cache::remember('creators.analytics.'.$profile->id, now()->addSeconds((int) config('creators.analytics.cache_ttl')), function () use ($profile) {
            $days = (int) config('creators.analytics.recent_days');

            // استعلام واحد لأعمال المبدع (محدودة بسقف CREATOR_MAX_WORKS) يغذّي: المعرّفات، عدد المنشور، وعدّادات التفاعل للظاهر منها. (E28 كان استعلامين لأول اثنين.)
            $works = CreatorPuzzle::query()->where('creator_profile_id', $profile->id)
                ->get(['id', 'public_id', 'published_title', 'published_puzzle_id', 'is_visible', 'ratings_count', 'ratings_sum', 'favorites_count']);
            $workIds = $works->pluck('id')->all();
            $published = $works->whereNotNull('published_puzzle_id')->count();

            $engagement = $this->engagementFor($profile, $works->where('is_visible', true));

            if ($workIds === []) {
                return ['published' => 0, 'players' => 0, 'attempts' => 0, 'completion_rate' => null, 'recent_attempts' => 0, 'recent_days' => $days, 'per_work' => []] + $engagement;
            }

            $totals = CommunityPuzzleAttempt::query()->whereIn('creator_puzzle_id', $workIds)
                ->selectRaw('COUNT(*) as attempts, COUNT(DISTINCT user_id) as players, COUNT(DISTINCT CASE WHEN is_correct THEN user_id END) as solvers')
                ->first();

            $recent = CommunityPuzzleAttempt::query()->whereIn('creator_puzzle_id', $workIds)->where('created_at', '>=', now()->subDays($days))
                ->selectRaw('creator_puzzle_id, COUNT(*) as recent')->groupBy('creator_puzzle_id')->pluck('recent', 'creator_puzzle_id');

            $players = (int) ($totals->players ?? 0);

            return [
                'published' => $published,
                'players' => $players,
                'attempts' => (int) ($totals->attempts ?? 0),
                'completion_rate' => $players > 0 ? (int) round(((int) $totals->solvers / $players) * 100) : null,
                'recent_attempts' => (int) $recent->sum(),
                'recent_days' => $days,
                'per_work' => $recent->map(fn ($n) => ['recent' => (int) $n])->all(),
            ] + $engagement;
        });
    }

    /**
     * عدّادات التفاعل على أعمال المبدع **الظاهرة** (المخفي لا يدخل أرقامًا حتى يُستعاد)، من الأعمال المحمَّلة أصلًا (بلا استعلام إضافي).
     *
     * @return array{followers:int, favorites_received:int, ratings_count:int, average_rating:float|null, top_rated_work: array{public_id:string, title:string, average:float, count:int}|null}
     */
    protected function engagementFor(CreatorProfile $profile, \Illuminate\Support\Collection $works): array
    {
        $count = (int) $works->sum('ratings_count');
        $sum = (int) $works->sum('ratings_sum');

        $top = $works->filter(fn ($w) => $this->quality->eligible((int) $w->ratings_count))
            ->sortByDesc(fn ($w) => [$this->quality->score((int) $w->ratings_count, (int) $w->ratings_sum), (int) $w->ratings_count, (int) $w->id])
            ->first();

        return [
            'followers' => (int) $profile->followers_count,
            'favorites_received' => (int) $works->sum('favorites_count'),
            'ratings_count' => $count,
            'average_rating' => $this->quality->displayAverage($count, $sum),
            'top_rated_work' => $top === null ? null : [
                'public_id' => (string) $top->public_id,
                'title' => (string) $top->published_title,
                'average' => round(((int) $top->ratings_sum) / max(1, (int) $top->ratings_count), 1),
                'count' => (int) $top->ratings_count,
            ],
        ];
    }

    /** ملخص إداري صغير مخزَّن دقيقة (مبدعون/طلبات/مراجعات/منشورات/لعب/بلاغات). */
    public function adminSummary(): array
    {
        return Cache::remember('creators.admin.summary', now()->addSeconds(60), fn () => [
            'approved_creators' => CreatorProfile::query()->where('status', CreatorProfile::STATUS_APPROVED)->count(),
            'pending_applications' => CreatorProfile::query()->where('status', CreatorProfile::STATUS_PENDING)->count(),
            'pending_submissions' => CreatorPuzzleRevision::query()->where('status', CreatorPuzzleRevision::STATUS_PENDING_REVIEW)->count(),
            'published_puzzles' => CreatorPuzzle::query()->where('is_visible', true)->count(),
            'community_plays' => CommunityPuzzleAttempt::query()->count(),
            'open_reports' => CommunityPuzzleReport::query()->where('status', CommunityPuzzleReport::STATUS_OPEN)->count(),
        ] + $this->engagementSummary());
    }

    /**
     * E29: ملخص التفاعل للإدارة (مخزَّن مع الملخص). المجاميع من العدّادات المشتقّة (تُجمع على آلاف الصفوف لا ملايينها)، والمجموعات COUNT واحد.
     * الأعلى تقييمًا من اللقطة القصيرة نفسها (معرّفات+درجات) مع إعادة فحص الظهور؛ الأكثر متابعة من المبدعين المعتمدين. لا هوية لاعبين.
     */
    protected function engagementSummary(): array
    {
        $topIds = array_slice(array_keys($this->quality->topRated()), 0, 5);
        $top = $topIds === [] ? collect() : CreatorPuzzle::query()->visible()->whereIn('id', $topIds)->get(['id', 'public_id', 'published_title', 'ratings_count', 'ratings_sum'])->keyBy('id');

        $mostFollowed = CreatorProfile::query()->where('creator_profiles.status', CreatorProfile::STATUS_APPROVED)->where('creator_profiles.followers_count', '>', 0)
            ->join('users', 'users.id', '=', 'creator_profiles.user_id')
            ->orderByDesc('creator_profiles.followers_count')->orderBy('creator_profiles.id')->limit(5)
            ->get(['creator_profiles.id', 'creator_profiles.slug', 'creator_profiles.followers_count', 'users.name']);

        return [
            'total_follows' => (int) CreatorProfile::query()->sum('followers_count'),
            'total_ratings' => (int) CreatorPuzzle::query()->sum('ratings_count'),
            'total_favorites' => (int) CreatorPuzzle::query()->sum('favorites_count'),
            'total_collections' => (int) DB::table('user_collections')->count(),
            'top_rated' => collect($topIds)->filter(fn ($id) => $top->has($id))->map(fn ($id) => [
                'public_id' => (string) $top[$id]->public_id,
                'title' => (string) $top[$id]->published_title,
                'count' => (int) $top[$id]->ratings_count,
                'average' => round(((int) $top[$id]->ratings_sum) / max(1, (int) $top[$id]->ratings_count), 1),
            ])->values()->all(),
            'most_followed' => $mostFollowed->map(fn ($c) => ['slug' => (string) $c->slug, 'name' => (string) $c->name, 'followers' => (int) $c->followers_count])->all(),
        ];
    }
}
