<?php

namespace App\Policies;

use App\Models\User;

/**
 * E35 - الأرفف التحريرية: العرض editorial.view، الإنشاء/التعديل editorial.manage (وكل كتابة تمرّ بـEditorialShelfService الذي يعيد فحص الصلاحية ويدقّق).
 * لا حذف: الأرشفة هي المسار (تحفظ التاريخ والتدقيق).
 */
class EditorialShelfPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('editorial.view');
    }

    public function view(User $user, $model): bool
    {
        return $user->can('editorial.view');
    }

    public function create(User $user): bool
    {
        return $user->can('editorial.manage');
    }

    public function update(User $user, $model): bool
    {
        return $user->can('editorial.manage');
    }

    public function delete(User $user, $model): bool
    {
        return false;
    }
}
