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
