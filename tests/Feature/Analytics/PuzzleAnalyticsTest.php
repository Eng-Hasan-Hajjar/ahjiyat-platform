<?php

use App\Models\Puzzle;
use App\Models\PuzzleAttempt;
use App\Models\User;
use App\Services\Analytics\PuzzleAnalyticsService;
use App\Support\AnalyticsPeriod;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
});

test('total attempts and correct/incorrect breakdown are accurate', function () {
    $puzzle = Puzzle::factory()->create();
    PuzzleAttempt::factory()->count(3)->create(['puzzle_id' => $puzzle->id, 'is_correct' => true, 'created_at' => now()]);
    PuzzleAttempt::factory()->count(2)->create(['puzzle_id' => $puzzle->id, 'is_correct' => false, 'created_at' => now()]);

    $overview = app(PuzzleAnalyticsService::class)->overview(AnalyticsPeriod::fromPreset('last_30_days'));

    expect($overview['total_attempts'])->toBe(5)
        ->and($overview['correct_attempts'])->toBe(3)
        ->and($overview['incorrect_attempts'])->toBe(2);
});

test('success rate is calculated correctly', function () {
    $puzzle = Puzzle::factory()->create();
    PuzzleAttempt::factory()->count(4)->create(['puzzle_id' => $puzzle->id, 'is_correct' => true, 'created_at' => now()]);
    PuzzleAttempt::factory()->count(1)->create(['puzzle_id' => $puzzle->id, 'is_correct' => false, 'created_at' => now()]);

    $overview = app(PuzzleAnalyticsService::class)->overview(AnalyticsPeriod::fromPreset('last_30_days'));

    expect($overview['success_rate'])->toBe(80.0);
});

test('zero attempts does not divide by zero', function () {
    $overview = app(PuzzleAnalyticsService::class)->overview(AnalyticsPeriod::fromPreset('today'));

    expect($overview['success_rate'])->toBe(0.0)
        ->and($overview['total_attempts'])->toBe(0);
});

test('unique player count uses distinct users, not attempt count', function () {
    $user = User::factory()->create();
    $puzzle = Puzzle::factory()->create();
    PuzzleAttempt::factory()->count(5)->create(['user_id' => $user->id, 'puzzle_id' => $puzzle->id, 'created_at' => now()]);

    $overview = app(PuzzleAnalyticsService::class)->overview(AnalyticsPeriod::fromPreset('last_30_days'));

    expect($overview['unique_players'])->toBe(1);
});

test('a puzzle below the minimum sample size is excluded from success-rate rankings', function () {
    $puzzle = Puzzle::factory()->create(['title' => 'أحجية بمحاولة واحدة']);
    PuzzleAttempt::factory()->create(['puzzle_id' => $puzzle->id, 'is_correct' => true, 'created_at' => now()]);

    $top = app(PuzzleAnalyticsService::class)->topPuzzles(AnalyticsPeriod::fromPreset('last_30_days'));

    expect(collect($top['highest_success_rate'])->pluck('title'))->not->toContain('أحجية بمحاولة واحدة');
});

test('game type breakdown groups attempts correctly', function () {
    $classicPuzzle = Puzzle::factory()->create(['game_type' => null]);
    $spotDiffPuzzle = Puzzle::factory()->create(['game_type' => 'spot_difference']);

    PuzzleAttempt::factory()->count(2)->create(['puzzle_id' => $classicPuzzle->id, 'created_at' => now()]);
    PuzzleAttempt::factory()->count(3)->create(['puzzle_id' => $spotDiffPuzzle->id, 'created_at' => now()]);

    $breakdown = collect(app(PuzzleAnalyticsService::class)->gameTypeBreakdown(AnalyticsPeriod::fromPreset('last_30_days')));

    expect($breakdown->firstWhere('game_type', 'spot_difference')['attempts'])->toBe(3)
        ->and($breakdown->firstWhere('game_type', 'classic')['attempts'])->toBe(2);
});