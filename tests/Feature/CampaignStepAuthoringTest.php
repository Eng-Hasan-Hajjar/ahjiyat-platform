<?php

use App\Models\Campaign;
use App\Models\CampaignGate;
use App\Models\CampaignStage;
use App\Models\CampaignStep;
use App\Models\User;

test('all four content status constants exist with the expected string values', function () {
    expect(CampaignStep::CONTENT_STATUS_FINAL)->toBe('final')
        ->and(CampaignStep::CONTENT_STATUS_PLACEHOLDER)->toBe('placeholder')
        ->and(CampaignStep::CONTENT_STATUS_CONTENT_PENDING)->toBe('content_pending')
        ->and(CampaignStep::CONTENT_STATUS_TECHNICAL_PENDING)->toBe('technical_pending');
});

test('StepsRelationManager never references puzzle solution data directly', function () {
    $source = file_get_contents(app_path('Filament/Resources/CampaignGateResource/RelationManagers/StepsRelationManager.php'));

    expect($source)->not->toContain('solution_data')
        ->and($source)->not->toContain('answer_hash');
});

test('a reflection step stores and reads back an optional intro alongside its prompt', function () {
    $stage = CampaignStage::factory()->create(['sort_order' => 1]);
    $gate = CampaignGate::factory()->create(['campaign_stage_id' => $stage->id, 'sort_order' => 1]);

    $step = CampaignStep::factory()->create([
        'campaign_gate_id' => $gate->id,
        'sort_order' => 1,
        'kind' => CampaignStep::KIND_REFLECTION,
        'content' => ['intro' => 'قبل أن تجيب، فكّر قليلاً.', 'prompt' => 'لماذا؟', 'min_chars' => 10, 'max_chars' => 500],
    ]);

    expect($step->fresh()->content['intro'])->toBe('قبل أن تجيب، فكّر قليلاً.');
});

test('the reflection player page renders the optional intro text when present', function () {
    $campaign = Campaign::factory()->create(['is_active' => true]);
    $stage = CampaignStage::factory()->create(['campaign_id' => $campaign->id, 'sort_order' => 1]);
    $gate = CampaignGate::factory()->create(['campaign_stage_id' => $stage->id, 'sort_order' => 1]);
    $step = CampaignStep::factory()->create([
        'campaign_gate_id' => $gate->id,
        'sort_order' => 1,
        'kind' => CampaignStep::KIND_REFLECTION,
        'content' => ['intro' => 'مقدّمة خاصة بالاختبار', 'prompt' => 'السؤال', 'min_chars' => 5, 'max_chars' => 100],
    ]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('campaigns.steps.show', [$campaign, $step]))
        ->assertOk()
        ->assertSee('مقدّمة خاصة بالاختبار');
});

test('a reflection page with no intro set does not render an empty intro paragraph error', function () {
    $campaign = Campaign::factory()->create(['is_active' => true]);
    $stage = CampaignStage::factory()->create(['campaign_id' => $campaign->id, 'sort_order' => 1]);
    $gate = CampaignGate::factory()->create(['campaign_stage_id' => $stage->id, 'sort_order' => 1]);
    $step = CampaignStep::factory()->create([
        'campaign_gate_id' => $gate->id,
        'sort_order' => 1,
        'kind' => CampaignStep::KIND_REFLECTION,
        'content' => ['prompt' => 'السؤال', 'min_chars' => 5, 'max_chars' => 100],
    ]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('campaigns.steps.show', [$campaign, $step]))
        ->assertOk();
});

test('the step media upload restricts accepted file types conditionally by media_type', function () {
    $source = file_get_contents(app_path('Filament/Resources/CampaignGateResource/RelationManagers/StepsRelationManager.php'));

    expect($source)->toContain('audio/mpeg')
        ->and($source)->toContain('image/jpeg');
});