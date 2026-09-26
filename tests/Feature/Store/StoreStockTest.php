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

test('a stock_limit of 1 allows exactly one purchase and blocks a second user', function () {
    $item = StoreItem::factory()->create(['stock_limit' => 1]);
    $price = StoreItemPrice::factory()->create(['store_item_id' => $item->id, 'amount' => 10]);

    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $this->wallets->creditAvailable($userA, $price->currency, 100, 'test');
    $this->wallets->creditAvailable($userB, $price->currency, 100, 'test');

    $this->purchases->purchase($userA, $item, $price, (string) Str::uuid());

    expect(fn () => $this->purchases->purchase($userB, $item, $price->fresh(), (string) Str::uuid()))
        ->toThrow(RuntimeException::class);

    expect(StorePurchase::where('store_item_id', $item->id)->count())->toBe(1);
});

test('remainingStock reflects only pending_fulfillment and fulfilled purchases, never all rows', function () {
    $item = StoreItem::factory()->create(['stock_limit' => 5]);

    expect($item->remainingStock())->toBe(5);
});

test('a refunded purchase frees up the stock slot it previously occupied', function () {
    $item = StoreItem::factory()->create(['stock_limit' => 1]);
    $price = StoreItemPrice::factory()->create(['store_item_id' => $item->id, 'amount' => 10]);
    $user = User::factory()->create();
    $this->wallets->creditAvailable($user, $price->currency, 100, 'test');

    $purchase = $this->purchases->purchase($user, $item, $price, (string) Str::uuid());
    expect($item->fresh()->isSoldOut())->toBeTrue();

    $admin = User::factory()->create();
    $this->purchases->refund($purchase, $admin, 'اختبار');

    expect($item->fresh()->isSoldOut())->toBeFalse()
        ->and($item->fresh()->remainingStock())->toBe(1);
});

test('with no stock_limit set, the item is never sold out regardless of purchase count', function () {
    $item = StoreItem::factory()->create(['stock_limit' => null]);
    $price = StoreItemPrice::factory()->create(['store_item_id' => $item->id, 'amount' => 10]);

    for ($i = 0; $i < 3; $i++) {
        $user = User::factory()->create();
        $this->wallets->creditAvailable($user, $price->currency, 100, 'test');
        $this->purchases->purchase($user, $item, $price->fresh(), (string) Str::uuid());
    }

    expect($item->fresh()->isSoldOut())->toBeFalse()
        ->and($item->fresh()->remainingStock())->toBeNull();
});