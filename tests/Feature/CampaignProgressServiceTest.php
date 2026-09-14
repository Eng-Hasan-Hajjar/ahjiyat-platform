<?php

use App\Models\Campaign;
use App\Models\CampaignGate;
use App\Models\CampaignStage;
use App\Models\CampaignStep;
use App\Models\GameSession;
use App\Models\Puzzle;
use App\Models\PuzzleAttempt;
use App\Models\User;
use App\Models\UserCampaignProgress;
use App\Services\CampaignProgressService;

beforeEach(function () {
    $this->service = app(CampaignProgressService::class);
});

// ===== A. Campaign Availability =====

test('an inactive campaign is unavailable', function () {
    $campaign = Campaign::factory()->create(['is_active' => false]);

    expect($this->service->isCampaignAvailable($campaign))->toBeFalse();
});

test('a campaign before its starts_at is unavailable', function () {
    $campaign = Campaign::factory()->create(['starts_at' => now()->addDay()]);

    expect($this->service->isCampaignAvailable($campaign))->toBeFalse();
});

test('a campaign after its ends_at is unavailable', function () {
    $campaign = Campaign::factory()->create(['ends_at' => now()->subDay()]);

    expect($this->service->isCampaignAvailable($campaign))->toBeFalse();
});

test('an active campaign within its window is available', function () {
    $campaign = Campaign::factory()->create([
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDay(),
    ]);

    expect($this->service->isCampaignAvailable($campaign))->toBeTrue();
});

// ===== B/C/D. Unlock (Stage / Gate / Step) =====

test('the first stage is unlocked when the campaign is available', function () {
    $campaign = Campaign::factory()->create();
    $stage = CampaignStage::factory()->create(['campaign_id' => $campaign->id, 'sort_order' => 1]);
    $user = User::factory()->create();

    expect($this->service->isStageUnlocked($user, $stage))->toBeTrue();
});

test('a later stage stays locked until the previous stage is complete', function () {
    $campaign = Campaign::factory()->create();
    $first = CampaignStage::factory()->create(['campaign_id' => $campaign->id, 'sort_order' => 1]);
    $second = CampaignStage::factory()->create(['campaign_id' => $campaign->id, 'sort_order' => 2]);
    CampaignGate::factory()->create(['campaign_stage_id' => $first->id, 'sort_order' => 1]); // فارغة = غير مكتملة
    $user = User::factory()->create();

    expect($this->service->isStageUnlocked($user, $second))->toBeFalse();
});

test('the first gate is unlocked when its stage is unlocked', function () {
    $stage = CampaignStage::factory()->create(['sort_order' => 1]);
    $gate = CampaignGate::factory()->create(['campaign_stage_id' => $stage->id, 'sort_order' => 1]);
    $user = User::factory()->create();

    expect($this->service->isGateUnlocked($user, $gate))->toBeTrue();
});

test('a later gate stays locked until the previous gate is complete', function () {
    $stage = CampaignStage::factory()->create(['sort_order' => 1]);
    $first = CampaignGate::factory()->create(['campaign_stage_id' => $stage->id, 'sort_order' => 1]);
    $second = CampaignGate::factory()->create(['campaign_stage_id' => $stage->id, 'sort_order' => 2]);
    CampaignStep::factory()->create(['campaign_gate_id' => $first->id, 'sort_order' => 1]); // غير مكتملة
    $user = User::factory()->create();

    expect($this->service->isGateUnlocked($user, $second))->toBeFalse();
});

test('the first step is unlocked when its gate is unlocked', function () {
    $gate = CampaignGate::factory()->create();
    $step = CampaignStep::factory()->create(['campaign_gate_id' => $gate->id, 'sort_order' => 1]);
    $user = User::factory()->create();

    expect($this->service->isStepUnlocked($user, $step))->toBeTrue();
});

test('a later step stays locked until the previous step is complete', function () {
    $gate = CampaignGate::factory()->create();
    $first = CampaignStep::factory()->create(['campaign_gate_id' => $gate->id, 'sort_order' => 1]);
    $second = CampaignStep::factory()->create(['campaign_gate_id' => $gate->id, 'sort_order' => 2]);
    $user = User::factory()->create();

    expect($this->service->isStepUnlocked($user, $second))->toBeFalse();

    UserCampaignProgress::factory()->create([
        'user_id' => $user->id,
        'campaign_step_id' => $first->id,
        'completed_at' => now(),
    ]);

    expect($this->service->isStepUnlocked($user, $second->fresh()))->toBeTrue();
});

// ===== E. Narrative completion =====

test('narrative completion is derived from completed_at', function () {
    $step = CampaignStep::factory()->create();
    $user = User::factory()->create();

    expect($this->service->isStepCompleted($user, $step))->toBeFalse();

    UserCampaignProgress::factory()->create([
        'user_id' => $user->id,
        'campaign_step_id' => $step->id,
        'completed_at' => now(),
    ]);

    expect($this->service->isStepCompleted($user, $step))->toBeTrue();
});

