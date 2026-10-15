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
