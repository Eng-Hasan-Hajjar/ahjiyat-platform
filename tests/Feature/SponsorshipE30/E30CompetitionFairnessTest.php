<?php

require_once __DIR__.'/_support.php';
require_once __DIR__.'/../CompetitiveRewards/RewardTestHelpers.php';

use App\Models\CompetitiveEvent;
use App\Models\CompetitiveEventParticipant;
use App\Models\CompetitiveRewardGrant;
use App\Models\CurrencyTransaction;
use App\Models\SponsorshipEventRewardPlan;
use App\Models\SponsorshipRewardBonus;
use App\Services\Competitive\Rewards\CompetitiveRewardDistributionService;
use App\Services\Economy\CurrencyRegistry;
use App\Services\Sponsorship\EventRewardPlanService;
use App\Services\Sponsorship\SponsorshipException;
use App\Services\Sponsorship\SponsorshipProgramService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    e30_seed();
    e17Freeze();
});
afterEach(fn () => Carbon::setTestNow());

/** حدث قادم بجائزة جواهر افتراضية (100) للمركزين 1–2، وبرنامج رعاية مستهدف له بتعزيز (1% لكل 10$، سقف 10%) وتمويل معطى. @return array{0: CompetitiveEvent, 1: \App\Models\SponsorshipProgram, 2: \App\Models\Currency} */
function e30_event(string $funding = '20', int $amount = 100): array
{
    $event = e18Event();
    $currency = app(CurrencyRegistry::class)->defaultEarnedCurrency();
    e18Rule($event, ['min_rank' => 1, 'max_rank' => 2, 'reward_type' => 'currency', 'currency_id' => $currency->id, 'amount' => $amount]);
    $program = e30_program(['target_type' => 'competitive_event', 'target_id' => $event->id]);
    e30_boost($program, '10', 100, 1000);

    if ((float) $funding > 0) {
        e30_confirmed(e30_supporter(), $program, $funding);
    }

    return [$event->refresh(), $program->fresh(), $currency];
}

function e30_lock(CompetitiveEvent $event): SponsorshipEventRewardPlan
{
    return app(EventRewardPlanService::class)->lock($event->fresh(), e30_admin());
}

test('AM7-69: the sponsor reward plan is editable until it is locked, then it is frozen', function () {
    [$event, $program] = e30_event('20');
    $service = app(SponsorshipProgramService::class);

    $service->updateRewardPlan(e30_admin(), $program, ['enabled' => true, 'step' => '10', 'per_step_bps' => 200, 'max_bps' => 1000]);
    expect($program->fresh()->boost_per_step_bps)->toBe(200);

    $plan = e30_lock($event);
    expect($plan->boost_bps)->toBe(400)->and($plan->funding_snapshot_minor)->toBe(2000)->and($plan->sponsorship_program_id)->toBe($program->id);

    expect(fn () => $service->updateRewardPlan(e30_admin(), $program->fresh(), ['enabled' => true, 'step' => '10', 'per_step_bps' => 900, 'max_bps' => 1000]))->toThrow(SponsorshipException::class, 'مقفلة');
    expect($program->fresh()->boost_per_step_bps)->toBe(200);
    $this->assertDatabaseHas('operational_audit_logs', ['action' => 'sponsorship_event_reward_plan_locked']);
});

test('AM7-70: once the competition has started the plan can no longer be locked (and the scheduler command skips it)', function () {
    [$event] = e30_event('20');
    e17Forward(2 * 3600_000); // بدأت

    expect(fn () => e30_lock($event))->toThrow(SponsorshipException::class);
    expect(app(EventRewardPlanService::class)->lockUpcoming())->toBe(0)->and(SponsorshipEventRewardPlan::query()->count())->toBe(0);
});

test('AM7-70b: the scheduled command locks plans of events about to start (and is idempotent)', function () {
    [$event] = e30_event('20');
    e17Forward(30 * 60_000); // نصف ساعة قبل البدء (ضمن نافذة الساعة)

    $this->artisan('sponsorship:lock-event-plans')->assertSuccessful();
    $this->artisan('sponsorship:lock-event-plans')->assertSuccessful();

    expect(SponsorshipEventRewardPlan::query()->where('competitive_event_id', $event->id)->count())->toBe(1);
});

