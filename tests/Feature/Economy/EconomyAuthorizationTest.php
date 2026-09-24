<?php

use App\Models\Currency;
use App\Models\User;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
});

test('a user without economy.currencies.view cannot access the currencies admin page', function () {
    $player = User::factory()->create();
    $player->assignRole('player');

    $this->actingAs($player)->get('/admin/currencies')->assertForbidden();
});

test('an administrator can access the currencies admin page', function () {
    $admin = User::factory()->create();
    $admin->assignRole('administrator');

    $this->actingAs($admin)->get('/admin/currencies')->assertSuccessful();
});

test('a content-manager can view currencies but cannot adjust wallets', function () {
    $manager = User::factory()->create();
    $manager->assignRole('content-manager');

    expect($manager->can('economy.currencies.view'))->toBeTrue()
        ->and($manager->can('wallet.adjust'))->toBeFalse();
});

test('creating a currency without the required permission is rejected server-side', function () {
    $player = User::factory()->create();
    $player->assignRole('player');

    expect($player->can('economy.currencies.create'))->toBeFalse();

    $this->actingAs($player)->get('/admin/currencies/create')->assertForbidden();
});