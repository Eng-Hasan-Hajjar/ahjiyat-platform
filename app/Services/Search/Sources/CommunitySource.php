<?php

namespace App\Services\Search\Sources;

use App\Models\CreatorPuzzle;
use App\Services\Search\SearchContext;
use App\Services\Search\SearchResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * E28 / E27 - أحجيات المجتمع: **نوع بحث مستقل** بوسم «من المجتمع» (لا يختلط بالأحجيات الرسمية ولا يرث ترتيبها). قاعدة الظهور = is_visible وحدها
 * (منشور ∧ غير مخفي ∧ غير مؤرشف ∧ مبدع معتمَد بحساب سليم) — نفس شرط صفحة /community/puzzles العامة، فالمسودات والمرفوضة والمخفية والمؤرشفة والمعلَّقة لا تُكتشف أبدًا.
 * البحث بعنوان المنشور **فقط** (published_title): لا يلمس المحتوى المشفَّر ولا الجواب ولا التلميح ولا الاختيارات، فلا يصلح «أوراكل» لتخمين الجواب.
 * الأعمدة مختارة صراحة وبضمّ واحد (التصنيف + اسم المبدع) فلا N+1 ولا استعلامات إضافية. لا يُكشَف معرّف داخلي: الرابط بمعرّف ULID عام.
 */
class CommunitySource extends EloquentSource
{
    private const DIFFICULTY = ['easy' => 'سهل', 'medium' => 'متوسط', 'hard' => 'صعب'];

    public function type(): string
    {
        return 'community';
    }

    public function label(): string
    {
        return 'أحجيات المجتمع';
    }

    public function icon(): string
    {
        return 'friends';
    }

    protected function table(): string
    {
        return 'creator_puzzles';
    }

    protected function column(): string
    {
        return 'creator_puzzles.published_title';
    }

    protected function baseQuery(SearchContext $context): Builder
    {
        return CreatorPuzzle::query()->visible()
            ->join('creator_profiles', 'creator_profiles.id', '=', 'creator_puzzles.creator_profile_id')
            ->join('users', 'users.id', '=', 'creator_profiles.user_id')
            ->leftJoin('puzzle_categories', 'puzzle_categories.id', '=', 'creator_puzzles.published_category_id')
            ->select(
                'creator_puzzles.id', 'creator_puzzles.public_id', 'creator_puzzles.published_title', 'creator_puzzles.published_difficulty',
                'users.name as creator_name', 'puzzle_categories.name as category_name',
            );
    }

    protected function tieBreak(Builder $query): Builder
    {
        return $query->orderByDesc('creator_puzzles.plays_count')->orderByDesc('creator_puzzles.id');
    }

    protected function toResult(Model $model, int $tier): SearchResult
    {
        /** @var CreatorPuzzle $model */
        $by = 'بقلم '.$model->getAttribute('creator_name');
        $category = $model->getAttribute('category_name');
        $difficulty = self::DIFFICULTY[$model->published_difficulty] ?? null;

        return new SearchResult(
            type: $this->type(),
            title: (string) $model->published_title,
            url: route('community.puzzles.show', $model->public_id),
            subtitle: ($category ? $category.' · ' : '').$by,
            badge: 'من المجتمع'.($difficulty ? ' · '.$difficulty : ''),
            icon: $this->icon(),
            tier: $tier,
            subject: $model,
        );
    }
}
