<?php

use App\Models\Campaign;
use App\Models\CampaignGate;
use App\Models\CampaignStage;
use App\Models\CampaignStep;
use App\Models\Season;
use App\Models\User;
use App\Services\CampaignProgressService;

test('a season logo uploaded via admin data renders on the season landing page', function () {
    Illuminate\Support\Facades\Storage::fake('public');
    Illuminate\Support\Facades\Storage::disk('public')->put('seasons/branding/logo-test.png', 'fake-image-content');

    $campaign = Campaign::factory()->create(['is_active' => true]);
    $season = Season::factory()->create([
        'campaign_id' => $campaign->id,
        'is_published' => true,
        'logo_image' => 'seasons/branding/logo-test.png',
    ]);

    $response = $this->get(route('seasons.show', $season));

    $response->assertOk()->assertSee(Illuminate\Support\Facades\Storage::url('seasons/branding/logo-test.png'), false);
});

test('a season with no logo uploaded renders no broken image tag for it', function () {
    $campaign = Campaign::factory()->create(['is_active' => true]);
    $season = Season::factory()->create(['campaign_id' => $campaign->id, 'is_published' => true, 'logo_image' => null]);

    $this->get(route('seasons.show', $season))->assertOk();
});

test('a hero tagline set via admin data renders on the season page', function () {
    $campaign = Campaign::factory()->create(['is_active' => true]);
    $season = Season::factory()->create([
        'campaign_id' => $campaign->id,
        'is_published' => true,
        'theme_config' => ['hero_tagline' => 'جملة تعريفية اختبارية فريدة'],
    ]);

    $this->get(route('seasons.show', $season))->assertOk()->assertSee('جملة تعريفية اختبارية فريدة');
});

test('an upcoming published season now appears on the public index with an "upcoming" label', function () {
    $campaign = Campaign::factory()->create(['is_active' => true, 'starts_at' => now()->addWeek(), 'title' => 'حملة قادمة قريبًا']);
    Season::factory()->create(['campaign_id' => $campaign->id, 'is_published' => true]);

    $this->get(route('seasons.index'))
        ->assertOk()
        ->assertSee('حملة قادمة قريبًا')
        ->assertSee('قريباً');
});

test('a finished published season appears on the public index with a "finished" label', function () {
    $campaign = Campaign::factory()->create(['is_active' => true, 'ends_at' => now()->subDay(), 'title' => 'حملة منتهية']);
    Season::factory()->create(['campaign_id' => $campaign->id, 'is_published' => true]);

    $this->get(route('seasons.index'))
        ->assertOk()
        ->assertSee('حملة منتهية')
        ->assertSee('انتهى');
});

test('a live published season shows the live label', function () {
    $campaign = Campaign::factory()->create(['is_active' => true]);
    Season::factory()->create(['campaign_id' => $campaign->id, 'is_published' => true]);

    $this->get(route('seasons.index'))->assertOk()->assertSee('مباشر الآن');
});

test('the hero shows the correct unavailable state instead of a play CTA when the campaign is not yet available', function () {
    $campaign = Campaign::factory()->create(['is_active' => true, 'starts_at' => now()->addWeek()]);
    $season = Season::factory()->create(['campaign_id' => $campaign->id, 'is_published' => true]);
    $stage = CampaignStage::factory()->create(['campaign_id' => $campaign->id, 'sort_order' => 1]);
    $gate = CampaignGate::factory()->create(['campaign_stage_id' => $stage->id, 'sort_order' => 1]);
    CampaignStep::factory()->create(['campaign_gate_id' => $gate->id, 'sort_order' => 1]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('seasons.show', $season))
        ->assertOk()
        ->assertDontSee('ابدأ الرحلة');
});

test('a qualified user sees their rank displayed on the story map', function () {
    $campaign = Campaign::factory()->create(['is_active' => true]);
    $season = Season::factory()->create(['campaign_id' => $campaign->id, 'is_published' => true]);
    $stage = CampaignStage::factory()->create(['campaign_id' => $campaign->id, 'sort_order' => 1]);
    $gate = CampaignGate::factory()->create([
        'campaign_stage_id' => $stage->id, 'sort_order' => 1,
        'qualification_rule' => 'first_n', 'qualification_config' => ['limit' => 1],
    ]);
    $step = CampaignStep::factory()->create(['campaign_gate_id' => $gate->id, 'sort_order' => 1]);
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('campaigns.steps.complete', [$campaign, $step]));

    $this->actingAs($user)
        ->get(route('seasons.show', $season))
        ->assertOk()
        ->assertSee('الترتيب #1');
});

test('CampaignProgressService::qualifiedRankFor returns null for a non-qualified user', function () {
    $stage = CampaignStage::factory()->create(['sort_order' => 1]);
    $gate = CampaignGate::factory()->create([
        'campaign_stage_id' => $stage->id, 'sort_order' => 1,
        'qualification_rule' => 'first_n', 'qualification_config' => ['limit' => 1],
    ]);
    $user = User::factory()->create();

    expect(app(CampaignProgressService::class)->qualifiedRankFor($user, $gate))->toBeNull();
});