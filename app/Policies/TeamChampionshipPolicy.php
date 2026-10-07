<?php

namespace App\Policies;

use App\Models\TeamChampionship;
use App\Models\User;

/** بطولات الفرق (E20-E14..E17): العرض بـview، الإنشاء/التعديل (وللمسودة وحدها) بـmanage، ودورة الحياة (نشر/إلغاء/اعتماد) بـpublish. الحذف للمسودة فقط. */
class TeamChampionshipPolicy extends BasePermissionPolicy
{
    protected string $prefix = 'team_championships';

    public function create(User $user): bool
    {
        return $user->can("{$this->prefix}.manage");
    }

    public function update(User $user, $model): bool
    {
        return $user->can("{$this->prefix}.manage");
    }

    public function delete(User $user, $model): bool
    {
        return $model instanceof TeamChampionship && $model->isDraft() && $user->can("{$this->prefix}.manage");
    }

    public function publish(User $user, $model): bool
    {
        return $user->can("{$this->prefix}.publish");
    }
}
