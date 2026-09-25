<?php

namespace App\Policies;

use App\Models\User;

class StoreItemPolicy extends BasePermissionPolicy
{
    protected string $prefix = 'store.items';

    public function update(User $user, $model): bool
    {
        return $user->can('store.items.update');
    }

    public function delete(User $user, $model): bool
    {
        return $user->can('store.items.deactivate');
    }
}