# E27 — DELIVERY PACK (A–E)

NO GIT COMMANDS. أساس المقارنة: `acc9d643a84bf6e0a3ce613e67a8384bb360dc5c`. هذا الملف خارج المستودع عمدًا (لا يُنسخ إليه).

## A) NEW FILES — المسار + المحتوى الكامل (42 ملفًا)

### `app/Http/Controllers/DiscoverController.php`

`````php
<?php

namespace App\Http\Controllers;

use App\Services\Discovery\DiscoveryService;
use Illuminate\Http\Request;

/** E27: مركز الاكتشاف (قراءة فقط). عام للضيوف؛ المسجَّل يضاف له «تابع» و«مقترحة لك». لا مكافآت ولا كتابة. */
class DiscoverController extends Controller
{
    public function __construct(protected DiscoveryService $discovery) {}

    public function index(Request $request)
    {
        return view('discover.index', $this->discovery->hub($request->user()));
    }
}
`````

### `app/Http/Controllers/SearchController.php`

`````php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\SearchRequest;
use App\Services\Search\GlobalSearchService;
use App\Services\Search\SearchContext;
use App\Services\Search\SearchResult;
use Illuminate\Http\JsonResponse;

/**
 * E27: البحث العام (قراءة فقط). صفحة /search تعمل بلا JavaScript (نموذج GET)؛ /search/suggest نقطة JSON للاقتراحات الفورية.
 * عامة للضيوف والأعضاء: كل نوع يطبّق قاعدة ظهور صفحته العامة (انظر مصادر App\Services\Search\Sources). لا كتابة ولا تسجيل لنص البحث.
 */
class SearchController extends Controller
{
    public function __construct(protected GlobalSearchService $search) {}

    public function index(SearchRequest $request)
    {
        $viewer = $request->user();
        $type = $this->search->isType($request->typeFilter()) ? $request->typeFilter() : null;
        $context = SearchContext::for($viewer);
        $common = [
            'types' => collect($this->search->types())->mapWithKeys(fn (string $t) => [$t => $this->search->label($t)])->all(),
            'minLength' => $this->search->overallMinLength($context),
            'guest' => $context->isGuest(),
        ];

        if ($type !== null) {
            $data = $this->search->ofType($type, $request->term(), $viewer, $request->pageNumber(), array_filter(['q' => $request->term() === '' ? null : $this->search->normalize($request->term())->display, 'type' => $type]));

            return view('search.index', $common + $data + ['type' => $type, 'mode' => 'type', 'typeLabel' => $this->search->label($type)]);
        }

        return view('search.index', $common + $this->search->grouped($request->term(), $viewer) + ['type' => null, 'mode' => 'all']);
    }

    public function suggest(SearchRequest $request): JsonResponse
    {
        $data = $this->search->suggest($request->term(), $request->user());
        $query = $data['query'];

        $payload = [
            'state' => $data['state'],
            'query' => $query,
            'groups' => array_map(fn (array $group) => [
                'type' => $group['type'],
                'label' => $group['label'],
                'icon' => $group['icon'],
                'items' => array_map(fn (SearchResult $r) => $r->toSuggestion(), $group['results']),
                'more_url' => $group['has_more'] ? SearchResult::relativePath(route('search.index', ['q' => $query, 'type' => $group['type']])) : null,
            ], $data['groups']),
            'all_url' => SearchResult::relativePath(route('search.index', $query === '' ? [] : ['q' => $query])),
            'note' => in_array('players', $data['notes'], true) && $data['state'] === 'ok' ? 'اكتب '.config('search.min_length_guest_players', 3).' أحرف على الأقل للبحث عن لاعبين.' : null,
        ];

        return response()->json($payload)->header('Cache-Control', 'no-store, private');
    }
}
`````

### `app/Http/Requests/SearchRequest.php`

`````php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * E27: مدخلات البحث العام. **متسامحة عمدًا**: لا تفشل بالتحقق ولا تُعيد توجيهًا بأخطاء (صفحة بحث بمدخل غريب تُعرض فارغة بدل 422).
 * مصفوفات/أنواع غير نصية (?q[]=x) تُعامَل كفراغ. الطول والتطبيع والحدود تفرضها خدمة البحث (SearchQuery) لا هنا.
 */
class SearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [];
    }

    public function term(): string
    {
        $term = $this->query('q');

        return is_string($term) ? $term : '';
    }

    public function typeFilter(): ?string
    {
        $type = $this->query('type');

        return is_string($type) && $type !== '' && strlen($type) <= 32 ? $type : null;
    }

    public function pageNumber(): int
    {
        $page = $this->query('page');

        return is_string($page) && ctype_digit($page) && strlen($page) <= 4 ? max(1, (int) $page) : 1;
    }
}
`````

### `app/Services/Discovery/DiscoverCard.php`

`````php
<?php

namespace App\Services\Discovery;

/** E27-B: بطاقة عرض موحَّدة لقسم اكتشاف. نصوص عادية ومسار فقط؛ يُهرَّب الإخراج بـBlade. لا بيانات حساسة. */
final class DiscoverCard
{
    public function __construct(
        public readonly string $title,
        public readonly string $url,
        public readonly string $icon = 'puzzle',
        public readonly ?string $subtitle = null,
        public readonly ?string $badge = null,
        public readonly ?string $badgeTone = 'neutral',
        public readonly ?string $reason = null,
    ) {}
}
`````

### `app/Services/Discovery/DiscoveryService.php`

`````php
<?php

namespace App\Services\Discovery;

use App\Models\Campaign;
use App\Models\CompetitiveEvent;
use App\Models\Puzzle;
use App\Models\PuzzleCategory;
use App\Models\Season;
use App\Models\Team;
use App\Models\TeamChampionship;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * E27-B: مركز الاكتشاف. يجمع أقسامًا **محدودة العدد** (لا ترقيم ولا قوائم مفتوحة) من الأنظمة القائمة بنفس شروط ظهورها العامة، ويضيف للمسجَّل «تابع» و«مقترح لك».
 * عدد الاستعلامات لا يتبع حجم البيانات (كل قسم استعلام محدود بـLIMIT). كل الأقسام عرض فقط: لا مكافآت ولا نقاط ولا كتابة.
 *
 * @phpstan-type Section array{key: string, title: string, icon: string, href: ?string, linkLabel: ?string, cards: list<DiscoverCard>}
 */
class DiscoveryService
{
    public function __construct(protected TrendingService $trending, protected RecommendationService $recommendations) {}

    /**
     * @return array{sections: list<array>, categories: Collection<int, PuzzleCategory>, personalized: bool}
     */
    public function hub(?User $viewer): array
    {
        $limit = max(1, (int) config('search.discover.section_limit', 8));
        $sections = [];
        $reco = $viewer !== null ? $this->recommendations->forUser($viewer) : null;

        if ($reco !== null) {
            $this->add($sections, 'continue', 'تابع من حيث توقفت', 'bolt', null, null, array_merge(
                $reco['continue_puzzles']->map(fn (Puzzle $p) => $this->puzzleCard($p, 'محاولاتك لم تنتهِ'))->all(),
                $reco['continue_campaigns']->map(fn (Campaign $c) => $this->campaignCard($c, 'تقدّمك محفوظ'))->all(),
            ));
            $this->add($sections, 'for-you', 'مقترحة لك', 'sparkles', route('puzzles.index'), 'كل الأحجيات',
                array_map(fn (array $r) => $this->puzzleCard($r['puzzle'], $r['reason']), $reco['puzzles']));
        }

        $this->add($sections, 'trending', 'الرائج هذا الأسبوع', 'bolt', route('puzzles.index'), 'كل الأحجيات',
            $this->trending->puzzles($limit)->map(fn (Puzzle $p) => $this->puzzleCard($p, $p->trending_players.' لاعب هذا الأسبوع'))->all());

        $daily = Puzzle::query()->select('puzzles.id', 'puzzles.title', 'puzzles.difficulty', 'puzzles.puzzle_category_id')->with('category:id,name')
            ->where('is_daily_puzzle', true)->whereDate('daily_puzzle_date', today())->where('is_active', true)->limit(1)->get();
        $this->add($sections, 'daily', 'أحجية اليوم', 'calendar', null, null, $daily->map(fn (Puzzle $p) => $this->puzzleCard($p, 'تتجدّد يوميًا'))->all());

        $this->add($sections, 'new', 'جديد الأحجيات', 'puzzle', route('puzzles.index'), 'كل الأحجيات',
            Puzzle::query()->select('puzzles.id', 'puzzles.title', 'puzzles.difficulty', 'puzzles.puzzle_category_id')->with('category:id,name')
                ->where('puzzles.is_active', true)->orderByDesc('puzzles.id')->limit($limit)->get()
                ->map(fn (Puzzle $p) => $this->puzzleCard($p, null))->all());

        $seasons = Season::query()->join('campaigns', 'campaigns.id', '=', 'seasons.campaign_id')
            ->select('seasons.id', 'seasons.slug', 'seasons.is_featured', 'campaigns.title as campaign_title')
            ->where('seasons.is_published', true)->orderByDesc('seasons.is_featured')->orderByDesc('seasons.id')
            ->limit((int) config('search.discover.season_limit', 4))->get();
        $this->add($sections, 'seasons', 'المواسم', 'calendar', route('seasons.index'), 'كل المواسم',
            $seasons->map(fn (Season $s) => new DiscoverCard((string) $s->getAttribute('campaign_title'), route('seasons.show', $s), 'calendar', $s->is_featured ? 'موسم مميّز' : null))->all());

        $this->add($sections, 'campaigns', 'حملات متاحة', 'flag', route('campaigns.index'), 'كل الحملات',
            $this->recommendations->listableCampaigns()->orderByDesc('campaigns.id')->limit((int) config('search.discover.campaign_limit', 6))->get()
                ->map(fn (Campaign $c) => $this->campaignCard($c, null))->all());

        $events = $reco !== null && $reco['competitions']->isNotEmpty() ? $reco['competitions'] : $this->trending->competitions((int) config('search.discover.event_limit', 6));
        $this->add($sections, 'competitions', $reco !== null ? 'منافسات لم تسجّل بها' : 'منافسات رائجة', 'trophy', route('competitions.index'), 'كل المنافسات',
            $events->map(fn (CompetitiveEvent $e) => $this->eventCard($e))->all());

        $this->add($sections, 'championships', 'بطولات الفرق', 'flag', route('team-championships.index'), 'كل البطولات',
            TeamChampionship::query()->select('team_championships.id', 'team_championships.slug', 'team_championships.title', 'team_championships.status', 'team_championships.starts_at', 'team_championships.ends_at')
                ->public()->where('team_championships.ends_at', '>', now())->orderBy('team_championships.starts_at')->orderByDesc('team_championships.id')->limit(4)->get()
                ->map(fn (TeamChampionship $c) => new DiscoverCard($c->title, route('team-championships.show', $c), 'flag', $c->starts_at?->format('Y-m-d'), $c->phase() === TeamChampionship::PHASE_LIVE ? 'مباشرة' : 'قريبًا', $c->phase() === TeamChampionship::PHASE_LIVE ? 'success' : 'warning'))->all());

        $teams = $reco !== null && $reco['teams']->isNotEmpty() ? $reco['teams'] : $this->trending->teams((int) config('search.discover.team_limit', 6));
        $this->add($sections, 'teams', $reco !== null && $reco['teams']->isNotEmpty() ? 'فرق تنضم إليها' : 'فرق صاعدة', 'team', route('teams.index'), 'كل الفرق',
            $teams->map(fn (Team $t) => new DiscoverCard($t->name, route('teams.show', $t), 'team', number_format((int) $t->members_count).' عضو'))->all());

        if ($reco !== null && $reco['campaigns']->isNotEmpty()) {
            $this->add($sections, 'new-campaigns', 'حملات لم تبدأها', 'flag', route('campaigns.index'), 'كل الحملات',
                $reco['campaigns']->map(fn (Campaign $c) => $this->campaignCard($c, null))->all());
        }

        $categories = PuzzleCategory::query()->select('id', 'name', 'slug')->where('is_active', true)->orderBy('sort_order')->orderBy('id')->limit(12)->get();

        return ['sections' => $sections, 'categories' => $categories, 'personalized' => $reco !== null];
    }

    /** @param list<array> $sections @param list<DiscoverCard> $cards */
    protected function add(array &$sections, string $key, string $title, string $icon, ?string $href, ?string $linkLabel, array $cards): void
    {
        if ($cards === []) {
            return;
        }

        $sections[] = ['key' => $key, 'title' => $title, 'icon' => $icon, 'href' => $href, 'linkLabel' => $linkLabel, 'cards' => array_values($cards)];
    }

    protected function puzzleCard(Puzzle $puzzle, ?string $reason): DiscoverCard
    {
        $difficulty = ['easy' => 'سهل', 'medium' => 'متوسط', 'hard' => 'صعب'][$puzzle->difficulty] ?? null;
        $tone = ['easy' => 'success', 'medium' => 'warning', 'hard' => 'danger'][$puzzle->difficulty] ?? 'neutral';

        return new DiscoverCard($puzzle->title, route('puzzles.show', $puzzle), 'puzzle', $puzzle->category?->name, $difficulty, $tone, $reason);
    }

    protected function campaignCard(Campaign $campaign, ?string $reason): DiscoverCard
    {
        return new DiscoverCard($campaign->title, route('campaigns.show', $campaign), 'flag', $campaign->ends_at ? 'تنتهي '.$campaign->ends_at->format('Y-m-d') : null, null, 'neutral', $reason);
    }

    protected function eventCard(CompetitiveEvent $event): DiscoverCard
    {
        $phase = $event->phase();

        return new DiscoverCard($event->title, route('competitions.show', $event), 'trophy', 'المشاركون: '.$event->participants_count,
            $phase === CompetitiveEvent::PHASE_LIVE ? 'مباشرة الآن' : ($phase === CompetitiveEvent::PHASE_UPCOMING ? 'قادمة' : 'منتهية'),
            $phase === CompetitiveEvent::PHASE_LIVE ? 'success' : ($phase === CompetitiveEvent::PHASE_UPCOMING ? 'warning' : 'neutral'));
    }
}
`````

### `app/Services/Discovery/RecommendationService.php`

`````php
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
`````

### `app/Services/Discovery/TrendingService.php`

`````php
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
`````

### `app/Services/Search/Contracts/SearchBackend.php`

`````php
<?php

namespace App\Services\Search\Contracts;

use App\Services\Search\SearchContext;
use App\Services\Search\SearchQuery;
use App\Services\Search\SearchResult;
use Illuminate\Support\Collection;

/**
 * E27: واجهة محرك البحث. التنفيذ الحالي DatabaseSearchBackend (قاعدة البيانات). محرك خارجي مستقبلي (Meilisearch/Typesense/…) = تنفيذ جديد لهذه الواجهة
 * فقط، دون لمس المتحكّمات ولا القوالب؛ ويجب أن يحترم نفس قواعد الظهور (انظر docs/search-discovery-e27.md).
 */
interface SearchBackend
{
    /** @return list<string> أنواع الكيانات المتاحة بالترتيب الافتراضي. */
    public function types(): array;

    public function label(string $type): string;

    public function minLength(string $type, SearchContext $context): int;

    /**
     * @return Collection<int, SearchResult> نتائج نوع واحد.
     */
    public function searchType(string $type, SearchQuery $query, SearchContext $context, int $limit, int $offset = 0): Collection;
}
`````

### `app/Services/Search/Contracts/SearchSource.php`

`````php
<?php

namespace App\Services\Search\Contracts;

use App\Services\Search\SearchContext;
use App\Services\Search\SearchQuery;
use App\Services\Search\SearchResult;
use Illuminate\Support\Collection;

/** E27: نوع كيان قابل للبحث. كل مصدر يفرض قاعدة ظهور كيانه بنفسه (نفس قاعدة صفحة القائمة العامة). */
interface SearchSource
{
    public function type(): string;

    public function label(): string;

    public function icon(): string;

    /** الحد الأدنى لطول المصطلح لهذا النوع وهذا المشاهد. */
    public function minLength(SearchContext $context): int;

    /** @return Collection<int, SearchResult> مرتبة بالصلة ثم بترتيب ثابت. $limit صفوف بدءًا من $offset. */
    public function search(SearchQuery $query, SearchContext $context, int $limit, int $offset = 0): Collection;
}
`````

### `app/Services/Search/DatabaseSearchBackend.php`

`````php
<?php

namespace App\Services\Search;

use App\Services\Search\Contracts\SearchBackend;
use App\Services\Search\Contracts\SearchSource;
use App\Services\Search\Sources\CampaignSource;
use App\Services\Search\Sources\ChampionshipSource;
use App\Services\Search\Sources\CompetitionSource;
use App\Services\Search\Sources\PlayerSource;
use App\Services\Search\Sources\PuzzleSource;
use App\Services\Search\Sources\SeasonSource;
use App\Services\Search\Sources\TeamSource;
use Illuminate\Support\Collection;

/**
 * E27: التنفيذ الحالي لـSearchBackend: مصادر Eloquent فوق قاعدة البيانات الحالية. لا محرك خارجي ولا Scout ولا ذكاء اصطناعي ولا بحث متجهي.
 */
final class DatabaseSearchBackend implements SearchBackend
{
    /** @var array<string, SearchSource> */
    private array $sources = [];

    public function __construct(PuzzleSource $puzzles, CampaignSource $campaigns, SeasonSource $seasons, CompetitionSource $competitions, ChampionshipSource $championships, TeamSource $teams, PlayerSource $players)
    {
        foreach ([$puzzles, $campaigns, $seasons, $competitions, $championships, $teams, $players] as $source) {
            $this->sources[$source->type()] = $source;
        }
    }

    public function types(): array
    {
        $configured = array_values(array_filter((array) config('search.types', []), fn ($t) => is_string($t) && isset($this->sources[$t])));

        return $configured !== [] ? $configured : array_keys($this->sources);
    }

    public function label(string $type): string
    {
        return $this->source($type)->label();
    }

    public function minLength(string $type, SearchContext $context): int
    {
        return $this->source($type)->minLength($context);
    }

    public function searchType(string $type, SearchQuery $query, SearchContext $context, int $limit, int $offset = 0): Collection
    {
        return $this->source($type)->search($query, $context, $limit, $offset);
    }

    private function source(string $type): SearchSource
    {
        return $this->sources[$type] ?? throw new \InvalidArgumentException('Unknown search type.');
    }
}
`````

### `app/Services/Search/GlobalSearchService.php`

`````php
<?php

namespace App\Services\Search;

use App\Models\User;
use App\Services\Search\Contracts\SearchBackend;
use Illuminate\Pagination\Paginator;

/**
 * E27: واجهة البحث العامة للمتحكّمات. تنظّف المُدخَل وتطبّع، تفرض الحدود، وتفوّض للمحرك (SearchBackend).
 * لا تسجّل نص البحث ولا هوية الباحث بأي مكان (لا سجل ولا جدول ولا كاش): انظر قرار التحليلات بالمستند.
 *
 * حالات الإخراج (state): empty (لا مُدخَل) · too_short (دون الحدّ الأدنى لكل الأنواع) · ok.
 */
class GlobalSearchService
{
    public function __construct(protected SearchBackend $backend) {}

    public function normalize(?string $input): SearchQuery
    {
        return SearchQuery::from($input);
    }

    /** @return list<string> */
    public function types(): array
    {
        return $this->backend->types();
    }

    public function label(string $type): string
    {
        return $this->backend->label($type);
    }

    public function isType(?string $type): bool
    {
        return is_string($type) && in_array($type, $this->backend->types(), true);
    }

    /** أقل حدّ أدنى لطول المصطلح بين الأنواع لهذا الباحث (يحدد أبكر لحظة تُجدي فيها الاقتراحات). */
    public function overallMinLength(SearchContext $context): int
    {
        return min(array_map(fn (string $t) => $this->backend->minLength($t, $context), $this->backend->types()));
    }

    /**
     * اقتراحات فورية (autocomplete): بحد أقصى لكل نوع ولكل الاقتراحات. مجموعة فيها تطابق تام تتقدّم.
     *
     * @return array{state: string, query: string, groups: list<array{type: string, label: string, icon: string, results: list<SearchResult>, has_more: bool}>, notes: list<string>}
     */
    public function suggest(?string $input, ?User $viewer): array
    {
        return $this->gather($this->normalize($input), SearchContext::for($viewer), (int) config('search.suggest_per_type', 3), (int) config('search.suggest_total', 9));
    }

    /**
     * صفحة البحث «الكل»: أفضل النتائج لكل نوع.
     *
     * @return array{state: string, query: string, groups: list<array{type: string, label: string, icon: string, results: list<SearchResult>, has_more: bool}>, notes: list<string>}
     */
    public function grouped(?string $input, ?User $viewer): array
    {
        return $this->gather($this->normalize($input), SearchContext::for($viewer), (int) config('search.all_per_type', 5), null);
    }

    /**
     * تبويب نوع واحد بترقيم بسيط (بلا COUNT) وسقف عمق: الضيف أقل عمقًا من الأعضاء.
     *
     * @return array{state: string, query: string, results: Paginator, notes: list<string>, capped: bool}
     */
    public function ofType(string $type, ?string $input, ?User $viewer, int $page, array $linkQuery = []): array
    {
        $context = SearchContext::for($viewer);
        $query = $this->normalize($input);
        $size = max(1, (int) config('search.page_size', 12));
        $cap = max(1, (int) config($context->isGuest() ? 'search.guest_max_page' : 'search.max_page', 5));
        $effective = min(max(1, $page), $cap);
        $items = collect();
        $state = $this->stateFor($query, $context);
        $notes = [];

        if ($state === 'ok' && ! $query->meets($this->backend->minLength($type, $context))) {
            $state = 'too_short';
        }

        if ($state === 'ok') {
            $items = $this->backend->searchType($type, $query, $context, $size + 1, ($effective - 1) * $size);
        }

        $hasMore = $items->count() > $size && $effective < $cap;
        $capped = $items->count() > $size && $effective >= $cap;
        $paginator = new Paginator($items->take($size)->values(), $size, $effective, ['path' => route('search.index'), 'query' => $linkQuery]);
        $paginator->hasMorePagesWhen($hasMore);

        return ['state' => $state, 'query' => $query->display, 'results' => $paginator, 'notes' => $notes, 'capped' => $capped];
    }

    /** @return array{state: string, query: string, groups: list<array>, notes: list<string>} */
    private function gather(SearchQuery $query, SearchContext $context, int $perType, ?int $total): array
    {
        $state = $this->stateFor($query, $context);
        $groups = [];
        $notes = [];

        if ($state === 'ok') {
            foreach ($this->backend->types() as $order => $type) {
                if (! $query->meets($this->backend->minLength($type, $context))) {
                    $notes[] = $type;

                    continue;
                }

                $found = $this->backend->searchType($type, $query, $context, $perType + 1);

                if ($found->isEmpty()) {
                    continue;
                }

                $groups[] = [
                    'type' => $type,
                    'label' => $this->backend->label($type),
                    'icon' => $found->first()->icon,
                    'results' => $found->take($perType)->values()->all(),
                    'has_more' => $found->count() > $perType,
                    'best' => $found->min('tier'),
                    'order' => $order,
                ];
            }

            usort($groups, fn (array $a, array $b) => [$a['best'], $a['order']] <=> [$b['best'], $b['order']]);

            if ($total !== null) {
                $left = $total;
                foreach ($groups as $i => $group) {
                    $groups[$i]['results'] = array_slice($group['results'], 0, max(0, $left));
                    $left -= count($groups[$i]['results']);
                    $groups[$i]['has_more'] = $group['has_more'] || count($groups[$i]['results']) < count($group['results']);
                }
                $groups = array_values(array_filter($groups, fn (array $g) => $g['results'] !== []));
            }
        }

        return [
            'state' => $state,
            'query' => $query->display,
            'groups' => array_map(fn (array $g) => array_diff_key($g, ['best' => 1, 'order' => 1]), $groups),
            'notes' => $notes,
        ];
    }

    private function stateFor(SearchQuery $query, SearchContext $context): string
    {
        return match (true) {
            $query->isEmpty() => 'empty',
            ! $query->meets($this->overallMinLength($context)) => 'too_short',
            default => 'ok',
        };
    }
}
`````

### `app/Services/Search/PartialProbe.php`

`````php
<?php

namespace App\Services\Search;

use App\Support\CachedSnapshot;
use Illuminate\Support\Facades\DB;

/**
 * E27: هل نسمح بالمطابقة الجزئية (مسح الجدول مع طيّ الحروف) لهذا الجدول؟ فقط إذا كان أكبر معرّف ≤ الحدّ المضبوط.
 * القراءة: MAX(id) نقطة على المفتاح الأساسي (O(1))، وتُحفظ لقطة قصيرة. فشل الكاش = قراءة مباشرة (انظر CachedSnapshot). لا بيانات مستخدمين.
 */
final class PartialProbe
{
    public function allowed(string $type, string $table): bool
    {
        $max = (int) config("search.partial_match_max_id_by_type.{$type}", config('search.partial_match_max_id', 10000));
        $ttl = (int) config('search.partial_probe_ttl', 600);
        $maxId = (int) CachedSnapshot::remember('search.probe.'.TextFold::identifier($table), $ttl, fn () => (int) DB::table($table)->max('id'));

        return $maxId <= $max;
    }
}
`````

### `app/Services/Search/SearchContext.php`

`````php
<?php

namespace App\Services\Search;

use App\Models\User;

/** E27: من يبحث. الضيف وغير الموثَّق يُعاملان كزائر في نتائج اللاعبين (لا ملفات «للأعضاء فقط»، ولا استثناء حظر). */
final class SearchContext
{
    public function __construct(public readonly ?User $viewer = null) {}

    public static function for(?User $viewer): self
    {
        return new self($viewer);
    }

    public function isGuest(): bool
    {
        return $this->viewer === null;
    }

    /** مشاهد موثَّق البريد وغير مجمَّد: يرى ملفات «الأعضاء» ويُطبَّق عليه الحظر. */
    public function isTrustedMember(): bool
    {
        return $this->viewer !== null && $this->viewer->email_verified_at !== null && ! $this->viewer->is_frozen;
    }
}
`````

### `app/Services/Search/SearchQuery.php`

`````php
<?php

namespace App\Services\Search;

/**
 * E27: مُدخَل بحث بعد التنظيف والتطبيع. غير قابل للتعديل. لا يحتفظ بشيء عن المستخدم.
 *  - display: النص المنظَّف كما كتبه المستخدم (للعرض في الحقل والروابط؛ يُهرَّب بـBlade دائمًا).
 *  - folded: الشكل المطوي للمطابقة (أحرف صغيرة، عربية موحَّدة، أرقام ASCII).
 *  - tokens: كلمات الشكل المطوي (حدّ أقصى بالإعدادات) للمطابقة الجزئية.
 */
final class SearchQuery
{
    /** @param list<string> $tokens */
    private function __construct(
        public readonly string $display,
        public readonly string $folded,
        public readonly array $tokens,
        public readonly bool $truncated,
    ) {}

    public static function from(?string $input): self
    {
        $max = max(1, (int) config('search.max_length', 64));
        $clean = (string) $input;

        if ($clean === '' || ! mb_check_encoding($clean, 'UTF-8')) {
            return new self('', '', [], false);
        }

        $clean = preg_replace('/\p{Cf}/u', '', str_replace("\u{200B}", ' ', $clean)) ?? '';
        $clean = trim(preg_replace('/[\p{Cc}\p{Z}]+/u', ' ', $clean) ?? '');
        $truncated = mb_strlen($clean) > $max;
        $clean = $truncated ? rtrim(mb_substr($clean, 0, $max)) : $clean;

        $folded = TextFold::fold($clean);
        $tokens = array_slice(array_values(array_unique(array_filter(explode(' ', $folded), fn (string $t) => $t !== ''))), 0, max(1, (int) config('search.max_tokens', 5)));

        return new self($clean, $folded, $tokens, $truncated);
    }

    /** عدد الحروف المطوية بلا مسافات: أساس الحدّ الأدنى. */
    public function length(): int
    {
        return mb_strlen(str_replace(' ', '', $this->folded));
    }

    public function isEmpty(): bool
    {
        return $this->folded === '';
    }

    public function meets(int $minLength): bool
    {
        return $this->length() >= max(1, $minLength);
    }

    /** نمط بادئة آمن للمقارنة مع الشكل المطوي. */
    public function foldedPrefixPattern(): string
    {
        return TextFold::escapeLike($this->folded).'%';
    }

    /** نمط بادئة كلمة (أي كلمة تبدأ بالمصطلح) للمقارنة مع الشكل المطوي. */
    public function foldedWordPattern(): string
    {
        return '% '.TextFold::escapeLike($this->folded).'%';
    }

    /** نمط بادئة بالنص الأصلي بأحرف صغيرة (يطابق العمود الخام حين يتساوى المطوي والخام في المحرك). */
    public function rawPrefixPattern(): string
    {
        return TextFold::escapeLike(mb_strtolower($this->display, 'UTF-8')).'%';
    }

    /**
     * أنماط المطابقة الجزئية لكل كلمة. أداة التعريف «ال» تُسقَط من الكلمات الطويلة (≥ 5 حروف) فيجد «الاحجية» عنوان «أحجية» وعكسه، لأن الصيغة المجرّدة جزء من الصيغتين.
     *
     * @return list<string>
     */
    public function tokenPatterns(): array
    {
        return array_map(fn (string $t) => '%'.TextFold::escapeLike(self::withoutArticle($t)).'%', $this->tokens);
    }

    private static function withoutArticle(string $token): string
    {
        return mb_strlen($token) >= 5 && str_starts_with($token, "\u{0627}\u{0644}") ? mb_substr($token, 2) : $token;
    }

    /** مفتاح مستقر لوسائط الروابط. */
    public function urlTerm(): string
    {
        return $this->display;
    }
}
`````

### `app/Services/Search/SearchResult.php`

`````php
<?php

namespace App\Services\Search;

/**
 * E27: نتيجة بحث واحدة بحقول عرض فقط. لا تحمل بريدًا ولا هاتفًا ولا معرّفًا داخليًا (id) ولا أي حقل جواب/تلميح.
 * tier: 0 تطابق تام، 1 بادئة، 2 بادئة كلمة، 3 جزئي (الأصغر أعلى صلة).
 */
final class SearchResult
{
    public function __construct(
        public readonly string $type,
        public readonly string $title,
        public readonly string $url,
        public readonly ?string $subtitle = null,
        public readonly ?string $badge = null,
        public readonly string $icon = 'search',
        public readonly int $tier = 3,
        public readonly mixed $subject = null,   // النموذج لعرض الصفحة الكاملة (لا يُسلسَل إلى JSON أبدًا)
    ) {}

    /** شكل الاقتراح الفوري: مسار نسبي فقط (يمنع توجيهًا لمضيف آخر) ونصوص عادية يعرضها العميل بـtextContent. */
    public function toSuggestion(): array
    {
        return [
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'badge' => $this->badge,
            'icon' => $this->icon,
            'url' => self::relativePath($this->url),
        ];
    }

    public static function relativePath(string $url): string
    {
        $parts = parse_url($url);
        $path = '/'.ltrim((string) (($parts['path'] ?? '/') ?: '/'), '/\\');   // دائمًا «/» واحدة في البداية (لا «//host»)

        return $path.(isset($parts['query']) ? '?'.$parts['query'] : '');
    }
}
`````

