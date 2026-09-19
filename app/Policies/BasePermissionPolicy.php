<?php

namespace App\Policies;

use App\Models\User;

abstract class BasePermissionPolicy
{
    protected string $prefix;

    public function viewAny(User $user): bool
    {
        return $user->can("{$this->prefix}.view");
    }

    public function view(User $user, $model): bool
    {
        return $user->can("{$this->prefix}.view");
    }

    public function create(User $user): bool
    {
        return $user->can("{$this->prefix}.create");
    }

    public function update(User $user, $model): bool
    {
        return $user->can("{$this->prefix}.update");
    }

    public function delete(User $user, $model): bool
    {
        return $user->can("{$this->prefix}.delete");
    }
}