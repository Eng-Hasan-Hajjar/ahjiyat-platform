<?php

use App\Models\Campaign;
use App\Models\CampaignGate;
use App\Models\CampaignStage;
use App\Models\CampaignStep;
use App\Models\GameSession;
use App\Models\Puzzle;
use App\Models\PuzzleAttempt;
use App\Models\User;
use App\Services\CampaignProgressService;
use App\Services\GameSessionService;use Illuminate\Support\Facades\Route;

function campaignSpotDifferenceStep(array $puzzleOverrides = []): CampaignStep
{
    $puzzle = Puzzle::factory()->create(array_merge([
        'game_type' => 'spot_difference',
        'game_config' => [
            'image_before' => 'puzzles/spot-difference/before.png',
            'image_after' => 'puzzles/spot-difference/after.png',
        ],
        'solution_data' => [
            'hotspots' => [
                ['x' => 0.25, 'y' => 0.25, 'radius' => 0.05],
                ['x' => 0.75, 'y' => 0.75, 'radius' => 0.05],
            ],
        ],
        'gem_reward' => 30,
    ], $puzzleOverrides));

    return CampaignStep::factory()->puzzle()->create(['puzzle_id' => $puzzle->id]);
}

beforeEach(function () {
    $this->progress = app(CampaignProgressService::class);
});

// ===== 1-2. Start session, context stored =====

test('an unlocked spot_difference campaign step can start a session', function () {
    $step = campaignSpotDifferenceStep();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('campaigns.steps.session', [$step->gate->stage->campaign, $step]))
        ->assertOk()
        ->assertJsonStructure(['session_id', 'found', 'required']);
});

test('the created session stores context_type=campaign_step and context_id=step.id', function () {
    $step = campaignSpotDifferenceStep();
    $user = User::factory()->create();

    $this->actingAs($user)->postJson(route('campaigns.steps.session', [$step->gate->stage->campaign, $step]));

    $session = GameSession::where('user_id', $user->id)->first();
    expect($session->context_type)->toBe('campaign_step')
        ->and($session->context_id)->toBe($step->id);
});

// ===== 3-5. Session reuse / isolation =====

test('starting the same campaign step session twice resumes the same active session', function () {
    $step = campaignSpotDifferenceStep();
    $user = User::factory()->create();
    $campaign = $step->gate->stage->campaign;

    $first = $this->actingAs($user)->postJson(route('campaigns.steps.session', [$campaign, $step]))->json('session_id');
    $second = $this->actingAs($user)->postJson(route('campaigns.steps.session', [$campaign, $step]))->json('session_id');

    expect($second)->toBe($first);
});

test('a standalone active session is not reused for a campaign step, and vice versa', function () {
    $step = campaignSpotDifferenceStep();
    $user = User::factory()->create();

    $standaloneSession = app(GameSessionService::class)->start($user, $step->puzzle);

    $campaignSessionId = $this->actingAs($user)
        ->postJson(route('campaigns.steps.session', [$step->gate->stage->campaign, $step]))
        ->json('session_id');

    expect($campaignSessionId)->not->toBe($standaloneSession->id);
});

test('a campaign A session is not reused for the equivalent step in campaign B', function () {
    $puzzle = Puzzle::factory()->create([
        'game_type' => 'spot_difference',
        'game_config' => ['image_before' => 'a.png', 'image_after' => 'b.png'],
        'solution_data' => ['hotspots' => [['x' => 0.2, 'y' => 0.2, 'radius' => 0.05]]],
    ]);
    $stepA = CampaignStep::factory()->puzzle()->create(['puzzle_id' => $puzzle->id]);
    $stepB = CampaignStep::factory()->puzzle()->create(['puzzle_id' => $puzzle->id]);
    $user = User::factory()->create();

    $sessionA = $this->actingAs($user)->postJson(route('campaigns.steps.session', [$stepA->gate->stage->campaign, $stepA]))->json('session_id');
    $sessionB = $this->actingAs($user)->postJson(route('campaigns.steps.session', [$stepB->gate->stage->campaign, $stepB]))->json('session_id');

    expect($sessionB)->not->toBe($sessionA);
});

// ===== 6-9. Guards =====

test('a locked step cannot start a session', function () {
    $step = campaignSpotDifferenceStep(); // sort_order = 1 افتراضياً
    CampaignStep::factory()->create(['campaign_gate_id' => $step->campaign_gate_id, 'sort_order' => 0]); // تسبقها ولم تكتمل
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('campaigns.steps.session', [$step->gate->stage->campaign, $step]))
        ->assertForbidden();
});

