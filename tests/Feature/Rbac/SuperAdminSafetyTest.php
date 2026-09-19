<?php

use App\Models\User;
use App\Services\AuthorizationSafetyService;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
});

test('the last super admin cannot be deleted', function () {
    $lastSuperAdmin = User::factory()->create();
    $lastSuperAdmin->assignRole('super-admin');

    expect(fn () => app(AuthorizationSafetyService::class)->assertCanDeleteUser($lastSuperAdmin))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

test('a super admin can be deleted safely when at least one other super admin exists', function () {
    $superAdmin1 = User::factory()->create();
    $superAdmin1->assignRole('super-admin');
    $superAdmin2 = User::factory()->create();
    $superAdmin2->assignRole('super-admin');

    app(AuthorizationSafetyService::class)->assertCanDeleteUser($superAdmin1);

    expect(true)->toBeTrue();
});

test('the super-admin role cannot be removed from the last super admin via role sync', function () {
    $lastSuperAdmin = User::factory()->create();
    $lastSuperAdmin->assignRole('super-admin');

    expect(fn () => app(AuthorizationSafetyService::class)->assertCanSyncRoles($lastSuperAdmin, $lastSuperAdmin, []))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

test('the super-admin role can be removed from a user when another super admin still exists', function () {
    $actor = User::factory()->create();
    $actor->assignRole('super-admin');
    $other = User::factory()->create();
    $other->assignRole('super-admin');

    app(AuthorizationSafetyService::class)->assertCanSyncRoles($actor, $other, []);

    expect(true)->toBeTrue();
});

test('isLastSuperAdmin correctly identifies the sole super admin', function () {
    $solo = User::factory()->create();
    $solo->assignRole('super-admin');

    expect(app(AuthorizationSafetyService::class)->isLastSuperAdmin($solo))->toBeTrue();

    $second = User::factory()->create();
    $second->assignRole('super-admin');

    expect(app(AuthorizationSafetyService::class)->isLastSuperAdmin($solo->fresh()))->toBeFalse();
});

test('super admin bypasses every permission check, including ones that do not exist at all', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super-admin');

    expect($superAdmin->can('users.delete'))->toBeTrue()
        ->and($superAdmin->can('some.totally.made.up.permission'))->toBeTrue();
});

test('a non-super-admin never bypasses permission checks, even with the administrator role', function () {
    $administrator = User::factory()->create();
    $administrator->assignRole('administrator');

    expect($administrator->can('some.totally.made.up.permission'))->toBeFalse();
});