### `app/Services/Search/Sources/CampaignSource.php`

`````php
<?php

namespace App\Services\Search\Sources;

use App\Models\Campaign;
use App\Services\Search\SearchContext;
use App\Services\Search\SearchResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * الحملات: نفس شرط /campaigns (نشطة وضمن نافذتها الزمنية). المسوّدات والمنتهية والقادمة لا تظهر.
 * حملة تقف خلف موسم منشور تظهر مرة واحدة كـ«موسم» (وجهه العام) لا مرتين: لا نكرّر نفس الشيء بنتيجتين.
 */
class CampaignSource extends EloquentSource
{
    public function type(): string
    {
        return 'campaigns';
    }

    public function label(): string
    {
        return 'الحملات';
    }

    public function icon(): string
    {
        return 'flag';
    }

    protected function table(): string
    {
        return 'campaigns';
    }

    protected function column(): string
    {
        return 'campaigns.title';
    }

    protected function baseQuery(SearchContext $context): Builder
    {
        return Campaign::query()
            ->select('campaigns.id', 'campaigns.slug', 'campaigns.title', 'campaigns.starts_at', 'campaigns.ends_at')
            ->where('campaigns.is_active', true)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('seasons')->whereColumn('seasons.campaign_id', 'campaigns.id')->where('seasons.is_published', true))
            ->where(fn ($q) => $q->whereNull('campaigns.starts_at')->orWhere('campaigns.starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('campaigns.ends_at')->orWhere('campaigns.ends_at', '>=', now()));
    }

    protected function tieBreak(Builder $query): Builder
    {
        return $query->orderByDesc('campaigns.id');
    }

    protected function toResult(Model $model, int $tier): SearchResult
    {
        /** @var Campaign $model */
        return new SearchResult(
            type: $this->type(),
            title: (string) $model->title,
            url: route('campaigns.show', $model),
            subtitle: $model->ends_at ? 'تنتهي '.$model->ends_at->format('Y-m-d') : null,
            badge: 'مباشرة',
            icon: $this->icon(),
            tier: $tier,
            subject: $model,
        );
    }
}
`````

### `app/Services/Search/Sources/ChampionshipSource.php`

`````php
<?php

namespace App\Services\Search\Sources;

use App\Models\TeamChampionship;
use App\Services\Search\SearchContext;
use App\Services\Search\SearchResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** بطولات الفرق: نفس شرط /team-championships (public()). */
class ChampionshipSource extends EloquentSource
{
    private const PHASES = [
        TeamChampionship::PHASE_UPCOMING => 'قريبًا',
        TeamChampionship::PHASE_LIVE => 'مباشرة',
        TeamChampionship::PHASE_ENDED => 'انتهت',
    ];

    public function type(): string
    {
        return 'championships';
    }

    public function label(): string
    {
        return 'بطولات الفرق';
    }

    public function icon(): string
    {
        return 'flag';
    }

    protected function table(): string
    {
        return 'team_championships';
    }

    protected function column(): string
    {
        return 'team_championships.title';
    }

    protected function baseQuery(SearchContext $context): Builder
    {
        return TeamChampionship::query()
            ->select('team_championships.id', 'team_championships.slug', 'team_championships.title', 'team_championships.status', 'team_championships.starts_at', 'team_championships.ends_at')
            ->public();
    }

    protected function tieBreak(Builder $query): Builder
    {
        return $query->orderByDesc('team_championships.starts_at')->orderByDesc('team_championships.id');
    }

    protected function toResult(Model $model, int $tier): SearchResult
    {
        /** @var TeamChampionship $model */
        return new SearchResult(
            type: $this->type(),
            title: (string) $model->title,
            url: route('team-championships.show', $model),
            subtitle: $model->starts_at?->format('Y-m-d'),
            badge: $model->status === TeamChampionship::STATUS_COMPLETED ? 'مكتملة' : (self::PHASES[$model->phase()] ?? null),
            icon: $this->icon(),
            tier: $tier,
            subject: $model,
        );
    }
}
`````

### `app/Services/Search/Sources/CompetitionSource.php`

`````php
<?php

namespace App\Services\Search\Sources;

use App\Models\CompetitiveEvent;
use App\Services\Search\SearchContext;
use App\Services\Search\SearchResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** المنافسات: نفس شرط /competitions (public(): منشورة أو مكتملة). المسوّدة والملغاة لا تظهر. */
class CompetitionSource extends EloquentSource
{
    private const PHASES = [
        CompetitiveEvent::PHASE_UPCOMING => 'قريبًا',
        CompetitiveEvent::PHASE_LIVE => 'مباشر',
        CompetitiveEvent::PHASE_ENDED => 'انتهت',
        CompetitiveEvent::PHASE_COMPLETED => 'مكتملة',
    ];

    public function type(): string
    {
        return 'competitions';
    }

    public function label(): string
    {
        return 'المنافسات';
    }

    public function icon(): string
    {
        return 'trophy';
    }

    protected function table(): string
    {
        return 'competitive_events';
    }

    protected function column(): string
    {
        return 'competitive_events.title';
    }

    protected function baseQuery(SearchContext $context): Builder
    {
        return CompetitiveEvent::query()
            ->select('competitive_events.id', 'competitive_events.slug', 'competitive_events.title', 'competitive_events.status', 'competitive_events.starts_at', 'competitive_events.ends_at', 'competitive_events.participants_count')
            ->public();
    }

    protected function tieBreak(Builder $query): Builder
    {
        return $query->orderByDesc('competitive_events.starts_at')->orderByDesc('competitive_events.id');
    }

    protected function toResult(Model $model, int $tier): SearchResult
    {
        /** @var CompetitiveEvent $model */
        return new SearchResult(
            type: $this->type(),
            title: (string) $model->title,
            url: route('competitions.show', $model),
            subtitle: $model->starts_at?->format('Y-m-d'),
            badge: self::PHASES[$model->phase()] ?? null,
            icon: $this->icon(),
            tier: $tier,
            subject: $model,
        );
    }
}
`````

### `app/Services/Search/Sources/EloquentSource.php`

`````php
<?php

namespace App\Services\Search\Sources;

use App\Services\Search\Contracts\SearchSource;
use App\Services\Search\PartialProbe;
use App\Services\Search\SearchContext;
use App\Services\Search\SearchQuery;
use App\Services\Search\SearchResult;
use App\Services\Search\TextFold;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * E27: أساس مصادر البحث. يُطبِّق المطابقة والترتيب بالصلة ثم يترك للمصدر قاعدة الظهور والأعمدة والترتيب الثابت.
 *
 * المطابقة (WHERE):
 *   أ) بادئة على **العمود الخام** (يستعمل الفهرس): col LIKE 'مطوي%' OR col LIKE 'خام-صغير%'.
 *   ب) فقط إذا كان الجدول صغيرًا (PartialProbe): كل كلمة من المصطلح موجودة في العمود المطوي: fold(col) LIKE '%كلمة%' (AND بين الكلمات).
 * الترتيب بالصلة (يُحسب لصفوف المطابقة فقط): 0 تطابق تام، 1 بادئة، 2 بادئة كلمة، 3 جزئي — ثم ترتيب المصدر الثابت.
 * كل القيم مربوطة بـbindings (حتى % و _ تُهرَّب)؛ ما يُركَّب نصًّا هو اسم العمود المثبَّت بالكود بعد تحقق صارم فقط.
 */
abstract class EloquentSource implements SearchSource
{
    public function __construct(protected PartialProbe $probe) {}

    abstract protected function table(): string;

    /** عمود البحث الأساسي (مؤهَّل بالجدول). */
    abstract protected function column(): string;

    /** الاستعلام الأساسي: قاعدة الظهور + الأعمدة المختارة صراحة (لا select *). */
    abstract protected function baseQuery(SearchContext $context): Builder;

    /** ترتيب ثابت بعد الصلة (لقطع التعادل بقرار قاطع). */
    abstract protected function tieBreak(Builder $query): Builder;

    abstract protected function toResult(Model $model, int $tier): SearchResult;

    public function minLength(SearchContext $context): int
    {
        return max(1, (int) config('search.min_length', 2));
    }

    public function search(SearchQuery $query, SearchContext $context, int $limit, int $offset = 0): Collection
    {
        if ($query->isEmpty() || ! $query->meets($this->minLength($context))) {
            return collect();
        }

        $builder = $this->applyRelevance($this->baseQuery($context), $query);

        return $this->tieBreak($builder)
            ->offset(max(0, $offset))->limit(max(1, $limit))
            ->get()
            ->map(fn (Model $model) => $this->toResult($model, (int) $model->getAttribute('search_tier')))
            ->values();
    }

    protected function applyRelevance(Builder $builder, SearchQuery $query): Builder
    {
        $column = TextFold::identifier($this->column());
        $partial = $this->probe->allowed($this->type(), $this->table());
        $prefixes = array_values(array_unique([$query->foldedPrefixPattern(), $query->rawPrefixPattern()]));
        $rawLike = $column." like ? escape '!'";
        $fold = TextFold::sqlFold($column);
        $foldLike = $fold." like ? escape '!'";

        $builder->where(function (Builder $where) use ($prefixes, $rawLike, $foldLike, $partial, $query) {
            foreach ($prefixes as $pattern) {
                $where->orWhereRaw($rawLike, [$pattern]);
            }

            if ($partial) {
                $where->orWhere(function (Builder $all) use ($foldLike, $query) {
                    foreach ($query->tokenPatterns() as $pattern) {
                        $all->whereRaw($foldLike, [$pattern]);
                    }
                });
            }
        });

        if ($partial) {
            $tierSql = 'CASE WHEN '.$fold.' = ? THEN 0 WHEN '.$foldLike.' THEN 1 WHEN '.$foldLike.' THEN 2 ELSE 3 END as search_tier';
            $tierBindings = [$query->folded, $query->foldedPrefixPattern(), $query->foldedWordPattern()];
        } else {
            $tierSql = 'CASE WHEN LOWER('.$column.') = ? THEN 0 WHEN '.$rawLike.' OR '.$rawLike.' THEN 1 ELSE 3 END as search_tier';
            $tierBindings = [mb_strtolower($query->display, 'UTF-8'), $query->foldedPrefixPattern(), $query->rawPrefixPattern()];
        }

        return $builder->selectRaw($tierSql, $tierBindings)->orderBy('search_tier');
    }
}
`````

### `app/Services/Search/Sources/PlayerSource.php`

`````php
<?php

namespace App\Services\Search\Sources;

use App\Models\User;
use App\Services\Search\PartialProbe;
use App\Services\Search\SearchContext;
use App\Services\Search\SearchResult;
use App\Services\Social\BlockService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * اللاعبون. الخصوصية أولًا (نفس سياسة PlayerSearchService + أشدّ للضيف):
 *  - بالاسم المعروض فقط (users.name). لا بريد ولا هاتف ولا معرّف داخلي ولا محفظة: الأعمدة المختارة صراحة.
 *  - لا يظهر إلا من يحق للباحث رؤية ملفه: الضيف وغير الموثَّق يرون ملفات «عام» فقط؛ الموثَّق يرى «عام» و«للأعضاء»؛ «خاص» لا يُكتشف أبدًا.
 *  - يُستبعد: الباحث نفسه، المجمَّد، غير الموثَّق بريده، ومن بينه وبين الباحث حظر بأي اتجاه (للموثَّق).
 *  - حدّ أدنى أعلى للضيف (config search.min_length_guest_players).
 *  - الرابط بـpublic_id (ULID) لا بالمعرّف التسلسلي.
 */
class PlayerSource extends EloquentSource
{
    public function __construct(PartialProbe $probe, protected BlockService $blocks)
    {
        parent::__construct($probe);
    }

    public function type(): string
    {
        return 'players';
    }

    public function label(): string
    {
        return 'اللاعبون';
    }

    public function icon(): string
    {
        return 'user';
    }

    public function minLength(SearchContext $context): int
    {
        return $context->isTrustedMember()
            ? max(1, (int) config('search.min_length', 2))
            : max(1, (int) config('search.min_length_guest_players', 3));
    }

    protected function table(): string
    {
        return 'users';
    }

    protected function column(): string
    {
        return 'users.name';
    }

    protected function baseQuery(SearchContext $context): Builder
    {
        $visibilities = $context->isTrustedMember() ? [User::VISIBILITY_PUBLIC, User::VISIBILITY_MEMBERS] : [User::VISIBILITY_PUBLIC];

        $query = User::query()
            ->select('users.id', 'users.name', 'users.public_id', 'users.profile_visibility')
            ->where('users.is_frozen', false)
            ->whereNotNull('users.email_verified_at')
            ->whereIn('users.profile_visibility', $visibilities);

        if ($context->viewer !== null) {
            $query->whereKeyNot($context->viewer->getKey());
        }

        if ($context->isTrustedMember()) {
            $query->whereNotIn('users.id', $this->blocks->hiddenIds($context->viewer));
        }

        return $query;
    }

    protected function tieBreak(Builder $query): Builder
    {
        return $query->orderBy('users.name')->orderBy('users.id');
    }

    protected function toResult(Model $model, int $tier): SearchResult
    {
        /** @var User $model */
        return new SearchResult(
            type: $this->type(),
            title: (string) $model->name,
            url: route('players.show', $model),
            subtitle: $model->profile_visibility === User::VISIBILITY_MEMBERS ? 'ملف للأعضاء' : null,
            badge: null,
            icon: $this->icon(),
            tier: $tier,
            subject: $model,
        );
    }
}
`````

### `app/Services/Search/Sources/PuzzleSource.php`

`````php
<?php

namespace App\Services\Search\Sources;

use App\Models\Puzzle;
use App\Services\Search\SearchContext;
use App\Services\Search\SearchResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * الأحجيات: نفس شرط قائمة /puzzles (is_active). البحث بالعنوان **فقط**: لا يلمس أبدًا answer_hash/answer_raw/hint/solution_data/choices/game_config،
 * فلا يمكن استعمال البحث كـ«أوراكل» لتخمين الجواب. الأعمدة المختارة صراحة لا تشمل أيًّا منها.
 */
class PuzzleSource extends EloquentSource
{
    private const DIFFICULTY = ['easy' => 'سهل', 'medium' => 'متوسط', 'hard' => 'صعب'];

    public function type(): string
    {
        return 'puzzles';
    }

    public function label(): string
    {
        return 'الأحجيات';
    }

    public function icon(): string
    {
        return 'puzzle';
    }

    protected function table(): string
    {
        return 'puzzles';
    }

    protected function column(): string
    {
        return 'puzzles.title';
    }

    protected function baseQuery(SearchContext $context): Builder
    {
        return Puzzle::query()
            ->select('puzzles.id', 'puzzles.title', 'puzzles.difficulty', 'puzzles.puzzle_category_id')
            ->with('category:id,name')
            ->where('puzzles.is_active', true);
    }

    protected function tieBreak(Builder $query): Builder
    {
        return $query->orderByDesc('puzzles.id');
    }

    protected function toResult(Model $model, int $tier): SearchResult
    {
        /** @var Puzzle $model */
        return new SearchResult(
            type: $this->type(),
            title: (string) $model->title,
            url: route('puzzles.show', $model),
            subtitle: $model->category?->name,
            badge: self::DIFFICULTY[$model->difficulty] ?? null,
            icon: $this->icon(),
            tier: $tier,
            subject: $model,
        );
    }
}
`````

### `app/Services/Search/Sources/SeasonSource.php`

`````php
<?php

namespace App\Services\Search\Sources;

use App\Models\Season;
use App\Services\Search\SearchContext;
use App\Services\Search\SearchResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** المواسم: نفس شرط /seasons (is_published) — العنوان من حملة الموسم. المواسم غير المنشورة لا تظهر لأحد هنا (المعاينة للإدارة تبقى من لوحتها). */
class SeasonSource extends EloquentSource
{
    public function type(): string
    {
        return 'seasons';
    }

    public function label(): string
    {
        return 'المواسم';
    }

    public function icon(): string
    {
        return 'calendar';
    }

    protected function table(): string
    {
        return 'seasons';
    }

    protected function column(): string
    {
        return 'campaigns.title';
    }

    protected function baseQuery(SearchContext $context): Builder
    {
        return Season::query()
            ->join('campaigns', 'campaigns.id', '=', 'seasons.campaign_id')
            ->select('seasons.id', 'seasons.slug', 'seasons.is_featured', 'campaigns.title as campaign_title', 'campaigns.is_active as campaign_active', 'campaigns.starts_at as campaign_starts_at', 'campaigns.ends_at as campaign_ends_at')
            ->where('seasons.is_published', true);
    }

    protected function tieBreak(Builder $query): Builder
    {
        return $query->orderByDesc('seasons.is_featured')->orderByDesc('seasons.id');
    }

    protected function toResult(Model $model, int $tier): SearchResult
    {
        /** @var Season $model */
        $starts = $model->getAttribute('campaign_starts_at');
        $ends = $model->getAttribute('campaign_ends_at');
        $badge = match (true) {
            ! (bool) $model->getAttribute('campaign_active') => 'غير متاح',
            $starts !== null && \Illuminate\Support\Carbon::parse($starts)->isFuture() => 'قريبًا',
            $ends !== null && \Illuminate\Support\Carbon::parse($ends)->isPast() => 'انتهى',
            default => 'مباشر',
        };

        return new SearchResult(
            type: $this->type(),
            title: (string) $model->getAttribute('campaign_title'),
            url: route('seasons.show', $model),
            subtitle: $model->is_featured ? 'موسم مميّز' : null,
            badge: $badge,
            icon: $this->icon(),
            tier: $tier,
            subject: $model,
        );
    }
}
`````

### `app/Services/Search/Sources/TeamSource.php`

`````php
<?php

namespace App\Services\Search\Sources;

use App\Models\Team;
use App\Services\Search\SearchContext;
use App\Services\Search\SearchResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * الفرق: نفس شرط دليل /teams (directory(): مفعَّل وعام). الفريق الخاص أو المعطَّل لا يُكتشف بالبحث حتى لأعضائه (يبلغونه من «فريقي»).
 * لا يُعرض اسم المالك ولا الأعضاء ولا سياسة الانضمام الداخلية؛ فقط الاسم وعدد الأعضاء.
 */
class TeamSource extends EloquentSource
{
    public function type(): string
    {
        return 'teams';
    }

    public function label(): string
    {
        return 'الفرق';
    }

    public function icon(): string
    {
        return 'team';
    }

    protected function table(): string
    {
        return 'teams';
    }

    protected function column(): string
    {
        return 'teams.name';
    }

    protected function baseQuery(SearchContext $context): Builder
    {
        return Team::query()
            ->select('teams.id', 'teams.slug', 'teams.name', 'teams.members_count')
            ->directory();
    }

    protected function tieBreak(Builder $query): Builder
    {
        return $query->orderByDesc('teams.members_count')->orderByDesc('teams.id');
    }

    protected function toResult(Model $model, int $tier): SearchResult
    {
        /** @var Team $model */
        return new SearchResult(
            type: $this->type(),
            title: (string) $model->name,
            url: route('teams.show', $model),
            subtitle: number_format((int) $model->members_count).' عضو',
            badge: null,
            icon: $this->icon(),
            tier: $tier,
            subject: $model,
        );
    }
}
`````

### `app/Services/Search/TextFold.php`

`````php
<?php

namespace App\Services\Search;

use InvalidArgumentException;

/**
 * E27: مصدر واحد لتطبيع نص البحث (للمُدخَل بـPHP ولطيّ العمود بـSQL) كي لا ينحرفا.
 *
 * على جانب PHP (المُدخَل): NFKC إن توفّرت intl، حذف المحارف غير المرئية (صفر العرض، اتجاه، BOM) وتحويل المسافات/التحكم لمسافة واحدة، أحرف صغيرة،
 * حذف التطويل والتشكيل، توحيد الألف (أ إ آ ٱ ← ا) والألف المقصورة (ى ← ي) والتاء المربوطة (ة ← ه) والهمزة على واو/ياء، توحيد الأرقام العربية/الفارسية إلى ASCII،
 * وتوحيد الحرف التركي ı/İ إلى i.
 *
 * على جانب SQL (العمود): نفس التحويلات الحرفية المتاحة بـREPLACE متداخل على LOWER(العمود)، وتُستعمل **فقط** داخل طبقة المطابقة الجزئية المحدودة بحجم الجدول
 * (انظر config/search.php: partial_match_max_id). الثوابت حرفية داخل الكود؛ المعرّف (اسم العمود) يُتحقَّق منه بنمط صارم ولا يصل إليه أي مُدخَل مستخدم.
 */
final class TextFold
{
    /** @var array<string, string> */
    private const LETTER_MAP = [
        "\u{0623}" => "\u{0627}", // أ → ا
        "\u{0625}" => "\u{0627}", // إ → ا
        "\u{0622}" => "\u{0627}", // آ → ا
        "\u{0671}" => "\u{0627}", // ٱ → ا
        "\u{0649}" => "\u{064A}", // ى → ي
        "\u{0629}" => "\u{0647}", // ة → ه
        "\u{0624}" => "\u{0648}", // ؤ → و
        "\u{0626}" => "\u{064A}", // ئ → ي
        "\u{0131}" => 'i',        // ı → i
    ];

    /** تطويل + تشكيل شائع (فتحتان…سكون) + ألف خنجرية: يُحذف على جانبي PHP وSQL. */
    private const STRIP = ["\u{0640}", "\u{064B}", "\u{064C}", "\u{064D}", "\u{064E}", "\u{064F}", "\u{0650}", "\u{0651}", "\u{0652}", "\u{0670}"];

    /** @var array<string, string> */
    private const DIGITS = [
        "\u{0660}" => '0', "\u{0661}" => '1', "\u{0662}" => '2', "\u{0663}" => '3', "\u{0664}" => '4', "\u{0665}" => '5', "\u{0666}" => '6', "\u{0667}" => '7', "\u{0668}" => '8', "\u{0669}" => '9',
        "\u{06F0}" => '0', "\u{06F1}" => '1', "\u{06F2}" => '2', "\u{06F3}" => '3', "\u{06F4}" => '4', "\u{06F5}" => '5', "\u{06F6}" => '6', "\u{06F7}" => '7', "\u{06F8}" => '8', "\u{06F9}" => '9',
    ];

    /** يحوّل نصًا إلى شكله المطوي. نص غير صالح UTF-8 ← نص فارغ (يُعامَل كقصير جدًا). */
    public static function fold(?string $text): string
    {
        $s = (string) $text;

        if ($s === '' || ! mb_check_encoding($s, 'UTF-8')) {
            return '';
        }

        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($s, \Normalizer::FORM_KC);
            if (is_string($normalized)) {
                $s = $normalized;
            }
        }

        $s = str_replace("\u{0130}", 'i', $s);                                             // İ قبل خفض الحالة (وإلا تصير i + نقطة)
        $s = str_replace("\u{200B}", ' ', $s);                                              // مسافة صفرية العرض = فاصل كلمات (لا تُلصق الكلمتين)
        $s = preg_replace('/\p{Cf}/u', '', $s) ?? '';                                       // ZWJ/ZWNJ، علامات الاتجاه، BOM
        $s = preg_replace('/[\p{Cc}\p{Z}]+/u', ' ', $s) ?? '';                              // تحكم + أي فاصل ← مسافة
        $s = mb_strtolower($s, 'UTF-8');
        $s = preg_replace('/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}\x{0640}\x{0307}]/u', '', $s) ?? '';
        $s = strtr($s, self::LETTER_MAP);
        $s = strtr($s, self::DIGITS);

        return trim(preg_replace('/\s+/u', ' ', $s) ?? '');
    }

    /** يهرب رموز LIKE (% _ ومحرف الهروب !) فتصير حرفية. يُستعمل مع `escape '!'` دائمًا. */
    public static function escapeLike(string $text): string
    {
        return strtr($text, ['!' => '!!', '%' => '!%', '_' => '!_']);
    }

    /**
     * تعبير SQL يطوي العمود بنفس تحويلات الحروف (بلا الأرقام ولا NFKC). المعرّف **يجب** أن يطابق `table.column` أو `column` بأحرف صغيرة/أرقام/شرطة سفلية فقط.
     */
    public static function sqlFold(string $identifier): string
    {
        $expression = 'LOWER('.self::identifier($identifier).')';

        foreach (self::STRIP as $char) {
            $expression = "REPLACE({$expression}, '{$char}', '')";
        }

        foreach (self::LETTER_MAP as $from => $to) {
            $expression = "REPLACE({$expression}, '{$from}', '{$to}')";
        }

        return $expression;
    }

    /** يتحقق من معرّف عمود ويعيده كما هو؛ يرمي استثناءً لأي شيء آخر (لا يصل مُدخَل مستخدم هنا أصلًا). */
    public static function identifier(string $identifier): string
    {
        if (! preg_match('/^[a-z_][a-z0-9_]*(\.[a-z_][a-z0-9_]*)?$/', $identifier)) {
            throw new InvalidArgumentException('Invalid SQL identifier.');
        }

        return $identifier;
    }
}
`````

### `config/search.php`

`````php
<?php

/*
| E27 - البحث العام والاكتشاف والرائج والتوصيات.
| كل القيم قابلة للضبط بالبيئة أو هنا؛ لا أسرار. السائق الحالي database (بلا محرك بحث خارجي)؛ التبديل لمحرك آخر = تنفيذ جديد لـ SearchBackend (docs/search-discovery-e27.md).
*/
return [
    // database = LIKE/تطبيع على قاعدة البيانات الحالية. أي قيمة غير معروفة تسقط إلى database (لا تعطّل الموقع).
    'driver' => env('SEARCH_DRIVER', 'database'),

    // طول المصطلح بعد التطبيع (حروف لا بايتات). اللاعبون لهم حدّ أدنى أعلى للضيوف (يقلّل مسح الأسماء بالتخمين).
    'min_length' => (int) env('SEARCH_MIN_LENGTH', 2),
    'min_length_guest_players' => (int) env('SEARCH_MIN_LENGTH_GUEST_PLAYERS', 3),
    'max_length' => 64,
    'max_tokens' => 5,

    // النتائج: صفحة البحث الكاملة وتبويب النوع الواحد (ترقيم بسيط، بلا COUNT) وسقف عمق الصفحات.
    'all_per_type' => 5,
    'page_size' => 12,
    'max_page' => 5,
    'guest_max_page' => 3,

    // الاقتراحات الفورية (autocomplete): سقف لكل نوع وسقف كلي.
    'suggest_per_type' => 3,
    'suggest_total' => 9,

    // ترتيب الأنواع الافتراضي في النتائج (المجموعة التي فيها تطابق تام تتقدّم).
    'types' => ['puzzles', 'campaigns', 'seasons', 'competitions', 'championships', 'teams', 'players'],

    // المطابقة الجزئية (بالوسط/بأي كلمة + طيّ الحروف العربية بـREPLACE) مسح كامل للجدول؛ لذا لا تُفعَّل إلا حين يكون أكبر معرّف بالجدول ≤ هذا الحدّ.
    // فوقه: بحث ببادئة العمود فقط (يستعمل الفهرس). هذا هو مؤشر الانتقال لمحرك بحث/FULLTEXT (انظر المستند).
    'partial_match_max_id' => (int) env('SEARCH_PARTIAL_MAX_ID', 10000),
    'partial_match_max_id_by_type' => [
        // 'players' => 20000,
    ],
    'partial_probe_ttl' => 600,

    // مراتب الصلة (الأصغر أعلى): تطابق تام ثم بادئة ثم بادئة كلمة ثم جزئي. لا تغيّرها دون تحديث الاختبارات.
    'rank_tiers' => ['exact' => 0, 'prefix' => 1, 'word_prefix' => 2, 'partial' => 3],

    'trending' => [
        'window_days' => (int) env('SEARCH_TRENDING_WINDOW_DAYS', 7),
        'cache_ttl' => (int) env('SEARCH_TRENDING_TTL', 300),
        // اللاعبون المميَّزون (لا عدد المحاولات): أوزان تراكمية لآخر يوم / 3 أيام / كامل النافذة، + وزن إضافي لمن حلّوا الأحجية.
        'recency_weights' => ['d1' => 4, 'd3' => 2, 'd7' => 1],
        'solver_weight' => 2,
        'min_unique_players' => (int) env('SEARCH_TRENDING_MIN_PLAYERS', 2),
        'limit' => 8,
        'pool' => 40,
        'binary_search_steps' => 40,
    ],

    'recommendations' => [
        'limit' => 8,
        'pool' => 60,
        'history_cap' => 500,
        'exploration_share' => 0.25,
        'new_content_days' => 14,
        'weights' => ['category' => 5.0, 'difficulty' => 2.0, 'popularity' => 1.5, 'novelty' => 1.0],
    ],

    'discover' => [
        'section_limit' => 8,
        'season_limit' => 4,
        'campaign_limit' => 6,
        'event_limit' => 6,
        'team_limit' => 6,
    ],
];
`````

### `database/migrations/2026_10_19_000001_add_e27_search_indexes.php`

`````php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E27 - فهرسان فقط، كلٌّ لاستعلام بحث فعلي بادئته index-friendly (col LIKE 'prefix%'):
 *
 *  users(name)               - بحث اللاعبين بالبادئة (الجدول الأكبر نموًا بين مصادر البحث). بدونه يمسح كل المستخدمين لكل ضغطة اقتراح. كلفة الكتابة: عند التسجيل وتغيير الاسم فقط (نادر).
 *  puzzles(is_active, title) - بحث الأحجيات: المساواة على is_active ثم مدى على title؛ يطابق شرط قائمة /puzzles (is_active=1). كلفة الكتابة: عند نشر/تعديل أحجية (إداري، نادر).
 *
 * غير مشمول عمدًا (موثّق بـdocs/search-discovery-e27.md):
 *  - لا فهرس على puzzle_attempts: «الرائج» يبدأ نافذته بالبحث الثنائي على المفتاح الأساسي ضمن حساب مخزَّن 5 دقائق، فلا كلفة كتابة على أسخن جدول (قرار E24 باقٍ).
 *  - لا فهرس على campaigns/competitive_events/team_championships/teams(name): جداول صغيرة بطبيعتها (مئات صفوف)، والمطابقة الجزئية عليها مسح بحدّ معرّف أقصى (config search.partial_match_max_id).
 *  - لا FULLTEXT: الانتقال له أو لمحرك خارجي مؤشره بالمستند (حجم الجداول + زمن الاستعلام المقيس).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasIndex('users', 'users_name_search_index')) {
            Schema::table('users', fn (Blueprint $table) => $table->index('name', 'users_name_search_index'));
        }

        if (! Schema::hasIndex('puzzles', 'puzzles_active_title_search_index')) {
            Schema::table('puzzles', fn (Blueprint $table) => $table->index(['is_active', 'title'], 'puzzles_active_title_search_index'));
        }
    }

    public function down(): void
    {
        Schema::table('puzzles', function (Blueprint $table) {
            if (Schema::hasIndex('puzzles', 'puzzles_active_title_search_index')) {
                $table->dropIndex('puzzles_active_title_search_index');
            }
        });

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasIndex('users', 'users_name_search_index')) {
                $table->dropIndex('users_name_search_index');
            }
        });
    }
};
`````

