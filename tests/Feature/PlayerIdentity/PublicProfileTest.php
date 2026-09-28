<?php

use App\Models\User;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
});

test('a public profile is visible to a guest', function () {
    $player = User::factory()->create(['profile_visibility' => User::VISIBILITY_PUBLIC, 'name' => 'لاعب عام']);

    $this->get(route('players.show', $player))->assertOk()->assertSee('لاعب عام');
});

test('a private profile returns 404 to a guest and to another authenticated user', function () {
    $player = User::factory()->create(['profile_visibility' => User::VISIBILITY_PRIVATE]);
    $otherUser = User::factory()->create();

    $this->get(route('players.show', $player))->assertNotFound();
    $this->actingAs($otherUser)->get(route('players.show', $player))->assertNotFound();
});

test('the owner can always access their own profile even when private, as a preview', function () {
    $player = User::factory()->create(['profile_visibility' => User::VISIBILITY_PRIVATE, 'name' => 'أنا نفسي']);

    $this->actingAs($player)->get(route('players.show', $player))->assertOk()->assertSee('أنا نفسي');
});

test('a members-only profile is denied to a guest but allowed to any authenticated user', function () {
    $player = User::factory()->create(['profile_visibility' => User::VISIBILITY_MEMBERS, 'name' => 'عضو فقط']);
    $otherUser = User::factory()->create();

    $this->get(route('players.show', $player))->assertNotFound();
    $this->actingAs($otherUser)->get(route('players.show', $player))->assertOk()->assertSee('عضو فقط');
});

test('the default profile_visibility for a newly created user is private', function () {
    $user = User::factory()->create();

    expect($user->profile_visibility)->toBe(User::VISIBILITY_PRIVATE);
});

test('every user - existing or new - has a unique non-null public_id, never based on email', function () {
    $user = User::factory()->create();

    expect($user->public_id)->not->toBeNull()
        ->and($user->public_id)->not->toContain('@')
        ->and(User::where('public_id', $user->public_id)->count())->toBe(1);
});