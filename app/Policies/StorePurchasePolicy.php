<?php

namespace App\Policies;

use App\Models\User;

class StorePurchasePolicy extends BasePermissionPolicy
{
    protected string $prefix = 'store.purchases';

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

    public function fulfill(User $user, $model): bool
    {
        return $user->can('store.purchases.fulfill');
    }

    public function refund(User $user, $model): bool
    {
        return $user->can('store.purchases.refund');
    }
}