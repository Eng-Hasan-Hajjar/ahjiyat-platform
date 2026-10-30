<?php

use App\Models\SeasonSupporterCard;
use App\Models\SeasonSupporterPackage;
use App\Models\SeasonSupporterStat;
use App\Models\SponsorshipPayment;
use App\Models\SponsorshipProgram;
use App\Models\SupporterProfile;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/_support.php';

/*
| E44 / بطاقات الداعم الموسمية فوق دفع E30/E32 الحقيقي: الخادم سلطة السعر، رفع الإثبات لا يمنح شيئًا، التحقق المالي وحده يفعّل البطاقة ويمنح نقاطها،
| المستوى = أعلى بطاقة نشطة، الاسترداد يعكس نقاط تلك البطاقة مرة واحدة، تعطيل المفتاح يوقف الطلبات الجديدة فقط، والمطابقة تكشف أي انحراف.
*/

beforeEach(fn () => e44_seed());
afterEach(fn () => Carbon::setTestNow());

// ───────────────────────────── الباقات ─────────────────────────────

test('E44-SC-01: seeding creates the three default packages with the server-side prices, points and name styles — idempotently and only for a program targeting this season', function () {
    $admin = e44_admin();
    $season = e44_season();
    e44_settings()->setSupportersEnabled($season, true, $admin);
    $program = e30_program(['target_type' => 'season', 'target_id' => $season->id], true, $admin);

    expect(e44_reason(fn () => e44_packages_svc()->seedDefaults($season, $program, e44_staff(['seasonal-recognition.view']))))->toBe('forbidden')
        ->and(e44_packages_svc()->seedDefaults($season, $program, $admin))->toBe(3)
        ->and(e44_packages_svc()->seedDefaults($season, $program, $admin))->toBe(0);

    $packages = SeasonSupporterPackage::query()->where('season_id', $season->id)->orderBy('sort_order')->get()->keyBy('tier_key');

    expect($packages->keys()->all())->toBe(['silver', 'gold', 'diamond'])
        ->and($packages->map(fn ($p) => (int) $p->price_minor)->all())->toBe(['silver' => 2500, 'gold' => 10000, 'diamond' => 25000])
        ->and($packages->pluck('currency')->unique()->values()->all())->toBe(['USD'])
        ->and($packages->map(fn ($p) => (int) $p->support_points)->all())->toBe(['silver' => 250, 'gold' => 1000, 'diamond' => 2500])
        ->and($packages->map(fn ($p) => $p->name_style_key)->all())->toBe(['silver' => 'silver', 'gold' => 'gold', 'diamond' => 'diamond'])
        ->and($packages->map(fn ($p) => (bool) $p->social_mention_eligible)->all())->toBe(['silver' => false, 'gold' => true, 'diamond' => true])
        ->and($packages->every(fn ($p) => (int) $p->sponsorship_program_id === (int) $program->id && $p->is_active))->toBeTrue()
        ->and(e44_audit('seasonal_supporter_package_created'))->toBe(3);

    $platform = e30_program([], true, $admin);
    $other = e44_season();
    $closed = e30_program(['target_type' => 'season', 'target_id' => $other->id], true, $admin);
    DB::table('sponsorship_programs')->where('id', $closed->id)->update(['status' => SponsorshipProgram::STATUS_CLOSED]);

    expect(e44_reason(fn () => e44_packages_svc()->seedDefaults($season, $platform, $admin)))->toBe('program_target_mismatch')
        ->and(e44_reason(fn () => e44_packages_svc()->seedDefaults($other, $closed->fresh(), $admin)))->toBe('program_closed');
});

