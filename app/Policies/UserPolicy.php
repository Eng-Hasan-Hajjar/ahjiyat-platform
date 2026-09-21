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
        if (! app(AuthorizationSafetyService::class)->isSuperAdmin($user)) {
            return false;
        }

        if ($user->is($model)) {
            abort(403, 'لا يمكنك حذف حسابك الخاص أثناء تسجيل دخولك به.');
        }

        $this->safety->assertCanDeleteUser($model);

        return true;
    }

    public function freeze(User $user, User $model): bool
    {
        if (! $user->can('users.freeze')) {
            return false;
        }

        $this->safety->assertCanModifyUserAuthorization($user, $model);

        return ! $user->is($model);
    }

    public function unfreeze(User $user, User $model): bool
    {
        if (! $user->can('users.unfreeze')) {
            return false;
        }

        $this->safety->assertCanModifyUserAuthorization($user, $model);

        return true;
    }

    public function viewSecurity(User $user, User $model): bool
    {
        return $user->can('users.view_security');
    }

    public function viewWallet(User $user, User $model): bool
    {
        return $user->can('users.view_wallet');
    }

    public function viewActivity(User $user, User $model): bool
    {
        return $user->can('users.view_activity');
    }

    public function manageRoles(User $user, User $model): bool
    {
        if (! $user->can('users.manage_roles')) {
            return false;
        }

        $this->safety->assertCanModifyUserAuthorization($user, $model);

        return true;
    }
}