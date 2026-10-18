<?php

use App\Models\Puzzle;
use App\Models\SponsorshipContribution;
use App\Models\SponsorshipPayment;
use App\Models\SupporterProfile;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\PuzzleAttemptService;
use App\Services\Sponsorship\SponsorshipException;
use App\Services\Sponsorship\SponsorshipProgramService;
use App\Services\Sponsorship\SupporterLeaderboardService;
use App\Services\Sponsorship\SupporterService;
use App\Services\Sponsorship\SupporterTierService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/_support.php';

/*
| E32 (AK4 + AK5) - التحقق المالي الذرّي (طلب → مساهمة مؤكَّدة) وعزل غير المحقَّق: لا تمويل/مستوى/صدارة/تعزيز قبل التحقق.
*/

beforeEach(function () {
    e32_seed();
    config(['gems.daily_earn_cap' => 100000]);
});

function e32_tier(?int $supporterId): ?string
{
    return $supporterId === null ? null : app(SupporterTierService::class)->forSupporter($supporterId)?->slug;
}

function e32_solve(?User $user = null, int $reward = 200): array
{
    $user ??= User::factory()->create();
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح', 'gem_reward' => $reward]);

    return [app(PuzzleAttemptService::class)->attempt($user, $puzzle, 'صح'), $user];
}

test('E32-36: an ordinary user and a content manager (no sponsorships.confirm) cannot verify, reject, refund or cancel', function () {
    $payment = e32_submitted();

    foreach ([e16User(), e32_manager()] as $who) {
        expect(fn () => e32_payments()->verify($who, $payment))->toThrow(HttpException::class)
            ->and(fn () => e32_payments()->reject($who, $payment, 'other'))->toThrow(HttpException::class)
            ->and(fn () => e32_payments()->cancel($who, $payment, 'سبب كافٍ'))->toThrow(HttpException::class)
            ->and(fn () => e32_payments()->requestClarification($who, $payment, 'سبب كافٍ'))->toThrow(HttpException::class)
            ->and(fn () => e32_payments()->refund($who, $payment, 'سبب كافٍ'))->toThrow(HttpException::class);
    }

    expect($payment->fresh()->status)->toBe('submitted')->and(SponsorshipContribution::query()->count())->toBe(0);
});

test('E32-37: finance (sponsorships.confirm) can verify, and a public user cannot confirm their own payment by any route', function () {
    $user = e16User();
    $payment = e32_submitted($user);

    $this->actingAs($user);
    foreach (['verify', 'confirm', 'approve'] as $action) {
        $this->post('/support/payments/'.$payment->public_id.'/'.$action)->assertNotFound();
    }
    $this->post(route('support.payments.transferred', $payment->public_id), ['status' => 'verified'])->assertRedirect();
    expect($payment->fresh()->status)->toBe('submitted');

    $verified = e32_payments()->verify(e32_finance(), $payment);
    expect($verified->status)->toBe('verified')->and($verified->verified_by)->not->toBeNull();
});

test('E32-38/39: verify creates one confirmed contribution linked to the payment with the same reference', function () {
    $program = e32_program();
    $payment = e32_submitted(null, $program, '37.50');

    $verified = e32_payments()->verify(e32_finance(), $payment);
    $contribution = e32_contribution($verified);

    expect($contribution)->not->toBeNull()->and($contribution->status)->toBe('confirmed')->and($contribution->sponsorship_program_id)->toBe($program->id)
        ->and($contribution->provider)->toBe('manual')->and($contribution->external_reference)->toBe($payment->reference)->and($contribution->reference)->toBe($payment->reference)
        ->and($verified->sponsorship_contribution_id)->toBe($contribution->id)->and($verified->verified_at)->not->toBeNull()
        ->and(SponsorshipContribution::query()->count())->toBe(1);
});