test('E44-SC-02: package edits are permissioned, validated and audited; the price and currency lock once a card exists; the tier never changes and nothing is deleted', function () {
    $w = e44_supporters_world();
    $silver = $w['packages']['silver'];

    expect(e44_reason(fn () => e44_packages_svc()->update($silver, ['name' => 'x'], e44_staff(['seasonal-recognition.view']))))->toBe('forbidden')
        ->and(e44_reason(fn () => e44_packages_svc()->update($silver, ['name' => '   '], $w['admin'])))->toBe('invalid_name')
        ->and(e44_reason(fn () => e44_packages_svc()->update($silver, ['name' => str_repeat('م', 61)], $w['admin'])))->toBe('invalid_name')
        ->and(e44_reason(fn () => e44_packages_svc()->update($silver, ['support_points' => -1], $w['admin'])))->toBe('invalid_points')
        ->and(e44_reason(fn () => e44_packages_svc()->update($silver, ['support_points' => 'abc'], $w['admin'])))->toBe('invalid_points')
        ->and(e44_reason(fn () => e44_packages_svc()->update($silver, ['support_points' => 1000001], $w['admin'])))->toBe('invalid_points')
        ->and(e44_reason(fn () => e44_packages_svc()->update($silver, ['price_minor' => 0], $w['admin'])))->toBe('invalid_price')
        ->and(e44_reason(fn () => e44_packages_svc()->update($silver, ['currency' => 'dollars'], $w['admin'])))->toBe('invalid_currency');

    $updated = e44_packages_svc()->update($silver, ['name' => '<b>فضي</b> جديد', 'support_points' => 300, 'price_minor' => 3000, 'currency' => 'usd'], $w['admin']);

    expect($updated->name)->toBe('فضي جديد')
        ->and((int) $updated->support_points)->toBe(300)
        ->and((int) $updated->price_minor)->toBe(3000)
        ->and($updated->currency)->toBe('USD')
        ->and(e44_audit('seasonal_supporter_package_updated'))->toBe(1)
        ->and(e44_packages_svc()->update($silver->fresh(), [], $w['admin'])->name)->toBe('فضي جديد')
        ->and(e44_audit('seasonal_supporter_package_updated'))->toBe(1);

    // بعد وجود بطاقة: السعر والعملة مقفلان؛ النقاط والاسم والتفعيل يتعدّلان للمستقبل فقط.
    $user = e16User();
    $card = e44_request_card($user, $silver->fresh());

    expect(e44_reason(fn () => e44_packages_svc()->update($silver->fresh(), ['price_minor' => 4000], $w['admin'])))->toBe('price_locked')
        ->and(e44_reason(fn () => e44_packages_svc()->update($silver->fresh(), ['currency' => 'EUR'], $w['admin'])))->toBe('price_locked')
        ->and((int) e44_packages_svc()->update($silver->fresh(), ['price_minor' => 3000, 'support_points' => 999], $w['admin'])->support_points)->toBe(999)
        ->and((int) $card->fresh()->support_points)->toBe(300) // لقطة الطلب لا تتغير
        ->and((int) $card->fresh()->price_minor)->toBe(3000);

    expect(fn () => $silver->fresh()->forceFill(['tier_key' => 'gold'])->save())->toThrow(LogicException::class)
        ->and(fn () => $silver->fresh()->forceFill(['season_id' => 99999])->save())->toThrow(LogicException::class)
        ->and(fn () => $silver->fresh()->delete())->toThrow(LogicException::class)
        ->and(fn () => $silver->fresh()->forceFill(['tier_key' => 'platinum'])->save())->toThrow(InvalidArgumentException::class)
        ->and(fn () => $silver->fresh()->forceFill(['name_style_key' => 'neon-pink'])->save())->toThrow(InvalidArgumentException::class)
        ->and(fn () => $silver->fresh()->forceFill(['price_minor' => 0])->save())->toThrow(InvalidArgumentException::class)
        ->and(fn () => $silver->fresh()->forceFill(['currency' => 'usd'])->save())->toThrow(InvalidArgumentException::class);
});

// ───────────────────────────── الطلب والدفع ─────────────────────────────

