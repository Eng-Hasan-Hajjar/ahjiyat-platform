<?php

use App\Models\Puzzle;
use App\Models\PuzzleCategory;
use App\Models\QuestDefinition;
use App\Models\User;
use App\Models\UserQuestProgress;
use App\Services\Engagement\EngagementService;
use App\Services\Engagement\QuestService;
use App\Services\PuzzleAttemptService;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->quests = app(QuestService::class);
    $this->engagement = app(EngagementService::class);
    $this->puzzles = app(PuzzleAttemptService::class);
    $this->user = User::factory()->create();
});

afterEach(function () {
    Carbon::setTestNow();
});

function e13SolvePuzzleFor(User $user, PuzzleAttemptService $service, ?PuzzleCategory $category = null): void
{
    $puzzle = Puzzle::factory()->create(array_filter([
        'answer_raw' => 'صح',
        'xp_reward' => 0,
        'gem_reward' => 0,
        'puzzle_category_id' => $category?->id,
    ]));
    $service->attempt($user, $puzzle, 'صح');
}

test('E13 req 168-170: no progress row exists until first relevant event or explicit sync', function () {
    QuestDefinition::factory()->daily(3)->create();

    expect(UserQuestProgress::count())->toBe(0);
});

test('E13 req 78-79: current-period authoritative count reflects real prior gameplay, not an in-memory counter', function () {
    $quest = QuestDefinition::factory()->daily(3)->create();

    e13SolvePuzzleFor($this->user, $this->puzzles);
    e13SolvePuzzleFor($this->user, $this->puzzles);

    $progress = UserQuestProgress::where('user_id', $this->user->id)->where('quest_definition_id', $quest->id)->first();

    expect($progress->current_value)->toBe(2);
});

test('E13 req 238: daily and weekly quests both progress from the same single puzzle solve', function () {
    $daily = QuestDefinition::factory()->daily(5)->create();
    $weekly = QuestDefinition::factory()->weekly(10)->create();

    e13SolvePuzzleFor($this->user, $this->puzzles);

    $dailyProgress = UserQuestProgress::where('user_id', $this->user->id)->where('quest_definition_id', $daily->id)->first();
    $weeklyProgress = UserQuestProgress::where('user_id', $this->user->id)->where('quest_definition_id', $weekly->id)->first();

    expect($dailyProgress->current_value)->toBe(1)
        ->and($weeklyProgress->current_value)->toBe(1);
});

test('E13 req 194: a wrong attempt grants zero quest progress', function () {
    $quest = QuestDefinition::factory()->daily(1)->create();
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح']);

    $this->puzzles->attempt($this->user, $puzzle, 'إجابة خاطئة');

    expect(UserQuestProgress::where('quest_definition_id', $quest->id)->exists())->toBeFalse();
});

test('E13 req 352: activity before starts_at is excluded from the count', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 08:00:00', 'UTC'));
    $quest = QuestDefinition::factory()->daily(5)->create(['starts_at' => Carbon::parse('2026-10-05 12:00:00', 'UTC')]);

    e13SolvePuzzleFor($this->user, $this->puzzles); // قبل starts_at

    Carbon::setTestNow(Carbon::parse('2026-10-05 13:00:00', 'UTC'));
    e13SolvePuzzleFor($this->user, $this->puzzles); // بعد starts_at

    $progress = UserQuestProgress::where('user_id', $this->user->id)->where('quest_definition_id', $quest->id)->first();

    expect($progress->current_value)->toBe(1);
});

test('E13 req 353: activity after ends_at is excluded from the count', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 08:00:00', 'UTC'));
    $quest = QuestDefinition::factory()->daily(5)->create(['ends_at' => Carbon::parse('2026-10-05 10:00:00', 'UTC')]);

    e13SolvePuzzleFor($this->user, $this->puzzles); // قبل ends_at

    Carbon::setTestNow(Carbon::parse('2026-10-05 11:00:00', 'UTC'));
    e13SolvePuzzleFor($this->user, $this->puzzles); // بعد ends_at - لكن ما زالت بنفس اليوم فلن تُنشِئ معاملة بفترة جديدة

    $progress = UserQuestProgress::where('user_id', $this->user->id)->where('quest_definition_id', $quest->id)->first();

    expect($progress->current_value)->toBe(1);
});

test('E13 req 171: starts_at null means current-period activity is counted immediately upon activation (mid-period)', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 08:00:00', 'UTC'));
    e13SolvePuzzleFor($this->user, $this->puzzles); // قبل إنشاء التعريف إطلاقًا

    $quest = QuestDefinition::factory()->daily(1)->create(); // starts_at = null

    $this->quests->evaluateQuest($this->user, $quest);

    $progress = UserQuestProgress::where('user_id', $this->user->id)->where('quest_definition_id', $quest->id)->first();

    expect($progress->current_value)->toBe(1)
        ->and($progress->completed_at)->not->toBeNull();
});

test('E13 req 349: an old incomplete daily quest does not complete from a later, new-day activity', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 08:00:00', 'UTC'));
    $quest = QuestDefinition::factory()->daily(3)->create();
    e13SolvePuzzleFor($this->user, $this->puzzles);

    $oldProgress = UserQuestProgress::where('user_id', $this->user->id)->where('quest_definition_id', $quest->id)->first();
    expect($oldProgress->current_value)->toBe(1)->and($oldProgress->completed_at)->toBeNull();

    Carbon::setTestNow(Carbon::parse('2026-10-06 08:00:00', 'UTC'));
    e13SolvePuzzleFor($this->user, $this->puzzles);
    e13SolvePuzzleFor($this->user, $this->puzzles);
    e13SolvePuzzleFor($this->user, $this->puzzles);

    expect($oldProgress->fresh()->current_value)->toBe(1)
        ->and($oldProgress->fresh()->completed_at)->toBeNull();

    $newProgress = UserQuestProgress::where('user_id', $this->user->id)->where('quest_definition_id', $quest->id)
        ->where('period_key', 'daily:2026-10-06')->first();
    expect($newProgress->current_value)->toBe(3)->and($newProgress->completed_at)->not->toBeNull();
});

test('E13 req 29: unique(user, quest, period_key) prevents a duplicate progress row for the same period', function () {
    $quest = QuestDefinition::factory()->daily(5)->create();
    e13SolvePuzzleFor($this->user, $this->puzzles);
    e13SolvePuzzleFor($this->user, $this->puzzles);

    expect(UserQuestProgress::where('user_id', $this->user->id)->where('quest_definition_id', $quest->id)->count())->toBe(1);
});

test('E13 req 243: category-scoped quest only counts solves within that category', function () {
    $logic = PuzzleCategory::factory()->create();
    $math = PuzzleCategory::factory()->create();
    $quest = QuestDefinition::factory()->create([
        'period_type' => QuestDefinition::PERIOD_DAILY,
        'condition_type' => \App\Services\Engagement\QuestEvaluatorRegistry::PUZZLES_SOLVED_IN_CATEGORY,
        'target_value' => 2,
        'scope_type' => 'puzzle_category',
        'scope_id' => $logic->id,
    ]);

    e13SolvePuzzleFor($this->user, $this->puzzles, $logic);
    e13SolvePuzzleFor($this->user, $this->puzzles, $math);

    $progress = UserQuestProgress::where('user_id', $this->user->id)->where('quest_definition_id', $quest->id)->first();

    expect($progress->current_value)->toBe(1);
});
