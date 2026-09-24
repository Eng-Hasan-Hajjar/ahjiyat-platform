<?php

use App\Models\Currency;
use App\Models\CurrencyTransaction;
use App\Models\User;
use App\Services\Economy\CurrencyWalletService;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    $this->wallets = app(CurrencyWalletService::class);
    $this->currency = Currency::factory()->create();
});

test('every successful mutation creates exactly one correct ledger entry', function () {
    $user = User::factory()->create();

    $this->wallets->creditPending($user, $this->currency, 30, 'test');

    expect(CurrencyTransaction::where('user_id', $user->id)->where('currency_id', $this->currency->id)->count())->toBe(1);

    $transaction = CurrencyTransaction::where('user_id', $user->id)->first();
    expect($transaction->amount)->toBe(30)
        ->and($transaction->type)->toBe(CurrencyTransaction::TYPE_EARN_PENDING);
});

test('adjust never allows the available balance to go negative', function () {
    $user = User::factory()->create();
    $this->wallets->creditAvailable($user, $this->currency, 20, 'test');

    expect(fn () => $this->wallets->adjust($user, $this->currency, -50, 'test'))
        ->toThrow(RuntimeException::class);

    expect($this->wallets->balanceFor($user, $this->currency)->available_balance)->toBe(20);
});

test('zero amount is rejected for credit and adjust', function () {
    $user = User::factory()->create();

    expect(fn () => $this->wallets->creditAvailable($user, $this->currency, 0, 'test'))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => $this->wallets->adjust($user, $this->currency, 0, 'test'))
        ->toThrow(InvalidArgumentException::class);
});

test('an idempotency key prevents double-crediting on a repeated call', function () {
    $user = User::factory()->create();

    $first = $this->wallets->creditAvailable($user, $this->currency, 100, 'test', idempotencyKey: 'webhook-abc-123');
    $second = $this->wallets->creditAvailable($user, $this->currency, 100, 'test', idempotencyKey: 'webhook-abc-123');

    expect($first->id)->toBe($second->id)
        ->and($this->wallets->balanceFor($user, $this->currency)->available_balance)->toBe(100)
        ->and(CurrencyTransaction::where('idempotency_key', 'webhook-abc-123')->count())->toBe(1);
});

test('concurrent-style sequential debits never push the balance below zero', function () {
    $user = User::factory()->create();
    $this->wallets->creditAvailable($user, $this->currency, 100, 'test');

    $this->wallets->debitAvailable($user, $this->currency, 60, 'test1');

    expect(fn () => $this->wallets->debitAvailable($user, $this->currency, 60, 'test2'))
        ->toThrow(RuntimeException::class);

    expect($this->wallets->balanceFor($user, $this->currency)->available_balance)->toBe(40);
});