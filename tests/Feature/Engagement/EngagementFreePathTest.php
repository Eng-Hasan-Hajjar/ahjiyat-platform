<?php

use App\Models\Currency;
use App\Models\Puzzle;
use App\Models\QuestDefinition;
use App\Models\StorePurchase;
use App\Models\User;
use App\Models\UserEntitlement;
use App\Models\UserInventoryItem;
use App\Models\UserQuestProgress;
use App\Services\Economy\CurrencyRegistry;
use App\Services\PuzzleAttemptService;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->puzzles = app(PuzzleAttemptService::class);
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * E13 req 441 (أهم اختبار بهذه المرحلة): السيناريو الكامل من المواصفة
 * حرفيًا - لاعب مجاني 100%، يومان متتاليان، إثبات الدورة الكاملة.
 */
test('E13 CRITICAL req 441: a genuinely free user completes Daily then Weekly quests across two days with zero payment dependency', function () {
    $user = User::factory()->create();

    // بند 258: صفر مميز، صفر مشتريات، صفر امتيازات من أي نوع كخط أساس.
    expect(StorePurchase::where('user_id', $user->id)->count())->toBe(0)
        ->and(UserEntitlement::where('user_id', $user->id)->count())->toBe(0);

    $daily = QuestDefinition::factory()->daily(2)->withXpReward(20)->create(['reward_currency_amount' => 10, 'reward_currency_id' => app(CurrencyRegistry::class)->defaultEarnedCurrency()->id]);
    $weekly = QuestDefinition::factory()->weekly(3)->withXpReward(100)->create(['reward_currency_amount' => 50, 'reward_currency_id' => app(CurrencyRegistry::class)->defaultEarnedCurrency()->id]);

    Carbon::setTestNow(Carbon::parse('2026-10-05 09:00:00', 'UTC')); // Monday

    // --- اليوم الأول: حلّان ---
    $this->puzzles->attempt($user, Puzzle::factory()->create(['answer_raw' => 'ص1', 'xp_reward' => 0, 'gem_reward' => 0]), 'ص1');

    $dailyProgressDay1 = UserQuestProgress::where('user_id', $user->id)->where('quest_definition_id', $daily->id)->first();
    $weeklyProgress = UserQuestProgress::where('user_id', $user->id)->where('quest_definition_id', $weekly->id)->first();
    $streak = \App\Models\PlayerStreak::where('user_id', $user->id)->first();

    expect($dailyProgressDay1->current_value)->toBe(1)
        ->and($weeklyProgress->current_value)->toBe(1)
        ->and($streak->current_streak)->toBe(1);

    $this->puzzles->attempt($user, Puzzle::factory()->create(['answer_raw' => 'ص2', 'xp_reward' => 0, 'gem_reward' => 0]), 'ص2');

    $dailyProgressDay1->refresh();
    $weeklyProgress->refresh();

    expect($dailyProgressDay1->current_value)->toBe(2)
        ->and($dailyProgressDay1->completed_at)->not->toBeNull()
        ->and($dailyProgressDay1->reward_granted_at)->not->toBeNull()
        ->and($weeklyProgress->current_value)->toBe(2)
        ->and($weeklyProgress->completed_at)->toBeNull()
        ->and(\App\Models\PlayerStreak::where('user_id', $user->id)->first()->current_streak)->toBe(1);

    // --- اليوم الثاني: حل ثالث ---
    Carbon::setTestNow(Carbon::parse('2026-10-06 09:00:00', 'UTC'));
    $this->puzzles->attempt($user, Puzzle::factory()->create(['answer_raw' => 'ص3', 'xp_reward' => 0, 'gem_reward' => 0]), 'ص3');

    $dailyProgressDay2 = UserQuestProgress::where('user_id', $user->id)->where('quest_definition_id', $daily->id)
        ->where('period_key', 'daily:2026-10-06')->first();
    $weeklyProgress->refresh();
    $streak = \App\Models\PlayerStreak::where('user_id', $user->id)->first();

    expect($dailyProgressDay2->current_value)->toBe(1) // فترة يومية جديدة تمامًا
        ->and($dailyProgressDay2->completed_at)->toBeNull()
        ->and($weeklyProgress->current_value)->toBe(3)
        ->and($weeklyProgress->completed_at)->not->toBeNull()
        ->and($weeklyProgress->reward_granted_at)->not->toBeNull()
        ->and($streak->current_streak)->toBe(2);

    // --- التحقُّقات النهائية: XP، عملة، بلا دفع ---
    $currency = app(CurrencyRegistry::class)->defaultEarnedCurrency();
    $wallet = $user->wallets()->where('currency_id', $currency->id)->first();

    expect($user->fresh()->playerProgression->total_xp)->toBe(120) // 20 (يومية) + 100 (أسبوعية)
        ->and($wallet->pending_balance)->toBe(60) // 10 + 50
        ->and($wallet->available_balance)->toBe(0)
        ->and(StorePurchase::where('user_id', $user->id)->count())->toBe(0);
});

