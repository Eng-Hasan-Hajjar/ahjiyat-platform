<?php

namespace App\Policies;

use App\Models\User;

class CurrencyPackPolicy extends BasePermissionPolicy
{
    protected string $prefix = 'economy.packs';

    public function viewAny(User $user): bool
    {
        return $user->can('economy.packs.view');
    }

    public function create(User $user): bool
    {
        return $user->can('economy.packs.manage');
    }

    public function update(User $user, $model): bool
    {
        return $user->can('economy.packs.manage');
    }

    public function delete(User $user, $model): bool
    {
        return $user->can('economy.packs.manage');
    }
}