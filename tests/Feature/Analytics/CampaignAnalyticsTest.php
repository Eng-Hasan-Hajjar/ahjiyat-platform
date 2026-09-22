<?php

use App\Models\Campaign;
use App\Models\CampaignGate;
use App\Models\CampaignGateQualification;
use App\Models\CampaignStage;
use App\Models\CampaignStep;
use App\Models\User;
use App\Models\UserCampaignProgress;
use App\Services\Analytics\CampaignAnalyticsService;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
});

function makeE7TestCampaignChain(): array
{
    $campaign = Campaign::factory()->create();
    $stage = CampaignStage::factory()->create(['campaign_id' => $campaign->id, 'sort_order' => 1]);
    $gate = CampaignGate::factory()->create(['campaign_stage_id' => $stage->id, 'sort_order' => 1]);
    $step1 = CampaignStep::factory()->create(['campaign_gate_id' => $gate->id, 'sort_order' => 1, 'kind' => 'narrative']);
    $step2 = CampaignStep::factory()->create(['campaign_gate_id' => $gate->id, 'sort_order' => 2, 'kind' => 'narrative']);

    return [$campaign, $step1, $step2];
}

test('participant count reflects distinct users who started the campaign', function () {
    [$campaign, $step1, $step2] = makeE7TestCampaignChain();
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();

    UserCampaignProgress::create(['user_id' => $user1->id, 'campaign_step_id' => $step1->id, 'started_at' => now()]);
    UserCampaignProgress::create(['user_id' => $user2->id, 'campaign_step_id' => $step1->id, 'started_at' => now()]);

    expect(app(CampaignAnalyticsService::class)->forCampaign($campaign)['started'])->toBe(2);
});

test('completion rate uses starters as the denominator, not all registered users', function () {
    [$campaign, $step1, $step2] = makeE7TestCampaignChain();
    User::factory()->count(5)->create();

    $starter = User::factory()->create();
    $completer = User::factory()->create();

    UserCampaignProgress::create(['user_id' => $starter->id, 'campaign_step_id' => $step1->id, 'started_at' => now()]);
    UserCampaignProgress::create(['user_id' => $completer->id, 'campaign_step_id' => $step1->id, 'started_at' => now(), 'completed_at' => now()]);
    UserCampaignProgress::create(['user_id' => $completer->id, 'campaign_step_id' => $step2->id, 'started_at' => now(), 'completed_at' => now()]);

    $stats = app(CampaignAnalyticsService::class)->forCampaign($campaign);

    expect($stats['started'])->toBe(2)
        ->and($stats['completed'])->toBe(1)
        ->and($stats['completion_rate'])->toBe(50.0);
});

test('funnel counts reflect how many distinct users reached each step', function () {
    [$campaign, $step1, $step2] = makeE7TestCampaignChain();
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    UserCampaignProgress::create(['user_id' => $userA->id, 'campaign_step_id' => $step1->id, 'started_at' => now()]);
    UserCampaignProgress::create(['user_id' => $userB->id, 'campaign_step_id' => $step1->id, 'started_at' => now()]);
    UserCampaignProgress::create(['user_id' => $userA->id, 'campaign_step_id' => $step2->id, 'started_at' => now()]);

    $funnel = app(CampaignAnalyticsService::class)->funnel($campaign);

    expect($funnel[0]['reached'])->toBe(2)
        ->and($funnel[1]['reached'])->toBe(1);
});

test('a campaign with no progress at all returns zeroed stats, not an error', function () {
    $campaign = Campaign::factory()->create();

    $stats = app(CampaignAnalyticsService::class)->forCampaign($campaign);

    expect($stats['started'])->toBe(0)
        ->and($stats['completion_rate'])->toBe(0.0);
});

test('qualification counts and occupancy percent are calculated correctly', function () {
    [$campaign, $step1, $step2] = makeE7TestCampaignChain();
    $gate = $step1->gate;
    $gate->update(['qualification_rule' => 'first_n', 'qualification_config' => ['limit' => 10]]);

    CampaignGateQualification::factory()->count(4)->create(['campaign_gate_id' => $gate->id]);

    $season = \App\Models\Season::factory()->create(['campaign_id' => $campaign->id]);
    $overview = app(CampaignAnalyticsService::class)->seasonOverview($season);

    $gateStats = collect($overview['qualifications'])->firstWhere('gate', $gate->title);

    expect($gateStats['qualified_count'])->toBe(4)
        ->and($gateStats['limit'])->toBe(10)
        ->and($gateStats['occupancy_percent'])->toBe(40.0);
});