<?php

namespace App\Services\Teams;

use App\Events\TeamJoinRequestAccepted;
use App\Events\TeamJoinRequestCreated;
use App\Models\Team;
use App\Models\TeamJoinRequest;
use App\Models\TeamMembership;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * طلبات الانضمام (E19-B): لسياسة request فقط (الفريق المفتوح ينضم مباشرة، والمقتصر على الدعوة لا يقبل طلبات). المستخدم ينشئ ويلغي طلبه؛ المالك/المشرف
 * يقبل ويرفض. قبول ذري يعيد فحص الأهلية والسعة. طلب معلّق واحد لكل (فريق، مستخدم) بقيد UNIQUE، وحد لطلبات المستخدم المعلّقة. الرفض بلا إشعار.
 */
class TeamJoinRequestService
{
    public function __construct(protected TeamMembershipService $members) {}

    public function create(User $user, Team $team): TeamJoinRequest
    {
        if ($team->join_policy !== Team::JOIN_REQUEST) {
            throw new TeamException($team->join_policy === Team::JOIN_OPEN ? 'هذا الفريق مفتوح: انضم مباشرة.' : 'هذا الفريق بدعوة فقط.');
        }

        if (($reason = $this->members->joinBlocker($user, $team)) !== null) {
            throw new TeamException($reason);
        }

        $pending = TeamJoinRequest::query()->where('user_id', $user->getKey())->where('status', TeamJoinRequest::STATUS_PENDING)->count();

        if ($pending >= (int) config('teams.max_pending_requests_per_user', 5)) {
            throw new TeamException('لديك طلبات انضمام معلّقة كثيرة: ألغِ بعضها أولًا.');
        }

        try {
            return DB::transaction(function () use ($user, $team) {
                $request = TeamJoinRequest::create([
                    'team_id' => $team->getKey(), 'user_id' => $user->getKey(), 'status' => TeamJoinRequest::STATUS_PENDING,
                    'pending_key' => TeamJoinRequest::pendingKey($team->getKey(), $user->getKey()),
                ]);

                $this->afterCommit(fn () => event(new TeamJoinRequestCreated($request->getKey())));

                return $request;
            });
        } catch (UniqueConstraintViolationException) {
            throw new TeamException('لديك طلب معلّق لهذا الفريق بالفعل.');
        }
    }

    /** المستخدم يلغي طلبه المعلّق لهذا الفريق (لا معرّف من العميل). */
    public function cancel(User $user, Team $team): TeamJoinRequest
    {
        $request = TeamJoinRequest::query()->where('team_id', $team->getKey())->where('user_id', $user->getKey())->where('status', TeamJoinRequest::STATUS_PENDING)->first()
            ?? throw new TeamException('لا يوجد طلب معلّق لإلغائه.');

        TeamJoinRequest::query()->whereKey($request->getKey())->where('status', TeamJoinRequest::STATUS_PENDING)
            ->update(['status' => TeamJoinRequest::STATUS_CANCELLED, 'pending_key' => null, 'decided_at' => now()]);

        return $request->refresh();
    }

    public function accept(User $actor, Team $team, TeamJoinRequest $request): TeamMembership
    {
        $this->assertManager($actor, $team, $request);

        return DB::transaction(function () use ($actor, $team, $request) {
            $claimed = TeamJoinRequest::query()->whereKey($request->getKey())->where('team_id', $team->getKey())->where('status', TeamJoinRequest::STATUS_PENDING)
                ->update(['status' => TeamJoinRequest::STATUS_ACCEPTED, 'pending_key' => null, 'decided_by' => $actor->getKey(), 'decided_at' => now()]);

            if ($claimed !== 1) {
                throw new TeamException('لم يعد هذا الطلب قائمًا.');
            }

            // الأهلية (فريق واحد، حظر، حساب) والسعة والفريق المفعَّل: تُعاد لحظة القبول. الفشل يتراجع معه تحديث الطلب.
            $membership = $this->members->addMember($team, $request->user()->firstOrFail());

            $this->afterCommit(fn () => event(new TeamJoinRequestAccepted($request->getKey())));

            return $membership;
        });
    }

    public function decline(User $actor, Team $team, TeamJoinRequest $request): TeamJoinRequest
    {
        $this->assertManager($actor, $team, $request);

        $affected = TeamJoinRequest::query()->whereKey($request->getKey())->where('status', TeamJoinRequest::STATUS_PENDING)
            ->update(['status' => TeamJoinRequest::STATUS_DECLINED, 'pending_key' => null, 'decided_by' => $actor->getKey(), 'decided_at' => now()]); // بلا إشعار

        if ($affected === 0) {
            throw new TeamException('لم يعد هذا الطلب قائمًا.');
        }

        return $request->refresh();
    }

    protected function assertManager(User $actor, Team $team, TeamJoinRequest $request): void
    {
        $this->members->assertTeamActive($team);

        if ($request->team_id !== $team->getKey()) {
            throw new TeamException('الطلب غير موجود.');
        }

        if (! in_array($this->members->roleIn($actor, $team), [TeamMembership::ROLE_OWNER, TeamMembership::ROLE_ADMIN], true)) {
            throw new TeamException('قبول الطلبات لمالك الفريق أو مشرفيه فقط.');
        }
    }

    protected function afterCommit(\Closure $callback): void
    {
        DB::afterCommit(function () use ($callback) {
            try {
                $callback();
            } catch (\Throwable $e) {
                report($e);
            }
        });
    }
}