test('E32-40: the supporter profile is created safely once, reused when it exists, and an anonymous-first profile stays private', function () {
    $program = e32_program();
    $user = e16User();
    expect(SupporterProfile::query()->where('user_id', $user->id)->count())->toBe(0);

    $first = e32_verified($user, $program, '10', false);
    $profile = SupporterProfile::query()->where('user_id', $user->id)->firstOrFail();
    expect($first->supporter_profile_id)->toBe($profile->id)->and($profile->type)->toBe('individual')->and((bool) $profile->is_public)->toBeTrue();

    $second = e32_verified($user, $program, '5', false);
    expect(SupporterProfile::query()->where('user_id', $user->id)->count())->toBe(1)->and($second->supporter_profile_id)->toBe($profile->id);

    // مستخدم مرتبط مسبقًا بملف أنشأته الإدارة: يُعاد استعماله ولا يُنشأ ثانٍ.
    $linkedUser = e16User();
    $adminProfile = e30_supporter(['user_id' => $linkedUser->id, 'display_name' => 'ملف الإدارة']);
    $third = e32_verified($linkedUser, $program, '8', false);
    expect(SupporterProfile::query()->where('user_id', $linkedUser->id)->count())->toBe(1)->and($third->supporter_profile_id)->toBe($adminProfile->id);

    // دعم مجهول أولًا: ملف خاص، ولا يتحول علنيًا إلا بقرار علني لاحق.
    $anon = e16User();
    $a = e32_verified($anon, $program, '12', true);
    $anonProfile = SupporterProfile::query()->findOrFail($a->supporter_profile_id);
    expect((bool) $anonProfile->is_public)->toBeFalse();

    e32_verified($anon, $program, '12', false);
    expect((bool) $anonProfile->fresh()->is_public)->toBeTrue()->and(SupporterProfile::query()->where('user_id', $anon->id)->count())->toBe(1);
});

test('E32-41: verification is audited (payment_verified and contribution_materialized) with the actor', function () {
    $finance = e32_finance();
    $payment = e32_submitted();

    e32_payments()->verify($finance, $payment);

    expect(e32_audit('payment_verified'))->toBe(1)->and(e32_audit('contribution_materialized'))->toBe(1);
    $row = DB::table('operational_audit_logs')->where('action', 'payment_verified')->first();
    expect($row->actor_user_id)->toBe($finance->id);
    $raw = (string) $row->metadata;
    foreach (['proof_path', 'proof_sha256', 'payer_note', 'review_note'] as $forbidden) {
        expect(str_contains($raw, $forbidden))->toBeFalse("audit leaked {$forbidden}");
    }
});

test('E32-42: a second verify (double click / retry) does not duplicate anything', function () {
    $program = e32_program();
    $payment = e32_submitted(null, $program, '20');

    e32_payments()->verify(e32_finance(), $payment);
    $again = e32_payments()->verify(e32_finance(), $payment->fresh());

    expect($again->status)->toBe('verified')->and(SponsorshipContribution::query()->count())->toBe(1)->and(e32_funding($program))->toBe(2000)
        ->and(e32_audit('payment_verified'))->toBe(1)->and(e32_notes($payment->user, 'support_confirmed'))->toHaveCount(1);
});

test('E32-43: the confirmation notification is dispatched strictly after the verification transaction commits', function () {
    $user = e16User();
    $payment = e32_submitted($user);
    $baseline = DB::transactionLevel();
    $levels = new ArrayObject;

    app()->extend(NotificationDispatcher::class, fn ($real) => new class($real, $levels) extends NotificationDispatcher
    {
        public function __construct(protected $real, protected ArrayObject $levels) {}

        public function dispatch(User $user, \App\Services\Notifications\NotificationType $type, array $params, string $idempotencyKey, array $refs = [], array $actionParams = [], ?string $actionRoute = null): \App\Services\Notifications\DispatchResult
        {
            $this->levels[] = DB::transactionLevel();

            return $this->real->dispatch($user, $type, $params, $idempotencyKey, $refs, $actionParams, $actionRoute);
        }
    });
    app()->forgetInstance(\App\Services\Sponsorship\SponsorshipNotifier::class);
    app()->forgetInstance(\App\Services\Sponsorship\ContributionService::class);
    app()->forgetInstance(\App\Services\Payments\SponsorshipPaymentService::class);

    e32_payments()->verify(e32_finance(), $payment);

    expect(e32_notes($user, 'support_confirmed'))->toHaveCount(1)->and(count($levels))->toBeGreaterThan(0);
    foreach ($levels as $level) {
        expect($level)->toBe($baseline);
    }
});

test('E32-44: a notification outage never rolls back the verified payment, the contribution or the funding', function () {
    $program = e32_program();
    $user = e16User();
    $payment = e32_submitted($user, $program, '30');

    app()->bind(NotificationDispatcher::class, fn () => new class extends NotificationDispatcher
    {
        public function __construct() {}

        public function dispatch(User $user, \App\Services\Notifications\NotificationType $type, array $params, string $idempotencyKey, array $refs = [], array $actionParams = [], ?string $actionRoute = null): \App\Services\Notifications\DispatchResult
        {
            throw new RuntimeException('simulated notification outage');
        }
    });
    app()->forgetInstance(\App\Services\Sponsorship\SponsorshipNotifier::class);
    app()->forgetInstance(\App\Services\Sponsorship\ContributionService::class);
    app()->forgetInstance(\App\Services\Payments\SponsorshipPaymentService::class);

    $verified = e32_payments()->verify(e32_finance(), $payment);

    expect($verified->status)->toBe('verified')->and($payment->fresh()->status)->toBe('verified')->and(e32_funding($program))->toBe(3000)->and(e32_audit('payment_verified'))->toBe(1)
        ->and(e32_notes($user, 'support_confirmed'))->toHaveCount(0);
});

