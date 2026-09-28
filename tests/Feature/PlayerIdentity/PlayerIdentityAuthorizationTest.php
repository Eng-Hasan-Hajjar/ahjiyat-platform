<?php

use App\Models\StoreItem;
use App\Models\User;
use App\Models\UserCosmeticLoadout;
use App\Services\PlayerIdentity\CosmeticLoadoutService;
use App\Services\Store\InventoryService;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
});

test('equip always applies to the authenticated user only - there is no user_id parameter a client can pass', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $avatar = StoreItem::factory()->cosmeticAvatar()->create();
    app(InventoryService::class)->grant($userA, $avatar, 1, 'test');

    $this->actingAs($userB)
        ->post(route('profile.cosmetics.equip', $avatar))
        ->assertRedirect()
        ->assertSessionHas('error');

    expect(UserCosmeticLoadout::where('user_id', $userB->id)->count())->toBe(0);
});

test('unequip only ever clears the acting users own loadout slot, never another users', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $avatar = StoreItem::factory()->cosmeticAvatar()->create();
    app(InventoryService::class)->grant($userA, $avatar, 1, 'test');
    app(CosmeticLoadoutService::class)->equip($userA, $avatar);

    $this->actingAs($userB)->delete(route('profile.cosmetics.unequip', StoreItem::SLOT_AVATAR))->assertRedirect();

    expect(UserCosmeticLoadout::where('user_id', $userA->id)->exists())->toBeTrue();
});

test('a user cannot change another users profile visibility - the update always targets auth()->user()', function () {
    $userA = User::factory()->create(['profile_visibility' => User::VISIBILITY_PRIVATE]);
    $userB = User::factory()->create(['profile_visibility' => User::VISIBILITY_PRIVATE]);

    $this->actingAs($userB)->patch(route('profile.visibility.update'), ['profile_visibility' => User::VISIBILITY_PUBLIC]);

    expect($userA->fresh()->profile_visibility)->toBe(User::VISIBILITY_PRIVATE)
        ->and($userB->fresh()->profile_visibility)->toBe(User::VISIBILITY_PUBLIC);
});

test('unequip rejects an arbitrary non-existent slot value with a 404, not a server error', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->delete(route('profile.cosmetics.unequip', 'not-a-real-slot'))
        ->assertNotFound();
});