test('a narrative step started but not finished is in_progress, not completed', function () {
    $step = CampaignStep::factory()->create();
    $user = User::factory()->create();

    UserCampaignProgress::factory()->create([
        'user_id' => $user->id,
        'campaign_step_id' => $step->id,
        'started_at' => now(),
        'completed_at' => null,
    ]);

    expect($this->service->isStepCompleted($user, $step))->toBeFalse()
        ->and($this->service->isStepInProgress($user, $step))->toBeTrue();
});

// ===== F. Puzzle completion + context isolation =====

test('a correct puzzle attempt with the matching campaign_step context marks the step completed', function () {
    $puzzle = Puzzle::factory()->create();
    $step = CampaignStep::factory()->puzzle()->create(['puzzle_id' => $puzzle->id]);
    $user = User::factory()->create();

    PuzzleAttempt::factory()->create([
        'user_id' => $user->id,
        'puzzle_id' => $puzzle->id,
        'is_correct' => true,
        'context_type' => 'campaign_step',
        'context_id' => $step->id,
    ]);

    expect($this->service->isStepCompleted($user, $step))->toBeTrue();
});

test('solving the same puzzle standalone does not complete the campaign step', function () {
    $puzzle = Puzzle::factory()->create();
    $step = CampaignStep::factory()->puzzle()->create(['puzzle_id' => $puzzle->id]);
    $user = User::factory()->create();

    PuzzleAttempt::factory()->create([
        'user_id' => $user->id,
        'puzzle_id' => $puzzle->id,
        'is_correct' => true,
        'context_type' => null,
        'context_id' => null,
    ]);

    expect($this->service->isStepCompleted($user, $step))->toBeFalse();
});

test('solving the same puzzle in campaign A does not complete the equivalent step in campaign B', function () {
    $puzzle = Puzzle::factory()->create();
    $stepInA = CampaignStep::factory()->puzzle()->create(['puzzle_id' => $puzzle->id]);
    $stepInB = CampaignStep::factory()->puzzle()->create(['puzzle_id' => $puzzle->id]);
    $user = User::factory()->create();

    PuzzleAttempt::factory()->create([
        'user_id' => $user->id,
        'puzzle_id' => $puzzle->id,
        'is_correct' => true,
        'context_type' => 'campaign_step',
        'context_id' => $stepInA->id,
    ]);

    expect($this->service->isStepCompleted($user, $stepInA))->toBeTrue()
        ->and($this->service->isStepCompleted($user, $stepInB))->toBeFalse();
});

test('a wrong contextual attempt leaves the puzzle step in_progress, not completed', function () {
    $puzzle = Puzzle::factory()->create();
    $step = CampaignStep::factory()->puzzle()->create(['puzzle_id' => $puzzle->id]);
    $user = User::factory()->create();

    PuzzleAttempt::factory()->create([
        'user_id' => $user->id,
        'puzzle_id' => $puzzle->id,
        'is_correct' => false,
        'context_type' => 'campaign_step',
        'context_id' => $step->id,
    ]);

    expect($this->service->isStepCompleted($user, $step))->toBeFalse()
        ->and($this->service->isStepInProgress($user, $step))->toBeTrue();
});

test('an active contextual GameSession marks a stateful puzzle step in_progress even with no attempt yet', function () {
    $puzzle = Puzzle::factory()->create();
    $step = CampaignStep::factory()->puzzle()->create(['puzzle_id' => $puzzle->id]);
    $user = User::factory()->create();

    GameSession::factory()->create([
        'user_id' => $user->id,
        'puzzle_id' => $puzzle->id,
        'context_type' => 'campaign_step',
        'context_id' => $step->id,
        'status' => GameSession::STATUS_ACTIVE,
        'started_at' => now(),
        'expires_at' => null,
    ]);

    expect($this->service->isStepCompleted($user, $step))->toBeFalse()
        ->and($this->service->isStepInProgress($user, $step))->toBeTrue();
});

// ===== G. Empty containers =====

test('an empty gate is never completed', function () {
    $gate = CampaignGate::factory()->create();
    $user = User::factory()->create();

    expect($this->service->isGateCompleted($user, $gate))->toBeFalse();
});

test('an empty stage is never completed', function () {
    $stage = CampaignStage::factory()->create();
    $user = User::factory()->create();

    expect($this->service->isStageCompleted($user, $stage))->toBeFalse();
});

test('an empty campaign is never completed', function () {
    $campaign = Campaign::factory()->create();
    $user = User::factory()->create();

    expect($this->service->isCampaignCompleted($user, $campaign))->toBeFalse();
});

// ===== H. Completion propagation =====

