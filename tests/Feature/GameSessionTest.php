<?php

use App\Models\Puzzle;
use App\Models\User;
use App\Services\GameSessionService;

function makeHttpSpotDifferencePuzzle(array $overrides = []): Puzzle
{
    return Puzzle::factory()->create(array_merge([
        'game_type' => 'spot_difference',
        'game_config' => [
            'image_before' => 'puzzles/spot-difference/before.png',
            'image_after' => 'puzzles/spot-difference/after.png',
        ],
        'solution_data' => [
            'hotspots' => [
                ['x' => 0.25, 'y' => 0.25, 'radius' => 0.05],
                ['x' => 0.75, 'y' => 0.75, 'radius' => 0.05],
            ],
        ],
        'gem_reward' => 30,
    ], $overrides));
}

test('an authenticated verified user can start a session for a spot_difference puzzle', function () {
    $user = User::factory()->create();
    $puzzle = makeHttpSpotDifferencePuzzle();

    $this->actingAs($user)
        ->postJson(route('game-sessions.start', $puzzle))
        ->assertOk()
        ->assertJsonStructure(['session_id', 'found', 'required']);
});

test('a guest cannot start a session', function () {
    $puzzle = makeHttpSpotDifferencePuzzle();

    $this->postJson(route('game-sessions.start', $puzzle))->assertUnauthorized();
});

test('a user cannot reveal on another users session', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $puzzle = makeHttpSpotDifferencePuzzle();

    $session = app(GameSessionService::class)->start($owner, $puzzle);

    $this->actingAs($intruder)
        ->postJson(route('game-sessions.reveal', $session), ['x' => 0.25, 'y' => 0.25])
        ->assertForbidden();
});

test('invalid out-of-range coordinates are rejected', function () {
    $user = User::factory()->create();
    $puzzle = makeHttpSpotDifferencePuzzle();
    $session = app(GameSessionService::class)->start($user, $puzzle);

    $this->actingAs($user)
        ->postJson(route('game-sessions.reveal', $session), ['x' => 1.5, 'y' => -0.2])
        ->assertUnprocessable();
});

test('a correct full playthrough via HTTP awards gems exactly once and never leaks hotspots in the page HTML', function () {
    $user = User::factory()->create();
    $puzzle = makeHttpSpotDifferencePuzzle();

    // صفحة الأحجية نفسها لا تكشف الإحداثيات أو نصف القطر إطلاقاً
    $this->actingAs($user)
        ->get(route('puzzles.show', $puzzle))
        ->assertDontSee('0.25', false)
        ->assertDontSee('radius', false);

    $start = $this->actingAs($user)->postJson(route('game-sessions.start', $puzzle));
    $sessionId = $start->json('session_id');

    $this->actingAs($user)
        ->postJson(route('game-sessions.reveal', $sessionId), ['x' => 0.25, 'y' => 0.25])
        ->assertOk()
        ->assertJson(['hit' => true]);

    $this->actingAs($user)
        ->postJson(route('game-sessions.reveal', $sessionId), ['x' => 0.75, 'y' => 0.75])
        ->assertOk()
        ->assertJson(['hit' => true, 'completed' => true, 'correct' => true, 'gems_awarded' => 30]);

    $user->wallet->refresh();
    expect($user->wallet->pending_balance)->toBe(30);
});

test('rate limiting eventually blocks rapid reveal requests from the same user', function () {
    $user = User::factory()->create();
    $puzzle = makeHttpSpotDifferencePuzzle();
    $session = app(GameSessionService::class)->start($user, $puzzle);

    $lastStatus = null;

    for ($i = 0; $i < 65; $i++) {
        $lastStatus = $this->actingAs($user)
            ->postJson(route('game-sessions.reveal', $session), ['x' => 0.01, 'y' => 0.01])
            ->status();
    }

    expect($lastStatus)->toBe(429);
});