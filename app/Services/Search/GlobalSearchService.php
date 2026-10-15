<?php

namespace App\Services\Search;

use App\Models\User;
use App\Services\Search\Contracts\SearchBackend;
use Illuminate\Pagination\Paginator;

/**
 * E27: واجهة البحث العامة للمتحكّمات. تنظّف المُدخَل وتطبّع، تفرض الحدود، وتفوّض للمحرك (SearchBackend).
 * لا تسجّل نص البحث ولا هوية الباحث بأي مكان (لا سجل ولا جدول ولا كاش): انظر قرار التحليلات بالمستند.
 *
 * حالات الإخراج (state): empty (لا مُدخَل) · too_short (دون الحدّ الأدنى لكل الأنواع) · ok.
 */
class GlobalSearchService
{
    public function __construct(protected SearchBackend $backend) {}

    public function normalize(?string $input): SearchQuery
    {
        return SearchQuery::from($input);
    }

    /** @return list<string> */
    public function types(): array
    {
        return $this->backend->types();
    }

    public function label(string $type): string
    {
        return $this->backend->label($type);
    }

    public function isType(?string $type): bool
    {
        return is_string($type) && in_array($type, $this->backend->types(), true);
    }

    /** أقل حدّ أدنى لطول المصطلح بين الأنواع لهذا الباحث (يحدد أبكر لحظة تُجدي فيها الاقتراحات). */
    public function overallMinLength(SearchContext $context): int
    {
        return min(array_map(fn (string $t) => $this->backend->minLength($t, $context), $this->backend->types()));
    }

    /**
     * اقتراحات فورية (autocomplete): بحد أقصى لكل نوع ولكل الاقتراحات. مجموعة فيها تطابق تام تتقدّم.
     *
     * @return array{state: string, query: string, groups: list<array{type: string, label: string, icon: string, results: list<SearchResult>, has_more: bool}>, notes: list<string>}
     */
    public function suggest(?string $input, ?User $viewer): array
    {
        return $this->gather($this->normalize($input), SearchContext::for($viewer), (int) config('search.suggest_per_type', 3), (int) config('search.suggest_total', 9));
    }

    /**
     * صفحة البحث «الكل»: أفضل النتائج لكل نوع.
     *
     * @return array{state: string, query: string, groups: list<array{type: string, label: string, icon: string, results: list<SearchResult>, has_more: bool}>, notes: list<string>}
     */
    public function grouped(?string $input, ?User $viewer): array
    {
        return $this->gather($this->normalize($input), SearchContext::for($viewer), (int) config('search.all_per_type', 5), null);
    }

    /**
     * تبويب نوع واحد بترقيم بسيط (بلا COUNT) وسقف عمق: الضيف أقل عمقًا من الأعضاء.
     *
     * @return array{state: string, query: string, results: Paginator, notes: list<string>, capped: bool}
     */
    public function ofType(string $type, ?string $input, ?User $viewer, int $page, array $linkQuery = []): array
    {
        $context = SearchContext::for($viewer);
        $query = $this->normalize($input);
        $size = max(1, (int) config('search.page_size', 12));
        $cap = max(1, (int) config($context->isGuest() ? 'search.guest_max_page' : 'search.max_page', 5));
        $effective = min(max(1, $page), $cap);
        $items = collect();
        $state = $this->stateFor($query, $context);
        $notes = [];

        if ($state === 'ok' && ! $query->meets($this->backend->minLength($type, $context))) {
            $state = 'too_short';
        }

        if ($state === 'ok') {
            $items = $this->backend->searchType($type, $query, $context, $size + 1, ($effective - 1) * $size);
        }

        $hasMore = $items->count() > $size && $effective < $cap;
        $capped = $items->count() > $size && $effective >= $cap;
        $paginator = new Paginator($items->take($size)->values(), $size, $effective, ['path' => route('search.index'), 'query' => $linkQuery]);
        $paginator->hasMorePagesWhen($hasMore);

        return ['state' => $state, 'query' => $query->display, 'results' => $paginator, 'notes' => $notes, 'capped' => $capped];
    }

    /** @return array{state: string, query: string, groups: list<array>, notes: list<string>} */
    private function gather(SearchQuery $query, SearchContext $context, int $perType, ?int $total): array
    {
        $state = $this->stateFor($query, $context);
        $groups = [];
        $notes = [];

        if ($state === 'ok') {
            foreach ($this->backend->types() as $order => $type) {
                if (! $query->meets($this->backend->minLength($type, $context))) {
                    $notes[] = $type;

                    continue;
                }

                $found = $this->backend->searchType($type, $query, $context, $perType + 1);

                if ($found->isEmpty()) {
                    continue;
                }

                $groups[] = [
                    'type' => $type,
                    'label' => $this->backend->label($type),
                    'icon' => $found->first()->icon,
                    'results' => $found->take($perType)->values()->all(),
                    'has_more' => $found->count() > $perType,
                    'best' => $found->min('tier'),
                    'order' => $order,
                ];
            }

            usort($groups, fn (array $a, array $b) => [$a['best'], $a['order']] <=> [$b['best'], $b['order']]);

            if ($total !== null) {
                $left = $total;
                foreach ($groups as $i => $group) {
                    $groups[$i]['results'] = array_slice($group['results'], 0, max(0, $left));
                    $left -= count($groups[$i]['results']);
                    $groups[$i]['has_more'] = $group['has_more'] || count($groups[$i]['results']) < count($group['results']);
                }
                $groups = array_values(array_filter($groups, fn (array $g) => $g['results'] !== []));
            }
        }

        return [
            'state' => $state,
            'query' => $query->display,
            'groups' => array_map(fn (array $g) => array_diff_key($g, ['best' => 1, 'order' => 1]), $groups),
            'notes' => $notes,
        ];
    }

    private function stateFor(SearchQuery $query, SearchContext $context): string
    {
        return match (true) {
            $query->isEmpty() => 'empty',
            ! $query->meets($this->overallMinLength($context)) => 'too_short',
            default => 'ok',
        };
    }
}
