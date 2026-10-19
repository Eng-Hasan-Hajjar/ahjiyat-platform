<?php

use App\Filament\Resources\UserReferralResource\Pages\ListUserReferrals;
use App\Models\ReferralCode;
use App\Models\UserReferral;
use App\Services\Referrals\ReferralAnalytics;
use App\Services\Referrals\ReferralCampaignService;
use App\Services\Referrals\ReferralQualificationService;
use App\Services\Referrals\ShareLinkBuilder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

require_once __DIR__.'/_support.php';

/*
| AF12 - الأداء بالأعداد لا بالزمن: عدد الاستعلامات (والكتابات) ثابت لا يكبر مع حجم البيانات، والمسارات الساخنة تُخدَم من الكاش.
| الأعداد هنا حدود مرتفعة قليلًا فوق القياس الفعلي لتبقى مستقرة، وما يهم هو **عدم النمو** (مقارنة حجمين) لا الرقم المطلق.
*/

beforeEach(fn () => e33_seed());

/** @return array{total: int, writes: int, referral: int} */
function e33_q(Closure $fn): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $fn();
    $log = DB::getQueryLog();
    DB::disableQueryLog();

    return [
        'total' => count($log),
        'writes' => count(array_filter($log, fn ($q) => ! str_starts_with(strtolower(ltrim($q['query'])), 'select'))),
        'referral' => count(array_filter($log, fn ($q) => str_contains($q['query'], 'referral'))),
    ];
}

function e33_guest_visit(string $url): array
{
    test()->flushSession();
    app('auth')->forgetGuards();

    return e33_q(fn () => test()->get($url));
}

test('AF12-75: the redirect route costs a bounded handful of queries when cold and none when warm, whatever the amount of data', function () {
    $small = e33_user();
    $codeSmall = e33_code($small);
    Cache::flush();
    $cold = e33_guest_visit(route('referrals.landing', $codeSmall));
    $warm = e33_guest_visit(route('referrals.landing', $codeSmall));

    expect($cold['referral'])->toBeLessThanOrEqual(2)->and($cold['writes'])->toBeLessThanOrEqual(3)->and($warm['referral'])->toBe(0)->and($warm['total'])->toBe(0);

    // بيانات أكبر بكثير ⇒ نفس العدد
    for ($i = 0; $i < 40; $i++) {
        e33_attr($small);
        e33_code(e33_user());
    }
    $big = e33_user();
    $codeBig = e33_code($big);
    Cache::flush();
    $coldBig = e33_guest_visit(route('referrals.landing', $codeBig));

    // (المجموع الكلي يتضمن تتبّع بصمة الجهاز الموجود سابقًا ويتفاوت بين الزيارة الأولى والتالية؛ المقياس هنا استعلامات الإحالة)
    expect($coldBig['referral'])->toBe($cold['referral'])->and($coldBig['total'])->toBeLessThanOrEqual($cold['total']);

    // رمز غير موجود: استعلام واحد ثم كاش سلبي (لا إغراق قاعدة البيانات بتخمين الرموز)
    $first = e33_guest_visit(route('referrals.landing', 'ZZZZZZ'));
    $again = e33_guest_visit(route('referrals.landing', 'ZZZZZZ'));
    expect($first['referral'])->toBe(1)->and($first['writes'])->toBeLessThanOrEqual(2)->and($again['referral'])->toBe(0);
});

test('AF12-76: the dashboard issues a constant number of referral queries and the total does not grow with the number of invited people', function () {
    $measure = function (int $invited) {
        $referrer = e33_user();

        for ($i = 0; $i < $invited; $i++) {
            [$u] = e33_attr($referrer);
            $i % 3 === 0 && e33_act($u);
        }

        e33_code($referrer);
        Cache::flush();
        $this->flushSession();

        return e33_q(fn () => $this->actingAs($referrer)->get(route('referrals.index'))->assertOk());
    };

    $few = $measure(2);
    $many = $measure(30);

    expect($many['referral'])->toBe($few['referral'])->and($few['referral'])->toBeLessThanOrEqual(6)
        ->and($many['total'])->toBeLessThanOrEqual($few['total'])->and($many['total'])->toBeLessThanOrEqual(40)->and($many['writes'])->toBeLessThanOrEqual($few['writes']);
});

test('AF12-77: the gameplay hook costs one indexed read for a non-referred player and nothing for an already settled referral', function () {
    $svc = app(ReferralQualificationService::class);
    $plain = e33_user();

    $q = e33_q(fn () => $svc->onMeaningfulAction($plain));
    expect($q['total'])->toBe(1)->and($q['writes'])->toBe(0);

    $referrer = e33_user();
    [$referred] = e33_attr($referrer);
    e33_act($referred);
    expect(UserReferral::query()->where('referred_user_id', $referred->id)->value('status'))->toBeIn(UserReferral::QUALIFIED_STATUSES);

    $again = e33_q(fn () => $svc->onMeaningfulAction($referred));
    expect($again['total'])->toBeLessThanOrEqual(1)->and($again['writes'])->toBe(0);

    // الحلّ الفعلي لأحجية لاعب غير مُحال لا يزيد سوى هذا الاستعلام الواحد
    $puzzle = \App\Models\Puzzle::factory()->create(['answer_raw' => 'صح']);
    $player = e33_user();
    $withHook = e33_q(fn () => e33_solve($player, $puzzle));
    expect(collect([$withHook])->sum('referral'))->toBe(1);
});

