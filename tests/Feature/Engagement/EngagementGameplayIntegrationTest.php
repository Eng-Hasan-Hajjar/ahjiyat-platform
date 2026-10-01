<?php

use App\Models\Puzzle;
use App\Models\PuzzleAttempt;
use App\Models\QuestDefinition;
use App\Models\User;
use App\Models\UserQuestProgress;
use App\Services\PuzzleAttemptService;

beforeEach(function () {
    $this->puzzles = app(PuzzleAttemptService::class);
    $this->user = User::factory()->create();
});

test('E13 req 438: a correct solve updates Daily progress, Weekly progress, and Streak from one single authoritative event', function () {
    $daily = QuestDefinition::factory()->daily(3)->create();
    $weekly = QuestDefinition::factory()->weekly(10)->create();
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح']);

    $this->puzzles->attempt($this->user, $puzzle, 'صح');

    $dailyProgress = UserQuestProgress::where('user_id', $this->user->id)->where('quest_definition_id', $daily->id)->first();
    $weeklyProgress = UserQuestProgress::where('user_id', $this->user->id)->where('quest_definition_id', $weekly->id)->first();
    $streak = \App\Models\PlayerStreak::where('user_id', $this->user->id)->first();

    expect($dailyProgress->current_value)->toBe(1)
        ->and($weeklyProgress->current_value)->toBe(1)
        ->and($streak->current_streak)->toBe(1);
});

test('E13 req 217: a wrong attempt updates none of Daily, Weekly, or Streak', function () {
    QuestDefinition::factory()->daily(3)->create();
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح']);

    $this->puzzles->attempt($this->user, $puzzle, 'خطأ');

    expect(UserQuestProgress::count())->toBe(0)
        ->and(\App\Models\PlayerStreak::where('user_id', $this->user->id)->exists())->toBeFalse();
});

test('E13 req 221/222: E13 does not grant puzzle XP itself - that remains exclusively E12 territory', function () {
    QuestDefinition::factory()->daily(3)->create(); // بلا مكافأة XP
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح', 'xp_reward' => 7]);

    $this->puzzles->attempt($this->user, $puzzle, 'صح');

    // XP الوحيدة يجب أن تكون من E12 (puzzle_solve)، لا أي إضافة مضاعَفة من E13.
    $xpTotal = \App\Models\XpTransaction::where('user_id', $this->user->id)->sum('amount');
    expect($xpTotal)->toBe(7);
});

test('E13 req 342: retrying the same solve request does not duplicate quest reward or streak increment', function () {
    $quest = QuestDefinition::factory()->daily(1)->withXpReward(10)->create();
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح']);

    $this->puzzles->attempt($this->user, $puzzle, 'صح');

    // محاولة "إعادة" لنفس السياق تُرفَض أصلًا ببنية المشروع (hasSolvedPuzzle) - نتحقق أن لا تكرار نتج فعليًا.
    try {
        $this->puzzles->attempt($this->user, $puzzle, 'صح');
    } catch (\Throwable $e) {
        // متوقَّع - النظام القائم يمنع إعادة حل نفس الأحجية بنفس السياق.
    }

    $progress = UserQuestProgress::where('user_id', $this->user->id)->where('quest_definition_id', $quest->id)->first();
    expect($progress->current_value)->toBe(1)
        ->and(\App\Models\PlayerStreak::where('user_id', $this->user->id)->first()->current_streak)->toBe(1);
});

test('E13 req 344/439: self-healing - authoritative rows exist without the hook having run, then sync restores correct progress', function () {
    $quest = QuestDefinition::factory()->daily(2)->create();

    // إنشاء PuzzleAttempt صحيحة مباشرة - محاكاة فشل الخطاف سابقًا بلا استدعائه إطلاقًا.
    $puzzle = Puzzle::factory()->create();
    PuzzleAttempt::create([
        'user_id' => $this->user->id, 'puzzle_id' => $puzzle->id, 'attempt_number' => 1,
        'is_correct' => true, 'used_hint' => false, 'submission_snapshot' => [],
        'context_type' => 'none', 'context_id' => 0,
    ]);

    expect(UserQuestProgress::where('quest_definition_id', $quest->id)->exists())->toBeFalse();

    app(\App\Services\Engagement\QuestService::class)->syncCurrentQuests($this->user);

    $progress = UserQuestProgress::where('user_id', $this->user->id)->where('quest_definition_id', $quest->id)->first();
    expect($progress->current_value)->toBe(1);
});
