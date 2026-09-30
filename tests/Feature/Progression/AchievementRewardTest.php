<?php

use App\Models\Achievement;
use App\Models\Currency;
use App\Models\Puzzle;
use App\Models\StoreItem;
use App\Models\User;
use App\Models\UserEntitlement;
use App\Models\UserInventoryItem;
use App\Services\Progression\AchievementService;
use App\Services\PuzzleAttemptService;

beforeEach(function () {
    $this->achievements = app(AchievementService::class);
    $this->puzzles = app(PuzzleAttemptService::class);
    $this->user = User::factory()->create();
});

/**
 * E12 (تشخيص فعلي): PuzzleAttemptService::attempt() الحقيقية تستدعي
 * afterPuzzleSolved() تلقائيًا - والتي (أ) تمنح XP الأحجية نفسها، و(ب) تُقيِّم
 * كل الإنجازات المطابقة لحدث puzzle_solved تلقائيًا. لعزل مكافأة الإنجاز
 * وحدها بدقة، نجعل الأحجية تمنح صفرًا صراحةً (xp_reward=0, gem_reward=0)
 * - فلا يتبقى أي مصدر XP/عملة إلا الإنجاز نفسه.
 */
function e12SolvePuzzleFor(User $user, PuzzleAttemptService $service): void
{
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح', 'xp_reward' => 0, 'gem_reward' => 0]);
    $service->attempt($user, $puzzle, 'صح');
}

test('E12 req 191/60: an achievement XP reward is granted exactly once via evaluate + retry', function () {
    $achievement = Achievement::factory()->puzzlesSolvedTotal(1)->withXpReward(30)->create();
    e12SolvePuzzleFor($this->user, $this->puzzles);

    $this->achievements->evaluateAchievement($this->user, $achievement);
    $this->achievements->evaluateAchievement($this->user, $achievement); // Retry

    expect($this->user->fresh()->playerProgression->total_xp)->toBe(30)
        ->and(\App\Models\XpTransaction::where('user_id', $this->user->id)->where('type', 'achievement_reward')->count())->toBe(1);
});

test('E12 req 86/87/207: an achievement currency reward is granted via CurrencyWalletService, respecting canEarn() (pending balance - same fraud-safe hold as regular puzzle rewards)', function () {
    $currency = Currency::where('is_earnable', true)->first();
    $achievement = Achievement::factory()->puzzlesSolvedTotal(1)->create([
        'reward_currency_id' => $currency->id,
        'reward_currency_amount' => 25,
    ]);

    // تشخيص صريح (بند تحقيق سابق فشل - 5 بدل 25، ثم 0/25 غير مؤكَّد بعد عزل XP الأحجية):
    // نتحقق من كل خطوة على حدة لتحديد بالضبط أين تُفقَد القيمة عند إعادة التشغيل.
    expect($achievement->fresh()->reward_currency_id)->toBe($currency->id)
        ->and($achievement->fresh()->reward_currency_amount)->toBe(25);

    e12SolvePuzzleFor($this->user, $this->puzzles);

    $progressAfterAutoEval = $this->user->achievementProgress()->where('achievement_id', $achievement->id)->first();

    $this->achievements->evaluateAchievement($this->user, $achievement);

    $progressAfterExplicitEval = $this->user->achievementProgress()->where('achievement_id', $achievement->id)->first();

    $rewardTransaction = \App\Models\CurrencyTransaction::where('user_id', $this->user->id)
        ->where('currency_id', $currency->id)
        ->where('reference_type', $progressAfterExplicitEval?->getMorphClass())
        ->first();

    $wallet = $this->user->wallets()->where('currency_id', $currency->id)->first();

    // كل قيمة تشخيصية اسمها هو التوثيق نفسه - فشل أيٍّ منها يُحدِّد بالضبط أين تُفقَد القيمة،
    // بلا حاجة لتشغيل إضافي أو dd(). الاسم نفسه يظهر في رسالة PHPUnit عند الفشل.
    $achievementUnlockedAfterAutomaticEvaluation = $progressAfterAutoEval?->unlocked_at !== null;
    $rewardMarkedGrantedAfterAutomaticEvaluation = $progressAfterAutoEval?->reward_granted_at !== null;
    $achievementUnlockedAfterExplicitRetry = $progressAfterExplicitEval?->unlocked_at !== null;
    $rewardMarkedGrantedAfterExplicitRetry = $progressAfterExplicitEval?->reward_granted_at !== null;
    $currencyLedgerRowExistsForThisReward = $rewardTransaction !== null;
    $currencyLedgerRowAmount = $rewardTransaction->amount ?? -1;
    $walletPendingBalanceActual = $wallet->pending_balance ?? -1;

    expect($achievementUnlockedAfterAutomaticEvaluation)->toBeTrue()
        ->and($rewardMarkedGrantedAfterAutomaticEvaluation)->toBeTrue()
        ->and($achievementUnlockedAfterExplicitRetry)->toBeTrue()
        ->and($rewardMarkedGrantedAfterExplicitRetry)->toBeTrue()
        ->and($currencyLedgerRowExistsForThisReward)->toBeTrue()
        ->and($currencyLedgerRowAmount)->toBe(25)
        ->and($walletPendingBalanceActual)->toBe(25);
});

