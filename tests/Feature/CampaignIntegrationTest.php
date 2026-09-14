<?php

use App\Models\Campaign;
use App\Models\CampaignGate;
use App\Models\CampaignStage;
use App\Models\CampaignStep;
use App\Models\Puzzle;
use App\Models\User;
use App\Services\CampaignProgressService;

test('narrative → puzzle → gate 1 completion → gate 2 unlocks (qualification=none)', function () {
    $progress = app(CampaignProgressService::class);

    $campaign = Campaign::factory()->create(['is_active' => true]);
    $stage = CampaignStage::factory()->create(['campaign_id' => $campaign->id, 'sort_order' => 1]);

    $gate1 = CampaignGate::factory()->create(['campaign_stage_id' => $stage->id, 'sort_order' => 1]);
    $narrativeStep = CampaignStep::factory()->create(['campaign_gate_id' => $gate1->id, 'sort_order' => 1]);
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح']);
    $puzzleStep = CampaignStep::factory()->puzzle()->create(['campaign_gate_id' => $gate1->id, 'sort_order' => 2, 'puzzle_id' => $puzzle->id]);

    $gate2 = CampaignGate::factory()->create(['campaign_stage_id' => $stage->id, 'sort_order' => 2]);
    CampaignStep::factory()->create(['campaign_gate_id' => $gate2->id, 'sort_order' => 1]);

    $user = User::factory()->create();

    expect($progress->stepState($user, $narrativeStep))->toBe(CampaignProgressService::STATE_AVAILABLE);

    $this->actingAs($user)->post(route('campaigns.steps.complete', [$campaign, $narrativeStep]));
    expect($progress->isStepCompleted($user, $narrativeStep->fresh()))->toBeTrue();

    expect($progress->stepState($user, $puzzleStep->fresh()))->toBe(CampaignProgressService::STATE_AVAILABLE);

    $this->actingAs($user)->post(route('campaigns.steps.attempt', [$campaign, $puzzleStep]), ['answer' => 'صح']);
    expect($progress->isStepCompleted($user, $puzzleStep->fresh()))->toBeTrue();

    expect($progress->isGateCompleted($user, $gate1->fresh()))->toBeTrue();

    expect($progress->isGateUnlocked($user, $gate2->fresh()))->toBeTrue();
});

test('first-n limit=1: first completer qualifies, second is completed-but-locked-out of the next gate', function () {
    $progress = app(CampaignProgressService::class);

    $campaign = Campaign::factory()->create(['is_active' => true]);
    $stage = CampaignStage::factory()->create(['campaign_id' => $campaign->id, 'sort_order' => 1]);

    $gate1 = CampaignGate::factory()->create([
        'campaign_stage_id' => $stage->id, 'sort_order' => 1,
        'qualification_rule' => 'first_n', 'qualification_config' => ['limit' => 1],
    ]);
    $step = CampaignStep::factory()->create(['campaign_gate_id' => $gate1->id, 'sort_order' => 1]);

    $gate2 = CampaignGate::factory()->create(['campaign_stage_id' => $stage->id, 'sort_order' => 2]);
    CampaignStep::factory()->create(['campaign_gate_id' => $gate2->id, 'sort_order' => 1]);

    $userA = User::factory()->create();
    $userB = User::factory()->create();

    $this->actingAs($userA)->post(route('campaigns.steps.complete', [$campaign, $step]));
    $this->actingAs($userB)->post(route('campaigns.steps.complete', [$campaign, $step]));

    expect($progress->isGateCompleted($userA, $gate1->fresh()))->toBeTrue()
        ->and($progress->isGateCompleted($userB, $gate1->fresh()))->toBeTrue()
        ->and($progress->gateState($userA, $gate1->fresh()))->toBe(CampaignProgressService::STATE_COMPLETED)
        ->and($progress->gateState($userB, $gate1->fresh()))->toBe(CampaignProgressService::STATE_NOT_QUALIFIED);

    expect($progress->isGateUnlocked($userA, $gate2->fresh()))->toBeTrue()
        ->and($progress->isGateUnlocked($userB, $gate2->fresh()))->toBeFalse();
});