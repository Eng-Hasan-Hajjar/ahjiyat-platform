<?php

namespace App\Http\Controllers;

use App\Models\Team;
use App\Models\TeamInvitation;
use App\Services\Teams\TeamException;
use App\Services\Teams\TeamInvitationService;
use App\Services\Teams\TeamJoinRequestService;
use App\Services\Teams\TeamMembershipService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * إجراءات المستخدم على عضويته (E19-B): انضمام مباشر، طلب انضمام وإلغاؤه، مغادرة، ودعواته (قبول/رفض). كلها POST/DELETE. لا معرّف عضوية ولا دور من الطلب:
 * المستخدم هو المصادَق، والفريق من المسار، والدعوة تُربط بمعرّف ULID ثم تتحقق الخدمة أنها له. أي رفض منطقي يعود برسالة عربية محايدة.
 */
class TeamMembershipController extends Controller
{
    public function join(Request $request, Team $team, TeamMembershipService $members): RedirectResponse
    {
        return $this->run(fn () => $members->joinOpen($request->user(), $team), route('teams.show', $team), 'انضممت إلى الفريق.');
    }

    public function requestJoin(Request $request, Team $team, TeamJoinRequestService $requests): RedirectResponse
    {
        return $this->run(fn () => $requests->create($request->user(), $team), route('teams.show', $team), 'أُرسل طلب انضمامك، وسيراجعه مشرفو الفريق.');
    }

    public function cancelRequest(Request $request, Team $team, TeamJoinRequestService $requests): RedirectResponse
    {
        return $this->run(fn () => $requests->cancel($request->user(), $team), route('teams.show', $team), 'أُلغي طلب الانضمام.');
    }

    public function leave(Request $request, Team $team, TeamMembershipService $members): RedirectResponse
    {
        abort_unless($members->roleIn($request->user(), $team) !== null, 404);

        return $this->run(fn () => $members->leave($request->user()), route('teams.index'), 'غادرت الفريق.');
    }

    /** دعواتي المعلّقة (غير المنتهية). */
    public function invitations(Request $request)
    {
        $invitations = TeamInvitation::query()->where('invited_user_id', $request->user()->id)->where('status', TeamInvitation::STATUS_PENDING)
            ->where('expires_at', '>', now())->with(['team:id,name,slug,members_count,max_members,is_active', 'inviter:id,name'])->orderByDesc('id')->limit(30)->get();

        return view('teams.invitations', ['invitations' => $invitations]);
    }

    public function accept(Request $request, TeamInvitation $invitation, TeamInvitationService $invitations): RedirectResponse
    {
        abort_unless($invitation->invited_user_id === $request->user()->id, 404); // IDOR: دعوة غيرك غير موجودة لك

        return $this->run(fn () => $invitations->accept($request->user(), $invitation), route('teams.show', $invitation->team), 'انضممت إلى الفريق.');
    }

    public function decline(Request $request, TeamInvitation $invitation, TeamInvitationService $invitations): RedirectResponse
    {
        abort_unless($invitation->invited_user_id === $request->user()->id, 404);

        return $this->run(fn () => $invitations->decline($request->user(), $invitation), route('teams.invitations'), 'رُفضت الدعوة.');
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