test('a gate is completed once every one of its steps is completed', function () {
    $gate = CampaignGate::factory()->create();
    $stepA = CampaignStep::factory()->create(['campaign_gate_id' => $gate->id, 'sort_order' => 1]);
    $stepB = CampaignStep::factory()->create(['campaign_gate_id' => $gate->id, 'sort_order' => 2]);
    $user = User::factory()->create();

    UserCampaignProgress::factory()->create(['user_id' => $user->id, 'campaign_step_id' => $stepA->id, 'completed_at' => now()]);
    expect($this->service->isGateCompleted($user, $gate))->toBeFalse();

    UserCampaignProgress::factory()->create(['user_id' => $user->id, 'campaign_step_id' => $stepB->id, 'completed_at' => now()]);
    expect($this->service->isGateCompleted($user, $gate->fresh()))->toBeTrue();
});

test('a stage is completed once every one of its gates is completed', function () {
    $stage = CampaignStage::factory()->create();
    $gateA = CampaignGate::factory()->create(['campaign_stage_id' => $stage->id, 'sort_order' => 1]);
    $gateB = CampaignGate::factory()->create(['campaign_stage_id' => $stage->id, 'sort_order' => 2]);
    $stepA = CampaignStep::factory()->create(['campaign_gate_id' => $gateA->id]);
    $stepB = CampaignStep::factory()->create(['campaign_gate_id' => $gateB->id]);
    $user = User::factory()->create();

    UserCampaignProgress::factory()->create(['user_id' => $user->id, 'campaign_step_id' => $stepA->id, 'completed_at' => now()]);
    expect($this->service->isStageCompleted($user, $stage))->toBeFalse();

    UserCampaignProgress::factory()->create(['user_id' => $user->id, 'campaign_step_id' => $stepB->id, 'completed_at' => now()]);
    expect($this->service->isStageCompleted($user, $stage->fresh()))->toBeTrue();
});

test('a campaign is completed once every one of its stages is completed', function () {
    $campaign = Campaign::factory()->create();
    $stageA = CampaignStage::factory()->create(['campaign_id' => $campaign->id, 'sort_order' => 1]);
    $stageB = CampaignStage::factory()->create(['campaign_id' => $campaign->id, 'sort_order' => 2]);
    $stepA = CampaignStep::factory()->create(['campaign_gate_id' => CampaignGate::factory()->create(['campaign_stage_id' => $stageA->id])->id]);
    $stepB = CampaignStep::factory()->create(['campaign_gate_id' => CampaignGate::factory()->create(['campaign_stage_id' => $stageB->id])->id]);
    $user = User::factory()->create();

    UserCampaignProgress::factory()->create(['user_id' => $user->id, 'campaign_step_id' => $stepA->id, 'completed_at' => now()]);
    expect($this->service->isCampaignCompleted($user, $campaign))->toBeFalse();

    UserCampaignProgress::factory()->create(['user_id' => $user->id, 'campaign_step_id' => $stepB->id, 'completed_at' => now()]);
    expect($this->service->isCampaignCompleted($user, $campaign->fresh()))->toBeTrue();
});

// ===== I. Safety / determinism =====

test('a later puzzle step stays locked despite a stray UserCampaignProgress row, when its prerequisite is incomplete', function () {
    $gate = CampaignGate::factory()->create();
    CampaignStep::factory()->create(['campaign_gate_id' => $gate->id, 'sort_order' => 1]); // غير مكتملة
    $puzzle = Puzzle::factory()->create();
    $secondStep = CampaignStep::factory()->create([
        'campaign_gate_id' => $gate->id,
        'kind' => CampaignStep::KIND_PUZZLE,
        'puzzle_id' => $puzzle->id,
        'sort_order' => 2,
    ]);
    $user = User::factory()->create();

    // صف Progress ضال - لا ينبغي وجوده أصلاً لخطوة puzzle (مصدر حقيقتها
    // الوحيد PuzzleAttempt) - يجب ألا يؤثر إطلاقاً على isStepCompleted.
    UserCampaignProgress::factory()->create([
        'user_id' => $user->id,
        'campaign_step_id' => $secondStep->id,
        'completed_at' => now(),
    ]);

    expect($this->service->isStepCompleted($user, $secondStep))->toBeFalse()
        ->and($this->service->isStepUnlocked($user, $secondStep))->toBeFalse()
        ->and($this->service->stepState($user, $secondStep))->toBe(CampaignProgressService::STATE_LOCKED);
});

test('duplicate sort_order values fall back to id for deterministic previous-step behaviour', function () {
    $gate = CampaignGate::factory()->create();
    $stepA = CampaignStep::factory()->create(['campaign_gate_id' => $gate->id, 'sort_order' => 1]);
    $stepB = CampaignStep::factory()->create(['campaign_gate_id' => $gate->id, 'sort_order' => 1]);
    $user = User::factory()->create();

    expect($this->service->isStepUnlocked($user, $stepA))->toBeTrue()
        ->and($this->service->isStepUnlocked($user, $stepB))->toBeFalse();

    UserCampaignProgress::factory()->create([
        'user_id' => $user->id,
        'campaign_step_id' => $stepA->id,
        'completed_at' => now(),
    ]);

    expect($this->service->isStepUnlocked($user, $stepB->fresh()))->toBeTrue();
});