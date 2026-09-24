<?php

namespace App\Policies;

use App\Models\Currency;
use App\Models\User;

class CurrencyPolicy extends BasePermissionPolicy
{
    protected string $prefix = 'economy.currencies';

    public function update(User $user, $model): bool
    {
        return $user->can('economy.currencies.update');
    }

    public function delete(User $user, $model): bool
    {
        return $user->can('economy.currencies.deactivate');
    }
}