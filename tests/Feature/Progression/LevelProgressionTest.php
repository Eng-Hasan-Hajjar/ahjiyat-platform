<?php

use App\Models\Currency;
use App\Models\LevelDefinition;
use App\Models\User;
use App\Models\UserLevelUnlock;
use App\Models\XpTransaction;
use App\Services\Progression\LevelService;
use App\Services\Progression\XpService;

beforeEach(function () {
    $this->xp = app(XpService::class);
    $this->levels = app(LevelService::class);
    $this->user = User::factory()->create();

    LevelDefinition::factory()->first()->create();
    LevelDefinition::factory()->create(['level_number' => 2, 'xp_required_total' => 100, 'name' => 'مستوى 2']);
    LevelDefinition::factory()->create(['level_number' => 3, 'xp_required_total' => 250, 'name' => 'مستوى 3']);
    LevelDefinition::factory()->create(['level_number' => 4, 'xp_required_total' => 500, 'name' => 'مستوى 4']);
    LevelDefinition::factory()->create(['level_number' => 5, 'xp_required_total' => 900, 'name' => 'مستوى 5']);
});

test('a new user starts at level 1 with zero xp', function () {
    expect($this->levels->currentLevelFor($this->user)->level_number)->toBe(1)
        ->and($this->levels->progressionFor($this->user)->total_xp)->toBe(0);
});

test('E12 req 185: crossing a threshold changes the level and creates a UserLevelUnlock row', function () {
    $this->xp->grantXp($this->user, 100, XpTransaction::TYPE_PUZZLE_SOLVE, 'test');

    expect($this->levels->currentLevelFor($this->user)->level_number)->toBe(2)
        ->and(UserLevelUnlock::where('user_id', $this->user->id)->whereHas('level', fn ($q) => $q->where('level_number', 2))->exists())->toBeTrue();
});

test('E12 req 186: a large XP grant crosses multiple levels correctly in one operation', function () {
    $this->xp->grantXp($this->user, 900, XpTransaction::TYPE_PUZZLE_SOLVE, 'test');

    $progression = $this->levels->progressionFor($this->user);

    expect($progression->current_level)->toBe(5)
        ->and(UserLevelUnlock::where('user_id', $this->user->id)->count())->toBe(4);
});

test('E12 req 187: retrying (re-evaluating) does not grant the level reward twice', function () {
    $currency = Currency::where('is_earnable', true)->first();
    $level2 = LevelDefinition::where('level_number', 2)->first();
    $level2->update(['reward_currency_id' => $currency->id, 'reward_currency_amount' => 15]);

    expect($level2->fresh()->reward_currency_id)->toBe($currency->id)
        ->and($level2->fresh()->reward_currency_amount)->toBe(15);

    $this->xp->grantXp($this->user, 100, XpTransaction::TYPE_PUZZLE_SOLVE, 'test');

    $unlock = UserLevelUnlock::where('user_id', $this->user->id)
        ->whereHas('level', fn ($q) => $q->where('level_number', 2))
        ->first();

    $rewardTransaction = \App\Models\CurrencyTransaction::where('user_id', $this->user->id)
        ->where('currency_id', $currency->id)
        ->where('reference_type', $unlock?->getMorphClass())
        ->first();

    $levelReachedCorrectly = $this->levels->currentLevelFor($this->user)->level_number === 2;
    $unlockRowExists = $unlock !== null;
    $unlockRewardMarkedGranted = $unlock?->reward_granted_at !== null;
    $currencyLedgerRowExistsForThisUnlock = $rewardTransaction !== null;
    $currencyLedgerRowAmount = $rewardTransaction->amount ?? -1;
    $balanceAfterFirst = $this->user->wallets()->where('currency_id', $currency->id)->first()?->pending_balance ?? -1;

    expect($levelReachedCorrectly)->toBeTrue()
        ->and($unlockRowExists)->toBeTrue()
        ->and($unlockRewardMarkedGranted)->toBeTrue()
        ->and($currencyLedgerRowExistsForThisUnlock)->toBeTrue()
        ->and($currencyLedgerRowAmount)->toBe(15)
        ->and($balanceAfterFirst)->toBe(15);

    $progression = $this->levels->progressionFor($this->user);
    $this->levels->recalculateFor($this->user, $progression);

    $balanceAfterRetry = $this->user->wallets()->where('currency_id', $currency->id)->first()?->pending_balance ?? 0;

    expect($balanceAfterRetry)->toBe(15);
});

test('level never regresses even if somehow re-evaluated with the same XP', function () {
    $this->xp->grantXp($this->user, 250, XpTransaction::TYPE_PUZZLE_SOLVE, 'test');
    expect($this->levels->currentLevelFor($this->user)->level_number)->toBe(3);

    $progression = $this->levels->progressionFor($this->user);
    $this->levels->recalculateFor($this->user, $progression);

    expect($this->levels->currentLevelFor($this->user)->level_number)->toBe(3);
});

test('E12 req 38/125: max level shows 100 percent progress with no divide-by-zero', function () {
    $this->xp->grantXp($this->user, 10000, XpTransaction::TYPE_PUZZLE_SOLVE, 'test');

    expect($this->levels->nextLevelFor($this->user))->toBeNull()
        ->and($this->levels->progressPercentFor($this->user))->toBe(100.0);
});

test('progress percent is computed correctly within the current level range', function () {
    $this->xp->grantXp($this->user, 175, XpTransaction::TYPE_PUZZLE_SOLVE, 'test');

    expect($this->levels->progressPercentFor($this->user))->toBe(50.0);
});

test('E12 req 27: level 1 has no explicit UserLevelUnlock row by design - it is the implicit starting point', function () {
    expect(UserLevelUnlock::where('user_id', $this->user->id)->whereHas('level', fn ($q) => $q->where('level_number', 1))->exists())->toBeFalse();
});