<?php

use App\Models\Campaign;
use App\Models\CampaignGate;
use App\Models\CampaignGateQualification;
use App\Models\CampaignStage;
use App\Models\CampaignStep;
use App\Models\Puzzle;
use App\Models\User;
use App\Models\UserCampaignProgress;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

// ===== Factory creation =====

test('a campaign can be created via its factory with the expected defaults', function () {
    $campaign = Campaign::factory()->create();

    expect($campaign->exists)->toBeTrue()
        ->and($campaign->slug)->not->toBeEmpty()
        ->and($campaign->is_active)->toBeTrue();
});

// ===== Relationships =====

test('a campaign has many stages and a stage belongs to its campaign', function () {
    $campaign = Campaign::factory()->create();
    $stage = CampaignStage::factory()->create(['campaign_id' => $campaign->id]);

    expect($campaign->stages)->toHaveCount(1)
        ->and($stage->campaign->is($campaign))->toBeTrue();
});

test('a stage has many gates and a gate belongs to its stage', function () {
    $stage = CampaignStage::factory()->create();
    $gate = CampaignGate::factory()->create(['campaign_stage_id' => $stage->id]);

    expect($stage->gates)->toHaveCount(1)
        ->and($gate->stage->is($stage))->toBeTrue();
});

test('a gate has many steps and a step belongs to its gate', function () {
    $gate = CampaignGate::factory()->create();
    $step = CampaignStep::factory()->create(['campaign_gate_id' => $gate->id]);

    expect($gate->steps)->toHaveCount(1)
        ->and($step->gate->is($gate))->toBeTrue();
});

test('a puzzle-kind step belongs to a puzzle, and the puzzle can see its campaign steps', function () {
    $puzzle = Puzzle::factory()->create();
    $step = CampaignStep::factory()->puzzle()->create(['puzzle_id' => $puzzle->id]);

    expect($step->puzzle->is($puzzle))->toBeTrue()
        ->and($puzzle->campaignSteps)->toHaveCount(1);
});

test('a narrative step has a null puzzle_id by default', function () {
    $step = CampaignStep::factory()->create();

    expect($step->kind)->toBe(CampaignStep::KIND_NARRATIVE)
        ->and($step->puzzle_id)->toBeNull();
});

test('a user has many campaign progress records', function () {
    $user = User::factory()->create();
    UserCampaignProgress::factory()->create(['user_id' => $user->id]);

    expect($user->campaignProgress)->toHaveCount(1);
});

test('a gate has many qualifications', function () {
    $gate = CampaignGate::factory()->create();
    CampaignGateQualification::factory()->create(['campaign_gate_id' => $gate->id]);

    expect($gate->qualifications)->toHaveCount(1);
});

test('a user has many created campaigns and many gate qualifications', function () {
    $user = User::factory()->create();
    Campaign::factory()->create(['created_by' => $user->id]);
    CampaignGateQualification::factory()->create(['user_id' => $user->id]);

    expect($user->createdCampaigns)->toHaveCount(1)
        ->and($user->campaignQualifications)->toHaveCount(1);
});

// ===== Unique constraints =====

test('a user cannot have two progress records for the same step', function () {
    $user = User::factory()->create();
    $step = CampaignStep::factory()->create();

    UserCampaignProgress::factory()->create(['user_id' => $user->id, 'campaign_step_id' => $step->id]);

    expect(fn () => UserCampaignProgress::factory()->create(['user_id' => $user->id, 'campaign_step_id' => $step->id]))
        ->toThrow(QueryException::class);
});

test('a user cannot qualify twice for the same gate', function () {
    $gate = CampaignGate::factory()->create();
    $user = User::factory()->create();

    CampaignGateQualification::factory()->create(['campaign_gate_id' => $gate->id, 'user_id' => $user->id]);

    expect(fn () => CampaignGateQualification::factory()->create(['campaign_gate_id' => $gate->id, 'user_id' => $user->id]))
        ->toThrow(QueryException::class);
});

test('two different users cannot hold the same rank in the same gate', function () {
    $gate = CampaignGate::factory()->create();

    CampaignGateQualification::factory()->create(['campaign_gate_id' => $gate->id, 'rank' => 1]);

    expect(fn () => CampaignGateQualification::factory()->create(['campaign_gate_id' => $gate->id, 'rank' => 1]))
        ->toThrow(QueryException::class);
});

test('multiple unconditional (null rank) qualifications on the same gate are allowed', function () {
    $gate = CampaignGate::factory()->create();

    CampaignGateQualification::factory()->create(['campaign_gate_id' => $gate->id, 'rank' => null]);
    $second = CampaignGateQualification::factory()->create(['campaign_gate_id' => $gate->id, 'rank' => null]);

    expect($second->exists)->toBeTrue()
        ->and(CampaignGateQualification::where('campaign_gate_id', $gate->id)->count())->toBe(2);
});

// ===== Cascade / deletion behaviour =====

test('deleting a campaign cascades through stage, gate, and step', function () {
    $campaign = Campaign::factory()->create();
    $stage = CampaignStage::factory()->create(['campaign_id' => $campaign->id]);
    $gate = CampaignGate::factory()->create(['campaign_stage_id' => $stage->id]);
    $step = CampaignStep::factory()->create(['campaign_gate_id' => $gate->id]);

    $campaign->delete();

    expect(CampaignStage::find($stage->id))->toBeNull()
        ->and(CampaignGate::find($gate->id))->toBeNull()
        ->and(CampaignStep::find($step->id))->toBeNull();
});

test('deleting the creator user nulls campaigns.created_by instead of deleting the campaign', function () {
    $user = User::factory()->create();
    $campaign = Campaign::factory()->create(['created_by' => $user->id]);

    $user->delete();

    expect($campaign->fresh())->not->toBeNull()
        ->and($campaign->fresh()->created_by)->toBeNull();
});

test('a puzzle used by a campaign step cannot be deleted', function () {
    $puzzle = Puzzle::factory()->create();
    CampaignStep::factory()->puzzle()->create(['puzzle_id' => $puzzle->id]);

    expect(fn () => $puzzle->delete())->toThrow(QueryException::class);

    expect(Puzzle::find($puzzle->id))->not->toBeNull();
});

// ===== Casts =====

test('content and qualification_config are cast to array', function () {
    $step = CampaignStep::factory()->create(['content' => ['body' => 'نص تجريبي']]);
    $gate = CampaignGate::factory()->create(['qualification_rule' => 'first_n', 'qualification_config' => ['limit' => 100]]);

    expect($step->fresh()->content)->toBe(['body' => 'نص تجريبي'])
        ->and($gate->fresh()->qualification_config)->toBe(['limit' => 100]);
});

test('a gate without a qualification rule has null config, not an empty array', function () {
    $gate = CampaignGate::factory()->create();

    expect($gate->qualification_rule)->toBeNull()
        ->and($gate->qualification_config)->toBeNull();
});

test('datetime casts work on campaign, progress, and qualification', function () {
    $campaign = Campaign::factory()->create(['starts_at' => now(), 'ends_at' => now()->addWeek()]);
    $progress = UserCampaignProgress::factory()->create(['completed_at' => now()]);
    $qualification = CampaignGateQualification::factory()->create();

    expect($campaign->starts_at)->toBeInstanceOf(Carbon::class)
        ->and($progress->completed_at)->toBeInstanceOf(Carbon::class)
        ->and($qualification->qualified_at)->toBeInstanceOf(Carbon::class);
});