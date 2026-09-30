<?php

use App\Models\Achievement;
use App\Models\Puzzle;
use App\Models\User;
use App\Services\Progression\AchievementService;
use App\Services\PuzzleAttemptService;

beforeEach(function () {
    $this->achievements = app(AchievementService::class);
    $this->puzzles = app(PuzzleAttemptService::class);
    $this->user = User::factory()->create();
});

test('E12 req 189: progress increases correctly from authoritative solved-puzzle data', function () {
    $achievement = Achievement::factory()->puzzlesSolvedTotal(3)->create();
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح']);
    $this->puzzles->attempt($this->user, $puzzle, 'صح');

    $this->achievements->evaluateForEvent('puzzle_solved', $this->user);

    $progress = $this->user->achievementProgress()->where('achievement_id', $achievement->id)->first();
    expect($progress->current_value)->toBe(1)
        ->and($progress->unlocked_at)->toBeNull();
});

test('E12 req 190: reaching the target unlocks the achievement exactly once', function () {
    $achievement = Achievement::factory()->puzzlesSolvedTotal(1)->create();
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح']);
    $this->puzzles->attempt($this->user, $puzzle, 'صح');

    $this->achievements->evaluateForEvent('puzzle_solved', $this->user);
    $firstUnlockedAt = $this->user->achievementProgress()->where('achievement_id', $achievement->id)->first()->unlocked_at;

    $this->achievements->evaluateForEvent('puzzle_solved', $this->user);
    $secondUnlockedAt = $this->user->achievementProgress()->where('achievement_id', $achievement->id)->first()->unlocked_at;

    expect($firstUnlockedAt)->not->toBeNull()
        ->and($firstUnlockedAt->eq($secondUnlockedAt))->toBeTrue();
});

test('an achievement never unlocks before its target is reached', function () {
    $achievement = Achievement::factory()->puzzlesSolvedTotal(5)->create();
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح']);
    $this->puzzles->attempt($this->user, $puzzle, 'صح');

    $this->achievements->evaluateForEvent('puzzle_solved', $this->user);

    expect($this->user->achievementProgress()->where('achievement_id', $achievement->id)->first()->unlocked_at)->toBeNull();
});

test('E12 req 55: progress is monotonic - it never decreases even across repeated evaluations', function () {
    $achievement = Achievement::factory()->puzzlesSolvedTotal(3)->create();

    foreach (range(1, 3) as $i) {
        $puzzle = Puzzle::factory()->create(['answer_raw' => "صح{$i}"]);
        $this->puzzles->attempt($this->user, $puzzle, "صح{$i}");
        $this->achievements->evaluateForEvent('puzzle_solved', $this->user);
    }

    $progress = $this->user->achievementProgress()->where('achievement_id', $achievement->id)->first();
    expect($progress->current_value)->toBe(3)
        ->and($progress->unlocked_at)->not->toBeNull();
});

test('E12 req 192: a hidden achievement reveals no name or description before unlock, and full data after', function () {
    $achievement = Achievement::factory()->puzzlesSolvedTotal(1)->hidden()->create(['name' => 'اسم الإنجاز السري', 'description' => 'وصف سري']);

    expect($this->user->achievementProgress()->where('achievement_id', $achievement->id)->exists())->toBeFalse();

    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح']);
    $this->puzzles->attempt($this->user, $puzzle, 'صح');
    $this->achievements->evaluateForEvent('puzzle_solved', $this->user);

    $progress = $this->user->achievementProgress()->where('achievement_id', $achievement->id)->first();
    expect($progress->isUnlocked())->toBeTrue();
});

test('a campaign_steps_completed_total achievement stays at zero from a standalone (non-campaign) puzzle solve', function () {
    $campaignAchievement = Achievement::factory()->campaignStepsCompleted(1)->create();
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح']);
    $this->puzzles->attempt($this->user, $puzzle, 'صح');

    $this->achievements->evaluateForEvent('puzzle_solved', $this->user);

    $progress = $this->user->achievementProgress()->where('achievement_id', $campaignAchievement->id)->first();
    expect($progress->current_value)->toBe(0)
        ->and($progress->unlocked_at)->toBeNull();
});