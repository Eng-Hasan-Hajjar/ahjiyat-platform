<?php

namespace App\Services\Search\Contracts;

use App\Services\Search\SearchContext;
use App\Services\Search\SearchQuery;
use App\Services\Search\SearchResult;
use Illuminate\Support\Collection;

/**
 * E27: واجهة محرك البحث. التنفيذ الحالي DatabaseSearchBackend (قاعدة البيانات). محرك خارجي مستقبلي (Meilisearch/Typesense/…) = تنفيذ جديد لهذه الواجهة
 * فقط، دون لمس المتحكّمات ولا القوالب؛ ويجب أن يحترم نفس قواعد الظهور (انظر docs/search-discovery-e27.md).
 */
interface SearchBackend
{
    /** @return list<string> أنواع الكيانات المتاحة بالترتيب الافتراضي. */
    public function types(): array;

    public function label(string $type): string;

    public function minLength(string $type, SearchContext $context): int;

    /**
     * @return Collection<int, SearchResult> نتائج نوع واحد.
     */
    public function searchType(string $type, SearchQuery $query, SearchContext $context, int $limit, int $offset = 0): Collection;
}