### `docs/search-discovery-e27.md`

`````markdown
# البحث العام والاكتشاف والرائج والتوصيات (E27)

> بحث عربي-أولًا عبر الأحجيات والحملات والمواسم والمنافسات وبطولات الفرق والفرق واللاعبين، ومركز اكتشاف، وإشارات «الرائج»، وتوصيات شخصية **بخصوصية أولًا**. كله قراءة فقط: لا يمسّ نقاطًا ولا XP ولا مكافآت ولا أهلية ولا احتمالات ولا نتيجة.
> بلا محرك بحث خارجي، بلا Scout، بلا ذكاء اصطناعي ولا بحث متجهي، بلا ترتيب مدفوع/برعاية، بلا حزمة Composer أو npm جديدة. التحويل لاحقًا لمحرك خارجي = تنفيذ جديد لواجهة `SearchBackend` (انظر [مؤشر الانتقال](#مؤشر-الانتقال-لمحرك-بحث-خارجي)).
> يكمّل [performance-e24](performance-e24.md) (قرار عدم فهرسة `puzzle_attempts` باقٍ) و[security-e26](security-e26.md).

## ما أُضيف

| المسار | الوظيفة | حد المعدّل |
| --- | --- | --- |
| `GET /search` | صفحة البحث الكاملة (نموذج GET يعمل بلا JavaScript): الكل أو تبويب نوع واحد | `global-search` |
| `GET /search/suggest` | JSON للاقتراحات الفورية (يغذّي التراكب) | `search-suggest` |
| `GET /discover` | مركز الاكتشاف: أقسام محدودة + «تابع» و«مقترحة لك» للمسجَّل | `discover` |

- زر البحث في الرأس (رابط حقيقي إلى `/search`، خارج التنقل الرئيسي فلا يغيّر أيقوناته الست) + تراكب اقتراحات يفتحه الزر أو `/` أو `Ctrl/⌘+K`.
- عنصر «اكتشف» أول قائمة «استكشف» للمسجَّل. الضيف: التراكب يعرض رابط الاكتشاف عند الخمول (عقد تنقل الضيف مثبّت باختبار E22 فلم يُغيَّر).
- **فهرسان فقط** (هجرة واحدة، ملف جديد): `users(name)` و`puzzles(is_active, title)`.

## المعمارية

```
SearchController ─► GlobalSearchService ─► SearchBackend (واجهة)
                                              └─ DatabaseSearchBackend ─► 7 مصادر (EloquentSource)
                                                   Puzzle · Campaign · Season · Competition · Championship · Team · Player
```

- `SearchBackend` يُربط في `AppServiceProvider::register()` حسب `config('search.driver')`؛ أي قيمة غير معروفة تسقط إلى `database` (لا يتعطل الموقع).
- كل مصدر يملك **قاعدة ظهوره** (`baseQuery`) ويعيد `SearchResult` مسطّحًا (عنوان، رابط **نسبي**، عنوان فرعي، شارة، أيقونة، مرتبة الصلة). لا نموذج Eloquent يصل للعرض أو JSON.
- `GlobalSearchService` يطبّع المصطلح، يطبّق الحدّ الأدنى، يجمع الأنواع ويرتّب المجموعات (التي فيها تطابق أفضل أولًا ثم الترتيب الإعدادي)، ويرقّم نوعًا واحدًا بترقيم بسيط (بلا `COUNT`).

## التطبيع (`TextFold`) — مصدر واحد للحقيقة

تُطبَّق الدالة نفسها على المصطلح (PHP) وعلى العمود (SQL) كي يتطابق الطرفان:

| التحويل | مثال |
| --- | --- |
| NFKC (إن توفر `intl`)، صفر العرض ZWSP → مسافة، حذف محارف التنسيق/التحكم | `لُغ​ز` = `لغز` |
| تصغير الأحرف اللاتينية؛ `ı`/`İ` التركية → `i` | `ISTANBUL` = `ıstanbul` |
| حذف التطويل والحركات والألف الخنجرية | `أَحْجِيَّةٌ` = `احجيه` |
| توحيد الألف `أإآٱ→ا`، `ى→ي`، `ة→ه`، `ؤ→و`، `ئ→ي` | `الأحجية` = `الاحجيه` |
| أرقام عربية-هندية/فارسية → ASCII (PHP فقط) | `٢٠٢٦` = `2026` |
| ضغط المسافات؛ حدّ 64 حرفًا و5 كلمات (الزائد يُقصّ لا يُرفض) | |
| «ال» التعريف تُسقط من الكلمات ≥ 5 أحرف **للنمط الجزئي فقط** | `الاحجية` يطابق `أحجية` |

`TextFold::identifier()` يقبل فقط `^[a-z_][a-z0-9_]*(\.[a-z_][a-z0-9_]*)?$` ويرمي استثناءً غير ذلك: أسماء الأعمدة ثابتة بالشيفرة لا من المستخدم. القيم لا تدخل SQL إلا ربطًا (bindings). `%` و`_` و`!` حرفية (`ESCAPE '!'`).

## الصلة والمطابقة

المراتب (الأصغر أعلى): **0 تطابق تام**، **1 بادئة**، **2 بادئة كلمة**، **3 جزئي**. ثم كاسر تعادل المصدر (غالبًا الأحدث). كلمات متعددة = كلها مطلوبة بأي ترتيب.

- **وضع كامل** (الجدول صغير: أكبر معرّف ≤ `search.partial_match_max_id`، افتراضيًا 10000): مطابقة بادئة العمود الخام **أو** مطابقة كل الكلمات بعد طيّ الحروف (REPLACE متداخل) — يلتقط وسط الكلمة والأشكال العربية المتقاربة. مسح كامل للجدول لكنه محدود الحجم.
- **وضع كبير** (الجدول تجاوز الحد): بادئة العمود فقط (`col LIKE 'x%'` على العمود العاري، قابل لاستعمال الفهرس)؛ لا وسط كلمة ولا طيّ حروف إلا ما يفعله ترتيب MySQL. هذا **حدّ موثّق**، ومؤشر الانتقال لمحرك بحث.
- مجسّ الحجم `PartialProbe` = `MAX(id)` مخزَّن 10 دقائق (`CachedSnapshot`)، لكل جدول. جدول فارغ يبقى بالوضع الكامل (مسحه مجاني).

## الظهور والخصوصية لكل نوع

| النوع | يظهر فقط | ملاحظات |
| --- | --- | --- |
| الأحجيات | `is_active` | البحث **بالعنوان فقط**؛ لا يمسّ الإجابة ولا التلميح ولا الخيارات ولا `solution_data` ولا `game_config` ولا نص السؤال (منع «بحث كأوراكل للإجابة») |
| الحملات | فعّالة داخل نافذتها، **ولا يقوم عليها موسم منشور** | الحملة التي لها موسم منشور تظهر كموسم فقط (لا بطاقتان لشيء واحد) |
| المواسم | `is_published` + حملتها فعّالة | العنوان من الحملة |
| المنافسات / بطولات الفرق | منشورة أو مكتملة (`public()`) | المسودات لا تظهر لأحد، حتى الإدارة |
| الفرق | `directory()` = عام + فعّال | الفريق الخاص غير قابل للاكتشاف حتى لأعضائه (صفحته مباشرة) |
| اللاعبون | الاسم فقط + رابط ULID `public_id` | بلا بريد ولا معرّف رقمي ولا رصيد. **الضيف/غير الموثَّق**: ملفات public فقط؛ **الموثَّق**: public + members؛ **private لا يظهر لأحد**. تُستثنى النفس والمجمَّد وغير الموثَّق والمحظور من الجهتين (`BlockService::hiddenIds`) دون أي إشارة لوجود حظر |

الضيف يحتاج 3 أحرف للاعبين (2 لغيرهم). تعداد الأسماء المعلنة عامًّا ممكن نظريًا ويُخفَّف بالحد الأدنى + حد المعدّل + سقف العمق، لا يُلغى (انظر الحدود المعروفة).

## الحدود ومعدلات الطلب

| الحد | القيمة |
| --- | --- |
| صفحة البحث | مسجَّل 60/دقيقة لكل **مستخدم**؛ ضيف 20/دقيقة لكل IP |
| الاقتراحات | مسجَّل 120/دقيقة + 16 لكل ثانيتين؛ ضيف 40/دقيقة + 10 لكل ثانيتين |
| الاكتشاف | 90/دقيقة (مستخدم أو IP) |
| الاقتراحات | 3 لكل نوع و9 إجمالًا، مع رابط «عرض المزيد» |
| صفحة نوع واحد | 12 نتيجة، عمق أقصى 5 صفحات (ضيف 3)، بلا `COUNT` |
| كل نوع بصفحة «الكل» | 5 |

الاقتراحات `Cache-Control: no-store, private`. لا تُسجَّل نصوص البحث في أي قاعدة أو سجل أو كاش (يُختبر)، ولا يوجد «سجل بحث» للمستخدم. الاستثناء الوحيد سلوك الإطار القياسي: عنوان آخر صفحة GET (بما فيها `/search?q=…`) يُحفظ في **جلسة صاحبه نفسه** (`_previous.url`) لخدمة `back()` ويُستبدل بالصفحة التالية؛ طلبات الاقتراحات (ajax) لا تُحفظ.

## الاكتشاف (`/discover`)

أقسام محدودة (كل قسم استعلام واحد بـ`LIMIT`) من الأنظمة القائمة بشروط ظهورها العامة نفسها: **تابع** (للمسجَّل: أحجيات بدأها ولم يستنفد محاولاتها + حملات بدأها ولم يُكملها)، **مقترحة لك** (للمسجَّل)، **الرائج هذا الأسبوع**، **أحجية اليوم**، **جديد الأحجيات**، **المواسم**، **حملات متاحة**، **منافسات** (للمسجَّل: التي لم يسجّل بها؛ للضيف: الرائجة)، **بطولات الفرق**، **فرق** (للمسجَّل بلا فريق: مفتوحة للانضمام؛ وإلا: صاعدة)، **حملات لم تبدأها**، وشرائح التصنيفات (12 حدًّا أقصى، الفعّالة فقط). قسم فارغ لا يُعرض؛ صفحة بلا أي قسم تعرض حالة فارغة مع طريق للأحجيات. لا لاعبين ولا بيانات شخصية لآخرين في أي قسم.

## الرائج (`TrendingService`)

**الأحجيات** خلال نافذة (7 أيام):

```
score = 4·(لاعبون مميَّزون آخر يوم) + 2·(… آخر 3 أيام) + 1·(… كل النافذة) + 2·(مميَّزون حلّوها)
```

- نعدّ **لاعبين مميَّزين لا محاولات**: لاعب واحد بألف محاولة = لاعب واحد.
- فقط المحاولات المستقلة (`context_type IS NULL` — تُستثنى المنافسات والتحديات والحملات)، من حسابات **موثَّقة وغير مجمَّدة**، على أحجيات **فعّالة**. حد أدنى 2 لاعبين مميَّزين. تعادل الدرجات: المعرّف الأكبر (قاعدة حتمية).
- الأقدمية مدمجة بالأوزان التراكمية: تتراجع الأحجية بصمت حين يتوقف اللعب، بلا مهمة جدولة.
- **بلا فهرس جديد على `puzzle_attempts`**: بداية النافذة تُحدَّد ببحث ثنائي على المفتاح الأساسي (المعرّف يزداد مع الزمن) داخل حساب مخزَّن، فيقرأ الاستعلام مدى PK فقط. الافتراض: تزايد المعرّف مع الزمن (واقع الإنتاج)؛ والاستعلام الرئيسي يرشّح `created_at` أيضًا فلا تدخل صفوف قديمة حتى لو اختلّ الافتراض.
- **الكاش** (`CachedSnapshot`، 5 دقائق، حماية تدافع): معرّفات ودرجات فقط، لا أسماء ولا بيانات لاعبين. الأحجيات تُعاد قراءتها من القاعدة بشرط الظهور في كل طلب: سحب أحجية يخفيها فورًا ولو بقيت بالكاش.
- **المنافسات الرائجة**: عامة، لم تنتهِ، `participants_count ≥ الحد الأدنى`. **الفرق الصاعدة**: انضمامات جديدة بالنافذة ≥ الحد الأدنى، دليل عام فقط.
- مقاومة التلاعب: حسابات غير موثَّقة أو مجمَّدة لا تُحتسب؛ تكرار المحاولات لا يرفع؛ حد أدنى من اللاعبين المميَّزين. التلاعب الكامل بحسابات موثَّقة متعددة ممكن نظريًا وتحدّه كلفة التوثيق وتجميد الاحتيال القائم (E8/E26).

## التوصيات (`RecommendationService`)

بخصوصية أولًا، حتمية، قراءة فقط.

- **الإشارات**: تاريخ المستخدم **نفسه** من محاولاته المستقلة (مجمَّعًا لكل أحجية، سقف 500): التصنيفات (حل = 2، محاولة = 1، ×0.5 لما قبل 30 يومًا)، الصعوبة الملائمة (الأكثر حلًّا؛ الأعلى مجاورة 0.7، الباقي 0.3)، + مجاميع عامة (الرائج المخزَّن) + حداثة المحتوى (14 يومًا).
- **ما لا يُستعمل عمدًا**: لا أصدقاء ولا فرق ولا دردشة ولا ملفات خاصة. «لأن صديقك…» تكشف نشاط آخرين؛ لا بيانات شخص تدخل حساب غيره.
- **الدرجة**: `5·ألفة التصنيف + 2·ملاءمة الصعوبة + 1.5·الشعبية + 1·الحداثة` (الأوزان بالإعدادات).
- **الاستبعاد الدائم**: ما حلّه، وما استنفد محاولاته، وغير الفعّال، وما يتابعه الآن (يظهر تحت «تابع» لا «مقترحة»).
- **الاستكشاف**: 25٪ من الخانات من تصنيفات لم يجرّبها، بترتيب حتمي يومي `crc32(user_id|Y-m-d|puzzle_id)` — ثابت طوال اليوم ويتبدل غدًا، لا عشوائية. تعادل أخير بالمعرّف الأكبر.
- **بداية باردة** (بلا تاريخ): الرائج أولًا ثم الأحدث، بلا خانات استكشاف. كل عنصر يحمل «سببًا» مقروءًا («لأنك تلعب «…»»، «رائجة هذا الأسبوع»، «تصنيف جديد لك»…).
- **بلا كاش لكل مستخدم** (حداثة فورية بعد الحل، وصفر خطر خلط حسابين). الكاش الوحيد هو لقطة الرائج العامة.
- تُقترح أيضًا: حملات لم تُكمل، حملات لم تبدأ، منافسات عامة لم يسجّل بها، فرق مفتوحة لمن بلا فريق.

## تجربة الاستخدام

- **التراكب**: `role=dialog` + `combobox`/`listbox`/`option`، `aria-expanded`/`aria-activedescendant`/`aria-selected`، منطقة حالة حيّة، **بلا `<form>`** (صفحات القراءة العامة تُدقَّق على غيابه). الكتابة بتأخير 220ms وإلغاء الطلب السابق (`AbortController`) وتجاهل الردود القديمة، وإيقاف 5 ثوانٍ عند 429، ولا طلبات أثناء تركيب IME.
- **لوحة المفاتيح**: `↑/↓` تنقّل، `Enter` يفتح المحدَّد أو صفحة البحث الكاملة، `Esc` يغلق ويعيد التركيز لمن فتحه، `Tab` محصور بالحوار، `/` و`Ctrl/⌘+K` للفتح (لا يُعترض `/` داخل حقل).
- **الجوال**: شاشة كاملة؛ سطح المكتب: لوحة وسطية ≤ 75vh؛ أهداف لمس ≥ 40px؛ `enterkeyhint="search"`.
- **RTL**: خصائص منطقية فقط (`ms-`/`text-start`)، لا `left/right` فيزيائية. حركة مخفَّضة محترمة.
- **أمان الواجهة**: النصوص بـ`x-text` (textContent) فقط، لا `innerHTML`/`eval`/تخزين محلي/`console`. الروابط تمرّ بـ`safePath`: مسار نسبي بشرطة مائلة واحدة، بلا `\` ولا أي مسافة/محرف تحكم (المتصفح يحذف التبويب والسطر الجديد فيحوّل `/<tab>/x` إلى `//x`). والخادم يضمن بدوره شرطة واحدة في كل رابط يُرسله.
- **بلا JavaScript**: الزر رابط إلى `/search`، والصفحة نموذج GET كامل (المصطلح والنوع والصفحة في الرابط).

## التحليلات ولوحة الإدارة — قرار: لا تخزين

لا جدول ولا سجل لنصوص البحث ولا لمعدلات النقر. السبب: نص البحث بيانات شخصية محتملة (أسماء، بريد، أرقام)، ولا حاجة تشغيلية تبرّر تخزينه الآن. وصفر هجرات جديدة عدا الفهرسين. القياس التشغيلي يكفيه سجلات الخادم القائمة (مسارات ورموز حالة بلا استعلامات) وحدود المعدّل (E24/E26). **لوحة «رؤى البحث» للإدارة مؤجلة** ريثما يُقرَّر نموذج تخزين مجهَّل (مثلاً عدّادات تجميعية لمصطلحات مقنَّعة بعتبة k-anonymity).

## الأداء (SQLite محلي، PHP 8.3، 3000 أحجية / 5000 لاعب / 30000 محاولة)

| العملية | p50 تقريبي |
| --- | --- |
| اقتراحات — وضع كامل (الجداول ≤ 10000 معرّف) | ≈ 71ms |
| اقتراحات — وضع كبير (بادئة) | ≈ 9ms |
| الرائج بارد (مرة كل 5 دقائق) / دافئ | ≈ 81ms / 0ms |
| توصيات لاعب | ≈ 36ms |
| مركز الاكتشاف (خدمة فقط) ضيف / لاعب | ≈ 5ms / 38ms |
| HTTP: اقتراحات / بحث كامل / اكتشاف (لاعب، شاملة الإطار) | ≈ 80 / 97 / 64ms |

هذه أرقام **مرجعية** على SQLite وليست وعدًا بزمن MySQL. المقياس الذي تحرسه الاختبارات هو **عدد الاستعلامات** (ثابت بين 3 و40 صفًّا بكل جدول؛ لا N+1) وشكل الخطة:

| نقطة الدخول | استعلامات (دافئ) |
| --- | --- |
| اقتراحات ضيف / لاعب | 8 / 10 |
| بحث «الكل» ضيف / لاعب (شاملة إطار الصفحة) | 17 / 24 |
| اكتشاف ضيف / لاعب (شاملة إطار الصفحة) | 22 / 22 |
| الرائج بارد (400 صف) / دافئ | ≤ 5 + log2(الصفوف) / 0 |

**EXPLAIN** (SQLite): استعلام الرائج = `SEARCH a ... (puzzle_id=? AND rowid>?)` أي مدى على المفتاح لا `SCAN`. بحث الأحجيات بالبادئة يستعمل `puzzles_active_title_search_index (is_active=?)`. SQLite لا يحوّل `LIKE` إلى مدى لعمود بترتيب ثنائي حساس للحالة، فلا يُظهر استعمال `users_name_search_index`؛ على MySQL بترتيب `utf8mb4_*_ci` يُتوقع مدى على `name`. **تحقق منه محليًا** بـ`EXPLAIN` على قاعدتك (انظر أدناه). شكل الاستعلام مضمون باختبار: عمود عارٍ في `WHERE` بلا `LOWER`/`REPLACE`.

## الإعدادات (`.env`، كلها اختيارية)

`SEARCH_DRIVER`, `SEARCH_MIN_LENGTH`, `SEARCH_MIN_LENGTH_GUEST_PLAYERS`, `SEARCH_PARTIAL_MAX_ID`, `SEARCH_TRENDING_WINDOW_DAYS`, `SEARCH_TRENDING_TTL`, `SEARCH_TRENDING_MIN_PLAYERS` (أمثلتها معلَّقة في `.env.example`). الأوزان والحدود الباقية في `config/search.php`. بعد أي تغيير على الإنتاج: `php artisan config:cache`.

## تشغيل الاختبارات

بمسارات محددة فقط (لا `php artisan test` مجرّد):

```
php vendor/bin/pest tests/Feature/DiscoveryE27
```

ملفات: `E27SearchTest` (البحث والخصوصية والصلة) · `E27NormalizationTest` (N) · `E27TrendingTest` (T) · `E27RecommendationTest` (R) · `E27DiscoverTest` (D) · `E27UxTest` (U) · `E27SecurityTest` (S) · `E27PerformanceTest` (P). `U7` يشغّل `safePath` فعليًا بـNode (يُتخطّى إن غاب).

التحقق من الفهرس على MySQL (بعد `php artisan migrate`):

```sql
EXPLAIN SELECT id, name FROM users WHERE name LIKE 'أحمد%' AND profile_visibility = 'public' LIMIT 4;
EXPLAIN SELECT id, title FROM puzzles WHERE is_active = 1 AND title LIKE 'لغز%' LIMIT 4;
```

المتوقع: `key` = `users_name_search_index` / `puzzles_active_title_search_index` و`type` = `range`.

## مؤشر الانتقال لمحرك بحث خارجي

**انتقل** (نفّذ `SearchBackend` جديدًا، مثل Meilisearch/Typesense/OpenSearch، وبدّل `SEARCH_DRIVER`) حين يتحقق أحد:

1. جدول مصدر تجاوز `partial_match_max_id` **وأردت** وسط الكلمة/الأشكال العربية المتقاربة عليه (أي لم تعد البادئة تكفي).
2. زمن اقتراحات p95 المقيس على الإنتاج تجاوز ~150ms رغم الوضع الكبير.
3. الحاجة لتصحيح الإملاء/المرادفات/الترتيب بالشعبية داخل المحرك.

ما يبقى في التطبيق عند الانتقال: قواعد الظهور والخصوصية (تُطبَّق على النتائج أو تُدفع فلاتر)، التطبيع، حدود المعدّل، العقد `SearchResult`. ما يتغير: `DatabaseSearchBackend` فقط. لا تُفهرس بيانات اللاعبين الخاصة ولا إجابات الأحجيات في المحرك.

## الحدود المعروفة

- **تعداد الأسماء العامة** ممكن نظريًا (أسماء لاعبين بملف public قابلة للبحث): مُخفَّف لا مُلغى.
- **الوضع الكبير**: بعد `partial_match_max_id` تبقى البادئة فقط؛ لا وسط كلمة (ما لم يتولّاه ترتيب MySQL). الطيّ العربي في SQL يشمل الحروف والحركات؛ الأرقام العربية-الهندية وNFKC على جانب PHP فقط، فالعمود المخزَّن بأرقام عربية لا يُطابق مصطلحًا بأرقام لاتينية في SQL.
- `LOWER` في SQLite ASCII فقط (لا أثر على MySQL)؛ إذن اختبارات تطابق الأحرف اللاتينية الموسَّعة محلية.
- افتراض الرائج: تزايد معرّف `puzzle_attempts` مع الزمن.
- نتائج اللاعبين بأفاتار الأحرف الأولى (لا تحميل تجميليات لكل نتيجة حفاظًا على عدد الاستعلامات).
- «اكتشف» ليس بتنقل الضيف الرئيسي (عقد E22)؛ يُوصل إليه من التراكب والصفحة الرئيسية للبحث.
- بلا بحث في عناصر المتجر ولا «شوهد مؤخرًا» ولا إشارة «نشاط الأصدقاء» (قرارات خصوصية/نطاق).

## متابعات مقترحة

**P1:** لوحة إدارة «رؤى البحث» بعدّادات مجهَّلة (إن طُلبت)؛ قياس p95 على الإنتاج بعد أسبوع وتحديد عتبة `SEARCH_PARTIAL_MAX_ID` بالبيانات. **P2:** تنفيذ محرك خارجي خلف `SearchBackend` عند تحقق المؤشر؛ بحث عناصر المتجر؛ مرادفات عربية.
`````

### `resources/js/search.js`

`````javascript
/**
 * E27: البحث العام - تراكب اقتراحات فورية (combobox/listbox) فوق صفحة /search (نموذج GET يعمل بدون JavaScript).
 *  - النصوص تُعرض بـx-text (textContent) أبدًا لا innerHTML؛ والروابط تُقبل فقط إن كانت مسارًا نسبيًا للموقع نفسه.
 *  - تأخير 220ms + إلغاء الطلب السابق (AbortController) + تجاهل الردود القديمة. 429 = رسالة لطيفة وإيقاف مؤقت. لا تخزين لنص البحث (لا localStorage ولا سجل).
 *  - لوحة المفاتيح: ↑/↓ تنقّل، Enter يفتح المحدَّد أو صفحة البحث الكاملة، Esc يغلق، Tab محصور بالحوار. اختصار «/» أو Ctrl/⌘+K للفتح.
 */
const DEBOUNCE_MS = 220;
const BACKOFF_MS = 5000;

// مسار نسبي للموقع نفسه فقط: يبدأ بـ«/» واحدة، بلا «//» ولا «\» ولا أي مسافة/محرف تحكّم (المتصفح يحذف التبويب والسطر الجديد من الروابط فيحوّل "/<tab>/x" إلى "//x").
const SAFE_PATH = /^\/(?![\/\\])[^\s\\\u0000-\u001f\u007f]*$/u;

export function safePath(url) {
    return typeof url === 'string' && SAFE_PATH.test(url) ? url : null;
}

function isEditable(el) {
    if (!el) return false;
    const tag = el.tagName;
    return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || el.isContentEditable === true;
}

