<?php

namespace App\Services\Search\Sources;

use App\Models\UserCollection;
use App\Services\Collections\CollectionAccess;
use App\Services\Search\PartialProbe;
use App\Services\Search\SearchContext;
use App\Services\Search\SearchResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * E35 / E27 - مجموعات المجتمع: **نوع بحث مستقل** (collections) بوسم «مجموعة». قاعدة الظهور = CollectionAccess::listableQuery نفسها التي تحكم الاكتشاف وملف اللاعب:
 * عامة ∧ غير مخفية إشرافيًا ∧ مالك موثَّق غير مجمَّد وملفه عام ∧ فيها ≥ 1 عملًا ظاهرًا ∧ بلا حظر بين الباحث والأمين. الخاصة وغير المدرجة **لا تُكتشف أبدًا** بالبحث.
 * البحث بعنوان المجموعة فقط (نص عادي). الأعمدة مختارة صراحة وبضمّ واحد لاسم الأمين (لا N+1)؛ الرابط بمعرّف ULID عام. لا كاش مشترك: الظهور حيّ بكل طلب.
 */
class CollectionSource extends EloquentSource
{
    public function __construct(PartialProbe $probe, protected CollectionAccess $access)
    {
        parent::__construct($probe);
    }

    public function type(): string
    {
        return 'collections';
    }

    public function label(): string
    {
        return 'مجموعات المجتمع';
    }

    public function icon(): string
    {
        return 'inventory';
    }

    protected function table(): string
    {
        return 'user_collections';
    }

    protected function column(): string
    {
        return 'user_collections.title';
    }

    protected function baseQuery(SearchContext $context): Builder
    {
        return $this->access->listableQuery($context->viewer, (int) config('collections.search.min_visible_items', 1))
            ->select('user_collections.id', 'user_collections.public_id', 'user_collections.title', 'user_collections.saves_count', 'owner.name as owner_name');
    }

    protected function tieBreak(Builder $query): Builder
    {
        return $query->orderByDesc('user_collections.saves_count')->orderByDesc('user_collections.id');
    }

    protected function toResult(Model $model, int $tier): SearchResult
    {
        /** @var UserCollection $model */
        return new SearchResult(
            type: $this->type(),
            title: (string) $model->title,
            url: route('collections.public.show', $model->public_id),
            subtitle: 'بقلم '.$model->getAttribute('owner_name'),
            badge: 'مجموعة',
            icon: $this->icon(),
            tier: $tier,
            subject: $model,
        );
    }
}
