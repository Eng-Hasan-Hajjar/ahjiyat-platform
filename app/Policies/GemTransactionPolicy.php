<?php

namespace App\Policies;

use App\Models\User;

class GemTransactionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('wallet.view');
    }

    public function view(User $user, $model): bool
    {
        return $user->can('wallet.view');
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