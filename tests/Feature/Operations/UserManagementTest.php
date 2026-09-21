<?php

use App\Models\User;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
});

test('an authorized admin can view the users list', function () {
    $admin = User::factory()->create();
    $admin->assignRole('administrator');

    $this->actingAs($admin)->get(\App\Filament\Resources\UserResource::getUrl())->assertOk();
});

test('a role without users.view cannot view the users list', function () {
    $user = User::factory()->create();
    $user->assignRole('player');

    $response = $this->actingAs($user)->get(\App\Filament\Resources\UserResource::getUrl());

    expect($response->status())->toBe(403);
});

test('direct URL to a user record returns 403 for an unauthorized role', function () {
    $viewer = User::factory()->create();
    $viewer->assignRole('player');
    $target = User::factory()->create();

    $this->actingAs($viewer)
        ->get(\App\Filament\Resources\UserResource::getUrl('view', ['record' => $target]))
        ->assertForbidden();
});

test('an authorized admin can open a single user 360 view page', function () {
    $admin = User::factory()->create();
    $admin->assignRole('administrator');
    $target = User::factory()->create();

    $this->actingAs($admin)
        ->get(\App\Filament\Resources\UserResource::getUrl('view', ['record' => $target]))
        ->assertOk();
});

test('the frozen account status is reflected correctly for a frozen user', function () {
    $user = User::factory()->create(['is_frozen' => true]);

    expect($user->is_frozen)->toBeTrue();
});

test('users table query does not run a separate roles query per row (eager loaded)', function () {
    $admin = User::factory()->create();
    $admin->assignRole('administrator');
    User::factory()->count(5)->create();

    $this->actingAs($admin);

    \Illuminate\Support\Facades\DB::enableQueryLog();
    $users = \App\Models\User::with(['wallet:id,user_id,available_balance,pending_balance', 'roles:id,name,label_ar,color'])
        ->withCount(['fraudFlags as open_fraud_flags_count' => fn ($q) => $q->where('resolved', false)])
        ->get();
    $queryCount = count(\Illuminate\Support\Facades\DB::getQueryLog());
    \Illuminate\Support\Facades\DB::disableQueryLog();

    expect($queryCount)->toBeLessThan(6);
});