test('E32-45: a same-currency payment keeps the accounting currency; a foreign currency needs an explicit accounting equivalent that is preserved', function () {
    $program = e32_program();

    $usd = e32_verified(null, $program, '15');
    $usdContribution = e32_contribution($usd);
    expect($usd->accounting_currency)->toBe('USD')->and($usd->accounting_amount_minor)->toBe(1500)->and($usdContribution->accounting_amount_minor)->toBe(1500);

    $eurUser = e16User();
    $eur = e32_request($eurUser, $program, '10', false, 'EUR');
    $eur = e32_payments()->markTransferred($eurUser, $eur, 'يورو');

    expect(fn () => e32_payments()->verify(e32_finance(), $eur))->toThrow(SponsorshipException::class);
    expect($eur->fresh()->status)->toBe('submitted')->and(SponsorshipContribution::query()->count())->toBe(1);

    $done = e32_payments()->verify(e32_finance(), $eur->fresh(), '11.50');
    $contribution = e32_contribution($done);
    expect($done->currency)->toBe('EUR')->and($done->amount_minor)->toBe(1000)->and($done->accounting_currency)->toBe('USD')->and($done->accounting_amount_minor)->toBe(1150)
        ->and($contribution->currency)->toBe('EUR')->and($contribution->original_amount_minor)->toBe(1000)->and($contribution->accounting_amount_minor)->toBe(1150)
        ->and(e32_funding($program))->toBe(2650);
});

test('E32-46: amounts are exact integers end to end (no float drift)', function () {
    $payment = e32_verified(null, null, '1.07');
    $contribution = e32_contribution($payment);

    expect($payment->amount_minor)->toBeInt()->toBe(107)->and($contribution->original_amount_minor)->toBe(107)->and($contribution->accounting_amount_minor)->toBe(107);

    $big = e32_verified(null, null, '1234567.89');
    expect(e32_contribution($big)->accounting_amount_minor)->toBe(123456789);
});

test('E32-47: a verified payment appears in the program funding and on the public program page', function () {
    $program = e32_program(['title' => 'برنامج التمويل', 'goal_amount' => '1000', 'show_goal_progress' => true]);
    expect(e32_funding($program))->toBe(0);

    e32_verified(null, $program, '250');

    expect(e32_funding($program))->toBe(25000);
    $this->get(route('support.program', $program->slug))->assertOk()->assertSee('برنامج التمويل');
});

test('E32-48: the supporter tier updates only on verification', function () {
    $program = e32_program();
    $user = e16User();
    $payment = e32_submitted($user, $program, '60');
    expect(SupporterProfile::query()->where('user_id', $user->id)->exists())->toBeFalse();

    $verified = e32_payments()->verify(e32_finance(), $payment);
    expect(e32_tier($verified->supporter_profile_id))->toBe('silver');

    e32_verified($user, $program, '300');
    expect(e32_tier($verified->supporter_profile_id))->toBe('gold');
});

test('E32-49: the supporter leaderboard updates only on verification', function () {
    $program = e32_program();
    $small = e32_verified(e16User(['name' => 'داعم صغير']), $program, '5', false);
    $payment = e32_submitted(e16User(['name' => 'داعم كبير']), $program, '900', false);

    $ids = fn () => array_map(fn ($e) => $e['supporter']->id, app(SupporterLeaderboardService::class)->top('all')['entries']);
    expect($ids())->toBe([$small->supporter_profile_id]);

    $verified = e32_payments()->verify(e32_finance(), $payment);
    expect($ids())->toBe([$verified->supporter_profile_id, $small->supporter_profile_id]);
});

test('E32-50: the official reward boost sees verified funding and adds exactly the planned bonus', function () {
    $program = e32_program();
    e30_boost($program, '10', 100, 1000); // 1% لكل 10$ بسقف 10%
    [$before] = e32_solve();
    expect($before['sponsor_bonus'])->toBe(0);

    e32_verified(null, $program, '20');
    [$after] = e32_solve();

    expect($after['sponsor_bonus'])->toBe(4); // 2% من 200
});

