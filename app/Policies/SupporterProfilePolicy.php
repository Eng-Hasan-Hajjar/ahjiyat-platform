<?php

namespace App\Policies;

use App\Models\User;

/**
 * E30 - ملفات الداعمين: العرض بـsponsorships.view، الإنشاء والتعديل بـsponsorships.manage (بالصلاحيات لا بأسماء الأدوار). لا حذف من لوحة الإدارة (التاريخ المالي مرتبط بها):
 * الإيقاف/الإخفاء بتغيير الحالة (مدقَّق عبر الخدمة).
 */
class SupporterProfilePolicy
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
