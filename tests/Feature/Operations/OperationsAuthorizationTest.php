<?php

use App\Models\Role;
use App\Models\User;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
});

test('manual QA scenario: a custom "موظف دعم" role with narrow permissions behaves exactly as scoped', function () {
    $role = Role::create(['name' => 'employee-support', 'guard_name' => 'web', 'label_ar' => 'موظف دعم']);
    $permissions = \Spatie\Permission\Models\Permission::whereIn('name', [
        'admin.access', 'users.view', 'users.view_activity',
    ])->get();
    $role->syncPermissions($permissions);

    $employee = User::factory()->create();
    $employee->assignRole('employee-support');

    $this->actingAs($employee)->get(\App\Filament\Resources\UserResource::getUrl())->assertOk();

    expect($employee->can('users.freeze'))->toBeFalse()
        ->and($employee->can('wallet.adjust'))->toBeFalse()
        ->and($employee->can('fraud.resolve'))->toBeFalse()
        ->and($employee->can('roles.assign'))->toBeFalse();

    $target = User::factory()->create();
    $response = $this->actingAs($employee)->get(\App\Filament\Resources\UserResource::getUrl('edit', ['record' => $target]));
    expect($response->status())->toBe(403);
});

test('content-manager does not receive any of the new sensitive E6 permissions by default', function () {
    $contentManager = User::factory()->create();
    $contentManager->assignRole('content-manager');

    expect($contentManager->can('wallet.adjust'))->toBeFalse()
        ->and($contentManager->can('fraud.resolve'))->toBeFalse()
        ->and($contentManager->can('security.sessions_revoke'))->toBeFalse()
        ->and($contentManager->can('users.freeze'))->toBeFalse();
});

test('administrator role received all new E6 permissions via the safe additive grant', function () {
    $admin = User::factory()->create();
    $admin->assignRole('administrator');

    foreach ([
        'users.freeze', 'users.unfreeze', 'users.view_security', 'users.view_wallet',
        'users.view_activity', 'users.manage_roles', 'wallet.adjust',
        'security.sessions_view', 'security.sessions_revoke', 'operations.dashboard_view',
    ] as $permission) {
        expect($admin->can($permission))->toBeTrue("expected administrator to have {$permission}");
    }
});

test('re-seeding does not duplicate or remove any existing custom permission a role was manually given', function () {
    $moderatorRole = Role::where('name', 'moderator')->firstOrFail();
    $moderatorRole->givePermissionTo('seasons.view');

    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

    expect($moderatorRole->fresh()->hasPermissionTo('seasons.view'))->toBeTrue();
});

test('super admin bypass still works correctly after all E6 permission additions', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super-admin');

    expect($superAdmin->can('wallet.adjust'))->toBeTrue()
        ->and($superAdmin->can('users.freeze'))->toBeTrue()
        ->and($superAdmin->can('security.sessions_revoke'))->toBeTrue()
        ->and($superAdmin->can('operations.dashboard_view'))->toBeTrue();
});