test('E13 req 442/266: a free cosmetic quest reward grants ownership with zero StorePurchase', function () {
    $user = User::factory()->create();
    $item = \App\Models\StoreItem::factory()->cosmeticBadge()->create();
    QuestDefinition::factory()->daily(1)->create(['reward_store_item_id' => $item->id, 'reward_item_quantity' => 1]);

    $this->puzzles->attempt($user, Puzzle::factory()->create(['answer_raw' => 'صح']), 'صح');

    expect(UserInventoryItem::where('user_id', $user->id)->where('store_item_id', $item->id)->exists())->toBeTrue()
        ->and(StorePurchase::where('user_id', $user->id)->count())->toBe(0);
});

test('E13 req 443/346/290: free user and premium-holding user produce identical quest progress and streak for identical events', function () {
    $freeUser = User::factory()->create();
    $premiumUser = User::factory()->create();

    $premiumCurrency = Currency::where('is_earnable', false)->where('is_purchasable', true)->first();
    if ($premiumCurrency) {
        app(\App\Services\Economy\CurrencyWalletService::class)->creditAvailable($premiumUser, $premiumCurrency, 100000, 'test-premium-grant');
    }

    $quest = QuestDefinition::factory()->daily(2)->create();
    $puzzleA = Puzzle::factory()->create(['answer_raw' => 'صح']);
    $puzzleB = Puzzle::factory()->create(['answer_raw' => 'صح']);

    $this->puzzles->attempt($freeUser, $puzzleA, 'صح');
    $this->puzzles->attempt($premiumUser, $puzzleA, 'صح');
    $this->puzzles->attempt($freeUser, $puzzleB, 'صح');
    $this->puzzles->attempt($premiumUser, $puzzleB, 'صح');

    $freeProgress = UserQuestProgress::where('user_id', $freeUser->id)->where('quest_definition_id', $quest->id)->first();
    $premiumProgress = UserQuestProgress::where('user_id', $premiumUser->id)->where('quest_definition_id', $quest->id)->first();

    expect($freeProgress->current_value)->toBe($premiumProgress->current_value)
        ->and(\App\Models\PlayerStreak::where('user_id', $freeUser->id)->first()->current_streak)
        ->toBe(\App\Models\PlayerStreak::where('user_id', $premiumUser->id)->first()->current_streak);
});

test('E13 req 444: higher level grants no extra quest progress for the same event', function () {
    $lowUser = User::factory()->create();
    $highUser = User::factory()->create();
    app(\App\Services\Progression\XpService::class)->grantXp($highUser, 5000, \App\Models\XpTransaction::TYPE_PUZZLE_SOLVE, 'test-level-boost');

    $quest = QuestDefinition::factory()->daily(1)->create();
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح']);
    $puzzle2 = Puzzle::factory()->create(['answer_raw' => 'صح']);

    $this->puzzles->attempt($lowUser, $puzzle, 'صح');
    $this->puzzles->attempt($highUser, $puzzle2, 'صح');

    $lowProgress = UserQuestProgress::where('user_id', $lowUser->id)->where('quest_definition_id', $quest->id)->first();
    $highProgress = UserQuestProgress::where('user_id', $highUser->id)->where('quest_definition_id', $quest->id)->first();

    expect($lowProgress->current_value)->toBe($highProgress->current_value);
});

test('E13 req 328/347/445: a user with zero wallet balance can still complete a core quest', function () {
    $user = User::factory()->create();
    $currency = app(CurrencyRegistry::class)->defaultEarnedCurrency();

    expect($user->wallets()->where('currency_id', $currency->id)->first()?->available_balance ?? 0)->toBe(0);

    $quest = QuestDefinition::factory()->daily(1)->create();
    $this->puzzles->attempt($user, Puzzle::factory()->create(['answer_raw' => 'صح']), 'صح');

    $progress = UserQuestProgress::where('user_id', $user->id)->where('quest_definition_id', $quest->id)->first();
    expect($progress->completed_at)->not->toBeNull();
});