export default function globalSearch() {
    return {
        open: false,
        q: '',
        state: 'idle', // idle | short | loading | ok | empty | error | throttled
        groups: [],
        note: null,
        allUrl: null,
        active: -1,
        status: '',
        composing: false,
        timer: null,
        controller: null,
        blockedUntil: 0,
        opener: null,

        init() {
            this.cfg = {
                suggest: this.$el.dataset.suggestUrl,
                page: this.$el.dataset.searchUrl,
                min: parseInt(this.$el.dataset.min || '2', 10),
            };
        },

        get flat() {
            const items = [];
            this.groups.forEach((g) => g.items.forEach((item) => items.push(item)));
            return items;
        },

        shortcut(event) {
            const slash = event.key === '/' && !event.ctrlKey && !event.metaKey && !event.altKey && !isEditable(event.target);
            const palette = (event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k';

            if (!this.open && (slash || palette)) {
                event.preventDefault();
                this.show();
            }
        },

        show() {
            if (this.open) return;
            this.opener = document.activeElement;
            this.open = true;
            document.documentElement.classList.add('overflow-hidden');
            this.$nextTick(() => this.$refs.input && this.$refs.input.focus());
        },

        close() {
            if (!this.open) return;
            this.open = false;
            this.cancel();
            document.documentElement.classList.remove('overflow-hidden');
            const opener = this.opener;
            this.opener = null;
            this.$nextTick(() => opener && typeof opener.focus === 'function' && opener.focus());
        },

        cancel() {
            clearTimeout(this.timer);
            if (this.controller) this.controller.abort();
            this.controller = null;
        },

        reset(state = 'idle') {
            this.groups = [];
            this.note = null;
            this.allUrl = null;
            this.active = -1;
            this.state = state;
            this.status = '';
        },

        onInput() {
            this.cancel();
            if (this.composing) return;

            const term = this.q.trim();
            if (term === '') return this.reset('idle');
            if (term.length < this.cfg.min) return this.reset('short');

            this.state = 'loading';
            this.timer = setTimeout(() => this.fetchSuggestions(term), DEBOUNCE_MS);
        },

        async fetchSuggestions(term) {
            if (Date.now() < this.blockedUntil) return this.reset('throttled');

            this.controller = new AbortController();
            const { signal } = this.controller;

            try {
                const response = await fetch(this.cfg.suggest + '?q=' + encodeURIComponent(term), {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                    signal,
                });

                if (response.status === 429) {
                    this.blockedUntil = Date.now() + BACKOFF_MS;
                    return this.reset('throttled');
                }
                if (!response.ok) return this.reset('error');

                const data = await response.json();
                if (term !== this.q.trim()) return; // ردّ قديم

                this.groups = (data.groups || [])
                    .map((g) => ({
                        type: g.type,
                        label: g.label,
                        more: safePath(g.more_url),
                        items: (g.items || []).map((i) => ({ ...i, url: safePath(i.url) })).filter((i) => i.url),
                    }))
                    .filter((g) => g.items.length);
                this.note = data.note || null;
                this.allUrl = safePath(data.all_url) || this.cfg.page;
                this.active = -1;
                this.state = this.groups.length ? 'ok' : data.state === 'too_short' ? 'short' : 'empty';
                this.status = this.groups.length ? this.flat.length + ' اقتراحات متاحة' : 'لا نتائج';
            } catch (error) {
                if (error && error.name === 'AbortError') return;
                this.reset('error');
            }
        },

        move(step) {
            const count = this.flat.length;
            if (!count) return;
            this.active = (this.active + step + count + (this.active === -1 && step < 0 ? 1 : 0)) % count;
            this.$nextTick(() => {
                const el = document.getElementById('gs-opt-' + this.active);
                if (el && el.scrollIntoView) el.scrollIntoView({ block: 'nearest' });
            });
        },

        enter(event) {
            event.preventDefault();
            const item = this.flat[this.active];

            if (item) {
                window.location.assign(item.url);
            } else {
                this.go();
            }
        },

        // بلا عنصر محدَّد: صفحة البحث الكاملة (الصفحة نفسها نموذج GET يعمل بلا JavaScript).
        go() {
            const term = this.q.trim();
            window.location.assign(term === '' ? this.cfg.page : this.cfg.page + '?q=' + encodeURIComponent(term));
        },

        trap(event) {
            const nodes = Array.from(this.$refs.panel.querySelectorAll('input, button, a[href]')).filter((n) => n.offsetParent !== null && n.tabIndex !== -1);
            if (!nodes.length) return;
            const first = nodes[0];
            const last = nodes[nodes.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        },

        get activeId() {
            return this.active >= 0 ? 'gs-opt-' + this.active : null;
        },

        indexOf(groupIndex, itemIndex) {
            let n = 0;
            for (let g = 0; g < groupIndex; g++) n += this.groups[g].items.length;
            return n + itemIndex;
        },
    };
}
`````

### `resources/views/discover/_card.blade.php`

`````blade
@php /** @var \App\Services\Discovery\DiscoverCard $card */ @endphp
{{-- E27: بطاقة اكتشاف موحَّدة. البطاقة كلها رابط واحد. النصوص مهرَّبة؛ الرابط من route() بالخادم. «السبب» يشرح لماذا ظهرت (شفافية التوصية) ولا يكشف نشاط أي لاعب آخر. --}}
<a href="{{ $card->url }}" class="glass rounded-2xl p-4 flex flex-col h-full hover:border-amethyst/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst transition motion-reduce:transition-none">
    <div class="flex items-start justify-between gap-2 mb-2">
        <span class="grid place-items-center w-10 h-10 shrink-0 rounded-xl bg-amethyst/15 text-amethyst"><x-ui-icon :name="$card->icon" class="w-5 h-5" /></span>
        @if ($card->badge)
            <x-status-badge :tone="$card->badgeTone ?? 'neutral'" :small="true">{{ $card->badge }}</x-status-badge>
        @endif
    </div>
    <h3 class="font-display font-black text-white leading-snug break-words">{{ $card->title }}</h3>
    @if ($card->subtitle)
        <p class="text-xs text-slate-400 mt-1">{{ $card->subtitle }}</p>
    @endif
    @if ($card->reason)
        <p class="mt-auto pt-3 text-[11px] font-bold text-amethyst">{{ $card->reason }}</p>
    @endif
</a>
`````

### `resources/views/discover/index.blade.php`

`````blade
@extends('layouts.app')

@section('title', 'اكتشف')
@section('meta_description', 'الرائج والجديد من الأحجيات والحملات والمنافسات والفرق، ومقترحات تناسبك.')

@section('content')
    <div class="space-y-10">

        <x-page-header title="اكتشف" :subtitle="$personalized ? 'ما يناسبك الآن، والرائج والجديد في المنصة.' : 'الرائج والجديد في المنصة. سجّل دخولك لتحصل على مقترحات تناسبك.'" icon="sparkles" class="anim-fade-up !mb-0" />

        <form method="GET" action="{{ route('search.index') }}" role="search" class="glass rounded-3xl p-4 flex flex-wrap items-center gap-3 anim-fade-up">
            <label for="discover-q" class="sr-only">ابحث في المنصة</label>
            <input id="discover-q" type="search" name="q" maxlength="64" autocomplete="off" enterkeyhint="search" placeholder="ابحث عن أحجية أو فريق أو لاعب…"
                class="flex-1 min-w-[12rem] rounded-xl bg-white/5 border border-white/10 px-4 py-2.5 text-sm text-white placeholder:text-slate-500 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">
            <button type="submit" class="btn-gem !py-2 !px-5 text-sm">بحث</button>
        </form>

        @if ($categories->isNotEmpty())
            <nav aria-label="تصنيفات الأحجيات" class="flex flex-wrap gap-2 anim-fade-up">
                @foreach ($categories as $category)
                    <a href="{{ route('puzzles.category', $category) }}" class="chip !py-1.5 !px-3 text-xs focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">{{ $category->name }}</a>
                @endforeach
            </nav>
        @endif

        @forelse ($sections as $section)
            <section aria-label="{{ $section['title'] }}" data-discover-section="{{ $section['key'] }}" class="anim-fade-up">
                <x-section-header :title="$section['title']" :href="$section['href']" :linkLabel="$section['linkLabel'] ?? 'عرض الكل'" />
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                    @foreach ($section['cards'] as $card)
                        @include('discover._card', ['card' => $card])
                    @endforeach
                </div>
            </section>
        @empty
            <x-empty-state icon="sparkles" title="لا محتوى للاكتشاف بعد" message="عند نشر أحجيات ومنافسات جديدة ستظهر هنا." :action="route('puzzles.index')" actionLabel="تصفّح الأحجيات" />
        @endforelse
    </div>
@endsection
`````

### `resources/views/layouts/partials/search-overlay.blade.php`

`````blade
{{--
    E27: تراكب البحث العام (اقتراحات فورية). يعمل بـJavaScript فقط؛ بدونه يبقى زر الرأس رابطًا عاديًا إلى /search (نموذج GET كامل).
    - لا <form> هنا عمدًا (صفحات قراءة عامة كالمتجر تتحقق من غياب النماذج): الإدخال + أزرار، وEnter يفتح المحدَّد أو /search?q=.
    - Combobox/listbox: role=combobox بـaria-controls/aria-expanded/aria-activedescendant والخيارات role=option؛ التركيز يبقى بالحقل. Esc يغلق ويعيد التركيز لمن فتحه. Tab محصور بالحوار.
    - النصوص من JSON تُعرض بـx-text (textContent) لا HTML. z-60 (فوق التنقل السفلي z-50 ودون الحدّ 100).
    - جوال: شاشة كاملة؛ سطح المكتب: لوحة وسطية بحدّ أقصى 75vh. حركة مخفَّضة تُحترم (لا حركات هنا أصلًا).
--}}
<div x-data="globalSearch" data-suggest-url="{{ route('search.suggest', [], false) }}" data-search-url="{{ route('search.index', [], false) }}" data-min="{{ (int) config('search.min_length', 2) }}"
    @open-search.window="show()" @keydown.window="shortcut($event)" @keydown.escape.window="close()">
    <div x-show="open" x-cloak style="display: none" class="fixed inset-0 z-[60]">
        <div class="absolute inset-0 bg-black/60" @click="close()" aria-hidden="true"></div>

        <div x-ref="panel" role="dialog" aria-modal="true" aria-label="البحث في المنصة" @keydown.tab="trap($event)"
            class="relative mx-auto flex h-full w-full max-w-2xl flex-col overflow-hidden bg-night-900 border-white/10 shadow-2xl shadow-black/50 sm:mt-16 sm:h-auto sm:max-h-[75vh] sm:rounded-3xl sm:border">
            <div role="search" class="flex items-center gap-2 border-b border-white/10 p-3">
                <x-ui-icon name="search" class="w-5 h-5 shrink-0 text-slate-400 ms-1" />
                <input x-ref="input" x-model="q" type="search" maxlength="64" autocomplete="off" enterkeyhint="search" spellcheck="false"
                    role="combobox" aria-autocomplete="list" aria-controls="gs-listbox" aria-label="ابحث في المنصة" placeholder="ابحث عن أحجية أو فريق أو لاعب…"
                    :aria-expanded="(state === 'ok').toString()" :aria-activedescendant="activeId"
                    @input="onInput()" @compositionstart="composing = true" @compositionend="composing = false; onInput()"
                    @keydown.arrow-down.prevent="move(1)" @keydown.arrow-up.prevent="move(-1)" @keydown.enter="enter($event)"
                    class="min-w-0 flex-1 rounded-xl bg-white/5 border border-white/10 px-4 py-2.5 text-sm text-white placeholder:text-slate-500 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">
                <button type="button" @click="go()" class="btn-gem !py-2 !px-4 text-sm shrink-0">بحث</button>
                <button type="button" @click="close()" aria-label="إغلاق البحث" title="إغلاق (Esc)"
                    class="grid place-items-center w-10 h-10 shrink-0 rounded-xl border border-white/10 bg-white/5 text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">
                    <x-ui-icon name="x" class="w-5 h-5" />
                </button>
            </div>

            <div class="flex-1 overflow-y-auto p-2 sm:min-h-[7rem]">
                <p class="sr-only" role="status" aria-live="polite" x-text="status"></p>

                <p x-show="state === 'idle'" class="px-3 py-8 text-center text-sm text-slate-400">ابدأ الكتابة لتظهر الاقتراحات فورًا. اضغط <kbd class="rounded border border-white/20 px-1.5 text-xs">/</kbd> في أي صفحة لفتح البحث. أو <a href="{{ route('discover.index', [], false) }}" class="font-bold text-amethyst hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst rounded">تصفّح مركز الاكتشاف</a>.</p>
                <p x-show="state === 'short'" class="px-3 py-8 text-center text-sm text-slate-400">اكتب حرفين على الأقل.</p>
                <p x-show="state === 'loading'" class="px-3 py-8 text-center text-sm text-slate-400">جارٍ البحث…</p>
                <p x-show="state === 'empty'" class="px-3 py-8 text-center text-sm text-slate-400">لا نتائج مطابقة. جرّب كلمة أخرى أو افتح البحث الكامل.</p>
                <p x-show="state === 'error'" class="px-3 py-8 text-center text-sm text-rose-400">تعذّر تحميل الاقتراحات. اضغط «بحث» لفتح صفحة البحث الكاملة.</p>
                <p x-show="state === 'throttled'" class="px-3 py-8 text-center text-sm text-gold">طلبات كثيرة خلال وقت قصير. انتظر لحظة ثم تابع الكتابة.</p>

                <div id="gs-listbox" role="listbox" aria-label="اقتراحات البحث" x-show="state === 'ok'">
                    <template x-for="(group, gi) in groups" :key="group.type">
                        <div role="group" :aria-label="group.label" class="mb-2">
                            <p class="px-3 pt-2 pb-1 text-[11px] font-black tracking-wide text-slate-500" x-text="group.label"></p>
                            <template x-for="(item, ii) in group.items" :key="item.url">
                                <a role="option" tabindex="-1" aria-label="نتيجة بحث" :aria-label="item.title" :href="item.url" :id="'gs-opt-' + indexOf(gi, ii)"
                                    :aria-selected="(active === indexOf(gi, ii)).toString()" :class="active === indexOf(gi, ii) ? 'bg-white/10' : ''"
                                    class="flex items-center gap-3 rounded-xl px-3 py-2.5 hover:bg-white/5 focus:outline-none">
                                    <span class="min-w-0 flex-1 text-start">
                                        <span class="block truncate text-sm font-black text-white" x-text="item.title"></span>
                                        <span x-show="item.subtitle" class="block truncate text-xs text-slate-400" x-text="item.subtitle"></span>
                                    </span>
                                    <span x-show="item.badge" class="shrink-0 rounded-full border border-white/10 bg-white/5 px-2 py-0.5 text-[11px] font-black text-slate-300" x-text="item.badge"></span>
                                </a>
                            </template>
                            <a x-show="group.more" :href="group.more" class="block rounded-lg px-3 py-1 text-xs font-bold text-amethyst hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">عرض كل النتائج في هذا القسم</a>
                        </div>
                    </template>
                    <p x-show="note" class="px-3 py-1 text-xs text-slate-500" x-text="note"></p>
                </div>

                <a x-show="state === 'ok' || state === 'empty'" :href="allUrl || '{{ route('search.index', [], false) }}'"
                    class="mt-1 block rounded-xl px-3 py-2.5 text-center text-sm font-bold text-amethyst hover:bg-white/5 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">فتح صفحة البحث الكاملة</a>
            </div>
        </div>
    </div>
</div>
`````

### `resources/views/search/_result.blade.php`

`````blade
@php /** @var \App\Services\Search\SearchResult $r */ @endphp
{{-- E27: صف نتيجة بحث. رابط واحد كامل الصف (هدف لمس واسع). كل النصوص مهرَّبة؛ الرابط من route() بالخادم لا من المستخدم. --}}
<li>
    <a href="{{ $r->url }}" class="flex items-center gap-3 px-4 py-3 transition motion-reduce:transition-none hover:bg-white/5 focus:outline-none focus-visible:bg-white/5 focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-amethyst">
        @if ($r->type === 'players')
            <x-player-avatar :name="$r->title" size="md" />
        @else
            <span class="grid place-items-center w-10 h-10 shrink-0 rounded-xl bg-amethyst/15 text-amethyst"><x-ui-icon :name="$r->icon" class="w-5 h-5" /></span>
        @endif
        <span class="min-w-0 flex-1 text-start">
            <span class="block font-black text-white truncate">{{ $r->title }}</span>
            @if ($r->subtitle)
                <span class="block text-xs text-slate-400 truncate">{{ $r->subtitle }}</span>
            @endif
        </span>
        @if ($r->badge)
            <x-status-badge :small="true">{{ $r->badge }}</x-status-badge>
        @endif
    </a>
</li>
`````

### `resources/views/search/index.blade.php`

`````blade
@extends('layouts.app')

@section('title', $query !== '' ? 'نتائج البحث: '.$query : 'البحث')
@section('meta_description', 'ابحث في الأحجيات والحملات والمواسم والمنافسات وبطولات الفرق والفرق واللاعبين.')

@section('content')
    @php
        $hasQuery = $state !== 'empty';
        $tabUrl = fn (?string $t) => route('search.index', array_filter(['q' => $query === '' ? null : $query, 'type' => $t]));
    @endphp
    <div class="max-w-3xl mx-auto space-y-5">

        <x-page-header title="البحث" subtitle="الأحجيات والحملات والمواسم والمنافسات والفرق واللاعبون في مكان واحد." icon="search" class="anim-fade-up !mb-0" />

        <form method="GET" action="{{ route('search.index') }}" role="search" class="glass rounded-3xl p-4 sm:p-5 flex flex-wrap items-center gap-3 anim-fade-up">
            <label for="search-page-q" class="sr-only">كلمة البحث</label>
            <input id="search-page-q" type="search" name="q" value="{{ $query }}" maxlength="64" autocomplete="off" enterkeyhint="search" placeholder="ابحث عن أحجية أو فريق أو لاعب…"
                class="flex-1 min-w-[12rem] rounded-xl bg-white/5 border border-white/10 px-4 py-2.5 text-sm text-white placeholder:text-slate-500 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">
            @if ($type)
                <input type="hidden" name="type" value="{{ $type }}">
            @endif
            <button type="submit" class="btn-gem !py-2 !px-5 text-sm">بحث</button>
        </form>

        <nav aria-label="نوع النتائج" class="flex flex-wrap gap-2">
            <a href="{{ $tabUrl(null) }}" @if ($type === null) aria-current="page" @endif
                class="chip !py-1.5 !px-3 text-xs focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst {{ $type === null ? '!border-amethyst/60 !text-white' : '' }}">الكل</a>
            @foreach ($types as $key => $label)
                <a href="{{ $tabUrl($key) }}" @if ($type === $key) aria-current="page" @endif
                    class="chip !py-1.5 !px-3 text-xs focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst {{ $type === $key ? '!border-amethyst/60 !text-white' : '' }}">{{ $label }}</a>
            @endforeach
        </nav>

        <div aria-live="polite">
            @if ($state === 'empty')
                <x-empty-state icon="search" title="ابدأ بكتابة ما تبحث عنه" message="اكتب حرفين على الأقل، أو تصفّح ما هو جديد ورائج في مركز الاكتشاف." :action="route('discover.index')" actionLabel="افتح مركز الاكتشاف" />
            @elseif ($state === 'too_short')
                <x-empty-state icon="search" title="الكلمة قصيرة جدًا" :message="'اكتب '.$minLength.' أحرف على الأقل لتظهر النتائج.'" />
            @elseif ($mode === 'type')
                @if ($results->isEmpty())
                    <x-empty-state icon="search" :title="'لا نتائج في «'.$typeLabel.'»'" message="جرّب كلمة أقصر أو إملاءً مختلفًا، أو ابحث في كل الأقسام." :action="$tabUrl(null)" actionLabel="ابحث في الكل" />
                @else
                    <section aria-label="{{ $typeLabel }}" class="glass rounded-2xl overflow-hidden anim-fade-up">
                        <h2 class="px-4 pt-4 pb-2 text-sm font-black text-slate-300">{{ $typeLabel }}</h2>
                        <ul class="divide-y divide-white/5">
                            @foreach ($results as $r)
                                @include('search._result', ['r' => $r])
                            @endforeach
                        </ul>
                    </section>
                    <div class="flex items-center justify-between gap-3 text-sm font-bold">
                        @if ($results->previousPageUrl())
                            <a href="{{ $results->previousPageUrl() }}" rel="prev" class="chip !py-1.5 !px-4 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">السابق</a>
                        @else
                            <span></span>
                        @endif
                        @if ($results->nextPageUrl())
                            <a href="{{ $results->nextPageUrl() }}" rel="next" class="chip !py-1.5 !px-4 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">التالي</a>
                        @endif
                    </div>
                    @if ($capped)
                        <p class="text-xs text-slate-500 text-center">عرضنا أعلى النتائج صلة. حدّد كلمة البحث أكثر لتصل لما تريد.</p>
                    @endif
                @endif
            @else
                @forelse ($groups as $group)
                    <section aria-label="{{ $group['label'] }}" class="glass rounded-2xl overflow-hidden mb-4 anim-fade-up">
                        <div class="flex items-center justify-between gap-3 px-4 pt-4 pb-2">
                            <h2 class="text-sm font-black text-slate-300">{{ $group['label'] }}</h2>
                            @if ($group['has_more'])
                                <a href="{{ $tabUrl($group['type']) }}" class="inline-flex items-center gap-1 text-xs font-bold text-amethyst hover:underline rounded-lg focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">
                                    عرض المزيد <x-ui-icon name="chevron-left" class="w-3.5 h-3.5" />
                                </a>
                            @endif
                        </div>
                        <ul class="divide-y divide-white/5">
                            @foreach ($group['results'] as $r)
                                @include('search._result', ['r' => $r])
                            @endforeach
                        </ul>
                    </section>
                @empty
                    <x-empty-state icon="search" :title="'لا نتائج لـ «'.$query.'»'" message="جرّب كلمة أقصر أو إملاءً مختلفًا. البحث يتجاهل التشكيل ويوحّد الألف والهمزة والتاء المربوطة." :action="route('discover.index')" actionLabel="تصفّح الاكتشاف" />
                @endforelse

                @if (in_array('players', $notes, true))
                    <p class="text-xs text-slate-500 text-center">للبحث عن لاعبين اكتب {{ config('search.min_length_guest_players', 3) }} أحرف على الأقل.</p>
                @endif
            @endif
        </div>
    </div>
@endsection
`````

### `tests/Feature/DiscoveryE27/E27DiscoverTest.php`

`````php
<?php

require_once __DIR__.'/_support.php';

use App\Models\Team;
use Illuminate\Support\Facades\DB;

/*
| E27-B - مركز الاكتشاف /discover: أقسام محدودة من الأنظمة القائمة بنفس شروط ظهورها العامة؛ للضيف عام، وللمسجَّل «تابع» و«مقترحة لك». قراءة فقط.
*/

beforeEach(fn () => $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class));

if (! function_exists('e27_sections')) {
    /** @return list<string> مفاتيح الأقسام بترتيب ظهورها. */
    function e27_sections(string $html): array
    {
        return uiAttrs(uiXpath($html), '//section[@data-discover-section]', 'data-discover-section');
    }

    function e27_section_cards(string $html, string $key): int
    {
        return uiXpath($html)->query('//section[@data-discover-section="'.$key.'"]//a[h3]')->length;
    }
}

test('D1: a guest sees the public hub - trending, new, campaigns - with no personal sections, a search form and category chips', function () {
    $category = e27_category('أحاجي');
    e27_pool($category, 3, 'NEW');
    e27_campaign('حملة متاحة');
    e27_play(e27_pool($category, 1, 'HOT')[0], 2);

    $response = $this->get(route('discover.index'))->assertOk();
    $html = $response->getContent();
    $x = uiXpath($html);

    expect(e27_sections($html))->toContain('trending')->toContain('new')->toContain('campaigns')->not->toContain('continue')->not->toContain('for-you')
        ->and(e27_a11y_problems($html))->toBe([])
        ->and($x->query('//form[@role="search" and @method="GET" or @role="search" and @method="get"]')->length)->toBe(1)
        ->and($x->query('//nav[@aria-label="تصنيفات الأحجيات"]//a')->length)->toBe(1);
    $response->assertSee('سجّل دخولك لتحصل على مقترحات تناسبك');
});

test('D2: a signed-in player also gets continue and recommended sections, and the page is accessible', function () {
    $category = e27_category('منطق');
    $user = e27_user();
    e27_solve($user, e27_pool($category, 1, 'SOLVED')[0]);
    e27_attempt($user, e27_pool($category, 1, 'TRYING')[0], false, now()->subHour());
    e27_pool($category, 6, 'MORE');

    $html = $this->actingAs($user)->get(route('discover.index'))->assertOk()->assertDontSee('سجّل دخولك لتحصل على مقترحات تناسبك')->getContent();
    $sections = e27_sections($html);

    expect($sections)->toContain('continue')->toContain('for-you')
        ->and(array_search('continue', $sections))->toBeLessThan(array_search('for-you', $sections))
        ->and(e27_a11y_problems($html))->toBe([])
        ->and(uiXpath($html)->query('//section[@data-discover-section="continue"]')->item(0)->textContent)->toContain('TRYING-1')->toContain('محاولاتك لم تنتهِ');
});

test('D3: nothing hidden elsewhere appears on the hub - inactive, draft, expired, future, private and stopped items', function (bool $signedIn) {
    $category = e27_category('رؤية');
    e27_pool($category, 1, 'VISIBLE-PUZZLE');
    e27_pool($category, 1, 'HIDDEN-PUZZLE', 'easy', ['is_active' => false]);
    e27_campaign('VISIBLE-CAMPAIGN');
    e27_campaign('HIDDEN-INACTIVE-CAMPAIGN', ['is_active' => false]);
    e27_campaign('HIDDEN-EXPIRED-CAMPAIGN', ['ends_at' => now()->subDay()]);
    e27_campaign('HIDDEN-FUTURE-CAMPAIGN', ['starts_at' => now()->addDay()]);
    e27_season('VISIBLE-SEASON');
    e27_season('HIDDEN-DRAFT-SEASON', ['is_published' => false], ['is_active' => false]);
    $private = e19Team(null, ['name' => 'HIDDEN-PRIVATE-TEAM']);
    $private->forceFill(['visibility' => Team::VISIBILITY_PRIVATE])->save();
    $stopped = e19Team(null, ['name' => 'HIDDEN-STOPPED-TEAM']);
    $stopped->forceFill(['is_active' => false])->save();
    $event = e17Event(['title' => 'HIDDEN-DRAFT-EVENT'], \App\Models\CompetitiveEvent::STATUS_DRAFT);
    $visibleEvent = e17Event(['title' => 'VISIBLE-EVENT']);
    DB::table('competitive_events')->where('id', $visibleEvent->id)->update(['participants_count' => 4]);

    $html = ($signedIn ? $this->actingAs(e27_user()) : $this)->get(route('discover.index'))->assertOk()->getContent();

    expect($html)->toContain('VISIBLE-PUZZLE-1')->toContain('VISIBLE-CAMPAIGN')->toContain('VISIBLE-SEASON')->toContain('VISIBLE-EVENT')
        ->not->toContain('HIDDEN-');
})->with([[false], [true]]);

test('D4: every section is capped by configuration - a large catalogue never produces a long page', function () {
    $category = e27_category('كثير');
    e27_pool($category, 20, 'P');
    foreach (range(1, 10) as $i) {
        e27_campaign('C'.$i);
    }
    foreach (range(1, 7) as $i) {
        e27_season('S'.$i);
    }

    $html = $this->get(route('discover.index'))->assertOk()->getContent();

    expect(e27_section_cards($html, 'new'))->toBe(8)
        ->and(e27_section_cards($html, 'campaigns'))->toBeLessThanOrEqual(6)
        ->and(e27_section_cards($html, 'seasons'))->toBe(4);
});

test('D5: with nothing published the hub still answers with one heading and a way forward', function () {
    $html = $this->get(route('discover.index'))->assertOk()->getContent();

    expect(e27_sections($html))->toBe([])->and(e27_a11y_problems($html))->toBe([])->and($html)->toContain('لا محتوى للاكتشاف بعد')->toContain(route('puzzles.index'));
});

test('D6: the daily puzzle section shows today\'s puzzle only', function () {
    $category = e27_category('يومي');
    e27_pool($category, 1, 'TODAY', 'easy', ['is_daily_puzzle' => true, 'daily_puzzle_date' => today()]);
    e27_pool($category, 1, 'YESTERDAY', 'easy', ['is_daily_puzzle' => true, 'daily_puzzle_date' => today()->subDay()]);

    $section = uiXpath($this->get(route('discover.index'))->assertOk()->getContent())->query('//section[@data-discover-section="daily"]');

    expect($section->length)->toBe(1)->and($section->item(0)->textContent)->toContain('TODAY-1')->not->toContain('YESTERDAY-1');
});

test('D7: a campaign that backs a published season is shown once, as a season', function () {
    e27_season('موسم الحملة');
    e27_campaign('حملة مستقلة');

    $html = $this->get(route('discover.index'))->assertOk()->getContent();
    $x = uiXpath($html);

    expect($x->query('//section[@data-discover-section="seasons"]//h3[contains(., "موسم الحملة")]')->length)->toBe(1)
        ->and($x->query('//section[@data-discover-section="campaigns"]//h3[contains(., "موسم الحملة")]')->length)->toBe(0)
        ->and($x->query('//section[@data-discover-section="campaigns"]//h3[contains(., "حملة مستقلة")]')->length)->toBe(1);
});

test('D8: the hub contains no player entries at all - no profile links, names or avatars of other people', function () {
    $category = e27_category('لاعبون');
    $puzzle = e27_pool($category, 1, 'HOT')[0];
    foreach (e27_play($puzzle, 3) as $i => $player) {
        $player->forceFill(['name' => 'لاعب-خاص-'.$i])->save();
    }

    foreach ([$this, $this->actingAs(e27_user())] as $caller) {
        $html = $caller->get(route('discover.index'))->assertOk()->getContent();

        expect(uiXpath($html)->query('//main//a[starts-with(@href, "/players/") or contains(@href, "/players/")]')->length)->toBe(0)
            ->and($html)->not->toContain('لاعب-خاص');
    }
});

test('D9: titles are escaped - a markup title is text on the hub, never HTML', function () {
    $category = e27_category('هروب');
    e27_pool($category, 1, '<script>alert(1)</script><img src=x onerror=alert(2)>');
    e27_campaign('<b onmouseover=alert(3)>حملة</b>');

    $html = $this->get(route('discover.index'))->assertOk()->getContent();

    expect($html)->not->toContain('<script>alert(1)')->not->toContain('<img src=x onerror')->not->toContain('<b onmouseover');
});

test('D10: the hub is read-only - no write queries, for guests and players', function () {
    $category = e27_category('قراءة');
    $user = e27_user();
    e27_solve($user, e27_pool($category, 1, 'SOLVED')[0]);
    e27_pool($category, 4, 'MORE');

    $sql = e27_sql(function () use ($user) {
        $this->get(route('discover.index'))->assertOk();
        $this->actingAs($user)->get(route('discover.index'))->assertOk();
    });

    // عدا ما يفعله إطار الصفحة القائم لأي صفحة (E11/E12: صف مستوى اللاعب عند أول عرض للرأس).
    expect(array_values(array_filter($sql, fn ($q) => preg_match('/^\s*(insert|update|delete|replace)\b/i', $q) && ! str_contains($q, '"player_progressions"'))))->toBe([]);
});

test('D11: personal sections never leak between viewers - one player\'s continue list is invisible to a guest and to another player', function () {
    $category = e27_category('عزل');
    $a = e27_user();
    e27_attempt($a, e27_pool($category, 1, 'A-PRIVATE-TRY')[0], false, now()->subHour());
    $campaign = e27_campaign('A-PRIVATE-CAMPAIGN');
    e27_progress($a, $campaign);

    $own = $this->actingAs($a)->get(route('discover.index'))->assertOk()->getContent();
    expect($own)->toContain('محاولاتك لم تنتهِ')->toContain('تقدّمك محفوظ');

    auth()->logout();
    $guest = $this->get(route('discover.index'))->assertOk()->getContent();
    $b = $this->actingAs(e27_user())->get(route('discover.index'))->assertOk()->getContent();

    foreach ([$guest, $b] as $other) {
        expect($other)->not->toContain('محاولاتك لم تنتهِ')->not->toContain('تقدّمك محفوظ')->and(e27_sections($other))->not->toContain('continue');
    }
    expect(e27_sections($guest))->not->toContain('for-you');   // الضيف بلا مقترحات؛ اللاعب الجديد له مقترحات لكن من تاريخه هو (فارغ)
});

test('D12: only active categories are listed as chips, at most twelve, and each links to the existing category page', function () {
    foreach (range(1, 14) as $i) {
        e27_category('تصنيف '.$i);
    }
    $off = e27_category('تصنيف معطّل');
    $off->forceFill(['is_active' => false])->save();

    $x = uiXpath($this->get(route('discover.index'))->assertOk()->getContent());
    $links = uiAttrs($x, '//nav[@aria-label="تصنيفات الأحجيات"]//a', 'href');

    expect($links)->toHaveCount(12)->and(implode(' ', $links))->not->toContain($off->slug);
    foreach ($links as $href) {
        expect($href)->toContain('/puzzles/');
    }
});

test('D13: a frozen or unverified player gets a normal page, not an error', function () {
    $category = e27_category('حالات');
    e27_pool($category, 2, 'P');

    foreach ([e27_user(['is_frozen' => true]), e27_user(['email_verified_at' => null])] as $viewer) {
        expect($this->actingAs($viewer)->get(route('discover.index'))->status())->toBeLessThan(500);
    }
});
`````

### `tests/Feature/DiscoveryE27/E27NormalizationTest.php`

`````php
<?php

require_once __DIR__.'/_support.php';

use App\Services\Search\SearchQuery;
use App\Services\Search\TextFold;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class));

test('N1: folding unifies alef, hamza, ta marbuta, alef maksura, tatweel and diacritics', function (string $input, string $expected) {
    expect(TextFold::fold($input))->toBe($expected);
})->with([
    ['أحجية', 'احجيه'], ['إسلام', 'اسلام'], ['آمال', 'امال'], ['ٱلكتاب', 'الكتاب'], ['مَدْرَسَةٌ', 'مدرسه'], ['ـمـدرسـة', 'مدرسه'],
    ['على', 'علي'], ['مؤمن', 'مومن'], ['رئيس', 'رييس'], ['ﻻ', 'لا'], ['  كَلِمَةٌ   طَوِيلَةٌ  ', 'كلمه طويله'],
]);

test('N2: digits in Arabic-Indic, Persian and ASCII forms fold together', function () {
    expect(TextFold::fold('٠١٢٣٤٥٦٧٨٩'))->toBe('0123456789')->and(TextFold::fold('۰۱۲۳۴۵۶۷۸۹'))->toBe('0123456789')->and(TextFold::fold('Level ٣'))->toBe('level 3');
});

