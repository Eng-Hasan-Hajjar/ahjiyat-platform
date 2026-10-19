<?php

use App\Models\OperationalAuditLog;
use App\Models\ReferralCampaign;
use App\Models\Season;
use App\Models\UserReferral;
use App\Services\Referrals\ReferralCampaignService;
use App\Services\Referrals\ReferralException;
use App\Services\Referrals\ShareLinkBuilder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/_support.php';

beforeEach(fn () => e33_seed());

test('AF7-45: creating a campaign is validated and authorised, starts as a draft and is audited', function () {
    $svc = app(ReferralCampaignService::class);
    $admin = e33_admin();
    $ok = ['name' => 'حملة الاختبار', 'slug' => 'test-campaign'];

    foreach ([
        ['name' => ''], ['slug' => 'Bad Slug'], ['slug' => 'UPPER'], ['slug' => str_repeat('a', 65)], ['qualification_rule' => 'free_money'],
        ['starts_at' => '2026-12-10', 'ends_at' => '2026-12-01'], ['season_id' => 999999], ['min_account_age_hours' => 9999], ['referrer_reward_gems' => -1],
    ] as $bad) {
        expect(fn () => $svc->create($admin, $bad + $ok))->toThrow(ValidationException::class);
    }

    $c = $svc->create($admin, $ok);
    expect($c->status)->toBe(ReferralCampaign::STATUS_DRAFT)->and($c->public_id)->toHaveLength(26)->and($c->created_by)->toBe($admin->id)
        ->and(OperationalAuditLog::query()->where('action', 'referral_campaign_created')->count())->toBe(1)
        ->and(fn () => $svc->create($admin, $ok))->toThrow(ValidationException::class); // slug مكرر

    foreach ([e33_user(), e33_moderator()] as $nobody) {
        try {
            $svc->create($nobody, ['name' => 'x', 'slug' => 'x-'.$nobody->id]);
            $this->fail('forbidden expected');
        } catch (ReferralException $e) {
            expect($e->reason)->toBe('forbidden');
        }
    }
});

test('AF7-46: the lifecycle draft, active, paused, active, ended is enforced and the cache follows each change', function () {
    $svc = app(ReferralCampaignService::class);
    $admin = e33_admin();
    $c = e33_campaign(activate: false);

    expect(fn () => $svc->pause($admin, $c))->toThrow(ReferralException::class)
        ->and(fn () => $svc->resume($admin, $c))->toThrow(ReferralException::class)
        ->and($svc->resolve($c->slug))->toBeNull();

    $c = $svc->activate($admin, $c);
    expect($svc->resolve($c->slug)?->id)->toBe($c->id);
    expect(fn () => $svc->activate($admin, $c))->toThrow(ReferralException::class);

    $c = $svc->pause($admin, $c);
    expect($svc->resolve($c->slug))->toBeNull();

    $c = $svc->resume($admin, $c);
    expect($svc->resolve($c->slug)?->id)->toBe($c->id);

    $c = $svc->end($admin, $c);
    expect($c->status)->toBe(ReferralCampaign::STATUS_ENDED)->and($c->ended_at)->not->toBeNull()->and($svc->resolve($c->slug))->toBeNull()
        ->and(fn () => $svc->update($admin, $c, ['name' => 'جديد']))->toThrow(ReferralException::class)
        ->and(fn () => $svc->resume($admin, $c))->toThrow(ReferralException::class);

    expect(OperationalAuditLog::query()->whereIn('action', ['referral_campaign_activated', 'referral_campaign_paused', 'referral_campaign_resumed', 'referral_campaign_ended'])->count())->toBe(4);
});

test('AF7-47: only one campaign is the default, the database guarantees it, and pausing it clears the flag', function () {
    $svc = app(ReferralCampaignService::class);
    $admin = e33_admin();
    $a = e33_campaign(default: true);
    $b = e33_campaign();

    expect($svc->resolve(null)?->id)->toBe($a->id);

    $b = $svc->makeDefault($admin, $b);
    expect($a->fresh()->default_slot)->toBeNull()->and($b->fresh()->default_slot)->toBe(1)->and($svc->resolve(null)?->id)->toBe($b->id)
        ->and(ReferralCampaign::query()->where('default_slot', 1)->count())->toBe(1);

    expect(fn () => DB::table('referral_campaigns')->where('id', $a->id)->update(['default_slot' => 1]))->toThrow(QueryException::class);

    $draft = e33_campaign(activate: false);
    expect(fn () => $svc->makeDefault($admin, $draft))->toThrow(ReferralException::class);

    $svc->pause($admin, $b->fresh());
    expect($b->fresh()->default_slot)->toBeNull()->and($svc->resolve(null))->toBeNull();
});

