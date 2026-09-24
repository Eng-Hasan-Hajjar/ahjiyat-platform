<?php

use App\Models\Currency;
use App\Models\CurrencyTransaction;
use App\Models\User;
use App\Services\Analytics\EconomyAnalyticsService;
use App\Services\Economy\CurrencyWalletService;
use App\Support\AnalyticsPeriod;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    $this->analytics = app(EconomyAnalyticsService::class);
    $this->wallets = app(CurrencyWalletService::class);
});

test('gemsOverview for one currency never includes another currency amount', function () {
    $legacy = app(\App\Services\Economy\CurrencyRegistry::class)->defaultEarnedCurrency();
    $eventCurrency = Currency::factory()->create();
    $user = User::factory()->create();

    $this->wallets->creditPending($user, $legacy, 100, 'test');
    $this->wallets->creditPending($user, $eventCurrency, 5000, 'test');

    $legacyOverview = $this->analytics->gemsOverview(AnalyticsPeriod::fromPreset('last_30_days'), $legacy);

    expect($legacyOverview['issued_in_period'])->toBe(100);
});

test('allCurrenciesOverview returns one independent entry per currency', function () {
    Currency::factory()->create();
    Currency::factory()->create();

    $overview = $this->analytics->allCurrenciesOverview(AnalyticsPeriod::fromPreset('last_30_days'));

    expect(count($overview))->toBe(4);
    foreach ($overview as $row) {
        expect($row)->toHaveKey('currency')->and($row['currency'])->toHaveKey('id');
    }
});