test('N3: Latin case, Turkish dotted/dotless i and invisible characters fold away', function () {
    expect(TextFold::fold('İSTANBUL'))->toBe('istanbul')->and(TextFold::fold('ISPARTA ıı'))->toBe('isparta ii')->and(TextFold::fold("a\u{200D}b\u{200C}c"))->toBe('abc')
        ->and(TextFold::fold("a\u{200B}b"))->toBe('a b')->and(TextFold::fold("\u{202E}abc\u{202C}"))->toBe('abc')->and(TextFold::fold("a\x00b\tc\nd"))->toBe('a b c d');
});

test('N4: invalid UTF-8 and empty input fold to an empty string instead of failing', function () {
    expect(TextFold::fold("\xC3\x28"))->toBe('')->and(TextFold::fold(null))->toBe('')->and(TextFold::fold(''))->toBe('')->and(SearchQuery::from("\xFF\xFE")->isEmpty())->toBeTrue();
});

test('N5: the SQL fold of a column equals the PHP fold of the same text for every letter rule (parity)', function () {
    $corpus = ['أحجية', 'إسلام', 'آمال', 'ٱلكتاب', 'مَدْرَسَةٌ', 'ـمـدرسـة', 'على', 'مؤمن', 'رئيس', 'ISPARTA', 'ıspartı', 'Ab Cd', 'لغز  الذكاء'];
    DB::statement('create table e27_fold (v text)');
    foreach ($corpus as $text) {
        DB::table('e27_fold')->insert(['v' => $text]);
    }

    $expression = TextFold::sqlFold('v');
    $rows = DB::table('e27_fold')->selectRaw($expression.' as f, v')->get();

    foreach ($rows as $row) {
        // SQL لا يطوي المسافات المتعددة ولا أحرف Unicode الكبيرة خارج ASCII؛ نقارن بعد توحيد المسافات (الحدّ الموثّق للمطابقة الجزئية).
        expect(preg_replace('/\s+/u', ' ', $row->f))->toBe(TextFold::fold($row->v), $row->v);
    }
    DB::statement('drop table e27_fold');
});

test('N6: the SQL fold accepts only plain identifiers - anything else throws and never reaches SQL', function (string $bad) {
    expect(fn () => TextFold::sqlFold($bad))->toThrow(InvalidArgumentException::class);
})->with(["title; drop table users", "title) or (1=1", "users.name'", 'a.b.c', 'Title', '1abc', "t`x", '', 'name --', "na\nme"]);

test('N7: LIKE escaping makes percent, underscore and the escape character literal', function () {
    expect(TextFold::escapeLike('100%_!'))->toBe('100!%!_!!')->and(SearchQuery::from('50%')->foldedPrefixPattern())->toBe('50!%%');
});

test('N8: a query keeps its display form, folds for matching, caps tokens and length, and measures length without spaces', function () {
    $q = SearchQuery::from("  أَحْجِيَّةُ   المدينة  ");

    expect($q->display)->toBe('أَحْجِيَّةُ المدينة')->and($q->folded)->toBe('احجيه المدينه')->and($q->tokens)->toBe(['احجيه', 'المدينه'])->and($q->length())->toBe(12);

    $long = SearchQuery::from(str_repeat('ب', 200));
    expect(mb_strlen($long->display))->toBe(64)->and($long->truncated)->toBeTrue();

    expect(SearchQuery::from('a b c d e f g h')->tokens)->toHaveCount(5)->and(SearchQuery::from('a a a')->tokens)->toBe(['a']);
});

test('N9: the minimum length counts folded letters, not bytes - two Arabic letters pass, one does not', function () {
    expect(SearchQuery::from('لغ')->meets(2))->toBeTrue()->and(SearchQuery::from('ل')->meets(2))->toBeFalse()->and(SearchQuery::from('ل ')->meets(2))->toBeFalse()->and(SearchQuery::from('ـ')->meets(1))->toBeFalse();
});

test('N10: the definite article is dropped from long words for partial matching only', function () {
    expect(SearchQuery::from('الاحجية')->tokenPatterns())->toBe(['%احجيه%'])->and(SearchQuery::from('الله')->tokenPatterns())->toBe(['%الله%'])->and(SearchQuery::from('الم')->tokenPatterns())->toBe(['%الم%']);
});

test('N11: on a table beyond the partial limit only column prefixes match - fast, index-friendly, honest about the trade-off', function () {
    config(['search.partial_match_max_id' => 2]);
    e27_puzzle('أحجية أولى');
    e27_puzzle('ثانية أحجية');
    e27_puzzle('أحجية ثالثة');
    e27_cold();

    // بادئة بالنص كما كُتب تعمل؛ كلمة وسط العنوان لا تُطابَق في هذا الوضع (حدّ موثّق: الانتقال لمحرك بحث).
    expect(e27_group(e27_suggest($this, 'أحجية'), 'puzzles'))->toBe(['أحجية ثالثة', 'أحجية أولى'])
        ->and(e27_group(e27_suggest($this, 'ثالثة'), 'puzzles'))->toBe([]);
});

test('N12: below the partial limit the same data is found by a middle word and an Arabic variant', function () {
    config(['search.partial_match_max_id' => 100]);
    e27_puzzle('أحجية أولى');
    e27_puzzle('ثانية أحجية');
    e27_cold();

    // البادئة (المرتبة 1) تسبق الجزئي (المرتبة 3) حتى لو كان الثاني أحدث.
    expect(e27_group(e27_suggest($this, 'احجيه'), 'puzzles'))->toBe(['أحجية أولى', 'ثانية أحجية']);
    expect(e27_group(e27_suggest($this, 'اولي'), 'puzzles'))->toBe(['أحجية أولى'])->and(e27_group(e27_suggest($this, 'أولى'), 'puzzles'))->toBe(['أحجية أولى']);
});

test('N13: per-type overrides of the partial limit apply to that type only', function () {
    config(['search.partial_match_max_id' => 100, 'search.partial_match_max_id_by_type' => ['teams' => 1]]);
    e27_puzzle('ثانية أحجية');
    e19Team(null, ['name' => 'فريق الأول']);
    e19Team(null, ['name' => 'فريق الثاني']);
    e27_cold();

    expect(e27_group(e27_suggest($this, 'احجيه'), 'puzzles'))->toBe(['ثانية أحجية'])->and(e27_group(e27_suggest($this, 'الثاني'), 'teams'))->toBe([]);
});
`````

### `tests/Feature/DiscoveryE27/E27PerformanceTest.php`

`````php
<?php

require_once __DIR__.'/_support.php';

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| E27-E - الأداء. المبدأ: عدد الاستعلامات لا يتبع حجم البيانات (لا N+1)، السقوف مقيسة (لا مخمَّنة)، الرائج مخزَّن ولا يمسح أسخن جدول،
| والبحث بالبادئة قابل لاستعمال الفهرس. SQLite لا يقيس زمن MySQL؛ لذا الاختبارات هنا بنيوية (عدد، خطة، شكل SQL) لا زمنية.
*/

beforeEach(fn () => $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class));

if (! function_exists('e27_grow')) {
    /** يضيف $count عنصرًا من كل نوع قابل للبحث، كلها تطابق «نمط». */
    function e27_grow(int $from, int $to): void
    {
        $category = \App\Models\PuzzleCategory::query()->first() ?? e27_category('نمط');

        for ($i = $from + 1; $i <= $to; $i++) {
            e27_puzzle('نمط أحجية '.$i, ['puzzle_category_id' => $category->id]);
            e27_campaign('نمط حملة '.$i);
            e27_season('نمط موسم '.$i);
            e27_user(['name' => 'نمط لاعب '.$i]);
            e19Team(null, ['name' => 'نمط فريق '.$i]);
            e17Event(['title' => 'نمط منافسة '.$i]);
        }
    }

    /** @return array<string, callable> نقاط الدخول المقيسة. */
    function e27_endpoints($test, $viewer): array
    {
        return [
            'suggest guest' => fn () => $test->getJson(route('search.suggest', ['q' => 'نمط']))->assertOk(),
            'suggest player' => fn () => $test->actingAs($viewer)->getJson(route('search.suggest', ['q' => 'نمط']))->assertOk(),
            'search all (player)' => fn () => $test->actingAs($viewer)->get(route('search.index', ['q' => 'نمط']))->assertOk(),
            'search all (guest)' => fn () => $test->get(route('search.index', ['q' => 'نمط']))->assertOk(),
            'search players page' => fn () => $test->actingAs($viewer)->get(route('search.index', ['q' => 'نمط', 'type' => 'players']))->assertOk(),
            'search puzzles page' => fn () => $test->actingAs($viewer)->get(route('search.index', ['q' => 'نمط', 'type' => 'puzzles']))->assertOk(),
            'discover guest' => fn () => $test->get(route('discover.index'))->assertOk(),
            'discover player' => fn () => $test->actingAs($viewer)->get(route('discover.index'))->assertOk(),
        ];
    }
}

test('P1: query counts do not grow with the data - every endpoint runs the same number of queries on 3 rows per table as on 40 (no N+1)', function () {
    $viewer = e27_user();
    e27_grow(0, 3);
    $endpoints = e27_endpoints($this, $viewer);
    foreach ($endpoints as $run) {
        $run();   // تسخين الكاش (الرائج، مجسّ الحجم)
    }
    $small = array_map(fn ($run) => e27_queries($run), $endpoints);

    e27_grow(3, 40);
    $large = array_map(fn ($run) => e27_queries($run), $endpoints);

    expect($large)->toBe($small);
});

test('P2: measured query ceilings hold on a warm cache - search and discovery add a bounded, small number of queries over a plain page', function () {
    $viewer = e27_user();
    e27_grow(0, 12);
    $endpoints = e27_endpoints($this, $viewer);
    foreach ($endpoints as $run) {
        $run();
    }
    $baseGuest = e27_queries(fn () => $this->get('/terms')->assertOk());
    $basePlayer = e27_queries(fn () => $this->actingAs($viewer)->get('/terms')->assertOk());
    $count = fn (string $name) => e27_queries($endpoints[$name]);

    // JSON بلا إطار صفحة: مصدر لكل نوع (7) + تحميل التصنيف + الحظر للمسجَّل. مقيس 8 (ضيف) و10 (لاعب).
    expect($count('suggest guest'))->toBeLessThanOrEqual(10)->and($count('suggest player'))->toBeLessThanOrEqual(12);
    // صفحات كاملة: فوق قاعدة الصفحة العادية بنفس المشاهد (مقيس: ضيف +7، لاعب +7 أو أقل).
    expect($count('search all (guest)') - $baseGuest)->toBeLessThanOrEqual(12)
        ->and($count('search all (player)') - $basePlayer)->toBeLessThanOrEqual(12)
        ->and($count('search players page') - $basePlayer)->toBeLessThanOrEqual(5)
        ->and($count('search puzzles page') - $basePlayer)->toBeLessThanOrEqual(5);
    // الاكتشاف: قسم بكل استعلام محدود (مقيس 22).
    expect($count('discover guest'))->toBeLessThanOrEqual(26)->and($count('discover player'))->toBeLessThanOrEqual(28);
});

test('P3: a cold cache costs a few extra queries once - then nothing until it expires', function () {
    $viewer = e27_user();
    e27_grow(0, 5);
    $endpoints = e27_endpoints($this, $viewer);

    e27_cold();
    $cold = e27_queries($endpoints['suggest guest']);
    $warm = e27_queries($endpoints['suggest guest']);
    expect($cold)->toBeLessThanOrEqual(18)->and($warm)->toBeLessThan($cold)->and($cold - $warm)->toBeLessThanOrEqual(8);   // مجسّ الحجم لكل نوع مرة واحدة

    e27_cold();
    expect(e27_queries($endpoints['discover guest']))->toBeLessThanOrEqual(30);
});

test('P4: the cold trending computation is logarithmic in the number of attempts and the warm one is free', function () {
    $puzzle = e27_puzzle('أحجية القياس');
    $users = array_map(fn () => e27_user(), range(1, 20));
    $rows = 400;
    foreach (range(1, $rows) as $n) {
        e27_attempt($users[$n % 20], $puzzle, $n % 7 === 0, $n <= 200 ? now()->subDays(12) : now()->subHours(3), $n);
    }
    e27_cold();

    $cold = e27_queries(fn () => e27_trending()->puzzleScores());
    $warm = e27_queries(fn () => e27_trending()->puzzleScores());

    // min + max + قيمة الأخير + الاستعلام الرئيسي + خطوات البحث الثنائي (≤ log2(صفوف)) + هامش خطوة.
    expect($cold)->toBeLessThanOrEqual(5 + (int) ceil(log($rows, 2)))->and($warm)->toBe(0);
    expect(e27_trending()->puzzleScores()[$puzzle->id]['players'])->toBe(20);   // النتيجة صحيحة: الصفوف القديمة (قبل 12 يومًا) لم تُحتسب
});

test('P5: the trending query is a bounded range on the primary key - never a full scan of the attempts table', function () {
    $puzzle = e27_puzzle('أحجية الخطة');
    $users = array_map(fn () => e27_user(), range(1, 10));
    foreach (range(1, 120) as $n) {
        e27_attempt($users[$n % 10], $puzzle, false, $n <= 60 ? now()->subDays(12) : now()->subHours(3), $n);
    }
    e27_cold();

    DB::flushQueryLog();
    DB::enableQueryLog();
    e27_trending()->puzzleScores();
    $log = DB::getQueryLog();
    DB::disableQueryLog();

    $main = collect($log)->first(fn ($q) => str_contains($q['query'], 'COUNT(DISTINCT'));
    expect($main)->not->toBeNull();
    // شكل الاستعلام: مدى على المعرّف + حد زمني + محاولات مستقلة فقط.
    expect($main['query'])->toContain('"a"."id" >= ?')->toContain('"a"."created_at" >= ?')->toContain('"a"."context_type" is null');

    $plan = collect(DB::select('EXPLAIN QUERY PLAN '.$main['query'], $main['bindings']))->pluck('detail')->all();
    $attempts = array_values(array_filter($plan, fn ($line) => str_contains($line, ' a ') || str_starts_with($line, 'SCAN a') || str_starts_with($line, 'SEARCH a')));

    expect($attempts)->not->toBe([]);
    foreach ($attempts as $line) {
        expect($line)->toStartWith('SEARCH a')->not->toContain('SCAN');   // SEARCH بفهرس/مفتاح مع شرط rowid>? = مدى، لا مسح كامل
        expect($line)->toContain('rowid>?');
    }
});

test('P6: beyond the partial-match limit the filter is a bare-column prefix LIKE - nothing wraps the column, so a name/title index can serve it', function () {
    config(['search.partial_match_max_id' => 0]);
    e27_grow(0, 1);   // صف في كل جدول مصدر: الجدول الفارغ رخيص المسح فيبقى بالوضع الكامل عمدًا، والمقصود هنا الوضع الكبير
    e27_cold();

    DB::flushQueryLog();
    DB::enableQueryLog();
    e27_suggest($this, 'نمط', e27_user());
    $log = DB::getQueryLog();
    DB::disableQueryLog();

    $tiered = collect($log)->filter(fn ($q) => str_contains($q['query'], 'search_tier') && ! str_contains($q['query'], 'from "team_championships"'));   // لا بطولات بالبيانات: جدول فارغ
    expect($tiered->count())->toBe(6);
    foreach ($tiered as $q) {
        $where = substr($q['query'], (int) strpos($q['query'], ' from "'));

        expect($where)->not->toContain('REPLACE(')->not->toContain('LOWER(')->not->toContain('like ?  ')->and($where)->toMatch('/like \? escape \'!\'/');
        foreach ($q['bindings'] as $binding) {
            if (is_string($binding) && str_contains($binding, '%')) {
                expect($binding)->not->toStartWith('%');   // لا «%x» في الوضع الكبير: كل نمط بادئة
            }
        }
    }

    $puzzles = $tiered->first(fn ($q) => str_contains($q['query'], 'from "puzzles"'));
    $plan = collect(DB::select('EXPLAIN QUERY PLAN '.$puzzles['query'], $puzzles['bindings']))->pluck('detail')->implode(' | ');
    expect($plan)->toContain('puzzles_active_title_search_index');   // SQLite لا يحوّل LIKE إلى مدى (غير حساس للحالة)؛ MySQL بترتيب utf8mb4 يفعل — يُتحقق منه بـEXPLAIN محليًا (docs)
});

test('P7: the two search indexes exist, are reversible, and nothing was added to the hot attempts table or the small tables', function () {
    expect(Schema::hasIndex('users', 'users_name_search_index'))->toBeTrue()->and(Schema::hasIndex('puzzles', 'puzzles_active_title_search_index'))->toBeTrue();

    foreach (Schema::getIndexes('puzzle_attempts') as $index) {
        expect($index['columns'])->not->toContain('created_at');   // قرار E24/E27: لا فهرس زمني على أسخن جدول
    }
    foreach (['campaigns', 'seasons', 'competitive_events', 'team_championships', 'teams'] as $table) {
        foreach (Schema::getIndexes($table) as $index) {
            expect($index['name'])->not->toContain('search');
        }
    }

    $migration = require base_path('database/migrations/2026_10_19_000001_add_e27_search_indexes.php');
    $migration->down();
    expect(Schema::hasIndex('users', 'users_name_search_index'))->toBeFalse()->and(Schema::hasIndex('puzzles', 'puzzles_active_title_search_index'))->toBeFalse();
    $migration->up();
    $migration->up();   // آمن لإعادة التشغيل
    expect(Schema::hasIndex('users', 'users_name_search_index'))->toBeTrue()->and(Schema::hasIndex('puzzles', 'puzzles_active_title_search_index'))->toBeTrue();

    $source = file_get_contents(base_path('database/migrations/2026_10_19_000001_add_e27_search_indexes.php'));
    expect(substr_count($source, '->index('))->toBe(2)->and($source)->not->toContain('Schema::create(')->not->toContain('DB::')->not->toContain('->fullText(');
});

test('P8: responses are bounded however many rows match - suggest nine items, a page twelve, depth five, groups five', function () {
    e27_grow(0, 40);
    $viewer = e27_user();

    $json = e27_suggest($this, 'نمط', $viewer);
    expect(collect($json['groups'])->sum(fn ($g) => count($g['items'])))->toBeLessThanOrEqual(9)
        ->and(collect($json['groups'])->every(fn ($g) => count($g['items']) <= 3))->toBeTrue()
        ->and(strlen(json_encode($json)))->toBeLessThan(6000);

    $all = uiXpath($this->actingAs($viewer)->get(route('search.index', ['q' => 'نمط']))->assertOk()->getContent());
    foreach ($all->query('//main//section[@aria-label]') as $section) {
        expect($all->query('.//ul/li', $section)->length)->toBeLessThanOrEqual(5);
    }

    $page = uiXpath($this->actingAs($viewer)->get(route('search.index', ['q' => 'نمط', 'type' => 'puzzles']))->assertOk()->getContent());
    expect($page->query('//main//ul/li')->length)->toBe(12)->and($page->query('//a[@rel="next"]')->length)->toBe(1);

    $last = uiXpath($this->actingAs($viewer)->get(route('search.index', ['q' => 'نمط', 'type' => 'puzzles', 'page' => 5]))->assertOk()->getContent());
    expect($last->query('//a[@rel="next"]')->length)->toBe(0)->and($last->query('//main//ul/li')->length)->toBeLessThanOrEqual(12);
});

test('P9: input size is bounded before it reaches the database - a ten-thousand-character term never produces a long binding', function () {
    $viewer = e27_user();
    e27_puzzle('أحجية');

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->actingAs($viewer)->get(route('search.index', ['q' => str_repeat('أحجية ', 2000)]))->assertOk();
    $this->actingAs($viewer)->getJson(route('search.suggest', ['q' => str_repeat('ب', 10000)]))->assertOk();
    $log = DB::getQueryLog();
    DB::disableQueryLog();

    $longest = 0;
    foreach ($log as $q) {
        if (! str_contains($q['query'], 'search_tier')) {
            continue;
        }
        foreach ($q['bindings'] as $binding) {
            if (is_string($binding)) {
                $longest = max($longest, mb_strlen($binding));
            }
        }
    }

    expect($longest)->toBeGreaterThan(0)->and($longest)->toBeLessThanOrEqual(70);   // 64 حرفًا كحد أقصى + محارف النمط
});

test('P10: recommendations cost the same queries for a short history as for a long one, and the history read is capped', function () {
    $category = e27_category('تاريخ طويل');
    $short = e27_user();
    $long = e27_user();
    e27_pool($category, 12, 'POOL');

    foreach (e27_pool($category, 3, 'S') as $i => $puzzle) {
        e27_attempt($short, $puzzle, $i === 0, now()->subDays(2));
    }
    foreach (e27_pool($category, 60, 'L') as $i => $puzzle) {
        e27_attempt($long, $puzzle, $i % 3 === 0, now()->subDays(2));
    }
    e27_trending()->puzzleScores();   // تسخين

    $countShort = e27_queries(fn () => e27_reco($short));
    $countLong = e27_queries(fn () => e27_reco($long));
    expect($countLong)->toBe($countShort)->and($countLong)->toBeLessThanOrEqual(14);

    config(['search.recommendations.history_cap' => 5]);
    DB::flushQueryLog();
    DB::enableQueryLog();
    e27_reco($long);
    $history = collect(DB::getQueryLog())->first(fn ($q) => str_contains($q['query'], 'group by "puzzle_id"'));
    DB::disableQueryLog();

    expect($history['query'])->toContain('limit 5');
});
`````

### `tests/Feature/DiscoveryE27/E27RecommendationTest.php`

`````php
<?php

require_once __DIR__.'/_support.php';

use App\Models\Puzzle;
use App\Models\PuzzleAttempt;
use App\Models\PuzzleCategory;
use App\Models\Team;
use App\Models\User;
use App\Services\Discovery\RecommendationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
| E27-D - التوصيات الشخصية. بخصوصية أولًا: من تاريخ المستخدم نفسه + مجاميع عامة فقط؛ حتمية؛ بلا كاش لكل مستخدم؛ بلا كتابة؛ بلا أثر على الاقتصاد.
*/

beforeEach(fn () => $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class));

test('R1: a player with no history gets the trending puzzle first, then newer ones - no exploration slots, no history', function () {
    $category = e27_category('عام');
    $puzzles = e27_pool($category, 4, 'P');
    foreach ([e27_user(), e27_user()] as $other) {
        e27_attempt($other, $puzzles[2], false, now()->subHour());
    }
    $user = e27_user();

    $reco = e27_reco($user);
    $titles = array_map(fn ($r) => $r['puzzle']->title, $reco['puzzles']);

    expect($reco['has_history'])->toBeFalse()->and($titles[0])->toBe('P-3')->and($titles)->toHaveCount(4)->and($titles)->toEqualCanonicalizing(['P-1', 'P-2', 'P-3', 'P-4'])
        ->and($reco['puzzles'][0]['reason'])->toBe('رائجة هذا الأسبوع')
        ->and(collect($reco['puzzles'])->pluck('reason')->all())->not->toContain('تصنيف جديد لك');
    expect(e27_reco_titles($user))->toBe($titles);   // حتمي
});

test('R2: a player who solved puzzles of a category is mostly served that category, with a quarter of the slots for categories not yet tried', function () {
    $a = e27_category('منطق');
    $b = e27_category('تاريخ');
    $user = e27_user();
    [$solvedOne, $solvedTwo] = array_slice(e27_pool($a, 2, 'SOLVED'), 0, 2);
    e27_solve($user, $solvedOne);
    e27_solve($user, $solvedTwo);
    e27_pool($a, 8, 'A');
    e27_pool($b, 8, 'B');

    $reco = e27_reco($user);
    $titles = array_map(fn ($r) => $r['puzzle']->title, $reco['puzzles']);
    $reasons = array_map(fn ($r) => $r['reason'], $reco['puzzles']);

    expect($titles)->toHaveCount(8)
        ->and(count(array_filter($titles, fn ($t) => str_starts_with($t, 'A-'))))->toBe(6)
        ->and(count(array_filter($titles, fn ($t) => str_starts_with($t, 'B-'))))->toBe(2)
        ->and(array_slice($reasons, 0, 6))->each->toBe('لأنك تلعب «منطق»')
        ->and(array_slice($reasons, 6))->each->toBe('تصنيف جديد لك');
    expect($reco['has_history'])->toBeTrue();
});

test('R3: solved, exhausted, inactive and in-progress puzzles are never recommended - in-progress ones appear under continue instead', function () {
    $a = e27_category('ألغاز');
    $user = e27_user();
    $solved = e27_pool($a, 1, 'SOLVED')[0];
    $exhausted = e27_pool($a, 1, 'EXHAUSTED')[0];
    $continuing = e27_pool($a, 1, 'CONTINUE')[0];
    e27_pool($a, 1, 'INACTIVE', 'easy', ['is_active' => false]);
    e27_pool($a, 7, 'FREE');

    e27_solve($user, $solved);
    foreach ([1, 2, 3] as $n) {
        e27_attempt($user, $exhausted, false, now()->subDay(), $n);   // max_attempts = 3
    }
    e27_attempt($user, $continuing, false, now()->subHour(), 1);

    $reco = e27_reco($user);
    $titles = array_map(fn ($r) => $r['puzzle']->title, $reco['puzzles']);

    expect($titles)->not->toContain('SOLVED-1')->not->toContain('EXHAUSTED-1')->not->toContain('CONTINUE-1')->not->toContain('INACTIVE-1')->toHaveCount(7)
        ->and($reco['continue_puzzles']->pluck('title')->all())->toBe(['CONTINUE-1']);
});

test('R4: exploration order is a fixed daily hash of user, date and puzzle - the same all day, recomputed tomorrow, never random', function () {
    $a = e27_category('أ');
    $b = e27_category('ب');
    $user = e27_user();
    e27_solve($user, e27_pool($a, 1, 'SOLVED')[0]);
    e27_pool($a, 8, 'A');
    $explore = e27_pool($b, 6, 'B');

    $expect = fn () => collect($explore)->sortBy(fn (Puzzle $p) => crc32($user->id.'|'.now()->format('Y-m-d').'|'.$p->id))->take(2)->pluck('title')->values()->all();

    expect(array_slice(e27_reco_titles($user), -2))->toBe($expect())->and(e27_reco_titles($user))->toBe(e27_reco_titles($user));

    $this->travel(1)->days();
    expect(array_slice(e27_reco_titles($user), -2))->toBe($expect());
    $this->travelBack();
});

test('R5: difficulty fit - the preferred difficulty first, the next harder one second, the rest last', function () {
    $a = e27_category('تدرج');
    $user = e27_user();
    e27_solve($user, e27_pool($a, 1, 'SOLVED', 'easy')[0]);
    e27_pool($a, 2, 'HARD', 'hard');
    e27_pool($a, 2, 'MEDIUM', 'medium');
    e27_pool($a, 2, 'EASY', 'easy');

    $order = array_map(fn ($r) => $r['puzzle']->difficulty, e27_reco($user)['puzzles']);

    expect($order)->toBe(['easy', 'easy', 'medium', 'medium', 'hard', 'hard']);
});

test('R6: among otherwise equal puzzles, the one many players are trying this week comes first', function () {
    $a = e27_category('شعبية');
    $user = e27_user();
    e27_solve($user, e27_pool($a, 1, 'SOLVED')[0]);
    $pool = e27_pool($a, 4, 'A');
    foreach ([e27_user(), e27_user()] as $other) {
        e27_attempt($other, $pool[1], false, now()->subHour());
    }

    expect(e27_reco_titles($user)[0])->toBe('A-2');
});

test('R7: new content gets a freshness boost - a recent puzzle outranks an old one with the same signals', function () {
    $a = e27_category('حداثة');
    $user = e27_user();
    e27_solve($user, e27_pool($a, 1, 'SOLVED')[0]);
    [$fresh, $stale] = e27_pool($a, 2, 'N');
    DB::table('puzzles')->where('id', $stale->id)->update(['created_at' => now()->subDays(40)]);

    expect(e27_reco_titles($user))->toBe(['N-1', 'N-2']);
    DB::table('puzzles')->where('id', $fresh->id)->update(['created_at' => now()->subDays(60)]);
    DB::table('puzzles')->where('id', $stale->id)->update(['created_at' => now()->subDay()]);
    expect(e27_reco_titles($user))->toBe(['N-2', 'N-1']);
});

test('R8: nothing is cached per user - history changes show at once and the cache holds only the public trending snapshot', function () {
    $a = e27_category('طازج');
    $user = e27_user();
    $pool = e27_pool($a, 3, 'F');
    e27_solve($user, e27_pool($a, 1, 'SOLVED')[0]);

    expect(e27_reco_titles($user))->toContain('F-1');
    e27_solve($user, $pool[0]);
    expect(e27_reco_titles($user))->not->toContain('F-1');   // لو كان هناك كاش لكل مستخدم لبقيت

    $store = Cache::getStore();
    $storage = new ReflectionProperty($store, 'storage');
    $storage->setAccessible(true);
    $keys = array_keys($storage->getValue($store));

    // مفاتيح البحث/الاكتشاف الوحيدة: لقطات الرائج العامة ومجسّ حجم الجدول. لا مفتاح باسم مستخدم أو توصيات. (مفاتيح أخرى كاقتصاد المنصة ليست من هذه الحزمة.)
    $ours = array_values(array_filter($keys, fn ($key) => preg_match('/search|reco|discover/i', $key)));

    expect($ours)->not->toBe([]);
    foreach ($ours as $key) {
        expect($key)->toMatch('/^search\.(trending\.(puzzles|teams)\.v1|probe\.[a-z_]+)(:lock)?$/', "unexpected cache key: {$key}");
    }
});

test('R9: two players never influence each other - each result depends only on their own history', function () {
    $a = e27_category('عزل أ');
    $b = e27_category('عزل ب');
    [$u1, $u2] = [e27_user(), e27_user()];
    e27_pool($a, 8, 'A');
    e27_pool($b, 8, 'B');
    e27_solve($u1, e27_pool($a, 1, 'S1')[0]);
    e27_solve($u2, e27_pool($b, 1, 'S2')[0]);

    $first1 = e27_reco_titles($u1);
    $second1 = e27_reco_titles($u2);

    expect(array_slice($first1, 0, 3))->each->toStartWith('A-')->and(array_slice($second1, 0, 3))->each->toStartWith('B-');
    expect(e27_reco_titles($u1))->toBe($first1)->and(e27_reco_titles($u2))->toBe($second1);
});

