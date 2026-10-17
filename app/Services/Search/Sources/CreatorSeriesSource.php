<?php

namespace App\Services\Search\Sources;

use App\Models\CreatorSeries;
use App\Services\CreatorSeries\CreatorSeriesVisibilityService;
use App\Services\Search\PartialProbe;
use App\Services\Search\SearchContext;
use App\Services\Search\SearchResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * E30 / E27 - سلاسل المبدعين: **نوع بحث مستقل** (creator_series) بوسم «سلسلة من المجتمع». قاعدة الظهور = CreatorSeriesVisibilityService نفسها التي تحكم صفحة السلسلة والاكتشاف
 * (منشورة ∧ غير مخفية/مؤرشفة ∧ مبدع معتمَد بحساب سليم ∧ كل عناصرها ظاهرة الآن)، فالمسودة والمرفوضة والمخفية والمؤرشفة وسلسلة فيها عنصر مخفي أو مبدعها معلَّق لا تُكتشف أبدًا.
 * البحث بعنوان اللقطة المنشورة **فقط** (published_title) بلا ضمّ للنسخ ولا لعناصر ولا لمحتوى الأحجيات. الأعمدة مختارة صراحة وبضمّ واحد لاسم المبدع (لا N+1)؛ الرابط بمعرّف ULID عام.
 */
class CreatorSeriesSource extends EloquentSource
{
    public function __construct(PartialProbe $probe, protected CreatorSeriesVisibilityService $visibility)
    {
        parent::__construct($probe);
    }

    public function type(): string
    {
        return 'creator_series';
    }

    public function label(): string
    {
        return 'سلاسل المجتمع';
    }

    public function icon(): string
    {
        return 'grid';
    }

    protected function table(): string
    {
        return 'creator_series';
    }

    protected function column(): string
    {
        return 'creator_series.published_title';
    }

    protected function baseQuery(SearchContext $context): Builder
    {
        return $this->visibility->visibleQuery()
            ->join('creator_profiles as scp', 'scp.id', '=', 'creator_series.creator_profile_id')
            ->join('users as scu', 'scu.id', '=', 'scp.user_id')
            ->select('creator_series.id', 'creator_series.public_id', 'creator_series.published_title', 'creator_series.published_items_count', 'scu.name as creator_name');
    }

    protected function tieBreak(Builder $query): Builder
    {
        return $query->orderByDesc('creator_series.published_at')->orderByDesc('creator_series.id');
    }

    protected function toResult(Model $model, int $tier): SearchResult
    {
        /** @var CreatorSeries $model */
        return new SearchResult(
            type: $this->type(),
            title: (string) $model->published_title,
            url: route('series.show', $model->public_id),
            subtitle: (int) $model->published_items_count.' أحجية · بقلم '.$model->getAttribute('creator_name'),
            badge: 'سلسلة من المجتمع',
            icon: $this->icon(),
            tier: $tier,
            subject: $model,
        );
    }
}
