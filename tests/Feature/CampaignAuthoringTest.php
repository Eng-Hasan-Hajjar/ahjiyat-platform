<?php

use App\Filament\Resources\CampaignGateResource;
use App\Filament\Resources\CampaignResource;
use App\Filament\Resources\CampaignStageResource;
use App\Models\Campaign;
use App\Models\CampaignGate;
use App\Models\CampaignStage;
use App\Models\CampaignStep;
use App\Models\Puzzle;

test('CampaignResource is registered against the Campaign model', function () {
    expect(CampaignResource::getModel())->toBe(Campaign::class);
});

test('CampaignStageResource and CampaignGateResource are hidden from the main navigation', function () {
    expect(CampaignStageResource::shouldRegisterNavigation())->toBeFalse()
        ->and(CampaignGateResource::shouldRegisterNavigation())->toBeFalse();
});

test('switching a step from puzzle to narrative clears puzzle_id on save', function () {
    $puzzle = Puzzle::factory()->create();
    $step = CampaignStep::factory()->puzzle()->create(['puzzle_id' => $puzzle->id]);

    expect($step->puzzle_id)->not->toBeNull();

    $step->kind = CampaignStep::KIND_NARRATIVE;
    $step->save();

    expect($step->fresh()->puzzle_id)->toBeNull();
});

test('a narrative step never persists a puzzle_id even if one is force-set before saving', function () {
    $puzzle = Puzzle::factory()->create();
    $step = CampaignStep::factory()->make(['kind' => CampaignStep::KIND_NARRATIVE, 'puzzle_id' => $puzzle->id]);
    $step->save();

    expect($step->fresh()->puzzle_id)->toBeNull();
});

test('switching reward_mode away from override clears reward_override_amount on save', function () {
    $step = CampaignStep::factory()->puzzle()->create(['reward_mode' => 'override', 'reward_override_amount' => 50]);

    expect($step->reward_override_amount)->toBe(50);

    $step->reward_mode = 'inherit';
    $step->save();

    expect($step->fresh()->reward_override_amount)->toBeNull();
});

test('reward_override_amount is never persisted for reward_mode=none even if force-set', function () {
    $step = CampaignStep::factory()->puzzle()->make(['reward_mode' => 'none', 'reward_override_amount' => 999]);
    $step->save();

    expect($step->fresh()->reward_override_amount)->toBeNull();
});

test('an override step keeps its override amount untouched when re-saved without changes', function () {
    $step = CampaignStep::factory()->puzzle()->create(['reward_mode' => 'override', 'reward_override_amount' => 33]);

    $step->title = 'عنوان محدَّث';
    $step->save();

    expect($step->fresh()->reward_override_amount)->toBe(33);
});

test('removing the qualification rule clears the qualification config on save', function () {
    $gate = CampaignGate::factory()->create(['qualification_rule' => 'first_n', 'qualification_config' => ['limit' => 100]]);

    expect($gate->qualification_config)->toBe(['limit' => 100]);

    $gate->qualification_rule = null;
    $gate->save();

    expect($gate->fresh()->qualification_config)->toBeNull();
});

test('a first_n qualification_config with a valid limit is preserved as-is', function () {
    $gate = CampaignGate::factory()->create(['qualification_rule' => 'first_n', 'qualification_config' => ['limit' => 250]]);

    expect($gate->fresh()->qualification_config)->toBe(['limit' => 250]);
});

test('a gate with no qualification_rule stores no config, by default', function () {
    $gate = CampaignGate::factory()->create();

    expect($gate->qualification_rule)->toBeNull()
        ->and($gate->qualification_config)->toBeNull();
});