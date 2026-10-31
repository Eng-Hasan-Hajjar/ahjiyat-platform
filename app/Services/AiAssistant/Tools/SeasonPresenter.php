<?php

namespace App\Services\AiAssistant\Tools;

use App\Models\Season;
use App\Services\SeasonLifecycleService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * E45 - عارض المواسم المشترك: يختار المواسم الظاهرة علنًا ويحوّلها لمصفوفة بحقول صريحة (لا نموذج). حالة الموسم من SeasonLifecycleService وحده.
 */
final class SeasonPresenter
{
    public function __construct(private readonly SeasonLifecycleService $lifecycle) {}

    /** المواسم الظاهرة علنًا وغير المنتهية (مباشر/قادم): المميز ثم الأحدث. @return Collection<int, Season> */
    public function active(CarbonImmutable $now, int $limit = 12): Collection
    {
        return Season::query()->publiclyVisible($now)->notEnded($now)->with('campaign')
            ->orderByDesc('seasons.is_featured')->orderByDesc('seasons.id')->limit($limit)->get();
    }

    /** موسم ظاهر علنًا بمعرّفه النصي (أي حالة: مباشر/قادم/منتهٍ/مؤرشف)، أو null. */
    public function visibleBySlug(string $slug, CarbonImmutable $now): ?Season
    {
        /** @var Builder<Season> $query */
        $query = Season::query()->publiclyVisible($now)->with('campaign');

        return $query->where('seasons.slug', $slug)->first();
    }

    /** أول موسم مباشر الآن، أو null. */
    public function currentLive(CarbonImmutable $now): ?Season
    {
        return $this->active($now)->first(fn (Season $s) => $this->lifecycle->state($s, $now) === SeasonLifecycleService::STATE_LIVE);
    }

    /** @return array<string, mixed> */
    public function summary(Season $season, CarbonImmutable $now): array
    {
        $state = $this->lifecycle->state($season, $now);

        return [
            'slug' => $season->slug,
            'name' => Presenter::text($season->campaign?->title ?? $season->code, 120),
            'tagline' => Presenter::text($season->heroTagline(), 160),
            'state' => $state,
            'state_label' => $this->lifecycle->stateLabel($state),
            'starts_at' => Presenter::date($this->lifecycle->windowStart($season)),
            'ends_at' => Presenter::date($this->lifecycle->windowEnd($season)),
            'is_featured' => (bool) $season->is_featured,
            'path' => Presenter::path('seasons.show', ['season' => $season->slug]),
        ];
    }

    public function source(Season $season): ?array
    {
        return Presenter::link('صفحة الموسم', Presenter::path('seasons.show', ['season' => $season->slug]));
    }
}
