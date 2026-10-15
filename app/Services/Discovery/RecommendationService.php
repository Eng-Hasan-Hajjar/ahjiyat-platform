<?php

namespace App\Services\Discovery;

use App\Models\Campaign;
use App\Models\CompetitiveEvent;
use App\Models\Puzzle;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * E27-D: توصيات شخصية **بخصوصية أولًا**، حتمية، بلا ذكاء اصطناعي ولا بحث متجهي ولا مصادر خارجية، وبلا أي أثر على نقاط/XP/مكافآت/أهلية/احتمالات/نتيجة.
 *
 * إشارات (كلها من **بيانات المستخدم نفسه** + مجاميع عامة لا تحدّد أحدًا):
 *  - تاريخ محاولاته المستقلة (context_type NULL): الأحجيات التي لعبها/حلّها/استنفد محاولاتها، فيُشتقّ منها: تصنيفات يحبها (حلّ=2، محاولة=1، ×1 لآخر 30 يومًا و×0.5 لما قبله)
 *    وصعوبة ملائمة (الأعلى حلًّا؛ المتجاورة الأعلى تُرجَّح أقل).
 *  - شعبية الأحجية العامة (الرائج المخزَّن: TrendingService) وحداثتها (آخر N يوم).
 *  - لا إشارات من الأصدقاء ولا من الفرق ولا من الدردشة ولا من الملفات الخاصة (قرار خصوصية: «لأن صديقك…» تكشف نشاط آخرين). لا بيانات شخص آخر تدخل حساب أي مستخدم.
 * ما يُستبعد دائمًا: ما حلّه سابقًا، وما استنفد محاولاته (لا يمكنه لعبه ثانيةً)، وغير الفعّال.
 * ترتيب نهائي: درجة = Σ وزن·إشارة مُطبَّعة، ثم جزء استكشاف (افتراضيًا 25٪) من تصنيفات لم يجرّبها، يُرتَّب بتجزئة حتمية (user_id|اليوم|puzzle_id): ثابتة طوال اليوم وتتبدّل غدًا. تعادل أخير بالمعرّف الأكبر.
 * لا كاش لكل مستخدم عمدًا (الحداثة بعد الحلّ، وصفر خطر خلط بين حسابين). الكاش الوحيد المستعمل هو الرائج العام (معرّفات ودرجات فقط).
 *
 * @phpstan-type Reco array{puzzle: Puzzle, reason: string}
 */
class RecommendationService
{
    public function __construct(protected TrendingService $trending) {}

    /**
     * @return array{
     *   puzzles: list<array{puzzle: Puzzle, reason: string}>,
     *   continue_puzzles: Collection<int, Puzzle>,
     *   campaigns: Collection<int, Campaign>,
     *   continue_campaigns: Collection<int, Campaign>,
     *   competitions: Collection<int, CompetitiveEvent>,
     *   teams: Collection<int, Team>,
     *   has_history: bool
     * }
     */
    public function forUser(User $user): array
    {
        $history = $this->history($user);
        $puzzles = $this->puzzles($user, $history);
        [$continueCampaigns, $startedIds] = $this->continueCampaigns($user);

        return [
            'puzzles' => $puzzles,
            'continue_puzzles' => $history['continue'],
            'campaigns' => $this->newCampaigns($startedIds),
            'continue_campaigns' => $continueCampaigns,
            'competitions' => $this->competitions($user),
            'teams' => $this->teams($user),
            'has_history' => $history['count'] > 0,
        ];
    }

