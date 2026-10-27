<?php

namespace App\Services\Editorial;

use App\Models\Campaign;
use App\Models\CompetitiveEvent;
use App\Models\CreatorPuzzle;
use App\Models\CreatorSeries;
use App\Models\Puzzle;
use App\Models\Season;
use App\Models\User;
use App\Services\Collections\CollectionAccess;
use App\Services\CreatorSeries\CreatorSeriesVisibilityService;
use App\Services\Discovery\DiscoverCard;
use App\Services\Discovery\RecommendationService;
use Illuminate\Support\Collection;
use Throwable;

/**
 * E35 - يحوّل مراجع الأرفف (نوع مغلق + معرّف رقمي) إلى بطاقات عرض **وقت القراءة**، بدفعة واحدة لكل نوع (لا استعلام لكل عنصر)، مع إعادة فحص أهلية الظهور العامة لكل نوع بقاعدته الأصلية:
 *
 *   official_puzzle    رسمية ونشطة (origin=official ∧ is_active)
 *   community_puzzle   CreatorPuzzle::visible() (منشورة ∧ غير مخفية/مؤرشفة؛ المبدع المعلَّق/الحساب المجمَّد يخفيان منشوراته)
 *   creator_series     CreatorSeriesVisibilityService::visibleQuery()
 *   campaign           نفس شرط /campaigns (RecommendationService::availableCampaigns)
 *   season             منشورة
 *   competitive_event  public(): منشورة أو مكتملة
 *   public_collection  CollectionAccess::listableQuery (عامة ∧ غير مخفية ∧ مالك سليم وملفه عام ∧ فيها عمل ظاهر ∧ بلا حظر مع المشاهد)
 *
 * ما لا يُحلّ (محذوف/مخفي/مؤرشف/منتهي/محظور على المشاهد) يُسقَط بصمت فيختفي من الرف فورًا. الروابط تُبنى بـroute() بالخادم؛ لا شيء من الخزن يُستعمل كرابط أو صنف.
 * فشل نوع واحد (استثناء) يُعزل ويُسجَّل ولا يكسر الرف ولا الصفحة.
 */
class EditorialShelfResolver
{
    public function __construct(
        protected CollectionAccess $collections,
        protected CreatorSeriesVisibilityService $series,
        protected RecommendationService $recommendations,
    ) {}

