<?php

use App\Filament\Pages\ReferralAnalyticsPage;
use App\Models\PlayerProgression;
use App\Models\ReferralCode;
use App\Models\UserReferral;
use App\Services\Referrals\ReferralAnalytics;
use App\Services\Referrals\ReferralReviewService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

require_once __DIR__.'/_support.php';

beforeEach(fn () => e33_seed());

function e33_analytics(): ReferralAnalytics
{
    return app(ReferralAnalytics::class);
}

/** مزيج معروف: 2 معلّقة، 1 بانتظار المراجعة، 2 مؤهَّلة ومكافَأة، 1 مستبعدة. يعيد المحيل. */
function e33_mixed_funnel(): \App\Models\User
{
    e33_reward_campaign(default: true);
    $referrer = e33_user();

    e33_attr($referrer);
    e33_attr($referrer);

    [$suspect] = e33_attr($referrer);
    e33_share_network($referrer, $suspect);
    e33_act($suspect);

    e33_qualified($referrer);
    e33_qualified($referrer);

    [, $bad] = e33_attr($referrer);
    app(ReferralReviewService::class)->disqualify(e33_admin(), $bad, 'fake_accounts');

    return $referrer;
}

test('AF10-64: the funnel counts every status exactly, conversion is qualified over attributed for the same cohort, and an empty window shows no fake percentage', function () {
    // المجاميع نص SQL ثابت (لا استيفاء في SQL خام - تدقيق E26 W5) ويجب أن يغطي كل حالة معرّفة في النموذج بلا زيادة
    foreach (UserReferral::STATUSES as $status) {
        expect(ReferralAnalytics::STATUS_SUMS)->toContain("when status = '{$status}' then 1 else 0 end) as {$status}");
    }
    expect(substr_count(ReferralAnalytics::STATUS_SUMS, 'sum(case'))->toBe(count(UserReferral::STATUSES));

    expect(e33_analytics()->overview(30))->toMatchArray(['attributed' => 0, 'qualified' => 0, 'conversion_pct' => null, 'reward_cost_gems' => 0]);

    e33_mixed_funnel();
    e33_analytics()->forget();
    $o = e33_analytics()->overview(30);

    expect($o)->toMatchArray([
        'attributed' => 6, 'verified' => 6, 'pending' => 2, 'under_review' => 1, 'qualified' => 2, 'rewarded' => 2, 'disqualified' => 1,
        'conversion_pct' => 33.3, 'reward_cost_gems' => 60, // 2 إحالتين × (20 محيل + 10 مُحال) من لقطات الحملة المسلَّمة فقط
    ]);
    expect($o['pending'] + $o['under_review'] + $o['qualified'] + $o['disqualified'])->toBe($o['attributed']);
});

test('AF10-65: windows are cohorts of attribution date, so late qualification never inflates an older period and the day range is clamped', function () {
    $referrer = e33_user();
    [$old] = e33_attr($referrer);
    DB::table('user_referrals')->where('referred_user_id', $old->id)->update(['created_at' => now()->subDays(40)]);
    [$fresh] = e33_attr($referrer);

    e33_act($old); // تأهّلت اليوم لكن دفعتها عمرها 40 يومًا

    e33_analytics()->forget();
    $o30 = e33_analytics()->overview(30);
    $o90 = e33_analytics()->overview(90);

    expect($o30['attributed'])->toBe(1)->and($o30['qualified'])->toBe(0)->and($o30['conversion_pct'])->toBe(0.0)
        ->and($o90['attributed'])->toBe(2)->and($o90['qualified'])->toBe(1)->and($o90['conversion_pct'])->toBe(50.0)
        ->and(e33_analytics()->overview(0)['days'])->toBe(1)->and(e33_analytics()->overview(99999)['days'])->toBe(365)
        ->and(e33_analytics()->overview(-5)['days'])->toBe(1);

    expect(e33_analytics()->topReferrers(30))->toHaveCount(1)->and(e33_analytics()->topReferrers(30)[0]['qualified'])->toBe(1); // تأهّلت ضمن 30 يومًا
});

