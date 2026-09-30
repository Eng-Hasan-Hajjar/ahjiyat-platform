<?php

use App\Models\Achievement;
use App\Models\LevelDefinition;
use App\Models\Puzzle;
use App\Models\StoreItem;
use App\Models\StoreItemPrice;
use App\Models\StorePurchase;
use App\Models\User;
use App\Models\UserInventoryItem;
use App\Services\Economy\CurrencyRegistry;
use App\Services\Economy\CurrencyWalletService;
use App\Services\PlayerIdentity\CosmeticLoadoutService;
use App\Services\Progression\AchievementEvaluatorRegistry;
use App\Services\Progression\AchievementService;
use App\Services\Progression\LevelService;
use App\Services\PuzzleAttemptService;
use App\Services\Store\StorePurchaseService;
use Illuminate\Support\Str;

beforeEach(function () {
    LevelDefinition::factory()->first()->create();
    LevelDefinition::factory()->create(['level_number' => 2, 'xp_required_total' => 25, 'name' => 'مستوى 2']);

    $this->puzzles = app(PuzzleAttemptService::class);
    $this->achievements = app(AchievementService::class);
    $this->levels = app(LevelService::class);
});

function e12AssertGenuinelyFreeUser(User $user): void
{
    $premium = app(CurrencyRegistry::class)->primaryPremiumCurrency();

    if ($premium !== null) {
        $premiumBalance = $user->wallets()->where('currency_id', $premium->id)->first();
        expect($premiumBalance?->available_balance ?? 0)->toBe(0)
            ->and($premiumBalance?->pending_balance ?? 0)->toBe(0);
    }

    expect(StorePurchase::where('user_id', $user->id)->count())->toBe(0)
        ->and(\App\Models\UserEntitlement::where('user_id', $user->id)->count())->toBe(0);
}

test('E12 CRITICAL - req 200/242: a genuinely free user can play, earn XP, earn default currency, progress an achievement, and level up - entirely without payment', function () {
    $freeUser = User::factory()->create();
    e12AssertGenuinelyFreeUser($freeUser);

    $achievement = Achievement::factory()->puzzlesSolvedTotal(1)->withXpReward(10)->create();
    $earnedCurrency = app(CurrencyRegistry::class)->defaultEarnedCurrency();

    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح', 'xp_reward' => 20, 'gem_reward' => 8]);

    $result = $this->puzzles->attempt($freeUser, $puzzle, 'صح');
    expect($result['correct'])->toBeTrue();

    $this->achievements->evaluateForEvent('puzzle_solved', $freeUser);

    expect($freeUser->fresh()->playerProgression->total_xp)->toBe(30);

    $wallet = $freeUser->wallets()->where('currency_id', $earnedCurrency->id)->first();
    expect($wallet->pending_balance)->toBe(8);

    $progress = $freeUser->achievementProgress()->where('achievement_id', $achievement->id)->first();
    expect($progress->unlocked_at)->not->toBeNull();

    expect($this->levels->currentLevelFor($freeUser)->level_number)->toBe(2);

    e12AssertGenuinelyFreeUser($freeUser);
});

test('E12 req 209/222/223: a free cosmetic reward from an achievement can be equipped afterward - full free identity loop', function () {
    $freeUser = User::factory()->create();
    $cosmetic = StoreItem::factory()->cosmeticBadge()->create();
    Achievement::factory()->puzzlesSolvedTotal(1)->create([
        'reward_store_item_id' => $cosmetic->id,
        'reward_item_quantity' => 1,
    ]);

    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح']);
    $this->puzzles->attempt($freeUser, $puzzle, 'صح');
    $this->achievements->evaluateForEvent('puzzle_solved', $freeUser);

    expect(UserInventoryItem::where('user_id', $freeUser->id)->where('store_item_id', $cosmetic->id)->first()->quantity)->toBe(1)
        ->and(StorePurchase::where('user_id', $freeUser->id)->count())->toBe(0);

    $loadout = app(CosmeticLoadoutService::class)->equip($freeUser, $cosmetic);
    expect($loadout->store_item_id)->toBe($cosmetic->id);
});

test('E12 req 201/83: buying a virtual store item grants zero XP', function () {
    $user = User::factory()->create();
    $item = StoreItem::factory()->create(['item_type' => StoreItem::TYPE_DIGITAL, 'fulfillment_type' => StoreItem::FULFILLMENT_INVENTORY]);
    $price = StoreItemPrice::factory()->create(['store_item_id' => $item->id, 'amount' => 10]);
    app(CurrencyWalletService::class)->creditAvailable($user, $price->currency, 1000, 'test');

    app(StorePurchaseService::class)->purchase($user, $item, $price, (string) Str::uuid());

    expect(\App\Models\PlayerProgression::where('user_id', $user->id)->exists())->toBeFalse();
});

test('E12 req 202/84: crediting premium currency does not alter XP at all', function () {
    $user = User::factory()->create();
    $premium = app(CurrencyRegistry::class)->primaryPremiumCurrency();

    if ($premium === null) {
        $this->markTestSkipped('لا عملة مميزة مُعرَّفة حاليًا بهذه البيئة.');
    }

    app(CurrencyWalletService::class)->creditAvailable($user, $premium, 5000, 'test-grant');

    expect(\App\Models\PlayerProgression::where('user_id', $user->id)->exists())->toBeFalse();
});