test('AM7-71: a late contribution or refund never changes the rewards of the current event', function () {
    [$event, $program, $currency] = e30_event('20'); // 2%
    e30_lock($event);
    e30_confirmed(e30_supporter(), $program, '500'); // بعد القفل وقبل البدء

    $players = [e16User(), e16User()];
    e18Run($event, [[$players[0], E17_ANSWER, 10_000], [$players[1], E17_ANSWER, 20_000]]);

    expect(e18Pending($players[0], $currency))->toBe(102)->and(e18Pending($players[1], $currency))->toBe(102);
    $grant = e18Grant($event, $players[0]);
    expect($grant->amount)->toBe(100)->and($grant->sponsor_bonus_bps)->toBe(200)->and($grant->sponsor_bonus_amount)->toBe(2)->and($grant->sponsor_program_id)->toBe($program->id);
});

test('AM7-71b: an event with funding but no locked plan gets no boost (fail closed)', function () {
    [$event, , $currency] = e30_event('500');
    $player = e16User();

    e18Run($event, [[$player, E17_ANSWER, 10_000]]);

    expect(e18Pending($player, $currency))->toBe(100)->and(e18Grant($event, $player)->sponsor_bonus_amount)->toBe(0)->and(SponsorshipRewardBonus::query()->count())->toBe(0);
});

test('AM7-72/73: sponsorship never changes the winner, ranks or scores', function () {
    $play = function (CompetitiveEvent $event) {
        $users = [e16User(), e16User(), e16User()];
        e18Run($event, [[$users[0], E17_ANSWER, 30_000], [$users[1], E17_ANSWER, 10_000], [$users[2], E17_ANSWER, 20_000]]);

        return \App\Models\CompetitiveEventResult::query()->where('competitive_event_id', $event->id)->orderBy('final_rank')->get(['user_id', 'final_rank', 'score', 'duration_ms'])
            ->map(fn ($p) => [array_search($p->user_id, array_column($users, 'id'), true), (int) $p->final_rank, (int) $p->score, (int) $p->duration_ms])->all();
    };

    [$sponsored] = e30_event('900');
    e30_lock($sponsored);
    $withSponsor = $play($sponsored);

    Carbon::setTestNow();
    e17Freeze();
    $plain = e18Event();
    e18Rule($plain, ['min_rank' => 1, 'max_rank' => 2, 'reward_type' => 'xp', 'amount' => 10]);
    $without = $play($plain);

    expect($withSponsor)->toBe($without)->and($withSponsor[0][0])->toBe(1);
});

test('AM7-74: eligibility and registration do not depend on sponsorship (an ordinary player joins; no sponsor code in the eligibility path)', function () {
    [$event] = e30_event('900');
    e30_lock($event);
    $ordinary = e16User();
    e17Forward(2 * 3600_000);

    $outcome = e17PlayEvent($ordinary, $event, E17_ANSWER, 5_000);

    expect($outcome->correct ?? true)->toBeTrue()->and(CompetitiveEventParticipant::query()->where('user_id', $ordinary->id)->count())->toBe(1);
    foreach (glob(app_path('Services/Competitive/*.php')) as $file) {
        expect(file_get_contents($file))->not->toContain('Sponsorship');
    }
});

test('AM7-75: reward finalization stays idempotent - re-running, replaying and retrying never duplicates the bonus', function () {
    [$event, , $currency] = e30_event('30'); // 3%
    e30_lock($event);
    $a = e16User();
    $b = e16User();
    e18Run($event, [[$a, E17_ANSWER, 10_000], [$b, E17_ANSWER, 20_000]]);
    $rows = CurrencyTransaction::count();
    $bonusRows = SponsorshipRewardBonus::query()->count();

    $dist = app(CompetitiveRewardDistributionService::class);
    $dist->distributeChunk($event);
    $dist->distributeChunk($event);
    \App\Jobs\DistributeCompetitiveRewardsChunk::dispatchSync($event->id);
    $dist->retryFailed($event, e30_admin());

    expect(CurrencyTransaction::count())->toBe($rows)->and(SponsorshipRewardBonus::query()->count())->toBe($bonusRows)->toBe(2)
        ->and(e18Pending($a, $currency))->toBe(103)->and(e18Pending($b, $currency))->toBe(103)->and(CompetitiveRewardGrant::query()->count())->toBe(2);
    expect(CurrencyTransaction::query()->where('reason', 'like', 'sponsor_bonus:%')->count())->toBe(2);
});
