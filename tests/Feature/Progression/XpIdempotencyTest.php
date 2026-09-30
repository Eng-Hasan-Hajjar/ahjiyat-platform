<?php

use App\Models\User;
use App\Models\XpTransaction;
use App\Services\Progression\XpService;

beforeEach(function () {
    $this->xp = app(XpService::class);
    $this->user = User::factory()->create();
});

test('E12 req 183: same idempotency key twice grants XP exactly once', function () {
    $key = 'puzzle-solve:test-1';

    $first = $this->xp->grantXp($this->user, 20, XpTransaction::TYPE_PUZZLE_SOLVE, 'test', null, $key);
    $second = $this->xp->grantXp($this->user, 20, XpTransaction::TYPE_PUZZLE_SOLVE, 'test', null, $key);

    expect($first->id)->toBe($second->id)
        ->and(XpTransaction::where('idempotency_key', $key)->count())->toBe(1)
        ->and($this->user->playerProgression()->first()->total_xp)->toBe(20);
});

test('E12 req 184: same idempotency key with a different amount is a real conflict - exception, not silent mismatch', function () {
    $key = 'puzzle-solve:test-2';

    $this->xp->grantXp($this->user, 20, XpTransaction::TYPE_PUZZLE_SOLVE, 'test', null, $key);

    expect(fn () => $this->xp->grantXp($this->user, 999, XpTransaction::TYPE_PUZZLE_SOLVE, 'test', null, $key))
        ->toThrow(RuntimeException::class);

    expect($this->user->playerProgression()->first()->total_xp)->toBe(20);
});

test('same idempotency key with a different type is also a conflict', function () {
    $key = 'shared-key';

    $this->xp->grantXp($this->user, 20, XpTransaction::TYPE_PUZZLE_SOLVE, 'test', null, $key);

    expect(fn () => $this->xp->grantXp($this->user, 20, XpTransaction::TYPE_ACHIEVEMENT_REWARD, 'test', null, $key))
        ->toThrow(RuntimeException::class);
});

test('same idempotency key for a different user is also a conflict - keys are not scoped by user internally', function () {
    $otherUser = User::factory()->create();
    $key = 'shared-key-cross-user';

    $this->xp->grantXp($this->user, 20, XpTransaction::TYPE_PUZZLE_SOLVE, 'test', null, $key);

    expect(fn () => $this->xp->grantXp($otherUser, 20, XpTransaction::TYPE_PUZZLE_SOLVE, 'test', null, $key))
        ->toThrow(RuntimeException::class);
});

test('two different idempotency keys grant XP twice independently', function () {
    $this->xp->grantXp($this->user, 20, XpTransaction::TYPE_PUZZLE_SOLVE, 'test', null, 'key-a');
    $this->xp->grantXp($this->user, 20, XpTransaction::TYPE_PUZZLE_SOLVE, 'test', null, 'key-b');

    expect($this->user->playerProgression()->first()->total_xp)->toBe(40);
});

test('no idempotency key at all still works and simply always grants', function () {
    $this->xp->grantXp($this->user, 5, XpTransaction::TYPE_PUZZLE_SOLVE, 'a');
    $this->xp->grantXp($this->user, 5, XpTransaction::TYPE_PUZZLE_SOLVE, 'b');

    expect($this->user->playerProgression()->first()->total_xp)->toBe(10);
});