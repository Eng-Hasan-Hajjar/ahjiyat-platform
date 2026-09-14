<?php

use App\Models\CampaignGate;
use App\Models\CampaignGateQualification;
use App\Models\CampaignStage;
use App\Models\CampaignStep;
use App\Models\Puzzle;
use App\Models\User;
use App\Services\CampaignProgressService;
use App\Services\PuzzleAttemptService;
use App\Services\QualificationService;

beforeEach(function () {
    $this->progress = app(CampaignProgressService::class);
    $this->qualification = app(QualificationService::class);
});

function firstNGate(int $limit, int $stepCount = 1): CampaignGate
{
    $stage = CampaignStage::factory()->create(['sort_order' => 1]);
    $gate = CampaignGate::factory()->create([
        'campaign_stage_id' => $stage->id,
        'sort_order' => 1,
        'qualification_rule' => 'first_n',
        'qualification_config' => ['limit' => $limit],
    ]);

    for ($i = 1; $i <= $stepCount; $i++) {
        CampaignStep::factory()->create(['campaign_gate_id' => $gate->id, 'sort_order' => $i]);
    }

    return $gate;
}

function completeAllStepsIn(CampaignGate $gate, User $user): void
{
    foreach ($gate->steps as $step) {
        app(App\Services\CampaignNarrativeService::class)->complete($user, $step);
    }
}

// ===== No rule =====

test('a gate without a qualification rule does not require a qualification row to unlock the next gate', function () {
    $stage = CampaignStage::factory()->create(['sort_order' => 1]);
    $gateA = CampaignGate::factory()->create(['campaign_stage_id' => $stage->id, 'sort_order' => 1]);
    $gateB = CampaignGate::factory()->create(['campaign_stage_id' => $stage->id, 'sort_order' => 2]);
    $step = CampaignStep::factory()->create(['campaign_gate_id' => $gateA->id]);
    $user = User::factory()->create();

    app(App\Services\CampaignNarrativeService::class)->complete($user, $step);

    expect($this->progress->isGateUnlocked($user, $gateB->fresh()))->toBeTrue()
        ->and(CampaignGateQualification::count())->toBe(0);
});

// ===== First-N basics =====

test('a user within the limit qualifies once the gate completes', function () {
    $gate = firstNGate(limit: 2);
    $user = User::factory()->create();

    completeAllStepsIn($gate, $user);

    expect($this->qualification->isUserQualified($user, $gate->fresh()))->toBeTrue();
});

test('rank starts from 1 and increments correctly for subsequent qualifiers', function () {
    $gate = firstNGate(limit: 3);
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    completeAllStepsIn($gate, $userA);
    completeAllStepsIn($gate, $userB);

    $rankA = CampaignGateQualification::where('campaign_gate_id', $gate->id)->where('user_id', $userA->id)->value('rank');
    $rankB = CampaignGateQualification::where('campaign_gate_id', $gate->id)->where('user_id', $userB->id)->value('rank');

    expect($rankA)->toBe(1)->and($rankB)->toBe(2);
});

test('a user outside the limit does not qualify', function () {
    $gate = firstNGate(limit: 1);
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    completeAllStepsIn($gate, $userA);
    completeAllStepsIn($gate, $userB);

    expect($this->qualification->isUserQualified($userA, $gate->fresh()))->toBeTrue()
        ->and($this->qualification->isUserQualified($userB, $gate->fresh()))->toBeFalse();
});

// ===== Idempotency =====

test('the same user cannot qualify twice, and duplicate triggers are idempotent', function () {
    $gate = firstNGate(limit: 5);
    $user = User::factory()->create();

    completeAllStepsIn($gate, $user);
    $first = CampaignGateQualification::where('campaign_gate_id', $gate->id)->where('user_id', $user->id)->first();

    $this->qualification->afterStepCompletion($user, $gate->steps->first());

    expect(CampaignGateQualification::where('campaign_gate_id', $gate->id)->where('user_id', $user->id)->count())->toBe(1);

    $second = CampaignGateQualification::where('campaign_gate_id', $gate->id)->where('user_id', $user->id)->first();
    expect($second->id)->toBe($first->id)
        ->and($second->rank)->toBe($first->rank)
        ->and($second->qualified_at->eq($first->qualified_at))->toBeTrue();
});

// ===== Unlock integration =====

test('the next gate unlocks for a qualified user', function () {
    $gate = firstNGate(limit: 1);
    $nextGate = CampaignGate::factory()->create(['campaign_stage_id' => $gate->campaign_stage_id, 'sort_order' => 2]);
    $user = User::factory()->create();

    completeAllStepsIn($gate, $user);

    expect($this->progress->isGateUnlocked($user, $nextGate->fresh()))->toBeTrue();
});

