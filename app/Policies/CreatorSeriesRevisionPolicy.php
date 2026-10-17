<?php

namespace App\Policies;

use App\Models\User;

/**
 * E30 - سلاسل المبدعين المرسلة للمراجعة (نسخ السلاسل): العرض بـcreators.view والإجراءات (اعتماد/طلب تعديل/رفض/إخفاء/استعادة) بصلاحيتها داخل الخدمات المدقَّقة (creators.review). بالصلاحيات لا بأسماء الأدوار.
 * لا إنشاء ولا تعديل خام ولا حذف من لوحة الإدارة: التاريخ وسجل التدقيق لا يُمحيان، وكل تغيير حالة يمرّ بالخدمة (تدقيق + إشعار + إعلان المتابعين).
 */
class CreatorSeriesRevisionPolicy
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
