<?php

namespace App\Services\Teams;

use App\Events\TeamMemberRemoved;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\TeamJoinRequest;
use App\Models\TeamMembership;
use App\Models\User;
use App\Models\UserBlock;
use App\Services\OperationalAuditService;
use App\Services\Social\BlockService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * العضوية (E19-A/B). فريق واحد لكل مستخدم: UNIQUE(user_id) بالقاعدة، فسباق انضمامين متزامنين لا ينجح إلا أحدهما. السعة: UPDATE شرطي ذري على members_count (لا count ثم
 * insert). المالك لا يغادر ولا يُطرد ولا يُخفَّض إلا بنقل الملكية. الإزالة: المالك يزيل المشرف والعضو، والمشرف يزيل العضو فقط، والعضو لا يزيل أحدًا. العضوية لا تمنح أي
 * XP أو عملة أو أفضلية: لا اعتماد على أي خدمة اقتصاد هنا. الحظر بين المستخدمين لا يُلتفّ عليه بالفريق (انظر blockedWithManagers) ولا يطرد أعضاء حاليين تلقائيًا.
 */
class TeamMembershipService
{
    public function __construct(protected BlockService $blocks, protected OperationalAuditService $audit) {}

    public function membershipOf(User $user): ?TeamMembership
    {
        return TeamMembership::query()->where('user_id', $user->getKey())->first();
    }

    public function roleIn(User $user, Team $team): ?string
    {
        return TeamMembership::query()->where('team_id', $team->getKey())->where('user_id', $user->getKey())->value('role');
    }

    /** مصدر لقطة التسجيل بالأحداث: فريق المستخدم **المفعَّل** فقط (وإلا null). */
    public function teamIdFor(User $user): ?int
    {
        $id = TeamMembership::query()->join('teams as t', 't.id', '=', 'team_memberships.team_id')
            ->where('team_memberships.user_id', $user->getKey())->where('t.is_active', true)->value('team_memberships.team_id');

        return $id === null ? null : (int) $id;
    }

    /** فريق عام مفعَّل للمستخدم (لشارة الملف العام). فريق خاص أو معطَّل: لا شارة. */
    public function publicTeamFor(User $user): ?Team
    {
        return Team::query()->directory()->whereIn('id', TeamMembership::query()->where('user_id', $user->getKey())->select('team_id'))->first(['id', 'name', 'slug']);
    }

    /** سبب منع الانضمام بالعربية (محايد)، أو null. السعة تُفحص ذريًا عند الإدخال لا هنا. */
    public function joinBlocker(User $user, Team $team): ?string
    {
        return match (true) {
            ! $user->hasVerifiedEmail() || $user->is_frozen === true => 'حسابك غير مؤهَّل للانضمام إلى الفرق.',
            ! $team->is_active => 'هذا الفريق غير مفعَّل.',
            $this->membershipOf($user) !== null => 'أنت عضو في فريق بالفعل.',
            $this->blockedWithManagers($user, $team) => 'لا يمكن الانضمام إلى هذا الفريق.',
            default => null,
        };
    }

    /** حظر بأي اتجاه بين المستخدم ومالك/مشرفي الفريق: الفريق ليس وسيلة للالتفاف على الحظر (لا يطرد أعضاء حاليين). */
    public function blockedWithManagers(User $user, Team $team): bool
    {
        $managers = TeamMembership::query()->where('team_id', $team->getKey())->whereIn('role', [TeamMembership::ROLE_OWNER, TeamMembership::ROLE_ADMIN])->pluck('user_id')->all();

        if ($managers === []) {
            return false;
        }

        return UserBlock::query()->where(fn ($q) => $q->whereIn('blocker_id', $managers)->where('blocked_id', $user->getKey()))
            ->orWhere(fn ($q) => $q->where('blocker_id', $user->getKey())->whereIn('blocked_id', $managers))->exists();
    }

    /** انضمام مباشر لفريق مفتوح. */
    public function joinOpen(User $user, Team $team): TeamMembership
    {
        if ($team->join_policy !== Team::JOIN_OPEN) {
            throw new TeamException('هذا الفريق لا يقبل الانضمام المباشر.');
        }

        return $this->addMember($team, $user);
    }

    /**
     * يضيف عضوًا بذرية: السعة بـUPDATE شرطي واحد (فريق مفعَّل، وأقل من السعة)، ثم الإدخال (UNIQUE(user_id): فريق واحد). أي فشل يتراجع معه العدّاد.
     * يُلغي بعد النجاح طلبات/دعوات المستخدم المعلّقة الأخرى (صار بفريق).
     */
    public function addMember(Team $team, User $user, string $role = TeamMembership::ROLE_MEMBER, bool $checkEligibility = true): TeamMembership
    {
        return DB::transaction(function () use ($team, $user, $role, $checkEligibility) {
            if ($checkEligibility && ($reason = $this->joinBlocker($user, $team)) !== null) {
                throw new TeamException($reason);
            }

            $reserved = Team::query()->whereKey($team->getKey())->where('is_active', true)
                ->where(fn ($q) => $q->whereNull('max_members')->orWhereColumn('members_count', '<', 'max_members'))
                ->where('members_count', '<', (int) config('teams.max_members_cap', 50))
                ->increment('members_count');

            if ($reserved === 0) {
                throw new TeamException('اكتمل عدد أعضاء الفريق أو لم يعد متاحًا.');
            }

            try {
                $membership = TeamMembership::create(['team_id' => $team->getKey(), 'user_id' => $user->getKey(), 'role' => $role, 'joined_at' => now()]);
            } catch (UniqueConstraintViolationException) {
                throw new TeamException('أنت عضو في فريق بالفعل.');
            }

            $this->clearPendingFor($user);

            return $membership;
        });
    }

