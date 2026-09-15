<?php

use App\Models\Campaign;
use App\Models\CampaignGate;
use App\Models\CampaignStage;
use App\Models\CampaignStep;
use App\Models\Puzzle;
use App\Models\Season;
use App\Services\SeasonReadinessService;

test('a season whose campaign has no stages is not ready', function () {
    $campaign = Campaign::factory()->create();
    $season = Season::factory()->create(['campaign_id' => $campaign->id]);

    $result = app(SeasonReadinessService::class)->check($season);

    expect($result['ready'])->toBeFalse()
        ->and($result['issues'])->not->toBeEmpty();
});

test('a season with a complete simple structure is ready', function () {
    $campaign = Campaign::factory()->create();
    $season = Season::factory()->create(['campaign_id' => $campaign->id]);
    $stage = CampaignStage::factory()->create(['campaign_id' => $campaign->id, 'sort_order' => 1]);
    $gate = CampaignGate::factory()->create(['campaign_stage_id' => $stage->id, 'sort_order' => 1]);
    CampaignStep::factory()->create(['campaign_gate_id' => $gate->id, 'sort_order' => 1]);

    expect(app(SeasonReadinessService::class)->isReadyToPublish($season))->toBeTrue();
});

test('a puzzle-kind step with no linked puzzle blocks readiness', function () {
    $campaign = Campaign::factory()->create();
    $season = Season::factory()->create(['campaign_id' => $campaign->id]);
    $stage = CampaignStage::factory()->create(['campaign_id' => $campaign->id, 'sort_order' => 1]);
    $gate = CampaignGate::factory()->create(['campaign_stage_id' => $stage->id, 'sort_order' => 1]);
    CampaignStep::factory()->create(['campaign_gate_id' => $gate->id, 'sort_order' => 1, 'kind' => CampaignStep::KIND_PUZZLE, 'puzzle_id' => null]);

    $result = app(SeasonReadinessService::class)->check($season);

    expect($result['ready'])->toBeFalse();
});

test('a step marked technical_pending blocks readiness', function () {
    $campaign = Campaign::factory()->create();
    $season = Season::factory()->create(['campaign_id' => $campaign->id]);
    $stage = CampaignStage::factory()->create(['campaign_id' => $campaign->id, 'sort_order' => 1]);
    $gate = CampaignGate::factory()->create(['campaign_stage_id' => $stage->id, 'sort_order' => 1]);
    CampaignStep::factory()->create([
        'campaign_gate_id' => $gate->id, 'sort_order' => 1,
        'content' => ['body' => 'x', 'status' => CampaignStep::CONTENT_STATUS_TECHNICAL_PENDING],
    ]);

    $result = app(SeasonReadinessService::class)->check($season);

    expect($result['ready'])->toBeFalse()
        ->and(collect($result['issues'])->contains(fn ($i) => str_contains($i, 'قرار تقني')))->toBeTrue();
});

test('a step marked content_pending (not technical_pending) does not block readiness', function () {
    $campaign = Campaign::factory()->create();
    $season = Season::factory()->create(['campaign_id' => $campaign->id]);
    $stage = CampaignStage::factory()->create(['campaign_id' => $campaign->id, 'sort_order' => 1]);
    $gate = CampaignGate::factory()->create(['campaign_stage_id' => $stage->id, 'sort_order' => 1]);
    CampaignStep::factory()->create([
        'campaign_gate_id' => $gate->id, 'sort_order' => 1,
        'content' => ['body' => 'x', 'status' => CampaignStep::CONTENT_STATUS_CONTENT_PENDING],
    ]);

    expect(app(SeasonReadinessService::class)->isReadyToPublish($season))->toBeTrue();
});

test('a step referencing a missing media file blocks readiness', function () {
    $campaign = Campaign::factory()->create();
    $season = Season::factory()->create(['campaign_id' => $campaign->id]);
    $stage = CampaignStage::factory()->create(['campaign_id' => $campaign->id, 'sort_order' => 1]);
    $gate = CampaignGate::factory()->create(['campaign_stage_id' => $stage->id, 'sort_order' => 1]);
    CampaignStep::factory()->create([
        'campaign_gate_id' => $gate->id, 'sort_order' => 1,
        'content' => ['body' => 'x', 'media_type' => 'image', 'media_path' => 'nonexistent/path.png'],
    ]);

    expect(app(SeasonReadinessService::class)->isReadyToPublish($season))->toBeFalse();
});

test('an empty gate blocks readiness', function () {
    $campaign = Campaign::factory()->create();
    $season = Season::factory()->create(['campaign_id' => $campaign->id]);
    $stage = CampaignStage::factory()->create(['campaign_id' => $campaign->id, 'sort_order' => 1]);
    CampaignGate::factory()->create(['campaign_stage_id' => $stage->id, 'sort_order' => 1]);

    expect(app(SeasonReadinessService::class)->isReadyToPublish($season))->toBeFalse();
});

test('the Filament EditSeason page silently refuses to persist is_published=true when not ready', function () {
    $campaign = Campaign::factory()->create();
    $season = Season::factory()->create(['campaign_id' => $campaign->id, 'is_published' => false]);

    $readiness = app(SeasonReadinessService::class)->check($season);

    expect($readiness['ready'])->toBeFalse();
});