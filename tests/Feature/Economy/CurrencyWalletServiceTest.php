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

// ===== E9.1: Currency validity invariants (centrally enforced) =====

test('creditPending rejects an inactive currency', function () {
    $user = User::factory()->create();
    $currency = Currency::factory()->create(['is_active' => false]);

    expect(fn () => $this->wallets->creditPending($user, $currency, 10, 'test'))
        ->toThrow(RuntimeException::class);

    expect(CurrencyTransaction::where('currency_id', $currency->id)->count())->toBe(0);
});

test('creditPending rejects a non-earnable currency', function () {
    $user = User::factory()->create();
    $currency = Currency::factory()->create(['is_earnable' => false]);

    expect(fn () => $this->wallets->creditPending($user, $currency, 10, 'test'))
        ->toThrow(RuntimeException::class);
});

test('creditPending rejects a currency before its starts_at', function () {
    $user = User::factory()->create();
    $currency = Currency::factory()->create(['starts_at' => now()->addDay()]);

    expect(fn () => $this->wallets->creditPending($user, $currency, 10, 'test'))
        ->toThrow(RuntimeException::class);
});

test('creditPending rejects a currency after its ends_at', function () {
    $user = User::factory()->create();
    $currency = Currency::factory()->create(['ends_at' => now()->subDay()]);

    expect(fn () => $this->wallets->creditPending($user, $currency, 10, 'test'))
        ->toThrow(RuntimeException::class);
});

test('creditPending rejects an expired currency', function () {
    $user = User::factory()->create();
    $currency = Currency::factory()->create(['expires_at' => now()->subDay()]);

    expect(fn () => $this->wallets->creditPending($user, $currency, 10, 'test'))
        ->toThrow(RuntimeException::class);
});

test('debitAvailable rejects a non-spendable currency', function () {
    $user = User::factory()->create();
    $currency = Currency::factory()->create(['is_spendable' => false]);
    $this->wallets->creditAvailable($user, $currency, 50, 'setup'); // creditAvailable لا تخضع لهذا الفحص عمداً - إعداد الاختبار فقط

    expect(fn () => $this->wallets->debitAvailable($user, $currency, 10, 'test'))
        ->toThrow(RuntimeException::class);

    expect($this->wallets->balanceFor($user, $currency)->available_balance)->toBe(50);
});

test('debitAvailable rejects an expired currency', function () {
    $user = User::factory()->create();
    $currency = Currency::factory()->create();
    $this->wallets->creditAvailable($user, $currency, 50, 'setup');
    $currency->update(['expires_at' => now()->subDay()]);

    expect(fn () => $this->wallets->debitAvailable($user, $currency->fresh(), 10, 'test'))
        ->toThrow(RuntimeException::class);
});

test('admin adjustment still works on an inactive currency - deliberate documented bypass', function () {
    $user = User::factory()->create();
    $currency = Currency::factory()->create(['is_active' => false]);

    $this->wallets->adjust($user, $currency, 100, 'تعويض إداري رغم تعطيل العملة');

    expect($this->wallets->balanceFor($user, $currency)->available_balance)->toBe(100);
});

test('refund still works regardless of currency spendability - a reserved balance must always be returnable', function () {
    $user = User::factory()->create();
    $currency = Currency::factory()->create(['is_spendable' => false]);

    $this->wallets->refund($user, $currency, 75, 'استرجاع رغم تعطيل الصرف');

    expect($this->wallets->balanceFor($user, $currency)->available_balance)->toBe(75);
});

// ===== E9.1: releasePending hardening =====

test('releasePending rejects a negative amount', function () {
    $user = User::factory()->create();

    expect(fn () => $this->wallets->releasePending($user, $this->currency, -10, 'test'))
        ->toThrow(InvalidArgumentException::class);
});

test('releasePending rejects a zero amount', function () {
    $user = User::factory()->create();

    expect(fn () => $this->wallets->releasePending($user, $this->currency, 0, 'test'))
        ->toThrow(InvalidArgumentException::class);
});

test('releasePending with nothing actually pending returns null and creates no zero-amount transaction', function () {
    $user = User::factory()->create();
    $this->wallets->balanceFor($user, $this->currency); // ينشئ Wallet برصيد معلَّق صفر

    $result = $this->wallets->releasePending($user, $this->currency, 10, 'test');

    expect($result)->toBeNull()
        ->and(CurrencyTransaction::where('user_id', $user->id)->where('type', CurrencyTransaction::TYPE_RELEASE_AVAILABLE)->count())->toBe(0)
        ->and($this->wallets->balanceFor($user, $this->currency)->available_balance)->toBe(0);
});

test('releasePending caps at the actual pending balance without ever going negative', function () {
    $user = User::factory()->create();
    $this->wallets->creditPending($user, $this->currency, 20, 'test');

    $result = $this->wallets->releasePending($user, $this->currency, 50, 'test');

    expect($result->amount)->toBe(20)
        ->and($this->wallets->balanceFor($user, $this->currency)->pending_balance)->toBe(0)
        ->and($this->wallets->balanceFor($user, $this->currency)->available_balance)->toBe(20);
});

// ===== E9.1: Idempotency conflict detection =====

test('reusing an idempotency key for a materially different operation throws a conflict error', function () {
    $user = User::factory()->create();
    $otherCurrency = Currency::factory()->create();

    $this->wallets->creditAvailable($user, $this->currency, 100, 'test', idempotencyKey: 'shared-key-1');

    expect(fn () => $this->wallets->creditAvailable($user, $this->currency, 999, 'test', idempotencyKey: 'shared-key-1'))
        ->toThrow(RuntimeException::class);

    expect(fn () => $this->wallets->creditAvailable($user, $otherCurrency, 100, 'test', idempotencyKey: 'shared-key-1'))
        ->toThrow(RuntimeException::class);

    expect($this->wallets->balanceFor($user, $this->currency)->available_balance)->toBe(100);
});