    /**
     * تاريخ المستخدم المستقل مجمَّعًا لكل أحجية (استعلام واحد محدود بـhistory_cap) + بيانات تلك الأحجيات (استعلام واحد).
     *
     * @return array{count: int, completed: list<int>, exhausted: list<int>, categories: array<int, float>, difficulties: array<string, float>, continue: Collection<int, Puzzle>}
     */
    protected function history(User $user): array
    {
        $rows = DB::table('puzzle_attempts')
            ->where('user_id', $user->getKey())
            ->whereNull('context_type')
            ->groupBy('puzzle_id')
            ->selectRaw('puzzle_id, COUNT(*) as attempts, SUM(CASE WHEN is_correct THEN 1 ELSE 0 END) as solved, MAX(created_at) as last_at')
            ->orderByDesc('last_at')->orderByDesc('puzzle_id')
            ->limit(max(1, (int) config('search.recommendations.history_cap', 500)))
            ->get();

        $empty = ['count' => 0, 'completed' => [], 'exhausted' => [], 'categories' => [], 'difficulties' => [], 'continue' => collect()];

        if ($rows->isEmpty()) {
            return $empty;
        }

        $puzzles = Puzzle::query()
            ->select('puzzles.id', 'puzzles.title', 'puzzles.difficulty', 'puzzles.puzzle_category_id', 'puzzles.max_attempts', 'puzzles.is_active', 'puzzles.gem_reward')
            ->whereIn('puzzles.id', $rows->pluck('puzzle_id')->all())
            ->get()->keyBy('id');

        $recentCut = now()->subDays(30);
        $completed = [];
        $exhausted = [];
        $categories = [];
        $difficulties = [];
        $continue = [];

        foreach ($rows as $row) {
            $puzzle = $puzzles->get((int) $row->puzzle_id);

            if ($puzzle === null) {
                continue;
            }

            $solved = (int) $row->solved > 0;
            $weight = ($solved ? 2.0 : 1.0) * (\Illuminate\Support\Carbon::parse($row->last_at)->gte($recentCut) ? 1.0 : 0.5);
            $categories[$puzzle->puzzle_category_id] = ($categories[$puzzle->puzzle_category_id] ?? 0.0) + $weight;

            if ($solved) {
                $completed[] = $puzzle->id;
                $difficulties[$puzzle->difficulty] = ($difficulties[$puzzle->difficulty] ?? 0.0) + 1.0;
            } elseif ((int) $row->attempts >= (int) $puzzle->max_attempts) {
                $exhausted[] = $puzzle->id;
            } elseif ($puzzle->is_active) {
                $continue[] = $puzzle;
            }
        }

        $continuing = new \Illuminate\Database\Eloquent\Collection(array_slice($continue, 0, 4));
        $continuing->isNotEmpty() && $continuing->load('category:id,name');

        return ['count' => $rows->count(), 'completed' => $completed, 'exhausted' => $exhausted, 'categories' => $categories, 'difficulties' => $difficulties, 'continue' => $continuing];
    }

