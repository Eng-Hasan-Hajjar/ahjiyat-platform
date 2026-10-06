<?php

namespace App\Http\Controllers;

use App\Http\Requests\TeamStoreRequest;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\TeamJoinRequest;
use App\Models\TeamMembership;
use App\Services\Social\PlayerCardLoader;
use App\Services\Teams\TeamCompetitiveRankingService;
use App\Services\Teams\TeamException;
use App\Services\Teams\TeamMembershipService;
use App\Services\Teams\TeamService;
use Illuminate\Http\Request;

/** الصفحات العامة للفرق (E19-C): الدليل، الملف، الإنشاء، جدول الميداليات. قراءة فقط عدا store. الدور والمالك والفريق الحالي من قاعدة البيانات لا من الطلب. */
class TeamController extends Controller
{
    public function index(Request $request)
    {
        $request->validate(['q' => ['nullable', 'string', 'max:40']]);
        $term = trim((string) $request->query('q', ''));

        $teams = Team::query()->directory()
            ->when($term !== '', fn ($q) => $q->whereRaw("LOWER(name) like ? escape '!'", ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($term)).'%']))
            ->orderByDesc('members_count')->orderBy('id')->paginate((int) config('teams.directory_per_page', 12))->withQueryString();

        return view('teams.index', ['teams' => $teams, 'term' => $term]);
    }

    public function show(Request $request, Team $team, TeamMembershipService $members, TeamCompetitiveRankingService $ranking, PlayerCardLoader $cards)
    {
        $viewer = $request->user();
        $showRoster = $viewer ? $viewer->can('viewRoster', $team) : $team->isPublic();

        $roster = collect();

        if ($showRoster) {
            $roster = TeamMembership::query()->where('team_id', $team->id)->with('user:id,name,public_id,profile_visibility')
                ->orderByRaw("case role when 'owner' then 0 when 'admin' then 1 else 2 end")->orderBy('joined_at')->orderBy('id')->limit((int) config('teams.max_members_cap', 50))->get();
            $cards->attach($roster->pluck('user')->filter(), $viewer);
        }

        $myRole = $viewer ? $members->roleIn($viewer, $team) : null;

        return view('teams.show', [
            'team' => $team->load('owner:id,name,public_id,profile_visibility'),
            'roster' => $roster,
            'showRoster' => $showRoster,
            'myRole' => $myRole,
            'state' => $this->joinState($viewer, $team, $members, $myRole),
            'stats' => $ranking->stats($team),
            'recent' => $ranking->recent($team),
        ]);
    }

    public function create(Request $request, TeamMembershipService $members)
    {
        if (($m = $members->membershipOf($request->user())) !== null) {
            return redirect()->route('teams.show', $m->team)->with('error', 'أنت عضو في فريق بالفعل.');
        }

        return view('teams.create');
    }

    public function store(TeamStoreRequest $request, TeamService $teams)
    {
        try {
            $team = $teams->create($request->user(), $request->validated());
        } catch (TeamException $e) {
            return back()->withInput()->withErrors(['name' => $e->getMessage()]);
        }

        return redirect()->route('teams.show', $team)->with('success', 'تم إنشاء الفريق، وأنت مالكه.');
    }

    /** فريقي: يحوّل لصفحة فريقي أو لدليل الفرق. */
    public function mine(Request $request, TeamMembershipService $members)
    {
        $m = $members->membershipOf($request->user());

        return $m ? redirect()->route('teams.show', $m->team) : redirect()->route('teams.index');
    }

    public function leaderboard(TeamCompetitiveRankingService $ranking)
    {
        return view('teams.leaderboard', ['table' => $ranking->medalTable()]);
    }

    /** حالة زر الانضمام لهذا الزائر (من قاعدة البيانات). */
    protected function joinState($viewer, Team $team, TeamMembershipService $members, ?string $myRole): string
    {
        return match (true) {
            $viewer === null => 'guest',
            $myRole !== null => 'member',
            ! $team->is_active => 'inactive',
            $members->membershipOf($viewer) !== null => 'in_other_team',
            TeamJoinRequest::query()->where('team_id', $team->id)->where('user_id', $viewer->id)->where('status', 'pending')->exists() => 'requested',
            TeamInvitation::query()->where('team_id', $team->id)->where('invited_user_id', $viewer->id)->where('status', 'pending')->where('expires_at', '>', now())->exists() => 'invited',
            $team->isFull() => 'full',
            default => $team->join_policy,
        };
    }
}
