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
