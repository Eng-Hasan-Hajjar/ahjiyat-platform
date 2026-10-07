<?php

namespace App\Policies;

use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;

/**
 * صلاحيات الفرق (E19). وجهان منفصلان: (1) إدارة المنصة بـFilament عبر teams.* (عرض فقط: لا إنشاء/تعديل/حذف خام يكسر الثوابت)، (2) صلاحيات أعضاء الفريق بحسب دوره
 * الفعلي بقاعدة البيانات (لا من الطلب أبدًا): viewRoster/viewManagement/manageMembers/manageSettings.
 */
class TeamPolicy extends BasePermissionPolicy
{
    protected string $prefix = 'teams';

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, $model): bool
    {
        return false;
    }

    public function delete(User $user, $model): bool
    {
        return false;
    }

    /** تعطيل/إعادة تفعيل بيد إدارة المنصة. */
    public function deactivate(User $user, $model): bool
    {
        return $user->can("{$this->prefix}.deactivate");
    }

    /** تدخل إداري: نقل ملكية/إزالة عضو. */
    public function adminManage(User $user, $model): bool
    {
        return $user->can("{$this->prefix}.manage");
    }

    /** الفريق العام: كل الزوار. الخاص: أعضاؤه فقط. */
    public function viewRoster(?User $user, Team $team): bool
    {
        return $team->isPublic() || ($user !== null && $this->roleOf($user, $team) !== null);
    }

    /** صفحة الإدارة: مالك/مشرف (حتى لفريق معطَّل: للقراءة). */
    public function viewManagement(User $user, Team $team): bool
    {
        return in_array($this->roleOf($user, $team), [TeamMembership::ROLE_OWNER, TeamMembership::ROLE_ADMIN], true);
    }

    /** إدارة الأعضاء/الدعوات/الطلبات: مالك/مشرف بفريق مفعَّل. */
    public function manageMembers(User $user, Team $team): bool
    {
        return $team->is_active && $this->viewManagement($user, $team);
    }

    /** تحدّيات الفرق (إنشاء/قبول/رفض/إلغاء/روستر): مالك أو مشرف بفريق مفعَّل. */
    public function manageChallenges(User $user, Team $team): bool
    {
        return $team->is_active && $this->viewManagement($user, $team);
    }

    /** الإعدادات الحساسة: المالك وحده بفريق مفعَّل. */
    public function manageSettings(User $user, Team $team): bool
    {
        return $team->is_active && $this->roleOf($user, $team) === TeamMembership::ROLE_OWNER;
    }

    protected function roleOf(User $user, Team $team): ?string
    {
        return TeamMembership::query()->where('team_id', $team->getKey())->where('user_id', $user->getKey())->value('role');
    }
}
