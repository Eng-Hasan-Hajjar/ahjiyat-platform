<?php

namespace App\Policies;

use App\Models\User;
use App\Services\AuthorizationSafetyService;

class UserPolicy
{
    public function __construct(protected AuthorizationSafetyService $safety) {}

    public function viewAny(User $user): bool
    {
        return $user->can('users.view');
    }

    public function view(User $user, User $model): bool
    {
        return $user->can('users.view');
    }

    public function create(User $user): bool
    {
        return $user->can('users.create');
    }

    public function update(User $user, User $model): bool
    {
        if (! $user->can('users.update')) {
            return false;
        }

        $this->safety->assertCanModifyUserAuthorization($user, $model);

        return true;
    }

    public function delete(User $user, User $model): bool
    {
        if (! $user->can('users.delete')) {
            return false;
        }

        if ($user->is($model)) {
            abort(403, 'لا يمكنك حذف حسابك الخاص أثناء تسجيل دخولك به.');
        }

        $this->safety->assertCanDeleteUser($model);

        return true;
    }
}