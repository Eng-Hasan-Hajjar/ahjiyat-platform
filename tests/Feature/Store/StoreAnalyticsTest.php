<?php

use App\Models\Currency;
use App\Models\StoreItem;
use App\Models\StoreItemPrice;
use App\Models\User;
use App\Services\Analytics\StoreAnalyticsService;
use App\Services\Economy\CurrencyRegistry;
use App\Services\Economy\CurrencyWalletService;
use App\Services\Store\StorePurchaseService;
use App\Support\AnalyticsPeriod;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    $this->analytics = app(StoreAnalyticsService::class);
});

test('spend for one currency never includes purchases made in a different currency', function () {
    $legacy = app(CurrencyRegistry::class)->defaultEarnedCurrency();
    $eventCurrency = Currency::factory()->create();

    $itemA = StoreItem::factory()->create();
    $priceA = StoreItemPrice::factory()->create(['store_item_id' => $itemA->id, 'currency_id' => $legacy->id, 'amount' => 100]);
    $itemB = StoreItem::factory()->create();
    $priceB = StoreItemPrice::factory()->create(['store_item_id' => $itemB->id, 'currency_id' => $eventCurrency->id, 'amount' => 5000]);

    $userA = User::factory()->create();
    $userB = User::factory()->create();
    app(CurrencyWalletService::class)->creditAvailable($userA, $legacy, 1000, 'test');
    app(CurrencyWalletService::class)->creditAvailable($userB, $eventCurrency, 10000, 'test');

    app(StorePurchaseService::class)->purchase($userA, $itemA, $priceA, (string) Str::uuid());
    app(StorePurchaseService::class)->purchase($userB, $itemB, $priceB, (string) Str::uuid());

    $legacyOverview = $this->analytics->overview(AnalyticsPeriod::fromPreset('last_30_days'), $legacy);

    expect($legacyOverview['total_spent'])->toBe(100);
});

test('allCurrenciesOverview returns one independent breakdown per currency, never a combined total', function () {
    Currency::factory()->create();
    Currency::factory()->create();

    $overview = $this->analytics->allCurrenciesOverview(AnalyticsPeriod::fromPreset('last_30_days'));

    expect(count($overview))->toBe(4);
    foreach ($overview as $row) {
        expect($row)->toHaveKey('currency')->and($row['currency'])->toHaveKey('code');
    }
});

test('pending fulfillment and refund counts are tracked separately from total spend', function () {
    $legacy = app(CurrencyRegistry::class)->defaultEarnedCurrency();
    $manualItem = StoreItem::factory()->manual()->create();
    $price = StoreItemPrice::factory()->create(['store_item_id' => $manualItem->id, 'currency_id' => $legacy->id, 'amount' => 50]);
    $user = User::factory()->create();
    app(CurrencyWalletService::class)->creditAvailable($user, $legacy, 100, 'test');
    app(StorePurchaseService::class)->purchase($user, $manualItem, $price, (string) Str::uuid());

    $overview = $this->analytics->overview(AnalyticsPeriod::fromPreset('last_30_days'), $legacy);

    expect($overview['pending_fulfillment'])->toBe(1)
        ->and($overview['refunds_count'])->toBe(0);
});