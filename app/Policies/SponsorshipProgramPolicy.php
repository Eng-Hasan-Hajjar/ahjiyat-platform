<?php

namespace App\Policies;

use App\Models\User;

/** E30 - برامج الرعاية: العرض sponsorships.view، الإنشاء/التعديل sponsorships.manage، بلا حذف (الإغلاق/الإلغاء بالخدمة). خطة المكافآت بصلاحية reward_plan داخل الخدمة. */
class SponsorshipProgramPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('sponsorships.view');
    }

    public function view(User $user, $model): bool
    {
        return $user->can('sponsorships.view');
    }

    public function create(User $user): bool
    {
        return $user->can('sponsorships.manage');
    }

    public function update(User $user, $model): bool
    {
        return $user->can('sponsorships.manage');
    }

    public function delete(User $user, $model): bool
    {
        return false;
    }
}
