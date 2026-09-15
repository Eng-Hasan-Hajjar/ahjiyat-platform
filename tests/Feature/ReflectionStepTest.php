<?php

use App\Models\Campaign;
use App\Models\CampaignGate;
use App\Models\CampaignStage;
use App\Models\CampaignStep;
use App\Models\User;
use App\Models\UserCampaignProgress;
use App\Services\CampaignProgressService;
use App\Services\CampaignReflectionService;

function reflectionStep(array $contentOverrides = []): CampaignStep
{
    $campaign = Campaign::factory()->create(['is_active' => true]);
    $stage = CampaignStage::factory()->create(['campaign_id' => $campaign->id, 'sort_order' => 1]);
    $gate = CampaignGate::factory()->create(['campaign_stage_id' => $stage->id, 'sort_order' => 1]);

    return CampaignStep::factory()->create([
        'campaign_gate_id' => $gate->id,
        'sort_order' => 1,
        'kind' => CampaignStep::KIND_REFLECTION,
        'content' => array_merge(['prompt' => 'لماذا؟', 'min_chars' => 10, 'max_chars' => 100], $contentOverrides),
    ]);
}

test('a valid reflection submission marks the step completed with the response stored', function () {
    $step = reflectionStep();
    $user = User::factory()->create();

    app(CampaignReflectionService::class)->submit($user, $step, 'هذه إجابة كافية الطول لاجتياز الحد الأدنى.');

    $progress = UserCampaignProgress::where('user_id', $user->id)->where('campaign_step_id', $step->id)->first();

    expect($progress->completed_at)->not->toBeNull()
        ->and($progress->response_payload['text'])->toBe('هذه إجابة كافية الطول لاجتياز الحد الأدنى.')
        ->and(app(CampaignProgressService::class)->isStepCompleted($user, $step))->toBeTrue();
});

test('a response shorter than min_chars is rejected', function () {
    $step = reflectionStep(['min_chars' => 20]);
    $user = User::factory()->create();

    expect(fn () => app(CampaignReflectionService::class)->submit($user, $step, 'قصيرة'))
        ->toThrow(RuntimeException::class);

    expect(UserCampaignProgress::count())->toBe(0);
});

test('a response longer than max_chars is rejected', function () {
    $step = reflectionStep(['max_chars' => 10]);
    $user = User::factory()->create();

    expect(fn () => app(CampaignReflectionService::class)->submit($user, $step, str_repeat('أ', 50)))
        ->toThrow(RuntimeException::class);
});

test('HTML/script tags are stripped before storage', function () {
    $step = reflectionStep();
    $user = User::factory()->create();

    app(CampaignReflectionService::class)->submit(
        $user, $step, '<script>alert(1)</script> نص عادي طويل بما يكفي لتجاوز الحد الأدنى.'
    );

    $progress = UserCampaignProgress::where('user_id', $user->id)->where('campaign_step_id', $step->id)->first();

    expect($progress->response_payload['text'])->not->toContain('<script>')
        ->and($progress->response_payload['text'])->not->toContain('</script>');
});

test('a completed reflection step cannot be resubmitted', function () {
    $step = reflectionStep();
    $user = User::factory()->create();

    app(CampaignReflectionService::class)->submit($user, $step, 'الإجابة الأولى وهي كافية الطول تمامًا.');

    expect(fn () => app(CampaignReflectionService::class)->submit($user, $step, 'محاولة إجابة ثانية مختلفة تمامًا.'))
        ->toThrow(RuntimeException::class);

    $progress = UserCampaignProgress::where('user_id', $user->id)->where('campaign_step_id', $step->id)->first();
    expect($progress->response_payload['text'])->toBe('الإجابة الأولى وهي كافية الطول تمامًا.');
});

test('a reflection step creates no PuzzleAttempt row at all', function () {
    $step = reflectionStep();
    $user = User::factory()->create();

    app(CampaignReflectionService::class)->submit($user, $step, 'إجابة كافية الطول للاختبار فعليًا.');

    expect(App\Models\PuzzleAttempt::count())->toBe(0);
});

test('a locked reflection step rejects submission with an authorization error', function () {
    $campaign = Campaign::factory()->create(['is_active' => true]);
    $stage = CampaignStage::factory()->create(['campaign_id' => $campaign->id, 'sort_order' => 1]);
    $gate = CampaignGate::factory()->create(['campaign_stage_id' => $stage->id, 'sort_order' => 1]);
    CampaignStep::factory()->create(['campaign_gate_id' => $gate->id, 'sort_order' => 1]);
    $locked = CampaignStep::factory()->create([
        'campaign_gate_id' => $gate->id, 'sort_order' => 2,
        'kind' => CampaignStep::KIND_REFLECTION, 'content' => ['prompt' => 'س', 'min_chars' => 5, 'max_chars' => 100],
    ]);
    $user = User::factory()->create();

    expect(fn () => app(CampaignReflectionService::class)->submit($user, $locked, 'محاولة إجابة رغم القفل.'))
        ->toThrow(Illuminate\Auth\Access\AuthorizationException::class);
});

test('submitting a reflection via the HTTP route advances progression to the next step', function () {
    $campaign = Campaign::factory()->create(['is_active' => true]);
    $stage = CampaignStage::factory()->create(['campaign_id' => $campaign->id, 'sort_order' => 1]);
    $gate = CampaignGate::factory()->create(['campaign_stage_id' => $stage->id, 'sort_order' => 1]);
    $step = CampaignStep::factory()->create([
        'campaign_gate_id' => $gate->id, 'sort_order' => 1,
        'kind' => CampaignStep::KIND_REFLECTION, 'content' => ['prompt' => 'س', 'min_chars' => 5, 'max_chars' => 200],
    ]);
    $second = CampaignStep::factory()->create(['campaign_gate_id' => $gate->id, 'sort_order' => 2]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('campaigns.steps.reflect', [$campaign, $step]), ['response' => 'إجابة كافية الطول تماماً للاختبار.'])
        ->assertRedirect(route('campaigns.steps.show', [$campaign, $second]));
});

test('a guest cannot submit a reflection', function () {
    $step = reflectionStep();

    $this->post(route('campaigns.steps.reflect', [$step->gate->stage->campaign, $step]), ['response' => 'محاولة ضيف'])
        ->assertRedirect(route('login'));
});

test('reflection completion can trigger gate qualification, same as narrative and puzzle', function () {
    $stage = CampaignStage::factory()->create(['sort_order' => 1]);
    $gate = CampaignGate::factory()->create([
        'campaign_stage_id' => $stage->id, 'sort_order' => 1,
        'qualification_rule' => 'first_n', 'qualification_config' => ['limit' => 1],
    ]);
    $step = CampaignStep::factory()->create([
        'campaign_gate_id' => $gate->id, 'sort_order' => 1,
        'kind' => CampaignStep::KIND_REFLECTION, 'content' => ['prompt' => 'س', 'min_chars' => 5, 'max_chars' => 200],
    ]);
    $user = User::factory()->create();

    app(CampaignReflectionService::class)->submit($user, $step, 'إجابة كافية لتجاوز الحد الأدنى المطلوب.');

    expect(app(App\Services\QualificationService::class)->isUserQualified($user, $gate->fresh()))->toBeTrue();
});