test('E44-SC-03: requesting a card creates a real E32 payment at the SERVER price and a pending card with a frozen snapshot — and grants nothing', function () {
    $w = e44_supporters_world();
    $user = e16User();
    $gold = $w['packages']['gold'];

    $card = e44_request_card($user, $gold);
    $payment = $card->payment()->firstOrFail();

    expect($card->status)->toBe(SeasonSupporterCard::STATUS_PENDING)
        ->and($card->tier_key)->toBe('gold')
        ->and((int) $card->price_minor)->toBe(10000)
        ->and($card->currency)->toBe('USD')
        ->and((int) $card->support_points)->toBe(1000)
        ->and($card->name_style_key)->toBe('gold')
        ->and($card->social_mention_eligible)->toBeTrue()
        ->and($card->is_anonymous)->toBeFalse()
        ->and((int) $card->season_id)->toBe((int) $w['season']->id)
        ->and((int) $payment->amount_minor)->toBe(10000)
        ->and($payment->currency)->toBe('USD')
        ->and((int) $payment->user_id)->toBe((int) $user->id)
        ->and((int) $payment->sponsorship_program_id)->toBe((int) $w['program']->id)
        ->and($payment->status)->toBe(SponsorshipPayment::STATUS_AWAITING)
        ->and(e44_entries($w['season']))->toHaveCount(0)
        ->and(e44_stat($w['season'], $user))->toBeNull()
        ->and(e44_audit('seasonal_supporter_card_requested'))->toBe(1);

    // لقطة البطاقة ثابتة: تعديل أي حقل لقطة مرفوض.
    foreach (['price_minor' => 1, 'currency' => 'EUR', 'support_points' => 5, 'tier_key' => 'silver', 'name_style_key' => 'diamond', 'user_id' => 99999, 'is_anonymous' => true] as $field => $value) {
        expect(fn () => $card->fresh()->forceFill([$field => $value])->save())->toThrow(LogicException::class);
    }
});