test('AF7-48: ?c=slug selects the campaign and an invalid, paused or unknown slug never breaks the referral', function () {
    $svc = app(ReferralCampaignService::class);
    $admin = e33_admin();
    $default = e33_campaign(default: true);
    $special = e33_campaign();
    $paused = $svc->pause($admin, e33_campaign());
    $code = e33_code(e33_user());

    $cases = [[$special->slug, $special->id], ['nope', $default->id], [$paused->slug, $default->id], ['BAD SLUG!!', $default->id], [str_repeat('z', 200), $default->id]];

    foreach ($cases as $i => [$slug, $expected]) {
        $this->flushSession();
        $this->app['auth']->forgetGuards(); // التسجيل السابق فتح جلسة؛ كل دورة زائر ضيف جديد
        $this->get(route('referrals.landing', ['code' => $code, 'c' => $slug]))->assertRedirect(route('register'))->assertSessionHas('referral_intent.code', $code);
        e33_register("campaign{$i}@example.test");
        $ref = UserReferral::query()->whereHas('referred', fn ($q) => $q->where('email', "campaign{$i}@example.test"))->first();

        expect($ref)->not->toBeNull()->and($ref->referral_campaign_id)->toBe($expected);
    }

    // نفس المنطق عبر ?ref&c على صفحة عامة
    $this->flushSession();
    $this->app['auth']->forgetGuards();
    $this->get(route('puzzles.index', ['ref' => $code, 'c' => $special->slug]))->assertSessionHas('referral_intent.c', $special->slug)->assertSessionHas('referral_intent.s', 'share_param');
});

test('AF7-49: the campaign window decides whether it is live and the lookup is served from cache', function () {
    $svc = app(ReferralCampaignService::class);
    $future = e33_campaign(['starts_at' => now()->addDay()->toDateTimeString()]);
    $past = e33_campaign(['starts_at' => now()->subDays(3)->toDateTimeString(), 'ends_at' => now()->subDay()->toDateTimeString()]);
    $open = e33_campaign(['starts_at' => now()->subDay()->toDateTimeString(), 'ends_at' => now()->addDay()->toDateTimeString()]);

    expect($svc->resolve($future->slug))->toBeNull()->and($svc->resolve($past->slug))->toBeNull()->and($svc->resolve($open->slug)?->id)->toBe($open->id)
        ->and($future->isLive())->toBeFalse()->and($past->isLive())->toBeFalse()->and($open->isLive())->toBeTrue();

    \Carbon\Carbon::setTestNow(now()->addDays(2)->addMinute());
    expect($svc->resolve($future->slug)?->id)->toBe($future->id)->and($svc->resolve($open->slug))->toBeNull(); // الحدود تُقيَّم بالذاكرة لا بالكاش القديم
    \Carbon\Carbon::setTestNow();

    $svc->resolve($open->slug); // تسخين
    DB::enableQueryLog();
    for ($i = 0; $i < 20; $i++) {
        $svc->validSlug($open->slug);
    }
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();
    expect($queries)->toBe(0);
});

test('AF7-50: a campaign can be attached to a season, share links of that season carry its slug, and deleting the season keeps the campaign', function () {
    $season = Season::factory()->create();
    $campaign = e33_campaign(['season_id' => $season->id]);
    $viewer = e33_user();
    e33_code($viewer);

    $share = app(ShareLinkBuilder::class)->build('season', $season, 'موسم', null, $viewer, $season->id);
    expect($share['url_ref'])->toContain('ref='.e33_codes()->myCode($viewer))->toContain('c='.$campaign->slug)
        ->and($share['url'])->not->toContain('?');

    $other = app(ShareLinkBuilder::class)->build('season', $season, 'موسم', null, $viewer, 999999);
    expect($other['url_ref'])->not->toContain('c=');

    DB::table('seasons')->where('id', $season->id)->delete();
    expect($campaign->fresh())->not->toBeNull()->and($campaign->fresh()->season_id)->toBeNull();
});

test('AF7-51: admin actions are permission gated, policy gated and rate limited per admin', function () {
    $mod = e33_moderator();
    $manager = e33_manager();
    $admin = e33_admin();
    $player = e33_user();
    $c = e33_campaign(activate: false);

    expect(Gate::forUser($player)->allows('viewAny', ReferralCampaign::class))->toBeFalse()
        ->and(Gate::forUser($mod)->allows('viewAny', ReferralCampaign::class))->toBeTrue()
        ->and(Gate::forUser($mod)->allows('create', ReferralCampaign::class))->toBeFalse()
        ->and(Gate::forUser($manager)->allows('create', ReferralCampaign::class))->toBeTrue()
        ->and(Gate::forUser($manager)->allows('delete', $c))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('update', $c))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('viewAny', UserReferral::class))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('create', UserReferral::class))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('delete', UserReferral::class))->toBeFalse();

    config(['referrals.limits.campaign_action_per_minute' => 3]);
    $svc = app(ReferralCampaignService::class);
    $svc->update($manager, $c, ['name' => 'a']);
    $svc->update($manager, $c, ['name' => 'b']);
    $svc->update($manager, $c, ['name' => 'c']);

    try {
        $svc->update($manager, $c, ['name' => 'd']);
        $this->fail('rate limit expected');
    } catch (ReferralException $e) {
        expect($e->reason)->toBe('rate_limited');
    }

    expect($c->fresh()->name)->toBe('c')
        ->and(\Illuminate\Support\Facades\RateLimiter::limiter('referral-campaign-action'))->not->toBeNull();

    // شاشات الإدارة تُرسم فعلًا (قائمة/إنشاء/تعديل) وتعرض الحملة ولا تنهار على استعلاماتها
    foreach (['/admin/referral-campaigns', '/admin/referral-campaigns/create', "/admin/referral-campaigns/{$c->public_id}/edit", '/admin/user-referrals'] as $url) {
        $this->flushSession();
        $this->actingAs($admin)->get($url)->assertOk();
    }

    $this->flushSession();
    $this->actingAs($player)->get('/admin/referral-campaigns')->assertForbidden();
});
