<?php

use App\Models\Achievement;
use App\Models\CurrencyTransaction;
use App\Models\Puzzle;
use App\Models\User;
use App\Services\Economy\CurrencyRegistry;
use App\Services\GemWalletService;
use App\Services\Progression\AchievementService;
use App\Services\PuzzleAttemptService;

test('E12.1 CRITICAL: a free player\'s progression reward genuinely becomes spendable - earn, pending, release, available, spend', function () {
    $user = User::factory()->create();
    $currency = app(CurrencyRegistry::class)->defaultEarnedCurrency();
    $gems = app(GemWalletService::class);

    $achievement = Achievement::factory()->puzzlesSolvedTotal(1)->create([
        'reward_currency_id' => $currency->id,
        'reward_currency_amount' => 15,
    ]);
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح', 'gem_reward' => 5]);
    app(PuzzleAttemptService::class)->attempt($user, $puzzle, 'صح');
    app(AchievementService::class)->evaluateForEvent('puzzle_solved', $user);

    $wallet = fn () => $user->wallets()->where('currency_id', $currency->id)->first();

    expect($wallet()->pending_balance)->toBe(20)
        ->and($wallet()->available_balance)->toBe(0);

    CurrencyTransaction::where('user_id', $user->id)
        ->where('type', CurrencyTransaction::TYPE_EARN_PENDING)
        ->update(['created_at' => now()->subDays((int) config('gems.pending_hold_days') + 1)]);

    $holdDays = (int) config('gems.pending_hold_days');
    $releasable = CurrencyTransaction::where('user_id', $user->id)
        ->where('currency_id', $currency->id)
        ->where('type', CurrencyTransaction::TYPE_EARN_PENDING)
        ->where('created_at', '<=', now()->subDays($holdDays))
        ->sum('amount');
    $alreadyReleased = CurrencyTransaction::where('user_id', $user->id)
        ->where('currency_id', $currency->id)
        ->where('type', CurrencyTransaction::TYPE_RELEASE_AVAILABLE)
        ->sum('amount');
    $toRelease = $releasable - $alreadyReleased;

    expect($toRelease)->toBe(20);

    $gems->releasePendingToAvailable($user, (int) $toRelease, 'pending_hold_expired');

    expect($wallet()->available_balance)->toBe(20)
        ->and($wallet()->pending_balance)->toBe(0);

    $spendablePuzzle = Puzzle::factory()->create(['hint' => 'تلميح حقيقي']);
    $hintCost = (int) config('gems.hint_cost');
    $hint = app(PuzzleAttemptService::class)->purchaseHint($user, $spendablePuzzle);

    expect($hint)->toBe('تلميح حقيقي')
        ->and($wallet()->available_balance)->toBe(20 - $hintCost);
});

test('a fresh user\'s progression reward stays pending before the hold period elapses - not prematurely spendable', function () {
    $user = User::factory()->create();
    $currency = app(CurrencyRegistry::class)->defaultEarnedCurrency();

    $achievement = Achievement::factory()->puzzlesSolvedTotal(1)->create([
        'reward_currency_id' => $currency->id,
        'reward_currency_amount' => 15,
    ]);
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح', 'gem_reward' => 0]);
    app(PuzzleAttemptService::class)->attempt($user, $puzzle, 'صح');
    app(AchievementService::class)->evaluateForEvent('puzzle_solved', $user);

    $holdDays = (int) config('gems.pending_hold_days');
    $releasable = CurrencyTransaction::where('user_id', $user->id)
        ->where('currency_id', $currency->id)
        ->where('type', CurrencyTransaction::TYPE_EARN_PENDING)
        ->where('created_at', '<=', now()->subDays($holdDays))
        ->sum('amount');

    expect($releasable)->toBe(0);

    $wallet = $user->wallets()->where('currency_id', $currency->id)->first();
    expect($wallet->pending_balance)->toBe(15)
        ->and($wallet->available_balance)->toBe(0);
});