<?php

use App\Models\Campaign;
use App\Models\CampaignStep;
use App\Models\Puzzle;
use App\Models\PuzzleAttempt;
use App\Models\Season;
use App\Models\User;
use App\Services\CampaignProgressService;
use App\Services\SeasonReadinessService;
use Database\Seeders\Seasons\AseelSeasonSeeder;

function seedAseel(): Campaign
{
    (new AseelSeasonSeeder)->run();

    return Campaign::where('slug', 'aseel-season-01')->firstOrFail();
}

test('the Aseel seeder is idempotent - running it twice does not duplicate anything', function () {
    seedAseel();
    $before = [
        Campaign::count(), Season::count(),
        \App\Models\CampaignStage::count(), \App\Models\CampaignGate::count(),
        CampaignStep::count(), Puzzle::where('title', 'أثر ١: مكتب أصيل')->count(),
    ];

    seedAseel();
    $after = [
        Campaign::count(), Season::count(),
        \App\Models\CampaignStage::count(), \App\Models\CampaignGate::count(),
        CampaignStep::count(), Puzzle::where('title', 'أثر ١: مكتب أصيل')->count(),
    ];

    expect($after)->toBe($before);
});

test('the Aseel structure has exactly 3 stages, 6 gates, and 22 steps', function () {
    $campaign = seedAseel();

    expect($campaign->stages)->toHaveCount(3);

    $totalGates = $campaign->stages->sum(fn ($s) => $s->gates->count());
    $totalSteps = $campaign->stages->sum(fn ($s) => $s->gates->sum(fn ($g) => $g->steps->count()));

    expect($totalGates)->toBe(6)
        ->and($totalSteps)->toBe(22);
});

test('gate 1 carries the first_n qualification with a provisional limit of 100', function () {
    $campaign = seedAseel();
    $gate1 = $campaign->stages->sortBy('sort_order')->first()->gates->sortBy('sort_order')->first();

    expect($gate1->qualification_rule)->toBe('first_n')
        ->and($gate1->qualification_config['limit'])->toBe(100);
});

test('the season is published and featured for the client demo', function () {
    $campaign = seedAseel();

    expect($campaign->season->is_published)->toBeTrue()
        ->and($campaign->season->is_featured)->toBeTrue();
});

test('completing gate 1 (narrative, spot_difference, math) unlocks gate 2', function () {
    $campaign = seedAseel();
    $progress = app(CampaignProgressService::class);
    $stage1 = $campaign->stages->sortBy('sort_order')->first();
    $gate1 = $stage1->gates->sortBy('sort_order')->first();
    $gate2 = $stage1->gates->sortBy('sort_order')->values()[1];
    [$narrative, $spotDiff, $notebook] = $gate1->steps->sortBy('sort_order')->values()->all();
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('campaigns.steps.complete', [$campaign, $narrative]));

    $sessionId = $this->actingAs($user)->postJson(route('campaigns.steps.session', [$campaign, $spotDiff]))->json('session_id');
    foreach ($spotDiff->puzzle->solution_data['hotspots'] as $hotspot) {
        $this->actingAs($user)->postJson(route('game-sessions.reveal', $sessionId), ['x' => $hotspot['x'], 'y' => $hotspot['y']]);
    }

    $this->actingAs($user)->post(route('campaigns.steps.attempt', [$campaign, $notebook]), ['answer' => '24']);

    expect($progress->isGateCompleted($user, $gate1->fresh()))->toBeTrue()
        ->and($progress->isGateUnlocked($user, $gate2->fresh()))->toBeTrue();
});

test('the two Stage 3 reflection steps use the generic reflection kind and flow', function () {
    $campaign = seedAseel();
    $stage3 = $campaign->stages->sortBy('sort_order')->values()[2];
    $gate6 = $stage3->gates->first();
    $steps = $gate6->steps->sortBy('sort_order')->values();

    expect($steps[0]->kind)->toBe(CampaignStep::KIND_REFLECTION)
        ->and($steps[1]->kind)->toBe(CampaignStep::KIND_REFLECTION)
        ->and($steps[2]->kind)->toBe(CampaignStep::KIND_NARRATIVE);
});

test('the team and chess placeholder steps are marked technical_pending and are narrative (not a fake system)', function () {
    $campaign = seedAseel();
    $stage2 = $campaign->stages->sortBy('sort_order')->values()[1];
    $gate5 = $stage2->gates->sortBy('sort_order')->values()[2];
    $steps = $gate5->steps->sortBy('sort_order')->values();

    expect($steps[0]->kind)->toBe(CampaignStep::KIND_NARRATIVE)
        ->and($steps[0]->contentStatus())->toBe(CampaignStep::CONTENT_STATUS_TECHNICAL_PENDING)
        ->and($steps[1]->kind)->toBe(CampaignStep::KIND_NARRATIVE)
        ->and($steps[1]->contentStatus())->toBe(CampaignStep::CONTENT_STATUS_TECHNICAL_PENDING);
});

test('technical_pending steps block the season from being readiness-approved for publish', function () {
    $campaign = seedAseel();
    $season = $campaign->season;

    expect(app(SeasonReadinessService::class)->isReadyToPublish($season))->toBeFalse();
});

test('the memory game step uses the existing generic memory game type, not a new Aseel-specific one', function () {
    $campaign = seedAseel();
    $stage2 = $campaign->stages->sortBy('sort_order')->values()[1];
    $gate5 = $stage2->gates->sortBy('sort_order')->values()[2];
    $memoryStep = $gate5->steps->sortBy('sort_order')->values()[2];

    expect($memoryStep->kind)->toBe(CampaignStep::KIND_PUZZLE)
        ->and($memoryStep->puzzle->game_type)->toBe('memory');
});

test('no Aseel-specific controller or game-engine class exists anywhere in the app', function () {
    expect(class_exists('App\Http\Controllers\AseelController'))->toBeFalse()
        ->and(class_exists('App\Http\Controllers\Season1Controller'))->toBeFalse()
        ->and(class_exists('App\Services\AseelPuzzleService'))->toBeFalse()
        ->and(class_exists('App\Services\AseelReflectionService'))->toBeFalse()
        ->and(class_exists('App\GameEngine\Definitions\AseelSpotDifferenceGameTypeDefinition'))->toBeFalse();
});

test('the spot_difference placeholder images were generated and exist on the public disk', function () {
    seedAseel();

    expect(Illuminate\Support\Facades\Storage::disk('public')->exists('seasons/aseel/story/office-before-placeholder.png'))->toBeTrue()
        ->and(Illuminate\Support\Facades\Storage::disk('public')->exists('seasons/aseel/story/office-after-placeholder.png'))->toBeTrue();
});

test('locked future steps do not leak their narrative content or puzzle prompts', function () {
    $campaign = seedAseel();
    $stage1 = $campaign->stages->sortBy('sort_order')->first();
    $gate2 = $stage1->gates->sortBy('sort_order')->values()[1];
    $lockedStep = $gate2->steps->sortBy('sort_order')->first();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('campaigns.steps.show', [$campaign, $lockedStep]))
        ->assertOk()
        ->assertViewIs('campaigns.steps.locked')
        ->assertDontSee('لقد وصلت إلى هنا');
});

test('no PuzzleAttempt exists before any interaction with the seeded puzzles', function () {
    seedAseel();

    expect(PuzzleAttempt::count())->toBe(0);
});