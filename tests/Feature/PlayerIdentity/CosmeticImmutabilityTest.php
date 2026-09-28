<?php

use App\Exceptions\StoreItemInvariantViolation;
use App\Models\StoreItem;
use App\Models\StoreItemPrice;
use App\Models\User;
use App\Services\Economy\CurrencyWalletService;
use App\Services\PlayerIdentity\CosmeticLoadoutService;
use App\Services\Store\InventoryService;
use App\Services\Store\StorePurchaseService;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    $this->inventory = app(InventoryService::class);
});

test('E11.1 req 161: a used cosmetic cannot change slot - the write is rejected and the stored slot is unchanged', function () {
    $user = User::factory()->create();
    $item = StoreItem::factory()->cosmeticFrame()->create();
    $this->inventory->grant($user, $item, 1, 'test');

    expect(fn () => $item->update(['cosmetic_slot' => StoreItem::SLOT_BADGE]))
        ->toThrow(StoreItemInvariantViolation::class);

    expect($item->fresh()->cosmetic_slot)->toBe(StoreItem::SLOT_FRAME);
});

test('a used cosmetic cannot have its slot cleared either', function () {
    $user = User::factory()->create();
    $item = StoreItem::factory()->cosmeticFrame()->create();
    $this->inventory->grant($user, $item, 1, 'test');

    expect(fn () => $item->update(['cosmetic_slot' => null]))->toThrow(StoreItemInvariantViolation::class);
    expect($item->fresh()->cosmetic_slot)->toBe(StoreItem::SLOT_FRAME);
});

test('purchase history alone keeps the slot locked even after a full refund brought ownership to zero', function () {
    $user = User::factory()->create();
    $admin = User::factory()->create();
    $item = StoreItem::factory()->cosmeticFrame()->create();
    $price = StoreItemPrice::factory()->create(['store_item_id' => $item->id, 'amount' => 10]);
    app(CurrencyWalletService::class)->creditAvailable($user, $price->currency, 100, 'test');

    $purchase = app(StorePurchaseService::class)->purchase($user, $item, $price, (string) Str::uuid());
    app(StorePurchaseService::class)->refund($purchase, $admin, 'اختبار');

    expect($this->inventory->quantityFor($user, $item))->toBe(0);

    expect(fn () => $item->fresh()->update(['cosmetic_slot' => StoreItem::SLOT_BADGE]))
        ->toThrow(StoreItemInvariantViolation::class);

    expect($item->fresh()->cosmetic_slot)->toBe(StoreItem::SLOT_FRAME);
});

test('an equipped-then-owned cosmetic also keeps its slot locked', function () {
    $user = User::factory()->create();
    $item = StoreItem::factory()->cosmeticAvatar()->create();
    $this->inventory->grant($user, $item, 1, 'test');
    app(CosmeticLoadoutService::class)->equip($user, $item);

    expect(fn () => $item->update(['cosmetic_slot' => StoreItem::SLOT_FRAME]))
        ->toThrow(StoreItemInvariantViolation::class);

    expect($item->fresh()->cosmetic_slot)->toBe(StoreItem::SLOT_AVATAR);
});

test('an unused cosmetic can still change slot', function () {
    $item = StoreItem::factory()->cosmeticFrame()->create();

    $item->update(['cosmetic_slot' => StoreItem::SLOT_BADGE]);

    expect($item->fresh()->cosmetic_slot)->toBe(StoreItem::SLOT_BADGE);
});

test('decision: a legacy owned cosmetic with no slot can be assigned one once - the only path to make it equippable', function () {
    $user = User::factory()->create();
    $item = StoreItem::factory()->legacyCosmeticWithoutSlot()->create(['image_path' => 'store-items/legacy.png']);
    $this->inventory->grant($user, $item, 1, 'test');

    $item->update(['cosmetic_slot' => StoreItem::SLOT_AVATAR]);

    expect($item->fresh()->isCosmeticEquippable())->toBeTrue();

    // بعد الإسناد تُقفَل الفتحة كأي عنصر مُستخدَم.
    expect(fn () => $item->fresh()->update(['cosmetic_slot' => StoreItem::SLOT_BADGE]))
        ->toThrow(StoreItemInvariantViolation::class);
});

test('E11.1 req 25: a used cosmetic cannot change item_type or fulfillment_type', function () {
    $user = User::factory()->create();
    $item = StoreItem::factory()->cosmeticBadge()->create();
    $this->inventory->grant($user, $item, 1, 'test');

    expect(fn () => $item->update(['item_type' => StoreItem::TYPE_DIGITAL]))
        ->toThrow(StoreItemInvariantViolation::class);

    expect(fn () => $item->fresh()->update(['fulfillment_type' => StoreItem::FULFILLMENT_MANUAL]))
        ->toThrow(StoreItemInvariantViolation::class);

    $fresh = $item->fresh();

    expect($fresh->item_type)->toBe(StoreItem::TYPE_COSMETIC)
        ->and($fresh->fulfillment_type)->toBe(StoreItem::FULFILLMENT_INVENTORY)
        ->and($fresh->cosmetic_slot)->toBe(StoreItem::SLOT_BADGE);
});

test('a used cosmetic can still receive unrelated edits like a new name or deactivation', function () {
    $user = User::factory()->create();
    $item = StoreItem::factory()->cosmeticBadge()->create();
    $this->inventory->grant($user, $item, 1, 'test');

    $item->update(['name' => 'اسم معدَّل', 'is_active' => false]);

    expect($item->fresh()->name)->toBe('اسم معدَّل')
        ->and($item->fresh()->is_active)->toBeFalse();
});