test('the next gate stays locked for a completed-but-not-qualified user', function () {
    $gate = firstNGate(limit: 1);
    $nextGate = CampaignGate::factory()->create(['campaign_stage_id' => $gate->campaign_stage_id, 'sort_order' => 2]);
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    completeAllStepsIn($gate, $userA);
    completeAllStepsIn($gate, $userB);

    expect($this->progress->isGateCompleted($userB, $gate->fresh()))->toBeTrue()
        ->and($this->progress->isGateUnlocked($userB, $nextGate->fresh()))->toBeFalse()
        ->and($this->progress->gateState($userB, $gate->fresh()))->toBe(CampaignProgressService::STATE_NOT_QUALIFIED);
});

test('a previous gate without a rule unlocks the next gate normally', function () {
    $stage = CampaignStage::factory()->create(['sort_order' => 1]);
    $gateA = CampaignGate::factory()->create(['campaign_stage_id' => $stage->id, 'sort_order' => 1]);
    $gateB = CampaignGate::factory()->create(['campaign_stage_id' => $stage->id, 'sort_order' => 2, 'qualification_rule' => 'first_n', 'qualification_config' => ['limit' => 1]]);
    $step = CampaignStep::factory()->create(['campaign_gate_id' => $gateA->id]);
    $user = User::factory()->create();

    app(App\Services\CampaignNarrativeService::class)->complete($user, $step);

    expect($this->progress->isGateUnlocked($user, $gateB->fresh()))->toBeTrue();
});

// ===== Independence =====

test('different gates maintain independent rank sequences', function () {
    $gateA = firstNGate(limit: 5);
    $gateB = firstNGate(limit: 5);
    $user = User::factory()->create();

    completeAllStepsIn($gateA, $user);
    completeAllStepsIn($gateB, $user);

    $rankA = CampaignGateQualification::where('campaign_gate_id', $gateA->id)->where('user_id', $user->id)->value('rank');
    $rankB = CampaignGateQualification::where('campaign_gate_id', $gateB->id)->where('user_id', $user->id)->value('rank');

    expect($rankA)->toBe(1)->and($rankB)->toBe(1);
});

test('first_n does not affect standalone puzzle solving in any way', function () {
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح']);
    $user = User::factory()->create();

    $result = app(PuzzleAttemptService::class)->attempt($user, $puzzle, 'صح');

    expect($result['correct'])->toBeTrue()
        ->and(CampaignGateQualification::count())->toBe(0);
});

// ===== Trigger points: narrative / stateless puzzle / stateful GameSession =====

test('a narrative last step can trigger qualification', function () {
    $gate = firstNGate(limit: 1);
    $user = User::factory()->create();

    app(App\Services\CampaignNarrativeService::class)->complete($user, $gate->steps->first());

    expect($this->qualification->isUserQualified($user, $gate->fresh()))->toBeTrue();
});

test('a stateless puzzle last step can trigger qualification', function () {
    $stage = CampaignStage::factory()->create(['sort_order' => 1]);
    $gate = CampaignGate::factory()->create([
        'campaign_stage_id' => $stage->id,
        'sort_order' => 1,
        'qualification_rule' => 'first_n',
        'qualification_config' => ['limit' => 1],
    ]);
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح']);
    $step = CampaignStep::factory()->puzzle()->create(['campaign_gate_id' => $gate->id, 'sort_order' => 1, 'puzzle_id' => $puzzle->id]);
    $user = User::factory()->create();

    app(App\Services\CampaignPuzzleService::class)->attempt($user, $step, 'صح', false, []);

    expect($this->qualification->isUserQualified($user, $gate->fresh()))->toBeTrue();
});

test('a stateful puzzle last step (GameSession completion) can trigger qualification', function () {
    $stage = CampaignStage::factory()->create(['sort_order' => 1]);
    $gate = CampaignGate::factory()->create([
        'campaign_stage_id' => $stage->id,
        'sort_order' => 1,
        'qualification_rule' => 'first_n',
        'qualification_config' => ['limit' => 1],
    ]);
    $puzzle = Puzzle::factory()->create([
        'game_type' => 'spot_difference',
        'game_config' => ['image_before' => 'a.png', 'image_after' => 'b.png'],
        'solution_data' => ['hotspots' => [['x' => 0.25, 'y' => 0.25, 'radius' => 0.05]]],
    ]);
    $step = CampaignStep::factory()->puzzle()->create(['campaign_gate_id' => $gate->id, 'sort_order' => 1, 'puzzle_id' => $puzzle->id]);
    $user = User::factory()->create();

    $sessionId = $this->actingAs($user)
        ->postJson(route('campaigns.steps.session', [$gate->stage->campaign, $step]))
        ->json('session_id');

    $this->actingAs($user)->postJson(route('game-sessions.reveal', $sessionId), ['x' => 0.25, 'y' => 0.25]);

    expect($this->qualification->isUserQualified($user, $gate->fresh()))->toBeTrue();
});

// ===== Config validation =====

test('a limit of zero or missing never qualifies anyone', function () {
    $gate = firstNGate(limit: 0);
    $user = User::factory()->create();

    completeAllStepsIn($gate, $user);

    expect($this->qualification->isUserQualified($user, $gate->fresh()))->toBeFalse();
});