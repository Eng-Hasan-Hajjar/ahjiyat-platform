<?php

use App\Models\Achievement;
use App\Models\LevelDefinition;
use App\Models\User;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
});

test('E12 req 214: an administrator can access both progression admin pages', function () {
    $admin = User::factory()->create();
    $admin->assignRole('administrator');

    $this->actingAs($admin)->get('/admin/achievements')->assertSuccessful();
    $this->actingAs($admin)->get('/admin/level-definitions')->assertSuccessful();
});

test('a content-manager can author achievements and levels but nothing sensitive elsewhere', function () {
    $manager = User::factory()->create();
    $manager->assignRole('content-manager');

    expect($manager->can('progression.achievements.create'))->toBeTrue()
        ->and($manager->can('progression.levels.create'))->toBeTrue()
        ->and($manager->can('wallet.adjust'))->toBeFalse();
});

test('a support user can view progression but cannot author definitions', function () {
    $support = User::factory()->create();
    $support->assignRole('support');

    expect($support->can('progression.users.view'))->toBeTrue()
        ->and($support->can('progression.achievements.create'))->toBeFalse()
        ->and($support->can('progression.levels.create'))->toBeFalse();
});

test('a moderator has no progression definition permissions by default', function () {
    $moderator = User::factory()->create();
    $moderator->assignRole('moderator');

    expect($moderator->can('progression.achievements.create'))->toBeFalse()
        ->and($moderator->can('progression.levels.create'))->toBeFalse();
});

test('a player has zero progression admin permissions', function () {
    $player = User::factory()->create();
    $player->assignRole('player');

    expect($player->can('progression.achievements.view'))->toBeFalse()
        ->and($player->can('progression.levels.view'))->toBeFalse()
        ->and($player->can('progression.users.view'))->toBeFalse();
});

test('E12 req 300: direct URL access to progression admin pages returns 403 for an unauthorized role', function () {
    $player = User::factory()->create();
    $player->assignRole('player');

    $this->actingAs($player)->get('/admin/achievements')->assertForbidden();
    $this->actingAs($player)->get('/admin/level-definitions')->assertForbidden();
});

test('/progress requires authentication', function () {
    $this->get(route('progress.show'))->assertRedirect(route('login'));
});

test('an authenticated user can access their own /progress page', function () {
    LevelDefinition::factory()->first()->create();
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('progress.show'))->assertOk();
});