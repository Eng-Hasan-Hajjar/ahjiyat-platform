<?php

namespace App\Services\Teams;

use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\TeamJoinRequest;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\OperationalAuditService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * دورة حياة الفريق (E19-A/C). المنشئ يصير المالك. الإعدادات الحساسة (الاسم، الخصوصية، سياسة الانضمام، السعة، التعطيل) للمالك وحده. التعطيل أرشفة لا حذف
 * (التاريخ التنافسي محفوظ)، وإعادة التفعيل لإدارة المنصة. نقل الملكية: معاملة ذرية بقفل صف الفريق، المالك القديم يصير admin، وتكرار النقل من المالك القديم مرفوض.
 * حذف حساب مالك: تُنقل الملكية لأقدم مشرف ثم أقدم عضو، وإلا يُؤرشف الفريق: لا فريق يتيم. مالك مجمَّد: لا يُفكَّك الفريق تلقائيًا (إدارة المنصة تتدخل).
 */
class TeamService
{
    public function __construct(protected TeamMembershipService $members, protected OperationalAuditService $audit) {}

    /** @param  array{name?: string, description?: ?string, visibility?: string, join_policy?: string, max_members?: ?int}  $data */
    public function create(User $creator, array $data): Team
    {
        if (! $creator->hasVerifiedEmail() || $creator->is_frozen === true) {
            throw new TeamException('حسابك غير مؤهَّل لإنشاء فريق.');
        }

        if ($this->members->membershipOf($creator) !== null) {
            throw new TeamException('أنت عضو في فريق بالفعل: غادره قبل إنشاء فريق جديد.');
        }

        $name = TeamNaming::normalize((string) ($data['name'] ?? ''));

        if (($error = TeamNaming::error($name)) !== null) {
            throw new TeamException($error);
        }

        $attrs = $this->settingsFrom($data, new Team(['visibility' => Team::VISIBILITY_PUBLIC, 'join_policy' => Team::JOIN_REQUEST]), 0);

        try {
            return DB::transaction(function () use ($creator, $name, $attrs) {
                $team = Team::create($attrs + [
                    'name' => $name, 'name_key' => TeamNaming::key($name), 'slug' => TeamNaming::uniqueSlug($name),
                    'owner_id' => $creator->getKey(), 'is_active' => true,
                ]);

                $this->members->addMember($team, $creator, TeamMembership::ROLE_OWNER, checkEligibility: false);

                return $team->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            throw new TeamException('الاسم أو المعرّف مستخدم، أو أنت عضو بفريق بالفعل.');
        }
    }

    /** إعدادات الفريق: للمالك وحده، والفريق مفعَّل. المعرّف (slug) لا يتغير. */
    public function updateSettings(User $actor, Team $team, array $data): Team
    {
        $this->assertOwner($actor, $team);
        $this->members->assertTeamActive($team);

        $team = Team::query()->findOrFail($team->getKey()); // عدّاد الأعضاء طازج من القاعدة (النسخة الممرَّرة قد تكون قديمة)
        $attrs = $this->settingsFrom($data, $team, $team->members_count);

        if (isset($data['name'])) {
            $name = TeamNaming::normalize((string) $data['name']);

            if (($error = TeamNaming::error($name, $team)) !== null) {
                throw new TeamException($error);
            }

            $attrs += ['name' => $name, 'name_key' => TeamNaming::key($name)];
        }

        try {
            $team->update($attrs);
        } catch (UniqueConstraintViolationException) {
            throw new TeamException('هذا الاسم مستخدم لفريق آخر.');
        }

        return $team->refresh();
    }

    /** تعطيل (أرشفة) بيد المالك: لا أعضاء جدد، وتُلغى الدعوات/الطلبات المعلّقة، والتاريخ باقٍ. */
    public function deactivate(User $actor, Team $team): Team
    {
        $this->assertOwner($actor, $team);

        $this->setActive($team, false);
        $this->audit->log('team_deactivated', $team, ['by' => 'owner'], $actor);

        return $team->refresh();
    }

    /** تعطيل/إعادة تفعيل بيد إدارة المنصة (صلاحية teams.deactivate). */
    public function adminSetActive(User $admin, Team $team, bool $active): Team
    {
        abort_unless($admin->can('teams.deactivate'), 403);

        $this->setActive($team, $active);
        $this->audit->log($active ? 'team_admin_reactivated' : 'team_admin_deactivated', $team, ['members' => $team->members_count], $admin);

        return $team->refresh();
    }

    /** نقل الملكية بيد المالك الحالي إلى عضو حالي. ذري، والمالك القديم يصير admin. */
    public function transferOwnership(User $actor, Team $team, User $newOwner): Team
    {
        return $this->swapOwner($team, $newOwner, actor: $actor, admin: null);
    }

    /** تدخل إدارة المنصة (مثلًا مالك مجمَّد): نقل لعضو حالي بصلاحية teams.manage. مدقَّق. */
    public function adminTransferOwnership(User $admin, Team $team, User $newOwner): Team
    {
        abort_unless($admin->can('teams.manage'), 403);

        return $this->swapOwner($team, $newOwner, actor: null, admin: $admin);
    }

    /** قبل حذف حساب: كل فريق يملكه يُنقل لأقدم مشرف ثم أقدم عضو، وإلا يُؤرشَف. لا فريق بلا مالك. */
    public function releaseOwnershipFor(User $user): void
    {
        Team::query()->where('owner_id', $user->getKey())->get()->each(function (Team $team) use ($user) {
            DB::transaction(function () use ($team, $user) {
                $locked = Team::query()->lockForUpdate()->find($team->getKey());

                if ($locked === null || $locked->owner_id !== $user->getKey()) {
                    return;
                }

                $candidate = TeamMembership::query()->where('team_id', $locked->getKey())->where('user_id', '!=', $user->getKey())
                    ->orderByRaw("case role when 'admin' then 0 else 1 end")->orderBy('joined_at')->orderBy('id')->first();

                if ($candidate === null) {
                    $locked->forceFill(['is_active' => false, 'owner_id' => null])->save();
                    $this->audit->log('team_auto_archived', $locked, ['reason' => 'owner_account_deleted']);

                    return;
                }

                $old = TeamMembership::query()->where('team_id', $locked->getKey())->where('user_id', $user->getKey())->first();
                $this->performSwap($locked, $old, $candidate);
                $this->audit->log('team_ownership_auto_transferred', $locked, ['to_user_id' => $candidate->user_id, 'reason' => 'owner_account_deleted']);
            });
        });
    }

    protected function swapOwner(Team $team, User $newOwner, ?User $actor, ?User $admin): Team
    {
        return DB::transaction(function () use ($team, $newOwner, $actor, $admin) {
            $locked = Team::query()->lockForUpdate()->findOrFail($team->getKey());

            if ($actor !== null && $locked->owner_id !== $actor->getKey()) {
                throw new TeamException('نقل الملكية للمالك وحده.');
            }

            if ($locked->owner_id === $newOwner->getKey()) {
                throw new TeamException('هذا العضو هو المالك بالفعل.');
            }

            $new = TeamMembership::query()->where('team_id', $locked->getKey())->where('user_id', $newOwner->getKey())->lockForUpdate()->first()
                ?? throw new TeamException('يجب أن يكون المستلم عضوًا حاليًا بالفريق.');
            $old = TeamMembership::query()->where('team_id', $locked->getKey())->where('user_id', $locked->owner_id)->lockForUpdate()->firstOrFail();

            $this->performSwap($locked, $old, $new);
            $this->audit->log('team_ownership_transferred', $locked, ['from_user_id' => $old->user_id, 'to_user_id' => $new->user_id], $admin ?? $actor);

            return $locked->refresh();
        });
    }

    /** المالك القديم ← admin، الجديد ← owner، owner_id يتبع. مالك واحد بكل لحظة (الحارس مرفوع ضمن النقل فقط). */
    protected function performSwap(Team $locked, TeamMembership $old, TeamMembership $new): void
    {
        TeamMembership::allowingOwnerChange(function () use ($locked, $old, $new) {
            $old->update(['role' => TeamMembership::ROLE_ADMIN]);
            $new->update(['role' => TeamMembership::ROLE_OWNER]);
            $locked->forceFill(['owner_id' => $new->user_id])->save();
        });
    }

    protected function setActive(Team $team, bool $active): void
    {
        DB::transaction(function () use ($team, $active) {
            $team->forceFill(['is_active' => $active])->save();

            if (! $active) {
                TeamInvitation::query()->where('team_id', $team->getKey())->where('status', TeamInvitation::STATUS_PENDING)
                    ->update(['status' => TeamInvitation::STATUS_CANCELLED, 'pending_key' => null, 'responded_at' => now()]);
                TeamJoinRequest::query()->where('team_id', $team->getKey())->where('status', TeamJoinRequest::STATUS_PENDING)
                    ->update(['status' => TeamJoinRequest::STATUS_CANCELLED, 'pending_key' => null, 'decided_at' => now()]);
            }
        });
    }

    protected function assertOwner(User $actor, Team $team): void
    {
        if ($this->members->roleIn($actor, $team) !== TeamMembership::ROLE_OWNER) {
            throw new TeamException('هذا الإجراء للمالك وحده.');
        }
    }

    /**
     * يستخرج إعدادات صالحة فقط: وصف مُنقّى، خصوصية وسياسة من قيم مغلقة، وسعة بين 2 والسقف وليست أقل من الأعضاء الحاليين.
     * لا تُقرأ owner_id ولا role ولا أي حقل آخر من المدخل (client trust).
     */
    protected function settingsFrom(array $data, Team $current, int $currentMembers): array
    {
        $out = [];

        if (array_key_exists('description', $data)) {
            $out['description'] = TeamNaming::cleanDescription($data['description']);
        }

        if (array_key_exists('visibility', $data)) {
            $out['visibility'] = in_array($data['visibility'], [Team::VISIBILITY_PUBLIC, Team::VISIBILITY_PRIVATE], true) ? $data['visibility'] : throw new TeamException('خصوصية غير صالحة.');
        }

        if (array_key_exists('join_policy', $data)) {
            $out['join_policy'] = in_array($data['join_policy'], [Team::JOIN_OPEN, Team::JOIN_REQUEST, Team::JOIN_INVITE_ONLY], true) ? $data['join_policy'] : throw new TeamException('سياسة انضمام غير صالحة.');
        }

        if (array_key_exists('max_members', $data)) {
            $max = $data['max_members'] === null || $data['max_members'] === '' ? null : (int) $data['max_members'];
            $cap = (int) config('teams.max_members_cap', 50);

            if ($max !== null && ($max < 2 || $max > $cap || $max < $currentMembers)) {
                throw new TeamException("السعة بين 2 و{$cap} ولا تقل عن عدد الأعضاء الحاليين.");
            }

            $out['max_members'] = $max;
        }

        return $out;
    }
}
