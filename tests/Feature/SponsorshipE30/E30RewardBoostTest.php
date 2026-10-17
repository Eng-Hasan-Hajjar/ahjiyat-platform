<?php

use App\Models\CurrencyTransaction;
use App\Models\Puzzle;
use App\Models\SponsorshipRewardBonus;
use App\Models\User;
use App\Services\Economy\CurrencyRegistry;
use App\Services\PuzzleAttemptService;
use App\Services\Sponsorship\SponsorshipException;
use App\Services\Sponsorship\SponsorshipProgramService;
use App\Services\Sponsorship\SponsorshipRewardService;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/_support.php';

beforeEach(function () {
    e30_seed();
    config(['gems.daily_earn_cap' => 100000]); // السقف اليومي الافتراضي (200) يقصّ المكافآت الكبيرة؛ يُختبر صراحة في AM6-66.
});

/** يحلّ أحجية رسمية (جواهر أساسية معطاة) بحساب لاعب جديد ويعيد [النتيجة, اللاعب]. */
function e30_solve(int $reward = 200, ?User $user = null, ?Puzzle $puzzle = null): array
{
    $user ??= User::factory()->create();
    $puzzle ??= Puzzle::factory()->create(['answer_raw' => 'صح', 'gem_reward' => $reward]);

    return [app(PuzzleAttemptService::class)->attempt($user, $puzzle, 'صح'), $user];
}

function e30_pending_gems(User $user): int
{
    return (int) $user->fresh()->wallet->pending_balance;
}

/** برنامج منصة معزَّز: خطوة 10$، 1% لكل خطوة، سقف 10% (إن لم تُعطَ غير ذلك) وممول بمبلغ معيّن. */
function e30_boosted(string $funding = '20', string $step = '10', int $perStep = 100, int $max = 1000)
{
    $program = e30_program();
    e30_boost($program, $step, $perStep, $max);

    if ((float) $funding > 0) {
        e30_confirmed(e30_supporter(), $program, $funding);
    }

    return $program->fresh();
}

test('AM6-50: with no program or no funding the boost is zero and the reward is exactly the base', function () {
    [$result, $user] = e30_solve(200);
    expect($result['gems_awarded'])->toBe(200)->and($result['sponsor_bonus'])->toBe(0)->and(e30_pending_gems($user))->toBe(200);

    e30_boosted('0');
    [$result, $user] = e30_solve(200);
    expect($result['sponsor_bonus'])->toBe(0)->and(e30_pending_gems($user))->toBe(200)->and(SponsorshipRewardBonus::query()->count())->toBe(0);
});

test('AM6-51: funding steps compute the boost (floor(funding/step) x per-step bps) and the bonus is floor(base x bps / 10000)', function () {
    e30_boosted('25'); // خطوتان ⇒ 200 نقطة أساس = 2%

    [$result, $user] = e30_solve(200);

    expect($result['gems_awarded'])->toBe(200)->and($result['sponsor_bonus'])->toBe(4)->and(e30_pending_gems($user))->toBe(204);
});

test('AM6-52: the program max and the global hard max cap the boost', function () {
    e30_boosted('1000', '10', 100, 300); // 100 خطوة لكن السقف 3%

    [$result] = e30_solve(200);
    expect($result['sponsor_bonus'])->toBe(6);

    expect(SponsorshipRewardService::computeBps(1_000_000_00, 1000, 100, 9000))->toBe(5000); // السقف الصلب
    config(['sponsorship.reward.hard_max_bps' => 250]);
    expect(SponsorshipRewardService::computeBps(1_000_000_00, 1000, 100, 9000))->toBe(250);
});

test('AM6-53: the boost uses integer basis points only and rounds the bonus down', function () {
    expect(SponsorshipRewardService::computeBps(2500, 1000, 100, 1000))->toBeInt()->toBe(200)
        ->and(SponsorshipRewardService::bonusFor(15, 100))->toBe(0)
        ->and(SponsorshipRewardService::bonusFor(199, 100))->toBe(1)
        ->and(SponsorshipRewardService::bonusFor(0, 500))->toBe(0)
        ->and(SponsorshipRewardService::computeBps(999, 1000, 100, 1000))->toBe(0);
    expect(\Illuminate\Support\Facades\Schema::getColumnType('sponsorship_programs', 'boost_per_step_bps'))->toContain('int');

    e30_boosted('20'); // 2%
    [$result] = e30_solve(15);
    expect($result['sponsor_bonus'])->toBe(0);
});

test('AM6-54: a pending contribution does not fund the boost', function () {
    $program = e30_boosted('0');
    e30_pending(e30_supporter(), $program, '500');

    [$result] = e30_solve(200);

    expect($result['sponsor_bonus'])->toBe(0);
});

