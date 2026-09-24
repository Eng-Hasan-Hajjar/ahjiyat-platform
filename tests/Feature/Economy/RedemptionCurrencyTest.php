<?php

use App\Models\Currency;
use App\Models\User;
use App\Services\Economy\CurrencyRegistry;
use App\Services\Economy\CurrencyWalletService;
use App\Services\RedemptionService;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    $this->redemptions = app(RedemptionService::class);
    $this->wallets = app(CurrencyWalletService::class);
});

function makeE9EligibleUser(): User
{
    $eligibility = config('gems.eligibility');

    $user = User::factory()->create([
        'created_at' => now()->subDays($eligibility['min_account_age_days'] + 1),
    ]);

    for ($i = 0; $i < $eligibility['min_puzzles_solved']; $i++) {
        \App\Models\PuzzleAttempt::create([
            'user_id' => $user->id,
            'puzzle_id' => \App\Models\Puzzle::factory()->create()->id,
            'attempt_number' => 1,
            'is_correct' => true,
        ]);
    }

    return $user->fresh();
}

test('a request against a non-redeemable currency is rejected', function () {
    $user = makeE9EligibleUser();
    $premium = app(CurrencyRegistry::class)->primaryPremiumCurrency();
    $this->wallets->creditAvailable($user, $premium, 999999, 'test');

    expect(fn () => $this->redemptions->requestRedemption($user, 100, 'مكافأة', $premium))
        ->toThrow(RuntimeException::class);
});

test('a request against a redeemable currency with sufficient balance succeeds', function () {
    $user = makeE9EligibleUser();
    $legacy = app(CurrencyRegistry::class)->defaultEarnedCurrency();
    $this->wallets->creditAvailable($user, $legacy, config('gems.min_redemption'), 'test');

    $request = $this->redemptions->requestRedemption($user, config('gems.min_redemption'), 'مكافأة', $legacy);

    expect($request->currency_id)->toBe($legacy->id);
});

test('rejecting a redemption refunds the exact currency it was requested in, not the default one', function () {
    $user = makeE9EligibleUser();
    $eventCurrency = Currency::factory()->create(['is_redeemable' => true]);
    $this->wallets->creditAvailable($user, $eventCurrency, config('gems.min_redemption'), 'test');

    $request = $this->redemptions->requestRedemption($user, config('gems.min_redemption'), 'مكافأة', $eventCurrency);

    $admin = User::factory()->create();
    $this->redemptions->reject($request, $admin, 'سبب');

    expect($this->wallets->balanceFor($user, $eventCurrency)->available_balance)->toBe(config('gems.min_redemption'));
});