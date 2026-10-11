<?php

use App\GameEngine\Support\AttemptContext;
use App\Models\Campaign;
use App\Models\CampaignGate;
use App\Models\CampaignStage;
use App\Models\CampaignStep;
use App\Models\Puzzle;
use App\Models\PuzzleAttempt;
use App\Models\User;
use App\Models\UserCampaignProgress;
use App\Services\CampaignProgressService;
use Illuminate\Support\Facades\DB;

/**
 * E24 - لقطة تقدّم الحملة (CampaignProgressService::preloadFor) يجب أن تعطي **نفس** الحالات بالضبط التي يعطيها الحساب المباشر الأصلي،
 * لكل خطوة/بوابة/مرحلة، لمستخدمَين مختلفَين (والمستخدم الآخر لا يرث شيئًا من لقطة غيره)، وبعدد استعلامات ثابت.
 */
function e24_campaign_fixture(int $stages = 2, int $stepsPerGate = 3): array
{
    $campaign = Campaign::factory()->create(['is_active' => true]);
    $steps = [];

    foreach (range(1, $stages) as $s) {
        $stage = CampaignStage::factory()->create(['campaign_id' => $campaign->id, 'sort_order' => $s]);
        $gate = CampaignGate::factory()->create(['campaign_stage_id' => $stage->id, 'sort_order' => 1]);

        foreach (range(1, $stepsPerGate) as $n) {
            // نمط متنوّع: سرد / أحجية / سرد ... ليغطي المصدرين (UserCampaignProgress وPuzzleAttempt).
            $factory = CampaignStep::factory()->state(['campaign_gate_id' => $gate->id, 'sort_order' => $n]);
            $steps[] = ($n % 2 === 0 ? $factory->puzzle() : $factory)->create();
        }
    }

    return [$campaign->fresh(), $steps];
}

/** @return array<string, mixed> كل الحالات المرئية بمفتاح مستقر. */
function e24_progress_states(CampaignProgressService $svc, User $user, Campaign $campaign): array
{
    $campaign->loadMissing('stages.gates.steps');
    $out = [];

    foreach ($campaign->stages as $stage) {
        $out["stage:{$stage->id}:unlocked"] = $svc->isStageUnlocked($user, $stage);
        $out["stage:{$stage->id}:completed"] = $svc->isStageCompleted($user, $stage);

        foreach ($stage->gates as $gate) {
            $out["gate:{$gate->id}:state"] = $svc->gateState($user, $gate);
            $out["gate:{$gate->id}:completed"] = $svc->isGateCompleted($user, $gate);

            foreach ($gate->steps as $step) {
                $out["step:{$step->id}:state"] = $svc->stepState($user, $step);
                $out["step:{$step->id}:completed"] = $svc->isStepCompleted($user, $step);
                $out["step:{$step->id}:progress"] = $svc->isStepInProgress($user, $step);
                $out["step:{$step->id}:unlocked"] = $svc->isStepUnlocked($user, $step);
            }
        }
    }

    $out['campaign:completed'] = $svc->isCampaignCompleted($user, $campaign);
    $out['campaign:current'] = $svc->currentStepFor($user, $campaign)?->id;

    return $out;
}

