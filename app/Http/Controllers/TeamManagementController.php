<?php

namespace App\Http\Controllers;

use App\Http\Requests\TeamSettingsRequest;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\TeamJoinRequest;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Social\PlayerCardLoader;
use App\Services\Social\PlayerSearchService;
use App\Services\Teams\TeamException;
use App\Services\Teams\TeamInvitationService;
use App\Services\Teams\TeamJoinRequestService;
use App\Services\Teams\TeamMembershipService;
use App\Services\Teams\TeamService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * إدارة الفريق (E19-C). **التفويض من دور المستخدم الفعلي بقاعدة البيانات** (TeamPolicy) قبل أي خدمة: غير المخوَّل 403 ولا شيء يتغير (IDOR: فريق آخر أو دور أدنى).
 * الإعدادات الحساسة (الاسم، الخصوصية، سياسة الانضمام، السعة، التعطيل، نقل الملكية، الأدوار) للمالك؛ المشرف يدير الأعضاء والدعوات والطلبات فقط.
 * لا owner_id ولا role للفريق من الطلب: role يُقبل فقط في تغيير دور (admin|member) ويتحقق منه الخدمة. كلها POST/PATCH/DELETE بـCSRF.
 */
class TeamManagementController extends Controller
{
    public function manage(Request $request, Team $team, PlayerSearchService $search, PlayerCardLoader $cards)
    {
        $viewer = $request->user();
        abort_unless($viewer->can('viewManagement', $team), 403);

        $request->validate(['q' => ['nullable', 'string', 'max:40']]);
        $term = trim((string) $request->query('q', ''));

        $members = TeamMembership::query()->where('team_id', $team->id)->with('user:id,name,public_id,profile_visibility')
            ->orderByRaw("case role when 'owner' then 0 when 'admin' then 1 else 2 end")->orderBy('joined_at')->orderBy('id')->get();
        $cards->attach($members->pluck('user')->filter(), $viewer);

        $results = null;
        $inTeams = [];

        if ($term !== '' && $viewer->can('manageMembers', $team) && $search->isValidTerm($term)) {
            $results = $search->search($viewer, $term);
            $inTeams = TeamMembership::query()->whereIn('user_id', $results->pluck('id'))->pluck('user_id')->all();
            $cards->attach($results->getCollection(), $viewer);
        }

        return view('teams.manage', [
            'team' => $team,
            'members' => $members,
            'myRole' => $members->firstWhere('user_id', $viewer->id)?->role,
            'invitations' => TeamInvitation::query()->where('team_id', $team->id)->where('status', TeamInvitation::STATUS_PENDING)->where('expires_at', '>', now())->with('invitedUser:id,name,public_id')->orderByDesc('id')->limit(50)->get(),
            'requests' => TeamJoinRequest::query()->where('team_id', $team->id)->where('status', TeamJoinRequest::STATUS_PENDING)->with('user:id,name,public_id')->orderBy('id')->limit(50)->get(),
            'results' => $results,
            'inTeams' => $inTeams,
            'term' => $term,
            'canManage' => $viewer->can('manageMembers', $team),
            'canSettings' => $viewer->can('manageSettings', $team),
        ]);
    }

    public function update(TeamSettingsRequest $request, Team $team, TeamService $teams): RedirectResponse
    {
        abort_unless($request->user()->can('manageSettings', $team), 403);

        return $this->run(fn () => $teams->updateSettings($request->user(), $team, $request->validated()), route('teams.manage', $team), 'حُفظت إعدادات الفريق.');
    }

    public function deactivate(Request $request, Team $team, TeamService $teams): RedirectResponse
    {
        abort_unless($request->user()->can('manageSettings', $team), 403);

        return $this->run(fn () => $teams->deactivate($request->user(), $team), route('teams.show', $team), 'عُطِّل الفريق. التاريخ محفوظ ولا أعضاء جدد.');
    }

    public function invite(Request $request, Team $team, User $user, TeamInvitationService $invitations): RedirectResponse
    {
        abort_unless($request->user()->can('manageMembers', $team), 403);

        return $this->run(fn () => $invitations->invite($request->user(), $team, $user), route('teams.manage', $team), 'أُرسلت الدعوة.');
    }

    public function cancelInvitation(Request $request, Team $team, TeamInvitation $invitation, TeamInvitationService $invitations): RedirectResponse
    {
        abort_unless($request->user()->can('manageMembers', $team), 403);
        abort_unless($invitation->team_id === $team->id, 404);

        return $this->run(fn () => $invitations->cancel($request->user(), $team, $invitation), route('teams.manage', $team), 'أُلغيت الدعوة.');
    }

    public function acceptRequest(Request $request, Team $team, TeamJoinRequest $joinRequest, TeamJoinRequestService $requests): RedirectResponse
    {
        abort_unless($request->user()->can('manageMembers', $team), 403);
        abort_unless($joinRequest->team_id === $team->id, 404);

        return $this->run(fn () => $requests->accept($request->user(), $team, $joinRequest), route('teams.manage', $team), 'قُبل الطلب.');
    }

    public function declineRequest(Request $request, Team $team, TeamJoinRequest $joinRequest, TeamJoinRequestService $requests): RedirectResponse
    {
        abort_unless($request->user()->can('manageMembers', $team), 403);
        abort_unless($joinRequest->team_id === $team->id, 404);

        return $this->run(fn () => $requests->decline($request->user(), $team, $joinRequest), route('teams.manage', $team), 'رُفض الطلب.');
    }

    public function removeMember(Request $request, Team $team, User $user, TeamMembershipService $members): RedirectResponse
    {
        abort_unless($request->user()->can('manageMembers', $team), 403);

        return $this->run(fn () => $members->remove($request->user(), $team, $user), route('teams.manage', $team), 'أُزيل العضو.');
    }

    public function changeRole(Request $request, Team $team, User $user, TeamMembershipService $members): RedirectResponse
    {
        abort_unless($request->user()->can('manageSettings', $team), 403);
        $data = $request->validate(['role' => ['required', 'in:admin,member']]);

        return $this->run(fn () => $members->changeRole($request->user(), $team, $user, $data['role']), route('teams.manage', $team), 'تغيّر دور العضو.');
    }

    public function transfer(Request $request, Team $team, User $user, TeamService $teams): RedirectResponse
    {
        abort_unless($request->user()->can('manageSettings', $team), 403);

        return $this->run(fn () => $teams->transferOwnership($request->user(), $team, $user), route('teams.manage', $team), 'نُقلت ملكية الفريق، وأصبحتَ مشرفًا.');
    }

    protected function run(\Closure $action, string $to, string $ok): RedirectResponse
    {
        try {
            $action();
        } catch (TeamException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect($to)->with('success', $ok);
    }
}
