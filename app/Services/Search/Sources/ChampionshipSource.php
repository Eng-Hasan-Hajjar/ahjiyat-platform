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
