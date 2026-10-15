<?php

namespace App\Http\Controllers;

use App\Http\Requests\SearchRequest;
use App\Services\Search\GlobalSearchService;
use App\Services\Search\SearchContext;
use App\Services\Search\SearchResult;
use Illuminate\Http\JsonResponse;

/**
 * E27: البحث العام (قراءة فقط). صفحة /search تعمل بلا JavaScript (نموذج GET)؛ /search/suggest نقطة JSON للاقتراحات الفورية.
 * عامة للضيوف والأعضاء: كل نوع يطبّق قاعدة ظهور صفحته العامة (انظر مصادر App\Services\Search\Sources). لا كتابة ولا تسجيل لنص البحث.
 */
class SearchController extends Controller
{
    public function __construct(protected GlobalSearchService $search) {}

    public function index(SearchRequest $request)
    {
        $viewer = $request->user();
        $type = $this->search->isType($request->typeFilter()) ? $request->typeFilter() : null;
        $context = SearchContext::for($viewer);
        $common = [
            'types' => collect($this->search->types())->mapWithKeys(fn (string $t) => [$t => $this->search->label($t)])->all(),
            'minLength' => $this->search->overallMinLength($context),
            'guest' => $context->isGuest(),
        ];

        if ($type !== null) {
            $data = $this->search->ofType($type, $request->term(), $viewer, $request->pageNumber(), array_filter(['q' => $request->term() === '' ? null : $this->search->normalize($request->term())->display, 'type' => $type]));

            return view('search.index', $common + $data + ['type' => $type, 'mode' => 'type', 'typeLabel' => $this->search->label($type)]);
        }

        return view('search.index', $common + $this->search->grouped($request->term(), $viewer) + ['type' => null, 'mode' => 'all']);
    }

    public function suggest(SearchRequest $request): JsonResponse
    {
        $data = $this->search->suggest($request->term(), $request->user());
        $query = $data['query'];

        $payload = [
            'state' => $data['state'],
            'query' => $query,
            'groups' => array_map(fn (array $group) => [
                'type' => $group['type'],
                'label' => $group['label'],
                'icon' => $group['icon'],
                'items' => array_map(fn (SearchResult $r) => $r->toSuggestion(), $group['results']),
                'more_url' => $group['has_more'] ? SearchResult::relativePath(route('search.index', ['q' => $query, 'type' => $group['type']])) : null,
            ], $data['groups']),
            'all_url' => SearchResult::relativePath(route('search.index', $query === '' ? [] : ['q' => $query])),
            'note' => in_array('players', $data['notes'], true) && $data['state'] === 'ok' ? 'اكتب '.config('search.min_length_guest_players', 3).' أحرف على الأقل للبحث عن لاعبين.' : null,
        ];

        return response()->json($payload)->header('Cache-Control', 'no-store, private');
    }
}
