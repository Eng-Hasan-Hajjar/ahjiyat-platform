<?php

use App\Models\CurrencyTransaction;
use App\Models\UserReferral;
use App\Services\Economy\CurrencyWalletService;
use App\Services\PendingGemReleaseService;
use App\Services\Referrals\ReferralCampaignService;
use App\Services\Referrals\ReferralRewardService;

require_once __DIR__.'/_support.php';

beforeEach(fn () => e33_seed());

test('AF5-26: rewards are disabled by default - the shipped config is false and a qualification pays nothing', function () {
    $shipped = require config_path('referrals.php');
    expect($shipped['rewards']['enabled'])->toBeFalse();

    config(['referrals.rewards.enabled' => false]);
    $campaign = e33_campaign(['rewards_enabled' => true, 'referrer_reward_gems' => 20, 'referred_reward_gems' => 10]);
    $referrer = e33_user();
    [$referred, $ref] = e33_attr($referrer, $campaign);
    $after = e33_act($referred);

    expect($after->status)->toBe(UserReferral::STATUS_QUALIFIED)
        ->and($after->reward_status)->toBe(UserReferral::REWARD_SKIPPED)
        ->and($after->reward_skip_reason)->toBe('rewards_disabled')
        ->and($after->snap_referrer_gems)->toBe(0)
        ->and(e33_ledger())->toHaveCount(0)
        ->and(e33_wallet($referrer)['pending'])->toBe(0);
});

test('AF5-27: a reward needs the global switch, the campaign switch and a positive amount', function () {
    $referrer = e33_user();

    config(['referrals.rewards.enabled' => false]);
    $a = e33_campaign(['rewards_enabled' => true, 'referrer_reward_gems' => 5]);
    [$u1] = e33_attr($referrer, $a);
    expect(e33_act($u1)->reward_skip_reason)->toBe('rewards_disabled');

    e33_rewards_on();
    $b = e33_campaign(['rewards_enabled' => false, 'referrer_reward_gems' => 5]);
    [$u2] = e33_attr($referrer, $b);
    expect(e33_act($u2)->reward_skip_reason)->toBe('rewards_disabled');

    $c = e33_campaign(['rewards_enabled' => true, 'referrer_reward_gems' => 0, 'referred_reward_gems' => 0]);
    [$u3] = e33_attr($referrer, $c);
    expect(e33_act($u3)->reward_skip_reason)->toBe('zero_amount');

    $d = e33_campaign(['rewards_enabled' => true, 'referrer_reward_gems' => 5]);
    [$u4] = e33_attr($referrer, $d);
    expect(e33_act($u4)->status)->toBe(UserReferral::STATUS_REWARDED)
        ->and(e33_ledger())->toHaveCount(1); // الجانب الموجب فقط

    // مبلغ فوق الحد الأقصى يُرفض عند الإدخال
    expect(fn () => e33_campaign(['rewards_enabled' => true, 'referrer_reward_gems' => 100000]))->toThrow(\Illuminate\Validation\ValidationException::class);
});

test('AF5-28: the referrer and the referred amounts are independent', function () {
    foreach ([[20, 10], [0, 10], [15, 0]] as [$gr, $gd]) {
        $referrer = e33_user();
        $campaign = e33_reward_campaign(['referrer_reward_gems' => $gr, 'referred_reward_gems' => $gd]);
        [$referred] = e33_attr($referrer, $campaign);
        e33_act($referred);

        expect(e33_wallet($referrer)['pending'])->toBe($gr)->and(e33_wallet($referred)['pending'])->toBe($gd);
    }
});

