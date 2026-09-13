<?php

use App\GameEngine\Support\AttemptContext;
use App\Models\Puzzle;
use App\Models\PuzzleAttempt;
use App\Models\User;
use App\Services\GameSessionService;
use App\Services\PuzzleAttemptService;

function makeIsolationSpotDifferencePuzzle(array $overrides = []): Puzzle
{
    return Puzzle::factory()->create(array_merge([
        'game_type' => 'spot_difference',
        'game_config' => [
            'image_before' => 'puzzles/spot-difference/before.png',
            'image_after' => 'puzzles/spot-difference/after.png',
        ],
        'solution_data' => [
            'hotspots' => [
                ['x' => 0.2, 'y' => 0.3, 'radius' => 0.05],
                ['x' => 0.7, 'y' => 0.6, 'radius' => 0.05],
            ],
        ],
        'gem_reward' => 10,
        'max_attempts' => 5,
    ], $overrides));
}

test('a standalone solve does not mark a campaign-like context as solved', function () {
    $user = User::factory()->create();
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح', 'max_attempts' => 3]);

    app(PuzzleAttemptService::class)->attempt($user, $puzzle, 'صح');

    expect($user->hasSolvedPuzzle($puzzle))->toBeTrue()
        ->and($user->hasSolvedPuzzle($puzzle, AttemptContext::for('campaign_step', 1)))->toBeFalse();
});

test('solving inside context A does not mark context B or standalone as solved', function () {
    $user = User::factory()->create();
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح', 'max_attempts' => 3]);

    app(PuzzleAttemptService::class)->attempt(
        $user, $puzzle, 'صح', false, [], AttemptContext::for('campaign_step', 1)
    );

    expect($user->hasSolvedPuzzle($puzzle, AttemptContext::for('campaign_step', 1)))->toBeTrue()
        ->and($user->hasSolvedPuzzle($puzzle, AttemptContext::for('campaign_step', 2)))->toBeFalse()
        ->and($user->hasSolvedPuzzle($puzzle))->toBeFalse();
});

test('standalone and context attempt counts are isolated - exhausting one does not block the other', function () {
    $user = User::factory()->create();
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح', 'max_attempts' => 1]);

    app(PuzzleAttemptService::class)->attempt($user, $puzzle, 'خطأ');

    expect(fn () => app(PuzzleAttemptService::class)->attempt($user, $puzzle, 'خطأ تاني'))
        ->toThrow(RuntimeException::class);

    $contextResult = app(PuzzleAttemptService::class)->attempt(
        $user, $puzzle, 'صح', false, [], AttemptContext::for('campaign_step', 5)
    );

    expect($contextResult['correct'])->toBeTrue();
});

test('legacy standalone-only behaviour is unchanged when no context is ever passed', function () {
    $user = User::factory()->create();
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح', 'max_attempts' => 2]);

    $first = app(PuzzleAttemptService::class)->attempt($user, $puzzle, 'خطأ');
    expect($first['correct'])->toBeFalse()->and($first['attempts_left'])->toBe(1);

    $second = app(PuzzleAttemptService::class)->attempt($user, $puzzle, 'صح');
    expect($second['correct'])->toBeTrue();

    expect(fn () => app(PuzzleAttemptService::class)->attempt($user, $puzzle, 'صح'))
        ->toThrow(RuntimeException::class);
});

test('a GameSession started with a context is not reused for a standalone request on the same puzzle', function () {
    $user = User::factory()->create();
    $puzzle = makeIsolationSpotDifferencePuzzle();

    $contextSession = app(GameSessionService::class)->start($user, $puzzle, AttemptContext::for('campaign_step', 9));
    $standaloneSession = app(GameSessionService::class)->start($user, $puzzle);

    expect($standaloneSession->id)->not->toBe($contextSession->id)
        ->and($standaloneSession->context_type)->toBeNull()
        ->and($contextSession->context_type)->toBe('campaign_step')
        ->and($contextSession->context_id)->toBe(9);
});

test('a GameSession started with context A is not reused for context B on the same puzzle', function () {
    $user = User::factory()->create();
    $puzzle = makeIsolationSpotDifferencePuzzle();

    $sessionA = app(GameSessionService::class)->start($user, $puzzle, AttemptContext::for('campaign_step', 1));
    $sessionB = app(GameSessionService::class)->start($user, $puzzle, AttemptContext::for('campaign_step', 2));

    expect($sessionA->id)->not->toBe($sessionB->id);
});

test('the final PuzzleAttempt inherits the GameSession context automatically, not none()', function () {
    $user = User::factory()->create();
    $puzzle = makeIsolationSpotDifferencePuzzle();

    $session = app(GameSessionService::class)->start($user, $puzzle, AttemptContext::for('campaign_step', 42));

    app(GameSessionService::class)->reveal($session, 0.2, 0.3);
    app(GameSessionService::class)->reveal($session->fresh(), 0.7, 0.6);

    $attempt = PuzzleAttempt::where('puzzle_id', $puzzle->id)->where('user_id', $user->id)->first();

    expect($attempt->context_type)->toBe('campaign_step')
        ->and($attempt->context_id)->toBe(42)
        ->and($attempt->game_session_id)->toBe($session->id);

    expect($user->hasSolvedPuzzle($puzzle))->toBeFalse()
        ->and($user->hasSolvedPuzzle($puzzle, AttemptContext::for('campaign_step', 42)))->toBeTrue();
});