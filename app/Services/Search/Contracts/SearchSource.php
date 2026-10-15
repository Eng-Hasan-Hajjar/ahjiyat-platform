<?php

namespace App\Services\Search\Contracts;

use App\Services\Search\SearchContext;
use App\Services\Search\SearchQuery;
use App\Services\Search\SearchResult;
use Illuminate\Support\Collection;

/** E27: نوع كيان قابل للبحث. كل مصدر يفرض قاعدة ظهور كيانه بنفسه (نفس قاعدة صفحة القائمة العامة). */
interface SearchSource
{
    public function type(): string;

    public function label(): string;

    public function icon(): string;

    /** الحد الأدنى لطول المصطلح لهذا النوع وهذا المشاهد. */
    public function minLength(SearchContext $context): int;

    /** @return Collection<int, SearchResult> مرتبة بالصلة ثم بترتيب ثابت. $limit صفوف بدءًا من $offset. */
    public function search(SearchQuery $query, SearchContext $context, int $limit, int $offset = 0): Collection;
}
