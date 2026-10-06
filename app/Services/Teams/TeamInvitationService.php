<?php

namespace App\Services\Teams;

use App\Events\TeamInvitationAccepted;
use App\Events\TeamInvitationCreated;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Social\BlockService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * دعوات الفريق (E19-B). الإرسال لمالك/مشرف فقط. لا دعوة: للنفس، لعضو بفريق (فريق واحد)، لمحظور بأي اتجاه بينه وبين الداعي، لحساب مجمَّد/غير موثَّق، من فريق معطَّل.
 * دعوة معلّقة واحدة لكل (فريق، مستخدم) بقيد UNIQUE. تنتهي بعد invitation_ttl_days. القبول للمدعو وحده، ذري وIdempotent، ويعيد فحص السعة والأهلية والفريق
 * الحالي لحظتها. الرفض/الإلغاء/الانتهاء بلا إشعار؛ وcooldown بسيط يمنع حلقة دعوة/إلغاء/إعادة سريعة. أي رفض لا يكشف حظرًا أو حالة حساب.
 */
class TeamInvitationService
{
    public function __construct(protected TeamMembershipService $members, protected BlockService $blocks) {}

    public function invite(User $actor, Team $team, User $target): TeamInvitation
    {
        $this->members->assertTeamActive($team);

        if (! in_array($this->members->roleIn($actor, $team), [TeamMembership::ROLE_OWNER, TeamMembership::ROLE_ADMIN], true)) {
            throw new TeamException('الدعوة لمالك الفريق أو مشرفيه فقط.');
        }

        if ($actor->is($target)) {
            throw new TeamException('لا يمكنك دعوة نفسك.');
        }

        if (! $target->hasVerifiedEmail() || $target->is_frozen === true || $this->members->membershipOf($target) !== null
            || $this->blocks->blockedEitherWay($actor, $target)) {
            throw new TeamException('لا يمكن دعوة هذا المستخدم.'); // رسالة واحدة: لا نكشف حظرًا ولا فريقًا ولا حالة حساب
        }

        if (Cache::has($this->cooldownKey($team->getKey(), $target->getKey()))) {
            throw new TeamException('حاول دعوة هذا المستخدم لاحقًا.');
        }

        $this->expireStale($team->getKey(), $target->getKey()); // دعوة منتهية بالوقت تحرّر مفتاحها

        try {
            return DB::transaction(function () use ($actor, $team, $target) {
                $invitation = TeamInvitation::create([
                    'team_id' => $team->getKey(), 'invited_user_id' => $target->getKey(), 'invited_by' => $actor->getKey(),
                    'status' => TeamInvitation::STATUS_PENDING, 'pending_key' => TeamInvitation::pendingKey($team->getKey(), $target->getKey()),
                    'expires_at' => now()->addDays(max(1, (int) config('teams.invitation_ttl_days', 7))),
                ]);

                $this->afterCommit(fn () => event(new TeamInvitationCreated($invitation->getKey())));

                return $invitation;
            });
        } catch (UniqueConstraintViolationException) {
            throw new TeamException('توجد دعوة معلّقة لهذا المستخدم بالفعل.');
        }
    }

    public function cancel(User $actor, Team $team, TeamInvitation $invitation): TeamInvitation
    {
        if ($invitation->team_id !== $team->getKey()) {
            throw new TeamException('الدعوة غير موجودة.');
        }

        if (! in_array($this->members->roleIn($actor, $team), [TeamMembership::ROLE_OWNER, TeamMembership::ROLE_ADMIN], true)) {
            throw new TeamException('إلغاء الدعوة لمالك الفريق أو مشرفيه فقط.');
        }

        $affected = TeamInvitation::query()->whereKey($invitation->getKey())->where('status', TeamInvitation::STATUS_PENDING)
            ->update(['status' => TeamInvitation::STATUS_CANCELLED, 'pending_key' => null, 'responded_at' => now()]);

        if ($affected === 0) {
            throw new TeamException('لا يمكن إلغاء هذه الدعوة.');
        }

        $this->startCooldown($invitation->team_id, $invitation->invited_user_id);

        return $invitation->refresh();
    }

    /** القبول للمدعو وحده؛ ذري وIdempotent؛ يعيد فحص كل شيء لحظته. إعادة القبول بعد نجاحه تعيد العضوية نفسها بلا حدث ثانٍ. */
    public function accept(User $actor, TeamInvitation $invitation): TeamMembership
    {
        if ($invitation->invited_user_id !== $actor->getKey()) {
            throw new TeamException('هذه الدعوة ليست لك.');
        }

        if ($invitation->status === TeamInvitation::STATUS_ACCEPTED) {
            return $this->members->membershipOf($actor) ?? throw new TeamException('لم تعد هذه الدعوة صالحة.');
        }

        if ($invitation->isExpired()) {
            $this->expireStale($invitation->team_id, $invitation->invited_user_id);

            throw new TeamException('انتهت صلاحية هذه الدعوة.');
        }

        return DB::transaction(function () use ($actor, $invitation) {
            $claimed = TeamInvitation::query()->whereKey($invitation->getKey())->where('status', TeamInvitation::STATUS_PENDING)
                ->where('invited_user_id', $actor->getKey())->where('expires_at', '>', now())
                ->update(['status' => TeamInvitation::STATUS_ACCEPTED, 'pending_key' => null, 'responded_at' => now()]);

            if ($claimed !== 1) {
                throw new TeamException('لم تعد هذه الدعوة صالحة.');
            }

            // السعة، الفريق مفعَّل، الأهلية، عدم وجود فريق حالي: تُعاد لحظة القبول (الفشل يتراجع معه تحديث الدعوة).
            $membership = $this->members->addMember($invitation->team()->firstOrFail(), $actor);

            $this->afterCommit(fn () => event(new TeamInvitationAccepted($invitation->getKey())));

            return $membership;
        });
    }

    public function decline(User $actor, TeamInvitation $invitation): TeamInvitation
    {
        if ($invitation->invited_user_id !== $actor->getKey()) {
            throw new TeamException('هذه الدعوة ليست لك.');
        }

        $affected = TeamInvitation::query()->whereKey($invitation->getKey())->where('status', TeamInvitation::STATUS_PENDING)
            ->update(['status' => TeamInvitation::STATUS_DECLINED, 'pending_key' => null, 'responded_at' => now()]); // بلا إشعار

        if ($affected === 0) {
            throw new TeamException('لا يمكن رفض هذه الدعوة.');
        }

        $this->startCooldown($invitation->team_id, $invitation->invited_user_id);

        return $invitation->refresh();
    }

    /** يُجسِّد انتهاء الدعوات المنتهية بالوقت ويحرّر مفاتيحها. Idempotent. @return int */
    public function expireStale(?int $teamId = null, ?int $userId = null): int
    {
        return TeamInvitation::query()->where('status', TeamInvitation::STATUS_PENDING)->where('expires_at', '<=', now())
            ->when($teamId, fn ($q) => $q->where('team_id', $teamId))->when($userId, fn ($q) => $q->where('invited_user_id', $userId))
            ->update(['status' => TeamInvitation::STATUS_EXPIRED, 'pending_key' => null, 'responded_at' => now()]);
    }

    protected function cooldownKey(int $teamId, int $userId): string
    {
        return "teams:invite-cooldown:{$teamId}:{$userId}";
    }

    protected function startCooldown(int $teamId, int $userId): void
    {
        Cache::put($this->cooldownKey($teamId, $userId), true, now()->addMinutes((int) config('teams.invite_cooldown_minutes', 10)));
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