test('AF5-29: gems move only through the wallet ledger with the semantic key per side', function () {
    $referrer = e33_user();
    [$referred, $ref] = e33_attr($referrer, e33_reward_campaign());
    e33_act($referred);

    $rows = e33_ledger()->keyBy('idempotency_key');

    expect($rows->keys()->all())->toEqualCanonicalizing(["referral-qualified:{$ref->id}:referrer", "referral-qualified:{$ref->id}:referred"])
        ->and($rows["referral-qualified:{$ref->id}:referrer"]->amount)->toBe(20)
        ->and($rows["referral-qualified:{$ref->id}:referred"]->amount)->toBe(10)
        ->and($rows["referral-qualified:{$ref->id}:referrer"]->type)->toBe(CurrencyTransaction::TYPE_EARN_PENDING)
        ->and($rows["referral-qualified:{$ref->id}:referrer"]->reason)->toBe('referral_reward')
        ->and($rows["referral-qualified:{$ref->id}:referrer"]->reference_type)->toBe((new UserReferral)->getMorphClass())
        ->and((int) $rows["referral-qualified:{$ref->id}:referrer"]->reference_id)->toBe($ref->id)
        ->and($rows["referral-qualified:{$ref->id}:referrer"]->user_id)->toBe($referrer->id)
        ->and($rows["referral-qualified:{$ref->id}:referred"]->user_id)->toBe($referred->id)
        ->and(e33_wallet($referrer)['earned'])->toBe(20);

    // لا مسار آخر في خدمات الإحالات يلمس رصيدًا مباشرة
    $code = '';
    foreach (glob(app_path('Services/Referrals/*.php')) as $file) {
        $code .= file_get_contents($file);
    }
    expect(preg_match('/->(increment|decrement)\(\s*[\'"](pending_balance|available_balance|lifetime_earned)/', $code))->toBe(0);
});

test('AF5-30: delivery is idempotent - repeated or stale deliveries never double-credit', function () {
    $referrer = e33_user();
    [$referred, $ref] = e33_attr($referrer, e33_reward_campaign());
    e33_act($referred);

    $service = app(ReferralRewardService::class);
    for ($i = 0; $i < 5; $i++) {
        $service->deliver($ref->id);
    }
    $this->artisan('referrals:process')->assertSuccessful();

    $fresh = $ref->fresh();
    expect(e33_ledger())->toHaveCount(2)
        ->and(e33_wallet($referrer)['pending'])->toBe(20)
        ->and(e33_wallet($referred)['pending'])->toBe(10)
        ->and($fresh->status)->toBe(UserReferral::STATUS_REWARDED)
        ->and($fresh->reward_status)->toBe(UserReferral::REWARD_GRANTED)
        ->and($fresh->rewarded_at)->not->toBeNull()
        ->and($fresh->referrer_granted_at)->not->toBeNull()
        ->and($fresh->referred_granted_at)->not->toBeNull();

    // الدفتر نفسه يرفض المفتاح المكرر (حارس القاعدة الأخير)
    $dupe = fn () => DB::table('currency_transactions')->insert(['user_id' => $referrer->id, 'currency_id' => e33_ledger()->first()->currency_id, 'amount' => 20, 'type' => 'earn_pending', 'reason' => 'referral_reward', 'idempotency_key' => "referral-qualified:{$ref->id}:referrer", 'created_at' => now(), 'updated_at' => now()]);
    expect($dupe)->toThrow(\Illuminate\Database\QueryException::class);
});

test('AF5-31: the daily cap is per referrer and a capped referral pays neither side', function () {
    $referrer = e33_user();
    $other = e33_user();
    $campaign = e33_reward_campaign(['daily_cap' => 2]);
    $paid = [];

    for ($i = 0; $i < 3; $i++) {
        [$referred, $ref] = e33_attr($referrer, $campaign);
        $paid[] = [e33_act($referred), $referred];
    }

    expect($paid[0][0]->status)->toBe(UserReferral::STATUS_REWARDED)
        ->and($paid[1][0]->status)->toBe(UserReferral::STATUS_REWARDED)
        ->and($paid[2][0]->status)->toBe(UserReferral::STATUS_QUALIFIED)
        ->and($paid[2][0]->reward_skip_reason)->toBe('cap_daily')
        ->and(e33_wallet($paid[2][1])['pending'])->toBe(0)
        ->and(e33_wallet($referrer)['pending'])->toBe(40)
        ->and(e33_ledger())->toHaveCount(4);

    // محيل آخر غير متأثر
    [$x] = e33_attr($other, $campaign);
    expect(e33_act($x)->status)->toBe(UserReferral::STATUS_REWARDED);
});

