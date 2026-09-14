<?php

use App\Models\Campaign;
use App\Models\CampaignGate;
use App\Models\CampaignStage;
use App\Models\CampaignStep;
use App\Models\GemTransaction;
use App\Models\Puzzle;
use App\Models\PuzzleAttempt;
use App\Models\User;
use App\Models\UserCampaignProgress;
use App\Services\CampaignNarrativeService;
use Illuminate\Auth\Access\AuthorizationException;

beforeEach(function () {
    $this->narrative = app(CampaignNarrativeService::class);
});

/**
 * أي CampaignStep::factory()->create() بلا تخصيص تنشئ سلسلة كاملة (Campaign
 * → Stage#1 → Gate#1 → Step#1) عبر factories C1 المتسلسلة - أي الخطوة
 * الأولى بكل شيء، فتكون مفتوحة تلقائياً حسب منطق C2 (لا حاجة لإعداد يدوي).
 */
function unlockedNarrativeStep(): CampaignStep
{
    return CampaignStep::factory()->create();
}

// ===== A. markStarted() =====

test('an unlocked narrative step can be marked started', function () {
    $step = unlockedNarrativeStep();
    $user = User::factory()->create();

    $progress = $this->narrative->markStarted($user, $step);

    expect($progress->started_at)->not->toBeNull()
        ->and($progress->completed_at)->toBeNull();
});

test('markStarted creates exactly one progress row', function () {
    $step = unlockedNarrativeStep();
    $user = User::factory()->create();

    $this->narrative->markStarted($user, $step);

    expect(UserCampaignProgress::where('user_id', $user->id)->where('campaign_step_id', $step->id)->count())->toBe(1);
});

test('calling markStarted twice keeps the same row', function () {
    $step = unlockedNarrativeStep();
    $user = User::factory()->create();

    $first = $this->narrative->markStarted($user, $step);
    $second = $this->narrative->markStarted($user, $step);

    expect($second->id)->toBe($first->id)
        ->and(UserCampaignProgress::count())->toBe(1);
});

test('markStarted does not overwrite an existing started_at', function () {
    $step = unlockedNarrativeStep();
    $user = User::factory()->create();

    $first = $this->narrative->markStarted($user, $step);
    $originalStartedAt = $first->started_at;

    $this->travel(5)->minutes();
    $second = $this->narrative->markStarted($user, $step);

    expect($second->started_at->eq($originalStartedAt))->toBeTrue();
});

test('a locked narrative step cannot be marked started', function () {
    $gate = CampaignGate::factory()->create();
    CampaignStep::factory()->create(['campaign_gate_id' => $gate->id, 'sort_order' => 1]); // غير مكتملة
    $locked = CampaignStep::factory()->create(['campaign_gate_id' => $gate->id, 'sort_order' => 2]);
    $user = User::factory()->create();

    expect(fn () => $this->narrative->markStarted($user, $locked))->toThrow(AuthorizationException::class);
    expect(UserCampaignProgress::count())->toBe(0);
});

test('a puzzle-kind step cannot use narrative markStarted', function () {
    $puzzle = Puzzle::factory()->create();
    $step = CampaignStep::factory()->puzzle()->create(['puzzle_id' => $puzzle->id]);
    $user = User::factory()->create();

    expect(fn () => $this->narrative->markStarted($user, $step))->toThrow(RuntimeException::class);
    expect(UserCampaignProgress::count())->toBe(0);
});

// ===== B. complete() =====

test('an unlocked narrative step can be completed', function () {
    $step = unlockedNarrativeStep();
    $user = User::factory()->create();

    $progress = $this->narrative->complete($user, $step);

    expect($progress->completed_at)->not->toBeNull();
});

test('complete creates a progress row with started_at and completed_at when none existed', function () {
    $step = unlockedNarrativeStep();
    $user = User::factory()->create();

    $progress = $this->narrative->complete($user, $step);

    expect($progress->started_at)->not->toBeNull()
        ->and($progress->completed_at)->not->toBeNull()
        ->and(UserCampaignProgress::count())->toBe(1);
});

test('complete preserves an already-set started_at and only sets completed_at', function () {
    $step = unlockedNarrativeStep();
    $user = User::factory()->create();

    $started = $this->narrative->markStarted($user, $step);
    $originalStartedAt = $started->started_at;

    $this->travel(5)->minutes();
    $completed = $this->narrative->complete($user, $step);

    expect($completed->started_at->eq($originalStartedAt))->toBeTrue()
        ->and($completed->completed_at)->not->toBeNull();
});

