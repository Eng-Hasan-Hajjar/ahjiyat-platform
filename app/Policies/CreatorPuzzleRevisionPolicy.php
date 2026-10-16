<?php

namespace App\Policies;

use App\Models\User;

/**
 * E28 - الأحجيات المرسلة للمراجعة (نسخ المبدعين): العرض بـcreators.view والإجراءات (اعتماد/رفض/إخفاء/تعليق) بصلاحياتها داخل الخدمات المدقَّقة (creators.review / creators.manage). بالصلاحيات لا بأسماء الأدوار.
 * لا إنشاء ولا تعديل خام ولا حذف من لوحة الإدارة: التاريخ وسجل التدقيق لا يُمحيان، وكل تغيير حالة يمرّ بالخدمة (تدقيق + إشعار + مصالحة ظهور).
 */
class CreatorPuzzleRevisionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('creators.view');
    }

    public function view(User $user, $model): bool
    {
        return $user->can('creators.view');
    }

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
}
