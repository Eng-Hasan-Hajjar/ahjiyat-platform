<?php

namespace App\Http\Controllers;

use App\Services\Social\FriendshipService;
use App\Services\Social\PlayerCardLoader;
use App\Services\Social\PlayerSearchService;
use Illuminate\Http\Request;

/** بحث لاعبين بالاسم المعروض. GET فقط وبلا أي تعديل. حقول آمنة فقط، ترقيم إلزامي، وحد أدنى للطول. */
class PlayerSearchController extends Controller
{
    public function index(Request $request, PlayerSearchService $search, FriendshipService $friendships, PlayerCardLoader $cards)
    {
        $request->validate(['q' => ['nullable', 'string', 'max:50']]);

        $viewer = $request->user();
        $term = trim((string) $request->query('q', ''));
        $results = null;
        $relations = [];

        if ($search->isValidTerm($term)) {
            $results = $search->search($viewer, $term);
            $cards->attach($results->getCollection(), $viewer);
            $relations = $friendships->relationsFor($viewer, $results->getCollection());
        }

        return view('friends.search', ['term' => $term, 'results' => $results, 'relations' => $relations, 'minLength' => $search->minLength()]);
    }
}
