<?php

use App\Models\Campaign;
use App\Models\CampaignStep;
use App\Models\Puzzle;
use App\Models\PuzzleAttempt;
use App\Models\User;
use App\Services\CampaignProgressService;
use App\Services\PuzzleAttemptService;

function campaignPuzzleStep(array $puzzleOverrides = []): CampaignStep
{
    $puzzle = Puzzle::factory()->create(array_merge([
        'answer_raw' => 'صح',
        'max_attempts' => 3,
    ], $puzzleOverrides));

    return CampaignStep::factory()->puzzle()->create(['puzzle_id' => $puzzle->id]);
}

beforeEach(function () {
    $this->progress = app(CampaignProgressService::class);
});

// ===== 1-3. Correct attempt: stores context, completes step =====

test('an unlocked puzzle step accepts a correct attempt', function () {
    $step = campaignPuzzleStep();
    $user = User::factory()->create();
    $campaign = $step->gate->stage->campaign;

    $this->actingAs($user)
        ->post(route('campaigns.steps.attempt', [$campaign, $step]), ['answer' => 'صح'])
        ->assertRedirect()
        ->assertSessionHas('success');
});

test('a correct campaign attempt stores context_type=campaign_step and context_id=step.id', function () {
    $step = campaignPuzzleStep();
    $user = User::factory()->create();
    $campaign = $step->gate->stage->campaign;

    $this->actingAs($user)->post(route('campaigns.steps.attempt', [$campaign, $step]), ['answer' => 'صح']);

    $attempt = PuzzleAttempt::where('user_id', $user->id)->where('puzzle_id', $step->puzzle_id)->first();

    expect($attempt->context_type)->toBe('campaign_step')
        ->and($attempt->context_id)->toBe($step->id);
});

test('a correct campaign attempt marks the step completed via derived logic', function () {
    $step = campaignPuzzleStep();
    $user = User::factory()->create();
    $campaign = $step->gate->stage->campaign;

    $this->actingAs($user)->post(route('campaigns.steps.attempt', [$campaign, $step]), ['answer' => 'صح']);

    expect($this->progress->isStepCompleted($user, $step->fresh()))->toBeTrue();
});

// ===== 4-5. Wrong attempt =====

test('a wrong campaign attempt does not complete the step', function () {
    $step = campaignPuzzleStep();
    $user = User::factory()->create();
    $campaign = $step->gate->stage->campaign;

    $this->actingAs($user)->post(route('campaigns.steps.attempt', [$campaign, $step]), ['answer' => 'خطأ']);

    expect($this->progress->isStepCompleted($user, $step->fresh()))->toBeFalse();
});

test('a wrong campaign attempt marks the step in_progress', function () {
    $step = campaignPuzzleStep();
    $user = User::factory()->create();
    $campaign = $step->gate->stage->campaign;

    $this->actingAs($user)->post(route('campaigns.steps.attempt', [$campaign, $step]), ['answer' => 'خطأ']);

    expect($this->progress->isStepInProgress($user, $step->fresh()))->toBeTrue();
});

// ===== 6-7. Cross-context isolation =====

test('solving the same puzzle standalone does not complete the campaign step', function () {
    $step = campaignPuzzleStep();
    $user = User::factory()->create();

    app(PuzzleAttemptService::class)->attempt($user, $step->puzzle, 'صح');

    expect($this->progress->isStepCompleted($user, $step->fresh()))->toBeFalse();
});

test('solving the puzzle in campaign A does not complete the equivalent step in campaign B', function () {
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح', 'max_attempts' => 3]);
    $stepA = CampaignStep::factory()->puzzle()->create(['puzzle_id' => $puzzle->id]);
    $stepB = CampaignStep::factory()->puzzle()->create(['puzzle_id' => $puzzle->id]);
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('campaigns.steps.attempt', [$stepA->gate->stage->campaign, $stepA]), ['answer' => 'صح']);

    expect($this->progress->isStepCompleted($user, $stepA->fresh()))->toBeTrue()
        ->and($this->progress->isStepCompleted($user, $stepB->fresh()))->toBeFalse();
});

