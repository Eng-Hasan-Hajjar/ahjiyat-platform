<?php

use App\Models\Puzzle;
use App\Models\PuzzleAttempt;
use App\Models\User;
use App\Services\PuzzleAttemptService;

beforeEach(function () {
    $this->service = app(PuzzleAttemptService::class);
    $this->user = User::factory()->create();
});

test('correct legacy text answer awards gems and logs a correct attempt', function () {
    $puzzle = Puzzle::factory()->create([
        'answer_raw' => 'الجواب الصحيح',
        'gem_reward' => 12,
    ]);

    $result = $this->service->attempt($this->user, $puzzle, 'الجواب الصحيح');

    expect($result['correct'])->toBeTrue()
        ->and($result['gems_awarded'])->toBe(12);

    $this->user->wallet->refresh();
    expect($this->user->wallet->pending_balance)->toBe(12);

    expect(PuzzleAttempt::where('user_id', $this->user->id)
        ->where('puzzle_id', $puzzle->id)
        ->where('is_correct', true)
        ->exists())->toBeTrue();
});

test('wrong legacy text answer awards no gems and decrements attempts left', function () {
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'الجواب الصحيح', 'max_attempts' => 3]);

    $result = $this->service->attempt($this->user, $puzzle, 'جواب خاطئ');

    expect($result['correct'])->toBeFalse()
        ->and($result['gems_awarded'])->toBe(0)
        ->and($result['attempts_left'])->toBe(2);

    $this->user->wallet->refresh();
    expect($this->user->wallet->pending_balance)->toBe(0);
});

test('throws once max_attempts is exhausted', function () {
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح', 'max_attempts' => 1]);

    $this->service->attempt($this->user, $puzzle, 'خطأ');

    expect(fn () => $this->service->attempt($this->user, $puzzle, 'خطأ تاني'))
        ->toThrow(RuntimeException::class);
});

test('throws when the puzzle was already solved', function () {
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح']);

    $this->service->attempt($this->user, $puzzle, 'صح');

    expect(fn () => $this->service->attempt($this->user, $puzzle, 'صح'))
        ->toThrow(RuntimeException::class);
});

test('daily earn cap limits gems awarded even on a correct solve', function () {
    config(['gems.daily_earn_cap' => 10]);

    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح', 'gem_reward' => 50]);

    $result = $this->service->attempt($this->user, $puzzle, 'صح');

    expect($result['correct'])->toBeTrue()
        ->and($result['gems_awarded'])->toBe(10);
});

test('sequence game type: correct order awards gems via the registry, wrong order does not', function () {
    $puzzle = Puzzle::factory()->create([
        'game_type' => 'sequence',
        'validation_type' => 'sequence_match',
        'score_mode' => 'flat',
        'renderer' => 'games.sequence',
        'game_config' => ['items' => ['الأول', 'الثاني', 'الثالث']],
        'gem_reward' => 20,
    ]);

    // الترتيب الصحيح مشتق تلقائياً بـ Puzzle::booted() -> GameTypeRegistry::prepareForSave
    expect($puzzle->fresh()->solution_data)->toBe(['order' => [0, 1, 2]]);

    $wrongUser = User::factory()->create();
    $wrongResult = $this->service->attempt($wrongUser, $puzzle, '', false, ['order' => [1, 0, 2]]);
    expect($wrongResult['correct'])->toBeFalse();

    $rightResult = $this->service->attempt($this->user, $puzzle, '', false, ['order' => [0, 1, 2]]);
    expect($rightResult['correct'])->toBeTrue()
        ->and($rightResult['gems_awarded'])->toBe(20);

    $attempt = PuzzleAttempt::where('user_id', $this->user->id)
        ->where('puzzle_id', $puzzle->id)
        ->where('is_correct', true)
        ->first();

    expect($attempt->submission_snapshot)->toBe(['order' => [0, 1, 2]]);
});