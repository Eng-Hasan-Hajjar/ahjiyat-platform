<?php

namespace App\Policies;

use App\Models\User;

/**
 * E35 - إشراف المجموعات المشتركة بلوحة الإدارة: العرض collections.view والإجراءات (إخفاء/استعادة) بصلاحيتها داخل CollectionModerationService (collections.moderate).
 * بالصلاحيات لا بأسماء الأدوار. لا إنشاء ولا تعديل خام لنص المجموعة ولا حذف من اللوحة: المشرف لا يعيد كتابة عمل المستخدم، والإخفاء علَم مستقل مدقَّق.
 * المجموعات الخاصة لا تظهر باللوحة أصلًا (استعلام المورد يحصر الظهور غير الخاص).
 */
class UserCollectionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('collections.view');
    }

    public function view(User $user, $model): bool
    {
        return $user->can('collections.view');
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
