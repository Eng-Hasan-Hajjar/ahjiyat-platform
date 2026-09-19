<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use Spatie\Permission\Models\Permission;

/**
 * مصدر الحقيقة الوحيد لكل قواعد أمان RBAC الحسّاسة - لا تُكرَّر هذه
 * القواعد داخل أي Resource/Controller. مسؤولة حصراً عن:
 * - حماية آخر Super Admin (لا حذف، لا سحب دور، لا سحب صلاحية admin.access).
 * - منع تصعيد الصلاحيات (Admin عادي لا يمنح صلاحية/دوراً لا يملكه هو، ولا
 *   يمنح/يعدّل super-admin إطلاقاً).
 * - حماية الأدوار النظامية (super-admin, player) من الحذف.
 */
class AuthorizationSafetyService
{
    public const SUPER_ADMIN_ROLE = 'super-admin';

    public function isSuperAdmin(User $user): bool
    {
        return $user->hasRole(self::SUPER_ADMIN_ROLE);
    }

    public function superAdminCount(): int
    {
        return User::role(self::SUPER_ADMIN_ROLE)->count();
    }

    public function isLastSuperAdmin(User $user): bool
    {
        return $this->isSuperAdmin($user) && $this->superAdminCount() <= 1;
    }

    /** يمنع حذف حساب المستخدم إن كان آخر Super Admin بالنظام. */
    public function assertCanDeleteUser(User $target): void
    {
        if ($this->isLastSuperAdmin($target)) {
            abort(403, 'لا يمكن حذف هذا الحساب لأنه آخر مدير أعلى بالمنصة. عيّن مديراً أعلى آخر أولاً.');
        }
    }

    /**
     * يُستدعى قبل حفظ قائمة أدوار جديدة لمستخدم (Sync). يمنع سحب دور
     * super-admin عن آخر Super Admin، ويمنع منح super-admin لمن ليس Super
     * Admin هو نفسه أصلاً (بند 35: لا يمنح صلاحية أعلى منه).
     */
    public function assertCanSyncRoles(User $actor, User $target, array $newRoleNames): void
    {
        $hadSuperAdmin = $target->hasRole(self::SUPER_ADMIN_ROLE);
        $willHaveSuperAdmin = in_array(self::SUPER_ADMIN_ROLE, $newRoleNames, true);

        if ($hadSuperAdmin && ! $willHaveSuperAdmin && $this->isLastSuperAdmin($target)) {
            abort(403, 'لا يمكن سحب دور "المدير الأعلى" من هذا الحساب لأنه آخر مدير أعلى بالمنصة.');
        }

        if ($willHaveSuperAdmin && ! $hadSuperAdmin && ! $this->isSuperAdmin($actor)) {
            abort(403, 'فقط مدير أعلى يستطيع منح دور "المدير الأعلى" لمستخدم آخر.');
        }

        if (! $this->isSuperAdmin($actor)) {
            foreach (Role::whereIn('name', $newRoleNames)->get() as $role) {
                foreach ($role->permissions as $permission) {
                    if (! $actor->can($permission->name)) {
                        abort(403, "لا يمكنك منح دور \"{$role->displayLabel()}\" لأنه يحتوي صلاحية (\"{$permission->name}\") لا تملكها أنت نفسك.");
                    }
                }
            }
        }
    }

    /** يمنع تعديل صلاحيات مستخدم Super Admin من طرف Admin عادي إطلاقاً. */
    public function assertCanModifyUserAuthorization(User $actor, User $target): void
    {
        if ($this->isSuperAdmin($target) && ! $this->isSuperAdmin($actor)) {
            abort(403, 'لا يمكن تعديل صلاحيات حساب مدير أعلى إلا من قِبل مدير أعلى آخر.');
        }
    }

    /** يمنع حذف دور "super-admin" أو "player" النظاميين إطلاقاً. */
    public function assertCanDeleteRole(Role $role): void
    {
        if ($role->is_system) {
            abort(403, "لا يمكن حذف الدور \"{$role->displayLabel()}\" لأنه دور نظامي أساسي.");
        }

        $usersCount = $role->users()->count();
        if ($usersCount > 0) {
            abort(403, "لا يمكن حذف هذا الدور لأنه مرتبط بـ{$usersCount} مستخدماً - أزل الدور عن هؤلاء المستخدمين أولاً.");
        }
    }

    /** منع منح/تعديل صلاحيات تتجاوز ما يملكه الـActor نفسه (لدوره الجديد المُعدَّل). */
    public function assertCanSyncRolePermissions(User $actor, Role $role, array $newPermissionNames): void
    {
        if ($this->isSuperAdmin($actor)) {
            return;
        }

        if ($role->isSuperAdmin()) {
            abort(403, 'لا يمكن تعديل صلاحيات دور "المدير الأعلى".');
        }

        $missing = collect($newPermissionNames)->first(fn ($name) => ! $actor->can($name));
        if ($missing) {
            $label = Permission::where('name', $missing)->first()?->name ?? $missing;
            abort(403, "لا يمكنك منح صلاحية (\"{$label}\") لا تملكها أنت نفسك.");
        }
    }
}