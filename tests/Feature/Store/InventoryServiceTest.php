<?php

use App\Models\InventoryTransaction;
use App\Models\StoreItem;
use App\Models\StoreItemPrice;
use App\Models\User;
use App\Services\Economy\CurrencyWalletService;
use App\Services\Store\InventoryService;
use App\Services\Store\StorePurchaseService;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    $this->inventory = app(InventoryService::class);
});

test('purchasing an inventory item increases quantity by exactly grant_quantity and logs one grant transaction', function () {
    $user = User::factory()->create();
    $item = StoreItem::factory()->create(['fulfillment_type' => StoreItem::FULFILLMENT_INVENTORY, 'grant_quantity' => 5]);
    $price = StoreItemPrice::factory()->create(['store_item_id' => $item->id, 'amount' => 10]);
    app(CurrencyWalletService::class)->creditAvailable($user, $price->currency, 100, 'test');

    app(StorePurchaseService::class)->purchase($user, $item, $price, (string) Str::uuid());

    expect($this->inventory->quantityFor($user, $item))->toBe(5)
        ->and(InventoryTransaction::where('user_id', $user->id)->where('type', InventoryTransaction::TYPE_GRANT)->count())->toBe(1);
});

test('grant() rejects a zero or negative quantity', function () {
    $user = User::factory()->create();
    $item = StoreItem::factory()->create();

    expect(fn () => $this->inventory->grant($user, $item, 0, 'test'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $this->inventory->grant($user, $item, -1, 'test'))->toThrow(InvalidArgumentException::class);
});

test('revoke never pushes the quantity below zero and returns null when nothing to revoke', function () {
    $user = User::factory()->create();
    $item = StoreItem::factory()->create();
    $this->inventory->grant($user, $item, 2, 'test');

    $result = $this->inventory->revoke($user, $item, 10, 'test');

    expect($result->quantity)->toBe(-2)
        ->and($this->inventory->quantityFor($user, $item))->toBe(0);

    $second = $this->inventory->revoke($user, $item, 5, 'test');
    expect($second)->toBeNull()
        ->and($this->inventory->quantityFor($user, $item))->toBe(0);
});

test('grant/revoke on the same user and item never affects a different user', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $item = StoreItem::factory()->create();

    $this->inventory->grant($userA, $item, 10, 'test');

    expect($this->inventory->quantityFor($userA, $item))->toBe(10)
        ->and($this->inventory->quantityFor($userB, $item))->toBe(0);
});