test('AF10-66: clicks are approximate, deduplicated per visitor and hour, and never feed conversion; shares appear only as short-lived counters', function () {
    $referrer = e33_user();
    $code = e33_code($referrer);
    [$referred] = e33_attr($referrer);
    e33_act($referred);
    e33_analytics()->forget();
    $conversionBefore = e33_analytics()->overview(30)['conversion_pct'];

    for ($i = 0; $i < 5; $i++) {
        $this->flushSession();
        $this->get(route('referrals.landing', $code))->assertRedirect(route('register'));
    }
    expect(ReferralCode::query()->where('code', $code)->value('clicks_count'))->toBe(1); // نفس الزائر نفس الساعة

    foreach (['198.51.100.7', '198.51.100.8'] as $ip) {
        $this->flushSession();
        $this->withServerVariables(['REMOTE_ADDR' => $ip])->get(route('referrals.landing', $code));
    }
    expect(ReferralCode::query()->where('code', $code)->value('clicks_count'))->toBe(3);

    $this->actingAs($referrer)->postJson(route('referrals.share'), ['kind' => 'puzzle', 'target' => 'copy', 'with_ref' => true])->assertNoContent();

    e33_analytics()->forget();
    $o = e33_analytics()->overview(30);

    expect($o['clicks_approx_lifetime'])->toBe(3)->and($o['conversion_pct'])->toBe($conversionBefore)->and($o['shares_7d']['total'])->toBe(1)
        ->and($o['shares_7d']['by_kind'])->toBe(['puzzle' => 1]);

    // الاكتساب لا يُخزّن IP ولا UA في أي جدول إحالة
    foreach (['referral_codes', 'user_referrals', 'referral_campaigns'] as $table) {
        $cols = \Illuminate\Support\Facades\Schema::getColumnListing($table);
        expect(array_intersect($cols, ['ip', 'ip_address', 'user_agent', 'ua', 'device_hash', 'fingerprint']))->toBe([]);
    }
});

test('AF10-67: top referrers rank by qualified referrals only and gems, clicks, signups, sponsors or XP never buy a place', function () {
    e33_reward_campaign(default: true);
    $steady = e33_user(['name' => 'ثابت']);
    $noisy = e33_user(['name' => 'ضجيج']);
    $disq = e33_user(['name' => 'مستبعد']);

    e33_qualified($steady);
    e33_qualified($steady);
    e33_qualified($steady);

    // «الضجيج»: أكثر تسجيلات ونقرات ومشاركات ورصيد، لكن مؤهَّلة واحدة فقط
    for ($i = 0; $i < 8; $i++) {
        e33_attr($noisy);
    }
    e33_qualified($noisy);
    ReferralCode::query()->where('active_for_user_id', $noisy->id)->update(['clicks_count' => 50000, 'shares_count' => 9000]);
    app(\App\Services\Economy\CurrencyWalletService::class)->creditPending($noisy, app(\App\Services\Economy\CurrencyRegistry::class)->defaultEarnedCurrency(), 100000, 'admin_adjustment', null, 'e33-noisy-wallet');

    // «المستبعد»: إحالتان مؤهّلتان ثم استُبعدت إحداهما
    [, $d1] = e33_qualified($disq);
    e33_qualified($disq);
    app(ReferralReviewService::class)->disqualify(e33_admin(), $d1->fresh(), 'fake_accounts', null, true);

    e33_analytics()->forget();
    $top = e33_analytics()->topReferrers(30);

    // التعادل يُفضّ بمعرّف المحيل (حتمي)، لا بالنقرات ولا بالرصيد ولا بالتسجيلات
    expect(array_column($top, 'name'))->toBe(['ثابت', 'ضجيج', 'مستبعد'])->and(array_column($top, 'qualified'))->toBe([3, 1, 1]);
    expect(array_keys($top[0]))->toBe(['user_id', 'name', 'qualified']); // لا بريد ولا رصيد ولا نقرات

    config(['referrals.analytics.top_referrers' => 2]);
    e33_analytics()->forget();
    expect(e33_analytics()->topReferrers(30))->toHaveCount(2);

    // الإحالة لا تمسّ تقدّم اللاعب: لا XP ولا مستوى للمحيل من تأهيل مُحاليه
    $before = PlayerProgression::query()->where('user_id', $steady->id)->first()?->only(['total_xp', 'level_id']);
    [$extra] = e33_attr($steady);
    e33_act($extra);
    expect(PlayerProgression::query()->where('user_id', $steady->id)->first()?->only(['total_xp', 'level_id']))->toBe($before)
        ->and(DB::table('xp_transactions')->where('user_id', $steady->id)->count())->toBe(0);
});

