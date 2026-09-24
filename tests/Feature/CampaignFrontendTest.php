<?php

use App\Models\Campaign;
use App\Models\CampaignGate;
use App\Models\CampaignStage;
use App\Models\CampaignStep;
use App\Models\Puzzle;
use App\Models\User;

function publishedCampaignWithStep(array $stepOverrides = []): array
{
    $campaign = Campaign::factory()->create(['is_active' => true]);
    $stage = CampaignStage::factory()->create(['campaign_id' => $campaign->id, 'sort_order' => 1]);
    $gate = CampaignGate::factory()->create(['campaign_stage_id' => $stage->id, 'sort_order' => 1]);
    $step = CampaignStep::factory()->create(array_merge(['campaign_gate_id' => $gate->id, 'sort_order' => 1], $stepOverrides));

    return [$campaign, $step];
}

test('the campaign index shows an active, in-window campaign', function () {
    $campaign = Campaign::factory()->create(['is_active' => true, 'title' => 'حملة الاختبار']);

    $this->get(route('campaigns.index'))->assertOk()->assertSee('حملة الاختبار');
});

test('an inactive campaign is not publicly playable (404 on detail)', function () {
    $campaign = Campaign::factory()->create(['is_active' => false]);

    $this->get(route('campaigns.show', $campaign))->assertNotFound();
});

test('an inactive campaign does not appear on the index', function () {
    Campaign::factory()->create(['is_active' => false, 'title' => 'حملة غير مفعّلة']);

    $this->get(route('campaigns.index'))->assertOk()->assertDontSee('حملة غير مفعّلة');
});

test('the campaign detail page renders its stage/gate/step hierarchy', function () {
    [$campaign, $step] = publishedCampaignWithStep(['title' => 'الخطوة الأولى']);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('campaigns.show', $campaign))
        ->assertOk()
        ->assertSee($step->gate->stage->title)
        ->assertSee($step->gate->title)
        ->assertSee('الخطوة الأولى');
});

test('a locked step cannot be accessed directly', function () {
    $campaign = Campaign::factory()->create(['is_active' => true]);
    $stage = CampaignStage::factory()->create(['campaign_id' => $campaign->id, 'sort_order' => 1]);
    $gate = CampaignGate::factory()->create(['campaign_stage_id' => $stage->id, 'sort_order' => 1]);
    CampaignStep::factory()->create(['campaign_gate_id' => $gate->id, 'sort_order' => 1]); // غير مكتملة
    $locked = CampaignStep::factory()->create(['campaign_gate_id' => $gate->id, 'sort_order' => 2]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('campaigns.steps.show', [$campaign, $locked]))
        ->assertOk()
        ->assertViewIs('campaigns.steps.locked');
});

test('an available narrative step renders its content', function () {
    [$campaign, $step] = publishedCampaignWithStep(['content' => ['body' => 'نص السرد التجريبي']]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('campaigns.steps.show', [$campaign, $step]))
        ->assertOk()
        ->assertSee('نص السرد التجريبي');
});

test('a puzzle step renders the existing shared renderer view', function () {
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح']);
    [$campaign, $step] = publishedCampaignWithStep(['kind' => CampaignStep::KIND_PUZZLE, 'puzzle_id' => $puzzle->id]);
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('campaigns.steps.show', [$campaign, $step]));

    $response->assertOk()->assertViewIs('campaigns.steps.puzzle');

    // نفس الـrenderer الذي يستخدمه المسار المستقل تماماً لنفس الأحجية - صفر
    // نسخة "campaign-*" منفصلة (C8.7). لا نفترض قيمة محدَّدة، فقط التطابق.
    expect($response->viewData('renderer'))->toBe(app(App\GameEngine\GameTypeRegistry::class)->rendererFor($puzzle));
});

test('the standalone stateful renderer still points at the standalone session start URL', function () {
    $puzzle = Puzzle::factory()->create([
        'game_type' => 'spot_difference',
        'game_config' => ['image_before' => 'a.png', 'image_after' => 'b.png'],
        'solution_data' => ['hotspots' => [['x' => 0.2, 'y' => 0.2, 'radius' => 0.05]]],
    ]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('puzzles.show', $puzzle))
        ->assertOk()
        ->assertSee(route('game-sessions.start', $puzzle), false);
});

test('the campaign stateful renderer points at the campaign session start URL, not the standalone one', function () {
    $puzzle = Puzzle::factory()->create([
        'game_type' => 'spot_difference',
        'game_config' => ['image_before' => 'a.png', 'image_after' => 'b.png'],
        'solution_data' => ['hotspots' => [['x' => 0.2, 'y' => 0.2, 'radius' => 0.05]]],
    ]);
    [$campaign, $step] = publishedCampaignWithStep(['kind' => CampaignStep::KIND_PUZZLE, 'puzzle_id' => $puzzle->id]);
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('campaigns.steps.show', [$campaign, $step]));

    $response->assertOk()
        ->assertSee(route('campaigns.steps.session', [$campaign, $step]), false)
        ->assertDontSee(route('game-sessions.start', $puzzle), false);
});

