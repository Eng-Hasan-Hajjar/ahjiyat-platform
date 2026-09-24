<?php

use App\Models\Currency;
use App\Models\Puzzle;
use App\Models\User;
use App\Services\Economy\CurrencyRegistry;
use App\Services\PuzzleAttemptService;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    $this->service = app(PuzzleAttemptService::class);
});

test('a puzzle with no reward_currency_id awards the default earned currency', function () {
    $user = User::factory()->create();
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح', 'gem_reward' => 15]);

    $result = $this->service->attempt($user, $puzzle, 'صح');

    expect($result['correct'])->toBeTrue()
        ->and($result['gems_awarded'])->toBe(15);

    $legacy = app(CurrencyRegistry::class)->defaultEarnedCurrency();
    expect($user->wallet->fresh()->currency_id)->toBe($legacy->id)
        ->and($user->wallet->fresh()->pending_balance)->toBe(15);
});

test('a puzzle with an explicit reward_currency_id awards that currency instead, exactly once', function () {
    $eventCurrency = Currency::factory()->create();
    $user = User::factory()->create();
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح', 'gem_reward' => 25, 'reward_currency_id' => $eventCurrency->id]);

    $result = $this->service->attempt($user, $puzzle, 'صح');

    expect($result['correct'])->toBeTrue()
        ->and($result['gems_awarded'])->toBe(25);

    $eventWallet = \App\Models\Wallet::where('user_id', $user->id)->where('currency_id', $eventCurrency->id)->first();
    expect($eventWallet->pending_balance)->toBe(25);

    expect($user->wallet->fresh()->pending_balance)->toBe(0);
});

test('solving the same puzzle twice never awards a reward twice', function () {
    $user = User::factory()->create();
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح', 'gem_reward' => 10]);

    $this->service->attempt($user, $puzzle, 'صح');

    expect(fn () => $this->service->attempt($user, $puzzle, 'صح'))->toThrow(RuntimeException::class);
    expect($user->wallet->fresh()->pending_balance)->toBe(10);
});