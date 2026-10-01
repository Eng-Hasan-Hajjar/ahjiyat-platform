<?php

use App\Models\Puzzle;
use App\Models\QuestDefinition;
use App\Models\User;
use App\Services\Analytics\EngagementAnalyticsService;
use App\Services\PuzzleAttemptService;
use App\Support\AnalyticsPeriod;

beforeEach(function () {
    $this->analytics = app(EngagementAnalyticsService::class);
});

test('overview counts daily and weekly quest completions correctly for a period', function () {
    $daily = QuestDefinition::factory()->daily(1)->create();
    $user = User::factory()->create();
    app(PuzzleAttemptService::class)->attempt($user, Puzzle::factory()->create(['answer_raw' => 'صح']), 'صح');

    $overview = $this->analytics->overview(AnalyticsPeriod::fromPreset('last_30_days'));

    expect($overview['daily_quest_completions'])->toBe(1)
        ->and($overview['weekly_quest_completions'])->toBe(0);
});

test('E13 req 413: engagement analytics never mixes store revenue or premium spending', function () {
    $overview = $this->analytics->overview(AnalyticsPeriod::fromPreset('last_30_days'));

    expect($overview)->not->toHaveKey('store_revenue')
        ->and($overview)->not->toHaveKey('premium_spent')
        ->and($overview)->not->toHaveKey('currency_purchased');
});

test('top completed quests returns the correct completion counts', function () {
    $quest = QuestDefinition::factory()->daily(1)->create(['name' => 'الأكثر إكمالًا']);

    foreach (range(1, 3) as $i) {
        $user = User::factory()->create();
        app(PuzzleAttemptService::class)->attempt($user, Puzzle::factory()->create(['answer_raw' => "ص{$i}"]), "ص{$i}");
    }

    $top = collect($this->analytics->topCompletedQuests(AnalyticsPeriod::fromPreset('last_30_days')))->firstWhere('name', 'الأكثر إكمالًا');

    expect($top['completions'])->toBe(3);
});

test('active streak users count reflects only users with a current streak above zero', function () {
    $active = User::factory()->create();
    app(PuzzleAttemptService::class)->attempt($active, Puzzle::factory()->create(['answer_raw' => 'صح']), 'صح');

    User::factory()->create(); // بلا أي نشاط

    expect($this->analytics->activeStreakUsersCount())->toBe(1);
});
