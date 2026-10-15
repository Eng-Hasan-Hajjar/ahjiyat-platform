<?php

namespace App\Services\Search\Sources;

use App\Models\Puzzle;
use App\Services\Search\SearchContext;
use App\Services\Search\SearchResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * الأحجيات: نفس شرط قائمة /puzzles (is_active). البحث بالعنوان **فقط**: لا يلمس أبدًا answer_hash/answer_raw/hint/solution_data/choices/game_config،
 * فلا يمكن استعمال البحث كـ«أوراكل» لتخمين الجواب. الأعمدة المختارة صراحة لا تشمل أيًّا منها.
 */
class PuzzleSource extends EloquentSource
{
    private const DIFFICULTY = ['easy' => 'سهل', 'medium' => 'متوسط', 'hard' => 'صعب'];

    public function type(): string
    {
        return 'puzzles';
    }

    public function label(): string
    {
        return 'الأحجيات';
    }

    public function icon(): string
    {
        return 'puzzle';
    }

    protected function table(): string
    {
        return 'puzzles';
    }

    protected function column(): string
    {
        return 'puzzles.title';
    }

    protected function baseQuery(SearchContext $context): Builder
    {
        return Puzzle::query()
            ->select('puzzles.id', 'puzzles.title', 'puzzles.difficulty', 'puzzles.puzzle_category_id')
            ->with('category:id,name')
            ->where('puzzles.is_active', true);
    }

    protected function tieBreak(Builder $query): Builder
    {
        return $query->orderByDesc('puzzles.id');
    }

    protected function toResult(Model $model, int $tier): SearchResult
    {
        /** @var Puzzle $model */
        return new SearchResult(
            type: $this->type(),
            title: (string) $model->title,
            url: route('puzzles.show', $model),
            subtitle: $model->category?->name,
            badge: self::DIFFICULTY[$model->difficulty] ?? null,
            icon: $this->icon(),
            tier: $tier,
            subject: $model,
        );
    }
}