test('a narrative step cannot start a puzzle session', function () {
    $step = CampaignStep::factory()->create(); // narrative
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('campaigns.steps.session', [$step->gate->stage->campaign, $step]))
        ->assertStatus(422);
});

test('a stateless puzzle step cannot call the session endpoint', function () {
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح']);
    $step = CampaignStep::factory()->puzzle()->create(['puzzle_id' => $puzzle->id]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('campaigns.steps.session', [$step->gate->stage->campaign, $step]))
        ->assertStatus(422);
});

test('an inactive puzzle cannot start a campaign session', function () {
    $step = campaignSpotDifferenceStep(['is_active' => false]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('campaigns.steps.session', [$step->gate->stage->campaign, $step]))
        ->assertStatus(422);
});

// ===== 10-12. Finalization / completion =====

test('completing the session creates a PuzzleAttempt with the same campaign context', function () {
    $step = campaignSpotDifferenceStep();
    $user = User::factory()->create();

    $sessionId = $this->actingAs($user)
        ->postJson(route('campaigns.steps.session', [$step->gate->stage->campaign, $step]))
        ->json('session_id');

    $this->actingAs($user)->postJson(route('game-sessions.reveal', $sessionId), ['x' => 0.25, 'y' => 0.25]);
    $this->actingAs($user)->postJson(route('game-sessions.reveal', $sessionId), ['x' => 0.75, 'y' => 0.75]);

    $attempt = PuzzleAttempt::where('user_id', $user->id)->where('puzzle_id', $step->puzzle_id)->first();

    expect($attempt->context_type)->toBe('campaign_step')
        ->and($attempt->context_id)->toBe($step->id)
        ->and($attempt->is_correct)->toBeTrue();
});

test('a successfully completed session marks the step completed via derived logic', function () {
    $step = campaignSpotDifferenceStep();
    $user = User::factory()->create();

    $sessionId = $this->actingAs($user)
        ->postJson(route('campaigns.steps.session', [$step->gate->stage->campaign, $step]))
        ->json('session_id');

    $this->actingAs($user)->postJson(route('game-sessions.reveal', $sessionId), ['x' => 0.25, 'y' => 0.25]);
    $this->actingAs($user)->postJson(route('game-sessions.reveal', $sessionId), ['x' => 0.75, 'y' => 0.75]);

    expect($this->progress->isStepCompleted($user, $step->fresh()))->toBeTrue();
});

test('an incomplete session does not complete the step', function () {
    $step = campaignSpotDifferenceStep();
    $user = User::factory()->create();

    $sessionId = $this->actingAs($user)
        ->postJson(route('campaigns.steps.session', [$step->gate->stage->campaign, $step]))
        ->json('session_id');

    $this->actingAs($user)->postJson(route('game-sessions.reveal', $sessionId), ['x' => 0.25, 'y' => 0.25]);

    expect($this->progress->isStepCompleted($user, $step->fresh()))->toBeFalse()
        ->and($this->progress->isStepInProgress($user, $step->fresh()))->toBeTrue();
});

// ===== 13. Reveal stays generic =====

test('reveal uses the existing generic route - no campaign-specific reveal endpoint exists', function () {
    expect(Route::has('campaigns.steps.reveal'))->toBeFalse();
});

// ===== 14. No leak =====

test('the campaign session start response never leaks hotspots or solution_data', function () {
    $step = campaignSpotDifferenceStep();
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson(route('campaigns.steps.session', [$step->gate->stage->campaign, $step]));

    $response->assertOk();
    expect($response->getContent())->not->toContain('hotspots')->not->toContain('radius')->not->toContain('solution_data');
});

// ===== 15. Other user cannot reveal =====

test('another user cannot reveal a campaign-context session that is not theirs', function () {
    $step = campaignSpotDifferenceStep();
    $owner = User::factory()->create();
    $intruder = User::factory()->create();

    $sessionId = $this->actingAs($owner)
        ->postJson(route('campaigns.steps.session', [$step->gate->stage->campaign, $step]))
        ->json('session_id');

    $this->actingAs($intruder)
        ->postJson(route('game-sessions.reveal', $sessionId), ['x' => 0.25, 'y' => 0.25])
        ->assertForbidden();
});