test('AF12-78: analytics and the admin review table use a fixed number of queries, are served from cache when warm, and avoid N+1', function () {
    $analytics = app(ReferralAnalytics::class);
    $all = fn () => e33_q(function () use ($analytics) {
        $analytics->overview(30);
        $analytics->campaigns(30);
        $analytics->topReferrers(30);
    });

    $referrer = e33_user();
    e33_reward_campaign(default: true);

    for ($i = 0; $i < 3; $i++) {
        e33_qualified($referrer);
    }
    $analytics->forget();
    $small = $all();

    for ($i = 0; $i < 40; $i++) {
        $r = e33_user();
        e33_qualified($r);
        e33_attr($r);
    }
    $analytics->forget();
    $large = $all();
    $warm = $all();

    expect($small['total'])->toBeLessThanOrEqual(10)->and($large['total'])->toBe($small['total'])->and($warm['total'])->toBe(0);

    // جدول المراجعة: تحميل مسبق للعلاقات (لا استعلام لكل صف)
    $admin = e33_admin();
    $this->actingAs($admin);
    $rowsQueries = function (int $keep) {
        $ids = UserReferral::query()->orderBy('id')->limit($keep)->pluck('id')->all();
        DB::table('user_referrals')->whereNotIn('id', $ids)->update(['status' => 'disqualified']); // لا حذف: نُبقي الجدول بحجمه ونغيّر حالته فقط

        return e33_q(fn () => Livewire::test(ListUserReferrals::class)->assertSuccessful());
    };

    $fewRows = $rowsQueries(5);
    $manyRows = $rowsQueries(100);

    expect($manyRows['total'])->toBeLessThanOrEqual($fewRows['total'] + 2);
});

test('AF12-79: share links, codes and campaigns are cached so public pages add no queries for guests and at most one cold read for members', function () {
    $svc = app(ReferralCampaignService::class);
    $builder = app(ShareLinkBuilder::class);
    $campaign = e33_campaign(default: true);
    $member = e33_user();
    $code = e33_code($member);

    $guest = e33_q(fn () => $builder->build('platform', null, 'عنوان'));
    expect($guest['total'])->toBe(0);

    Cache::flush();
    $cold = e33_q(fn () => $builder->build('platform', null, 'عنوان', null, $member));
    $warm = e33_q(fn () => $builder->build('platform', null, 'عنوان', null, $member));
    expect($cold['total'])->toBeLessThanOrEqual(1)->and($warm['total'])->toBe(0);

    // المسارات الساخنة (كل زيارة /r و?ref وكل مشاركة موسم) تقرأ من صفوف الكاش الخفيفة: صفر استعلام
    $svc->validSlug($campaign->slug);
    $loop = e33_q(function () use ($svc, $campaign) {
        for ($i = 0; $i < 25; $i++) {
            $svc->validSlug($campaign->slug);
            $svc->validSlug('unknown-slug');
            $svc->slugForSeason(999999);
        }
    });
    expect($loop['total'])->toBe(0);

    // حلّ الحملة الكاملة يحدث مرة واحدة عند التسجيل فقط: قراءة واحدة بالمفتاح الأساسي لا غير
    $resolve = e33_q(fn () => $svc->resolve($campaign->slug));
    expect($resolve['total'])->toBeLessThanOrEqual(1);

    $codes = e33_codes();
    $codes->findActive($code);
    $found = e33_q(function () use ($codes, $code) {
        for ($i = 0; $i < 25; $i++) {
            $codes->findActive($code);
            $codes->findActive('NOPE22');
        }
    });
    expect($found['total'])->toBeLessThanOrEqual(1); // الرمز المجهول يقرأ مرة ثم يُخزَّن سلبيًا

    // صفحة عامة تحمل مكوّن المشاركة: الضيف لا يدفع أي استعلام إضافي مقابل المكوّن
    $puzzle = \App\Models\Puzzle::factory()->create(['is_active' => true]);
    $this->flushSession();
    app('auth')->forgetGuards();
    $page = e33_q(fn () => $this->get(route('puzzles.show', $puzzle))->assertOk());
    expect($page['referral'])->toBe(0);
});

test('AF12-80: the scheduled processor scales linearly per item and the reconcile command is read only with a query count independent of table size', function () {
    $seed = function (int $n) {
        $referrer = e33_user();

        for ($i = 0; $i < $n; $i++) {
            [$u, $ref] = e33_attr($referrer);
            DB::table('user_referrals')->where('id', $ref->id)->update(['action_at' => now()->subHours(3)]); // أول إجراء مسجَّل ينتظر التأهيل
        }
    };

    $seed(10);
    $q10 = e33_q(fn () => $this->artisan('referrals:process')->assertExitCode(0));
    expect(UserReferral::query()->where('status', UserReferral::STATUS_PENDING)->count())->toBe(0);

    $seed(40);
    $q40 = e33_q(fn () => $this->artisan('referrals:process')->assertExitCode(0));
    expect(UserReferral::query()->where('status', UserReferral::STATUS_PENDING)->count())->toBe(0);

    $perItem = ($q40['total'] - $q10['total']) / 30;
    expect($perItem)->toBeLessThanOrEqual(40)->and($q40['total'])->toBeLessThan($q10['total'] * 6);

    // إعادة التشغيل لا تعيد شيئًا (idempotent) وبلا كتابة
    $rerun = e33_q(fn () => $this->artisan('referrals:process')->assertExitCode(0));
    expect($rerun['writes'])->toBe(0);

    // المطابقة: قراءة فقط، وعدد الاستعلامات لا يتبع حجم الجدول على بيانات سليمة
    $small = e33_q(fn () => $this->artisan('referrals:reconcile')->assertExitCode(0));
    for ($i = 0; $i < 50; $i++) {
        e33_qualified(e33_user());
    }
    $large = e33_q(fn () => $this->artisan('referrals:reconcile')->assertExitCode(0));

    expect($small['writes'])->toBe(0)->and($large['writes'])->toBe(0)->and($large['total'])->toBeLessThanOrEqual($small['total'] + 2);
});