test('R10: friends, teams and chat are not signals - befriending a heavy player of another category changes nothing', function () {
    $a = e27_category('خصوصية أ');
    $b = e27_category('خصوصية ب');
    $user = e27_user();
    $friend = e27_user();
    e27_pool($a, 8, 'A');
    e27_pool($b, 8, 'B');
    e27_solve($user, e27_pool($a, 1, 'SOLVED-A')[0]);
    foreach (e27_pool($b, 5, 'FRIEND-SOLVED') as $puzzle) {
        e27_solve($friend, $puzzle);                  // لاعب واحد: دون حدّ الرائج، فلا يدخل المجاميع العامة
    }

    $before = e27_reco_titles($user);
    e16Befriend($user, $friend);
    $team = e19Team($friend);
    e19Member($team, $user);

    expect(e27_reco_titles($user))->toBe($before);
});

test('R11: the history window is capped by configuration - only the most recent distinct puzzles shape the affinity', function () {
    config(['search.recommendations.history_cap' => 1]);
    $a = e27_category('قديم');
    $b = e27_category('حديث');
    $user = e27_user();
    foreach (e27_pool($a, 3, 'OLD-SOLVED') as $puzzle) {
        e27_solve($user, $puzzle, now()->subDays(3));
    }
    e27_attempt($user, e27_pool($b, 1, 'RECENT-TRY')[0], false, now()->subHour());
    e27_pool($a, 8, 'A');
    e27_pool($b, 8, 'B');

    expect(array_slice(e27_reco_titles($user), 0, 3))->each->toStartWith('B-');
});

test('R12: the exploration share is configurable - zero means no exploration slots', function () {
    config(['search.recommendations.exploration_share' => 0]);
    $a = e27_category('بلا استكشاف أ');
    $b = e27_category('بلا استكشاف ب');
    $user = e27_user();
    e27_solve($user, e27_pool($a, 1, 'SOLVED')[0]);
    e27_pool($a, 8, 'A');
    e27_pool($b, 8, 'B');

    $reco = e27_reco($user);

    expect($reco['puzzles'])->toHaveCount(8)->and(collect($reco['puzzles'])->pluck('reason')->all())->not->toContain('تصنيف جديد لك')
        ->and(collect($reco['puzzles'])->map(fn ($r) => $r['puzzle']->title)->all())->each->toStartWith('A-');
});

test('R13: the result is bounded by the configured limit and every item says why it was suggested', function () {
    config(['search.recommendations.limit' => 3]);
    $a = e27_category('حدّ');
    $user = e27_user();
    e27_solve($user, e27_pool($a, 1, 'SOLVED')[0]);
    e27_pool($a, 10, 'A');

    $reco = e27_reco($user);

    expect($reco['puzzles'])->toHaveCount(3);
    foreach ($reco['puzzles'] as $row) {
        expect($row['reason'])->toBeString()->not->toBe('');
    }
});

test('R14: recommendations are read-only - no write queries and no effect on XP, currency, quests, streaks, achievements, purchases or attempts', function () {
    $a = e27_category('قراءة');
    $user = e27_user();
    e27_solve($user, e27_pool($a, 1, 'SOLVED')[0]);
    e27_pool($a, 5, 'A');
    $before = [...e16Snapshot(), PuzzleAttempt::count()];

    $sql = e27_sql(fn () => e27_reco($user));

    expect(array_values(array_filter($sql, fn ($q) => preg_match('/^\s*(insert|update|delete|replace)\b/i', $q))))->toBe([])
        ->and([...e16Snapshot(), PuzzleAttempt::count()])->toBe($before);
});

test('R15: campaigns - started and unfinished ones come back as continue, finished and unavailable ones do not, and new ones exclude everything already started', function () {
    $user = e27_user();
    $started = e27_campaign('بدأتها');
    $finished = e27_campaign('أنهيتها');
    $inactive = e27_campaign('معطّلة', ['is_active' => false]);
    $expired = e27_campaign('منتهية', ['ends_at' => now()->subDay()]);
    $newOne = e27_campaign('جديدة 1');
    $newTwo = e27_campaign('جديدة 2');
    foreach ([$started, $inactive, $expired] as $campaign) {
        e27_progress($user, $campaign);
    }
    e27_progress($user, $finished, true);

    $reco = e27_reco($user);

    expect($reco['continue_campaigns']->pluck('title')->all())->toBe(['بدأتها'])
        ->and($reco['campaigns']->pluck('title')->all())->toBe(['جديدة 2', 'جديدة 1']);
});

test('R16: competitions - public unfinished ones the player has not joined; teams - open ones, only for players without a team', function () {
    $user = e27_user();
    $open = e17Event(['title' => 'منافسة مفتوحة']);
    $joined = e17Event(['title' => 'منافسة مسجَّل بها']);
    e17Event(['title' => 'منافسة مسودة'], \App\Models\CompetitiveEvent::STATUS_DRAFT);
    e17Event(['title' => 'منافسة منتهية', 'starts_at' => now()->subDays(2), 'ends_at' => now()->subDay()]);
    \App\Models\CompetitiveEventParticipant::create(['competitive_event_id' => $joined->id, 'user_id' => $user->id, 'team_id_snapshot' => null, 'status' => 'completed', 'registered_at' => now()->subHour()]);

    $visible = e19Team(null, ['name' => 'فريق مفتوح']);
    $invite = e19Team(null, ['name' => 'فريق بدعوة']);
    $invite->forceFill(['join_policy' => Team::JOIN_INVITE_ONLY])->save();
    $private = e19Team(null, ['name' => 'فريق خاص']);
    $private->forceFill(['visibility' => Team::VISIBILITY_PRIVATE])->save();
    $full = e19Team(null, ['name' => 'فريق ممتلئ']);
    $full->forceFill(['max_members' => 1, 'members_count' => 1])->save();

    $reco = e27_reco($user);

    expect($reco['competitions']->pluck('title')->all())->toBe(['منافسة مفتوحة'])
        ->and($reco['teams']->pluck('name')->all())->toBe(['فريق مفتوح']);

    e19Member($visible, $user);
    expect(e27_reco($user)['teams']->all())->toBe([]);
});
`````

### `tests/Feature/DiscoveryE27/E27SearchTest.php`

`````php
<?php

require_once __DIR__.'/_support.php';

use App\Models\CompetitiveEvent;
use App\Models\Puzzle;
use App\Models\Team;
use App\Models\TeamChampionship;
use App\Models\User;

beforeEach(fn () => $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class));

test('1: the suggest endpoint answers a guest with grouped puzzles, relative URLs only and no internal ids', function () {
    $puzzle = e27_puzzle('لغز الذكاء الكبير');
    $json = e27_suggest($this, 'لغز');

    expect($json['state'])->toBe('ok')->and(e27_titles($json))->toBe(['لغز الذكاء الكبير']);
    $item = $json['groups'][0]['items'][0];
    expect($item['url'])->toBe('/puzzles/'.$puzzle->id)->and(array_keys($item))->toEqualCanonicalizing(['title', 'subtitle', 'badge', 'icon', 'url']);
    expect(json_encode($json))->not->toContain('http://')->not->toContain('"id"');
});

test('2: puzzle search never reads or exposes answers, hints, choices or solution data', function () {
    $secret = 'جواب-سري-فريد';
    $puzzle = e27_puzzle('أحجية عادية', ['answer_raw' => $secret, 'hint' => 'تلميح-سري-فريد']);

    expect(e27_titles(e27_suggest($this, 'جواب-سري')))->toBe([])
        ->and(e27_titles(e27_suggest($this, 'تلميح-سري')))->toBe([])
        ->and(e27_titles(e27_suggest($this, 'أحجية')))->toBe(['أحجية عادية']);

    $body = $this->getJson(route('search.suggest', ['q' => 'أحجية']))->getContent();
    expect($body)->not->toContain($secret)->not->toContain('تلميح-سري-فريد')->not->toContain((string) $puzzle->answer_hash)
        ->not->toContain(trim(json_encode($secret), '"'))->not->toContain(trim(json_encode('تلميح-سري-فريد'), '"'));   // JSON يهرّب العربية: نفحص الصيغة المهرَّبة أيضًا
});

test('3: inactive puzzles never appear, for guests, players or administrators alike', function () {
    e27_puzzle('أحجية مخفية', ['is_active' => false]);
    e27_puzzle('أحجية ظاهرة');
    $admin = e27_user();
    $admin->assignRole('administrator');

    foreach ([null, e27_user(), $admin] as $viewer) {
        expect(e27_titles(e27_suggest($this, 'أحجية', $viewer)))->toBe(['أحجية ظاهرة']);
    }
});

test('4: campaigns follow the public list rule - active and inside their window only', function () {
    e27_campaign('حملة مباشرة');
    e27_campaign('حملة مسودة', ['is_active' => false]);
    e27_campaign('حملة قادمة', ['starts_at' => now()->addDay()]);
    e27_campaign('حملة منتهية', ['ends_at' => now()->subDay()]);
    e27_campaign('حملة بنافذة', ['starts_at' => now()->subDay(), 'ends_at' => now()->addDay()]);

    expect(e27_group(e27_suggest($this, 'حملة'), 'campaigns'))->toEqualCanonicalizing(['حملة مباشرة', 'حملة بنافذة']);
});

test('5: seasons follow is_published and are titled from their campaign', function () {
    e27_season('موسم الربيع');
    e27_season('موسم مسودة', ['is_published' => false]);

    $json = e27_suggest($this, 'موسم');

    $group = collect($json['groups'])->firstWhere('type', 'seasons');
    expect(e27_group($json, 'seasons'))->toBe(['موسم الربيع'])->and($group['items'][0]['url'])->toStartWith('/seasons/')
        ->and(e27_group($json, 'campaigns'))->toBe(['موسم مسودة']);   // حملة الموسم المنشور تظهر كموسم فقط؛ حملة موسم غير منشور حملة عادية نشطة كما بقائمة /campaigns
});

test('6: competitions and team championships show only published or completed ones', function () {
    e27_user();
    e17Event(['title' => 'منافسة السطح']);
    e17Event(['title' => 'منافسة المسودة'], CompetitiveEvent::STATUS_DRAFT);
    e17Event(['title' => 'منافسة الملغاة'], CompetitiveEvent::STATUS_CANCELLED);
    e17Event(['title' => 'منافسة مكتملة'], CompetitiveEvent::STATUS_COMPLETED);

    foreach (['بطولة منشورة' => 'published', 'بطولة مسودة' => 'draft', 'بطولة ملغاة' => 'cancelled'] as $title => $status) {
        TeamChampionship::query()->forceCreate(['title' => $title, 'slug' => 'c-'.md5($title), 'status' => $status, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay()]);
    }

    expect(e27_group(e27_suggest($this, 'منافسة'), 'competitions'))->toEqualCanonicalizing(['منافسة السطح', 'منافسة مكتملة'])
        ->and(e27_group(e27_suggest($this, 'بطولة'), 'championships'))->toBe(['بطولة منشورة']);
});

test('7: only public active teams are discoverable - private, inactive and unrelated ones are not', function () {
    e19Team(null, ['name' => 'فريق النجوم']);
    $private = e19Team(null, ['name' => 'فريق سري']);
    $private->forceFill(['visibility' => Team::VISIBILITY_PRIVATE])->save();
    $off = e19Team(null, ['name' => 'فريق متوقف']);
    $off->forceFill(['is_active' => false])->save();

    $json = e27_suggest($this, 'فريق');
    expect(e27_group($json, 'teams'))->toBe(['فريق النجوم'])->and(json_encode($json))->not->toContain('owner')->not->toContain('email');
});

test('8: player search shows only people the searcher may see - public for guests, public and members for verified players, never private', function () {
    e27_user(['name' => 'سامر العام', 'profile_visibility' => User::VISIBILITY_PUBLIC]);
    e27_user(['name' => 'سامر الأعضاء', 'profile_visibility' => User::VISIBILITY_MEMBERS]);
    e27_user(['name' => 'سامر الخاص', 'profile_visibility' => User::VISIBILITY_PRIVATE]);

    expect(e27_group(e27_suggest($this, 'سامر'), 'players'))->toBe(['سامر العام'])
        ->and(e27_group(e27_suggest($this, 'سامر', e27_user(['name' => 'باحث'])), 'players'))->toEqualCanonicalizing(['سامر العام', 'سامر الأعضاء']);
});

test('9: player search excludes the searcher, frozen and unverified accounts', function () {
    $me = e27_user(['name' => 'ليلى أنا']);
    e27_user(['name' => 'ليلى مجمدة', 'is_frozen' => true]);
    e27_user(['name' => 'ليلى غير موثقة', 'email_verified_at' => null]);
    e27_user(['name' => 'ليلى سليمة']);

    expect(e27_group(e27_suggest($this, 'ليلى', $me), 'players'))->toBe(['ليلى سليمة']);
});

test('10: blocking hides players in both directions and never leaks that a block exists', function () {
    $me = e27_user(['name' => 'باحث']);
    $iBlocked = e27_user(['name' => 'منى محظورة']);
    $blockedMe = e27_user(['name' => 'منى حاظرة']);
    e27_user(['name' => 'منى عادية']);
    app(\App\Services\Social\BlockService::class)->block($me, $iBlocked);
    app(\App\Services\Social\BlockService::class)->block($blockedMe, $me);

    $json = e27_suggest($this, 'منى', $me);
    expect(e27_group($json, 'players'))->toBe(['منى عادية'])->and(json_encode($json))->not->toContain('block');
});

test('11: player results carry only the display name and a ULID profile link - no email, phone, numeric id or wallet', function () {
    $other = e27_user(['name' => 'ريم حسابها', 'email' => 'secret-rim@example.test']);
    $json = e27_suggest($this, 'ريم', e27_user(['name' => 'باحث']));

    $item = $json['groups'][0]['items'][0];
    expect($item['url'])->toBe('/players/'.$other->public_id);
    expect(json_encode($json))->not->toContain('secret-rim')->not->toContain('example.test')->not->toContain('"id"')->not->toContain('wallet');
    $html = $this->actingAs(e27_user())->get(route('search.index', ['q' => 'ريم']))->assertOk()->getContent();
    expect($html)->not->toContain('secret-rim')->and($html)->toContain('/players/'.$other->public_id);
});

test('12: a guest needs three letters to search players while other types answer from two', function () {
    e27_puzzle('نمر الغابة');
    e27_user(['name' => 'نمر اللاعب']);

    $two = e27_suggest($this, 'نم');
    expect(e27_types($two))->toBe(['puzzles'])->and($two['note'])->toContain('3');
    expect(e27_types(e27_suggest($this, 'نمر')))->toBe(['puzzles', 'players']);
    expect(e27_types(e27_suggest($this, 'نم', e27_user())))->toBe(['puzzles', 'players']);
});

test('13: empty, one-letter and whitespace-only input answer empty or too_short without touching the database', function () {
    e27_puzzle('أحجية');
    $queries = e27_queries(function () {
        foreach (['' => 'empty', ' ' => 'empty', 'أ' => 'too_short', "\t\n" => 'empty', '%' => 'too_short'] as $q => $state) {
            expect($this->getJson(route('search.suggest', ['q' => $q]))->assertOk()->json('state'))->toBe($state);
        }
    });

    expect($queries)->toBe(0);
});

test('14: Arabic variants find each other - hamza, ta marbuta, alef maksura, tatweel and diacritics are ignored', function (string $typed) {
    e27_puzzle('أُحْجِيَّةُ المدينة');

    expect(e27_group(e27_suggest($this, $typed), 'puzzles'))->toBe(['أُحْجِيَّةُ المدينة']);
})->with(['احجيه', 'أحجية', 'احجية', 'ـأحـجـيـة', 'احجيه المدينه', 'مدينه', 'الاحجية', 'المدينة']);

test('15: Arabic-Indic and Persian digits match ASCII digits', function () {
    e27_puzzle('لغز رقم 12');

    foreach (['رقم ١٢', 'رقم ۱۲', 'رقم 12'] as $q) {
        expect(e27_group(e27_suggest($this, $q), 'puzzles'))->toBe(['لغز رقم 12'], $q);
    }
});

test('16: Latin text matches case-insensitively and Turkish dotted/dotless i fold together', function () {
    e27_puzzle('Istanbul Riddle');

    foreach (['istanbul', 'ISTANBUL', 'İstanbul', 'ıstanbul', 'riddle'] as $q) {
        expect(e27_group(e27_suggest($this, $q), 'puzzles'))->toBe(['Istanbul Riddle'], $q);
    }
});

test('17: zero-width and control characters inside the term are removed before matching', function () {
    e27_puzzle('لغز البحر');

    foreach (["لغز\u{200B}البحر", "لغز\u{200F} البحر", "لغز\x00البحر", "\u{FEFF}لغز البحر"] as $q) {
        expect(e27_group(e27_suggest($this, $q), 'puzzles'))->toBe(['لغز البحر']);
    }
});

test('18: LIKE wildcards in the term are literal - % and _ never match everything', function () {
    e27_puzzle('لغز عادي');
    e27_puzzle('نسبة 100% صحيحة');
    e27_puzzle('اسم_مخصوص');

    expect(e27_titles(e27_suggest($this, '%%')))->toBe([])
        ->and(e27_titles(e27_suggest($this, '__')))->toBe([])
        ->and(e27_group(e27_suggest($this, '100%'), 'puzzles'))->toBe(['نسبة 100% صحيحة'])
        ->and(e27_group(e27_suggest($this, 'اسم_م'), 'puzzles'))->toBe(['اسم_مخصوص']);
});

test('19: relevance orders exact first, then prefix, then word prefix, then partial - ties break by newest', function () {
    e27_puzzle('الحوت الأزرق في البحر');   // partial (داخل «البحر»)
    e27_puzzle('بحرا');                       // prefix
    e27_puzzle('بحر');                        // exact
    e27_puzzle('سبحر عميق');                  // partial
    e27_puzzle('بحر الظلمات');                // prefix, newer than 'بحرا'
    e27_puzzle('عالم بحر الحكايات');          // word prefix

    $titles = app(\App\Services\Search\GlobalSearchService::class)->ofType('puzzles', 'بحر', null, 1)['results']->pluck('title')->all();
    $tiers = app(\App\Services\Search\GlobalSearchService::class)->ofType('puzzles', 'بحر', null, 1)['results']->pluck('tier')->all();

    expect($titles)->toBe(['بحر', 'بحر الظلمات', 'بحرا', 'عالم بحر الحكايات', 'سبحر عميق', 'الحوت الأزرق في البحر'])->and($tiers)->toBe([0, 1, 1, 2, 3, 3]);
});

test('20: tiers are exposed to the page ordering - a group holding an exact match comes before groups holding only partial ones', function () {
    e27_puzzle('لغز فريق النجوم');           // puzzles: partial (word)
    e19Team(null, ['name' => 'فريق']);       // teams: exact

    $json = e27_suggest($this, 'فريق');
    expect(e27_types($json))->toBe(['teams', 'puzzles']);
});

test('21: a multi-word term needs every word, in any order', function () {
    e27_puzzle('لغز الذكاء الكبير');
    e27_puzzle('لغز الذكاء فقط');

    expect(e27_group(e27_suggest($this, 'كبير ذكاء'), 'puzzles'))->toBe(['لغز الذكاء الكبير']);
});

test('22: suggestions are capped per type and in total, with a link to the full list when more exist', function () {
    foreach (range(1, 8) as $n) {
        e27_puzzle("لغز رقم {$n}");
    }
    foreach (range(1, 8) as $n) {
        e19Team(null, ['name' => "لغز فريق {$n}"]);
    }
    $json = e27_suggest($this, 'لغز');

    expect(count(e27_titles($json)))->toBeLessThanOrEqual(9)->and(count(e27_group($json, 'puzzles')))->toBeLessThanOrEqual(3)
        ->and(collect($json['groups'])->firstWhere('type', 'puzzles')['more_url'])->toBe('/search?q=%D9%84%D8%BA%D8%B2&type=puzzles');
});

test('23: the full search page works without JavaScript - a plain GET form, grouped results, tabs and one h1', function () {
    e27_puzzle('لغز البحر');
    e19Team(null, ['name' => 'لغز الفريق']);
    $x = uiXpath($this->get(route('search.index', ['q' => 'لغز']))->assertOk()->getContent());

    expect($x->query('//h1')->length)->toBe(1)
        ->and($x->query('//main//form[@method="GET"][@role="search"][@action="'.route('search.index').'"]//input[@name="q"]')->length)->toBe(1)
        ->and($x->query('//main//section[@aria-label="الأحجيات"]//a')->length)->toBe(1)
        ->and($x->query('//main//section[@aria-label="الفرق"]//a')->length)->toBe(1)
        ->and($x->query('//nav[@aria-label="نوع النتائج"]//a[@aria-current="page"]')->item(0)->textContent)->toBe('الكل');
});

test('24: a type tab shows only that type, keeps the term in its links and paginates without a COUNT', function () {
    foreach (range(1, 14) as $n) {
        e27_puzzle("لغز {$n}");
    }
    $page1 = $this->get(route('search.index', ['q' => 'لغز', 'type' => 'puzzles']))->assertOk();
    $page2 = $this->get(route('search.index', ['q' => 'لغز', 'type' => 'puzzles', 'page' => 2]))->assertOk();

    $x1 = uiXpath($page1->getContent());
    expect($x1->query('//main//section//ul/li')->length)->toBe(12)->and($x1->query('//a[@rel="next"]')->length)->toBe(1);
    expect(uiXpath($page2->getContent())->query('//main//section//ul/li')->length)->toBe(2);
    expect(uiXpath($page2->getContent())->query('//a[@rel="next"]')->length)->toBe(0);
});

test('25: result pages stop at the depth cap and say so - guests reach fewer pages than players', function () {
    config(['search.page_size' => 2, 'search.max_page' => 3, 'search.guest_max_page' => 2]);
    foreach (range(1, 12) as $n) {
        e27_puzzle("لغز {$n}");
    }

    $guest = $this->get(route('search.index', ['q' => 'لغز', 'type' => 'puzzles', 'page' => 9]))->assertOk();
    $player = $this->actingAs(e27_user())->get(route('search.index', ['q' => 'لغز', 'type' => 'puzzles', 'page' => 9]))->assertOk();

    expect(uiXpath($guest->getContent())->query('//a[@rel="next"]')->length)->toBe(0)->and($guest->getContent())->toContain('أعلى النتائج صلة');
    expect(uiXpath($player->getContent())->query('//a[@rel="next"]')->length)->toBe(0)->and($player->getContent())->toContain('أعلى النتائج صلة');
    // الصفحة الفعلية المعروضة = السقف، لا الصفحة المطلوبة
    expect(uiXpath($guest->getContent())->query('//main//section//ul/li')->length)->toBe(2);
});

test('26: hostile or malformed parameters never fail - arrays, unknown types, huge pages and invalid UTF-8 are tolerated', function () {
    e27_puzzle('لغز');

    foreach ([['q' => ['a', 'b']], ['q' => 'لغز', 'type' => 'users'], ['q' => 'لغز', 'type' => ['x']], ['q' => 'لغز', 'page' => '999999999'], ['q' => 'لغز', 'page' => '-1'], ['q' => 'لغز', 'page' => ['1']], ['q' => "\xC3\x28"], ['q' => str_repeat('ا', 5000)]] as $params) {
        $this->get(route('search.index', $params))->assertOk();
        $this->getJson(route('search.suggest', $params))->assertOk();
    }
});

test('27: very long terms are truncated to the configured length, not rejected', function () {
    e27_puzzle(str_repeat('ا', 70));
    $json = e27_suggest($this, str_repeat('ا', 300));

    expect(mb_strlen($json['query']))->toBe(64)->and(e27_titles($json))->toBe([str_repeat('ا', 70)]);
});

test('28: with no match the page says so and offers a way forward instead of an empty screen', function () {
    $html = $this->get(route('search.index', ['q' => 'لاشيء-مطابق']))->assertOk()->getContent();

    expect($html)->toContain('لا نتائج لـ')->and($html)->toContain(route('discover.index'));
});

test('29: a term below the minimum explains the minimum and a missing term invites typing', function () {
    expect($this->get(route('search.index', ['q' => 'أ']))->assertOk()->getContent())->toContain('الكلمة قصيرة جدًا')
        ->and($this->get(route('search.index'))->assertOk()->getContent())->toContain('ابدأ بكتابة ما تبحث عنه');
});

test('30: the term is echoed escaped on the page and inside the input', function () {
    $payload = '"><script>alert(1)</script><img src=x onerror=alert(2)>';
    $html = $this->get(route('search.index', ['q' => $payload]))->assertOk()->getContent();

    expect($html)->not->toContain('<script>alert(1)')->not->toContain('<img src=x onerror');
});

test('31: result titles with markup are escaped on the page and kept as plain text in JSON', function () {
    e27_puzzle('<script>alert(1)</script> لغز');
    $html = $this->get(route('search.index', ['q' => 'script']))->assertOk()->getContent();

    expect($html)->not->toContain('<script>alert(1)</script> لغز');
    expect(e27_titles(e27_suggest($this, 'script')))->toBe(['<script>alert(1)</script> لغز']);
});

test('32: the suggest response is private and never cacheable by shared caches', function () {
    $response = $this->getJson(route('search.suggest', ['q' => 'لغز']))->assertOk();

    expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
});

test('33: search is read-only - no write queries run and no table changes', function () {
    e27_puzzle('لغز للقراءة');
    e27_user(['name' => 'لاعب للقراءة']);
    $viewer = e27_user();

    $sql = e27_sql(function () use ($viewer) {
        $this->getJson(route('search.suggest', ['q' => 'للقراءة']))->assertOk();
        $this->get(route('search.index', ['q' => 'للقراءة']))->assertOk();
        $this->actingAs($viewer)->get(route('search.index', ['q' => 'للقراءة', 'type' => 'players']))->assertOk();
    });

    // الكاش array والجلسة array: أي كتابة هنا خلل، عدا ما يفعله إطار الصفحة القائم لأي صفحة (E11/E12: إنشاء صف مستوى اللاعب عند أول عرض للرأس)، وليس من البحث.
    $writes = array_values(array_filter($sql, fn ($q) => preg_match('/^\s*(insert|update|delete|replace)\b/i', $q) && ! str_contains($q, '"player_progressions"')));
    expect($writes)->toBe([]);
});

test('34: a banned or frozen searcher still gets only public results and no private player data', function () {
    $frozen = e27_user(['name' => 'مجمّد', 'is_frozen' => true]);
    e27_user(['name' => 'سلمى الأعضاء', 'profile_visibility' => User::VISIBILITY_MEMBERS]);
    e27_user(['name' => 'سلمى العامة']);

    expect(e27_group(e27_suggest($this, 'سلمى', $frozen), 'players'))->toBe(['سلمى العامة']);
});

test('35: an unverified searcher is treated like a guest for player results', function () {
    $unverified = e27_user(['name' => 'غير موثق', 'email_verified_at' => null]);
    e27_user(['name' => 'هالة الأعضاء', 'profile_visibility' => User::VISIBILITY_MEMBERS]);
    e27_user(['name' => 'هالة العامة']);

    expect(e27_group(e27_suggest($this, 'هالة', $unverified), 'players'))->toBe(['هالة العامة']);
});
`````

### `tests/Feature/DiscoveryE27/E27SecurityTest.php`

`````php
<?php

require_once __DIR__.'/_support.php';

use App\Models\Puzzle;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/*
| E27-E - الأمان: تحديد المعدّل (ضيف/مسجَّل، مفتاحه المستخدم لا IP فقط)، حقن SQL، التعداد، البيانات الحساسة، المخرجات، وتدقيق ساكن للشيفرة الجديدة.
| الاستعلامات الخبيثة هنا مدخلات اختبار محلية على قاعدة اختبار؛ لا شبكة ولا هدف حقيقي.
*/

beforeEach(fn () => $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class));

if (! function_exists('e27_limits')) {
    /** @return list<\Illuminate\Cache\RateLimiting\Limit> */
    function e27_limits(string $name, ?User $user = null, string $ip = '203.0.113.7'): array
    {
        $request = Request::create('/search', 'GET', [], [], [], ['REMOTE_ADDR' => $ip]);
        $request->setUserResolver(fn () => $user);
        $limit = (RateLimiter::limiter($name))($request);

        return is_array($limit) ? array_values($limit) : [$limit];
    }

    /** @return list<string> ملفات الشيفرة الجديدة التي تُدقَّق ساكنًا (بلا الاختبارات). */
    function e27_code_files(): array
    {
        return array_merge(
            e26_php_files(['app/Services/Search', 'app/Services/Discovery']),
            [base_path('app/Http/Controllers/SearchController.php'), base_path('app/Http/Controllers/DiscoverController.php'), base_path('app/Http/Requests/SearchRequest.php')],
        );
    }
}

test('S1: the limiters are keyed per user for players and per IP for guests, with a per-minute cap and a short burst cap', function () {
    $a = e27_user();
    $b = e27_user();

    [$guest] = e27_limits('global-search');
    [$userA] = e27_limits('global-search', $a);
    [$userB] = e27_limits('global-search', $b);
    expect([$guest->maxAttempts, $guest->decaySeconds])->toBe([20, 60])
        ->and([$userA->maxAttempts, $userA->decaySeconds])->toBe([60, 60])
        ->and($userA->key)->not->toBe($userB->key)->and($userA->key)->not->toBe($guest->key)
        ->and($guest->key)->not->toBe(e27_limits('global-search', null, '203.0.113.8')[0]->key);

    [$gMinute, $gBurst] = e27_limits('search-suggest');
    [$uMinute, $uBurst] = e27_limits('search-suggest', $a);
    expect([$gMinute->maxAttempts, $gMinute->decaySeconds, $gBurst->maxAttempts, $gBurst->decaySeconds])->toBe([40, 60, 10, 2])
        ->and([$uMinute->maxAttempts, $uMinute->decaySeconds, $uBurst->maxAttempts, $uBurst->decaySeconds])->toBe([120, 60, 16, 2])
        ->and($uMinute->key)->not->toBe($uBurst->key);

    [$discoverGuest] = e27_limits('discover');
    [$discoverUser] = e27_limits('discover', $a);
    expect([$discoverGuest->maxAttempts, $discoverGuest->decaySeconds])->toBe([90, 60])->and($discoverUser->key)->not->toBe($discoverGuest->key);
});

test('S2: routes are GET-only, carry their limiter, and expose no write verb for search or discovery', function () {
    $expected = ['search.index' => 'global-search', 'search.suggest' => 'search-suggest', 'discover.index' => 'discover'];

    foreach ($expected as $name => $limiter) {
        $route = Route::getRoutes()->getByName($name);

        expect($route)->not->toBeNull()->and($route->methods())->toBe(['GET', 'HEAD'])->and($route->gatherMiddleware())->toContain('throttle:'.$limiter);
    }

    $bad = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => preg_match('#^(search|discover)(/|$)#', $r->uri()) && array_diff($r->methods(), ['GET', 'HEAD']) !== []);
    expect($bad->count())->toBe(0);
});