// ===== 8-10. Attempt-limit scoping =====

test('standalone attempts do not consume campaign attempts, and vice versa', function () {
    $step = campaignPuzzleStep(['max_attempts' => 1]);
    $user = User::factory()->create();
    $campaign = $step->gate->stage->campaign;

    app(PuzzleAttemptService::class)->attempt($user, $step->puzzle, 'خطأ'); // يستنفد المحاولة المستقلة الوحيدة

    $this->actingAs($user)
        ->post(route('campaigns.steps.attempt', [$campaign, $step]), ['answer' => 'صح'])
        ->assertSessionHas('success'); // لسا عنده محاولته الكاملة داخل الحملة
});

test('campaign A attempts do not consume campaign B attempts for the same puzzle', function () {
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح', 'max_attempts' => 1]);
    $stepA = CampaignStep::factory()->puzzle()->create(['puzzle_id' => $puzzle->id]);
    $stepB = CampaignStep::factory()->puzzle()->create(['puzzle_id' => $puzzle->id]);
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('campaigns.steps.attempt', [$stepA->gate->stage->campaign, $stepA]), ['answer' => 'خطأ']);

    $this->actingAs($user)
        ->post(route('campaigns.steps.attempt', [$stepB->gate->stage->campaign, $stepB]), ['answer' => 'صح'])
        ->assertSessionHas('success');
});

// ===== 11. No double reward =====

test('an already-solved campaign step cannot be rewarded a second time', function () {
    $step = campaignPuzzleStep();
    $user = User::factory()->create();
    $campaign = $step->gate->stage->campaign;

    $this->actingAs($user)->post(route('campaigns.steps.attempt', [$campaign, $step]), ['answer' => 'صح']);
    $user->wallet->refresh();
    $firstBalance = $user->wallet->pending_balance;

    $this->actingAs($user)
        ->post(route('campaigns.steps.attempt', [$campaign, $step]), ['answer' => 'صح'])
        ->assertSessionHas('error');

    $user->wallet->refresh();
    expect($user->wallet->pending_balance)->toBe($firstBalance);
});

// ===== 12-16. Locked / unavailable / inactive =====

test('a locked puzzle step cannot be attempted', function () {
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح']);
    $step = CampaignStep::factory()->puzzle()->create(['puzzle_id' => $puzzle->id, 'sort_order' => 2]);
    CampaignStep::factory()->create(['campaign_gate_id' => $step->campaign_gate_id, 'sort_order' => 1]); // غير مكتملة
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('campaigns.steps.attempt', [$step->gate->stage->campaign, $step]), ['answer' => 'صح'])
        ->assertForbidden();
});

test('an inactive campaign cannot be attempted', function () {
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح']);
    $campaign = Campaign::factory()->create(['is_active' => false]);
    $step = campaignStepForCampaign($campaign, $puzzle);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('campaigns.steps.attempt', [$campaign, $step]), ['answer' => 'صح'])
        ->assertForbidden();
});

test('a not-yet-started campaign cannot be attempted', function () {
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح']);
    $campaign = Campaign::factory()->create(['starts_at' => now()->addDay()]);
    $step = campaignStepForCampaign($campaign, $puzzle);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('campaigns.steps.attempt', [$campaign, $step]), ['answer' => 'صح'])
        ->assertForbidden();
});

test('an ended campaign cannot be attempted', function () {
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح']);
    $campaign = Campaign::factory()->create(['ends_at' => now()->subDay()]);
    $step = campaignStepForCampaign($campaign, $puzzle);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('campaigns.steps.attempt', [$campaign, $step]), ['answer' => 'صح'])
        ->assertForbidden();
});

test('an inactive puzzle cannot be attempted through a campaign step', function () {
    $step = campaignPuzzleStep(['is_active' => false]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('campaigns.steps.attempt', [$step->gate->stage->campaign, $step]), ['answer' => 'صح'])
        ->assertRedirect()
        ->assertSessionHas('error');
});

