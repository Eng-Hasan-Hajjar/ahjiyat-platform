<?php

use App\Models\Puzzle;
use App\Models\PuzzleAttempt;
use App\Models\User;
use App\Services\Analytics\UserAnalyticsService;
use App\Support\AnalyticsPeriod;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
});

test('total user count is correct', function () {
    User::factory()->count(4)->create();

    expect(app(UserAnalyticsService::class)->overview(AnalyticsPeriod::fromPreset('last_30_days'))['total_users'])
        ->toBe(4);
});

test('new users within the date range are counted correctly', function () {
    User::factory()->create(['created_at' => now()->subDays(2)]);
    User::factory()->create(['created_at' => now()->subDays(40)]);

    $overview = app(UserAnalyticsService::class)->overview(AnalyticsPeriod::fromPreset('last_30_days'));

    expect($overview['new_users'])->toBe(1)
        ->and($overview['total_users'])->toBe(2);
});

test('verified and frozen counts are correct', function () {
    User::factory()->create(['email_verified_at' => now()]);
    User::factory()->create(['email_verified_at' => null]);
    User::factory()->create(['email_verified_at' => now(), 'is_frozen' => true]);

    $overview = app(UserAnalyticsService::class)->overview(AnalyticsPeriod::fromPreset('last_30_days'));

    expect($overview['verified_users'])->toBe(2)
        ->and($overview['unverified_users'])->toBe(1)
        ->and($overview['frozen_users'])->toBe(1);
});

test('active user count only counts distinct users with an attempt in the period, not attempts', function () {
    $user = User::factory()->create();
    $puzzle = Puzzle::factory()->create();

    PuzzleAttempt::factory()->count(3)->create(['user_id' => $user->id, 'puzzle_id' => $puzzle->id, 'created_at' => now()]);

    $period = AnalyticsPeriod::fromPreset('last_30_days');

    expect(app(UserAnalyticsService::class)->activeUserCount($period))->toBe(1);
});

test('an empty period returns zero, not an error', function () {
    $overview = app(UserAnalyticsService::class)->overview(AnalyticsPeriod::fromPreset('today'));

    expect($overview['new_users'])->toBe(0)
        ->and($overview['active_users'])->toBe(0);
});

test('registration growth buckets sum to the total new users in the period', function () {
    User::factory()->count(3)->create(['created_at' => now()->subDays(2)]);

    $growth = app(UserAnalyticsService::class)->registrationGrowth(AnalyticsPeriod::fromPreset('last_30_days'));

    expect(array_sum($growth['values']))->toBe(3);
});