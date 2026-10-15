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
