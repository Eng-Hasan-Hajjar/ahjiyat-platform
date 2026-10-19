<?php

namespace App\Policies;

use App\Models\User;

/** E33 - حملات الدعوة: العرض referrals.view، الإنشاء/التعديل referrals.manage، بلا حذف (الإنهاء بالخدمة يحفظ التاريخ). */
class ReferralCampaignPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('referrals.view');
    }

    public function view(User $user, $model): bool
    {
        return $user->can('referrals.view');
    }

    public function create(User $user): bool
    {
        return $user->can('referrals.manage');
    }

    public function update(User $user, $model): bool
    {
        return $user->can('referrals.manage');
    }

    public function delete(User $user, $model): bool
    {
        return false;
    }
}
