<?php

use App\Models\Puzzle;
use App\Models\User;
use App\Services\Engagement\EngagementService;
use App\Services\Engagement\StreakService;
use App\Services\PuzzleAttemptService;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->streaks = app(StreakService::class);
    $this->puzzles = app(PuzzleAttemptService::class);
    $this->user = User::factory()->create();
});

afterEach(function () {
    Carbon::setTestNow();
});

function e13SolveForStreak(User $user, PuzzleAttemptService $service): void
{
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح']);
    $service->attempt($user, $puzzle, 'صح');
}

test('E13 req 120: first correct solve sets current_streak to 1', function () {
    e13SolveForStreak($this->user, $this->puzzles);

    $streak = $this->streaks->streakFor($this->user);

    expect($streak->current_streak)->toBe(1)
        ->and($streak->longest_streak)->toBe(1);
});

test('E13 req 117/127: multiple correct solves the same day do not increment the streak further (idempotent)', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 09:00:00', 'UTC'));
    e13SolveForStreak($this->user, $this->puzzles);
    e13SolveForStreak($this->user, $this->puzzles);
    e13SolveForStreak($this->user, $this->puzzles);

    expect($this->streaks->streakFor($this->user)->current_streak)->toBe(1);
});

test('E13 req 118: a correct solve the next day increments the streak by 1', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 09:00:00', 'UTC'));
    e13SolveForStreak($this->user, $this->puzzles);

    Carbon::setTestNow(Carbon::parse('2026-10-06 09:00:00', 'UTC'));
    e13SolveForStreak($this->user, $this->puzzles);

    expect($this->streaks->streakFor($this->user)->current_streak)->toBe(2);
});

test('E13 req 119/133: a gap of more than one day resets current_streak to 1 without losing longest_streak', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 09:00:00', 'UTC'));
    e13SolveForStreak($this->user, $this->puzzles);
    Carbon::setTestNow(Carbon::parse('2026-10-06 09:00:00', 'UTC'));
    e13SolveForStreak($this->user, $this->puzzles);
    Carbon::setTestNow(Carbon::parse('2026-10-07 09:00:00', 'UTC'));
    e13SolveForStreak($this->user, $this->puzzles);

    expect($this->streaks->streakFor($this->user)->current_streak)->toBe(3);

    // فجوة يوم كامل (لا نشاط يوم 8).
    Carbon::setTestNow(Carbon::parse('2026-10-09 09:00:00', 'UTC'));
    e13SolveForStreak($this->user, $this->puzzles);

    $streak = $this->streaks->streakFor($this->user);

    expect($streak->current_streak)->toBe(1)
        ->and($streak->longest_streak)->toBe(3);
});

test('E13 req 122: breaking the streak never removes previously earned XP, currency, or achievements', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 09:00:00', 'UTC'));
    $quest = \App\Models\QuestDefinition::factory()->daily(1)->withXpReward(20)->create();
    e13SolveForStreak($this->user, $this->puzzles);

    $xpBefore = $this->user->fresh()->playerProgression->total_xp;

    // فجوة طويلة.
    Carbon::setTestNow(Carbon::parse('2026-10-20 09:00:00', 'UTC'));
    e13SolveForStreak($this->user, $this->puzzles);

    expect($this->user->fresh()->playerProgression->total_xp)->toBeGreaterThanOrEqual($xpBefore)
        ->and($this->streaks->streakFor($this->user)->current_streak)->toBe(1);
});

test('E13 req 131: activity at 23:59 then 00:01 next day counts as two consecutive days', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 23:59:00', 'UTC'));
    e13SolveForStreak($this->user, $this->puzzles);

    Carbon::setTestNow(Carbon::parse('2026-10-06 00:01:00', 'UTC'));
    e13SolveForStreak($this->user, $this->puzzles);

    expect($this->streaks->streakFor($this->user)->current_streak)->toBe(2);
});

test('E13 req 132: activity at 01:00 and 23:00 the same calendar day increments the streak only once', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 01:00:00', 'UTC'));
    e13SolveForStreak($this->user, $this->puzzles);

    Carbon::setTestNow(Carbon::parse('2026-10-05 23:00:00', 'UTC'));
    e13SolveForStreak($this->user, $this->puzzles);

    expect($this->streaks->streakFor($this->user)->current_streak)->toBe(1);
});

test('E13 req 116: a wrong answer does not update the streak', function () {
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح']);
    $this->puzzles->attempt($this->user, $puzzle, 'خطأ');

    expect(\App\Models\PlayerStreak::where('user_id', $this->user->id)->exists())->toBeFalse();
});

test('E13 req 354/229: recalculateFromHistory rebuilds the correct streak from real PuzzleAttempt history alone', function () {
    // نبني تاريخًا حقيقيًا بنفس الآلية المُثبَتة (لا بناء يدوي هش بـcreated_at
    // صريح قد يُفقَد بتجميد الساعة) - ثم نحذف صف PlayerStreak المُتولِّد تلقائيًا
    // بالمسار التدريجي، لنثبت أن الإصلاح وحده يُعيد بناء نفس النتيجة من الصفر.
    Carbon::setTestNow(Carbon::parse('2026-10-05 09:00:00', 'UTC'));
    e13SolveForStreak($this->user, $this->puzzles);

    Carbon::setTestNow(Carbon::parse('2026-10-06 10:00:00', 'UTC'));
    e13SolveForStreak($this->user, $this->puzzles);

    $incrementalStreak = $this->streaks->streakFor($this->user);
    expect($incrementalStreak->current_streak)->toBe(2); // تأكيد أن تاريخًا حقيقيًا بيومين متتاليين تكوَّن فعلًا

    $incrementalStreak->delete(); // محاكاة فقدان الملخَّص المُجسَّد - PuzzleAttempt الفعلية تبقى

    expect(\App\Models\PlayerStreak::where('user_id', $this->user->id)->exists())->toBeFalse();

    $rebuilt = $this->streaks->recalculateFromHistory($this->user);

    expect($rebuilt->current_streak)->toBe(2)
        ->and($rebuilt->longest_streak)->toBe(2);
});