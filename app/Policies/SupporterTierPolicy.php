<?php

namespace App\Policies;

use App\Models\User;

/** E30 - مستويات الداعمين: العرض sponsorships.view، الإنشاء/التعديل sponsorships.manage. لا حذف (تعطيل المستوى بدل الحذف). */
class SupporterTierPolicy
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