test('a correct stateless submission advances the campaign progression', function () {
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح']);
    [$campaign, $step] = publishedCampaignWithStep(['kind' => CampaignStep::KIND_PUZZLE, 'puzzle_id' => $puzzle->id]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('campaigns.steps.attempt', [$campaign, $step]), ['answer' => 'صح'])
        ->assertSessionHas('success');

    expect(app(App\Services\CampaignProgressService::class)->isStepCompleted($user, $step->fresh()))->toBeTrue();
});

test('a narrative completion advances the campaign progression and redirects to the next step', function () {
    $campaign = Campaign::factory()->create(['is_active' => true]);
    $stage = CampaignStage::factory()->create(['campaign_id' => $campaign->id, 'sort_order' => 1]);
    $gate = CampaignGate::factory()->create(['campaign_stage_id' => $stage->id, 'sort_order' => 1]);
    $first = CampaignStep::factory()->create(['campaign_gate_id' => $gate->id, 'sort_order' => 1]);
    $second = CampaignStep::factory()->create(['campaign_gate_id' => $gate->id, 'sort_order' => 2]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('campaigns.steps.complete', [$campaign, $first]))
        ->assertRedirect(route('campaigns.steps.show', [$campaign, $second]));
});

test('a completed-but-not-qualified gate leaves the next gate locked in the rendered detail page', function () {
    $campaign = Campaign::factory()->create(['is_active' => true]);
    $stage = CampaignStage::factory()->create(['campaign_id' => $campaign->id, 'sort_order' => 1]);
    $gate = CampaignGate::factory()->create([
        'campaign_stage_id' => $stage->id, 'sort_order' => 1,
        'qualification_rule' => 'first_n', 'qualification_config' => ['limit' => 1],
    ]);
    $step = CampaignStep::factory()->create(['campaign_gate_id' => $gate->id, 'sort_order' => 1]);
    CampaignGate::factory()->create(['campaign_stage_id' => $stage->id, 'sort_order' => 2]);

    $userA = User::factory()->create();
    $userB = User::factory()->create();

    $this->actingAs($userA)->post(route('campaigns.steps.complete', [$campaign, $step]));
    $this->actingAs($userB)->post(route('campaigns.steps.complete', [$campaign, $step]));

    $this->actingAs($userB)
        ->get(route('campaigns.show', $campaign))
        ->assertOk()
        ->assertSee('غير مؤهَّل');
});

test('an override reward step displays the override amount, not the puzzle default', function () {
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح', 'gem_reward' => 10]);
    [$campaign, $step] = publishedCampaignWithStep([
        'kind' => CampaignStep::KIND_PUZZLE, 'puzzle_id' => $puzzle->id,
        'reward_mode' => 'override', 'reward_override_amount' => 77,
    ]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('campaigns.steps.show', [$campaign, $step]))
        ->assertOk()
        ->assertSee('77')
        ->assertDontSee('+10 💎', false);
});

test('solution_data is never rendered on the campaign puzzle step page', function () {
    $puzzle = Puzzle::factory()->create([
        'game_type' => 'spot_difference',
        'game_config' => ['image_before' => 'a.png', 'image_after' => 'b.png'],
        'solution_data' => ['hotspots' => [['x' => 0.42, 'y' => 0.42, 'radius' => 0.05]]],
    ]);
    [$campaign, $step] = publishedCampaignWithStep(['kind' => CampaignStep::KIND_PUZZLE, 'puzzle_id' => $puzzle->id]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('campaigns.steps.show', [$campaign, $step]))
        ->assertOk()
              ->assertDontSee('0.42', false)
        ->assertDontSee('radius":', false);
});

test('a guest cannot perform any campaign write action', function () {
    [$campaign, $step] = publishedCampaignWithStep();

    $this->post(route('campaigns.steps.complete', [$campaign, $step]))->assertRedirect(route('login'));
});

test('an unverified user cannot perform any campaign write action', function () {
    [$campaign, $step] = publishedCampaignWithStep();
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->post(route('campaigns.steps.complete', [$campaign, $step]))
        ->assertRedirect(route('verification.notice'));
});

test('a verified user can perform campaign write actions', function () {
    [$campaign, $step] = publishedCampaignWithStep();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('campaigns.steps.complete', [$campaign, $step]))
        ->assertSessionHas('success');
});

test('a cross-campaign URL mismatch is rejected on the step show page too', function () {
    $campaignA = Campaign::factory()->create(['is_active' => true]);
    [, $stepInB] = publishedCampaignWithStep();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('campaigns.steps.show', [$campaignA, $stepInB]))
        ->assertNotFound();
});