test('S3: a guest hammering the search page is stopped at the 21st request while a signed-in player on the same address is unaffected', function () {
    $statuses = e26_hammer($this, 'get', route('search.index', ['q' => 'نص']), 21);

    expect(e26_first_429($statuses))->toBe(21);
    $this->actingAs(e27_user())->get(route('search.index', ['q' => 'نص']))->assertOk();
});

test('S4: the suggest burst limits stop rapid typing floods - guests at the 11th request in two seconds, players at the 17th - with Retry-After', function () {
    $guest = e26_hammer($this, 'get', route('search.suggest', ['q' => 'نص']), 12, [], true);
    expect(e26_first_429($guest))->toBe(11);
    $this->getJson(route('search.suggest', ['q' => 'نص']))->assertStatus(429)->assertHeader('Retry-After');

    $player = e27_user();
    $this->actingAs($player);
    $statuses = e26_hammer($this, 'get', route('search.suggest', ['q' => 'نص']), 18, [], true);
    expect(e26_first_429($statuses))->toBe(17);

    // لاعب آخر من العنوان نفسه لا يتأثر (المفتاح هو المستخدم لا العنوان).
    $this->actingAs(e27_user())->getJson(route('search.suggest', ['q' => 'نص']))->assertOk();
});

test('S5: injection-shaped input is inert on every search entry point - no SQL error, no leaked internals, no table touched', function () {
    $this->withoutMiddleware(ThrottleRequests::class);
    e27_puzzle('Ordinary Puzzle');
    e27_user(['name' => 'Ordinary Person']);
    e19Team(null, ['name' => 'Ordinary Team']);
    $viewer = e27_user();
    $counts = fn () => [Puzzle::count(), User::count(), DB::table('teams')->count(), DB::table('puzzle_attempts')->count()];
    $before = $counts();
    $payloads = ["' OR '1'='1", "'; DROP TABLE puzzles; --", '" OR ""="', "1) UNION SELECT email,password FROM users --", "%' ESCAPE '!' OR 1=1 --", '%', '_', '___', '\\', '!%', "\0", "Robert'); DROP TABLE users;--", '١٢٣٪', '${7*7}', '{{7*7}}', '<?php phpinfo(); ?>'];

    foreach ($payloads as $payload) {
        foreach ([[route('search.index'), ['q' => $payload]], [route('search.index'), ['q' => $payload, 'type' => 'players']], [route('search.index'), ['q' => 'نص', 'type' => $payload]], [route('search.suggest'), ['q' => $payload]]] as [$url, $query]) {
            foreach ([$this, $this->actingAs($viewer)] as $caller) {
                $response = $caller->get($url.'?'.http_build_query($query), ['Accept' => str_contains($url, 'suggest') ? 'application/json' : 'text/html']);
                $body = $response->getContent();

                expect($response->status())->toBeLessThan(500)->and($body)->not->toContain('SQLSTATE')->not->toContain('Illuminate\\')->not->toContain('Stack trace');
            }
        }
    }

    expect($counts())->toBe($before);
    expect(e27_titles(e27_suggest($this, '%')))->toBe([])->and(e27_titles(e27_suggest($this, '___')))->toBe([])->and(e27_titles(e27_suggest($this, "' OR '1'='1")))->toBe([]);
});

test('S6: names cannot be enumerated - wildcards and short probes find no players, and a hidden player looks exactly like a missing one', function () {
    $this->withoutMiddleware(ThrottleRequests::class);
    e27_user(['name' => 'Samir Public']);
    e27_user(['name' => 'Samir Hidden', 'profile_visibility' => User::VISIBILITY_PRIVATE]);
    $viewer = e27_user(['name' => 'Searcher']);

    foreach (['%', '%%%', '___', '_', 'S', 'Sa', '%am'] as $probe) {
        expect(e27_group(e27_suggest($this, $probe), 'players'))->toBe([], "guest probe {$probe}");
    }
    expect(e27_group(e27_suggest($this, 'Sa', $viewer), 'players'))->toBe(['Samir Public'])->and(e27_group(e27_suggest($this, '%', $viewer), 'players'))->toBe([]);

    $hidden = e27_suggest($this, 'Samir Hidden', $viewer);
    $missing = e27_suggest($this, 'Zzzzz Nobody', $viewer);
    expect(array_diff_key($hidden, ['query' => 1, 'all_url' => 1]))->toBe(array_diff_key($missing, ['query' => 1, 'all_url' => 1]));
});

test('S7: no secret ever reaches a response - emails, password hashes, tokens, answers, hints and answer hashes stay out of JSON and HTML', function () {
    $this->withoutMiddleware(ThrottleRequests::class);
    $secretUser = e27_user(['name' => 'سلمان الظاهر', 'email' => 'salman-secret@example.test']);
    $puzzle = e27_puzzle('أحجية سلمان', ['answer_raw' => 'إجابة-سرية-فريدة', 'hint' => 'تلميح-سري-فريد']);
    $viewer = e27_user();
    $needles = ['salman-secret', 'example.test', (string) $secretUser->password, (string) $secretUser->remember_token, 'إجابة-سرية-فريدة', 'تلميح-سري-فريد', (string) $puzzle->answer_hash, 'two_factor', 'api_token'];

    $bodies = [
        $this->getJson(route('search.suggest', ['q' => 'سلمان']))->getContent(),
        $this->actingAs($viewer)->getJson(route('search.suggest', ['q' => 'سلمان']))->getContent(),
        $this->actingAs($viewer)->get(route('search.index', ['q' => 'سلمان']))->getContent(),
        $this->actingAs($viewer)->get(route('search.index', ['q' => 'سلمان', 'type' => 'players']))->getContent(),
        $this->actingAs($viewer)->get(route('discover.index'))->getContent(),
        $this->get(route('discover.index'))->getContent(),
    ];

    foreach ($bodies as $body) {
        foreach (array_filter($needles) as $needle) {
            // JSON يهرّب الحروف غير اللاتينية (\uXXXX): نفحص الصيغتين كي لا يكون الفحص فارغًا للأسرار العربية.
            expect($body)->not->toContain($needle)->not->toContain(trim(json_encode($needle), '"'));
        }
    }
    expect(e27_titles(json_decode($bodies[0], true)))->toContain('أحجية سلمان')->toContain('سلمان الظاهر');
});

test('S8: player results are privacy-shaped at the query level - the SELECT list is a short allow-list with no email, password, token or wallet column', function () {
    e27_user(['name' => 'ندى الاختبار']);
    $viewer = e27_user();
    $sql = collect(e27_sql(fn () => $this->actingAs($viewer)->getJson(route('search.suggest', ['q' => 'ندى']))->assertOk()))->filter(fn ($q) => str_contains($q, 'search_tier'));

    expect($sql->count())->toBeGreaterThanOrEqual(7);   // نوع واحد لكل مصدر
    foreach ($sql as $query) {
        preg_match('/^select (.*?), CASE WHEN/s', $query, $m);
        $columns = $m[1] ?? '';

        expect($columns)->not->toBe('')->not->toContain('*')->not->toMatch('/email|password|remember_token|two_factor|api_token|phone|balance|wallet|answer/i');
        if (str_contains($query, 'from "users"')) {
            expect($columns)->toBe('"users"."id", "users"."name", "users"."public_id", "users"."profile_visibility"');
        }
    }
});

test('S9: no trace of searches is stored - no new tables, no query-bearing cache keys, no logging in the search code', function () {
    $this->withoutMiddleware(ThrottleRequests::class);
    foreach (['search_queries', 'search_logs', 'search_events', 'search_analytics', 'discovery_events', 'recommendation_cache', 'user_search_history'] as $table) {
        expect(Schema::hasTable($table))->toBeFalse($table);
    }

    $migrations = array_filter(glob(base_path('database/migrations/*.php')), fn ($f) => preg_match('/e27|search/i', basename($f)));
    expect($migrations)->toHaveCount(1);
    foreach ($migrations as $file) {
        expect(file_get_contents($file))->not->toContain('Schema::create(')->not->toContain('->fullText(');
    }

    $sentinel = 'مصطلح-فريد-للبحث-'.bin2hex(random_bytes(3));
    $this->getJson(route('search.suggest', ['q' => $sentinel]))->assertOk();
    $this->get(route('search.index', ['q' => $sentinel]))->assertOk();
    $store = \Illuminate\Support\Facades\Cache::getStore();
    $property = new ReflectionProperty($store, 'storage');
    $property->setAccessible(true);
    expect(json_encode(array_keys($property->getValue($store))))->not->toContain('مصطلح-فريد');

    foreach (e27_code_files() as $file) {
        expect(preg_match('/\bLog::|\blogger\(|\breport\(|\binfo\(|\bwarning\(|\berror_log\(|\bdump\(|\bdd\(|\bvar_dump\(|\bprint_r\(/', e26_php_code($file)))->toBe(0, e26_relative($file));
    }
});

test('S10: query values only travel as bindings - the raw SQL fragments in the search sources are built from validated identifiers and constants', function () {
    $source = e26_php_code(base_path('app/Services/Search/Sources/EloquentSource.php'));

    // الأسطر التي تبني نص SQL لا تذكر مدخل المستخدم؛ القيم تذهب في مصفوفات الربط فقط.
    preg_match_all('/\$(?:rawLike|foldLike|tierSql)\s*=\s*[^;]+;/', $source, $assignments);
    expect($assignments[0])->not->toBe([]);
    foreach ($assignments[0] as $line) {
        expect($line)->not->toContain('$query')->not->toContain('$term')->not->toContain('request(')->not->toContain('$_GET');
    }
    expect($source)->toContain('TextFold::identifier(')->toContain('TextFold::sqlFold(');

    foreach (e27_code_files() as $file) {
        if (str_ends_with($file, 'EloquentSource.php') || str_ends_with($file, 'TextFold.php')) {
            continue;
        }
        // أي استدعاء خام آخر: نص ثابت بلا استيفاء متغيّرات.
        preg_match_all('/(?:whereRaw|orWhereRaw|selectRaw|orderByRaw|havingRaw|groupByRaw|DB::raw)\(\s*(["\'])(.*?)\1/s', e26_php_code($file), $raw, PREG_SET_ORDER);
        foreach ($raw as $match) {
            expect($match[2])->not->toMatch('/\$[a-zA-Z_]|\{\$/', e26_relative($file).': '.$match[2]);
        }
    }

    foreach (['app/Services/Search/TextFold.php'] as $relative) {
        expect(e26_php_code(base_path($relative)))->toContain('/^[a-z_][a-z0-9_]*(\\.[a-z_][a-z0-9_]*)?$/');
    }
});

test('S11: the search and discovery code is read-only - no create/insert/update/delete/save/increment and no raw writes', function () {
    foreach (e27_code_files() as $file) {
        $code = e26_php_code($file);

        expect(preg_match('/->(?:create|insert|insertGetId|insertOrIgnore|update|updateOrCreate|upsert|delete|forceDelete|save|saveQuietly|increment|decrement|touch|forceFill|fill)\(|::(?:create|insert|upsert)\(|DB::(?:insert|update|delete|statement|unprepared)\(/', $code))->toBe(0, e26_relative($file));
    }
});

test('S12: trending and recommendations are separated from every reward system - no economy, XP, quest, streak, achievement, redemption, odds or score class is referenced', function () {
    foreach (e27_code_files() as $file) {
        $code = e26_php_code($file);

        expect(preg_match('/\b(?:Wallet\w*|Currency\w*|Xp\w*|Redemption\w*|Quest\w*|Streak\w*|Achievement\w*|Purchase\w*|Economy\w*|\w*RewardService|\w*Odds\w*|\w*Qualification\w*|Sponsor\w*|AdPlacement\w*|Payment\w*|Stripe\w*)\b/', $code, $m))->toBe(0, e26_relative($file).' -> '.($m[0] ?? ''));
    }
});

test('S13: puzzle search is title-only - the search layer never mentions answers, hints, choices, solution data, game config or the prompt', function () {
    foreach (e26_php_files(['app/Services/Search']) as $file) {
        expect(preg_match('/answer_hash|answer_raw|answer_normal|\bhint\b|solution_data|choices|game_config|\bprompt\b|image_path/i', e26_php_code($file)))->toBe(0, e26_relative($file));
    }
});

test('S14: no external search engine, vector store or AI dependency was added - composer and npm manifests stay clean', function () {
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true);
    $npm = json_decode((string) file_get_contents(base_path('package.json')), true);
    $deps = array_keys(array_merge($composer['require'] ?? [], $composer['require-dev'] ?? [], $npm['dependencies'] ?? [], $npm['devDependencies'] ?? []));

    foreach ($deps as $dependency) {
        expect($dependency)->not->toMatch('/scout|meilisearch|algolia|typesense|elastic|opensearch|solr|sphinx|tntsearch|fuse\.?js|lunr|flexsearch|minisearch|pgvector|qdrant|pinecone|weaviate|openai|anthropic|langchain|transformers/i');
    }
    expect(config('search.driver'))->toBe('database');
});

test('S15: the search backend is an abstraction - an unknown driver falls back to the database one instead of breaking the site', function () {
    config(['search.driver' => 'some-future-engine']);
    app()->forgetInstance(\App\Services\Search\Contracts\SearchBackend::class);

    expect(app(\App\Services\Search\Contracts\SearchBackend::class))->toBeInstanceOf(\App\Services\Search\DatabaseSearchBackend::class);
});

test('S16: self-play and fake accounts cannot manufacture trending - thirty unverified accounts and one account with a thousand attempts rank nothing', function () {
    $this->withoutMiddleware(ThrottleRequests::class);
    $target = e27_puzzle('أحجية مستهدفة');
    foreach (range(1, 30) as $i) {
        e27_attempt(e27_user(['email_verified_at' => null]), $target, true, now()->subHour());
    }
    $bot = e27_user();
    DB::table('puzzle_attempts')->insert(array_map(fn ($n) => ['user_id' => $bot->id, 'puzzle_id' => $target->id, 'attempt_number' => $n, 'is_correct' => false, 'used_hint' => false, 'context_type' => null, 'created_at' => now()->subHour(), 'updated_at' => now()->subHour()], range(1, 1000)));

    expect(e27_trending()->puzzleScores())->toBe([]);
    $html = $this->get(route('discover.index'))->assertOk()->getContent();
    expect(uiXpath($html)->query('//section[@data-discover-section="trending"]')->length)->toBe(0);
});
`````

### `tests/Feature/DiscoveryE27/E27TrendingTest.php`

`````php
<?php

require_once __DIR__.'/_support.php';

use App\Models\CompetitiveEvent;
use App\Models\Puzzle;
use App\Models\PuzzleAttempt;
use App\Models\Team;
use App\Services\Discovery\TrendingService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
| E27-C - الرائج. إشارات عامة مجمَّعة فقط: لاعبون مميَّزون لا محاولات، نافذة زمنية، أقدمية، حلّالون، حدّ أدنى، استبعاد غير الفعّال/المجمَّد/غير الموثَّق/السياقات.
| الكاش يحمل المعرّفات والدرجات فقط. لا كتابة ولا أثر على أي اقتصاد.
*/

beforeEach(fn () => $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class));

test('T1: trending counts distinct players, not attempts - one player hammering a puzzle never outranks a broad audience', function () {
    $hammered = e27_puzzle('أحجية مكررة');
    $broad = e27_puzzle('أحجية واسعة');
    $one = e27_user();

    for ($i = 1; $i <= 50; $i++) {
        e27_attempt($one, $hammered, false, now()->subHour(), $i);
    }
    e27_play($broad, 3);

    $scores = e27_trending()->puzzleScores();

    expect(array_keys($scores))->toBe([$broad->id])->and($scores[$broad->id]['players'])->toBe(3);
});

test('T2: the minimum number of distinct players is configurable and applies to the whole list', function () {
    $single = e27_puzzle('لاعب واحد');
    $broad = e27_puzzle('جمهور واسع');
    e27_attempt(e27_user(), $single, false, now()->subHour());
    e27_play($broad, 3);

    expect(array_keys(e27_trending()->puzzleScores()))->toBe([$broad->id]);

    config(['search.trending.min_unique_players' => 1]);
    e27_cold();

    expect(array_keys(e27_trending()->puzzleScores()))->toBe([$broad->id, $single->id]);
});

test('T3: only the configured window counts - attempts older than the window never contribute', function () {
    $old = e27_puzzle('قديمة');
    $mid = e27_puzzle('وسطى');
    e27_play($old, 2, now()->subDays(8));     // أقدم أولًا: المعرّف يزداد مع الزمن كما بالإنتاج
    e27_play($mid, 2, now()->subDays(5));

    expect(e27_trending()->puzzleScores())->toHaveKey($mid->id)->not->toHaveKey($old->id);

    config(['search.trending.window_days' => 3]);
    e27_cold();
    expect(e27_trending()->puzzleScores())->toBe([]);

    config(['search.trending.window_days' => 30]);
    e27_cold();
    expect(array_keys(e27_trending()->puzzleScores()))->toEqualCanonicalizing([$old->id, $mid->id]);
});

test('T4: score = 4*(players 24h) + 2*(players 3d) + 1*(players window) + 2*solvers, so recent activity outranks old activity', function () {
    $week = e27_puzzle('قبل خمسة أيام');
    $mid = e27_puzzle('قبل يومين');
    $fresh = e27_puzzle('قبل ساعتين');
    e27_play($week, 2, now()->subDays(5));
    e27_play($mid, 2, now()->subDays(2));
    e27_play($fresh, 2, now()->subHours(2));

    $scores = e27_trending()->puzzleScores();

    expect(array_keys($scores))->toBe([$fresh->id, $mid->id, $week->id])
        ->and($scores[$fresh->id]['score'])->toBe(4 * 2 + 2 * 2 + 1 * 2)
        ->and($scores[$mid->id]['score'])->toBe(2 * 2 + 1 * 2)
        ->and($scores[$week->id]['score'])->toBe(1 * 2);
});

test('T5: players who solved the puzzle add the solver weight', function () {
    $plain = e27_puzzle('بلا حلول');
    $solved = e27_puzzle('بحلّالين');
    e27_play($plain, 2);
    e27_play($solved, 2, null, 1);

    $scores = e27_trending()->puzzleScores();

    expect($scores[$solved->id]['score'])->toBe($scores[$plain->id]['score'] + 2)->and(array_key_first($scores))->toBe($solved->id);
});

test('T6: weights come from configuration, not from code', function () {
    config(['search.trending.recency_weights' => ['d1' => 10, 'd3' => 0, 'd7' => 0], 'search.trending.solver_weight' => 0]);
    $puzzle = e27_puzzle('أوزان مخصصة');
    e27_play($puzzle, 2, null, 2);
    e27_cold();

    expect(e27_trending()->puzzleScores()[$puzzle->id]['score'])->toBe(20);
});

test('T7: inactive puzzles, frozen players, unverified players and context attempts (events, challenges, campaigns) never count', function () {
    $inactive = e27_puzzle('معطّلة', ['is_active' => false]);
    e27_play($inactive, 3);

    $mixed = e27_puzzle('أحجية مختلطة');
    e27_attempt(e27_user(), $mixed, false, now()->subHour());
    e27_attempt(e27_user(['is_frozen' => true]), $mixed, false, now()->subHour());
    e27_attempt(e27_user(['email_verified_at' => null]), $mixed, false, now()->subHour());

    $contextual = e27_puzzle('داخل سياق');
    foreach (['competitive_event', 'friend_challenge', 'campaign_step'] as $context) {
        e27_attempt(e27_user(), $contextual, false, now()->subHour(), 1, $context);
    }

    $real = e27_puzzle('أحجية حقيقية');
    e27_play($real, 2);
    foreach (range(1, 5) as $i) {
        e27_attempt(e27_user(), $real, true, now()->subHour(), 1, 'competitive_event');   // لا تضيف لاعبين ولا حلّالين
    }

    $scores = e27_trending()->puzzleScores();

    expect(array_keys($scores))->toBe([$real->id])->and($scores[$real->id]['players'])->toBe(2)->and($scores[$real->id]['score'])->toBe(14);
});

test('T8: ties are broken by the higher id - a fixed, deterministic rule', function () {
    $first = e27_puzzle('الأولى');
    $second = e27_puzzle('الثانية');
    e27_play($first, 2);
    e27_play($second, 2);

    expect(array_keys(e27_trending()->puzzleScores()))->toBe([$second->id, $first->id]);
    e27_cold();
    expect(array_keys(e27_trending()->puzzleScores()))->toBe([$second->id, $first->id]);
});

test('T9: the cache holds puzzle ids and scores only - no names, emails or viewer data', function () {
    $puzzle = e27_puzzle('أحجية للكاش');
    $players = e27_play($puzzle, 2);
    $players[0]->forceFill(['name' => 'لاعب-سري-للكاش', 'email' => 'cache-secret@example.test'])->save();

    e27_trending()->puzzles();
    $cached = Cache::get('search.trending.puzzles.v1');

    expect($cached)->toBe([$puzzle->id => ['score' => 14, 'players' => 2]]);
    expect(json_encode($cached))->not->toContain('لاعب-سري')->not->toContain('cache-secret')->not->toContain('أحجية للكاش');
});

test('T10: withdrawing a puzzle hides it at once even while the cached ranking still lists it', function () {
    $keep = e27_puzzle('باقية');
    $gone = e27_puzzle('ستُسحب');
    e27_play($keep, 2);
    e27_play($gone, 3);

    expect(e27_trending()->puzzles()->pluck('title')->all())->toBe(['ستُسحب', 'باقية']);

    $gone->forceFill(['is_active' => false])->save();

    expect(e27_trending()->puzzles()->pluck('title')->all())->toBe(['باقية'])
        ->and(array_keys(Cache::get('search.trending.puzzles.v1')))->toContain($gone->id);   // ما زال بالكاش، لكنه لا يُعرض
});

test('T11: the window start is found by binary search on the primary key and always lands on the first in-window attempt', function (int $old, int $recent) {
    $puzzle = e27_puzzle('بحث ثنائي');
    $user = e27_user();
    $ids = [];

    for ($i = 1; $i <= $old; $i++) {
        $ids['old'][] = e27_attempt($user, $puzzle, false, now()->subDays(10), $i);
    }
    for ($i = 1; $i <= $recent; $i++) {
        $ids['recent'][] = e27_attempt($user, $puzzle, false, now()->subHours(2), $old + $i);
    }

    $method = new ReflectionMethod(TrendingService::class, 'firstAttemptIdSince');
    $method->setAccessible(true);
    $found = $method->invoke(e27_trending(), now()->subDays(7));

    expect($found)->toBe($recent === 0 ? null : $ids['recent'][0]);
})->with([
    'nothing at all' => [0, 0],
    'only recent' => [0, 5],
    'only old' => [6, 0],
    'one old, one recent' => [1, 1],
    'many old, one recent' => [57, 1],
    'one old, many recent' => [1, 40],
    'balanced' => [33, 33],
]);

test('T12: trending is read-only - no write queries and no effect on XP, currency, quests, streaks, achievements, purchases or attempts', function () {
    $puzzle = e27_puzzle('قراءة فقط');
    e27_play($puzzle, 3, null, 2);
    $before = [...e16Snapshot(), PuzzleAttempt::count()];

    $sql = e27_sql(function () {
        e27_trending()->puzzles();
        e27_trending()->competitions();
        e27_trending()->teams();
    });

    expect(array_values(array_filter($sql, fn ($q) => preg_match('/^\s*(insert|update|delete|replace)\b/i', $q))))->toBe([])
        ->and([...e16Snapshot(), PuzzleAttempt::count()])->toBe($before);
});

test('T13: trending competitions are public, not yet ended and have enough participants - ordered by participants', function () {
    $a = e17Event(['title' => 'منافسة أ']);
    $b = e17Event(['title' => 'منافسة ب']);
    $draft = e17Event(['title' => 'مسودة'], CompetitiveEvent::STATUS_DRAFT);
    $ended = e17Event(['title' => 'منتهية', 'starts_at' => now()->subDays(2), 'ends_at' => now()->subDay()]);
    $few = e17Event(['title' => 'قليلة']);
    foreach ([[$a, 5], [$b, 9], [$draft, 20], [$ended, 30], [$few, 1]] as [$event, $count]) {
        DB::table('competitive_events')->where('id', $event->id)->update(['participants_count' => $count]);
    }

    expect(e27_trending()->competitions()->pluck('title')->all())->toBe(['منافسة ب', 'منافسة أ']);
});

test('T14: rising teams need enough new members inside the window and must be public and active', function () {
    $rising = e19Team(null, ['name' => 'فريق صاعد']);
    e19Member($rising);
    e19Member($rising);

    $quiet = e19Team(null, ['name' => 'فريق هادئ']);

    $private = e19Team(null, ['name' => 'فريق خاص']);
    e19Member($private);
    e19Member($private);
    $private->forceFill(['visibility' => Team::VISIBILITY_PRIVATE])->save();

    $old = e19Team(null, ['name' => 'فريق قديم']);
    e19Member($old);
    e19Member($old);
    DB::table('team_memberships')->where('team_id', $old->id)->update(['joined_at' => now()->subDays(20)]);

    $stopped = e19Team(null, ['name' => 'فريق متوقف']);
    e19Member($stopped);
    e19Member($stopped);
    $stopped->forceFill(['is_active' => false])->save();

    expect(e27_trending()->teams()->pluck('name')->all())->toBe(['فريق صاعد']);
});

test('T15: the discover page shows trending as a count of players only - never a name, id or email', function () {
    $puzzle = e27_puzzle('أحجية الرائج');
    $players = e27_play($puzzle, 2);
    foreach ($players as $i => $player) {
        $player->forceFill(['name' => 'لاعب-ظاهر-'.$i, 'email' => "leak{$i}@example.test"])->save();
    }

    $html = $this->get(route('discover.index'))->assertOk()->getContent();
    $section = uiXpath($html)->query('//section[@data-discover-section="trending"]');

    expect($section->length)->toBe(1)->and($section->item(0)->textContent)->toContain('أحجية الرائج')->toContain('2 لاعب هذا الأسبوع');
    expect($html)->not->toContain('لاعب-ظاهر')->not->toContain('leak0@')->not->toContain('leak1@');
});
`````

### `tests/Feature/DiscoveryE27/E27UxTest.php`

`````php
<?php

require_once __DIR__.'/_support.php';

/*
| E27-E - تجربة البحث: زر الرأس، التراكب (combobox)، لوحة المفاتيح، RTL، الجوال، الوصولية، بدون JavaScript. فحص بنيوي لـDOM/XPath + تدقيق ساكن للمصدر
| + تشغيل safePath فعليًا بـNode (إن وُجد) لأن منطق الروابط الآمنة هو ما يهم أمنيًا في الواجهة.
*/

beforeEach(fn () => $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class));

if (! function_exists('e27_ux_views')) {
    /** @return array<string, string> مسار ← مصدر كل عروض E27. */
    function e27_ux_views(): array
    {
        $paths = ['search/index.blade.php', 'search/_result.blade.php', 'discover/index.blade.php', 'discover/_card.blade.php', 'layouts/partials/search-overlay.blade.php'];

        return array_combine($paths, array_map(fn (string $p) => uiView($p), $paths));
    }

    /** كود JavaScript بلا تعليقات (التعليق قد يذكر الممنوع بالشرح). */
    function e27_js_code(string $relative): string
    {
        return preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', (string) file_get_contents(resource_path($relative)));
    }
}

test('U1: the header search trigger is a real link to /search for guests and players, named, and outside the primary navigation', function () {
    foreach ([$this, $this->actingAs(e27_user())] as $caller) {
        $x = uiXpath($caller->get('/')->assertOk()->getContent());
        $trigger = $x->query('//a[@data-search-trigger]');

        expect($trigger->length)->toBe(1)
            ->and($trigger->item(0)->getAttribute('href'))->toBe(route('search.index'))
            ->and($trigger->item(0)->getAttribute('aria-label'))->toBe('بحث')
            ->and($trigger->item(0)->getAttribute('aria-haspopup'))->toBe('dialog')
            ->and($x->query('//header//a[@data-search-trigger]')->length)->toBe(1)
            ->and($x->query('//nav[@aria-label="التنقل الرئيسي"]//a[@data-search-trigger]')->length)->toBe(0);
    }
});

test('U2: the primary navigation keeps its icons - six for verified players, four for unverified ones - and guests keep their text links; search joined none of them', function () {
    expect(uiPrimaryLabels($this->actingAs(e27_user())->get('/')->assertOk()->getContent()))->toBe(['الرئيسية', 'الأحجيات', 'المنافسات', 'الفرق', 'الأصدقاء', 'الرسائل'])
        ->and(uiPrimaryLabels($this->actingAs(e27_user(['email_verified_at' => null]))->get('/')->assertOk()->getContent()))->toBe(['الرئيسية', 'الأحجيات', 'المنافسات', 'الفرق']);

    $guestLinks = uiAttrs(uiXpath($this->get('/')->assertOk()->getContent()), '//nav[@aria-label="التنقل الرئيسي"]//a', 'href');
    expect($guestLinks)->toContain(route('puzzles.index'))->toContain(route('competitions.index'))->toContain(route('teams.index'))->not->toContain(route('search.index'));
});

test('U3: the overlay is a labelled dialog holding a combobox wired to a listbox, with a live status region and no form', function () {
    foreach ([$this, $this->actingAs(e27_user())] as $caller) {
        $html = $caller->get('/')->assertOk()->getContent();
        $x = uiXpath($html);
        $overlay = $x->query('//div[@x-data="globalSearch"]')->item(0);

        expect($overlay)->not->toBeNull()
            ->and($x->query('//div[@x-data="globalSearch"]')->length)->toBe(1)
            ->and($x->query('.//*[@role="dialog" and @aria-modal="true" and @aria-label]', $overlay)->length)->toBe(1)
            ->and($x->query('.//form', $overlay)->length)->toBe(0)
            ->and($x->query('.//*[@role="search"]', $overlay)->length)->toBe(1)
            ->and($x->query('.//*[@role="status" and @aria-live="polite"]', $overlay)->length)->toBe(1);

        $combobox = $x->query('.//input[@role="combobox"]', $overlay);
        expect($combobox->length)->toBe(1)
            ->and($combobox->item(0)->getAttribute('aria-autocomplete'))->toBe('list')
            ->and($combobox->item(0)->getAttribute('aria-label'))->not->toBe('')
            ->and($combobox->item(0)->getAttribute('maxlength'))->toBe('64')
            ->and($combobox->item(0)->getAttribute('autocomplete'))->toBe('off');
        $listbox = $x->query('.//*[@role="listbox"]', $overlay);
        expect($listbox->length)->toBe(1)->and($listbox->item(0)->getAttribute('id'))->toBe($combobox->item(0)->getAttribute('aria-controls'));
        expect(uiUnnamedControls($x, '//div[@x-data="globalSearch"]'))->toBe([]);
    }
});

