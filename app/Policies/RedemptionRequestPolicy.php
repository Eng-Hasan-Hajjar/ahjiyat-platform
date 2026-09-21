<?php

namespace App\Policies;

use App\Models\RedemptionRequest;
use App\Models\User;

class RedemptionRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('redemptions.view');
    }

    public function view(User $user, RedemptionRequest $model): bool
    {
        return $user->can('redemptions.view');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, RedemptionRequest $model): bool
    {
        return $user->can('redemptions.approve') || $user->can('redemptions.reject');
    }

    public function delete(User $user, RedemptionRequest $model): bool
    {
        return false;
    }

    public function approve(User $user, RedemptionRequest $model): bool
    {
        return $user->can('redemptions.approve');
    }

    public function fulfill(User $user, RedemptionRequest $model): bool
    {
        return $user->can('redemptions.approve');
    }

    public function reject(User $user, RedemptionRequest $model): bool
    {
        return $user->can('redemptions.reject');
    }
}