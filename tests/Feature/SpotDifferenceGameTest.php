<?php

use App\GameEngine\Definitions\SpotDifferenceGameTypeDefinition;
use App\GameEngine\GameTypeRegistry;
use App\GameEngine\Validators\SpotDifferenceValidator;
use App\Models\GameSession;
use App\Models\Puzzle;
use App\Models\PuzzleAttempt;
use App\Models\User;
use App\Services\GameSessionService;

function makeSpotDifferencePuzzle(array $overrides = []): Puzzle
{
    return Puzzle::factory()->create(array_merge([
        'game_type' => 'spot_difference',
        'validation_type' => 'spot_difference_match',
        'score_mode' => 'flat',
        'renderer' => 'games.spot-difference',
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
        'gem_reward' => 22,
        'max_attempts' => 2,
    ], $overrides));
}

beforeEach(function () {
    $this->sessions = app(GameSessionService::class);
});

test('spot_difference is resolved as a registered game type with its own validator and renderer', function () {
    $puzzle = makeSpotDifferencePuzzle();
    $registry = app(GameTypeRegistry::class);

    expect($registry->validatorFor($puzzle))->toBeInstanceOf(SpotDifferenceValidator::class)
        ->and($registry->rendererFor($puzzle))->toBe('games.spot-difference');
});

test('public payload never leaks hotspot coordinates or solution_data', function () {
    $puzzle = makeSpotDifferencePuzzle();
    $payload = (new SpotDifferenceGameTypeDefinition)->publicPayload($puzzle);

    expect($payload)->toHaveKeys(['image_before', 'image_after', 'required_differences'])
        ->and($payload)->not->toHaveKey('hotspots')
        ->and($payload)->not->toHaveKey('solution_data')
        ->and(json_encode($payload))->not->toContain('radius');
});

test('starting a session creates an active game session with the correct initial state', function () {
    $user = User::factory()->create();
    $puzzle = makeSpotDifferencePuzzle();

    $session = $this->sessions->start($user, $puzzle);

    expect($session->status)->toBe(GameSession::STATUS_ACTIVE)
        ->and($session->user_id)->toBe($user->id)
        ->and($session->puzzle_id)->toBe($puzzle->id)
        ->and($session->server_state['found_indices'])->toBe([]);
});

test('starting a session for an inactive puzzle is rejected', function () {
    $user = User::factory()->create();
    $puzzle = makeSpotDifferencePuzzle(['is_active' => false]);

    expect(fn () => $this->sessions->start($user, $puzzle))->toThrow(RuntimeException::class);
});

test('revealing the correct coordinate hits and the same hotspot cannot count twice', function () {
    $user = User::factory()->create();
    $puzzle = makeSpotDifferencePuzzle();
    $session = $this->sessions->start($user, $puzzle);

    $first = $this->sessions->reveal($session, 0.2, 0.3);
    expect($first['hit'])->toBeTrue()->and($first['found'])->toBe(1);

    $duplicate = $this->sessions->reveal($session->fresh(), 0.2, 0.3);
    expect($duplicate['hit'])->toBeFalse()->and($duplicate['found'])->toBe(1);
});

test('revealing an empty area misses', function () {
    $user = User::factory()->create();
    $puzzle = makeSpotDifferencePuzzle();
    $session = $this->sessions->start($user, $puzzle);

    $result = $this->sessions->reveal($session, 0.9, 0.9);

    expect($result['hit'])->toBeFalse()->and($result['found'])->toBe(0);
});

test('finding all hotspots completes the session, creates exactly one correct attempt, and awards gems', function () {
    $user = User::factory()->create();
    $puzzle = makeSpotDifferencePuzzle();
    $session = $this->sessions->start($user, $puzzle);

    $this->sessions->reveal($session, 0.2, 0.3);
    $result = $this->sessions->reveal($session->fresh(), 0.7, 0.6);

    expect($result['completed'])->toBeTrue()
        ->and($result['correct'])->toBeTrue()
        ->and($result['gems_awarded'])->toBe(22);

    $user->wallet->refresh();
    expect($user->wallet->pending_balance)->toBe(22)
        ->and(PuzzleAttempt::where('puzzle_id', $puzzle->id)->where('user_id', $user->id)->count())->toBe(1);
});

test('cannot complete before all hotspots are found', function () {
    $user = User::factory()->create();
    $puzzle = makeSpotDifferencePuzzle();
    $session = $this->sessions->start($user, $puzzle);

    $result = $this->sessions->reveal($session, 0.2, 0.3);

    expect($result['completed'])->toBeFalse();
    expect(PuzzleAttempt::where('puzzle_id', $puzzle->id)->count())->toBe(0);
});

test('duplicate finalization does not grant a second reward', function () {
    $user = User::factory()->create();
    $puzzle = makeSpotDifferencePuzzle();
    $session = $this->sessions->start($user, $puzzle);

    $this->sessions->reveal($session, 0.2, 0.3);
    $this->sessions->reveal($session->fresh(), 0.7, 0.6);

    $second = $this->sessions->finalize($session->fresh());

    expect($second['already_finalized'])->toBeTrue()
        ->and($second['gems_awarded'])->toBe(0);

    $user->wallet->refresh();
    expect($user->wallet->pending_balance)->toBe(22);
});

test('an expired session cannot be finalized as a success', function () {
    $user = User::factory()->create();
    $puzzle = makeSpotDifferencePuzzle(['time_limit_seconds' => 30]);

    $session = $this->sessions->start($user, $puzzle);

    $this->travelTo(now()->addSeconds(31));

    $result = $this->sessions->reveal($session, 0.2, 0.3);

    expect($result['hit'])->toBeFalse()
        ->and($result['session_status'])->toBe(GameSession::STATUS_EXPIRED);

    $user->wallet->refresh();
    expect($user->wallet->pending_balance)->toBe(0);
});

test('a standalone playthrough keeps context null while still linking the game session', function () {
    $user = User::factory()->create();
    $puzzle = makeSpotDifferencePuzzle();
    $session = $this->sessions->start($user, $puzzle);

    $this->sessions->reveal($session, 0.2, 0.3);
    $this->sessions->reveal($session->fresh(), 0.7, 0.6);

    $attempt = PuzzleAttempt::where('puzzle_id', $puzzle->id)->where('user_id', $user->id)->first();

    expect($attempt->context_type)->toBeNull()
        ->and($attempt->context_id)->toBeNull()
        ->and($attempt->game_session_id)->toBe($session->id);
});