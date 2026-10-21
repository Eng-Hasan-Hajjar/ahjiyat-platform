<?php

namespace App\Policies;

use App\Models\User;

/**
 * E35 - بلاغات المجموعات: العرض collections.view والحسم/الإخفاء بـcollections.moderate داخل CollectionModerationService (تدقيق + ذرّية). لا إنشاء/تعديل/حذف من اللوحة.
 */
class UserCollectionReportPolicy
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
