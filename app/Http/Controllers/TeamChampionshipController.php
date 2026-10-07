<?php

namespace App\Http\Controllers;

use App\Models\TeamChampionship;
use App\Services\Teams\TeamChampionshipStandingsService;
use Illuminate\Http\Request;

/** بطولات الفرق العامة (E20-D21/D22): قراءة فقط. المنشورة والمعتمَدة فقط (المسودة والملغاة 404). الترتيب مشتق من ترتيب الأحداث المعتمَد (لا جمع درجات خام). */
class TeamChampionshipController extends Controller
{
    public function index(Request $request)
    {
        $per = (int) config('teams.championships.per_page', 12);
        $base = fn () => TeamChampionship::query()->public()->with('champion:id,name,slug')->withCount('events');

        return view('team-championships.index', [
            'live' => $base()->where('status', TeamChampionship::STATUS_PUBLISHED)->orderByDesc('is_featured')->orderBy('starts_at')->get(),
            'completed' => $base()->where('status', TeamChampionship::STATUS_COMPLETED)->orderByDesc('finalized_at')->orderByDesc('id')->paginate($per),
        ]);
    }

    public function show(TeamChampionship $championship, TeamChampionshipStandingsService $standings)
    {
        abort_unless(in_array($championship->status, [TeamChampionship::STATUS_PUBLISHED, TeamChampionship::STATUS_COMPLETED], true), 404);

        $championship->load('champion:id,name,slug');
        $events = $championship->events()->get(['competitive_events.id', 'title', 'slug', 'status', 'ends_at', 'team_rankings_finalized_at']);

        return view('team-championships.show', ['championship' => $championship, 'events' => $events, 'board' => $standings->standings($championship), 'points' => $championship->pointsMap()]);
    }
}
