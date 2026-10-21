<?php

namespace App\Policies;

use App\Models\User;

/**
 * E34 - مجموعات المهام: العرض liveops.view، الإنشاء/التعديل liveops.manage. بلا حذف عبر الواجهة
 * (الحذف الوحيد المسموح هو بالخدمة لمجموعة بلا سجل إسناد؛ التعطيل هو المسار الاعتيادي ويحفظ التاريخ).
 */
class MissionPoolPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('liveops.view');
    }

    public function view(User $user, $model): bool
    {
        return $user->can('liveops.view');
    }

    public function create(User $user): bool
    {
        return $user->can('liveops.manage');
    }

    public function update(User $user, $model): bool
    {
        return $user->can('liveops.manage');
    }

    public function delete(User $user, $model): bool
    {
        return false;
    }
}
