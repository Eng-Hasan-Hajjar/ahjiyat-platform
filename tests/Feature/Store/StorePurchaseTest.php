<?php

use App\Models\Currency;
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

test('a price belonging to a different item is rejected', function () {
    $user = User::factory()->create();
    $itemA = StoreItem::factory()->create();
    $itemB = StoreItem::factory()->create();
    $priceForB = StoreItemPrice::factory()->create(['store_item_id' => $itemB->id]);

    expect(fn () => $this->purchases->purchase($user, $itemA, $priceForB, (string) Str::uuid()))
        ->toThrow(RuntimeException::class);
});

test('an inactive price cannot be purchased', function () {
    $user = User::factory()->create();
    $item = StoreItem::factory()->create();
    $price = StoreItemPrice::factory()->create(['store_item_id' => $item->id, 'is_active' => false]);

    expect(fn () => $this->purchases->purchase($user, $item, $price, (string) Str::uuid()))
        ->toThrow(RuntimeException::class);
});

test('a successful purchase debits the exact price, creates exactly one purchase and one ledger entry', function () {
    $user = User::factory()->create();
    $item = StoreItem::factory()->create();
    $price = StoreItemPrice::factory()->create(['store_item_id' => $item->id, 'amount' => 200]);
    $this->wallets->creditAvailable($user, $price->currency, 1000, 'test');

    $purchase = $this->purchases->purchase($user, $item, $price, (string) Str::uuid());

    expect($this->wallets->balanceFor($user, $price->currency)->available_balance)->toBe(800)
        ->and(StorePurchase::where('user_id', $user->id)->count())->toBe(1)
        ->and(\App\Models\CurrencyTransaction::where('reference_type', StorePurchase::class)
            ->where('reference_id', $purchase->id)->where('type', \App\Models\CurrencyTransaction::TYPE_SPEND)->count())->toBe(1);
});

test('a purchase in currency A never touches a wallet in currency B', function () {
    $user = User::factory()->create();
    $item = StoreItem::factory()->create();
    $currencyA = Currency::factory()->create();
    $currencyB = Currency::factory()->create();
    $priceA = StoreItemPrice::factory()->create(['store_item_id' => $item->id, 'currency_id' => $currencyA->id, 'amount' => 50]);
    $this->wallets->creditAvailable($user, $currencyA, 500, 'test');
    $this->wallets->creditAvailable($user, $currencyB, 500, 'test');

    $this->purchases->purchase($user, $item, $priceA, (string) Str::uuid());

    expect($this->wallets->balanceFor($user, $currencyA)->available_balance)->toBe(450)
        ->and($this->wallets->balanceFor($user, $currencyB)->available_balance)->toBe(500);
});

test('insufficient balance blocks the purchase entirely - no purchase, no transaction, no fulfillment', function () {
    $user = User::factory()->create();
    $item = StoreItem::factory()->create();
    $price = StoreItemPrice::factory()->create(['store_item_id' => $item->id, 'amount' => 500]);
    $this->wallets->creditAvailable($user, $price->currency, 10, 'test');

    expect(fn () => $this->purchases->purchase($user, $item, $price, (string) Str::uuid()))
        ->toThrow(RuntimeException::class);

    expect(StorePurchase::where('user_id', $user->id)->count())->toBe(0)
        ->and($this->wallets->balanceFor($user, $price->currency)->available_balance)->toBe(10)
        ->and(\App\Models\UserInventoryItem::where('user_id', $user->id)->exists())->toBeFalse();
});

test('a non-spendable currency blocks the purchase via the central invariant', function () {
    $user = User::factory()->create();
    $item = StoreItem::factory()->create();
    $currency = Currency::factory()->create(['is_spendable' => false]);
    $price = StoreItemPrice::factory()->create(['store_item_id' => $item->id, 'currency_id' => $currency->id, 'amount' => 10]);
    $this->wallets->creditAvailable($user, $currency, 100, 'setup');

    expect(fn () => $this->purchases->purchase($user, $item, $price, (string) Str::uuid()))
        ->toThrow(RuntimeException::class);
});

test('an expired currency blocks the purchase via the central invariant', function () {
    $user = User::factory()->create();
    $item = StoreItem::factory()->create();
    $currency = Currency::factory()->create();
    $price = StoreItemPrice::factory()->create(['store_item_id' => $item->id, 'currency_id' => $currency->id, 'amount' => 10]);
    $this->wallets->creditAvailable($user, $currency, 100, 'setup');
    $currency->update(['expires_at' => now()->subDay()]);

    expect(fn () => $this->purchases->purchase($user, $item, $price->fresh(), (string) Str::uuid()))
        ->toThrow(RuntimeException::class);
});

test('an inactive currency blocks the purchase via the central invariant', function () {
    $user = User::factory()->create();
    $item = StoreItem::factory()->create();
    $currency = Currency::factory()->create(['is_active' => false]);
    $price = StoreItemPrice::factory()->create(['store_item_id' => $item->id, 'currency_id' => $currency->id, 'amount' => 10]);

    expect(fn () => $this->purchases->purchase($user, $item, $price, (string) Str::uuid()))
        ->toThrow(RuntimeException::class);
});