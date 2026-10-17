<?php

namespace App\Policies;

use App\Models\User;

/**
 * E30 - المساهمات (مالية): العرض sponsorships.view؛ التسجيل sponsorships.manage؛ التأكيد/الإلغاء/الاسترداد sponsorships.confirm فقط (فصل مالي) عبر ContributionService.
 * لا تعديل خام ولا حذف من لوحة الإدارة: التصحيح بالاسترداد.
 */
class SponsorshipContributionPolicy
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
        return false;
    }

    public function delete(User $user, $model): bool
    {
        return false;
    }
}