test('AM6-55: a refund reduces the funding for subsequent solves only', function () {
    $program = e30_boosted('0');
    $a = e30_confirmed(e30_supporter(), $program, '30'); // 3%
    [$before] = e30_solve(200);

    e30_refund($a);
    [$after] = e30_solve(200);

    expect($before['sponsor_bonus'])->toBe(6)->and($after['sponsor_bonus'])->toBe(0);
});

test('AM6-56: the browser cannot inject a boost; extra request fields are ignored', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح', 'gem_reward' => 100]);

    $this->actingAs($user)->post(route('puzzles.attempt', $puzzle), ['answer' => 'صح', 'sponsor_bonus' => 9999, 'boost_bps' => 5000, 'bonus' => 500, 'program_id' => 1]);

    expect(e30_pending_gems($user))->toBe(100)->and(SponsorshipRewardBonus::query()->count())->toBe(0);
});

test('AM6-57: admin reward-plan input is validated, requires the reward_plan permission and is audited', function () {
    $program = e30_program();
    $service = app(SponsorshipProgramService::class);

    foreach ([
        ['enabled' => true, 'step' => '0', 'per_step_bps' => 100, 'max_bps' => 500],
        ['enabled' => true, 'step' => '10', 'per_step_bps' => 0, 'max_bps' => 500],
        ['enabled' => true, 'step' => '10', 'per_step_bps' => 100, 'max_bps' => 0],
        ['enabled' => true, 'step' => '10', 'per_step_bps' => 100, 'max_bps' => 999999],
        ['enabled' => true, 'step' => 'abc', 'per_step_bps' => 100, 'max_bps' => 500],
    ] as $plan) {
        expect(fn () => $service->updateRewardPlan(e30_admin(), $program, $plan))->toThrow(SponsorshipException::class);
    }

    expect(fn () => $service->updateRewardPlan(e30_manager(), $program, ['enabled' => true, 'step' => '10', 'per_step_bps' => 100, 'max_bps' => 500]))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect($program->fresh()->reward_boost_enabled)->toBeFalse();

    $ok = $service->updateRewardPlan(e30_admin(), $program, ['enabled' => true, 'step' => '10', 'per_step_bps' => 100, 'max_bps' => 500]);
    expect($ok->reward_boost_enabled)->toBeTrue()->and($ok->funding_step_minor)->toBe(1000)->and($ok->boost_per_step_bps)->toBe(100)->and($ok->max_boost_bps)->toBe(500);
    $this->assertDatabaseHas('operational_audit_logs', ['action' => 'sponsorship_reward_plan_updated']);
});

test('AM6-58: an official eligible reward gets a separate bonus transaction on the wallet ledger', function () {
    e30_boosted('50');
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح', 'gem_reward' => 100]);

    [$result, $user] = e30_solve(100, null, $puzzle);

    $rows = CurrencyTransaction::query()->where('user_id', $user->id)->orderBy('id')->get();
    expect($result['sponsor_bonus'])->toBe(5)->and($rows)->toHaveCount(2)->and($rows[0]->reason)->toStartWith('solved_puzzle:')->and($rows[0]->amount)->toBe(100)
        ->and($rows[1]->reason)->toStartWith('sponsor_bonus:')->and($rows[1]->amount)->toBe(5)->and($rows[1]->type)->toBe('earn_pending');
});

test('AM6-59: a community puzzle gets zero bonus and never touches the economy', function () {
    e30_boosted('500');
    $creator = e28_creator();
    $work = e28_published($creator, 'text');
    $player = e16User();

    app(\App\Services\Creators\CommunityPlayService::class)->attempt($player, $work->fresh(), 'إجابة خاطئة');
    $solved = app(\App\Services\Creators\CommunityPlayService::class)->attempt($player, $work->fresh(), (string) ($work->fresh()->answer_raw ?? 'صح'));

    expect(CurrencyTransaction::query()->where('user_id', $player->id)->count())->toBe(0)->and(SponsorshipRewardBonus::query()->count())->toBe(0);

    // حتى لو مُرِّرت أحجية مجتمع للبوابة مباشرة: صفر.
    $communityPuzzle = Puzzle::factory()->create(['origin' => 'community', 'gem_reward' => 100]);
    $currency = app(CurrencyRegistry::class)->defaultEarnedCurrency();
    expect(app(SponsorshipRewardService::class)->applyToSolve($player, $communityPuzzle, \App\GameEngine\Support\AttemptContext::none(), $currency, 100, 777))->toBe(0);
    expect(fn () => app(PuzzleAttemptService::class)->attempt($player, $communityPuzzle, 'x'))->toThrow(\RuntimeException::class);
});

test('AM6-61/62: a supporter, a main sponsor and an ordinary player receive exactly the same sponsored reward', function () {
    $program = e30_boosted('0');
    $supporterUser = User::factory()->create();
    $profile = e30_supporter(['user_id' => $supporterUser->id]);
    e30_confirmed($profile, $program, '40'); // 4%
    e30_role($program, $profile, 'main_sponsor');
    $ordinary = User::factory()->create();

    [$a] = e30_solve(200, $supporterUser);
    [$b] = e30_solve(200, $ordinary);

    expect($a['sponsor_bonus'])->toBe(8)->and($b['sponsor_bonus'])->toBe(8)->and(e30_pending_gems($supporterUser))->toBe(e30_pending_gems($ordinary))->toBe(208);
});

