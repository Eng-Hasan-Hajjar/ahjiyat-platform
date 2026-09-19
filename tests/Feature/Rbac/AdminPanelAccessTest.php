<?php

use App\Models\Role;
use App\Models\User;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
});

test('a user with a role that grants admin.access can access the admin panel', function () {
    $user = User::factory()->create();
    $user->assignRole('support');

    $response = $this->actingAs($user)->get('/admin');

    expect($response->status())->not->toBe(403);
});

test('a user with a role that lacks admin.access cannot access the admin panel', function () {
    $user = User::factory()->create();
    $user->assignRole('player');

    $response = $this->actingAs($user)->get('/admin');

    expect($response->status())->toBe(403);
});

test('a user with no role at all cannot access the admin panel', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/admin');

    expect($response->status())->toBe(403);
});

test('a guest is redirected away from the admin panel, not shown a 403', function () {
    $this->get('/admin')->assertRedirect();
});

test('direct URL access to a protected settings page still returns 403 for a user without the permission, even with a valid session', function () {
    $user = User::factory()->create();
    $user->assignRole('player');

    $this->actingAs($user)->get('/admin/settings')->assertForbidden();
});

test('super-admin bypasses admin.access entirely via Gate::before, with zero explicit permissions assigned', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super-admin');

    expect($superAdmin->permissions)->toHaveCount(0)
        ->and($superAdmin->can('admin.access'))->toBeTrue()
        ->and($superAdmin->can('anything.not.even.a.real.permission'))->toBeTrue();

    $response = $this->actingAs($superAdmin)->get('/admin');
    expect($response->status())->not->toBe(403);
});

test('a custom role created only with admin.access can enter the panel but sees no protected resources', function () {
    $role = Role::create(['name' => 'bare-entry', 'guard_name' => 'web']);
    $role->givePermissionTo('admin.access');

    $user = User::factory()->create();
    $user->assignRole('bare-entry');

    $response = $this->actingAs($user)->get('/admin');
    expect($response->status())->not->toBe(403);

    $this->actingAs($user)->get('/admin/settings')->assertForbidden();
});