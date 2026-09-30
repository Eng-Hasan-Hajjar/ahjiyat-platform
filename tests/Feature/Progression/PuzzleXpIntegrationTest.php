<?php

use App\Models\Puzzle;
use App\Models\User;
use App\Models\XpTransaction;
use App\Services\PuzzleAttemptService;

beforeEach(function () {
    $this->service = app(PuzzleAttemptService::class);
    $this->user = User::factory()->create();
});

test('E12 req 195: a correct standalone solve grants XP exactly once using the config default', function () {
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح', 'xp_reward' => null]);

    $this->service->attempt($this->user, $puzzle, 'صح');

    expect($this->user->fresh()->playerProgression->total_xp)->toBe((int) config('progression.default_puzzle_xp'))
        ->and(XpTransaction::where('user_id', $this->user->id)->where('type', XpTransaction::TYPE_PUZZLE_SOLVE)->count())->toBe(1);
});

test('an explicit puzzle xp_reward overrides the config default', function () {
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح', 'xp_reward' => 42]);

    $this->service->attempt($this->user, $puzzle, 'صح');

    expect($this->user->fresh()->playerProgression->total_xp)->toBe(42);
});

test('E12 req 165: an explicit zero xp_reward means genuinely zero XP - not the config default', function () {
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح', 'xp_reward' => 0]);

    $this->service->attempt($this->user, $puzzle, 'صح');

    expect(\App\Models\PlayerProgression::where('user_id', $this->user->id)->exists())->toBeFalse()
        ->and(XpTransaction::where('user_id', $this->user->id)->count())->toBe(0);
});

test('E12 req 194: a wrong attempt grants zero XP', function () {
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح', 'xp_reward' => 20, 'max_attempts' => 3]);

    $this->service->attempt($this->user, $puzzle, 'خطأ');

    expect(XpTransaction::where('user_id', $this->user->id)->count())->toBe(0);
});

test('E12 req 196: solving the same puzzle context twice does not grant XP twice - the second attempt is blocked entirely by the existing solved-once rule', function () {
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح', 'xp_reward' => 20]);

    $this->service->attempt($this->user, $puzzle, 'صح');

    expect(fn () => $this->service->attempt($this->user, $puzzle, 'صح'))->toThrow(RuntimeException::class);
    expect($this->user->fresh()->playerProgression->total_xp)->toBe(20);
});

test('two different users solving the same puzzle each get their own XP independently', function () {
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح', 'xp_reward' => 15]);
    $otherUser = User::factory()->create();

    $this->service->attempt($this->user, $puzzle, 'صح');
    $this->service->attempt($otherUser, $puzzle, 'صح');

    expect($this->user->fresh()->playerProgression->total_xp)->toBe(15)
        ->and($otherUser->fresh()->playerProgression->total_xp)->toBe(15);
});