test('U4: keyboard support is wired - arrows, Enter, Escape, a focus trap, and the "/" and Ctrl/Cmd+K shortcuts', function () {
    $overlay = uiView('layouts/partials/search-overlay.blade.php');
    $js = file_get_contents(resource_path('js/search.js'));

    expect($overlay)->toContain('@keydown.arrow-down.prevent="move(1)"')->toContain('@keydown.arrow-up.prevent="move(-1)"')->toContain('@keydown.enter="enter($event)"')
        ->toContain('@keydown.escape.window="close()"')->toContain('@keydown.tab="trap($event)"')->toContain('@keydown.window="shortcut($event)"')
        ->toContain(':aria-activedescendant="activeId"')->toContain(':aria-selected=');
    expect($js)->toContain("event.key === '/'")->toContain("event.key.toLowerCase() === 'k'")->toContain('isEditable(event.target)')
        ->toContain('opener.focus')->toContain('shiftKey');   // Esc يعيد التركيز لمن فتح التراكب؛ Shift+Tab يدور داخل الحوار
});

test('U5: the overlay script never builds HTML or touches storage - text goes through x-text, links through safePath', function () {
    $js = e27_js_code('js/search.js');
    $views = e27_ux_views();

    expect(preg_match('/innerHTML|outerHTML|insertAdjacentHTML|document\.write|\beval\s*\(|new\s+Function|localStorage|sessionStorage|indexedDB|console\.|\.cookie|postMessage|importScripts/', $js))->toBe(0);
    foreach ($views as $path => $source) {
        expect(preg_match('/x-html|{!!|\bv-html\b|javascript:|<script|on(click|error|load)\s*=/i', $source))->toBe(0, $path);
    }
    expect($views['layouts/partials/search-overlay.blade.php'])->toContain('x-text="item.title"')->toContain(':href="item.url"');
});

test('U6: requests are debounced, cancelled when superseded, stale answers are ignored and a 429 pauses typing instead of hammering', function () {
    $js = file_get_contents(resource_path('js/search.js'));

    expect($js)->toContain('const DEBOUNCE_MS = 220;')->toContain('new AbortController()')->toContain('this.controller.abort()')->toContain('if (term !== this.q.trim()) return;')
        ->toContain('response.status === 429')->toContain('this.blockedUntil = Date.now() + BACKOFF_MS;')->toContain("error.name === 'AbortError'")
        ->toContain('this.composing')   // لا طلبات أثناء تركيب الكتابة (IME)
        ->toContain("credentials: 'same-origin'");
});

test('U7: safePath accepts only same-site relative paths - evaluated for real with Node', function () {
    $node = trim((string) shell_exec('command -v node 2>/dev/null'));
    if ($node === '') {
        $this->markTestSkipped('node is not available in this environment');
    }

    $copy = sys_get_temp_dir().'/e27-search-'.bin2hex(random_bytes(4)).'.mjs';
    copy(resource_path('js/search.js'), $copy);

    $cases = ['/puzzles/12', '/search?q=%D8%A3&type=teams', '/players/01J8ZZZZZZZZZZZZZZZZZZZZZZ', '/', '/%2F%2Fevil',
        '//evil.test', '/\\evil', "/\t/evil.com", "/\n/evil.com", "/\r/evil.com", '/ x', "/\u{00A0}x", "/\u{0000}x", 'https://evil.test/x', 'http://evil.test', 'javascript:alert(1)', 'data:text/html,x', 'evil.test', '', ' /x'];
    $script = 'import {safePath} from '.json_encode('file://'.$copy).'; const c = JSON.parse(process.argv[1]); console.log(JSON.stringify(c.map((u) => safePath(u))));'
        .' console.log(JSON.stringify([safePath(null), safePath(undefined), safePath(42), safePath({}), safePath([])]));';
    $out = shell_exec(escapeshellarg($node).' --input-type=module -e '.escapeshellarg($script).' '.escapeshellarg(json_encode($cases)).' 2>&1');
    @unlink($copy);

    $lines = array_values(array_filter(explode("\n", trim((string) $out))));
    expect($lines)->toHaveCount(2, (string) $out);
    $accepted = array_values(array_filter(json_decode($lines[0], true), fn ($v) => $v !== null));

    expect($accepted)->toBe(['/puzzles/12', '/search?q=%D8%A3&type=teams', '/players/01J8ZZZZZZZZZZZZZZZZZZZZZZ', '/', '/%2F%2Fevil'])
        ->and(json_decode($lines[1], true))->toBe([null, null, null, null, null]);
});

test('U8: the server only ever emits single-slash relative paths in suggestions', function () {
    $puzzle = e27_puzzle('أحجية الروابط');
    $team = e19Team(null, ['name' => 'فريق الروابط']);
    $json = e27_suggest($this, 'الروابط');
    $urls = collect($json['groups'])->flatMap(fn ($g) => collect($g['items'])->pluck('url')->push($g['more_url']))->filter()->push($json['all_url'])->all();

    expect($urls)->not->toBe([]);
    foreach ($urls as $url) {
        expect($url)->toMatch('#^/(?![/\\\\])[^\s\\\\]*$#');
    }
    expect(\App\Services\Search\SearchResult::relativePath('http://evil.test//double//slash?q=1'))->toBe('/double//slash?q=1')
        ->and(\App\Services\Search\SearchResult::relativePath('http://h.test'))->toBe('/');
});

test('U9: search and discover pages are accessible for guests and players in every state', function (string $url) {
    e27_puzzle('نتيجة موجودة');
    e19Team(null, ['name' => 'فريق موجود']);

    foreach ([$this, $this->actingAs(e27_user())] as $caller) {
        expect(e27_a11y_problems($caller->get($url)->assertOk()->getContent()))->toBe([], $url);
    }
})->with(['/search', '/search?q=موجود', '/search?q=م', '/search?q=لا-شيء-مطابق', '/search?q=موجود&type=puzzles', '/search?q=موجود&type=teams', '/search?type=players', '/discover']);

test('U10: the result type tabs are a labelled nav and mark exactly the current one', function () {
    e27_puzzle('أحجية التبويب');

    $all = uiXpath($this->get(route('search.index', ['q' => 'التبويب']))->assertOk()->getContent());
    $teams = uiXpath($this->get(route('search.index', ['q' => 'التبويب', 'type' => 'teams']))->assertOk()->getContent());

    expect($all->query('//nav[@aria-label="نوع النتائج"]//a[@aria-current="page"]')->length)->toBe(1)
        ->and(trim($all->query('//nav[@aria-label="نوع النتائج"]//a[@aria-current="page"]')->item(0)->textContent))->toBe('الكل')
        ->and($teams->query('//nav[@aria-label="نوع النتائج"]//a[@aria-current="page"]')->length)->toBe(1)
        ->and(trim($teams->query('//nav[@aria-label="نوع النتائج"]//a[@aria-current="page"]')->item(0)->textContent))->toBe('الفرق');
});

test('U11: the search page is a plain GET form that works without JavaScript - the term, the type and the page all travel in the URL', function () {
    $x = uiXpath($this->get(route('search.index', ['q' => 'نص', 'type' => 'puzzles']))->assertOk()->getContent());
    $form = $x->query('//main//form[@role="search"]');

    expect($form->length)->toBe(1)->and(strtolower($form->item(0)->getAttribute('method')))->toBe('get')->and($form->item(0)->getAttribute('action'))->toBe(route('search.index'))
        ->and($x->query('.//input[@name="q" and @type="search"]', $form->item(0))->length)->toBe(1)
        ->and($x->query('.//input[@name="type" and @value="puzzles"]', $form->item(0))->length)->toBe(1)
        ->and($x->query('.//button[@type="submit"]', $form->item(0))->length)->toBe(1);
});

test('U12: layout is right-to-left safe - the E27 views use logical utilities only and no physical left/right ones', function () {
    foreach (e27_ux_views() as $path => $source) {
        $classes = implode(' ', array_map(fn ($m) => $m, preg_match_all('/class="([^"]*)"/', $source, $all) ? $all[1] : []));

        expect(preg_match('/(?<![\w-])(?:-?m[lr]|p[lr]|border-[lr]|rounded-[lr]|rounded-[tb][lr]|text-(?:left|right)|float-(?:left|right)|left-\d|right-\d|inset-[lr])(?:-|\b)/', $classes))->toBe(0, $path);
    }

    $html = $this->get(route('search.index'))->assertOk()->getContent();
    expect(uiXpath($html)->query('/html[@lang="ar" and @dir="rtl"]')->length)->toBe(1);
});

test('U13: mobile - touch targets are at least 40px, the overlay is full screen on small screens, and the viewport is set', function () {
    $overlay = uiView('layouts/partials/search-overlay.blade.php');
    $header = uiView('layouts/partials/header.blade.php');
    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->toContain('name="viewport"')
        ->and($overlay)->toContain('fixed inset-0')->toContain('h-full w-full')->toContain('sm:h-auto')->toContain('sm:max-h-[75vh]')
        ->and($overlay)->toMatch('/aria-label="إغلاق البحث"[^>]*\n?[^>]*w-10 h-10/s')
        ->and($header)->toMatch('/data-search-trigger\s+class="[^"]*w-10 h-10/s')
        ->and($overlay)->toContain('enterkeyhint="search"')->toContain('type="search"');
});

test('U14: the overlay sits above the bottom navigation and below the global z-index ceiling', function () {
    foreach (e27_ux_views() as $path => $source) {
        preg_match_all('/z-\[(\d+)\]|\bz-(\d+)\b/', $source, $m);
        foreach (array_merge(array_filter($m[1]), array_filter($m[2])) as $z) {
            expect((int) $z)->toBeLessThan(100, $path);
        }
    }
    expect(uiView('layouts/partials/search-overlay.blade.php'))->toContain('z-[60]');
});

test('U15: motion respects the reduced-motion preference - every transition class carries its motion-reduce override', function () {
    foreach (e27_ux_views() as $path => $source) {
        foreach (preg_split('/\R/', $source) as $line) {
            if (preg_match('/class="[^"]*\btransition\b/', $line)) {
                expect($line)->toContain('motion-reduce:transition-none');
            }
        }
    }
});

test('U16: the discover entry is in the explore menu for players, and the overlay offers it to everyone when idle', function () {
    $player = $this->actingAs(e27_user())->get('/')->assertOk()->getContent();
    $guest = $this->get('/')->assertOk()->getContent();

    expect(uiXpath($player)->query('//a[@href="'.route('discover.index').'" and contains(normalize-space(.), "اكتشف")]')->length)->toBeGreaterThanOrEqual(1);
    foreach ([$guest, $player] as $html) {
        expect(uiXpath($html)->query('//div[@x-data="globalSearch"]//a[@href="/discover"]')->length)->toBe(1);
    }
});

test('U17: the overlay that now ships on every page introduces none of the words existing leak audits search for - hint, answer_raw, normalized_answer, answer_hash', function () {
    // انحدار حقيقي: خاصية Alpine باسم «hint» جعلت اختبار تسرّب الإجابات (E20) يفشل لأنه يفتّش كل HTML عن الكلمة.
    foreach ([$this, $this->actingAs(e27_user())] as $caller) {
        foreach (['/', '/puzzles', '/search', '/discover'] as $url) {
            expect($caller->get($url)->assertOk()->getContent())->not->toMatch('/answer_raw|normalized_answer|answer_hash|\bhint\b/i', $url);
        }
    }

    expect(file_get_contents(resource_path('js/search.js')))->not->toMatch('/\bhint\b/');
});
`````

### `tests/Feature/DiscoveryE27/_support.php`

`````php
<?php

use App\Models\Campaign;
use App\Models\Puzzle;
use App\Models\PuzzleCategory;
use App\Models\Season;
use App\Models\User;
use App\Services\Discovery\RecommendationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
| E27 - مساعدات مشتركة لاختبارات البحث والاكتشاف. ليست ملف اختبار (لا تنتهي بـTest.php) فتُحمَّل بـrequire_once.
| كل شيء محلي على قاعدة SQLite وكاش array؛ لا شبكة ولا محرك خارجي.
*/

require_once __DIR__.'/../SecurityE26/_support.php';
require_once __DIR__.'/../UiE22/UiTestHelpers.php';

if (! function_exists('e27_user')) {
    function e27_user(array $attrs = []): User
    {
        return e16User($attrs);
    }

    function e27_puzzle(string $title, array $attrs = []): Puzzle
    {
        return Puzzle::factory()->create($attrs + ['title' => $title]);
    }

    /** يكتب محاولة مباشرة بوقت معلوم (يتجاوز خدمة المحاولات عمدًا: لا مكافآت ولا أحداث). */
    function e27_attempt(User|int $user, Puzzle|int $puzzle, bool $correct = false, $at = null, int $number = 1, ?string $context = null): int
    {
        $at = $at ?? now();

        return (int) DB::table('puzzle_attempts')->insertGetId([
            'user_id' => $user instanceof User ? $user->id : $user,
            'puzzle_id' => $puzzle instanceof Puzzle ? $puzzle->id : $puzzle,
            'attempt_number' => $number,
            'is_correct' => $correct,
            'used_hint' => false,
            'context_type' => $context,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    function e27_campaign(string $title, array $attrs = []): Campaign
    {
        return Campaign::factory()->create($attrs + ['title' => $title, 'is_active' => true]);
    }

    function e27_season(string $title, array $attrs = [], array $campaign = []): Season
    {
        $c = e27_campaign($title, $campaign);

        return Season::factory()->create($attrs + ['campaign_id' => $c->id, 'is_published' => true]);
    }

    /** يبدأ المستخدم خطوة داخل حملة (صف تقدّم بلا إكمال) عبر المصانع: مرحلة ← بوابة ← خطوة. @return int معرّف الخطوة */
    function e27_progress(User $user, Campaign $campaign, bool $complete = false): int
    {
        $stage = \App\Models\CampaignStage::factory()->create(['campaign_id' => $campaign->id]);
        $gate = \App\Models\CampaignGate::factory()->create(['campaign_stage_id' => $stage->id]);
        $step = \App\Models\CampaignStep::factory()->create(['campaign_gate_id' => $gate->id]);
        \App\Models\UserCampaignProgress::factory()->create(['user_id' => $user->id, 'campaign_step_id' => $step->id]);

        if ($complete) {
            DB::table('user_campaign_completions')->insert(['user_id' => $user->id, 'campaign_id' => $campaign->id, 'completed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }

        return $step->id;
    }

    /** @return array<string, mixed> */
    function e27_suggest($test, string $q, ?User $as = null): array
    {
        $caller = $as ? $test->actingAs($as) : $test;

        return $caller->getJson(route('search.suggest', ['q' => $q]))->assertOk()->json();
    }

    /** @return list<string> كل عناوين الاقتراحات بترتيب ظهورها. */
    function e27_titles(array $json): array
    {
        return collect($json['groups'])->flatMap(fn ($g) => collect($g['items'])->pluck('title'))->values()->all();
    }

    /** @return list<string> عناوين مجموعة نوع واحد. */
    function e27_group(array $json, string $type): array
    {
        return collect($json['groups'])->firstWhere('type', $type)['items'] ?? [] ? collect(collect($json['groups'])->firstWhere('type', $type)['items'])->pluck('title')->all() : [];
    }

    /** @return list<string> */
    function e27_types(array $json): array
    {
        return collect($json['groups'])->pluck('type')->all();
    }

    /**
     * عدد استعلامات التطبيق أثناء $run، **بدون** تتبّع الأجهزة (device_sightings): وسيط قائم (E8/E24) يكتب بصمة الجهاز مرة بالدقيقة لكل طلب، وليس من البحث.
     * يعيد القائمة كاملة لمن يريد فحص النصوص (writes/selects).
     *
     * @return list<string>
     */
    function e27_sql(callable $run): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $run();
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        return array_values(array_map(fn ($q) => $q['query'], array_filter($log, fn ($q) => ! str_contains($q['query'], 'device_sightings'))));
    }

    function e27_queries(callable $run): int
    {
        return count(e27_sql($run));
    }

    function e27_trending(): \App\Services\Discovery\TrendingService
    {
        return app(\App\Services\Discovery\TrendingService::class);
    }

    /** @return list<User> $players لاعبًا مميَّزًا (حسابات جديدة) يحاول كلٌّ منهم الأحجية مرة؛ أول $solvers منهم يحلّونها. */
    function e27_play(Puzzle $puzzle, int $players, $at = null, int $solvers = 0): array
    {
        $users = [];

        for ($i = 0; $i < $players; $i++) {
            $user = e27_user();
            e27_attempt($user, $puzzle, $i < $solvers, $at ?? now()->subHour());
            $users[] = $user;
        }

        return $users;
    }

    /**
     * مشكلات وصولية صفحة (فارغة = سليمة). نسخة E27 المستقلة من فحص E22 (لا نعتمد على ملف اختبار آخر): لغة/اتجاه، main، رابط التخطي أول عنصر، h1 واحد، عناصر بلا اسم، صور بلا alt، حقول بلا تسمية.
     *
     * @return list<string>
     */
    function e27_a11y_problems(string $html): array
    {
        $x = uiXpath($html);
        $problems = [];

        if ($x->query('/html[@lang="ar" and @dir="rtl"]')->length !== 1) {
            $problems[] = 'html lang/dir';
        }
        if ($x->query('//main[@id="main-content"]')->length !== 1) {
            $problems[] = 'main#main-content';
        }
        $skip = $x->query('(//a | //button | //input | //select | //textarea)[1]')->item(0);
        if (! $skip || $skip->getAttribute('href') !== '#main-content') {
            $problems[] = 'skip link is not the first focusable element';
        }
        if ($x->query('//h1')->length !== 1) {
            $problems[] = 'h1 count = '.$x->query('//h1')->length;
        }
        foreach (uiUnnamedControls($x, '/html/body') as $bad) {
            $problems[] = 'unnamed control: '.$bad;
        }
        foreach ($x->query('//img[not(@alt)]') as $img) {
            $problems[] = 'img without alt: '.$img->getAttribute('src');
        }
        foreach ($x->query('//input[not(@type="hidden" or @type="submit" or @type="button" or @type="checkbox" or @type="radio")] | //select | //textarea') as $field) {
            $id = $field->getAttribute('id');
            $named = $field->getAttribute('aria-label') !== '' || $field->getAttribute('aria-labelledby') !== '' || $field->getAttribute('title') !== ''
                || ($id !== '' && $x->query('//label[@for="'.$id.'"]')->length > 0)
                || $x->query('ancestor::label', $field)->length > 0;

            if (! $named) {
                $problems[] = 'field without a label: '.($field->getAttribute('name') ?: $field->nodeName);
            }
        }

        return $problems;
    }

    /** @return array<string, mixed> */
    function e27_reco(User $user): array
    {
        return app(RecommendationService::class)->forUser($user);
    }

    /** @return list<string> */
    function e27_reco_titles(User $user): array
    {
        return array_map(fn (array $r) => $r['puzzle']->title, e27_reco($user)['puzzles']);
    }

    function e27_category(string $name): PuzzleCategory
    {
        return PuzzleCategory::factory()->create(['name' => $name, 'slug' => \Illuminate\Support\Str::slug($name).'-'.\Illuminate\Support\Str::random(4)]);
    }

    /** @return list<Puzzle> $n أحجية فعّالة في تصنيف بعنوان "<prefix>-<i>". */
    function e27_pool(PuzzleCategory $category, int $n, string $prefix, string $difficulty = 'easy', array $attrs = []): array
    {
        $out = [];

        for ($i = 1; $i <= $n; $i++) {
            $out[] = Puzzle::factory()->create($attrs + ['puzzle_category_id' => $category->id, 'title' => $prefix.'-'.$i, 'difficulty' => $difficulty]);
        }

        return $out;
    }

    /** يحلّ المستخدم الأحجية (محاولة صحيحة واحدة، مستقلة). */
    function e27_solve(User $user, Puzzle $puzzle, $at = null): void
    {
        e27_attempt($user, $puzzle, true, $at ?? now()->subDay());
    }

    /** يمسح كاش اللقطات (الرائج، مجسّ حجم الجدول) بين مرحلتين من اختبار واحد. */
    function e27_cold(): void
    {
        Cache::flush();
    }
}
`````

## B) MODIFIED FILES — تصحيحات (diff موحّد) دقيقة (8 ملفات)

النسخ الكاملة لهذه الملفات في الأرشيف المرفق تحت `modified-full-files/` (استبدال مباشر إن لم تعدّل هذه الملفات محليًا بعد الأساس).

### `.env.example`

`````diff
--- a/.env.example
+++ b/.env.example
@@ -163,3 +163,12 @@
 # REVERB_APP_ACCEPT_CLIENT_EVENTS_FROM=none  # أحداث العميل مغلقة (التطبيق لا يستعملها)
 # SECURITY_TRUST_HOSTS=true                  # يقيّد Host بمضيف APP_URL بالإنتاج (يمنع تسميم روابط إعادة التعيين). تأكد أن APP_URL هو النطاق الفعلي
 # SECURITY_TRUSTED_HOSTS=www.example.com     # مضيفات إضافية مسموحة (مفصولة بفواصل)
+
+# E27 - البحث العام والاكتشاف والرائج والتوصيات (كلها اختيارية؛ الافتراضيات آمنة ولا تتطلب شيئًا). انظر docs/search-discovery-e27.md.
+# SEARCH_DRIVER=database                     # السائق الوحيد حاليًا. قيمة غير معروفة تسقط إلى database (لا تعطّل الموقع). محرك خارجي = تنفيذ جديد لـSearchBackend
+# SEARCH_MIN_LENGTH=2                        # أقل طول للمصطلح (حروف بعد التطبيع)
+# SEARCH_MIN_LENGTH_GUEST_PLAYERS=3          # الضيف يحتاج 3 أحرف للبحث عن لاعبين (يحدّ التخمين)
+# SEARCH_PARTIAL_MAX_ID=10000                # أكبر معرّف بجدول يُسمح عنده بالمطابقة الجزئية (وسط الكلمة/الحروف العربية المتقاربة). فوقه: بادئة العمود فقط. هذا مؤشر الانتقال لمحرك بحث/FULLTEXT
+# SEARCH_TRENDING_WINDOW_DAYS=7              # نافذة «الرائج»
+# SEARCH_TRENDING_TTL=300                    # عمر لقطة الرائج بالثواني (معرّفات ودرجات فقط)
+# SEARCH_TRENDING_MIN_PLAYERS=2              # أقل عدد لاعبين مميَّزين كي تظهر أحجية بالرائج
`````

### `app/Providers/AppServiceProvider.php`

`````diff
--- a/app/Providers/AppServiceProvider.php
+++ b/app/Providers/AppServiceProvider.php
@@ -19,7 +19,10 @@
 {
     public function register(): void
     {
-        //
+        // E27: محرك البحث. السائق غير المعروف يسقط إلى database (لا يعطّل الموقع). محرك خارجي مستقبلي = تنفيذ جديد لـSearchBackend وفرع هنا.
+        $this->app->singleton(\App\Services\Search\Contracts\SearchBackend::class, fn ($app) => match ((string) config('search.driver', 'database')) {
+            default => $app->make(\App\Services\Search\DatabaseSearchBackend::class),
+        });
     }
 
     public function boot(): void
@@ -205,7 +208,17 @@
         RateLimiter::for('player-search', function (Request $request) {
             return Limit::perMinute(30)->by($request->user()?->id ?: $request->ip());
         });
-                // E11: تجهيز/إزالة تجميليات - UX تتطلب تجربة سريعة (تبديل/معاينة)، لا حساسية مالية هنا.
+                // E27: البحث العام. الاقتراحات الفورية أوسع (تُطلَق أثناء الكتابة بعد تأخير 220ms) لكن الضيف أضيق بكثير من العضو (مفتاح IP) كي لا يُستعمل مسحًا لأسماء اللاعبين.
+        // صفحة البحث الكاملة أضيق من الاقتراحات. الاكتشاف (القراءة فقط) بحدّ واسع. المفتاح المستخدم المصادَق لا IP (يمنع التفاف مفتاح IP المشترك).
+        RateLimiter::for('global-search', fn (Request $request) => $request->user()
+            ? Limit::perMinute(60)->by('gs:u:'.$request->user()->id)
+            : Limit::perMinute(20)->by('gs:ip:'.$request->ip()));
+        RateLimiter::for('search-suggest', fn (Request $request) => $request->user()
+            ? [Limit::perMinute(120)->by('ss:u:'.$request->user()->id), Limit::perSecond(16, 2)->by('ssb:u:'.$request->user()->id)]
+            : [Limit::perMinute(40)->by('ss:ip:'.$request->ip()), Limit::perSecond(10, 2)->by('ssb:ip:'.$request->ip())]);
+        RateLimiter::for('discover', fn (Request $request) => Limit::perMinute(90)->by('dc:'.($request->user()?->id ?: $request->ip())));
+
+        // E11: تجهيز/إزالة تجميليات - UX تتطلب تجربة سريعة (تبديل/معاينة)، لا حساسية مالية هنا.
         RateLimiter::for('cosmetic-equip', function (Request $request) {
             return Limit::perMinute(30)->by($request->user()?->id ?: $request->ip());
         });
`````

### `app/Support/NavigationMenu.php`

`````diff
--- a/app/Support/NavigationMenu.php
+++ b/app/Support/NavigationMenu.php
@@ -48,6 +48,7 @@
         ]));
 
         $explore = array_values(array_filter([
+            $item('discover', 'discover.index', 'sparkles', 'اكتشف', ['discover.*', 'search.*']),
             $show('show_seasons_link') ? $item('seasons', 'seasons.index', 'calendar', 'المواسم', ['seasons.*', 'campaigns.*']) : null,
             $show('show_challenges_link') ? $item('challenges', 'challenges.index', 'bolt', 'التحديات', ['challenges.*', 'friends.challenges.*']) : null,
             $show('show_leaderboard_link') ? $item('leaderboard', 'leaderboard.index', 'chart', 'لوحة الصدارة', ['leaderboard.*']) : null,
`````

### `resources/js/app.js`

`````diff
--- a/resources/js/app.js
+++ b/resources/js/app.js
@@ -1,6 +1,7 @@
 import Alpine from 'alpinejs';
 import './echo.js';
 import chatRoom from './chat.js';
+import globalSearch from './search.js';
 import sequenceGame from './games/renderers/sequence.js';
 import memoryGame from './games/renderers/memory.js';
 import spotDifferenceGame from './games/renderers/spot-difference.js';
@@ -11,6 +12,7 @@
 Alpine.data('memoryGame', memoryGame);
 Alpine.data('spotDifferenceGame', spotDifferenceGame);
 Alpine.data('chatRoom', chatRoom);
+Alpine.data('globalSearch', globalSearch);
 
 /**
  * مبدّل الثيم (E4) - data-theme على <html> هو مصدر الحقيقة الوحيد للعرض؛
`````

### `resources/views/components/ui-icon.blade.php`

`````diff
--- a/resources/views/components/ui-icon.blade.php
+++ b/resources/views/components/ui-icon.blade.php
@@ -35,6 +35,7 @@
         'chevron-left' => ['M15.75 19.5L8.25 12l7.5-7.5'],
         'arrow-right' => ['M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3'],
         'x' => ['M6 18L18 6M6 6l12 12'],
+        'search' => ['M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z'],
         'menu' => ['M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5'],
         'sun' => ['M12 3v2.25m6.364.386l-1.591 1.591M21 12h-2.25m-.386 6.364l-1.591-1.591M12 18.75V21m-4.773-4.227l-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0z'],
         'moon' => ['M21.752 15.002A9.718 9.718 0 0118 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 003 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 009.002-5.998z'],
`````

### `resources/views/layouts/app.blade.php`

`````diff
--- a/resources/views/layouts/app.blade.php
+++ b/resources/views/layouts/app.blade.php
@@ -117,6 +117,9 @@
 
     @include('layouts.partials.header')
 
+    {{-- E27: تراكب البحث العام (JavaScript فقط؛ يفتحه زر الرأس أو «/»). --}}
+    @include('layouts.partials.search-overlay')
+
     <main id="main-content" tabindex="-1" class="flex-1 focus:outline-none">
         {{-- E22: غلاف المحتوى مقيَّد القراءة ومستقل عن غلاف الرأس السائل. الصفحة تعدّل عرضه بـ@section('content_width', 'max-w-7xl') مثلًا. --}}
         <div class="mx-auto w-full {{ trim($__env->yieldContent('content_width')) ?: 'max-w-6xl' }} px-4 sm:px-6 py-6 sm:py-10">
`````

### `resources/views/layouts/partials/header.blade.php`

`````diff
--- a/resources/views/layouts/partials/header.blade.php
+++ b/resources/views/layouts/partials/header.blade.php
@@ -60,6 +60,12 @@
         @endauth
 
         <div class="flex items-center gap-1.5 sm:gap-2 shrink-0">
+            {{-- E27: البحث العام. رابط إلى /search بدون JavaScript؛ مع JavaScript يفتح تراكب الاقتراحات (انظر search-overlay). خارج التنقل الرئيسي فلا يغيّر أيقوناته الست. --}}
+            <a href="{{ route('search.index') }}" @click.prevent="$dispatch('open-search')" aria-label="بحث" title="بحث ( / )" aria-haspopup="dialog" data-search-trigger
+                class="grid place-items-center w-10 h-10 rounded-xl text-slate-400 hover:text-white hover:bg-white/5 transition motion-reduce:transition-none focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">
+                <x-ui-icon name="search" class="w-[1.35rem] h-[1.35rem]" />
+            </a>
+
             @if ($appearance['allow_theme_switch'])
                 <x-theme-switcher />
             @endif
`````

### `routes/web.php`

`````diff
--- a/routes/web.php
+++ b/routes/web.php
@@ -26,6 +26,11 @@
 
 Route::get('/', [HomeController::class, 'index'])->name('home');
 
+// E27: البحث العام والاكتشاف (قراءة فقط، عامة للضيوف). /search/suggest نقطة JSON للاقتراحات الفورية بمحدّد معدّل أضيق للضيف.
+Route::get('/search', [\App\Http\Controllers\SearchController::class, 'index'])->middleware('throttle:global-search')->name('search.index');
+Route::get('/search/suggest', [\App\Http\Controllers\SearchController::class, 'suggest'])->middleware('throttle:search-suggest')->name('search.suggest');
+Route::get('/discover', [\App\Http\Controllers\DiscoverController::class, 'index'])->middleware('throttle:discover')->name('discover.index');
+
 Route::get('/puzzles', [PuzzleController::class, 'index'])->name('puzzles.index');
 Route::get('/puzzles/category/{category:slug}', [PuzzleController::class, 'index'])->name('puzzles.category');
 Route::get('/puzzles/{puzzle}', [PuzzleController::class, 'show'])->name('puzzles.show');
`````
