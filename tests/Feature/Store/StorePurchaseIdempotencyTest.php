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
    $this->purchases = app(StorePurchaseService::class);
    $this->wallets = app(CurrencyWalletService::class);
});

test('replaying the same request key never debits or grants inventory twice', function () {
    $user = User::factory()->create();
    $item = StoreItem::factory()->create(['fulfillment_type' => StoreItem::FULFILLMENT_INVENTORY, 'grant_quantity' => 3]);
    $price = StoreItemPrice::factory()->create(['store_item_id' => $item->id, 'amount' => 100]);
    $this->wallets->creditAvailable($user, $price->currency, 1000, 'test');
    $key = (string) Str::uuid();

    $first = $this->purchases->purchase($user, $item, $price, $key);
    $second = $this->purchases->purchase($user, $item, $price, $key);

    expect($first->id)->toBe($second->id)
        ->and($this->wallets->balanceFor($user, $price->currency)->available_balance)->toBe(900)
        ->and(StorePurchase::where('user_id', $user->id)->count())->toBe(1)
        ->and(app(\App\Services\Store\InventoryService::class)->quantityFor($user, $item))->toBe(3);
});

test('reusing a request key with a different item is a conflict, not a silent success', function () {
    $user = User::factory()->create();
    $itemA = StoreItem::factory()->create();
    $itemB = StoreItem::factory()->create();
    $priceA = StoreItemPrice::factory()->create(['store_item_id' => $itemA->id]);
    $priceB = StoreItemPrice::factory()->create(['store_item_id' => $itemB->id]);
    $this->wallets->creditAvailable($user, $priceA->currency, 1000, 'test');
    $this->wallets->creditAvailable($user, $priceB->currency, 1000, 'test');
    $key = (string) Str::uuid();

    $this->purchases->purchase($user, $itemA, $priceA, $key);

    expect(fn () => $this->purchases->purchase($user, $itemB, $priceB, $key))
        ->toThrow(RuntimeException::class);

    expect(StorePurchase::where('user_id', $user->id)->count())->toBe(1);
});

test('reusing a request key for a different price on the same item is also a conflict', function () {
    $user = User::factory()->create();
    $item = StoreItem::factory()->create();
    $priceOne = StoreItemPrice::factory()->create(['store_item_id' => $item->id, 'amount' => 100]);
    $priceTwo = StoreItemPrice::factory()->create([
        'store_item_id' => $item->id,
        'currency_id' => \App\Models\Currency::factory()->create()->id, // عملة مختلفة - القيد الفريد (item+currency) يمنع نفس العملة مرتين لنفس العنصر
        'amount' => 200,
    ]);
    $this->wallets->creditAvailable($user, $priceOne->currency, 1000, 'test');
    $this->wallets->creditAvailable($user, $priceTwo->currency, 1000, 'test');
    $key = (string) Str::uuid();

    $this->purchases->purchase($user, $item, $priceOne, $key);

    expect(fn () => $this->purchases->purchase($user, $item, $priceTwo, $key))
        ->toThrow(RuntimeException::class);
});