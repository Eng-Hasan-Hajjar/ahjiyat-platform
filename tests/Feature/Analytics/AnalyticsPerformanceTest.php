<?php

use App\Models\Puzzle;
use App\Models\PuzzleAttempt;
use App\Models\User;
use App\Services\Analytics\PuzzleAnalyticsService;
use App\Services\Analytics\UserAnalyticsService;
use App\Support\AnalyticsPeriod;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
});

test('user overview does not run a query per user (aggregation stays inside the database)', function () {
    User::factory()->count(30)->create();

    DB::enableQueryLog();
    app(UserAnalyticsService::class)->overview(AnalyticsPeriod::fromPreset('last_30_days'));
    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queryCount)->toBeLessThan(10);
});

test('puzzle overview aggregates in the database rather than loading every attempt into PHP', function () {
    $puzzle = Puzzle::factory()->create();
    PuzzleAttempt::factory()->count(50)->create(['puzzle_id' => $puzzle->id, 'created_at' => now()]);

    DB::enableQueryLog();
    app(PuzzleAnalyticsService::class)->overview(AnalyticsPeriod::fromPreset('last_30_days'));
    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queryCount)->toBeLessThan(6);
});

test('top puzzles query does not issue one query per puzzle', function () {
    Puzzle::factory()->count(10)->create()->each(function (Puzzle $puzzle) {
        PuzzleAttempt::factory()->count(6)->create(['puzzle_id' => $puzzle->id, 'created_at' => now()]);
    });

    DB::enableQueryLog();
    app(PuzzleAnalyticsService::class)->topPuzzles(AnalyticsPeriod::fromPreset('last_30_days'));
    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queryCount)->toBeLessThan(6);
});