test('AF5-32: the monthly and lifetime caps also stop rewards', function () {
    $campaign = e33_reward_campaign(['daily_cap' => 10, 'monthly_cap' => 2]);
    $r1 = e33_user();
    $reasons = [];
    for ($i = 0; $i < 3; $i++) {
        [$u] = e33_attr($r1, $campaign);
        $reasons[] = e33_act($u)->reward_skip_reason;
    }
    expect($reasons)->toBe([null, null, 'cap_monthly']);

    $life = e33_reward_campaign(['daily_cap' => 10, 'monthly_cap' => 10, 'lifetime_cap' => 1]);
    $r2 = e33_user();
    [$a] = e33_attr($r2, $life);
    [$b] = e33_attr($r2, $life);
    expect(e33_act($a)->reward_skip_reason)->toBeNull()->and(e33_act($b)->reward_skip_reason)->toBe('cap_lifetime');

    // السقف المطلق في الإعداد لا يُتجاوز مهما أدخل المشرف
    expect(fn () => e33_campaign(['daily_cap' => 999999]))->toThrow(\Illuminate\Validation\ValidationException::class);
});

test('AF5-33: the reward terms are a snapshot - editing or ending the campaign later never rewrites history', function () {
    $referrer = e33_user();
    $admin = e33_admin();
    $campaign = e33_reward_campaign();
    [$u1, $r1] = e33_attr($referrer, $campaign);
    e33_act($u1);

    app(ReferralCampaignService::class)->update($admin, $campaign->fresh(), ['referrer_reward_gems' => 99, 'referred_reward_gems' => 77]);
    [$u2, $r2] = e33_attr($referrer, $campaign->fresh());
    e33_act($u2);
    app(ReferralCampaignService::class)->end($admin, $campaign->fresh());

    $a = $r1->fresh();
    $b = $r2->fresh();

    expect([$a->snap_referrer_gems, $a->snap_referred_gems])->toBe([20, 10])
        ->and([$b->snap_referrer_gems, $b->snap_referred_gems])->toBe([99, 77])
        ->and($a->snap_campaign_id)->toBe($campaign->id)
        ->and(e33_ledger()->where('idempotency_key', "referral-qualified:{$r1->id}:referrer")->first()->amount)->toBe(20)
        ->and(e33_ledger()->where('idempotency_key', "referral-qualified:{$r2->id}:referrer")->first()->amount)->toBe(99);
});

test('AF5-34: referral gems reuse the pending hold and become available only after the usual hold period', function () {
    $referrer = e33_user();
    [$referred] = e33_attr($referrer, e33_reward_campaign());
    e33_act($referred);

    expect(e33_wallet($referrer))->toBe(['pending' => 20, 'available' => 0, 'earned' => 20]);

    $days = (int) config('gems.pending_hold_days');
    app(PendingGemReleaseService::class)->run(now()->addDays($days - 1));
    expect(e33_wallet($referrer)['available'])->toBe(0);

    app(PendingGemReleaseService::class)->run(now()->addDays($days + 1));
    expect(e33_wallet($referrer))->toBe(['pending' => 0, 'available' => 20, 'earned' => 20]);
});

test('AF5-35: no client request can grant gems', function () {
    $referrer = e33_user();
    $code = e33_code($referrer);
    $player = e33_user();
    $before = [e33_wallet($referrer), e33_wallet($player)];

    $this->post(route('referrals.share'), ['kind' => 'platform', 'target' => 'copy', 'gems' => 999, 'amount' => 999, 'reward' => 999])->assertSessionHasNoErrors();
    $this->actingAs($player)->post(route('referrals.share'), ['kind' => 'platform', 'target' => 'copy', 'gems' => 999, 'amount' => 999, 'with_ref' => true])->assertNoContent();
    $this->actingAs($player)->post(route('referrals.rotate'), ['gems' => 999]);
    $this->actingAs($player)->get(route('referrals.index', ['gems' => 999, 'amount' => 999]))->assertOk();
    auth()->logout();
    $this->get(route('referrals.landing', ['code' => $code, 'gems' => 500, 'amount' => 500]));
    $this->get(route('puzzles.index', ['ref' => $code, 'gems' => 100]));

    expect(e33_wallet($referrer))->toBe($before[0])->and(e33_wallet($player))->toBe($before[1])->and(e33_ledger())->toHaveCount(0)
        ->and(CurrencyTransaction::query()->count())->toBe(0);

    // لا متحكم إحالات يلمس المحفظة أصلًا
    foreach (glob(app_path('Http/Controllers/Referrals/*.php')) as $file) {
        expect(file_get_contents($file))->not->toContain('Wallet')->not->toContain('creditPending');
    }
});