    public function leave(User $user): void
    {
        $membership = $this->membershipOf($user) ?? throw new TeamException('لست عضوًا في أي فريق.');

        if ($membership->role === TeamMembership::ROLE_OWNER) {
            throw new TeamException('المالك لا يغادر: انقل الملكية أو عطّل الفريق أولًا.');
        }

        $this->deleteMembership($membership);
    }

    /** إزالة عضو بيد مالك/مشرف حسب التسلسل. إشعار معلوماتي محايد للمُزال بعد commit. */
    public function remove(User $actor, Team $team, User $target): void
    {
        $this->assertTeamActive($team);
        $actorRole = $this->roleIn($actor, $team);

        if (! in_array($actorRole, [TeamMembership::ROLE_OWNER, TeamMembership::ROLE_ADMIN], true)) {
            throw new TeamException('لا تملك صلاحية إدارة الأعضاء.');
        }

        if ($actor->is($target)) {
            throw new TeamException('للمغادرة استعمل "مغادرة الفريق".');
        }

        $membership = TeamMembership::query()->where('team_id', $team->getKey())->where('user_id', $target->getKey())->first() ?? throw new TeamException('العضو غير موجود بهذا الفريق.');

        $allowed = $membership->role === TeamMembership::ROLE_MEMBER || ($actorRole === TeamMembership::ROLE_OWNER && $membership->role === TeamMembership::ROLE_ADMIN);

        if (! $allowed) {
            throw new TeamException('لا يمكنك إزالة هذا العضو.'); // المالك لا يُزال أبدًا، والمشرف لا يزيل مشرفًا
        }

        $this->deleteMembership($membership);
        $this->afterCommit(fn () => event(new TeamMemberRemoved($team->getKey(), $target->getKey(), $membership->getKey())));
    }

    /** ترقية/تخفيض مشرف: للمالك وحده، بين admin وmember فقط. */
    public function changeRole(User $actor, Team $team, User $target, string $role): TeamMembership
    {
        $this->assertTeamActive($team);

        if ($this->roleIn($actor, $team) !== TeamMembership::ROLE_OWNER) {
            throw new TeamException('تغيير الأدوار للمالك وحده.');
        }

        if (! in_array($role, [TeamMembership::ROLE_ADMIN, TeamMembership::ROLE_MEMBER], true)) {
            throw new TeamException('دور غير صالح.');
        }

        $membership = TeamMembership::query()->where('team_id', $team->getKey())->where('user_id', $target->getKey())->first() ?? throw new TeamException('العضو غير موجود بهذا الفريق.');

        if ($membership->role === TeamMembership::ROLE_OWNER) {
            throw new TeamException('دور المالك لا يتغير إلا بنقل الملكية.');
        }

        $membership->update(['role' => $role]);

        return $membership;
    }

    /** تدخل إدارة المنصة: إزالة عضو (غير المالك) عبر الخدمة لا بحذف خام. مدقَّق. */
    public function adminRemove(User $admin, Team $team, User $target): void
    {
        abort_unless($admin->can('teams.manage'), 403);

        $membership = TeamMembership::query()->where('team_id', $team->getKey())->where('user_id', $target->getKey())->first() ?? throw new TeamException('العضو غير موجود بهذا الفريق.');

        if ($membership->role === TeamMembership::ROLE_OWNER) {
            throw new TeamException('المالك لا يُزال: انقل الملكية أولًا.');
        }

        $this->deleteMembership($membership);
        $this->audit->log('team_admin_member_removed', $team, ['user_id' => $target->getKey()], $admin);
        $this->afterCommit(fn () => event(new TeamMemberRemoved($team->getKey(), $target->getKey(), $membership->getKey())));
    }

    /** يحذف الصف ويُنقص العدّاد بمعاملة واحدة (حارس النموذج يمنع حذف المالك). */
    public function deleteMembership(TeamMembership $membership): void
    {
        DB::transaction(function () use ($membership) {
            $membership->delete();
            Team::query()->whereKey($membership->team_id)->where('members_count', '>', 0)->decrement('members_count');
        });
    }

    /** المستخدم صار بفريق: تُلغى طلباته ودعواته المعلّقة (فلا تبقى حالة معلّقة غير قابلة للقبول). */
    public function clearPendingFor(User $user): void
    {
        TeamJoinRequest::query()->where('user_id', $user->getKey())->where('status', TeamJoinRequest::STATUS_PENDING)
            ->update(['status' => TeamJoinRequest::STATUS_CANCELLED, 'pending_key' => null, 'decided_at' => now()]);
        TeamInvitation::query()->where('invited_user_id', $user->getKey())->where('status', TeamInvitation::STATUS_PENDING)
            ->update(['status' => TeamInvitation::STATUS_CANCELLED, 'pending_key' => null, 'responded_at' => now()]);
    }

    public function assertTeamActive(Team $team): void
    {
        if (! Team::query()->whereKey($team->getKey())->where('is_active', true)->exists()) {
            throw new TeamException('هذا الفريق غير مفعَّل.');
        }
    }

    protected function afterCommit(\Closure $callback): void
    {
        DB::afterCommit(function () use ($callback) {
            try {
                $callback();
            } catch (\Throwable $e) {
                report($e); // الإشعارات ثانوية: فشلها لا يمسّ العضوية
            }
        });
    }
}
