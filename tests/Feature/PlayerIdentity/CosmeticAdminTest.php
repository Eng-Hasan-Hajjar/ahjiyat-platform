<?php

use App\Models\StoreItem;
use App\Models\User;
use App\Services\Store\InventoryService;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
});

test('an authorized admin can access the store items admin page to manage cosmetics', function () {
    $admin = User::factory()->create();
    $admin->assignRole('administrator');

    $this->actingAs($admin)->get('/admin/store-items')->assertSuccessful();
});

test('hasCosmeticUsageHistory is false for a brand-new unused cosmetic item', function () {
    $item = StoreItem::factory()->cosmeticFrame()->create();

    expect($item->hasCosmeticUsageHistory())->toBeFalse();
});

test('hasCosmeticUsageHistory is true once a user owns the item, even before any purchase record exists', function () {
    $user = User::factory()->create();
    $item = StoreItem::factory()->cosmeticFrame()->create();
    app(InventoryService::class)->grant($user, $item, 1, 'test');

    expect($item->fresh()->hasCosmeticUsageHistory())->toBeTrue();
});

test('hasCosmeticUsageHistory is true once the item is equipped in any loadout', function () {
    $user = User::factory()->create();
    $item = StoreItem::factory()->cosmeticFrame()->create();
    app(InventoryService::class)->grant($user, $item, 1, 'test');
    app(\App\Services\PlayerIdentity\CosmeticLoadoutService::class)->equip($user, $item);

    expect($item->fresh()->hasCosmeticUsageHistory())->toBeTrue();
});

test('isCosmeticEquippable is true only when type=cosmetic, fulfillment=inventory, and slot is set', function () {
    $ready = StoreItem::factory()->cosmeticBadge()->create();
    $legacy = StoreItem::factory()->legacyCosmeticWithoutSlot()->create();

    // E11.1: cosmetic + manual لم يعد يمكن إنشاؤه عبر Eloquent - نحاكي بيانات خام قديمة.
    $wrongFulfillment = StoreItem::factory()->cosmeticBadge()->create();
    \Illuminate\Support\Facades\DB::table('store_items')->where('id', $wrongFulfillment->id)->update(['fulfillment_type' => StoreItem::FULFILLMENT_MANUAL]);

    expect($ready->isCosmeticEquippable())->toBeTrue()
        ->and($legacy->isCosmeticEquippable())->toBeFalse()
        ->and($wrongFulfillment->fresh()->isCosmeticEquippable())->toBeFalse();
});

test('a valid hex cosmetic_color passes model-level assignment and is stored as-is', function () {
    $item = StoreItem::factory()->cosmeticTitle('لقب', '#ABCDEF')->create();

    expect($item->cosmetic_color)->toBe('#ABCDEF');
});