<?php

namespace App\Policies;

use App\Models\User;

/** فحص إدارة المنصة لتحدّيات الفرق (E20-E13): **عرض فقط**. لا تعديل ولا حذف ولا تغيير فائز يدويًا: النتيجة يحسبها الخادم وحده. صلاحيات اللاعبين تُفحص بالخدمات من أدوارهم الفعلية. */
class TeamChallengePolicy extends BasePermissionPolicy
{
    protected string $prefix = 'team_challenges';

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
