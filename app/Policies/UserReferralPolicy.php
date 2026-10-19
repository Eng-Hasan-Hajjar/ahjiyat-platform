<?php

namespace App\Policies;

use App\Models\User;

/** E33 - الإحالات: عرض فقط referrals.view. لا إنشاء ولا تعديل ولا حذف من الواجهة؛ الاعتماد/الاستبعاد إجراءات خدمة بصلاحية referrals.review. */
class UserReferralPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('referrals.view');
    }

    public function view(User $user, $model = null): bool
    {
        return $user->can('referrals.view');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, $model = null): bool
    {
        return false;
    }

    public function delete(User $user, $model = null): bool
    {
        return false;
    }
}
