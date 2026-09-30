<?php

use App\Models\Achievement;
use App\Models\LevelDefinition;
use App\Models\Puzzle;
use App\Models\User;
use App\Services\Analytics\ProgressionAnalyticsService;
use App\Services\Progression\AchievementService;
use App\Services\PuzzleAttemptService;
use App\Support\AnalyticsPeriod;

beforeEach(function () {
    LevelDefinition::factory()->first()->create();
    LevelDefinition::factory()->create(['level_number' => 2, 'xp_required_total' => 100, 'name' => 'مستوى 2']);
    $this->analytics = app(ProgressionAnalyticsService::class);
});

test('E12 req 215: level distribution counts users at each level correctly', function () {
    $userAtLevel1 = User::factory()->create();
    app(\App\Services\Progression\LevelService::class)->progressionFor($userAtLevel1);
    $userAtLevel2 = User::factory()->create();
    app(\App\Services\Progression\XpService::class)->grantXp($userAtLevel2, 100, \App\Models\XpTransaction::TYPE_PUZZLE_SOLVE, 'test');

    $distribution = collect($this->analytics->levelDistribution())->keyBy('level_number');

    expect($distribution[1]['users_count'])->toBe(1)
        ->and($distribution[2]['users_count'])->toBe(1);
});

test('xp earned total and active progression users are correct for a period', function () {
    $puzzles = app(PuzzleAttemptService::class);
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    $puzzles->attempt($userA, Puzzle::factory()->create(['answer_raw' => 'ص', 'xp_reward' => 10]), 'ص');
    $puzzles->attempt($userB, Puzzle::factory()->create(['answer_raw' => 'ص', 'xp_reward' => 15]), 'ص');

    $overview = $this->analytics->overview(AnalyticsPeriod::fromPreset('last_30_days'));

    expect($overview['xp_earned_total'])->toBe(25)
        ->and($overview['active_progression_users'])->toBe(2);
});

test('E12 req 148/305: analytics never mixes premium spending or store revenue into progression numbers', function () {
    $overview = $this->analytics->overview(AnalyticsPeriod::fromPreset('last_30_days'));

    expect($overview)->not->toHaveKey('premium_spent')
        ->and($overview)->not->toHaveKey('store_revenue')
        ->and($overview)->not->toHaveKey('currency_purchased');
});

test('top unlocked achievements returns the correct unlock counts', function () {
    $achievement = Achievement::factory()->puzzlesSolvedTotal(1)->create(['name' => 'الإنجاز الأكثر فتحًا']);
    $puzzles = app(PuzzleAttemptService::class);
    $achievements = app(AchievementService::class);

    foreach (range(1, 3) as $i) {
        $user = User::factory()->create();
        $puzzles->attempt($user, Puzzle::factory()->create(['answer_raw' => "ص{$i}"]), "ص{$i}");
        $achievements->evaluateForEvent('puzzle_solved', $user);
    }

    $top = collect($this->analytics->topUnlockedAchievements())->firstWhere('name', 'الإنجاز الأكثر فتحًا');
    expect($top['unlocks'])->toBe(3);
});