test('E12 req 208: a currency reward on a non-earnable currency is rejected safely - no partial state left behind', function () {
    $currency = Currency::factory()->create(['is_earnable' => false, 'is_active' => true]);
    $achievement = Achievement::factory()->puzzlesSolvedTotal(1)->withXpReward(10)->create([
        'reward_currency_id' => $currency->id,
        'reward_currency_amount' => 25,
    ]);

    e12SolvePuzzleFor($this->user, $this->puzzles);

    $thrown = null;
    try {
        $this->achievements->evaluateAchievement($this->user, $achievement);
    } catch (\Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(RuntimeException::class);

    $progress = $this->user->achievementProgress()->where('achievement_id', $achievement->id)->first();
    expect($progress->reward_granted_at)->toBeNull()
        ->and($this->user->fresh()->playerProgression)->toBeNull();
});

test('E12 req 209/148: an inventory cosmetic reward grants real ownership with zero StorePurchase records', function () {
    $item = StoreItem::factory()->cosmeticBadge()->create();
    $achievement = Achievement::factory()->puzzlesSolvedTotal(1)->create([
        'reward_store_item_id' => $item->id,
        'reward_item_quantity' => 1,
    ]);

    e12SolvePuzzleFor($this->user, $this->puzzles);
    $this->achievements->evaluateAchievement($this->user, $achievement);

    expect(UserInventoryItem::where('user_id', $this->user->id)->where('store_item_id', $item->id)->first()->quantity)->toBe(1)
        ->and(\App\Models\StorePurchase::where('user_id', $this->user->id)->count())->toBe(0);
});

test('the free cosmetic reward can then be equipped through the E11 cosmetic system', function () {
    $item = StoreItem::factory()->cosmeticBadge()->create();
    $achievement = Achievement::factory()->puzzlesSolvedTotal(1)->create([
        'reward_store_item_id' => $item->id,
        'reward_item_quantity' => 1,
    ]);

    e12SolvePuzzleFor($this->user, $this->puzzles);
    $this->achievements->evaluateAchievement($this->user, $achievement);

    $loadout = app(\App\Services\PlayerIdentity\CosmeticLoadoutService::class)->equip($this->user, $item);
    expect($loadout->store_item_id)->toBe($item->id);
});

test('E12 req 210: an entitlement reward grants access with zero StorePurchase records', function () {
    $item = StoreItem::factory()->entitlement('achievement.perk')->create();
    $achievement = Achievement::factory()->puzzlesSolvedTotal(1)->create([
        'reward_store_item_id' => $item->id,
    ]);

    e12SolvePuzzleFor($this->user, $this->puzzles);
    $this->achievements->evaluateAchievement($this->user, $achievement);

    expect(UserEntitlement::where('user_id', $this->user->id)->where('store_item_id', $item->id)->exists())->toBeTrue()
        ->and(\App\Models\StorePurchase::where('user_id', $this->user->id)->count())->toBe(0);
});

test('E12 req 152: evaluating an already-unlocked achievement many times never duplicates any reward component', function () {
    $currency = Currency::where('is_earnable', true)->first();
    $item = StoreItem::factory()->cosmeticBadge()->create();
    $achievement = Achievement::factory()->puzzlesSolvedTotal(1)->withXpReward(15)->create([
        'reward_currency_id' => $currency->id,
        'reward_currency_amount' => 10,
        'reward_store_item_id' => $item->id,
        'reward_item_quantity' => 1,
    ]);

    e12SolvePuzzleFor($this->user, $this->puzzles);

    foreach (range(1, 10) as $i) {
        $this->achievements->evaluateAchievement($this->user, $achievement);
    }

    expect($this->user->fresh()->playerProgression->total_xp)->toBe(15)
        ->and($this->user->wallets()->where('currency_id', $currency->id)->first()->pending_balance)->toBe(10)
        ->and(UserInventoryItem::where('user_id', $this->user->id)->where('store_item_id', $item->id)->first()->quantity)->toBe(1);
});