test('E44-SC-04: a repeated request key returns the same card and payment; the open-request cap and the account checks hold', function () {
    $w = e44_supporters_world();
    $user = e16User();
    $silver = $w['packages']['silver'];

    $first = e44_request_card($user, $silver, false, 'key-one-0001');
    $again = e44_request_card($user, $silver, false, 'key-one-0001');

    expect((int) $again->id)->toBe((int) $first->id)
        ->and(SeasonSupporterCard::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(SponsorshipPayment::query()->where('user_id', $user->id)->count())->toBe(1);

    e44_request_card($user, $silver, false, 'key-two-0002');
    e44_request_card($user, $silver, false, 'key-three-0003');

    expect(e44_reason(fn () => e44_request_card($user, $silver, false, 'key-four-0004')))->toBe('too_many_open')
        ->and(SeasonSupporterCard::query()->where('user_id', $user->id)->count())->toBe(3);

    $frozen = e16User();
    $frozen->forceFill(['is_frozen' => true])->save();
    $unverified = e16User(['email_verified_at' => null]);

    expect(e44_reason(fn () => e44_request_card($frozen, $silver)))->toBe('frozen')
        ->and(e44_reason(fn () => e44_request_card($unverified, $silver)))->toBe('unverified')
        ->and(SeasonSupporterCard::query()->whereIn('user_id', [$frozen->id, $unverified->id])->count())->toBe(0);
});

test('E44-SC-05: requests stop when the season key is off, the season is not live, the package is inactive or the ranking was finalized', function () {
    $w = e44_supporters_world();
    $silver = $w['packages']['silver'];
    $user = e16User();

    e44_packages_svc()->update($silver, ['is_active' => false], $w['admin']);
    expect(e44_reason(fn () => e44_request_card($user, $silver->fresh())))->toBe('package_inactive');
    e44_packages_svc()->update($silver, ['is_active' => true], $w['admin']);

    config(['seasonal_recognition.supporters.enabled' => false]);
    expect(e44_reason(fn () => e44_request_card($user, $silver->fresh())))->toBe('supporters_disabled');
    config(['seasonal_recognition.supporters.enabled' => true]);

    e44_settings()->setSupportersEnabled($w['season'], false, $w['admin']);
    expect(e44_reason(fn () => e44_request_card($user, $silver->fresh())))->toBe('supporters_disabled');
    e44_settings()->setSupportersEnabled($w['season'], true, $w['admin']);

    Carbon::setTestNow(Carbon::parse('2027-01-05 12:00:00'));
    expect(e44_reason(fn () => e44_request_card($user, $silver->fresh())))->toBe('season_not_live');

    Carbon::setTestNow(Carbon::parse('2026-12-10 12:00:00'));
    DB::table('season_recognition_settings')->where('season_id', $w['season']->id)->update(['supporters_finalized_at' => now()]);
    expect(e44_reason(fn () => e44_request_card($user, $silver->fresh())))->toBe('season_not_live')
        ->and(SeasonSupporterCard::query()->count())->toBe(0)
        ->and(SponsorshipPayment::query()->count())->toBe(0);
});

test('E44-SC-06: submitting proof of transfer grants nothing — no active card, no points, no tier, no notification', function () {
    $w = e44_supporters_world();
    $user = e16User();
    $card = e44_request_card($user, $w['packages']['diamond']);

    $payment = e32_payments()->markTransferred($user, $card->payment()->firstOrFail(), 'حوّلت من حسابي', e32_proof());

    expect($payment->status)->toBe(SponsorshipPayment::STATUS_SUBMITTED)
        ->and($card->fresh()->status)->toBe(SeasonSupporterCard::STATUS_PENDING)
        ->and(e44_entries($w['season']))->toHaveCount(0)
        ->and(e44_stat($w['season'], $user))->toBeNull()
        ->and(e44_notes('supporter_card_verified', $user))->toHaveCount(0)
        ->and(app(\App\Services\SeasonalRecognition\SeasonSupporterIdentityResolver::class)->forUser($user->id))->toBeNull();
});

// ───────────────────────────── التحقق المالي والتفعيل ─────────────────────────────

test('E44-SC-07: verification activates the card, grants its snapshot points once, sets the tier, books the real E30 contribution and notifies once', function () {
    $w = e44_supporters_world();
    $user = e16User();

    $card = e44_buy_card($user, $w['packages']['gold']);
    $payment = $card->payment()->firstOrFail();
    $stat = e44_stat($w['season'], $user);

    expect($card->status)->toBe(SeasonSupporterCard::STATUS_ACTIVE)
        ->and($card->activated_at)->not->toBeNull()
        ->and($payment->status)->toBe(SponsorshipPayment::STATUS_VERIFIED)
        ->and((int) $stat->points_total)->toBe(1000)
        ->and((int) $stat->public_points_total)->toBe(1000)
        ->and($stat->card_tier_key)->toBe('gold')
        ->and($stat->social_status)->toBe(SeasonSupporterStat::SOCIAL_ELIGIBLE)
        ->and(e44_entries($w['season'], $user))->toHaveCount(1)
        ->and(e44_entries($w['season'], $user)->first()->source_type)->toBe('supporter_card')
        ->and((int) e44_entries($w['season'], $user)->first()->source_id)->toBe((int) $card->id)
        ->and(e32_contribution($payment))->not->toBeNull()
        ->and(e32_funding($w['program']))->toBe(10000)
        ->and(e44_notes('supporter_card_verified', $user))->toHaveCount(1)
        ->and(e44_audit('seasonal_supporter_card_activated'))->toBe(1)
        ->and(e44_supporter_reconciler()->inspect($w['season']))->toBe([]);

    // التفعيل متكرر الأمان: لا نقاط ولا إشعار ثانٍ.
    expect(e44_cards()->activateForPayment($payment->id))->toBeNull()
        ->and(e44_entries($w['season'], $user))->toHaveCount(1)
        ->and(e44_notes('supporter_card_verified', $user))->toHaveCount(1)
        ->and(e32_funding($w['program']))->toBe(10000);
});

test('E44-SC-08: silver, gold and diamond grant their own points; only gold and diamond (non-anonymous) enter the social queue', function () {
    $w = e44_supporters_world();
    $expected = ['silver' => [250, null], 'gold' => [1000, 'eligible'], 'diamond' => [2500, 'eligible']];

    foreach ($expected as $tier => [$points, $social]) {
        $user = e16User();
        $card = e44_buy_card($user, $w['packages'][$tier]);
        $stat = e44_stat($w['season'], $user);

        expect($card->tier_key)->toBe($tier)
            ->and((int) $stat->points_total)->toBe($points)
            ->and($stat->card_tier_key)->toBe($tier)
            ->and($stat->social_status)->toBe($social);
    }
});

test('E44-SC-09: the tier is the highest valid, verified, non-refunded card; every active card adds its own points; each refund reverses only its own card once', function () {
    $w = e44_supporters_world();
    $user = e16User();
    $service = e44_cards();

    $silver = e44_buy_card($user, $w['packages']['silver']);
    expect(e44_user_points($w['season'], $user))->toBe(250)->and(e44_stat($w['season'], $user)->card_tier_key)->toBe('silver');

    $gold = e44_buy_card($user, $w['packages']['gold']);
    expect(e44_user_points($w['season'], $user))->toBe(1250)->and(e44_stat($w['season'], $user)->card_tier_key)->toBe('gold');

    $diamond = e44_buy_card($user, $w['packages']['diamond']);
    expect(e44_user_points($w['season'], $user))->toBe(3750)->and(e44_stat($w['season'], $user)->card_tier_key)->toBe('diamond');

    // أرخص بطاقة لا تخفض المستوى: استرداد الفضية يترك الماسي.
    e44_refund_card($silver);
    expect(e44_user_points($w['season'], $user))->toBe(3500)->and(e44_stat($w['season'], $user)->card_tier_key)->toBe('diamond');

    e44_refund_card($diamond);
    expect(e44_user_points($w['season'], $user))->toBe(1000)->and(e44_stat($w['season'], $user)->card_tier_key)->toBe('gold')
        ->and($diamond->fresh()->status)->toBe(SeasonSupporterCard::STATUS_REFUNDED)
        ->and($diamond->fresh()->refunded_at)->not->toBeNull();

    e44_refund_card($gold);
    $stat = e44_stat($w['season'], $user);

    expect((int) $stat->points_total)->toBe(0)
        ->and((int) $stat->public_points_total)->toBe(0)
        ->and($stat->card_tier_key)->toBeNull()
        ->and($stat->social_status)->toBeNull()
        ->and(e44_entries($w['season'], $user))->toHaveCount(6) // 3 منح + 3 عكس
        ->and(e44_entries($w['season'], $user)->where('reason', 'reversal')->count())->toBe(3)
        ->and($service->refundForPayment((int) $diamond->sponsorship_payment_id))->toBeFalse()
        ->and(e44_user_points($w['season'], $user))->toBe(0)
        ->and(e44_supporter_reconciler()->inspect($w['season']))->toBe([]);
});

test('E44-SC-10: a rejected, cancelled or expired payment voids the pending card — nothing is granted and a void card can never be activated', function () {
    $w = e44_supporters_world();

    $rejectedUser = e16User();
    $rejected = e44_request_card($rejectedUser, $w['packages']['silver']);
    $payment = e32_payments()->markTransferred($rejectedUser, $rejected->payment()->firstOrFail(), 'حوّلت', e32_proof());
    e32_payments()->reject(e32_finance(), $payment, 'not_received');

    $cancelledUser = e16User();
    $cancelled = e44_request_card($cancelledUser, $w['packages']['gold']);
    e32_payments()->cancelByOwner($cancelledUser, $cancelled->payment()->firstOrFail());

    expect($rejected->fresh()->status)->toBe(SeasonSupporterCard::STATUS_VOID)
        ->and($cancelled->fresh()->status)->toBe(SeasonSupporterCard::STATUS_VOID)
        ->and(e44_entries($w['season']))->toHaveCount(0)
        ->and(e44_stat($w['season'], $rejectedUser))->toBeNull()
        ->and(e44_cards()->activateForPayment((int) $rejected->sponsorship_payment_id))->toBeNull()
        ->and(e44_cards()->voidForPayment((int) $rejected->sponsorship_payment_id))->toBeFalse()
        ->and(e44_audit('seasonal_supporter_card_voided'))->toBe(2)
        ->and(fn () => $rejected->fresh()->forceFill(['status' => SeasonSupporterCard::STATUS_ACTIVE])->save())->toThrow(LogicException::class)
        ->and(fn () => $rejected->fresh()->delete())->toThrow(LogicException::class);
});

test('E44-SC-11: switching the supporters key off stops new requests only — a payment already made still activates its card and keeps its points', function () {
    $w = e44_supporters_world();
    $user = e16User();
    $card = e44_request_card($user, $w['packages']['gold']);
    $payment = e32_payments()->markTransferred($user, $card->payment()->firstOrFail(), 'حوّلت', e32_proof());

    e44_settings()->setSupportersEnabled($w['season'], false, $w['admin']);
    config(['seasonal_recognition.supporters.enabled' => false]);

    expect(e44_reason(fn () => e44_request_card(e16User(), $w['packages']['silver']->fresh())))->toBe('supporters_disabled');

    e32_payments()->verify(e32_finance(), $payment);

    expect($card->fresh()->status)->toBe(SeasonSupporterCard::STATUS_ACTIVE)
        ->and(e44_user_points($w['season'], $user))->toBe(1000)
        ->and(e32_funding($w['program']))->toBe(10000);

    // الرد المالي لاحقًا يعكس النقاط كذلك (التاريخ لا يُمحى).
    e44_refund_card($card);
    expect(e44_user_points($w['season'], $user))->toBe(0)->and(e44_entries($w['season'], $user))->toHaveCount(2);
});

test('E44-SC-12: a payment that does not match the card snapshot never activates it; the reconciler reports it and a supervisor re-activates after repair', function () {
    $w = e44_supporters_world();
    $user = e16User();
    $card = e44_request_card($user, $w['packages']['silver']);
    $payment = e32_payments()->markTransferred($user, $card->payment()->firstOrFail(), 'حوّلت', e32_proof());

    DB::table('season_supporter_cards')->where('id', $card->id)->update(['price_minor' => 9999]); // تلف مقصود يتجاوز حارس النموذج
    e32_payments()->verify(e32_finance(), $payment);

    expect($card->fresh()->status)->toBe(SeasonSupporterCard::STATUS_PENDING)
        ->and(e44_entries($w['season']))->toHaveCount(0)
        ->and(e44_audit('seasonal_supporter_card_mismatch'))->toBe(1);

    $codes = array_column(e44_supporter_reconciler()->inspect($w['season']), 'code');
    expect($codes)->toContain('card_pending_payment_verified')->toContain('card_payment_mismatch');

    DB::table('season_supporter_cards')->where('id', $card->id)->update(['price_minor' => 2500]);

    expect(e44_reason(fn () => e44_supporter_reconciler()->activatePendingCards($w['season'], e44_staff(['seasonal-recognition.view']))))->toBe('forbidden')
        ->and(e44_supporter_reconciler()->activatePendingCards($w['season'], $w['admin']))->toBe(1)
        ->and(e44_supporter_reconciler()->activatePendingCards($w['season'], $w['admin']))->toBe(0)
        ->and($card->fresh()->status)->toBe(SeasonSupporterCard::STATUS_ACTIVE)
        ->and(e44_user_points($w['season'], $user))->toBe(250)
        ->and(e44_supporter_reconciler()->inspect($w['season']))->toBe([])
        ->and(e44_audit('seasonal_supporter_cards_reactivated'))->toBe(1);
});

test('E44-SC-13: the reconciler reports an active card without its grant and a refunded card whose points were never reversed', function () {
    $w = e44_supporters_world();
    $rec = e44_supporter_reconciler();

    $one = e44_buy_card(e16User(), $w['packages']['gold']);
    DB::table('season_support_point_entries')->where('source_type', 'supporter_card')->where('source_id', $one->id)->delete();

    $codes = array_column($rec->inspect($w['season']), 'code');
    expect($codes)->toContain('card_active_without_grant')->toContain('stat_points_mismatch');

    $two = e44_buy_card(e16User(), $w['packages']['silver']);
    DB::table('season_supporter_cards')->where('id', $two->id)->update(['status' => SeasonSupporterCard::STATUS_REFUNDED]);

    $finding = collect($rec->inspect($w['season']))->firstWhere('code', 'card_refunded_not_reversed');

    expect($finding)->not->toBeNull()->and($finding['card_id'])->toBe((int) $two->id);
});

// ───────────────────────────── E30 والخصوصية ─────────────────────────────

test('E44-SC-14: a public choice yields a public E30 profile and public points; an anonymous one yields neither public points nor a social-queue slot', function () {
    $w = e44_supporters_world();
    $public = e16User();
    $anon = e16User();

    e44_buy_card($public, $w['packages']['gold'], false);
    e44_buy_card($anon, $w['packages']['gold'], true);

    expect((bool) SupporterProfile::query()->where('user_id', $public->id)->value('is_public'))->toBeTrue()
        ->and((bool) SupporterProfile::query()->where('user_id', $anon->id)->value('is_public'))->toBeFalse()
        ->and(e44_public_points($w['season'], $public))->toBe(1000)
        ->and(e44_public_points($w['season'], $anon))->toBe(0)
        ->and(e44_user_points($w['season'], $anon))->toBe(1000)
        ->and(e44_entries($w['season'], $anon)->first()->is_public)->toBeFalse()
        ->and(e44_stat($w['season'], $anon)->social_status)->toBeNull()
        ->and(e44_stat($w['season'], $public)->social_status)->toBe('eligible');

    // الاسترداد يحمل خصوصية الأصل: عكس المجهول لا يمسّ العلني.
    $card = SeasonSupporterCard::query()->where('user_id', $anon->id)->firstOrFail();
    e44_refund_card($card);

    expect(e44_entries($w['season'], $anon)->last()->is_public)->toBeFalse()
        ->and(e44_public_points($w['season'], $anon))->toBe(0)
        ->and(e44_user_points($w['season'], $anon))->toBe(0)
        ->and(e44_supporter_reconciler()->inspect($w['season']))->toBe([]);
});

// ───────────────────────────── طبقة HTTP ─────────────────────────────

test('E44-SC-15: the card form trusts only the tier and visibility — smuggled price, currency, points, style and status are ignored and the user lands on the E32 payment page', function () {
    $w = e44_supporters_world();
    $user = e16User();
    $route = route('seasonal.supporters.cards.store', $w['season']);

    $response = $this->actingAs($user)->post($route, [
        'tier' => 'silver', 'visibility' => 'public', 'display_name' => 'اسم عام', 'request_key' => 'http-key-1',
        'price_minor' => 1, 'amount' => 1, 'currency' => 'EUR', 'support_points' => 999999, 'name_style_key' => 'diamond', 'tier_key' => 'diamond', 'status' => 'active', 'user_id' => 1,
    ]);

    $card = SeasonSupporterCard::query()->where('user_id', $user->id)->firstOrFail();
    $payment = $card->payment()->firstOrFail();

    $response->assertRedirect(route('support.payments.show', ['payment' => $payment->public_id]));

    expect($card->tier_key)->toBe('silver')
        ->and((int) $card->price_minor)->toBe(2500)
        ->and($card->currency)->toBe('USD')
        ->and((int) $card->support_points)->toBe(250)
        ->and($card->name_style_key)->toBe('silver')
        ->and($card->status)->toBe(SeasonSupporterCard::STATUS_PENDING)
        ->and((int) $card->user_id)->toBe((int) $user->id)
        ->and((int) $payment->amount_minor)->toBe(2500)
        ->and($payment->currency)->toBe('USD');

    // نقر مزدوج بالمفتاح نفسه: البطاقة نفسها.
    $this->actingAs($user)->post($route, ['tier' => 'silver', 'visibility' => 'public', 'display_name' => 'اسم عام', 'request_key' => 'http-key-1'])
        ->assertRedirect(route('support.payments.show', ['payment' => $payment->public_id]));

    expect(SeasonSupporterCard::query()->where('user_id', $user->id)->count())->toBe(1);
});

test('E44-SC-16: the card form rejects bad input, guests and unverified users; a disabled season is a 404; the request rate is limited', function () {
    $w = e44_supporters_world();
    $user = e16User();
    $route = route('seasonal.supporters.cards.store', $w['season']);

    $this->post($route, ['tier' => 'silver', 'visibility' => 'public'])->assertRedirect(route('login'));

    $unverified = e16User(['email_verified_at' => null]);
    $this->actingAs($unverified)->post($route, ['tier' => 'silver', 'visibility' => 'public'])->assertRedirect(route('verification.notice'));

    $this->actingAs($user)->post($route, ['tier' => 'platinum', 'visibility' => 'public'])->assertSessionHasErrors('tier');
    $this->actingAs($user)->post($route, ['tier' => 'silver', 'visibility' => 'friends'])->assertSessionHasErrors('visibility');
    $this->actingAs($user)->post($route, ['visibility' => 'public'])->assertSessionHasErrors('tier');
    $this->actingAs($user)->post($route, ['tier' => 'silver', 'visibility' => 'public', 'display_name' => str_repeat('س', 500)])->assertSessionHasErrors('display_name');

    expect(SeasonSupporterCard::query()->count())->toBe(0);

    e44_settings()->setSupportersEnabled($w['season'], false, $w['admin']);
    $this->actingAs($user)->post($route, ['tier' => 'silver', 'visibility' => 'public'])->assertNotFound();
    e44_settings()->setSupportersEnabled($w['season'], true, $w['admin']);

    config(['seasonal_recognition.supporters.card_requests_per_minute' => 2]);
    $limited = e16User();
    $statuses = [];

    foreach (range(1, 3) as $i) {
        $statuses[] = $this->actingAs($limited)->post($route, ['tier' => 'silver', 'visibility' => 'public', 'request_key' => 'rl-'.$i])->getStatusCode();
    }

    expect($statuses)->toBe([302, 302, 429])
        ->and(SeasonSupporterCard::query()->where('user_id', $limited->id)->count())->toBe(2);
});

test('E44-SC-17: an anonymous HTTP request produces an anonymous card and payment — no public name, no public points and no recent-card row for a private E30 profile', function () {
    $w = e44_supporters_world();
    $user = e16User(['name' => 'مستخدم-سري-للاختبار']);

    $this->actingAs($user)->post(route('seasonal.supporters.cards.store', $w['season']), ['tier' => 'gold', 'visibility' => 'anonymous', 'display_name' => 'اسم-لن-يظهر'])->assertRedirect();

    $card = SeasonSupporterCard::query()->where('user_id', $user->id)->firstOrFail();

    expect($card->is_anonymous)->toBeTrue()
        ->and((bool) $card->payment()->firstOrFail()->is_anonymous)->toBeTrue();

    e32_payments()->verify(e32_finance(), e32_payments()->markTransferred($user, $card->payment()->firstOrFail(), 'حوّلت', e32_proof()));

    // يُجلب كلاعب آخر: actingAs يبقى ساريًا، والمالك نفسه يرى اسمه في إطار الصفحة (ترويسة حسابه) وهذا ليس تسرّبًا.
    $html = $this->actingAs(e16User())->get(route('seasonal.supporters.show', $w['season']))->assertOk()->getContent();

    expect(str_contains($html, 'مستخدم-سري-للاختبار'))->toBeFalse()
        ->and(str_contains($html, 'اسم-لن-يظهر'))->toBeFalse()
        ->and(e44_public_points($w['season'], $user))->toBe(0)
        ->and(app(\App\Services\SeasonalRecognition\SeasonSupporterLeaderboardService::class)->recentCards($w['season']))->toBe([]);
});
