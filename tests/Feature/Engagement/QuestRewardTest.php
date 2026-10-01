<?php

use App\Models\Currency;
use App\Models\Puzzle;
use App\Models\QuestDefinition;
use App\Models\StoreItem;
use App\Models\User;
use App\Models\UserEntitlement;
use App\Models\UserInventoryItem;
use App\Services\Engagement\QuestService;
use App\Services\PuzzleAttemptService;

beforeEach(function () {
    $this->quests = app(QuestService::class);
    $this->puzzles = app(PuzzleAttemptService::class);
    $this->user = User::factory()->create();
});

/** عزل تام لمنح XP/العملة الخاص بالأحجية نفسها - نفس درس E12 (منع التلوث عند قياس مكافأة المهمة وحدها). */
function e13SolveIsolatedPuzzleFor(User $user, PuzzleAttemptService $service): void
{
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح', 'xp_reward' => 0, 'gem_reward' => 0]);
    $service->attempt($user, $puzzle, 'صح');
}

test('E13 req 51/436: quest XP reward is granted exactly once even if re-evaluated manually afterward', function () {
    $quest = QuestDefinition::factory()->daily(1)->withXpReward(30)->create();

    e13SolveIsolatedPuzzleFor($this->user, $this->puzzles);
    $this->quests->evaluateQuest($this->user, $quest); // إعادة تقييم يدوية - يجب ألا تُضاعِف شيئًا

    expect($this->user->fresh()->playerProgression->total_xp)->toBe(30)
        ->and(\App\Models\XpTransaction::where('user_id', $this->user->id)->where('type', \App\Models\XpTransaction::TYPE_QUEST_REWARD)->count())->toBe(1);
});

test('E13 req 59/147/187: quest currency reward enters the Pending lifecycle, never Available directly', function () {
    $currency = Currency::where('is_earnable', true)->first();
    $quest = QuestDefinition::factory()->daily(1)->create([
        'reward_currency_id' => $currency->id,
        'reward_currency_amount' => 25,
    ]);

    e13SolveIsolatedPuzzleFor($this->user, $this->puzzles);

    $wallet = $this->user->wallets()->where('currency_id', $currency->id)->first();

    expect($wallet->pending_balance)->toBe(25)
        ->and($wallet->available_balance)->toBe(0);
});

test('E13 req 270/436: a non-earnable reward currency fails safely - no partial state, retry-safe', function () {
    $currency = Currency::factory()->create(['is_earnable' => false, 'is_active' => true]);
    $quest = QuestDefinition::factory()->daily(1)->withXpReward(10)->create([
        'reward_currency_id' => $currency->id,
        'reward_currency_amount' => 25,
    ]);

    e13SolveIsolatedPuzzleFor($this->user, $this->puzzles);

    $progress = \App\Models\UserQuestProgress::where('user_id', $this->user->id)->where('quest_definition_id', $quest->id)->first();

    expect($progress->completed_at)->not->toBeNull()
        ->and($progress->reward_granted_at)->toBeNull();

    // بند 159/348: فشل جزئي - XP لم تُمنَح أيضًا (المعاملة كاملة تتراجع معًا)، لا حالة غير متَّسقة.
    expect($this->user->fresh()->playerProgression)->toBeNull();
});

test('E13 req 266/442: a cosmetic inventory reward grants real ownership with zero StorePurchase records', function () {
    $item = StoreItem::factory()->cosmeticBadge()->create();
    $quest = QuestDefinition::factory()->daily(1)->create(['reward_store_item_id' => $item->id, 'reward_item_quantity' => 1]);

    e13SolveIsolatedPuzzleFor($this->user, $this->puzzles);

    expect(UserInventoryItem::where('user_id', $this->user->id)->where('store_item_id', $item->id)->first()->quantity)->toBe(1)
        ->and(\App\Models\StorePurchase::where('user_id', $this->user->id)->count())->toBe(0);
});

test('E13 req 268: an auto-fulfillable entitlement reward grants access with zero StorePurchase records', function () {
    $item = StoreItem::factory()->entitlement('quest.perk')->create();
    $quest = QuestDefinition::factory()->daily(1)->create(['reward_store_item_id' => $item->id]);

    e13SolveIsolatedPuzzleFor($this->user, $this->puzzles);

    expect(UserEntitlement::where('user_id', $this->user->id)->where('store_item_id', $item->id)->exists())->toBeTrue()
        ->and(\App\Models\StorePurchase::where('user_id', $this->user->id)->count())->toBe(0);
});

test('E13 req 152: evaluating an already-completed quest many times never duplicates any reward component', function () {
    $currency = Currency::where('is_earnable', true)->first();
    $item = StoreItem::factory()->cosmeticBadge()->create();
    $quest = QuestDefinition::factory()->daily(1)->withXpReward(15)->create([
        'reward_currency_id' => $currency->id,
        'reward_currency_amount' => 10,
        'reward_store_item_id' => $item->id,
        'reward_item_quantity' => 1,
    ]);

    e13SolveIsolatedPuzzleFor($this->user, $this->puzzles);

    foreach (range(1, 10) as $i) {
        $this->quests->evaluateQuest($this->user, $quest);
    }

    expect($this->user->fresh()->playerProgression->total_xp)->toBe(15)
        ->and($this->user->wallets()->where('currency_id', $currency->id)->first()->pending_balance)->toBe(10)
        ->and(UserInventoryItem::where('user_id', $this->user->id)->where('store_item_id', $item->id)->first()->quantity)->toBe(1);
});

test('E13 req 350: completed before expiry with failed reward can still be retried successfully after the period ends', function () {
    $currency = Currency::factory()->create(['is_earnable' => false, 'is_active' => true]);
    $quest = QuestDefinition::factory()->daily(1)->create(['reward_currency_id' => $currency->id, 'reward_currency_amount' => 20]);

    e13SolveIsolatedPuzzleFor($this->user, $this->puzzles);

    $progress = \App\Models\UserQuestProgress::where('user_id', $this->user->id)->where('quest_definition_id', $quest->id)->first();
    expect($progress->completed_at)->not->toBeNull()->and($progress->reward_granted_at)->toBeNull();

    // "الفترة تنتهي" ثم تُصلَح العملة - المحاولة اللاحقة يجب أن تنجح رغم انتهاء الفترة أصلًا.
    $currency->update(['is_earnable' => true]);
    $this->quests->evaluateQuest($this->user, $quest);

    expect($progress->fresh()->reward_granted_at)->not->toBeNull()
        ->and($this->user->wallets()->where('currency_id', $currency->id)->first()->pending_balance)->toBe(20);
});
