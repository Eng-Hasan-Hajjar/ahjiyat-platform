<?php

namespace App\Services\Search\Sources;

use App\Services\Search\Contracts\SearchSource;
use App\Services\Search\PartialProbe;
use App\Services\Search\SearchContext;
use App\Services\Search\SearchQuery;
use App\Services\Search\SearchResult;
use App\Services\Search\TextFold;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * E27: أساس مصادر البحث. يُطبِّق المطابقة والترتيب بالصلة ثم يترك للمصدر قاعدة الظهور والأعمدة والترتيب الثابت.
 *
 * المطابقة (WHERE):
 *   أ) بادئة على **العمود الخام** (يستعمل الفهرس): col LIKE 'مطوي%' OR col LIKE 'خام-صغير%'.
 *   ب) فقط إذا كان الجدول صغيرًا (PartialProbe): كل كلمة من المصطلح موجودة في العمود المطوي: fold(col) LIKE '%كلمة%' (AND بين الكلمات).
 * الترتيب بالصلة (يُحسب لصفوف المطابقة فقط): 0 تطابق تام، 1 بادئة، 2 بادئة كلمة، 3 جزئي — ثم ترتيب المصدر الثابت.
 * كل القيم مربوطة بـbindings (حتى % و _ تُهرَّب)؛ ما يُركَّب نصًّا هو اسم العمود المثبَّت بالكود بعد تحقق صارم فقط.
 */
abstract class EloquentSource implements SearchSource
{
    public function __construct(protected PartialProbe $probe) {}

    abstract protected function table(): string;

    /** عمود البحث الأساسي (مؤهَّل بالجدول). */
    abstract protected function column(): string;

    /** الاستعلام الأساسي: قاعدة الظهور + الأعمدة المختارة صراحة (لا select *). */
    abstract protected function baseQuery(SearchContext $context): Builder;

    /** ترتيب ثابت بعد الصلة (لقطع التعادل بقرار قاطع). */
    abstract protected function tieBreak(Builder $query): Builder;

    abstract protected function toResult(Model $model, int $tier): SearchResult;

    public function minLength(SearchContext $context): int
    {
        return max(1, (int) config('search.min_length', 2));
    }

    public function search(SearchQuery $query, SearchContext $context, int $limit, int $offset = 0): Collection
    {
        if ($query->isEmpty() || ! $query->meets($this->minLength($context))) {
            return collect();
        }

        $builder = $this->applyRelevance($this->baseQuery($context), $query);

        return $this->tieBreak($builder)
            ->offset(max(0, $offset))->limit(max(1, $limit))
            ->get()
            ->map(fn (Model $model) => $this->toResult($model, (int) $model->getAttribute('search_tier')))
            ->values();
    }

    protected function applyRelevance(Builder $builder, SearchQuery $query): Builder
    {
        $column = TextFold::identifier($this->column());
        $partial = $this->probe->allowed($this->type(), $this->table());
        $prefixes = array_values(array_unique([$query->foldedPrefixPattern(), $query->rawPrefixPattern()]));
        $rawLike = $column." like ? escape '!'";
        $fold = TextFold::sqlFold($column);
        $foldLike = $fold." like ? escape '!'";

        $builder->where(function (Builder $where) use ($prefixes, $rawLike, $foldLike, $partial, $query) {
            foreach ($prefixes as $pattern) {
                $where->orWhereRaw($rawLike, [$pattern]);
            }

            if ($partial) {
                $where->orWhere(function (Builder $all) use ($foldLike, $query) {
                    foreach ($query->tokenPatterns() as $pattern) {
                        $all->whereRaw($foldLike, [$pattern]);
                    }
                });
            }
        });

        if ($partial) {
            $tierSql = 'CASE WHEN '.$fold.' = ? THEN 0 WHEN '.$foldLike.' THEN 1 WHEN '.$foldLike.' THEN 2 ELSE 3 END as search_tier';
            $tierBindings = [$query->folded, $query->foldedPrefixPattern(), $query->foldedWordPattern()];
        } else {
            $tierSql = 'CASE WHEN LOWER('.$column.') = ? THEN 0 WHEN '.$rawLike.' OR '.$rawLike.' THEN 1 ELSE 3 END as search_tier';
            $tierBindings = [mb_strtolower($query->display, 'UTF-8'), $query->foldedPrefixPattern(), $query->rawPrefixPattern()];
        }

        return $builder->selectRaw($tierSql, $tierBindings)->orderBy('search_tier');
    }
}
