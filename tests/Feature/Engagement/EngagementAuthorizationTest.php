<?php

use App\Models\QuestDefinition;
use App\Models\User;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
});

test('an administrator can access the quest definitions admin page', function () {
    $admin = User::factory()->create();
    $admin->assignRole('administrator');

    $this->actingAs($admin)->get('/admin/quest-definitions')->assertOk();
});

test('a content-manager can author quests but nothing sensitive elsewhere', function () {
    $cm = User::factory()->create();
    $cm->assignRole('content-manager');

    $this->actingAs($cm)->get('/admin/quest-definitions')->assertOk();
    $this->actingAs($cm)->get('/admin/quest-definitions/create')->assertOk();
});

test('a support user can view user engagement but cannot author quest definitions', function () {
    $support = User::factory()->create();
    $support->assignRole('support');

    expect($support->can('engagement.users.view'))->toBeTrue()
        ->and($support->can('engagement.quests.create'))->toBeFalse();

    $this->actingAs($support)->get('/admin/quest-definitions/create')->assertForbidden();
});

test('a moderator has no engagement definition permissions by default', function () {
    $moderator = User::factory()->create();
    $moderator->assignRole('moderator');

    $this->actingAs($moderator)->get('/admin/quest-definitions')->assertForbidden();
});

test('a player has zero engagement admin permissions', function () {
    $player = User::factory()->create();
    $player->assignRole('player');

    $this->actingAs($player)->get('/admin/quest-definitions')->assertForbidden();
});

test('E13 req 407: direct URL access to the quest admin page returns 403 for an unauthorized role', function () {
    $quest = QuestDefinition::factory()->daily(1)->create();
    $player = User::factory()->create();
    $player->assignRole('player');

    $this->actingAs($player)->get("/admin/quest-definitions/{$quest->id}/edit")->assertForbidden();
});

test('/quests requires authentication', function () {
    $this->get(route('quests.show'))->assertRedirect(route('login'));
});

test('an authenticated user can access their own /quests page', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($user)->get(route('quests.show'))->assertOk();
});
