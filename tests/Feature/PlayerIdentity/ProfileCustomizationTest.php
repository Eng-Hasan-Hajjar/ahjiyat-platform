<?php

use App\Models\StoreItem;
use App\Models\User;
use App\Models\UserCosmeticLoadout;
use App\Services\Store\InventoryService;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    $this->inventory = app(InventoryService::class);
});

test('an authenticated user can equip an owned cosmetic via the route', function () {
    $user = User::factory()->create();
    $avatar = StoreItem::factory()->cosmeticAvatar()->create();
    $this->inventory->grant($user, $avatar, 1, 'test');

    $this->actingAs($user)
        ->post(route('profile.cosmetics.equip', $avatar))
        ->assertRedirect();

    expect(UserCosmeticLoadout::where('user_id', $user->id)->where('store_item_id', $avatar->id)->exists())->toBeTrue();
});

test('equipping an unowned item via the route fails gracefully with an error message, not a crash', function () {
    $user = User::factory()->create();
    $avatar = StoreItem::factory()->cosmeticAvatar()->create();

    $this->actingAs($user)
        ->post(route('profile.cosmetics.equip', $avatar))
        ->assertRedirect()
        ->assertSessionHas('error');

    expect(UserCosmeticLoadout::where('user_id', $user->id)->count())->toBe(0);
});

test('an authenticated user can unequip a slot via the route', function () {
    $user = User::factory()->create();
    $avatar = StoreItem::factory()->cosmeticAvatar()->create();
    $this->inventory->grant($user, $avatar, 1, 'test');
    app(\App\Services\PlayerIdentity\CosmeticLoadoutService::class)->equip($user, $avatar);

    $this->actingAs($user)
        ->delete(route('profile.cosmetics.unequip', StoreItem::SLOT_AVATAR))
        ->assertRedirect();

    expect(UserCosmeticLoadout::where('user_id', $user->id)->count())->toBe(0);
});

test('a guest cannot access equip/unequip/customize routes', function () {
    $item = StoreItem::factory()->cosmeticAvatar()->create();

    $this->get(route('profile.customize'))->assertRedirect(route('login'));
    $this->post(route('profile.cosmetics.equip', $item))->assertRedirect(route('login'));
});

test('a user can update their own profile visibility to an allowed value', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('profile.visibility.update'), ['profile_visibility' => \App\Models\User::VISIBILITY_PUBLIC])
        ->assertRedirect();

    expect($user->fresh()->profile_visibility)->toBe(\App\Models\User::VISIBILITY_PUBLIC);
});

test('an invalid profile visibility value is rejected by validation', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('profile.visibility.update'), ['profile_visibility' => 'super-public-hack'])
        ->assertSessionHasErrors('profile_visibility');
});

test('a frozen user cannot equip, unequip, or change visibility', function () {
    $user = User::factory()->create(['is_frozen' => true]);
    $avatar = StoreItem::factory()->cosmeticAvatar()->create();
    $this->inventory->grant($user, $avatar, 1, 'test');

    $this->actingAs($user)->post(route('profile.cosmetics.equip', $avatar))->assertForbidden();
    $this->actingAs($user)->delete(route('profile.cosmetics.unequip', StoreItem::SLOT_AVATAR))->assertForbidden();
    $this->actingAs($user)->patch(route('profile.visibility.update'), ['profile_visibility' => 'public'])->assertForbidden();
});