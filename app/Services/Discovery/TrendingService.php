<?php

namespace App\Services\Discovery;

use App\Models\CompetitiveEvent;
use App\Models\Puzzle;
use App\Models\Team;
use App\Support\CachedSnapshot;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * E27-C: الرائج بإشارات **عامة مجمَّعة** فقط (لا هوية، لا تفضيل شخصي). كلها قراءة؛ لا تغيّر نقاطًا ولا XP ولا مكافآت ولا أهلية ولا احتمالات ولا نتيجة. لا مال ولا رعاية ولا ترتيب مدفوع.
 *
 * أحجيات (الإشارة الأساسية) خلال نافذة (افتراضيًا 7 أيام):
 *   score = w1·(لاعبون مميَّزون آخر يوم) + w3·(… آخر 3 أيام) + w7·(… كل النافذة) + ws·(مميَّزون حلّوها)   [الأوزان بالإعدادات: 4/2/1 و2]
 *   - نعدّ **لاعبين مميَّزين لا محاولات**: لاعب واحد يكرّر المحاولة آلاف المرات لا يرفع الأحجية فوق لاعب واحد.
 *   - فقط محاولات مستقلة (context_type NULL) من حسابات موثَّقة وغير مجمَّدة، على أحجيات فعّالة. حدّ أدنى من اللاعبين المميَّزين (افتراضيًا 2).
 *   - الأقدمية مدمجة بالأوزان التراكمية (اليوم الأخير يُحتسب في الشرائح الثلاث)، فتتراجع الأحجية بصمت حين يتوقف اللعب.
 *   - تعادل الدرجات يُكسَر بالمعرّف الأكبر (قرار قاطع، حتمي).
 * الكاش (CachedSnapshot، TTL افتراضي 5 دقائق) يحمل **المعرّفات والدرجات فقط** — لا أسماء ولا بيانات لاعبين ولا شيء يخص مشاهدًا بعينه؛ وتُعاد قراءة الأحجيات من القاعدة مع شرط الظهور في كل طلب،
 * فسحب أحجية (is_active=false) يظهر فورًا ولو بقيت بالكاش.
 *
 * بدون فهرس جديد على أسخن جدول (puzzle_attempts): بداية النافذة تُحدَّد بالبحث الثنائي على المفتاح الأساسي (المعرّف يزداد مع الزمن) داخل الحساب المخزَّن مؤقتًا، فيمسح الاستعلام صفوف النافذة فقط بمدى PK.
 */
class TrendingService
{
    /** @return Collection<int, Puzzle> مرتبة بالدرجة؛ كل عنصر يحمل trending_players. */
    public function puzzles(?int $limit = null): Collection
    {
        $limit ??= (int) config('search.trending.limit', 8);
        $scores = $this->puzzleScores();

        if ($scores === []) {
            return collect();
        }

        $models = Puzzle::query()
            ->select('puzzles.id', 'puzzles.title', 'puzzles.difficulty', 'puzzles.puzzle_category_id', 'puzzles.gem_reward')
            ->with('category:id,name')
            ->whereIn('puzzles.id', array_keys($scores))
            ->where('puzzles.is_active', true)
            ->get()
            ->keyBy('id');

        return collect($scores)
            ->filter(fn (array $row, int $id) => $models->has($id))
            ->take($limit)
            ->map(function (array $row, int $id) use ($models) {
                $puzzle = $models->get($id);
                $puzzle->setAttribute('trending_players', $row['players']);
                $puzzle->setAttribute('trending_score', $row['score']);

                return $puzzle;
            })
            ->values();
    }

    /** @return Collection<int, CompetitiveEvent> منافسات عامة لم تنتهِ، بعدد مسجَّلين (حسابات موثَّقة أصلًا) ≥ الحدّ الأدنى. */
    public function competitions(?int $limit = null): Collection
    {
        return CompetitiveEvent::query()
            ->select('competitive_events.id', 'competitive_events.slug', 'competitive_events.title', 'competitive_events.status', 'competitive_events.starts_at', 'competitive_events.ends_at', 'competitive_events.participants_count')
            ->public()
            ->where('competitive_events.ends_at', '>', now())
            ->where('competitive_events.participants_count', '>=', max(1, (int) config('search.trending.min_unique_players', 2)))
            ->orderByDesc('competitive_events.participants_count')->orderBy('competitive_events.starts_at')->orderByDesc('competitive_events.id')
            ->limit($limit ?? (int) config('search.trending.limit', 8))
            ->get();
    }

    /** @return Collection<int, Team> فرق الدليل العام الأكثر انضمامًا جديدًا في النافذة. */
    public function teams(?int $limit = null): Collection
    {
        $scores = CachedSnapshot::remember('search.trending.teams.v1', $this->ttl(), fn () => $this->computeTeamScores());

        if ($scores === []) {
            return collect();
        }

        $models = Team::query()->select('teams.id', 'teams.slug', 'teams.name', 'teams.members_count')->directory()->whereIn('teams.id', array_keys($scores))->get()->keyBy('id');

        return collect($scores)
            ->filter(fn (int $joined, int $id) => $models->has($id))
            ->take($limit ?? (int) config('search.trending.limit', 8))
            ->map(function (int $joined, int $id) use ($models) {
                $team = $models->get($id);
                $team->setAttribute('recent_joins', $joined);

                return $team;
            })
            ->values();
    }