test('complete called twice is idempotent and does not regenerate completed_at', function () {
    $step = unlockedNarrativeStep();
    $user = User::factory()->create();

    $first = $this->narrative->complete($user, $step);
    $originalCompletedAt = $first->completed_at;

    $this->travel(5)->minutes();
    $second = $this->narrative->complete($user, $step);

    expect($second->id)->toBe($first->id)
        ->and($second->completed_at->eq($originalCompletedAt))->toBeTrue()
        ->and(UserCampaignProgress::where('user_id', $user->id)->where('campaign_step_id', $step->id)->count())->toBe(1);
});

test('a locked narrative step cannot be completed', function () {
    $gate = CampaignGate::factory()->create();
    CampaignStep::factory()->create(['campaign_gate_id' => $gate->id, 'sort_order' => 1]); // غير مكتملة
    $locked = CampaignStep::factory()->create(['campaign_gate_id' => $gate->id, 'sort_order' => 2]);
    $user = User::factory()->create();

    expect(fn () => $this->narrative->complete($user, $locked))->toThrow(AuthorizationException::class);
    expect(UserCampaignProgress::count())->toBe(0);
});

test('a puzzle-kind step cannot use narrative complete, and no progress row is created for it', function () {
    $puzzle = Puzzle::factory()->create();
    $step = CampaignStep::factory()->puzzle()->create(['puzzle_id' => $puzzle->id]);
    $user = User::factory()->create();

    expect(fn () => $this->narrative->complete($user, $step))->toThrow(RuntimeException::class);
    expect(UserCampaignProgress::where('campaign_step_id', $step->id)->count())->toBe(0);
});

// ===== C. Derived unlock propagation (no auto-advance columns written) =====

test('the next step becomes unlocked automatically once the previous narrative step completes - purely derived', function () {
    $gate = CampaignGate::factory()->create();
    $first = CampaignStep::factory()->create(['campaign_gate_id' => $gate->id, 'sort_order' => 1]);
    $second = CampaignStep::factory()->create(['campaign_gate_id' => $gate->id, 'sort_order' => 2]);
    $user = User::factory()->create();

    $progressService = app(App\Services\CampaignProgressService::class);
    expect($progressService->isStepUnlocked($user, $second))->toBeFalse();

    $this->narrative->complete($user, $first);

    expect($progressService->isStepUnlocked($user, $second->fresh()))->toBeTrue();
    // لا عمود "next_step"/"gate_completed" أو ما شابه كُتب لأي مكان - الاشتقاق فقط
    expect($second->fresh()->getAttributes())->not->toHaveKey('unlocked');
});

// ===== D. Campaign availability gating =====

test('narrative cannot start or complete on an inactive campaign', function () {
    $campaign = Campaign::factory()->create(['is_active' => false]);
    $step = stepInCampaign($campaign);
    $user = User::factory()->create();

    expect(fn () => $this->narrative->markStarted($user, $step))->toThrow(AuthorizationException::class);
    expect(fn () => $this->narrative->complete($user, $step))->toThrow(AuthorizationException::class);
});

test('narrative cannot start or complete on a not-yet-started campaign', function () {
    $campaign = Campaign::factory()->create(['starts_at' => now()->addDay()]);
    $step = stepInCampaign($campaign);
    $user = User::factory()->create();

    expect(fn () => $this->narrative->markStarted($user, $step))->toThrow(AuthorizationException::class);
    expect(fn () => $this->narrative->complete($user, $step))->toThrow(AuthorizationException::class);
});

test('narrative cannot start or complete on an ended campaign', function () {
    $campaign = Campaign::factory()->create(['ends_at' => now()->subDay()]);
    $step = stepInCampaign($campaign);
    $user = User::factory()->create();

    expect(fn () => $this->narrative->markStarted($user, $step))->toThrow(AuthorizationException::class);
    expect(fn () => $this->narrative->complete($user, $step))->toThrow(AuthorizationException::class);
});

function stepInCampaign(Campaign $campaign): CampaignStep
{
    $stage = CampaignStage::factory()->create(['campaign_id' => $campaign->id, 'sort_order' => 1]);
    $gate = CampaignGate::factory()->create(['campaign_stage_id' => $stage->id, 'sort_order' => 1]);

    return CampaignStep::factory()->create(['campaign_gate_id' => $gate->id, 'sort_order' => 1]);
}

// ===== E. started_at is not authorization =====

