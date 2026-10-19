<?php

use App\Models\ReferralCode;
use App\Models\User;
use App\Models\UserReferral;
use App\Services\Referrals\ReferralAttributionService;
use App\Services\Referrals\ReferralIntent;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

require_once __DIR__.'/_support.php';

beforeEach(fn () => e33_seed());

test('AF3-13: registering after a referral link creates exactly one pending referral for the code owner and clears the intent', function () {
    $referrer = e33_user();
    $code = e33_code($referrer);

    $this->get(route('referrals.landing', $code));
    $res = e33_register('friend@example.test');

    $res->assertRedirect(route('verification.notice'))->assertSessionMissing('referral_intent');
    $new = User::query()->where('email', 'friend@example.test')->firstOrFail();
    $ref = UserReferral::query()->where('referred_user_id', $new->id)->firstOrFail();

    expect($ref->referrer_user_id)->toBe($referrer->id)
        ->and($ref->status)->toBe(UserReferral::STATUS_PENDING)
        ->and($ref->qualified_at)->toBeNull()
        ->and($ref->reward_status)->toBe(UserReferral::REWARD_NONE)
        ->and($ref->source)->toBe('link')
        ->and($ref->referral_code_id)->toBe(ReferralCode::query()->where('code', $code)->value('id'))
        ->and(UserReferral::query()->count())->toBe(1);
    $res->assertCookieExpired('ahj_ref');
});

test('AF3-14: no intent means no referral, and a forged referrer field in the request is ignored (the server decides)', function () {
    $referrer = e33_user();
    $code = e33_code($referrer);

    e33_register('plain@example.test');
    e33_register('forged@example.test', ['ref' => $code, 'referral_code' => $code, 'referrer_id' => $referrer->id, 'referrer_user_id' => $referrer->id, 'c' => 'x']);

    expect(UserReferral::query()->count())->toBe(0);
});

test('AF3-15: self-referral and existing accounts are never attributed', function () {
    $user = e33_user();
    $code = e33_code($user);
    $svc = app(ReferralAttributionService::class);

    expect($svc->attribute($user, $code))->toBeNull()
        ->and(UserReferral::query()->count())->toBe(0);

    // حساب قديم لا يُسنَد عبر مسار التسجيل (لم يُنشأ الآن)
    $this->get(route('referrals.landing', $code));
    $old = e33_user();
    $req = Request::create('/register', 'POST');
    $req->setLaravelSession(app('session.store'));
    expect($svc->attributeFromRequest($old, $req))->toBeNull()->and(UserReferral::query()->count())->toBe(0);

    // محيل مجمَّد أو غير موثّق أو محذوف لا يُسنَد إليه
    $frozen = e33_user();
    $frozenCode = e33_code($frozen);
    $frozen->forceFill(['is_frozen' => true])->save();
    $unverified = e33_user();
    $unverifiedCode = e33_code($unverified);
    $unverified->forceFill(['email_verified_at' => null])->save();

    expect($svc->attribute(e33_user(), $frozenCode))->toBeNull()->and($svc->attribute(e33_user(), $unverifiedCode))->toBeNull();
});

test('AF3-16: an account has at most one referrer and it never changes - the database enforces it', function () {
    $a = e33_user();
    $b = e33_user();
    $svc = app(ReferralAttributionService::class);
    $referred = e33_user();

    $first = $svc->attribute($referred, e33_code($a));
    $second = $svc->attribute($referred, e33_code($b));

    expect($second->id)->toBe($first->id)
        ->and($second->referrer_user_id)->toBe($a->id)
        ->and(UserReferral::query()->where('referred_user_id', $referred->id)->count())->toBe(1);

    expect(fn () => DB::table('user_referrals')->insert([
        'public_id' => (string) Str::ulid(), 'referred_user_id' => $referred->id, 'referrer_user_id' => $b->id, 'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('AF3-17: the registration response does not change with a referral intent - no enumeration signal is added (E26)', function () {
    $existing = e33_user(['email' => 'taken@example.test']);
    $code = e33_code(e33_user());

    $plain = e33_register('taken@example.test');
    $plainErrors = session('errors')?->getBag('default')->toArray();

    $this->flushSession();
    $this->get(route('referrals.landing', $code));
    $withIntent = e33_register('taken@example.test');
    $intentErrors = session('errors')?->getBag('default')->toArray();

    expect($withIntent->getStatusCode())->toBe($plain->getStatusCode())
        ->and($intentErrors)->toBe($plainErrors)
        ->and(UserReferral::query()->count())->toBe(0);

    // رمز غير صالح في النية لا يُظهر أي رسالة ولا يمنع التسجيل
    $this->flushSession()->withCookie('ahj_ref', json_encode(['code' => 'BCDFGH', 'c' => null, 's' => 'link', 'at' => now()->getTimestamp()]));
    e33_register('fresh@example.test')->assertRedirect(route('verification.notice'))->assertSessionHasNoErrors();
    expect(User::query()->where('email', 'fresh@example.test')->exists())->toBeTrue();
});

test('AF3-18: a failure while attributing never blocks the registration', function () {
    $code = e33_code(e33_user());
    $this->get(route('referrals.landing', $code));

    $this->app->bind(ReferralIntent::class, fn () => new class(app(\App\Services\Referrals\ReferralCodeService::class), app(\App\Services\Referrals\ReferralCampaignService::class), app(\App\Services\Referrals\ReferralClickRecorder::class)) extends ReferralIntent {
        public function current(Request $request): ?array
        {
            throw new RuntimeException('boom');
        }
    });

    e33_register('survivor@example.test')->assertRedirect(route('verification.notice'));

    expect(User::query()->where('email', 'survivor@example.test')->exists())->toBeTrue()
        ->and(UserReferral::query()->count())->toBe(0);
});
