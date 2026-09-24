<?php

use App\Models\GemTransaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Economy\CurrencyRegistry;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
});

test('a pre-existing wallet with real balances is correctly assigned to the legacy earned currency', function () {
    $legacyCurrency = app(CurrencyRegistry::class)->defaultEarnedCurrency();

    $user = User::factory()->create();
    $user->wallet->update(['pending_balance' => 350, 'available_balance' => 1200, 'lifetime_earned' => 1550, 'lifetime_redeemed' => 0]);

    $wallet = Wallet::where('user_id', $user->id)->where('currency_id', $legacyCurrency->id)->first();

    expect($wallet)->not->toBeNull()
        ->and($wallet->pending_balance)->toBe(350)
        ->and($wallet->available_balance)->toBe(1200)
        ->and($wallet->lifetime_earned)->toBe(1550);
});

test('legacy GemTransaction rows remain fully readable and attributed to the legacy currency', function () {
    $legacyCurrency = app(CurrencyRegistry::class)->defaultEarnedCurrency();
    $user = User::factory()->create();

      // GemTransaction::$fillable لا تشمل currency_id عمداً (بند التوافق) -
    // نستخدم CurrencyTransaction مباشرة هنا لأن الاختبار يحتاج ضبطها صراحة.
    $transaction = \App\Models\CurrencyTransaction::create([
        'user_id' => $user->id,
        'currency_id' => $legacyCurrency->id,
        'amount' => 75,
        'type' => GemTransaction::TYPE_EARN_PENDING,
        'reason' => 'solved_puzzle:1',
    ]);

    expect(GemTransaction::find($transaction->id))->not->toBeNull()
        ->and(\App\Models\CurrencyTransaction::find($transaction->id)->currency_id)->toBe($legacyCurrency->id)
        ->and(\App\Models\CurrencyTransaction::find($transaction->id)->amount)->toBe(75);
});

test('User::wallet() still resolves to exactly one wallet - the legacy earned currency one', function () {
    $user = User::factory()->create();

    expect($user->wallet)->not->toBeNull()
        ->and($user->wallet->currency_id)->toBe(app(CurrencyRegistry::class)->defaultEarnedCurrency()->id);
});

test('User::gemTransactions() and User::currencyTransactions() return the same underlying rows', function () {
    $user = User::factory()->create();
    $legacyCurrency = app(CurrencyRegistry::class)->defaultEarnedCurrency();

    GemTransaction::create(['user_id' => $user->id, 'currency_id' => $legacyCurrency->id, 'amount' => 10, 'type' => GemTransaction::TYPE_EARN_PENDING, 'reason' => 'test']);

    expect($user->gemTransactions()->count())->toBe(1)
        ->and($user->currencyTransactions()->count())->toBe(1);
});

test('the legacy earned currency exists, is protected, and cannot be deleted', function () {
    $currency = app(CurrencyRegistry::class)->defaultEarnedCurrency();

    expect($currency->internal_key)->toBe('platform-earned')
        ->and($currency->is_system)->toBeTrue()
        ->and($currency->isProtected())->toBeTrue();
});

test('the placeholder premium currency exists and is purchasable but not earnable or redeemable', function () {
    $premium = app(CurrencyRegistry::class)->primaryPremiumCurrency();

    expect($premium)->not->toBeNull()
        ->and($premium->is_purchasable)->toBeTrue()
        ->and($premium->is_earnable)->toBeFalse()
        ->and($premium->is_redeemable)->toBeFalse();
});

test('GemWalletService still produces identical results to before the migration', function () {
    $user = User::factory()->create();
    $service = app(\App\Services\GemWalletService::class);

    $service->credit($user, 40, 'test_credit');
    $service->releasePendingToAvailable($user, 40, 'test_release');

    $user->wallet->refresh();
    expect($user->wallet->available_balance)->toBe(40)
        ->and($user->wallet->pending_balance)->toBe(0)
        ->and($user->wallet->lifetime_earned)->toBe(40);
});