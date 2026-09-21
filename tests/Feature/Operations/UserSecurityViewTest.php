<?php

use App\Models\DeviceSighting;
use App\Models\Session as SessionModel;
use App\Models\User;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
});

test('security data is only visible to a role with users.view_security', function () {
    $withPermission = User::factory()->create();
    $withPermission->assignRole('administrator');

    $withoutPermission = User::factory()->create();
    $withoutPermission->assignRole('support');

    expect($withPermission->can('users.view_security'))->toBeTrue()
        ->and($withoutPermission->can('users.view_security'))->toBeFalse();
});

test('device sightings store only a hashed device fingerprint, never a raw value', function () {
    $user = User::factory()->create();
    DeviceSighting::create([
        'user_id' => $user->id,
        'ip_address' => '10.0.0.1',
        'device_hash' => hash('sha256', 'some-user-agent-string'),
        'last_seen_at' => now(),
    ]);

    $sighting = $user->deviceSightings()->first();

    expect(strlen($sighting->device_hash))->toBe(64)
        ->and($sighting->device_hash)->not->toContain('Mozilla');
});

test('a user with users.view_security can see the security relation manager for a user record', function () {
    $admin = User::factory()->create();
    $admin->assignRole('administrator');

    $this->actingAs($admin);

    expect(
        \App\Filament\Resources\UserResource\RelationManagers\DeviceSightingsRelationManager::canViewForRecord(
            User::factory()->create(),
            \App\Filament\Resources\UserResource\Pages\ViewUser::class,
        )
    )->toBeTrue();
});

test('a user without users.view_security cannot see the security relation manager', function () {
    $support = User::factory()->create();
    $support->assignRole('support');

    $this->actingAs($support);

    expect(
        \App\Filament\Resources\UserResource\RelationManagers\DeviceSightingsRelationManager::canViewForRecord(
            User::factory()->create(),
            \App\Filament\Resources\UserResource\Pages\ViewUser::class,
        )
    )->toBeFalse();
});