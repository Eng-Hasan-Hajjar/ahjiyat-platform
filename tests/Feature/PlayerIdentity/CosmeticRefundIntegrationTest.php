<?php

use App\Models\StoreItem;
use App\Models\StoreItemPrice;
use App\Models\User;
use App\Models\UserCosmeticLoadout;
use App\Services\Economy\CurrencyWalletService;
use App\Services\PlayerIdentity\CosmeticLoadoutService;
use App\Services\Store\InventoryService;
use App\Services\Store\StorePurchaseService;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    $this->loadouts = app(CosmeticLoadoutService::class);
    $this->inventory = app(InventoryService::class);
    $this->purchases = app(StorePurchaseService::class);
    $this->wallets = app(CurrencyWalletService::class);
});

test('E11 critical: purchase then equip then refund - balance refunded, inventory zero, frame auto-unequipped', function () {
    $user = User::factory()->create();
    $admin = User::factory()->create();
    $frame = StoreItem::factory()->cosmeticFrame()->create();
    $price = StoreItemPrice::factory()->create(['store_item_id' => $frame->id, 'amount' => 100]);
    $this->wallets->creditAvailable($user, $price->currency, 1000, 'test');

    $purchase = $this->purchases->purchase($user, $frame, $price, (string) Str::uuid());
    $this->loadouts->equip($user, $frame);

    expect($this->loadouts->equippedForSlot($user, StoreItem::SLOT_FRAME)?->id)->toBe($frame->id);

    $this->purchases->refund($purchase, $admin, 'اختبار');

    expect($this->wallets->balanceFor($user, $price->currency)->available_balance)->toBe(1000)
        ->and($this->inventory->quantityFor($user, $frame))->toBe(0)
        ->and($this->loadouts->equippedForSlot($user, StoreItem::SLOT_FRAME))->toBeNull()
        ->and(UserCosmeticLoadout::where('user_id', $user->id)->where('store_item_id', $frame->id)->exists())->toBeFalse();
});

test('InventoryService revoke to zero auto-unequips even outside a refund flow', function () {
    $user = User::factory()->create();
    $badge = StoreItem::factory()->cosmeticBadge()->create();
    $this->inventory->grant($user, $badge, 1, 'test');
    $this->loadouts->equip($user, $badge);

    $this->inventory->revoke($user, $badge, 1, 'manual_admin_revoke');

    expect($this->loadouts->equippedForSlot($user, StoreItem::SLOT_BADGE))->toBeNull();
});

test('a partial revoke that leaves quantity above zero does not unequip the item', function () {
    $user = User::factory()->create();
    $badge = StoreItem::factory()->cosmeticBadge()->create();
    $this->inventory->grant($user, $badge, 2, 'test');
    $this->loadouts->equip($user, $badge);

    $this->inventory->revoke($user, $badge, 1, 'test');

    expect($this->inventory->quantityFor($user, $badge))->toBe(1)
        ->and($this->loadouts->equippedForSlot($user, StoreItem::SLOT_BADGE)?->id)->toBe($badge->id);
});

test('refunding a non-cosmetic inventory purchase does not touch any loadout row', function () {
    $user = User::factory()->create();
    $admin = User::factory()->create();
    $item = StoreItem::factory()->create(['item_type' => StoreItem::TYPE_DIGITAL, 'fulfillment_type' => StoreItem::FULFILLMENT_INVENTORY]);
    $price = StoreItemPrice::factory()->create(['store_item_id' => $item->id, 'amount' => 50]);
    $this->wallets->creditAvailable($user, $price->currency, 500, 'test');
    $purchase = $this->purchases->purchase($user, $item, $price, (string) Str::uuid());

    $this->purchases->refund($purchase, $admin, 'اختبار');

    expect(UserCosmeticLoadout::where('user_id', $user->id)->count())->toBe(0);
});