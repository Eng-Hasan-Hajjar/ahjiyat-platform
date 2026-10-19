<?php

use App\Models\ReferralCode;
use App\Models\UserReferral;
use Illuminate\Support\Facades\RateLimiter;

require_once __DIR__.'/_support.php';

beforeEach(fn () => e33_seed());

test('AF2-07: a guest opening /r/{code} gets the intent stored and is redirected internally, and no referral exists yet', function () {
    $referrer = e33_user();
    $code = e33_code($referrer);

    $res = $this->get(route('referrals.landing', $code));

    $res->assertRedirect(route('register'))->assertSessionHas('referral_intent.code', $code)->assertSessionHas('referral_intent.s', 'link');
    expect(UserReferral::query()->count())->toBe(0)
        ->and(ReferralCode::query()->where('code', $code)->value('clicks_count'))->toBe(1);
});

test('AF2-08: an invalid, unknown or rotated code behaves like a valid one for the visitor and stores nothing', function () {
    $referrer = e33_user();
    $old = e33_code($referrer);
    e33_codes()->rotate($referrer);

    foreach ([$old, 'BCDFGH', 'ABC', 'AAAAAA', 'bcdfgh'] as $bad) {
        $this->get(route('referrals.landing', $bad))->assertRedirect(route('register'))->assertSessionMissing('referral_intent');
    }

    $this->get('/r/'.str_repeat('B', 40))->assertNotFound(); // صيغة مستحيلة تُرفض بالمسار نفسه
    expect(ReferralCode::query()->sum('clicks_count'))->toBe(0);
});

test('AF2-09: there is no open redirect - ?to is a closed key and never a URL', function () {
    $code = e33_code(e33_user());
    $host = parse_url(url('/'), PHP_URL_HOST);

    foreach (['https://evil.example/x', '//evil.example', 'javascript:alert(1)', '/\\evil.example', 'register@evil.example', ['x'], 'puzzles/../../x'] as $to) {
        $res = $this->get(route('referrals.landing', ['code' => $code, 'to' => $to]));
        $res->assertRedirect(route('register'));
        expect(parse_url((string) $res->headers->get('Location'), PHP_URL_HOST))->toBe($host);
    }

    $this->get(route('referrals.landing', ['code' => $code, 'to' => 'puzzles']))->assertRedirect(route('puzzles.index'));
    $this->get(route('referrals.landing', ['code' => $code, 'to' => 'home']))->assertRedirect(route('home'));
});

test('AF2-10: an authenticated visitor is never attributed', function () {
    $referrer = e33_user();
    $code = e33_code($referrer);
    $visitor = e33_user();

    $this->actingAs($visitor)->get(route('referrals.landing', $code))->assertRedirect(route('home'))->assertSessionMissing('referral_intent');
    $this->actingAs($visitor)->get(route('puzzles.index', ['ref' => $code]))->assertSessionMissing('referral_intent');

    expect(ReferralCode::query()->where('code', $code)->value('clicks_count'))->toBe(0)
        ->and(UserReferral::query()->count())->toBe(0);
});

test('AF2-11: the first valid referrer wins and a later link cannot replace it - unless the first code is no longer valid', function () {
    $a = e33_user();
    $b = e33_user();
    $codeA = e33_code($a);
    $codeB = e33_code($b);

    $this->get(route('referrals.landing', $codeA));
    $this->get(route('referrals.landing', $codeB))->assertSessionHas('referral_intent.code', $codeA);
    $this->get(route('puzzles.index', ['ref' => $codeB]))->assertSessionHas('referral_intent.code', $codeA);

    // الأول لم يعد صالحًا (دُوِّر) ⇒ الثاني يربح
    e33_codes()->rotate($a);
    $this->get(route('referrals.landing', $codeB))->assertSessionHas('referral_intent.code', $codeB);
});

test('AF2-12: the intent is kept in a first-party encrypted cookie with a clamped 7-30 day lifetime, restored without a session, and ignored once expired', function () {
    $code = e33_code(e33_user());

    foreach ([[1, 7], [14, 14], [400, 30]] as [$configured, $expected]) {
        config(['referrals.attribution.ttl_days' => $configured]);
        Cache::flush();
        $res = $this->flushSession()->get(route('referrals.landing', $code));
        $cookie = $res->getCookie('ahj_ref', false);

        expect($cookie)->not->toBeNull()
            ->and($cookie->isHttpOnly())->toBeTrue()
            ->and(strtolower((string) $cookie->getSameSite()))->toBe('lax')
            ->and(round(($cookie->getExpiresTime() - time()) / 86400))->toBe((float) $expected)
            ->and($cookie->getValue())->not->toContain($code); // مشفّر لا نص صريح
    }

    config(['referrals.attribution.ttl_days' => 14]);
    $intent = json_encode(['code' => $code, 'c' => null, 's' => 'link', 'at' => now()->getTimestamp()]);

    // جلسة جديدة + كوكي فقط ⇒ النية تُستعاد وتُسنِد عند التسجيل
    $this->flushSession()->withCookie('ahj_ref', $intent);
    e33_register();
    expect(UserReferral::query()->count())->toBe(1);

    // منتهية المدة ⇒ تُتجاهل
    $this->flushSession()->withCookie('ahj_ref', json_encode(['code' => $code, 'c' => null, 's' => 'link', 'at' => now()->subDays(15)->getTimestamp()]));
    e33_register();
    expect(UserReferral::query()->count())->toBe(1);
});