test('AF5-36: a paused or ended campaign pays nothing, and one failing side does not block the other - the retry delivers it once', function () {
    $referrer = e33_user();
    $admin = e33_admin();
    $campaign = e33_reward_campaign();
    [$u1] = e33_attr($referrer, $campaign);
    app(ReferralCampaignService::class)->pause($admin, $campaign->fresh());
    $paused = e33_act($u1);
    expect($paused->status)->toBe(UserReferral::STATUS_QUALIFIED)->and($paused->reward_skip_reason)->toBe('campaign_inactive')->and(e33_ledger())->toHaveCount(0);

    $campaign2 = e33_reward_campaign();
    [$u2, $r2] = e33_attr($referrer, $campaign2);

    $real = app(CurrencyWalletService::class);
    $mock = Mockery::mock(CurrencyWalletService::class)->makePartial();
    $mock->shouldReceive('creditPending')->with(Mockery::on(fn ($u) => $u->id === $u2->id), Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any())->andThrow(new RuntimeException('wallet down'));
    $mock->shouldReceive('creditPending')->passthru();
    $this->app->instance(CurrencyWalletService::class, $mock);

    $partial = e33_act($u2);
    expect($partial->status)->toBe(UserReferral::STATUS_QUALIFIED)
        ->and($partial->reward_status)->toBe(UserReferral::REWARD_PENDING)
        ->and($partial->referrer_granted_at)->not->toBeNull()
        ->and($partial->referred_granted_at)->toBeNull()
        ->and(e33_wallet($referrer)['pending'])->toBe(20);

    $this->app->instance(CurrencyWalletService::class, $real);
    $this->artisan('referrals:process')->assertSuccessful();
    $this->artisan('referrals:process')->assertSuccessful();

    expect($r2->fresh()->status)->toBe(UserReferral::STATUS_REWARDED)
        ->and(e33_wallet($u2)['pending'])->toBe(10)
        ->and(e33_wallet($referrer)['pending'])->toBe(20)
        ->and(e33_ledger())->toHaveCount(2);
});

test('AF5-37: referrals change neither gameplay rewards nor ranking - ranking uses qualified referrals only', function () {
    $referrer = e33_user();
    $campaign = e33_reward_campaign();
    [$referred, $ref] = e33_attr($referrer, $campaign);
    $control = e33_user();

    $p1 = \App\Models\Puzzle::factory()->create(['answer_raw' => 'صح', 'gem_reward' => 12]);
    $withReferral = e33_solve($referred, $p1);
    $withoutReferral = e33_solve($control, $p1);

    expect($withReferral['gems_awarded'])->toBe($withoutReferral['gems_awarded'])->and($withReferral['correct'])->toBeTrue()
        ->and(DB::table('xp_transactions')->where('user_id', $referred->id)->sum('amount'))->toBe(DB::table('xp_transactions')->where('user_id', $control->id)->sum('amount'));

    // لا شيء في خدمات الإحالات يكتب XP أو ترتيبًا أو درجات، ولا يرتبط بالرعاية
    $code = '';
    foreach (glob(app_path('Services/Referrals/*.php')) as $file) {
        $code .= file_get_contents($file);
    }
    expect(preg_match('/XpService|grantXp|LeaderboardService|Sponsorship|SupporterTier/i', $code))->toBe(0);
});