    /**
     * @param  array{count: int, completed: list<int>, exhausted: list<int>, categories: array<int, float>, difficulties: array<string, float>, continue: Collection}  $history
     * @return list<array{puzzle: Puzzle, reason: string}>
     */
    protected function puzzles(User $user, array $history): array
    {
        $limit = max(1, (int) config('search.recommendations.limit', 8));
        $pool = max($limit, (int) config('search.recommendations.pool', 60));
        $weights = (array) config('search.recommendations.weights', []);
        // ما حلّه وما استنفد محاولاته لا يُقترح؛ وما يتابعه الآن يظهر في «تابع» فلا يتكرر في «مقترحة لك».
        $excluded = array_values(array_unique(array_merge($history['completed'], $history['exhausted'], $history['continue']->pluck('id')->all())));
        $popularity = $this->trending->puzzleScores();
        $maxPopularity = $popularity === [] ? 1 : max(1, max(array_column($popularity, 'score')));
        $newSince = now()->subDays(max(1, (int) config('search.recommendations.new_content_days', 14)));

        $affinity = $history['categories'];
        arsort($affinity);
        $favorites = array_slice(array_keys($affinity), 0, 3);
        $maxAffinity = $affinity === [] ? 1.0 : max($affinity);
        $preferred = $history['difficulties'];
        arsort($preferred);
        $preferredDifficulty = array_key_first($preferred);
        $order = ['easy' => 1, 'medium' => 2, 'hard' => 3];

        $base = fn () => Puzzle::query()
            ->select('puzzles.id', 'puzzles.title', 'puzzles.difficulty', 'puzzles.puzzle_category_id', 'puzzles.gem_reward', 'puzzles.created_at')
            ->with('category:id,name')
            ->where('puzzles.is_active', true)
            ->when($excluded !== [], fn ($q) => $q->whereNotIn('puzzles.id', $excluded));

        $liked = $favorites === [] ? collect() : $base()->whereIn('puzzles.puzzle_category_id', $favorites)->orderByDesc('puzzles.id')->limit($pool)->get();
        $tried = array_keys($affinity);
        $fresh = $base()
            ->when($tried !== [], fn ($q) => $q->whereNotIn('puzzles.puzzle_category_id', $tried))
            ->when($favorites === [], fn ($q) => $q->orderByRaw("CASE puzzles.difficulty WHEN 'easy' THEN 0 WHEN 'medium' THEN 1 ELSE 2 END"))
            ->orderByDesc('puzzles.id')->limit(max(20, $pool))->get();

        $day = now()->format('Y-m-d');
        $jitter = fn (Puzzle $p): int => crc32($user->getKey().'|'.$day.'|'.$p->id);
        $score = function (Puzzle $p) use ($affinity, $maxAffinity, $popularity, $maxPopularity, $newSince, $weights, $preferredDifficulty, $order): float {
            $fit = match (true) {
                $preferredDifficulty === null => 0.5,
                $p->difficulty === $preferredDifficulty => 1.0,
                ($order[$p->difficulty] ?? 0) === ($order[$preferredDifficulty] ?? 0) + 1 => 0.7,
                default => 0.3,
            };

            return (float) ($weights['category'] ?? 5.0) * (($affinity[$p->puzzle_category_id] ?? 0.0) / $maxAffinity)
                + (float) ($weights['difficulty'] ?? 2.0) * $fit
                + (float) ($weights['popularity'] ?? 1.5) * (($popularity[$p->id]['score'] ?? 0) / $maxPopularity)
                + (float) ($weights['novelty'] ?? 1.0) * ($p->created_at !== null && $p->created_at->gte($newSince) ? 1.0 : 0.0);
        };

        $exploreSlots = $history['count'] > 0 ? (int) floor($limit * max(0.0, min(1.0, (float) config('search.recommendations.exploration_share', 0.25)))) : 0;
        $picked = [];
        $result = [];

        $rank = fn (Collection $items) => $items->sort(fn (Puzzle $a, Puzzle $b) => [$score($b), $jitter($a), $b->id] <=> [$score($a), $jitter($b), $a->id])->values();

        // أ) الملائمة: من تصنيفاته المفضلة (أو، بلا تاريخ، الرائج ثم الأسهل/الأحدث).
        $primary = $history['count'] > 0 ? $liked : $fresh->merge($this->popularPuzzles($popularity, $excluded))->unique('id');

        foreach ($rank($primary)->take($limit - $exploreSlots) as $puzzle) {
            $picked[$puzzle->id] = true;
            $result[] = ['puzzle' => $puzzle, 'reason' => $this->reason($puzzle, $history, $popularity, $newSince)];
        }

        // ب) الاستكشاف: تصنيفات لم يجرّبها، ترتيب حتمي يومي (تجزئة) ثم الشعبية.
        if ($exploreSlots > 0) {
            $explore = $fresh->reject(fn (Puzzle $p) => isset($picked[$p->id]))
                ->sort(fn (Puzzle $a, Puzzle $b) => [$jitter($a), $b->id] <=> [$jitter($b), $a->id])->values();

            foreach ($explore->take($exploreSlots) as $puzzle) {
                $picked[$puzzle->id] = true;
                $result[] = ['puzzle' => $puzzle, 'reason' => 'تصنيف جديد لك'];
            }
        }

        // تعبئة إن لم تكفِ الشرائح (قاعدة صغيرة): من بقية المرشّحين بالدرجة.
        if (count($result) < $limit) {
            foreach ($rank($liked->merge($fresh)->unique('id')->reject(fn (Puzzle $p) => isset($picked[$p->id])))->take($limit - count($result)) as $puzzle) {
                $result[] = ['puzzle' => $puzzle, 'reason' => $this->reason($puzzle, $history, $popularity, $newSince)];
            }
        }

        return $result;
    }

    /** @param array<int, array{score: int, players: int}> $popularity */
    protected function popularPuzzles(array $popularity, array $excluded): Collection
    {
        $ids = array_values(array_diff(array_slice(array_keys($popularity), 0, 20), $excluded));

        return $ids === [] ? collect() : Puzzle::query()
            ->select('puzzles.id', 'puzzles.title', 'puzzles.difficulty', 'puzzles.puzzle_category_id', 'puzzles.gem_reward', 'puzzles.created_at')
            ->with('category:id,name')->where('puzzles.is_active', true)->whereIn('puzzles.id', $ids)->get();
    }

    /** @param array<int, array{score: int, players: int}> $popularity */
    protected function reason(Puzzle $puzzle, array $history, array $popularity, \Illuminate\Support\Carbon $newSince): string
    {
        return match (true) {
            isset($history['categories'][$puzzle->puzzle_category_id]) && $puzzle->category !== null => 'لأنك تلعب «'.$puzzle->category->name.'»',
            isset($popularity[$puzzle->id]) => 'رائجة هذا الأسبوع',
            $puzzle->created_at !== null && $puzzle->created_at->gte($newSince) => 'جديدة',
            default => 'مناسبة للبداية',
        };
    }