test('E32-51/52: awaiting and submitted payments are not funding', function () {
    $program = e32_program(['goal_amount' => '1000', 'show_goal_progress' => true]);

    e32_request(null, $program, '500');
    e32_submitted(null, $program, '700');

    expect(e32_funding($program))->toBe(0)->and(app(\App\Services\Sponsorship\SupportTotals::class)->confirmedCount($program->id))->toBe(0)->and(SponsorshipContribution::query()->count())->toBe(0);
});

test('E32-53: awaiting and submitted payments do not change the supporter tier', function () {
    $program = e32_program();
    $user = e16User();
    $profile = e30_supporter(['user_id' => $user->id]);
    e30_confirmed($profile, $program, '5');
    expect(e32_tier($profile->id))->toBe('supporter');

    e32_request($user, $program, '5000');
    e32_submitted($user, $program, '5000');

    expect(e32_tier($profile->id))->toBe('supporter');
});

test('E32-54: awaiting and submitted payments do not enter or move the leaderboard', function () {
    $program = e32_program();
    $leader = e30_supporter(['display_name' => 'المتصدر']);
    e30_confirmed($leader, $program, '50');

    $user = e16User();
    $mine = e30_supporter(['user_id' => $user->id, 'display_name' => 'أنا']);
    e30_confirmed($mine, $program, '10');
    e32_submitted($user, $program, '9999');

    $entries = app(SupporterLeaderboardService::class)->top('all')['entries'];
    expect(array_map(fn ($e) => $e['supporter']->id, $entries))->toBe([$leader->id, $mine->id]);
});

test('E32-55: awaiting and submitted payments add no reward boost', function () {
    $program = e32_program();
    e30_boost($program, '10', 100, 1000);

    e32_request(null, $program, '5000');
    e32_submitted(null, $program, '5000');
    [$result] = e32_solve();

    expect($result['sponsor_bonus'])->toBe(0);
});

test('E32-97: a failure while materializing the contribution rolls EVERYTHING back (no profile, no contribution, same status)', function () {
    $program = e32_program();
    $user = e16User();
    $payment = e32_submitted($user, $program, '40');
    app(SponsorshipProgramService::class)->close(e30_admin(), $program->fresh()); // confirmLocked يرفض البرنامج المغلق بعد إنشاء المساهمة داخل المعاملة

    expect(fn () => e32_payments()->verify(e32_finance(), $payment))->toThrow(SponsorshipException::class);

    $fresh = $payment->fresh();
    expect($fresh->status)->toBe('submitted')->and($fresh->sponsorship_contribution_id)->toBeNull()->and($fresh->verified_at)->toBeNull()
        ->and(SponsorshipContribution::query()->count())->toBe(0)->and(SupporterProfile::query()->where('user_id', $user->id)->exists())->toBeFalse()
        ->and(e32_audit('payment_verified'))->toBe(0)->and(e32_notes($user, 'support_confirmed'))->toHaveCount(0);
});

test('E32-40b: a suspended supporter profile blocks new requests and verification', function () {
    $program = e32_program();
    $user = e16User();
    $profile = e30_supporter(['user_id' => $user->id]);
    $payment = e32_submitted($user, $program, '10');

    app(SupporterService::class)->setStatus(e30_admin(), $profile, SupporterProfile::STATUS_SUSPENDED);

    expect(fn () => e32_payments()->verify(e32_finance(), $payment))->toThrow(SponsorshipException::class)
        ->and(fn () => e32_request($user, $program, '10'))->toThrow(SponsorshipException::class)
        ->and($payment->fresh()->status)->toBe('submitted')->and(SponsorshipContribution::query()->count())->toBe(0);
});

test('E32-36b: an expired request is final and finance cannot verify it (a new request or an E30 manual record is the path)', function () {
    $payment = e32_request();
    $this->travel(49)->hours();
    expect(e32_payments()->expireStale())->toBe(1);

    expect(fn () => e32_payments()->verify(e32_finance(), $payment->fresh()))->toThrow(SponsorshipException::class)->and(SponsorshipContribution::query()->count())->toBe(0);
});

test('E32-37b: finance may verify straight from awaiting_payment (money already seen) and the state machine allows it', function () {
    $payment = e32_request(null, null, '10');

    $verified = e32_payments()->verify(e32_finance(), $payment);

    expect($verified->status)->toBe('verified')->and(SponsorshipContribution::query()->count())->toBe(1);
});
