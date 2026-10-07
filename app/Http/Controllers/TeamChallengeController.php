<?php

namespace App\Http\Controllers;

use App\GameEngine\GameTypeRegistry;
use App\Http\Requests\TeamChallengeRosterRequest;
use App\Http\Requests\TeamChallengeStoreRequest;
use App\Models\Puzzle;
use App\Models\Team;
use App\Models\TeamChallenge;
use App\Models\TeamChallengeParticipant;
use App\Models\TeamMembership;
use App\Services\Competitive\CompetitiveEligibility;
use App\Services\Social\PlayerCardLoader;
use App\Services\Teams\TeamChallengePlayService;
use App\Services\Teams\TeamChallengeService;
use App\Services\Teams\TeamException;
use App\Services\Teams\TeamMembershipService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * صفحات تحدّيات الفرق (E20). كل هوية ودور من قاعدة البيانات (عضوية المستخدم المصادَق وروستر التحدّي)، لا من الطلب. التحدّي غير المعتمَد خاص بمشاركيه وأعضاء الفريقين (غيرهم 404 دون
 * كشف وجوده)؛ المعتمَد عام (الفريقان، النتيجة، اللاعبون المساهمون بقواعد الملف العام). لا إجابات ولا بيانات أحجية مخفية. نتيجة الخصم/تقدّمه التفصيلي لا يُعرضان قبل الاكتمال.
 */
class TeamChallengeController extends Controller
{
    public function __construct(protected TeamChallengeService $challenges, protected TeamMembershipService $members, protected CompetitiveEligibility $eligibility) {}

    public function index(Request $request)
    {
        $membership = $this->members->membershipOf($request->user());

        if ($membership === null) {
            return redirect()->route('teams.index')->with('error', 'انضم إلى فريق أولًا لتشارك بتحدّيات الفرق.');
        }

        $teamId = $membership->team_id;
        $per = (int) config('teams.challenges.per_page', 10);
        $with = ['challenger:id,name,slug', 'opponent:id,name,slug', 'puzzle:id,title', 'results'];
        $mine = fn () => TeamChallenge::query()->involvingTeam($teamId)->with($with)->latest('id');

        return view('teams.challenges.index', [
            'team' => $membership->team, 'canManage' => $membership->isManager(),
            'incoming' => $mine()->where('status', TeamChallenge::STATUS_PENDING)->where('opponent_team_id', $teamId)->where('expires_at', '>', now())->paginate($per, ['*'], 'incoming_page'),
            'outgoing' => $mine()->where('status', TeamChallenge::STATUS_PENDING)->where('challenger_team_id', $teamId)->where('expires_at', '>', now())->paginate($per, ['*'], 'outgoing_page'),
            'active' => $mine()->where('status', TeamChallenge::STATUS_ACCEPTED)->paginate($per, ['*'], 'active_page'),
            'history' => $mine()->whereNotIn('status', [TeamChallenge::STATUS_ACCEPTED])->where(fn ($q) => $q->where('status', '!=', TeamChallenge::STATUS_PENDING)->orWhere('expires_at', '<=', now()))->paginate($per, ['*'], 'history_page'),
        ]);
    }

