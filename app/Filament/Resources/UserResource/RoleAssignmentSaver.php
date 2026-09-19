<?php

namespace App\Filament\Resources\UserResource;

use App\Models\Role;
use App\Models\User;
use App\Services\AuthorizationAuditService;
use App\Services\AuthorizationSafetyService;
use Illuminate\Support\Facades\Auth;

class RoleAssignmentSaver
{
    public function save(User $record, array $roleIds): void
    {
        $actor = Auth::user();
        $before = $record->roles()->pluck('name')->all();
        $newRoleNames = Role::whereIn('id', $roleIds)->pluck('name')->all();

        app(AuthorizationSafetyService::class)->assertCanSyncRoles($actor, $record, $newRoleNames);

        $record->syncRoles($newRoleNames);

        $added = array_values(array_diff($newRoleNames, $before));
        $removed = array_values(array_diff($before, $newRoleNames));

        if ($added || $removed) {
            app(AuthorizationAuditService::class)->log('user_roles_updated', $record, [
                'added_roles' => $added,
                'removed_roles' => $removed,
            ]);
        }
    }
}