    /**
     * @param  list<array{0: string, 1: int}>  $refs  [نوع، معرّف]
     * @return array<string, DiscoverCard> المفتاح "type:id"
     */
    public function resolve(array $refs, ?User $viewer): array
    {
        $byType = [];

        foreach ($refs as [$type, $id]) {
            if (EditorialContentType::tryFrom((string) $type) !== null) {
                $byType[$type][] = (int) $id;
            }
        }

        $out = [];

        foreach ($byType as $type => $ids) {
            $ids = array_values(array_unique($ids));

            try {
                foreach ($this->resolveType(EditorialContentType::from($type), $ids, $viewer) as $id => $card) {
                    $out[$type.':'.$id] = $card;
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $out;
    }

    /** هل المرجع قابل للحل الآن؟ (للتحقق عند الإدارة قبل الحفظ). */
    public function exists(EditorialContentType $type, int $id): bool
    {
        return $this->resolveType($type, [$id], null) !== [];
    }

    /** بحث الإدارة الخادمي المحدود (لا ::all()): حتى 20 نتيجة بالعنوان للنوع المطلوب، من المرشَّحين القابلين للحل فقط. @return array<int, string> id => عنوان */
    public function search(EditorialContentType $type, string $term, int $limit = 20): array
    {
        $term = trim($term);
        $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';
        $limit = max(1, min(50, $limit));

        return match ($type) {
            EditorialContentType::OfficialPuzzle => Puzzle::query()->official()->where('is_active', true)->where('title', 'like', $like)->orderByDesc('id')->limit($limit)->pluck('title', 'id')->all(),
            EditorialContentType::CommunityPuzzle => CreatorPuzzle::query()->visible()->where('published_title', 'like', $like)->orderByDesc('id')->limit($limit)->pluck('published_title', 'id')->all(),
            EditorialContentType::CreatorSeries => $this->series->visibleQuery()->where('creator_series.published_title', 'like', $like)->orderByDesc('creator_series.id')->limit($limit)->pluck('creator_series.published_title', 'creator_series.id')->all(),
            EditorialContentType::Campaign => $this->recommendations->availableCampaigns()->where('campaigns.title', 'like', $like)->orderByDesc('campaigns.id')->limit($limit)->pluck('campaigns.title', 'campaigns.id')->all(),
            EditorialContentType::Season => Season::query()->join('campaigns', 'campaigns.id', '=', 'seasons.campaign_id')->publiclyVisible()->where('campaigns.title', 'like', $like)->orderByDesc('seasons.id')->limit($limit)->pluck('campaigns.title', 'seasons.id')->all(),
            EditorialContentType::CompetitiveEvent => CompetitiveEvent::query()->public()->where('title', 'like', $like)->orderByDesc('id')->limit($limit)->pluck('title', 'id')->all(),
            EditorialContentType::PublicCollection => $this->collections->listableQuery(null, 1)->where('user_collections.title', 'like', $like)->orderByDesc('user_collections.id')->limit($limit)->pluck('user_collections.title', 'user_collections.id')->all(),
        };
    }

    /** عنوان مرجع واحد لعرض الإدارة (الخيار المحفوظ). null إن لم يعد قابلًا للحل. */
    public function label(EditorialContentType $type, int $id): ?string
    {
        return ($this->resolveType($type, [$id], null)[$id] ?? null)?->title;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, DiscoverCard>
     */
    protected function resolveType(EditorialContentType $type, array $ids, ?User $viewer): array
    {
        if ($ids === []) {
            return [];
        }

        return match ($type) {
            EditorialContentType::OfficialPuzzle => $this->officialPuzzles($ids),
            EditorialContentType::CommunityPuzzle => $this->communityPuzzles($ids),
            EditorialContentType::CreatorSeries => $this->creatorSeries($ids),
            EditorialContentType::Campaign => $this->campaigns($ids),
            EditorialContentType::Season => $this->seasons($ids),
            EditorialContentType::CompetitiveEvent => $this->events($ids),
            EditorialContentType::PublicCollection => $this->publicCollections($ids, $viewer),
        };
    }

    /** @param list<int> $ids @return array<int, DiscoverCard> */
    protected function officialPuzzles(array $ids): array
    {
        return Puzzle::query()->official()->select('puzzles.id', 'puzzles.title', 'puzzles.difficulty', 'puzzles.puzzle_category_id')->with('category:id,name')
            ->whereIn('puzzles.id', $ids)->where('puzzles.is_active', true)->get()
            ->mapWithKeys(fn (Puzzle $p) => [(int) $p->id => new DiscoverCard(
                $p->title, route('puzzles.show', $p), 'puzzle', $p->category?->name,
                ['easy' => 'سهل', 'medium' => 'متوسط', 'hard' => 'صعب'][$p->difficulty] ?? null,
                ['easy' => 'success', 'medium' => 'warning', 'hard' => 'danger'][$p->difficulty] ?? 'neutral',
            )])->all();
    }

    /** @param list<int> $ids @return array<int, DiscoverCard> */
    protected function communityPuzzles(array $ids): array
    {
        return CreatorPuzzle::query()->visible()
            ->select('creator_puzzles.id', 'creator_puzzles.public_id', 'creator_puzzles.published_title', 'creator_puzzles.creator_profile_id')
            ->with(['creator:id,user_id', 'creator.user:id,name'])
            ->whereIn('creator_puzzles.id', $ids)->get()
            ->mapWithKeys(fn (CreatorPuzzle $w) => [(int) $w->id => new DiscoverCard(
                (string) $w->published_title, route('community.puzzles.show', $w->public_id), 'puzzle', $w->creator?->user?->name, 'من المجتمع', 'primary',
            )])->all();
    }

    /** @param list<int> $ids @return array<int, DiscoverCard> */
    protected function creatorSeries(array $ids): array
    {
        return $this->series->visibleQuery()
            ->select('creator_series.id', 'creator_series.public_id', 'creator_series.published_title', 'creator_series.published_items_count')
            ->whereIn('creator_series.id', $ids)->get()
            ->mapWithKeys(fn (CreatorSeries $s) => [(int) $s->id => new DiscoverCard(
                (string) $s->published_title, route('series.show', $s->public_id), 'grid', (int) $s->published_items_count.' أحجية', 'سلسلة من المجتمع', 'primary',
            )])->all();
    }

    /** @param list<int> $ids @return array<int, DiscoverCard> */
    protected function campaigns(array $ids): array
    {
        return $this->recommendations->availableCampaigns()->whereIn('campaigns.id', $ids)->get()
            ->mapWithKeys(fn (Campaign $c) => [(int) $c->id => new DiscoverCard(
                $c->title, route('campaigns.show', $c), 'flag', $c->ends_at ? 'تنتهي '.$c->ends_at->format('Y-m-d') : null,
            )])->all();
    }

    /** @param list<int> $ids @return array<int, DiscoverCard> */
    protected function seasons(array $ids): array
    {
        return Season::query()->join('campaigns', 'campaigns.id', '=', 'seasons.campaign_id')
            ->select('seasons.id', 'seasons.slug', 'seasons.is_featured', 'campaigns.title as campaign_title')
            ->publiclyVisible()->whereIn('seasons.id', $ids)->get()
            ->mapWithKeys(fn (Season $s) => [(int) $s->id => new DiscoverCard(
                (string) $s->getAttribute('campaign_title'), route('seasons.show', $s), 'calendar', null, $s->is_featured ? 'موسم مميّز' : null,
            )])->all();
    }

    /** @param list<int> $ids @return array<int, DiscoverCard> */
    protected function events(array $ids): array
    {
        return CompetitiveEvent::query()->public()->whereIn('id', $ids)->get()
            ->mapWithKeys(fn (CompetitiveEvent $e) => [(int) $e->id => new DiscoverCard(
                $e->title, route('competitions.show', $e), 'trophy', 'المشاركون: '.$e->participants_count,
            )])->all();
    }

    /** @param list<int> $ids @return array<int, DiscoverCard> */
    protected function publicCollections(array $ids, ?User $viewer): array
    {
        return $this->collections->listableQuery($viewer, 1)->whereIn('user_collections.id', $ids)
            ->select('user_collections.id', 'user_collections.public_id', 'user_collections.title', 'user_collections.saves_count', 'owner.name as owner_name')->get()
            ->mapWithKeys(fn ($c) => [(int) $c->id => new DiscoverCard(
                (string) $c->title, route('collections.public.show', $c->public_id), 'inventory', 'بقلم '.$c->getAttribute('owner_name'), 'مجموعة', 'primary',
            )])->all();
    }
}
