<?php

namespace App\Policies;

use App\Models\FraudFlag;
use App\Models\User;

class FraudFlagPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('fraud.view');
    }

    public function view(User $user, FraudFlag $model): bool
    {
        return $user->can('fraud.view');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, FraudFlag $model): bool
    {
        return $user->can('fraud.resolve');
    }

    public function delete(User $user, FraudFlag $model): bool
    {
        return false;
    }
}