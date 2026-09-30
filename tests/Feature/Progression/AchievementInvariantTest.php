<?php

use App\Exceptions\StoreItemInvariantViolation;
use App\Models\Achievement;
use App\Models\Puzzle;
use App\Models\StoreItem;
use App\Models\User;
use App\Services\Progression\AchievementService;
use App\Services\PuzzleAttemptService;

beforeEach(function () {
    $this->achievements = app(AchievementService::class);
    $this->puzzles = app(PuzzleAttemptService::class);
});

test('condition_type must come from the closed registry list - an arbitrary string is rejected', function () {
    expect(fn () => Achievement::factory()->create(['condition_type' => 'buy_currency_pack']))
        ->toThrow(StoreItemInvariantViolation::class);
});

test('E12 req 156: the registry contains no commercial/payment condition types at all', function () {
    $registryOptions = array_keys(app(\App\Services\Progression\AchievementEvaluatorRegistry::class)->options());

    foreach ($registryOptions as $type) {
        foreach (['purchase', 'payment', 'premium', 'spend', 'pack', 'checkout'] as $forbidden) {
            expect(str_contains($type, $forbidden))->toBeFalse();
        }
    }
});

test('E12 req 193: after a user has progress, sensitive fields are locked - direct model write is rejected', function () {
    $achievement = Achievement::factory()->puzzlesSolvedTotal(1)->create();
    $user = User::factory()->create();
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح']);
    $this->puzzles->attempt($user, $puzzle, 'صح');
    $this->achievements->evaluateForEvent('puzzle_solved', $user);

    expect(fn () => $achievement->fresh()->update(['condition_type' => 'qualifications_earned_total']))
        ->toThrow(StoreItemInvariantViolation::class);

    expect(fn () => $achievement->fresh()->update(['internal_key' => 'changed_key']))
        ->toThrow(StoreItemInvariantViolation::class);

    expect(fn () => $achievement->fresh()->update(['target_value' => 999]))
        ->toThrow(StoreItemInvariantViolation::class);
});

test('display fields remain editable after a user has progress', function () {
    $achievement = Achievement::factory()->puzzlesSolvedTotal(1)->create();
    $user = User::factory()->create();
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح']);
    $this->puzzles->attempt($user, $puzzle, 'صح');
    $this->achievements->evaluateForEvent('puzzle_solved', $user);

    $achievement->fresh()->update(['name' => 'اسم جديد']);

    expect($achievement->fresh()->name)->toBe('اسم جديد');
});

test('an unused achievement can still change its condition freely, within valid rules', function () {
    $achievement = Achievement::factory()->puzzlesSolvedTotal(1)->create();

    $achievement->update(['condition_type' => 'qualifications_earned_total', 'target_value' => 3]);

    expect($achievement->fresh()->condition_type)->toBe('qualifications_earned_total');
});

test('a used achievement cannot be deleted, an unused one can', function () {
    $usedAchievement = Achievement::factory()->puzzlesSolvedTotal(1)->create();

    $user = User::factory()->create();
    // E12 (تشخيص فعلي): تُنشَأ الأحجية بلا أي مصدر XP/عملة، والحل الحقيقي يُقيِّم تلقائيًا
    // كل إنجاز puzzles_solved_total موجود وقت الحل - لذا ننشئ unusedAchievement بعد الحل
    // مباشرة، فلا يراها التقييم التلقائي إطلاقًا وتبقى فعليًا غير مُستخدَمة.
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح', 'xp_reward' => 0, 'gem_reward' => 0]);
    $this->puzzles->attempt($user, $puzzle, 'صح');
    $this->achievements->evaluateAchievement($user, $usedAchievement);

    $unusedAchievement = Achievement::factory()->puzzlesSolvedTotal(1)->create();

    expect(fn () => $usedAchievement->delete())->toThrow(StoreItemInvariantViolation::class);

    $unusedAchievement->delete();
    expect(Achievement::find($unusedAchievement->id))->toBeNull();
});

test('E12 req 95/150: a manual-fulfillment store item is rejected as an achievement reward at the domain level', function () {
    $manualItem = StoreItem::factory()->manual()->create();

    expect(fn () => Achievement::factory()->create([
        'reward_store_item_id' => $manualItem->id,
        'reward_item_quantity' => 1,
    ]))->toThrow(StoreItemInvariantViolation::class);
});

test('an inventory-fulfillment store item is accepted as an achievement reward', function () {
    $inventoryItem = StoreItem::factory()->cosmeticBadge()->create();

    $achievement = Achievement::factory()->create([
        'reward_store_item_id' => $inventoryItem->id,
        'reward_item_quantity' => 1,
    ]);

    expect($achievement->reward_store_item_id)->toBe($inventoryItem->id);
});

test('target_value must be positive when set', function () {
    expect(fn () => Achievement::factory()->create(['target_value' => 0]))->toThrow(StoreItemInvariantViolation::class);
});

test('scope is cleared automatically for a condition type that does not need one', function () {
    $achievement = Achievement::factory()->puzzlesSolvedTotal(1)->create(['scope_type' => 'puzzle_category', 'scope_id' => 999]);

    expect($achievement->fresh()->scope_type)->toBeNull()
        ->and($achievement->fresh()->scope_id)->toBeNull();
});