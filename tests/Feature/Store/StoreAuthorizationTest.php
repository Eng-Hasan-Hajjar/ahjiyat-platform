<?php

use App\Models\StoreItem;
use App\Models\StoreItemPrice;
use App\Models\StorePurchase;
use App\Models\User;
use App\Services\Economy\CurrencyWalletService;
use App\Services\Store\StorePurchaseService;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
});

test('a content-manager can create a store item but cannot fulfill or refund purchases', function () {
    $manager = User::factory()->create();
    $manager->assignRole('content-manager');

    expect($manager->can('store.items.create'))->toBeTrue()
        ->and($manager->can('store.purchases.fulfill'))->toBeFalse()
        ->and($manager->can('store.purchases.refund'))->toBeFalse();
});

test('a support user can view and fulfill purchases but cannot refund', function () {
    $support = User::factory()->create();
    $support->assignRole('support');

    expect($support->can('store.purchases.view'))->toBeTrue()
        ->and($support->can('store.purchases.fulfill'))->toBeTrue()
        ->and($support->can('store.purchases.refund'))->toBeFalse();
});

test('a player has no store admin permissions at all', function () {
    $player = User::factory()->create();
    $player->assignRole('player');

    expect($player->can('store.items.view'))->toBeFalse()
        ->and($player->can('store.purchases.view'))->toBeFalse()
        ->and($player->can('store.purchases.refund'))->toBeFalse();
});

test('direct URL access to the store items admin page returns 403 for an unauthorized user', function () {
    $player = User::factory()->create();
    $player->assignRole('player');

    $this->actingAs($player)->get('/admin/store-items')->assertForbidden();
});

test('an administrator can access the store items and purchases admin pages', function () {
    $admin = User::factory()->create();
    $admin->assignRole('administrator');

    $this->actingAs($admin)->get('/admin/store-items')->assertSuccessful();
    $this->actingAs($admin)->get('/admin/store-purchases')->assertSuccessful();
});

test('a user cannot see another users inventory items in their own inventory page', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $itemB = StoreItem::factory()->create(['name' => 'عنصر المستخدم ب']);
    app(\App\Services\Store\InventoryService::class)->grant($userB, $itemB, 1, 'test');

    $this->actingAs($userA)->get(route('inventory.index'))->assertOk()->assertDontSee('عنصر المستخدم ب');
});

test('a user cannot see another users purchase history in their own inventory page', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $item = StoreItem::factory()->create();
    $price = StoreItemPrice::factory()->create(['store_item_id' => $item->id, 'amount' => 10, 'sort_order' => 999]);
    app(CurrencyWalletService::class)->creditAvailable($userB, $price->currency, 100, 'test');
    app(StorePurchaseService::class)->purchase($userB, $item, $price, (string) Str::uuid());

    $purchasesForA = $userA->fresh()->storePurchases()->count();

    expect($purchasesForA)->toBe(0);
});

test('a user cannot see another users active entitlements', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    app(\App\Services\Store\EntitlementService::class)->grant($userB, StoreItem::factory()->entitlement('season.vip')->create());

    expect(app(\App\Services\Store\EntitlementService::class)->hasActive($userA, 'season.vip'))->toBeFalse();
});