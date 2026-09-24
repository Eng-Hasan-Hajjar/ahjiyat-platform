<?php

namespace App\Policies;

use App\Models\User;

class CurrencyTransactionPolicy extends BasePermissionPolicy
{
    protected string $prefix = 'economy.transactions';

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