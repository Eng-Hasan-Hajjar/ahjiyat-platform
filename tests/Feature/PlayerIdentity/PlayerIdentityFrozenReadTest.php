<?php

use App\Models\StoreItem;
use App\Models\User;
use App\Models\UserCosmeticLoadout;
use App\Services\Store\InventoryService;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
});

test('a frozen user can still read public and members profiles - reading is not a mutation', function () {
    $frozen = User::factory()->create(['is_frozen' => true]);
    $publicPlayer = User::factory()->create(['profile_visibility' => User::VISIBILITY_PUBLIC, 'name' => 'لاعب عام مفتوح']);
    $membersPlayer = User::factory()->create(['profile_visibility' => User::VISIBILITY_MEMBERS, 'name' => 'لاعب للأعضاء فقط']);

    $this->actingAs($frozen)->get(route('players.show', $publicPlayer))->assertOk()->assertSee('لاعب عام مفتوح');
    $this->actingAs($frozen)->get(route('players.show', $membersPlayer))->assertOk()->assertSee('لاعب للأعضاء فقط');
});

test('a frozen user still gets 404 for another users private profile, and can read their own', function () {
    $frozen = User::factory()->create(['is_frozen' => true, 'profile_visibility' => User::VISIBILITY_PRIVATE, 'name' => 'مجمَّد يقرأ ملفه']);
    $privatePlayer = User::factory()->create(['profile_visibility' => User::VISIBILITY_PRIVATE]);

    $this->actingAs($frozen)->get(route('players.show', $privatePlayer))->assertNotFound();
    $this->actingAs($frozen)->get(route('players.show', $frozen))->assertOk()->assertSee('مجمَّد يقرأ ملفه');
});

test('the same frozen user still cannot mutate equip, unequip, or visibility', function () {
    $frozen = User::factory()->create(['is_frozen' => true, 'profile_visibility' => User::VISIBILITY_PRIVATE]);
    $avatar = StoreItem::factory()->cosmeticAvatar()->create();
    app(InventoryService::class)->grant($frozen, $avatar, 1, 'test');

    $this->actingAs($frozen)->post(route('profile.cosmetics.equip', $avatar))->assertRedirect()->assertSessionHas('error');
    $this->actingAs($frozen)->patch(route('profile.visibility.update'), ['profile_visibility' => User::VISIBILITY_PUBLIC])
        ->assertRedirect()->assertSessionHas('error');

    expect(UserCosmeticLoadout::where('user_id', $frozen->id)->count())->toBe(0)
        ->and($frozen->fresh()->profile_visibility)->toBe(User::VISIBILITY_PRIVATE);
});