test('a stray started_at on a still-locked step does not grant access to complete it', function () {
    $gate = CampaignGate::factory()->create();
    CampaignStep::factory()->create(['campaign_gate_id' => $gate->id, 'sort_order' => 1]); // غير مكتملة
    $locked = CampaignStep::factory()->create(['campaign_gate_id' => $gate->id, 'sort_order' => 2]);
    $user = User::factory()->create();

    // صف Progress "بدأ" موجود مسبقاً لخطوة لسا مقفلة فعلياً (بيانات غير منطقية) -
    // CampaignAccessService (عبر isStepUnlocked) يجب أن يبقى الحَكَم الوحيد.
    UserCampaignProgress::factory()->create([
        'user_id' => $user->id,
        'campaign_step_id' => $locked->id,
        'started_at' => now()->subDay(),
        'completed_at' => null,
    ]);

    expect(fn () => $this->narrative->complete($user, $locked))->toThrow(AuthorizationException::class);
});

// ===== F. HTTP layer =====

test('completing a step through a mismatched campaign URL is rejected with 404', function () {
    $campaignA = Campaign::factory()->create();
    $campaignB = Campaign::factory()->create();
    $stepInB = stepInCampaign($campaignB);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('campaigns.steps.complete', [$campaignA, $stepInB]))
        ->assertNotFound();

    expect(UserCampaignProgress::count())->toBe(0);
});

test('a guest cannot call the narrative completion route', function () {
    $campaign = Campaign::factory()->create();
    $step = stepInCampaign($campaign);

    $this->post(route('campaigns.steps.complete', [$campaign, $step]))
        ->assertRedirect(route('login'));
});

test('an unverified user cannot call the narrative completion route', function () {
    $campaign = Campaign::factory()->create();
    $step = stepInCampaign($campaign);
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->post(route('campaigns.steps.complete', [$campaign, $step]))
        ->assertRedirect(route('verification.notice'));

    expect(UserCampaignProgress::count())->toBe(0);
});

test('a verified authorized user can complete a narrative step via the route', function () {
    $campaign = Campaign::factory()->create();
    $step = stepInCampaign($campaign);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('campaigns.steps.complete', [$campaign, $step]))
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(UserCampaignProgress::where('user_id', $user->id)->where('campaign_step_id', $step->id)->whereNotNull('completed_at')->exists())->toBeTrue();
});

test('a direct POST to a future locked step is denied via the route', function () {
    $campaign = Campaign::factory()->create();
    $stage = CampaignStage::factory()->create(['campaign_id' => $campaign->id, 'sort_order' => 1]);
    $gate = CampaignGate::factory()->create(['campaign_stage_id' => $stage->id, 'sort_order' => 1]);
    CampaignStep::factory()->create(['campaign_gate_id' => $gate->id, 'sort_order' => 1]); // غير مكتملة
    $futureStep = CampaignStep::factory()->create(['campaign_gate_id' => $gate->id, 'sort_order' => 4]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('campaigns.steps.complete', [$campaign, $futureStep]))
        ->assertForbidden();

    expect(UserCampaignProgress::count())->toBe(0);
});

test('a puzzle-kind step returns 422 via the narrative completion route', function () {
    $campaign = Campaign::factory()->create();
    $stage = CampaignStage::factory()->create(['campaign_id' => $campaign->id, 'sort_order' => 1]);
    $gate = CampaignGate::factory()->create(['campaign_stage_id' => $stage->id, 'sort_order' => 1]);
    $puzzle = Puzzle::factory()->create();
    $step = CampaignStep::factory()->puzzle()->create(['campaign_gate_id' => $gate->id, 'sort_order' => 1, 'puzzle_id' => $puzzle->id]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('campaigns.steps.complete', [$campaign, $step]))
        ->assertStatus(422);
});

// ===== G. No side effects (Reward/Qualification/PuzzleAttempt untouched) =====

test('completing a narrative step grants no gems', function () {
    $step = unlockedNarrativeStep();
    $user = User::factory()->create();

    $this->narrative->complete($user, $step);

    expect(GemTransaction::where('user_id', $user->id)->count())->toBe(0)
        ->and($user->wallet->fresh()->pending_balance)->toBe(0);
});

test('completing a narrative step creates no PuzzleAttempt', function () {
    $step = unlockedNarrativeStep();
    $user = User::factory()->create();

    $this->narrative->complete($user, $step);

    expect(PuzzleAttempt::where('user_id', $user->id)->count())->toBe(0);
});

test('completing a narrative step creates no qualification record', function () {
    $step = unlockedNarrativeStep();
    $user = User::factory()->create();

    $this->narrative->complete($user, $step);

    expect(App\Models\CampaignGateQualification::count())->toBe(0);
});