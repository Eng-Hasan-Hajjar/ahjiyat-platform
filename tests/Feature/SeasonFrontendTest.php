<?php

use App\Models\Campaign;
use App\Models\CampaignGate;
use App\Models\CampaignStage;
use App\Models\CampaignStep;
use App\Models\Puzzle;
use App\Models\Season;
use App\Models\User;

test('a guest can view a published, available season landing page (read-only)', function () {
    $campaign = Campaign::factory()->create(['is_active' => true]);
    $season = Season::factory()->create(['campaign_id' => $campaign->id, 'is_published' => true]);

    $this->get(route('seasons.show', $season))->assertOk()->assertSee('سجّل الدخول');
});

test('a guest sees a sign-in prompt instead of a progress bar or current-mission card', function () {
    $campaign = Campaign::factory()->create(['is_active' => true]);
    $season = Season::factory()->create(['campaign_id' => $campaign->id, 'is_published' => true]);

    $this->get(route('seasons.show', $season))->assertOk()->assertDontSee('المهمة الحالية');
});

test('a verified player sees their current mission card on the season page', function () {
    $campaign = Campaign::factory()->create(['is_active' => true]);
    $season = Season::factory()->create(['campaign_id' => $campaign->id, 'is_published' => true]);
    $stage = CampaignStage::factory()->create(['campaign_id' => $campaign->id, 'sort_order' => 1]);
    $gate = CampaignGate::factory()->create(['campaign_stage_id' => $stage->id, 'sort_order' => 1]);
    CampaignStep::factory()->create(['campaign_gate_id' => $gate->id, 'sort_order' => 1, 'title' => 'المهمة الأولى']);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('seasons.show', $season))
        ->assertOk()
        ->assertSee('المهمة الحالية')
        ->assertSee('المهمة الأولى');
});

test('the preview route is server-side authorized - a non-admin cannot bypass publication by guessing the slug', function () {
    $campaign = Campaign::factory()->create(['is_active' => true]);
    $season = Season::factory()->create(['campaign_id' => $campaign->id, 'is_published' => false, 'slug' => 'secret-preview']);
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('seasons.show', $season))->assertNotFound();
});

test('an inactive campaign behind a published season shows "unavailable" state, not a playable page', function () {
    $campaign = Campaign::factory()->create(['is_active' => false]);
    $season = Season::factory()->create(['campaign_id' => $campaign->id, 'is_published' => true]);
    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($admin)
        ->get(route('seasons.show', $season))
        ->assertOk()
        ->assertSee('غير متاحة حالياً');
});

test('a season index page renders without any season present (empty state)', function () {
    $this->get(route('seasons.index'))->assertOk()->assertSee('لا توجد مواسم رسمية متاحة حالياً');
});

test('the season page never renders raw puzzle solution_data', function () {
    $campaign = Campaign::factory()->create(['is_active' => true]);
    $season = Season::factory()->create(['campaign_id' => $campaign->id, 'is_published' => true]);
    $puzzle = Puzzle::factory()->create([
        'game_type' => 'spot_difference',
        'game_config' => ['image_before' => 'a.png', 'image_after' => 'b.png'],
        'solution_data' => ['hotspots' => [['x' => 0.42, 'y' => 0.42, 'radius' => 0.05]]],
    ]);
    $stage = CampaignStage::factory()->create(['campaign_id' => $campaign->id, 'sort_order' => 1]);
    $gate = CampaignGate::factory()->create(['campaign_stage_id' => $stage->id, 'sort_order' => 1]);
    CampaignStep::factory()->puzzle()->create(['campaign_gate_id' => $gate->id, 'sort_order' => 1, 'puzzle_id' => $puzzle->id]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('seasons.show', $season))
        ->assertOk()
               ->assertDontSee('0.42', false)
        ->assertDontSee('radius":', false);
});

test('a non-admin never sees content-status metadata on the season page', function () {
    $campaign = Campaign::factory()->create(['is_active' => true]);
    $season = Season::factory()->create(['campaign_id' => $campaign->id, 'is_published' => true]);
    $stage = CampaignStage::factory()->create(['campaign_id' => $campaign->id, 'sort_order' => 1]);
    $gate = CampaignGate::factory()->create(['campaign_stage_id' => $stage->id, 'sort_order' => 1]);
    CampaignStep::factory()->create([
        'campaign_gate_id' => $gate->id, 'sort_order' => 1,
        'content' => ['body' => 'x', 'status' => CampaignStep::CONTENT_STATUS_TECHNICAL_PENDING],
    ]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('seasons.show', $season))
        ->assertOk()
        ->assertDontSee('بانتظار قرار تقني');
});

test('an admin does see content-status metadata on the season page', function () {
    $campaign = Campaign::factory()->create(['is_active' => true]);
    $season = Season::factory()->create(['campaign_id' => $campaign->id, 'is_published' => true]);
    $stage = CampaignStage::factory()->create(['campaign_id' => $campaign->id, 'sort_order' => 1]);
    $gate = CampaignGate::factory()->create(['campaign_stage_id' => $stage->id, 'sort_order' => 1]);
    CampaignStep::factory()->create([
        'campaign_gate_id' => $gate->id, 'sort_order' => 1,
        'content' => ['body' => 'x', 'status' => CampaignStep::CONTENT_STATUS_TECHNICAL_PENDING],
    ]);
    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($admin)
        ->get(route('seasons.show', $season))
        ->assertOk()
        ->assertSee('بانتظار قرار تقني');
});