test('E24: the snapshot gives exactly the same states as the direct per-call reads, for two different users', function () {
    [$campaign, $steps] = e24_campaign_fixture(2, 3);
    $a = User::factory()->create();
    $b = User::factory()->create();

    // A: أنهى السرد الأول، وبدأ الأحجية الثانية (محاولة خاطئة)، وحلّ الأحجية الرابعة في المرحلة الثانية فقط.
    UserCampaignProgress::factory()->create(['user_id' => $a->id, 'campaign_step_id' => $steps[0]->id, 'started_at' => now(), 'completed_at' => now()]);
    PuzzleAttempt::create(['user_id' => $a->id, 'puzzle_id' => $steps[1]->puzzle_id, 'attempt_number' => 1, 'is_correct' => false, 'context_type' => AttemptContext::TYPE_CAMPAIGN_STEP, 'context_id' => $steps[1]->id]);
    PuzzleAttempt::create(['user_id' => $a->id, 'puzzle_id' => $steps[1]->puzzle_id, 'attempt_number' => 2, 'is_correct' => true, 'context_type' => AttemptContext::TYPE_CAMPAIGN_STEP, 'context_id' => $steps[1]->id]);
    // B: بدأ سردًا ولم يكمله فقط.
    UserCampaignProgress::factory()->create(['user_id' => $b->id, 'campaign_step_id' => $steps[0]->id, 'started_at' => now(), 'completed_at' => null]);

    foreach ([$a, $b] as $user) {
        $direct = e24_progress_states(new CampaignProgressService, $user, Campaign::find($campaign->id));

        // نفس المثيل ونفس الكائنات المحمَّلة: اللقطة تُستهلك عبر الاستدعاءات نفسها.
        $snap = new CampaignProgressService;
        $loaded = Campaign::find($campaign->id);
        $snap->preloadFor($user, $loaded);
        $viaSnapshot = e24_progress_states($snap, $user, $loaded);

        expect($viaSnapshot)->toBe($direct);
    }

    // A تقدّم فعلًا (الاختبار ليس تافهًا): السرد الأول مكتمل والتالي مفتوح.
    $states = e24_progress_states(new CampaignProgressService, $a, Campaign::find($campaign->id));
    expect($states["step:{$steps[0]->id}:completed"])->toBeTrue()
        ->and($states["step:{$steps[1]->id}:completed"])->toBeTrue();
});

test('E24: a snapshot built for user A is never used for user B (falls back to the direct read)', function () {
    [$campaign, $steps] = e24_campaign_fixture(1, 2);
    $a = User::factory()->create();
    $b = User::factory()->create();
    UserCampaignProgress::factory()->create(['user_id' => $a->id, 'campaign_step_id' => $steps[0]->id, 'started_at' => now(), 'completed_at' => now()]);

    $svc = new CampaignProgressService;
    $loaded = Campaign::with('stages.gates.steps')->find($campaign->id);
    $svc->preloadFor($a, $loaded);
    $step = $loaded->stages->first()->gates->first()->steps->first();

    expect($svc->isStepCompleted($a, $step))->toBeTrue()
        ->and($svc->isStepCompleted($b, $step))->toBeFalse(); // B لا يرث إنجاز A
});

test('E24: forgetSnapshot() makes later reads see a write that happened after the preload', function () {
    [$campaign, $steps] = e24_campaign_fixture(1, 2);
    $user = User::factory()->create();
    $svc = new CampaignProgressService;
    $loaded = Campaign::with('stages.gates.steps')->find($campaign->id);
    $svc->preloadFor($user, $loaded);
    $step = $loaded->stages->first()->gates->first()->steps->first();

    expect($svc->isStepCompleted($user, $step))->toBeFalse();

    UserCampaignProgress::factory()->create(['user_id' => $user->id, 'campaign_step_id' => $step->id, 'started_at' => now(), 'completed_at' => now()]);
    expect($svc->isStepCompleted($user, $step))->toBeFalse(); // اللقطة قديمة عمدًا

    $svc->forgetSnapshot();
    expect($svc->isStepCompleted($user, $step))->toBeTrue(); // القراءة المباشرة ترى الكتابة
});

test('E24: with a snapshot the number of progress queries does not depend on the number of steps', function () {
    $count = function (int $stepsPerGate): int {
        [$campaign] = e24_campaign_fixture(2, $stepsPerGate);
        $user = User::factory()->create();
        $svc = new CampaignProgressService;
        $loaded = Campaign::find($campaign->id);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $svc->preloadFor($user, $loaded);
        e24_progress_states($svc, $user, $loaded);
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    $small = $count(2);
    $large = $count(12);

    // الهرم يُحمَّل بـ3 استعلامات eager ثابتة، والتقدّم من اللقطة (5 استعلامات): لا نموّ مع عدد الخطوات (كان استعلامًا أو أكثر لكل خطوة).
    expect($large)->toBeLessThanOrEqual($small + 2);
});