    /**
     * حملات بدأها المستخدم ولم يكملها وما زالت متاحة (نفس شرط /campaigns). لا نعيد حساب أي تقدّم: نربط فقط إلى صفحة الحملة التي تعرض تقدّمه بمصدر الحقيقة نفسه.
     *
     * @return array{0: Collection<int, Campaign>, 1: list<int>} [الحملات الجارية، كل معرّفات ما بدأه]
     */
    protected function continueCampaigns(User $user): array
    {
        $started = DB::table('user_campaign_progress as ucp')
            ->join('campaign_steps as s', 's.id', '=', 'ucp.campaign_step_id')
            ->join('campaign_gates as g', 'g.id', '=', 's.campaign_gate_id')
            ->join('campaign_stages as st', 'st.id', '=', 'g.campaign_stage_id')
            ->where('ucp.user_id', $user->getKey())
            ->groupBy('st.campaign_id')
            ->selectRaw('st.campaign_id as campaign_id, MAX(ucp.updated_at) as last_at')
            ->orderByDesc('last_at')->orderByDesc('campaign_id')
            ->limit(50)
            ->get();

        $ids = $started->pluck('campaign_id')->map(fn ($id) => (int) $id)->all();

        if ($ids === []) {
            return [collect(), []];
        }

        $completed = DB::table('user_campaign_completions')->where('user_id', $user->getKey())->whereIn('campaign_id', $ids)->pluck('campaign_id')->map(fn ($id) => (int) $id)->all();

        $campaigns = $this->availableCampaigns()->whereIn('campaigns.id', array_values(array_diff($ids, $completed)))->get()->keyBy('id');

        $ordered = collect($ids)->filter(fn (int $id) => $campaigns->has($id))->take(3)->map(fn (int $id) => $campaigns->get($id))->values();

        return [$ordered, $ids];
    }

    /** @param list<int> $startedIds */
    protected function newCampaigns(array $startedIds): Collection
    {
        return $this->listableCampaigns()
            ->when($startedIds !== [], fn ($q) => $q->whereNotIn('campaigns.id', $startedIds))
            ->orderByDesc('campaigns.id')->limit(4)->get();
    }

    /** للعرض كبطاقات اكتشاف: الحملة التي يقوم عليها موسم منشور تظهر كموسم فقط (نفس قاعدة البحث)، فلا تتكرر بطاقتان لشيء واحد. */
    public function listableCampaigns(): \Illuminate\Database\Eloquent\Builder
    {
        return $this->availableCampaigns()
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('seasons')->whereColumn('seasons.campaign_id', 'campaigns.id')->where('seasons.is_published', true));
    }

    /** نفس شرط /campaigns. */
    public function availableCampaigns(): \Illuminate\Database\Eloquent\Builder
    {
        return Campaign::query()
            ->select('campaigns.id', 'campaigns.slug', 'campaigns.title', 'campaigns.starts_at', 'campaigns.ends_at')
            ->where('campaigns.is_active', true)
            ->where(fn ($q) => $q->whereNull('campaigns.starts_at')->orWhere('campaigns.starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('campaigns.ends_at')->orWhere('campaigns.ends_at', '>=', now()));
    }

    /** منافسات عامة لم تنتهِ ولم يسجّل فيها المستخدم بعد. */
    protected function competitions(User $user): Collection
    {
        return CompetitiveEvent::query()
            ->select('competitive_events.id', 'competitive_events.slug', 'competitive_events.title', 'competitive_events.status', 'competitive_events.starts_at', 'competitive_events.ends_at', 'competitive_events.participants_count')
            ->public()
            ->where('competitive_events.ends_at', '>', now())
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('competitive_event_participants as p')->whereColumn('p.competitive_event_id', 'competitive_events.id')->where('p.user_id', $user->getKey()))
            ->orderByDesc('competitive_events.is_featured')->orderBy('competitive_events.starts_at')->orderByDesc('competitive_events.id')
            ->limit(3)->get();
    }

    /** فرق مفتوحة للانضمام، لمن ليس عضوًا بأي فريق (العضوية فريدة للمستخدم). */
    protected function teams(User $user): Collection
    {
        if (TeamMembership::query()->where('user_id', $user->getKey())->exists()) {
            return collect();
        }

        return Team::query()
            ->select('teams.id', 'teams.slug', 'teams.name', 'teams.members_count', 'teams.join_policy')
            ->directory()
            ->where('teams.join_policy', '!=', Team::JOIN_INVITE_ONLY)
            ->where(fn ($q) => $q->whereNull('teams.max_members')->orWhereColumn('teams.members_count', '<', 'teams.max_members'))
            ->orderByDesc('teams.members_count')->orderByDesc('teams.id')
            ->limit(4)->get();
    }
}
