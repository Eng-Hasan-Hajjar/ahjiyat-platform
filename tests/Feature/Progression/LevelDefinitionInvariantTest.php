<?php

use App\Exceptions\StoreItemInvariantViolation;
use App\Models\LevelDefinition;
use App\Models\User;
use App\Services\Progression\LevelService;
use App\Services\Progression\XpService;

beforeEach(function () {
    $this->xp = app(XpService::class);
});

test('level 1 must always have a zero xp threshold - direct model write is rejected otherwise', function () {
    expect(fn () => LevelDefinition::factory()->create(['level_number' => 1, 'xp_required_total' => 50]))
        ->toThrow(StoreItemInvariantViolation::class);
});

test('E12 req strict ordering: a level cannot have an xp threshold lower than or equal to its immediate predecessor', function () {
    LevelDefinition::factory()->first()->create();
    LevelDefinition::factory()->create(['level_number' => 2, 'xp_required_total' => 100]);

    expect(fn () => LevelDefinition::factory()->create(['level_number' => 3, 'xp_required_total' => 100]))
        ->toThrow(StoreItemInvariantViolation::class);

    expect(fn () => LevelDefinition::factory()->create(['level_number' => 3, 'xp_required_total' => 50]))
        ->toThrow(StoreItemInvariantViolation::class);
});

test('a level cannot have an xp threshold higher than or equal to its immediate successor', function () {
    LevelDefinition::factory()->first()->create();
    LevelDefinition::factory()->create(['level_number' => 2, 'xp_required_total' => 100]);
    LevelDefinition::factory()->create(['level_number' => 3, 'xp_required_total' => 200]);

    $level2 = LevelDefinition::where('level_number', 2)->first();

    expect(fn () => $level2->update(['xp_required_total' => 200]))->toThrow(StoreItemInvariantViolation::class);
});

test('E12 req 188: a used level threshold and number cannot change via direct Eloquent write', function () {
    LevelDefinition::factory()->first()->create();
    $level2 = LevelDefinition::factory()->create(['level_number' => 2, 'xp_required_total' => 100]);
    $user = User::factory()->create();
    $this->xp->grantXp($user, 100, \App\Models\XpTransaction::TYPE_PUZZLE_SOLVE, 'test');

    expect(fn () => $level2->fresh()->update(['xp_required_total' => 500]))->toThrow(StoreItemInvariantViolation::class);
    expect(fn () => $level2->fresh()->update(['level_number' => 20]))->toThrow(StoreItemInvariantViolation::class);

    expect($level2->fresh()->xp_required_total)->toBe(100);
});

test('an unused level can still change its threshold and number freely, within valid ordering', function () {
    LevelDefinition::factory()->first()->create();
    $level2 = LevelDefinition::factory()->create(['level_number' => 2, 'xp_required_total' => 100]);

    $level2->update(['xp_required_total' => 120]);

    expect($level2->fresh()->xp_required_total)->toBe(120);
});

test('display fields remain editable on a used level - name, description, icon, color', function () {
    LevelDefinition::factory()->first()->create();
    $level2 = LevelDefinition::factory()->create(['level_number' => 2, 'xp_required_total' => 100]);
    $user = User::factory()->create();
    $this->xp->grantXp($user, 100, \App\Models\XpTransaction::TYPE_PUZZLE_SOLVE, 'test');

    $level2->fresh()->update(['name' => 'اسم جديد', 'description' => 'وصف جديد']);

    expect($level2->fresh()->name)->toBe('اسم جديد');
});

test('E12 req: a used level cannot be deleted, an unused one can', function () {
    LevelDefinition::factory()->first()->create();
    $level2 = LevelDefinition::factory()->create(['level_number' => 2, 'xp_required_total' => 100]);
    $level3 = LevelDefinition::factory()->create(['level_number' => 3, 'xp_required_total' => 300]);

    $user = User::factory()->create();
    $this->xp->grantXp($user, 100, \App\Models\XpTransaction::TYPE_PUZZLE_SOLVE, 'test');

    expect(fn () => $level2->delete())->toThrow(StoreItemInvariantViolation::class);
    expect(LevelDefinition::find($level2->id))->not->toBeNull();

    $level3->delete();
    expect(LevelDefinition::find($level3->id))->toBeNull();
});

test('level_number must be a positive integer', function () {
    expect(fn () => LevelDefinition::factory()->create(['level_number' => 0]))->toThrow(StoreItemInvariantViolation::class);
});