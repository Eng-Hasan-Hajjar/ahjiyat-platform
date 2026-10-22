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
use App\Services\Collections\CollectionDiscovery;
use App\Services\CreatorSeries\CreatorSeriesDiscovery;
use App\Services\Creators\CommunityDiscovery;
use App\Services\Editorial\EditorialShelves;
use Illuminate\Support\Collection;

/**
 * E27-B: مركز الاكتشاف. يجمع أقسامًا **محدودة العدد** (لا ترقيم ولا قوائم مفتوحة) من الأنظمة القائمة بنفس شروط ظهورها العامة، ويضيف للمسجَّل «تابع» و«مقترح لك».
 * عدد الاستعلامات لا يتبع حجم البيانات (كل قسم استعلام محدود بـLIMIT). كل الأقسام عرض فقط: لا مكافآت ولا نقاط ولا كتابة.
 *
 * @phpstan-type Section array{key: string, title: string, icon: string, href: ?string, linkLabel: ?string, cards: list<DiscoverCard>}
 */
class DiscoveryService
{
    public function __construct(
        protected TrendingService $trending,
        protected RecommendationService $recommendations,
        protected CommunityDiscovery $community,
        protected CreatorSeriesDiscovery $series,
        protected CollectionDiscovery $collections,
        protected EditorialShelves $shelves,
    ) {}

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

        // E35: أرفف تحريرية يختارها فريق المنصة (موضع discover). تُحَلّ عناصرها حيًّا (المخفي/المحظور يسقط فورًا)، والرف الفارغ لا يظهر. تسبق الرائج.
        foreach ($this->shelves->forPlacement('discover', $viewer) as $shelf) {
            $this->add($sections, 'shelf-'.$shelf['key'], $shelf['title'], 'sparkles', null, null, $shelf['cards'], $shelf['subtitle']);
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

        // E28: أحجيات المجتمع بأقسام مستقلة موسومة «من المجتمع» (لا تمتزج بالرسمي ولا تؤثر برائجه/توصياته). قسم فارغ لا يظهر.
        $community = $this->community->sections($viewer);
        $this->add($sections, 'community-trending', 'رائج من المجتمع', 'friends', route('community.puzzles.index'), 'كل أحجيات المجتمع', $community['trending']);
        $this->add($sections, 'community-following', 'جديد من تتابعهم', 'friends', route('following.index'), 'كل ما ينشره من أتابعهم', $community['following']);
        $this->add($sections, 'community-top-rated', 'الأعلى تقييمًا من المجتمع', 'trophy', route('community.puzzles.index'), 'كل أحجيات المجتمع', $community['top_rated']);
        $this->add($sections, 'community-new', 'جديد من المجتمع', 'friends', route('community.puzzles.index'), 'كل أحجيات المجتمع', $community['new']);
        $this->add($sections, 'community-for-you', 'مقترحة لك من المجتمع', 'sparkles', route('community.puzzles.index'), 'كل أحجيات المجتمع', $community['for_you']);

        // E30: سلاسل المجتمع (وسم «سلسلة من المجتمع»): قسمان مستقلان؛ CreatorSeriesDiscovery تعزل فشلها (فلا يكسر مركز الاكتشاف)، وقسم فارغ لا يظهر.
        $seriesSections = $this->series->sections($viewer);

        $this->add($sections, 'community-series-following', 'سلاسل جديدة ممّن تتابعهم', 'grid', route('following.index'), 'كل ما ينشره من أتابعهم', $seriesSections['following']);
        $this->add($sections, 'community-series', 'سلاسل من المجتمع', 'grid', null, null, $seriesSections['new']);

        // E35: «مجموعات من المجتمع» (عامة ∧ غير مخفية ∧ أمين مؤهل ∧ ≥3 أعمال ظاهرة)، وسم «مجموعة»، سقف لكل أمين؛ معزولة الفشل. قسم فارغ لا يظهر.
        $this->add($sections, 'community-collections', 'مجموعات من المجتمع', 'inventory', null, null, $this->collections->safeCards($viewer));

        // E37: «مبدعون مختارون» (تحريري) و«مبدعون صاعدون» (جودة حديثة) بعدد قليل ورابط لصفحة الترتيبات؛ معزولة الفشل، تحترم الحظر. قسم فارغ لا يظهر.
        $reputation = app(\App\Services\Reputation\ReputationDiscovery::class)->safeSections($viewer);
        $this->add($sections, 'community-featured-creators', 'مبدعون مختارون', 'trophy', route('community.rankings'), 'ترتيبات المجتمع', $reputation['featured']);
        $this->add($sections, 'community-rising-creators', 'مبدعون صاعدون', 'sparkles', route('community.rankings'), 'ترتيبات المجتمع', $reputation['rising']);

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
    protected function add(array &$sections, string $key, string $title, string $icon, ?string $href, ?string $linkLabel, array $cards, ?string $subtitle = null): void
    {
        if ($cards === []) {
            return;
        }

        $sections[] = ['key' => $key, 'title' => $title, 'icon' => $icon, 'href' => $href, 'linkLabel' => $linkLabel, 'cards' => array_values($cards), 'subtitle' => $subtitle];
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
