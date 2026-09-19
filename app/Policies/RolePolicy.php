<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;
use App\Services\AuthorizationSafetyService;

class RolePolicy
{
    public function __construct(protected AuthorizationSafetyService $safety) {}

    public function viewAny(User $user): bool
    {
        return $user->can('roles.view');
    }

    public function view(User $user, Role $role): bool
    {
        return $user->can('roles.view');
    }

    public function create(User $user): bool
    {
        return $user->can('roles.create');
    }

    public function update(User $user, Role $role): bool
    {
        return $user->can('roles.update');
    }

    public function delete(User $user, Role $role): bool
    {
        if (! $user->can('roles.delete')) {
            return false;
        }

        $this->safety->assertCanDeleteRole($role);

        return true;
    }

    public function assign(User $user): bool
    {
        return $user->can('roles.assign');
    }
}