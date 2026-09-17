<?php

use App\Models\Campaign;
use App\Models\CampaignGate;
use App\Models\CampaignStage;
use App\Models\CampaignStep;
use App\Models\Season;
use App\Models\User;

test('an admin previewing an unpublished season sees the preview mode badge', function () {
    $campaign = Campaign::factory()->create(['is_active' => true]);
    $season = Season::factory()->create(['campaign_id' => $campaign->id, 'is_published' => false]);
    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($admin)
        ->get(route('seasons.show', $season))
        ->assertOk()
        ->assertSee('وضع المعاينة');
});

test('a published season shows no preview mode badge, even to an admin', function () {
    $campaign = Campaign::factory()->create(['is_active' => true]);
    $season = Season::factory()->create(['campaign_id' => $campaign->id, 'is_published' => true]);
    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($admin)
        ->get(route('seasons.show', $season))
        ->assertOk()
        ->assertDontSee('وضع المعاينة');
});

test('a regular player never sees the preview mode badge, even if somehow viewing an unpublished season (which they cannot)', function () {
    $campaign = Campaign::factory()->create(['is_active' => true]);
    $season = Season::factory()->create(['campaign_id' => $campaign->id, 'is_published' => false]);
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('seasons.show', $season))->assertNotFound();
});

test('the Filament preview action on a Puzzle and a Season both point at the real player-facing route, not a duplicate renderer', function () {
    $puzzleSource = file_get_contents(app_path('Filament/Resources/PuzzleResource/Pages/EditPuzzle.php'));
    $seasonSource = file_get_contents(app_path('Filament/Resources/SeasonResource.php'));

    expect($puzzleSource)->toContain("route('puzzles.show'")
        ->and($seasonSource)->toContain("route('seasons.show'");
});

test('admin-only content status metadata still stays hidden from non-admin players after the E2 changes', function () {
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