    /**
     * @return array<int, array{score: int, players: int}> معرّف الأحجية ← الدرجة وعدد اللاعبين المميَّزين بالنافذة (الأعلى أولًا).
     */
    public function puzzleScores(): array
    {
        return CachedSnapshot::remember('search.trending.puzzles.v1', $this->ttl(), fn () => $this->computePuzzleScores());
    }

    public function forgetSnapshots(): void
    {
        \Illuminate\Support\Facades\Cache::forget('search.trending.puzzles.v1');
        \Illuminate\Support\Facades\Cache::forget('search.trending.teams.v1');
    }

    protected function ttl(): int
    {
        return (int) config('search.trending.cache_ttl', 300);
    }

    protected function windowStart(): \Illuminate\Support\Carbon
    {
        return now()->subDays(max(1, (int) config('search.trending.window_days', 7)));
    }

    /** @return array<int, array{score: int, players: int}> */
    protected function computePuzzleScores(): array
    {
        $start = $this->windowStart();
        $firstId = $this->firstAttemptIdSince($start);

        if ($firstId === null) {
            return [];
        }

        $weights = (array) config('search.trending.recency_weights', []);
        $w1 = (int) ($weights['d1'] ?? 4);
        $w3 = (int) ($weights['d3'] ?? 2);
        $w7 = (int) ($weights['d7'] ?? 1);
        $ws = (int) config('search.trending.solver_weight', 2);
        $min = max(1, (int) config('search.trending.min_unique_players', 2));

        $rows = DB::table('puzzle_attempts as a')
            ->join('puzzles as p', 'p.id', '=', 'a.puzzle_id')
            ->join('users as u', 'u.id', '=', 'a.user_id')
            ->where('a.id', '>=', $firstId)
            ->where('a.created_at', '>=', $start)
            ->whereNull('a.context_type')
            ->where('p.is_active', true)
            ->where('u.is_frozen', false)
            ->whereNotNull('u.email_verified_at')
            ->groupBy('a.puzzle_id')
            ->selectRaw('a.puzzle_id as puzzle_id, COUNT(DISTINCT a.user_id) as players, COUNT(DISTINCT CASE WHEN a.created_at >= ? THEN a.user_id END) as players_3d, COUNT(DISTINCT CASE WHEN a.created_at >= ? THEN a.user_id END) as players_1d, COUNT(DISTINCT CASE WHEN a.is_correct THEN a.user_id END) as solvers', [now()->subDays(3), now()->subDay()])
            ->havingRaw('COUNT(DISTINCT a.user_id) >= ?', [$min])
            ->get();

        $scored = $rows->map(fn ($r) => [
            'id' => (int) $r->puzzle_id,
            'players' => (int) $r->players,
            'score' => $w1 * (int) $r->players_1d + $w3 * (int) $r->players_3d + $w7 * (int) $r->players + $ws * (int) $r->solvers,
        ])->sort(fn (array $a, array $b) => [$b['score'], $b['id']] <=> [$a['score'], $a['id']])->take((int) config('search.trending.pool', 40));

        return $scored->mapWithKeys(fn (array $r) => [$r['id'] => ['score' => $r['score'], 'players' => $r['players']]])->all();
    }

    /** @return array<int, int> */
    protected function computeTeamScores(): array
    {
        $min = max(1, (int) config('search.trending.min_unique_players', 2));

        return DB::table('team_memberships as m')
            ->join('teams as t', 't.id', '=', 'm.team_id')
            ->where('m.joined_at', '>=', $this->windowStart())
            ->where('t.is_active', true)
            ->where('t.visibility', Team::VISIBILITY_PUBLIC)
            ->groupBy('m.team_id')
            ->selectRaw('m.team_id as team_id, COUNT(*) as joined')
            ->havingRaw('COUNT(*) >= ?', [$min])
            ->orderByDesc('joined')->orderByDesc('team_id')
            ->limit((int) config('search.trending.pool', 40))
            ->get()
            ->mapWithKeys(fn ($r) => [(int) $r->team_id => (int) $r->joined])
            ->all();
    }

    /**
     * أصغر معرّف محاولة created_at >= $since، بالبحث الثنائي على المفتاح الأساسي (يفترض تزايد المعرّف مع الزمن، وهو الواقع بالإنتاج؛ والاستعلام الرئيسي
     * يرشّح created_at أيضًا فلا تدخل صفوف قديمة). null = لا محاولات بالنافذة. حدّ الخطوات بالإعدادات.
     */
    protected function firstAttemptIdSince(\Illuminate\Support\Carbon $since): ?int
    {
        $table = DB::table('puzzle_attempts');
        $low = (int) (clone $table)->min('id');
        $high = (int) (clone $table)->max('id');

        if ($high === 0) {
            return null;
        }

        $last = (clone $table)->where('id', $high)->value('created_at');

        if ($last === null || \Illuminate\Support\Carbon::parse($last)->lt($since)) {
            return null;
        }

        for ($steps = (int) config('search.trending.binary_search_steps', 40); $low < $high && $steps > 0; $steps--) {
            $mid = intdiv($low + $high, 2);
            $row = (clone $table)->where('id', '>=', $mid)->orderBy('id')->first(['id', 'created_at']);

            if ($row === null || (int) $row->id >= $high) {
                $high = $mid;

                continue;
            }

            if (\Illuminate\Support\Carbon::parse($row->created_at)->lt($since)) {
                $low = (int) $row->id + 1;
            } else {
                $high = (int) $row->id;
            }
        }

        return $low;
    }
}
