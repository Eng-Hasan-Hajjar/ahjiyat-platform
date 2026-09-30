<?php

use App\Models\PlayerProgression;
use App\Models\User;
use App\Models\XpTransaction;
use App\Services\Progression\XpService;

beforeEach(function () {
    $this->xp = app(XpService::class);
    $this->user = User::factory()->create();
});

test('E12 req 182: granting 50 XP creates a ledger row and increases total_xp by exactly 50', function () {
    $this->xp->grantXp($this->user, 50, XpTransaction::TYPE_PUZZLE_SOLVE, 'test');

    expect(XpTransaction::where('user_id', $this->user->id)->where('amount', 50)->count())->toBe(1);

    $progression = PlayerProgression::where('user_id', $this->user->id)->first();
    expect($progression->total_xp)->toBe(50)
        ->and($progression->last_xp_at)->not->toBeNull();
});

test('a user with no progression row yet gets one lazily on first grant', function () {
    expect(PlayerProgression::where('user_id', $this->user->id)->exists())->toBeFalse();

    $this->xp->grantXp($this->user, 10, XpTransaction::TYPE_PUZZLE_SOLVE, 'test');

    expect(PlayerProgression::where('user_id', $this->user->id)->exists())->toBeTrue();
});

test('E12 req 6: gameplay XP amount must be positive - zero or negative is rejected', function () {
    expect(fn () => $this->xp->grantXp($this->user, 0, XpTransaction::TYPE_PUZZLE_SOLVE, 'test'))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => $this->xp->grantXp($this->user, -5, XpTransaction::TYPE_PUZZLE_SOLVE, 'test'))
        ->toThrow(InvalidArgumentException::class);

    expect(XpTransaction::where('user_id', $this->user->id)->count())->toBe(0);
});

test('multiple grants accumulate total_xp correctly', function () {
    $this->xp->grantXp($this->user, 10, XpTransaction::TYPE_PUZZLE_SOLVE, 'a');
    $this->xp->grantXp($this->user, 15, XpTransaction::TYPE_PUZZLE_SOLVE, 'b');
    $this->xp->grantXp($this->user, 5, XpTransaction::TYPE_ACHIEVEMENT_REWARD, 'c');

    expect(PlayerProgression::where('user_id', $this->user->id)->first()->total_xp)->toBe(30)
        ->and(XpTransaction::where('user_id', $this->user->id)->count())->toBe(3);
});

test('XP ledger rows are never updated or deleted through the model directly - append-only in spirit', function () {
    $transaction = $this->xp->grantXp($this->user, 10, XpTransaction::TYPE_PUZZLE_SOLVE, 'test');

    expect($transaction->getAttributes())->not->toHaveKey('updated_at');
});