    public function create(Request $request)
    {
        $membership = $this->members->membershipOf($request->user());

        if ($membership === null || ! $membership->isManager() || ! $membership->team->is_active) {
            return redirect()->route('teams.index')->with('error', 'تحدّيات الفرق لمالك الفريق أو مشرفيه (بفريق مفعَّل) فقط.');
        }

        $request->validate(['opponent' => ['required', 'string', 'max:80'], 'q' => ['nullable', 'string', 'max:50']]);
        $opponent = Team::query()->where('slug', $request->query('opponent'))->where('is_active', true)->where('id', '!=', $membership->team_id)->first();

        if ($opponent === null) {
            return redirect()->route('teams.index')->with('error', 'اختر فريقًا آخر مفعَّلًا لتتحدّاه.');
        }

        $term = trim((string) $request->query('q', ''));
        $puzzles = $this->eligibility->eligiblePuzzlesQuery()->select('id', 'title', 'difficulty', 'time_limit_seconds')->orderBy('title')->orderBy('id')
            ->when($term !== '', fn ($q) => $q->whereRaw("LOWER(title) like ? escape '!'", ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($term)).'%']))
            ->paginate((int) config('teams.challenges.puzzle_search_per_page', 8))->withQueryString();

        return view('teams.challenges.create', ['team' => $membership->team, 'opponent' => $opponent, 'puzzles' => $puzzles, 'term' => $term, 'candidates' => $this->candidates($membership->team)]);
    }

    public function store(TeamChallengeStoreRequest $request): RedirectResponse
    {
        try {
            $opponent = Team::query()->where('slug', $request->input('opponent'))->firstOrFail();
            $puzzle = Puzzle::query()->find($request->integer('puzzle_id')) ?? throw new TeamException('هذه الأحجية غير متاحة.');
            $challenge = $this->challenges->create($request->user(), $opponent, $puzzle, $request->input('roster', []));
        } catch (TeamException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('teams.challenges.show', $challenge)->with('success', 'أُرسل تحدّي الفريق.');
    }

    public function show(Request $request, TeamChallenge $challenge, GameTypeRegistry $games, PlayerCardLoader $cards, TeamChallengePlayService $play)
    {
        $viewer = $request->user();
        $myTeamId = $viewer ? $this->members->membershipOf($viewer)?->team_id : null;
        $seat = $viewer ? TeamChallengeParticipant::query()->where('team_challenge_id', $challenge->id)->where('user_id', $viewer->id)->first() : null;
        $involved = $viewer !== null && ($seat !== null || in_array($myTeamId, [$challenge->challenger_team_id, $challenge->opponent_team_id], true));

        abort_unless($challenge->status === TeamChallenge::STATUS_COMPLETED || $involved, 404);   // غير المشارك لا يرى تحدّيًا غير معتمَد (ولا يُكشف وجوده)

        $challenge->load(['challenger:id,name,slug,is_active', 'opponent:id,name,slug,is_active', 'puzzle:id,title', 'results', 'winner:id,name,slug']);
        $participants = $challenge->participants()->with('user:id,name,public_id,profile_visibility')->orderBy('id')->get();
        $cards->attach($participants->pluck('user')->filter(), $viewer);

        $completed = $challenge->status === TeamChallenge::STATUS_COMPLETED;
        $membership = $viewer ? $this->members->membershipOf($viewer) : null;
        $isManagerOf = fn (int $teamId) => $membership !== null && $membership->team_id === $teamId && $membership->isManager() && $membership->team->is_active;
        $run = $seat !== null && $seat->locked_at !== null && $seat->completed_at === null && $challenge->isPlayable() ? $play->activeRun($viewer, $challenge) : null;

        return view('teams.challenges.show', [
            'challenge' => $challenge, 'status' => $challenge->effectiveStatus(), 'completed' => $completed, 'participants' => $participants,
            'seat' => $seat, 'involved' => $involved, 'viewer' => $viewer,
            'canAccept' => $challenge->status === TeamChallenge::STATUS_PENDING && $challenge->expires_at->greaterThan(now()) && $isManagerOf($challenge->opponent_team_id),
            'canCancel' => $challenge->status === TeamChallenge::STATUS_PENDING && $isManagerOf($challenge->challenger_team_id),
            'canEditRoster' => $challenge->status === TeamChallenge::STATUS_PENDING && $challenge->expires_at->greaterThan(now()) && $isManagerOf($challenge->challenger_team_id),
            'candidates' => $membership !== null && $challenge->status === TeamChallenge::STATUS_PENDING && $isManagerOf($membership->team_id) ? $this->candidates($membership->team) : collect(),
            'selectedIds' => $participants->where('team_id', $myTeamId)->pluck('user_id')->all(),
            'canStart' => $seat !== null && $seat->locked_at !== null && $seat->completed_at === null && $challenge->isPlayable() && $run === null,
            'run' => $run, 'puzzle' => $run ? $challenge->puzzle()->first() : null, 'renderer' => $run ? $games->rendererFor($challenge->puzzle()->first()) : null,
            'startedMs' => $run ? (int) ($run->server_state['started_ms'] ?? 0) : 0,
        ]);
    }

    public function accept(TeamChallengeRosterRequest $request, TeamChallenge $challenge): RedirectResponse
    {
        return $this->act($request, $challenge, fn () => $this->challenges->accept($request->user(), $challenge, $request->input('roster', [])), 'قبلتم التحدّي وقُفل الروستران. يمكن للاعبي الروستر اللعب الآن.');
    }

    public function decline(Request $request, TeamChallenge $challenge): RedirectResponse
    {
        return $this->act($request, $challenge, fn () => $this->challenges->decline($request->user(), $challenge), 'رُفض التحدّي.', route('teams.challenges.index'));
    }

    public function cancel(Request $request, TeamChallenge $challenge): RedirectResponse
    {
        return $this->act($request, $challenge, fn () => $this->challenges->cancel($request->user(), $challenge), 'أُلغي التحدّي.', route('teams.challenges.index'));
    }

    public function roster(TeamChallengeRosterRequest $request, TeamChallenge $challenge): RedirectResponse
    {
        return $this->act($request, $challenge, fn () => $this->challenges->setRoster($request->user(), $challenge, $request->input('roster', [])), 'حُدِّث روستر فريقكم.');
    }

    protected function act(Request $request, TeamChallenge $challenge, \Closure $action, string $ok, ?string $to = null): RedirectResponse
    {
        $membership = $this->members->membershipOf($request->user());
        $involved = $membership !== null && in_array($membership->team_id, [$challenge->challenger_team_id, $challenge->opponent_team_id], true);
        abort_unless($involved, 404);                                    // IDOR: تحدّي فريقين آخرين غير موجود لك

        try {
            $action();
        } catch (TeamException $e) {
            return redirect()->route('teams.challenges.show', $challenge)->with('error', $e->getMessage());
        }

        return redirect($to ?? route('teams.challenges.show', $challenge))->with('success', $ok);
    }

    /** أعضاء الفريق المؤهَّلون للروستر (موثَّقون وغير مجمَّدين). */
    protected function candidates(Team $team)
    {
        return TeamMembership::query()->where('team_id', $team->id)->whereHas('user', fn ($q) => $q->whereNotNull('email_verified_at')->where('is_frozen', false))
            ->with('user:id,name,public_id')->orderByRaw("case role when 'owner' then 0 when 'admin' then 1 else 2 end")->orderBy('id')->limit((int) config('teams.max_members_cap', 50))->get();
    }
}