test('E12 req 355: a premium-holding user and a free user solving the identical puzzle get identical XP', function () {
    $freeUser = User::factory()->create();
    $premiumUser = User::factory()->create();
    $premium = app(CurrencyRegistry::class)->primaryPremiumCurrency();

    if ($premium !== null) {
        app(CurrencyWalletService::class)->creditAvailable($premiumUser, $premium, 100000, 'test-grant');
    }

    $puzzleA = Puzzle::factory()->create(['answer_raw' => 'صح', 'xp_reward' => 20]);
    $puzzleB = Puzzle::factory()->create(['answer_raw' => 'صح', 'xp_reward' => 20]);

    $this->puzzles->attempt($freeUser, $puzzleA, 'صح');
    $this->puzzles->attempt($premiumUser, $puzzleB, 'صح');

    expect($freeUser->fresh()->playerProgression->total_xp)->toBe($premiumUser->fresh()->playerProgression->total_xp);
});

test('E12 req 210/355: a level-10 user and a level-1 user solving the identical puzzle get identical XP', function () {
    foreach (range(3, 10) as $n) {
        LevelDefinition::factory()->create(['level_number' => $n, 'xp_required_total' => $n * 200]);
    }

    $lowLevelUser = User::factory()->create();
    $highLevelUser = User::factory()->create();
    $this->puzzles->attempt($highLevelUser, Puzzle::factory()->create(['answer_raw' => 'ترقية', 'xp_reward' => 5000]), 'ترقية');
    expect($this->levels->currentLevelFor($highLevelUser)->level_number)->toBeGreaterThan(5);

    $puzzleA = Puzzle::factory()->create(['answer_raw' => 'صح', 'xp_reward' => 20]);
    $puzzleB = Puzzle::factory()->create(['answer_raw' => 'صح', 'xp_reward' => 20]);

    $this->puzzles->attempt($lowLevelUser, $puzzleA, 'صح');
    $xpFromLowLevelUserBefore = $this->levels->progressionFor($highLevelUser)->total_xp;
    $this->puzzles->attempt($highLevelUser, $puzzleB, 'صح');
    $xpGainedByHighLevelUser = $this->levels->progressionFor($highLevelUser)->total_xp - $xpFromLowLevelUserBefore;

    expect($this->levels->progressionFor($lowLevelUser)->total_xp)->toBe(20)
        ->and($xpGainedByHighLevelUser)->toBe(20);
});

test('E12 req 209/355: a user owning cosmetics and a user owning none get identical XP for the same puzzle', function () {
    $ownerUser = User::factory()->create();
    $cosmetic = StoreItem::factory()->cosmeticBadge()->create();
    app(\App\Services\Store\InventoryService::class)->grant($ownerUser, $cosmetic, 1, 'gift');

    $bareUser = User::factory()->create();

    $puzzleA = Puzzle::factory()->create(['answer_raw' => 'صح', 'xp_reward' => 20]);
    $puzzleB = Puzzle::factory()->create(['answer_raw' => 'صح', 'xp_reward' => 20]);

    $this->puzzles->attempt($ownerUser, $puzzleA, 'صح');
    $this->puzzles->attempt($bareUser, $puzzleB, 'صح');

    expect($ownerUser->fresh()->playerProgression->total_xp)->toBe($bareUser->fresh()->playerProgression->total_xp);
});

test('E12 req 206/227/80: a puzzle attempt succeeds even with an entirely empty (zero-balance) wallet - zero balance never blocks play', function () {
    $user = User::factory()->create();
    $earnedCurrency = app(CurrencyRegistry::class)->defaultEarnedCurrency();

    $wallet = $user->wallets()->where('currency_id', $earnedCurrency->id)->first();
    expect($wallet?->available_balance ?? 0)->toBe(0)
        ->and($wallet?->pending_balance ?? 0)->toBe(0);

    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح']);
    $result = $this->puzzles->attempt($user, $puzzle, 'صح');

    expect($result['correct'])->toBeTrue();
});

test('E12 req 82/192: purchasing a hint grants zero XP', function () {
    $user = User::factory()->create();
    $earnedCurrency = app(CurrencyRegistry::class)->defaultEarnedCurrency();
    app(CurrencyWalletService::class)->creditAvailable($user, $earnedCurrency, 1000, 'test');

    $puzzle = Puzzle::factory()->create(['hint' => 'تلميح تجريبي']);
    $this->puzzles->purchaseHint($user, $puzzle);

    expect(\App\Models\PlayerProgression::where('user_id', $user->id)->exists())->toBeFalse();
});

test('E12 req 203/226: the achievement condition registry contains no commercial or payment condition types', function () {
    $types = AchievementEvaluatorRegistry::ALL_TYPES;

    foreach ($types as $type) {
        foreach (['purchase', 'payment', 'premium', 'spend', 'pack', 'checkout', 'money'] as $forbidden) {
            expect(str_contains($type, $forbidden))->toBeFalse();
        }
    }
});

test('E12 req 204: level calculation never inspects any wallet or currency balance', function () {
    $user = User::factory()->create();
    $earnedCurrency = app(CurrencyRegistry::class)->defaultEarnedCurrency();
    app(CurrencyWalletService::class)->creditAvailable($user, $earnedCurrency, 999999, 'huge-balance');

    expect($this->levels->currentLevelFor($user)->level_number)->toBe(1);
});