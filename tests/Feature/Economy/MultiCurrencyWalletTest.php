<?php

use App\Models\Currency;
use App\Models\User;
use App\Services\Economy\CurrencyWalletService;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    $this->wallets = app(CurrencyWalletService::class);
});

test('a user can hold balances in two different currencies without interference', function () {
    $user = User::factory()->create();
    $eventCurrency = Currency::factory()->create();

    $this->wallets->creditAvailable($user, $eventCurrency, 100, 'test');

    $legacyBalance = $this->wallets->balanceFor($user, app(\App\Services\Economy\CurrencyRegistry::class)->defaultEarnedCurrency());
    $eventBalance = $this->wallets->balanceFor($user, $eventCurrency);

    expect($legacyBalance->available_balance)->toBe(0)
        ->and($eventBalance->available_balance)->toBe(100);
});

test('crediting currency A does not change currency B balance', function () {
    $user = User::factory()->create();
    $currencyA = Currency::factory()->create();
    $currencyB = Currency::factory()->create();

    $this->wallets->creditAvailable($user, $currencyA, 50, 'test');
    $this->wallets->creditAvailable($user, $currencyB, 200, 'test');

    expect($this->wallets->balanceFor($user, $currencyA)->available_balance)->toBe(50)
        ->and($this->wallets->balanceFor($user, $currencyB)->available_balance)->toBe(200);
});

test('debiting currency B does not change currency A balance', function () {
    $user = User::factory()->create();
    $currencyA = Currency::factory()->create();
    $currencyB = Currency::factory()->create();

    $this->wallets->creditAvailable($user, $currencyA, 50, 'test');
    $this->wallets->creditAvailable($user, $currencyB, 200, 'test');
    $this->wallets->debitAvailable($user, $currencyB, 100, 'test');

    expect($this->wallets->balanceFor($user, $currencyA)->available_balance)->toBe(50)
        ->and($this->wallets->balanceFor($user, $currencyB)->available_balance)->toBe(100);
});

test('insufficient balance throws a safe domain error and leaves the balance and ledger untouched', function () {
    $user = User::factory()->create();
    $currency = Currency::factory()->create();
    $this->wallets->creditAvailable($user, $currency, 10, 'test');

    expect(fn () => $this->wallets->debitAvailable($user, $currency, 999, 'test'))
        ->toThrow(RuntimeException::class);

    expect($this->wallets->balanceFor($user, $currency)->available_balance)->toBe(10)
        ->and(\App\Models\CurrencyTransaction::where('currency_id', $currency->id)->where('type', 'redeem')->count())->toBe(0);
});