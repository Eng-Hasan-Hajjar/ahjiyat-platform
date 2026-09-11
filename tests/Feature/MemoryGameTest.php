<?php

use App\GameEngine\Support\TimedAttemptToken;
use App\Models\Puzzle;
use App\Models\User;

function makeMemoryPuzzle(array $overrides = []): Puzzle
{
    return Puzzle::factory()->create(array_merge([
        'game_type' => 'memory',
        'validation_type' => 'memory_match',
        'score_mode' => 'flat',
        'renderer' => 'games.memory',
        'game_config' => ['faces' => ['أ', 'ب', 'ج']],
        'gem_reward' => 18,
    ], $overrides));
}

test('memory game config is auto-expanded into doubled, id-assigned cards on save', function () {
    $puzzle = makeMemoryPuzzle();
    $cards = $puzzle->fresh()->game_config['cards'];

    expect($cards)->toHaveCount(6)
        ->and(collect($cards)->pluck('face')->sort()->values()->all())
        ->toBe(['أ', 'أ', 'ب', 'ب', 'ج', 'ج']);
});

test('submitting a fully correct set of matches solves the puzzle and awards gems', function () {
    $user = User::factory()->create();
    $puzzle = makeMemoryPuzzle();
    $cards = $puzzle->fresh()->game_config['cards'];
    $pairs = collect($cards)->groupBy('face')->map(fn ($g) => $g->pluck('id')->all())->values()->all();

    $this->actingAs($user)
        ->post(route('puzzles.attempt', $puzzle), [
            'submission' => json_encode(['matches' => $pairs, 'moves' => 3, 'elapsed_seconds' => 20]),
        ])
        ->assertRedirect(route('puzzles.show', $puzzle))
        ->assertSessionHas('success');

    $user->wallet->refresh();
    expect($user->wallet->pending_balance)->toBe(18);
});

test('an incomplete or fabricated match list does not solve the puzzle', function () {
    $user = User::factory()->create();
    $puzzle = makeMemoryPuzzle();
    $cards = $puzzle->fresh()->game_config['cards'];

    // نفس البطاقة مستخدمة مرتين بزوجين مختلفين - محاولة تلاعب واضحة
    $cheatingPairs = [[$cards[0]['id'], $cards[1]['id']], [$cards[0]['id'], $cards[2]['id']]];

    $this->actingAs($user)
        ->post(route('puzzles.attempt', $puzzle), ['submission' => json_encode(['matches' => $cheatingPairs])])
        ->assertSessionHas('error');

    $user->wallet->refresh();
    expect($user->wallet->pending_balance)->toBe(0);
});

test('a correct solve submitted after the time limit is rejected', function () {
    $user = User::factory()->create();
    $puzzle = makeMemoryPuzzle(['time_limit_seconds' => 30]);
    $cards = $puzzle->fresh()->game_config['cards'];
    $pairs = collect($cards)->groupBy('face')->map(fn ($g) => $g->pluck('id')->all())->values()->all();

    $this->travelTo(now()->subMinutes(5));
    $staleToken = TimedAttemptToken::issue($puzzle, $user->id);
    $this->travelBack();

    $this->actingAs($user)
        ->post(route('puzzles.attempt', $puzzle), [
            'submission' => json_encode(['matches' => $pairs]),
            'start_token' => $staleToken,
        ])
        ->assertSessionHas('error');

    $user->wallet->refresh();
    expect($user->wallet->pending_balance)->toBe(0);
});

test('a correct solve submitted within the time limit is accepted', function () {
    $user = User::factory()->create();
    $puzzle = makeMemoryPuzzle(['time_limit_seconds' => 120, 'gem_reward' => 25]);
    $cards = $puzzle->fresh()->game_config['cards'];
    $pairs = collect($cards)->groupBy('face')->map(fn ($g) => $g->pluck('id')->all())->values()->all();
    $token = TimedAttemptToken::issue($puzzle, $user->id);

    $this->actingAs($user)
        ->post(route('puzzles.attempt', $puzzle), [
            'submission' => json_encode(['matches' => $pairs]),
            'start_token' => $token,
        ])
        ->assertSessionHas('success');

    $user->wallet->refresh();
    expect($user->wallet->pending_balance)->toBe(25);
});