test('AM6-63: the base reward is unchanged by sponsorship (gems_awarded and the base ledger row)', function () {
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح', 'gem_reward' => 120]);
    [$plain] = e30_solve(120, null, $puzzle);
    e30_boosted('100'); // 10%
    [$boosted, $user] = e30_solve(120, null, $puzzle);

    expect($boosted['gems_awarded'])->toBe($plain['gems_awarded'])->toBe(120)->and($boosted['sponsor_bonus'])->toBe(12)
        ->and(CurrencyTransaction::query()->where('user_id', $user->id)->where('reason', 'like', 'solved_puzzle:%')->value('amount'))->toBe(120);
});

test('AM6-64/65: the bonus is recorded once per attempt with a unique source key; retries never grant twice', function () {
    e30_boosted('100');
    [$result, $user] = e30_solve(100);
    $row = SponsorshipRewardBonus::query()->first();
    $attemptId = (int) str_replace('solve:', '', str_replace('sponsor-bonus:', '', $row->source_key));
    $service = app(SponsorshipRewardService::class);
    $currency = app(CurrencyRegistry::class)->defaultEarnedCurrency();
    $puzzle = Puzzle::query()->latest('id')->first();

    $retry = $service->applyToSolve($user, $puzzle, \App\GameEngine\Support\AttemptContext::none(), $currency, 100, $attemptId);

    expect($result['sponsor_bonus'])->toBe(10)->and($retry)->toBe(0)->and(SponsorshipRewardBonus::query()->count())->toBe(1)
        ->and(CurrencyTransaction::query()->where('reason', 'like', 'sponsor_bonus:%')->count())->toBe(1)->and(e30_pending_gems($user))->toBe(110);
    expect(fn () => DB::table('sponsorship_reward_bonuses')->insert(['source_key' => $row->source_key, 'sponsorship_program_id' => $row->sponsorship_program_id, 'user_id' => $user->id, 'currency_id' => $currency->id,
        'base_amount' => 1, 'boost_bps' => 1, 'calculated_bonus_amount' => 1, 'bonus_amount' => 1, 'final_amount' => 2, 'funding_snapshot_minor' => 1]))->toThrow(\Illuminate\Database\QueryException::class);
});

test('AM6-66: the daily earn cap is preserved (the bonus is clipped to the remaining allowance, never exceeds it)', function () {
    config(['gems.daily_earn_cap' => 105]);
    e30_boosted('100'); // 10%
    [$result, $user] = e30_solve(100);

    expect($result['gems_awarded'])->toBe(100)->and($result['sponsor_bonus'])->toBe(5)->and(e30_pending_gems($user))->toBe(105);

    [$next] = e30_solve(100, $user);
    expect($next['gems_awarded'])->toBe(0)->and($next['sponsor_bonus'])->toBe(0)->and(e30_pending_gems($user))->toBe(105);
    $row = SponsorshipRewardBonus::query()->first();
    expect($row->calculated_bonus_amount)->toBe(10)->and($row->bonus_amount)->toBe(5);
});

test('AM6-67: funding added later never grants retroactively', function () {
    $program = e30_boosted('0');
    [$first, $user] = e30_solve(200);

    e30_confirmed(e30_supporter(), $program, '100');

    expect($first['sponsor_bonus'])->toBe(0)->and(e30_pending_gems($user))->toBe(200)->and(SponsorshipRewardBonus::query()->count())->toBe(0);
    [$later] = e30_solve(200);
    expect($later['sponsor_bonus'])->toBe(20);
});

test('AM6-68: the historical bonus snapshot is stable after refunds and program changes', function () {
    $program = e30_boosted('0');
    $contribution = e30_confirmed(e30_supporter(), $program, '50'); // 5%
    [$result, $user] = e30_solve(200);
    $snapshot = SponsorshipRewardBonus::query()->first()->only(['boost_bps', 'funding_snapshot_minor', 'bonus_amount', 'base_amount', 'final_amount']);

    e30_refund($contribution);
    app(SponsorshipProgramService::class)->close(e30_admin(), $program->fresh());

    expect(SponsorshipRewardBonus::query()->first()->only(['boost_bps', 'funding_snapshot_minor', 'bonus_amount', 'base_amount', 'final_amount']))->toBe($snapshot)
        ->and($snapshot['boost_bps'])->toBe(500)->and($snapshot['funding_snapshot_minor'])->toBe(5000)->and($snapshot['bonus_amount'])->toBe(10)->and(e30_pending_gems($user))->toBe(210);
    [$after] = e30_solve(200);
    expect($after['sponsor_bonus'])->toBe(0);
});