test('AF10-68: campaign performance is grouped per campaign with cost from granted snapshots, plus a no-campaign bucket', function () {
    $main = e33_reward_campaign(['name' => 'حملة الشتاء'], default: true);
    $noReward = e33_campaign(['name' => 'حملة بلا جواهر']);
    $referrer = e33_user();

    e33_qualified($referrer);                   // الحملة الافتراضية: مكافأة 20+10
    e33_qualified($referrer);
    e33_qualified($referrer, $noReward);        // حملة بلا مكافأة
    e33_attr($referrer, $noReward);             // معلّقة
    app(\App\Services\Referrals\ReferralCampaignService::class)->pause(e33_admin(), $main);
    DB::table('user_referrals')->whereNull('referral_campaign_id')->count(); // لا إحالات بلا حملة بعد
    [$loose] = e33_attr($referrer, null);       // لا حملة افتراضية فاعلة الآن ⇒ بلا حملة
    expect(UserReferral::query()->where('referred_user_id', $loose->id)->value('referral_campaign_id'))->toBeNull();

    e33_analytics()->forget();
    $rows = collect(e33_analytics()->campaigns(30))->keyBy('name');

    expect($rows['حملة الشتاء'])->toMatchArray(['attributed' => 2, 'qualified' => 2, 'rewarded' => 2, 'cost_gems' => 60])
        ->and($rows['حملة بلا جواهر'])->toMatchArray(['attributed' => 2, 'qualified' => 1, 'rewarded' => 0, 'cost_gems' => 0])
        ->and($rows['بلا حملة'])->toMatchArray(['attributed' => 1, 'qualified' => 0, 'campaign_id' => null, 'cost_gems' => 0])
        ->and($rows->count())->toBe(3)
        ->and(collect(e33_analytics()->campaigns(30))->first()['name'])->toBe('حملة الشتاء'); // الأعلى تأهيلًا أولًا
});

test('AF10-69: analytics are cached for a short time, review decisions refresh them at once, and the admin page clamps the period and is read only', function () {
    $referrer = e33_mixed_funnel();
    e33_analytics()->forget();
    expect(e33_analytics()->overview(30)['attributed'])->toBe(6);

    e33_attr($referrer);
    expect(e33_analytics()->overview(30)['attributed'])->toBe(6); // ما زال من الكاش القصير

    $suspect = UserReferral::query()->where('status', UserReferral::STATUS_PENDING_REVIEW)->firstOrFail();
    app(ReferralReviewService::class)->approve(e33_admin(), $suspect);
    $after = e33_analytics()->overview(30);
    expect($after['attributed'])->toBe(7)->and($after['under_review'])->toBe(0)->and($after['qualified'])->toBe(3); // قرار المراجعة يفرّغ الكاش

    Cache::forget('x');
    $writes = DB::table('currency_transactions')->count() + UserReferral::query()->count();
    $this->flushSession();
    $this->actingAs(e33_admin());

    $page = Livewire::test(ReferralAnalyticsPage::class)->assertSuccessful()->set('days', 9999);
    expect($page->instance()->data()['days'])->toBe(30);
    $page->set('days', 7);
    expect($page->instance()->data()['days'])->toBe(7);

    foreach ([7, 30, 90] as $d) {
        expect($this->get('/admin/referral-analytics?days='.$d))->not->toBeNull();
    }

    expect(DB::table('currency_transactions')->count() + UserReferral::query()->count())->toBe($writes); // عرض فقط
});