function campaignStepForCampaign(Campaign $campaign, Puzzle $puzzle): CampaignStep
{
    $stage = App\Models\CampaignStage::factory()->create(['campaign_id' => $campaign->id, 'sort_order' => 1]);
    $gate = App\Models\CampaignGate::factory()->create(['campaign_stage_id' => $stage->id, 'sort_order' => 1]);

    return CampaignStep::factory()->puzzle()->create(['campaign_gate_id' => $gate->id, 'sort_order' => 1, 'puzzle_id' => $puzzle->id]);
}

// ===== 17-18. Kind mismatch / IDOR =====

test('a narrative step cannot use the puzzle attempt endpoint', function () {
    $step = CampaignStep::factory()->create(); // narrative بالافتراض
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('campaigns.steps.attempt', [$step->gate->stage->campaign, $step]), ['answer' => 'أي شيء'])
        ->assertRedirect()
        ->assertSessionHas('error');
});

test('a campaign-step relation mismatch is rejected with 404', function () {
    $campaignA = Campaign::factory()->create();
    $stepInB = campaignPuzzleStep();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('campaigns.steps.attempt', [$campaignA, $stepInB]), ['answer' => 'صح'])
        ->assertNotFound();
});

// ===== 19-20. Client cannot choose puzzle/context =====

test('a client cannot redirect the attempt to a different puzzle via the request body', function () {
    $step = campaignPuzzleStep();
    $otherPuzzle = Puzzle::factory()->create();
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('campaigns.steps.attempt', [$step->gate->stage->campaign, $step]), [
        'answer' => 'صح',
        'puzzle_id' => $otherPuzzle->id, // غير مقروء أبداً - يُتجاهَل بالكامل
    ]);

    $attempt = PuzzleAttempt::where('user_id', $user->id)->first();
    expect($attempt->puzzle_id)->toBe($step->puzzle_id)
        ->and($attempt->puzzle_id)->not->toBe($otherPuzzle->id);
});

test('a client cannot override the context via the request body', function () {
    $step = campaignPuzzleStep();
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('campaigns.steps.attempt', [$step->gate->stage->campaign, $step]), [
        'answer' => 'صح',
        'context_type' => 'campaign_step',
        'context_id' => 999999, // غير مقروء أبداً - السياق يُبنى من $step سيرفرياً فقط
    ]);

    $attempt = PuzzleAttempt::where('user_id', $user->id)->first();
    expect($attempt->context_id)->toBe($step->id);
});

// ===== 21. Existing game types keep working =====

test('a sequence puzzle submission still works correctly inside a campaign step', function () {
    $puzzle = Puzzle::factory()->create([
        'game_type' => 'sequence',
        'validation_type' => 'sequence_match',
        'score_mode' => 'flat',
        'renderer' => 'games.sequence',
        'game_config' => ['items' => ['أ', 'ب', 'ج']],
        'gem_reward' => 15,
    ]);
    $step = CampaignStep::factory()->puzzle()->create(['puzzle_id' => $puzzle->id]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('campaigns.steps.attempt', [$step->gate->stage->campaign, $step]), [
            'submission' => json_encode(['order' => [0, 1, 2]]),
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $user->wallet->refresh();
    expect($user->wallet->pending_balance)->toBe(15);
});

// ===== Unlock integration (item 33) =====

test('a following narrative step becomes available after a correct campaign-context puzzle solve - no manual progress row', function () {
    $puzzleStep = campaignPuzzleStep();
    $narrativeStep = CampaignStep::factory()->create(['campaign_gate_id' => $puzzleStep->campaign_gate_id, 'sort_order' => 2]);
    $user = User::factory()->create();
    $campaign = $puzzleStep->gate->stage->campaign;

    expect($this->progress->isStepUnlocked($user, $narrativeStep))->toBeFalse();

    $this->actingAs($user)->post(route('campaigns.steps.attempt', [$campaign, $puzzleStep]), ['answer' => 'صح']);

    expect($this->progress->isStepUnlocked($user, $narrativeStep->fresh()))->toBeTrue();
});