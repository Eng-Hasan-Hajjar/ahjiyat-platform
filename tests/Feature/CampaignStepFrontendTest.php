<?php

use App\Models\Campaign;
use App\Models\CampaignGate;
use App\Models\CampaignStage;
use App\Models\CampaignStep;
use App\Models\Puzzle;
use App\Models\User;
use App\Services\CampaignProgressService;

test('missionNumberFor returns the correct sequential number across the whole campaign', function () {
    $campaign = Campaign::factory()->create();
    $stage1 = CampaignStage::factory()->create(['campaign_id' => $campaign->id, 'sort_order' => 1]);
    $gate1 = CampaignGate::factory()->create(['campaign_stage_id' => $stage1->id, 'sort_order' => 1]);
    $step1 = CampaignStep::factory()->create(['campaign_gate_id' => $gate1->id, 'sort_order' => 1]);
    $step2 = CampaignStep::factory()->create(['campaign_gate_id' => $gate1->id, 'sort_order' => 2]);

    $stage2 = CampaignStage::factory()->create(['campaign_id' => $campaign->id, 'sort_order' => 2]);
    $gate2 = CampaignGate::factory()->create(['campaign_stage_id' => $stage2->id, 'sort_order' => 1]);
    $step3 = CampaignStep::factory()->create(['campaign_gate_id' => $gate2->id, 'sort_order' => 1]);

    $campaign->load('stages.gates.steps');
    $progress = app(CampaignProgressService::class);

    expect($progress->missionNumberFor($campaign, $step1))->toBe(1)
        ->and($progress->missionNumberFor($campaign, $step2))->toBe(2)
        ->and($progress->missionNumberFor($campaign, $step3))->toBe(3);
});

test('the step page displays its mission number and Stage/Gate context via the shared shell', function () {
    $campaign = Campaign::factory()->create(['is_active' => true]);
    $stage = CampaignStage::factory()->create(['campaign_id' => $campaign->id, 'sort_order' => 1, 'title' => 'مرحلة الاختبار']);
    $gate = CampaignGate::factory()->create(['campaign_stage_id' => $stage->id, 'sort_order' => 1, 'title' => 'بوابة الاختبار']);
    $step = CampaignStep::factory()->create(['campaign_gate_id' => $gate->id, 'sort_order' => 1]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('campaigns.steps.show', [$campaign, $step]))
        ->assertOk()
        ->assertSee('مرحلة الاختبار')
        ->assertSee('بوابة الاختبار')
        ->assertSee('مهمة #1');
});

test('a locked step never leaks its narrative body, even with the new shell/mission-number changes', function () {
    $campaign = Campaign::factory()->create(['is_active' => true]);
    $stage = CampaignStage::factory()->create(['campaign_id' => $campaign->id, 'sort_order' => 1]);
    $gate = CampaignGate::factory()->create(['campaign_stage_id' => $stage->id, 'sort_order' => 1]);
    CampaignStep::factory()->create(['campaign_gate_id' => $gate->id, 'sort_order' => 1]);
    $locked = CampaignStep::factory()->create([
        'campaign_gate_id' => $gate->id, 'sort_order' => 2,
        'content' => ['body' => 'نص سري لا يجب أن يظهر أبداً قبل الأوان'],
    ]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('campaigns.steps.show', [$campaign, $locked]))
        ->assertOk()
        ->assertViewIs('campaigns.steps.locked')
        ->assertDontSee('نص سري لا يجب أن يظهر أبداً قبل الأوان');
});

test('a locked puzzle step never leaks its prompt', function () {
    $campaign = Campaign::factory()->create(['is_active' => true]);
    $stage = CampaignStage::factory()->create(['campaign_id' => $campaign->id, 'sort_order' => 1]);
    $gate = CampaignGate::factory()->create(['campaign_stage_id' => $stage->id, 'sort_order' => 1]);
    CampaignStep::factory()->create(['campaign_gate_id' => $gate->id, 'sort_order' => 1]);
    $puzzle = Puzzle::factory()->create(['prompt' => 'سؤال سري لا يجب كشفه']);
    $locked = CampaignStep::factory()->puzzle()->create(['campaign_gate_id' => $gate->id, 'sort_order' => 2, 'puzzle_id' => $puzzle->id]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('campaigns.steps.show', [$campaign, $locked]))
        ->assertOk()
        ->assertDontSee('سؤال سري لا يجب كشفه');
});

test('the reflection page with the new character counter still submits correctly', function () {
    $campaign = Campaign::factory()->create(['is_active' => true]);
    $stage = CampaignStage::factory()->create(['campaign_id' => $campaign->id, 'sort_order' => 1]);
    $gate = CampaignGate::factory()->create(['campaign_stage_id' => $stage->id, 'sort_order' => 1]);
    $step = CampaignStep::factory()->create([
        'campaign_gate_id' => $gate->id, 'sort_order' => 1,
        'kind' => CampaignStep::KIND_REFLECTION,
        'content' => ['prompt' => 'سؤال', 'min_chars' => 5, 'max_chars' => 200],
    ]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('campaigns.steps.show', [$campaign, $step]))
        ->assertOk()
        ->assertSee('/ 200', false);

    $this->actingAs($user)
        ->post(route('campaigns.steps.reflect', [$campaign, $step]), ['response' => 'إجابة كافية الطول للاختبار.'])
        ->assertSessionHas('success');
});

test('the spot_difference puzzle page shows the clearer found-count wording', function () {
    $campaign = Campaign::factory()->create(['is_active' => true]);
    $stage = CampaignStage::factory()->create(['campaign_id' => $campaign->id, 'sort_order' => 1]);
    $gate = CampaignGate::factory()->create(['campaign_stage_id' => $stage->id, 'sort_order' => 1]);
    $puzzle = Puzzle::factory()->create([
        'game_type' => 'spot_difference',
        'game_config' => ['image_before' => 'a.png', 'image_after' => 'b.png'],
        'solution_data' => ['hotspots' => [['x' => 0.2, 'y' => 0.2, 'radius' => 0.05]]],
    ]);
    $step = CampaignStep::factory()->puzzle()->create(['campaign_gate_id' => $gate->id, 'sort_order' => 1, 'puzzle_id' => $puzzle->id]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('campaigns.steps.show', [$campaign, $step]))
        ->assertOk()
        ->assertSee('وجدت')
        ->assertSee('من');
});