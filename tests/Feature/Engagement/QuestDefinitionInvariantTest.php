<?php

use App\Exceptions\StoreItemInvariantViolation;
use App\Models\Puzzle;
use App\Models\QuestDefinition;
use App\Models\StoreItem;
use App\Models\User;
use App\Services\Engagement\QuestService;
use App\Services\PuzzleAttemptService;

beforeEach(function () {
    $this->quests = app(QuestService::class);
    $this->puzzles = app(PuzzleAttemptService::class);
});

test('period_type must be daily or weekly - an arbitrary string is rejected', function () {
    expect(fn () => QuestDefinition::factory()->create(['period_type' => 'monthly']))
        ->toThrow(StoreItemInvariantViolation::class);
});

test('condition_type must come from the closed registry list - an arbitrary string is rejected', function () {
    expect(fn () => QuestDefinition::factory()->create(['condition_type' => 'buy_currency_pack']))
        ->toThrow(StoreItemInvariantViolation::class);
});

test('E13 req 236: the registry contains no commercial/payment condition types at all', function () {
    $options = array_keys(app(\App\Services\Engagement\QuestEvaluatorRegistry::class)->options());

    foreach ($options as $type) {
        foreach (['purchase', 'payment', 'premium', 'spend', 'pack', 'checkout'] as $forbidden) {
            expect(str_contains($type, $forbidden))->toBeFalse();
        }
    }
});

test('target_value must be positive', function () {
    expect(fn () => QuestDefinition::factory()->create(['target_value' => 0]))->toThrow(StoreItemInvariantViolation::class);
});

test('a manual-fulfillment store item is rejected as a quest reward at the domain level', function () {
    $manualItem = StoreItem::factory()->manual()->create();

    expect(fn () => QuestDefinition::factory()->create([
        'reward_store_item_id' => $manualItem->id,
        'reward_item_quantity' => 1,
    ]))->toThrow(StoreItemInvariantViolation::class);
});

test('an inventory-fulfillment store item is accepted as a quest reward', function () {
    $item = StoreItem::factory()->cosmeticBadge()->create();

    $quest = QuestDefinition::factory()->create(['reward_store_item_id' => $item->id, 'reward_item_quantity' => 1]);

    expect($quest->reward_store_item_id)->toBe($item->id);
});

test('after a user has progress, sensitive fields are locked - direct model write is rejected', function () {
    $quest = QuestDefinition::factory()->daily(1)->create();
    $user = User::factory()->create();
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح']);
    $this->puzzles->attempt($user, $puzzle, 'صح');
    app(\App\Services\Engagement\EngagementService::class)->onPuzzleSolved($user, $user->puzzleAttempts()->latest()->first());

    expect(fn () => $quest->fresh()->update(['target_value' => 999]))
        ->toThrow(StoreItemInvariantViolation::class);

    expect(fn () => $quest->fresh()->update(['period_type' => QuestDefinition::PERIOD_WEEKLY]))
        ->toThrow(StoreItemInvariantViolation::class);

    expect(fn () => $quest->fresh()->update(['condition_type' => 'qualifications_earned']))
        ->toThrow(StoreItemInvariantViolation::class);
});

test('display fields remain editable after a user has progress', function () {
    $quest = QuestDefinition::factory()->daily(1)->create();
    $user = User::factory()->create();
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح']);
    $this->puzzles->attempt($user, $puzzle, 'صح');
    app(\App\Services\Engagement\EngagementService::class)->onPuzzleSolved($user, $user->puzzleAttempts()->latest()->first());

    $quest->fresh()->update(['name' => 'اسم جديد']);

    expect($quest->fresh()->name)->toBe('اسم جديد');
});

test('an unused quest can still change its condition freely', function () {
    $quest = QuestDefinition::factory()->daily(1)->create();

    $quest->update(['condition_type' => 'qualifications_earned', 'target_value' => 3]);

    expect($quest->fresh()->condition_type)->toBe('qualifications_earned');
});

test('a used quest cannot be deleted, an unused one can', function () {
    $usedQuest = QuestDefinition::factory()->daily(1)->create();
    $user = User::factory()->create();
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح']);
    $this->puzzles->attempt($user, $puzzle, 'صح');
    app(\App\Services\Engagement\EngagementService::class)->onPuzzleSolved($user, $user->puzzleAttempts()->latest()->first());

    $unusedQuest = QuestDefinition::factory()->daily(1)->create();

    expect(fn () => $usedQuest->delete())->toThrow(StoreItemInvariantViolation::class);

    $unusedQuest->delete();
    expect(QuestDefinition::find($unusedQuest->id))->toBeNull();
});

test('scope is cleared automatically for a condition type that does not need one', function () {
    $quest = QuestDefinition::factory()->daily(1)->create(['scope_type' => 'puzzle_category', 'scope_id' => 999]);

    expect($quest->fresh()->scope_type)->toBeNull()
        ->and($quest->fresh()->scope_id)->toBeNull();
});
