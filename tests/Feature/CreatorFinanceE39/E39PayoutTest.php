<?php

use App\Models\CreatorEarningAccount;
use App\Models\CreatorEarningEntry;
use App\Models\CreatorPayout;
use App\Models\FraudFlag;
use App\Models\ModerationCase;
use App\Models\OperationalAuditLog;
use App\Models\User;
use App\Services\CreatorFinance\CreatorFinanceException;
use App\Services\CreatorFinance\CreatorFinanceReconciler;
use App\Services\CreatorFinance\CreatorPayoutProvider;
use App\Services\CreatorFinance\ManualCreatorPayoutProvider;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/_support.php';
require_once __DIR__.'/../TrustSafetyE38/_support.php';

beforeEach(fn () => e39_seed());

/** حالة تجميد/تعليق E38 جاهزة على مبدعنا عبر الخدمة الرسمية. */
function e39_e38_case_on(User $subject): array
{
    [$case, $mod] = e38_user_case(null, $subject);

    return [$case, $mod];
}

function e39_balances(CreatorEarningAccount $account): array
{
    $account->refresh();

    return [$account->available_minor, $account->reserved_minor, $account->paid_lifetime_minor];
}

// ───────────────────────────── BA8: الطلب والحجز ─────────────────────────────

test('BA8-01: a request reserves the amount at once, moves it between buckets and writes one ledger entry and one audit row', function () {
    [$creator, , $account] = e39_funded_creator(3);
    $payout = e39_request($creator, '150.25');
    $entry = CreatorEarningEntry::query()->where('creator_payout_id', $payout->id)->firstOrFail();

    expect($payout->status)->toBe('requested')->and($payout->amount_minor)->toBe(15025)->and($payout->currency)->toBe('USD')->and($payout->provider)->toBe('manual')
        ->and($payout->external_reference)->toBeNull()->and($payout->method_code)->toBe('bank_transfer')
        ->and(e39_balances($account))->toBe([100000 - 15025, 15025, 0])
        ->and($entry->type)->toBe('payout_reserve')->and($entry->source_key)->toBe('payout:'.$payout->public_id.':reserve')
        ->and(OperationalAuditLog::query()->where('action', 'creator_payout_requested')->count())->toBe(1);
});

test('BA8-02: the minimum payout is enforced and the exact minimum is accepted', function () {
    [$creator, , $account] = e39_funded_creator(3);

    expect(e39_error(fn () => e39_request($creator, '24.99')))->not->toBeNull()->and(e39_balances($account))->toBe([100000, 0, 0])
        ->and(e39_request($creator, '25.00')->amount_minor)->toBe(2500);
});

test('BA8-03: a request above the available balance is refused and the exact balance is accepted', function () {
    [$creator, , $account] = e39_funded_creator(3);

    expect(e39_error(fn () => e39_request($creator, '1000.01')))->not->toBeNull()->and(CreatorPayout::query()->count())->toBe(0);

    e39_request($creator, '1000.00');

    expect(e39_balances($account))->toBe([0, 100000, 0]);
});

test('BA8-04: the configured maximum payout is enforced', function () {
    [$creator] = e39_funded_creator(3);
    config(['creator_monetization.payout.max_amount_minor' => 50000]);

    expect(e39_error(fn () => e39_request($creator, '500.01')))->not->toBeNull()->and(e39_request($creator, '500.00')->amount_minor)->toBe(50000);
});

test('BA8-05: malformed amounts are refused before anything is reserved', function () {
    [$creator, , $account] = e39_funded_creator(3);

    foreach (['', 'abc', '-30', '+30', '1e2', '30,00', '30.001', '0', '٣٠٫٥٠x', '3 0'] as $bad) {
        expect(e39_error(fn () => e39_request($creator, $bad)))->not->toBeNull();
    }

    expect(e39_balances($account))->toBe([100000, 0, 0])->and(CreatorPayout::query()->count())->toBe(0);
});

test('BA8-06: Arabic-Indic digits and the Arabic decimal separator are accepted as the same amount', function () {
    [$creator] = e39_funded_creator(3);

    expect(e39_request($creator, '٣٠٫٥٠')->amount_minor)->toBe(3050);
});

test('BA8-07: only configured methods are accepted and every one of them works', function () {
    [$creator] = e39_funded_creator(3);

    expect(e39_error(fn () => e39_request($creator, '30.00', 'crypto')))->not->toBeNull();

    foreach (array_keys(config('creator_monetization.payout.methods')) as $method) {
        $payout = e39_request($creator, '30.00', $method);
        expect($payout->method_code)->toBe($method);
        e39_payouts()->cancel($creator, $payout);
    }
});

test('BA8-08: one open request per creator in every open state, and a new one is allowed after any terminal state', function () {
    [$creator] = e39_funded_creator(3);
    $admin = e39_admin();
    $first = e39_request($creator, '30.00');

    expect(e39_error(fn () => e39_request($creator, '30.00')))->not->toBeNull();
    e39_payouts()->startReview($first, $admin);
    expect(e39_error(fn () => e39_request($creator, '30.00')))->not->toBeNull();
    e39_payouts()->approve($first->refresh(), $admin);
    expect(e39_error(fn () => e39_request($creator, '30.00')))->not->toBeNull();
    e39_payouts()->settle($first->refresh(), $admin, 'REF-A-1001');

    $second = e39_request($creator, '30.00');
    e39_payouts()->reject($second, $admin, 'رفض لأسباب تشغيلية معتمدة');
    $third = e39_request($creator, '30.00');
    e39_payouts()->cancel($creator, $third);

    expect(CreatorPayout::query()->count())->toBe(3);
    expect(e39_request($creator, '30.00')->status)->toBe('requested');
});

test('BA8-09: the database itself refuses a second open payout for one account', function () {
    [$creator, , $account] = e39_funded_creator(3);
    e39_request($creator, '30.00');
    $other = new CreatorPayout;
    $other->forceFill([
        'creator_earning_account_id' => $account->id, 'creator_profile_id' => $account->creator_profile_id, 'user_id' => $account->user_id,
        'amount_minor' => 3000, 'currency' => 'USD', 'method_code' => 'bank_transfer', 'provider' => 'manual', 'status' => 'requested', 'requested_at' => now(),
    ]);

    expect(fn () => $other->save())->toThrow(QueryException::class);
});

test('BA8-10: the reserved money cannot be spent twice - a second payout after the first is paid only sees what is left', function () {
    [$creator, , $account] = e39_funded_creator(3);
    $admin = e39_admin();
    $first = e39_request($creator, '600.00');
    e39_payouts()->approve($first, $admin);
    e39_payouts()->settle($first->refresh(), $admin, 'REF-B-2001');

    expect(e39_error(fn () => e39_request($creator, '400.01')))->not->toBeNull()->and(e39_request($creator, '400.00')->amount_minor)->toBe(40000)
        ->and(e39_balances($account))->toBe([0, 40000, 60000]);
});

test('BA8-11: unverified, frozen, suspended and pending creators cannot request', function () {
    [$creator, $profile, $account] = e39_funded_creator(3);

    $creator->forceFill(['email_verified_at' => null])->save();
    expect(e39_error(fn () => e39_request($creator, '30.00')))->not->toBeNull();
    $creator->forceFill(['email_verified_at' => now()])->save();

    $creator->forceFill(['is_frozen' => true])->save();
    expect(e39_error(fn () => e39_request($creator, '30.00')))->not->toBeNull();
    $creator->forceFill(['is_frozen' => false])->save();

    DB::table('creator_profiles')->where('id', $profile->id)->update(['status' => 'suspended']);
    expect(e39_error(fn () => e39_request($creator, '30.00')))->not->toBeNull();
    DB::table('creator_profiles')->where('id', $profile->id)->update(['status' => 'pending']);
    expect(e39_error(fn () => e39_request($creator, '30.00')))->not->toBeNull()->and(e39_balances($account))->toBe([100000, 0, 0]);

    DB::table('creator_profiles')->where('id', $profile->id)->update(['status' => 'approved']);
    expect(e39_request($creator, '30.00')->status)->toBe('requested');
});

test('BA8-12: a creator who was never enrolled for monetization cannot request', function () {
    $admin = e39_admin();
    [$creator] = e39_creator($admin, false);

    expect(e39_error(fn () => e39_request($creator, '30.00')))->not->toBeNull()->and(CreatorPayout::query()->count())->toBe(0);
});

test('BA8-13: a paused account cannot request until monetization is resumed', function () {
    [$creator, , $account] = e39_funded_creator(3);
    $admin = e39_admin();
    e39_monetization()->pause($account, $admin, 'إيقاف مؤقت للمراجعة');

    expect(e39_error(fn () => e39_request($creator, '30.00')))->not->toBeNull();

    e39_monetization()->enable($account->refresh()->creatorProfile, $admin);

    expect(e39_request($creator, '30.00')->status)->toBe('requested');
});

test('BA8-14: a payout hold blocks a new request and releasing it restores the ability', function () {
    [$creator, , $account] = e39_funded_creator(3);
    $admin = e39_admin();
    e39_monetization()->placeHold($account, $admin, 'investigation', 'تحقيق جارٍ في الحساب');

    expect(e39_error(fn () => e39_request($creator, '30.00')))->not->toBeNull();

    e39_monetization()->releaseHold($account->refresh(), $admin, 'انتهى التحقيق دون مخالفة');

    expect(e39_request($creator, '30.00')->status)->toBe('requested');
});

test('BA8-15: the global kill switch refuses payout requests', function () {
    [$creator] = e39_funded_creator(3);
    config(['creator_monetization.enabled' => false]);

    expect(e39_error(fn () => e39_request($creator, '30.00')))->not->toBeNull()->and(CreatorPayout::query()->count())->toBe(0);
});

test('BA8-16: an account whose currency no longer matches the accounting currency cannot request', function () {
    [$creator] = e39_funded_creator(3);
    config(['creator_monetization.accounting_currency' => 'TRY', 'sponsorship.accounting_currency' => 'TRY']);

    expect(e39_error(fn () => e39_request($creator, '30.00')))->not->toBeNull();
});

// ───────────────────────────── إلغاء المبدع ─────────────────────────────

test('BA8-17: the creator cancels a new request, the reservation returns and a repeated cancel is a no-op', function () {
    [$creator, , $account] = e39_funded_creator(3);
    $payout = e39_request($creator, '200.00');
    $cancelled = e39_payouts()->cancel($creator, $payout);
    $again = e39_payouts()->cancel($creator, $payout->refresh());

    expect($cancelled->status)->toBe('cancelled')->and($cancelled->cancelled_at)->not->toBeNull()->and($again->id)->toBe($payout->id)
        ->and(e39_balances($account))->toBe([100000, 0, 0])
        ->and(CreatorEarningEntry::query()->where('creator_payout_id', $payout->id)->pluck('type')->all())->toBe(['payout_reserve', 'payout_release'])
        ->and($payout->refresh()->open_account_id)->toBeNull();
});

test('BA8-18: a cancel is refused once review started, after approval, payment or rejection', function () {
    [$creator, , $account] = e39_funded_creator(3);
    $admin = e39_admin();
    $payout = e39_request($creator, '100.00');
    e39_payouts()->startReview($payout, $admin);

    expect(e39_error(fn () => e39_payouts()->cancel($creator, $payout->refresh())))->not->toBeNull();

    e39_payouts()->approve($payout->refresh(), $admin);
    expect(e39_error(fn () => e39_payouts()->cancel($creator, $payout->refresh())))->not->toBeNull();
    e39_payouts()->settle($payout->refresh(), $admin, 'REF-C-3001');
    expect(e39_error(fn () => e39_payouts()->cancel($creator, $payout->refresh())))->not->toBeNull();

    $next = e39_request($creator, '100.00');
    e39_payouts()->reject($next, $admin, 'رفض لأسباب تشغيلية معتمدة');
    expect(e39_error(fn () => e39_payouts()->cancel($creator, $next->refresh())))->not->toBeNull()->and(e39_balances($account))->toBe([90000, 0, 10000]);
});

test('BA8-19: only the owner may cancel a request', function () {
    [$creator] = e39_funded_creator(3);
    $payout = e39_request($creator, '100.00');

    expect(e39_error(fn () => e39_payouts()->cancel(e16User(), $payout)))->not->toBeNull()->and($payout->refresh()->status)->toBe('requested');
});

test('BA8-20: a creator can still cancel while paused or on hold so the reserved money is never stuck', function () {
    [$creator, , $account] = e39_funded_creator(3);
    $admin = e39_admin();
    $payout = e39_request($creator, '100.00');
    e39_monetization()->pause($account, $admin, 'إيقاف مؤقت للمراجعة');
    e39_monetization()->placeHold($account->refresh(), $admin, 'investigation', 'تحقيق جارٍ في الحساب');

    expect(e39_payouts()->cancel($creator, $payout)->status)->toBe('cancelled')->and(e39_balances($account))->toBe([100000, 0, 0]);
});

// ───────────────────────────── BA9: المراجعة والاعتماد والرفض ─────────────────────────────

test('BA9-01: starting a review is idempotent, needs the review permission and only applies to a new request', function () {
    [$creator] = e39_funded_creator(3);
    $payout = e39_request($creator, '100.00');

    expect(e39_error(fn () => e39_payouts()->startReview($payout, e39_plain_staff())))->not->toBeNull()
        ->and(e39_error(fn () => e39_payouts()->startReview($payout, e39_staff_with(['creator-finance.view']))))->not->toBeNull();

    $reviewer = e39_staff_with(['creator-finance.review-payouts']);
    $one = e39_payouts()->startReview($payout, $reviewer);
    $two = e39_payouts()->startReview($payout->refresh(), $reviewer);

    expect($one->status)->toBe('under_review')->and($one->review_started_by)->toBe($reviewer->id)->and($two->id)->toBe($one->id);

    e39_payouts()->approve($payout->refresh(), $reviewer);

    expect(e39_error(fn () => e39_payouts()->startReview($payout->refresh(), $reviewer)))->not->toBeNull();
});

test('BA9-02: approval works from requested or under review, records the approver, audits and notifies exactly once', function () {
    [$creator] = e39_funded_creator(3);
    $admin = e39_admin();
    $payout = e39_request($creator, '100.00');
    e39_payouts()->startReview($payout, $admin);
    $approved = e39_payouts()->approve($payout->refresh(), $admin, 'تم التحقق من الطلب');
    e39_payouts()->approve($payout->refresh(), $admin);

    expect($approved->status)->toBe('approved')->and($approved->approved_by)->toBe($admin->id)->and($approved->approved_at)->not->toBeNull()->and($approved->review_note)->toBe('تم التحقق من الطلب')
        ->and(OperationalAuditLog::query()->where('action', 'creator_payout_approved')->count())->toBe(1)
        ->and(e39_notes($creator, 'creator_payout_approved'))->toHaveCount(1);

    e39_payouts()->settle($payout->refresh(), $admin, 'REF-D-4001');
    $direct = e39_request($creator, '30.00');

    expect(e39_payouts()->approve($direct, $admin)->status)->toBe('approved');
});

test('BA9-03: the approval permission is enforced in the service and a long note is refused', function () {
    [$creator] = e39_funded_creator(3);
    $payout = e39_request($creator, '100.00');

    expect(e39_error(fn () => e39_payouts()->approve($payout, e39_plain_staff())))->not->toBeNull()
        ->and(e39_error(fn () => e39_payouts()->approve($payout, e39_staff_with(['creator-finance.settle-payouts']))))->not->toBeNull()
        ->and(e39_error(fn () => e39_payouts()->approve($payout, e39_admin(), str_repeat('ب', 501))))->not->toBeNull()
        ->and($payout->refresh()->status)->toBe('requested');
});

test('BA9-04: approval is blocked while paused, on hold, frozen or suspended, but rejection still works and returns the money', function () {
    [$creator, $profile, $account] = e39_funded_creator(3);
    $admin = e39_admin();

    $scenarios = [
        'paused' => [fn () => e39_monetization()->pause($account, $admin, 'إيقاف مؤقت للمراجعة'), fn () => e39_monetization()->enable($profile, $admin)],
        'hold' => [fn () => e39_monetization()->placeHold($account->refresh(), $admin, 'investigation', 'تحقيق جارٍ في الحساب'), fn () => e39_monetization()->releaseHold($account->refresh(), $admin, 'انتهى التحقيق دون مخالفة')],
        'frozen' => [fn () => $creator->forceFill(['is_frozen' => true])->save(), fn () => $creator->forceFill(['is_frozen' => false])->save()],
        'suspended' => [fn () => DB::table('creator_profiles')->where('id', $profile->id)->update(['status' => 'suspended']), fn () => DB::table('creator_profiles')->where('id', $profile->id)->update(['status' => 'approved'])],
    ];

    foreach ($scenarios as $name => [$apply, $undo]) {
        $payout = e39_request($creator->refresh(), '100.00');
        $apply();

        expect(e39_error(fn () => e39_payouts()->approve($payout->refresh(), $admin)))->not->toBeNull("approve should be refused when {$name}");
        expect($payout->refresh()->status)->toBe('requested');

        $undo();
        $rejected = e39_payouts()->reject($payout->refresh(), $admin, 'رفض لأسباب تشغيلية معتمدة');
        expect($rejected->status)->toBe('rejected')->and(e39_balances($account))->toBe([100000, 0, 0]);
    }
});

test('BA9-05: rejection needs a public message of 8 to 300 characters, returns the reservation, notifies once and is idempotent', function () {
    [$creator, , $account] = e39_funded_creator(3);
    $admin = e39_admin();
    $payout = e39_request($creator, '100.00');

    foreach (['', 'قصير', str_repeat('ب', 301)] as $bad) {
        expect(e39_error(fn () => e39_payouts()->reject($payout, $admin, $bad)))->not->toBeNull();
    }

    $rejected = e39_payouts()->reject($payout, $admin, 'المعلومات المقدمة غير كافية للصرف', 'ملاحظة داخلية للمراجع');
    e39_payouts()->reject($payout->refresh(), $admin, 'المعلومات المقدمة غير كافية للصرف');

    expect($rejected->status)->toBe('rejected')->and($rejected->decision_message)->toBe('المعلومات المقدمة غير كافية للصرف')->and($rejected->review_note)->toBe('ملاحظة داخلية للمراجع')
        ->and(e39_balances($account))->toBe([100000, 0, 0])->and(e39_notes($creator, 'creator_payout_rejected'))->toHaveCount(1)
        ->and(CreatorEarningEntry::query()->where('creator_payout_id', $payout->id)->count())->toBe(2)->and($payout->refresh()->open_account_id)->toBeNull();
});

test('BA9-06: an approved but unpaid request can still be rejected and the reservation returns', function () {
    [$creator, , $account] = e39_funded_creator(3);
    $admin = e39_admin();
    $payout = e39_request($creator, '100.00');
    e39_payouts()->approve($payout, $admin);
    e39_payouts()->reject($payout->refresh(), $admin, 'تعذّر الصرف لأسباب خارجة عن الإدارة');

    expect($payout->refresh()->status)->toBe('rejected')->and(e39_balances($account))->toBe([100000, 0, 0]);
});

test('BA9-07: a paid, cancelled or already-rejected request cannot be rejected again as a different outcome', function () {
    [$creator] = e39_funded_creator(3);
    $admin = e39_admin();
    $paid = e39_request($creator, '30.00');
    e39_payouts()->approve($paid, $admin);
    e39_payouts()->settle($paid->refresh(), $admin, 'REF-E-5001');
    $cancelled = e39_request($creator, '30.00');
    e39_payouts()->cancel($creator, $cancelled);

    expect(e39_error(fn () => e39_payouts()->reject($paid->refresh(), $admin, 'محاولة رفض دفعة مصروفة')))->not->toBeNull()
        ->and(e39_error(fn () => e39_payouts()->reject($cancelled->refresh(), $admin, 'محاولة رفض طلب ملغى')))->not->toBeNull();
});

test('BA9-08: staff cannot process their own financial account at any step', function () {
    $admin = e39_admin();
    [$creator, $profile, $account] = e39_creator($admin);
    e39_loose_policy();
    e39_solve([e39_work($creator)], 3);
    e39_finalize(e39_review($admin), $admin);
    $creator->assignRole('administrator');
    $payout = e39_request($creator->refresh(), '100.00');

    foreach ([
        fn () => e39_payouts()->startReview($payout->refresh(), $creator->refresh()),
        fn () => e39_payouts()->approve($payout->refresh(), $creator->refresh()),
        fn () => e39_payouts()->reject($payout->refresh(), $creator->refresh(), 'محاولة معالجة طلبي الخاص'),
    ] as $call) {
        expect(e39_error($call))->not->toBeNull();
    }

    e39_payouts()->approve($payout->refresh(), $admin);

    expect(e39_error(fn () => e39_payouts()->settle($payout->refresh(), $creator->refresh(), 'REF-SELF-1')))->not->toBeNull()->and($payout->refresh()->status)->toBe('approved');
});

test('BA9-09: approval refuses when the reserved balance no longer covers the request', function () {
    [$creator, , $account] = e39_funded_creator(3);
    $payout = e39_request($creator, '100.00');
    DB::table('creator_earning_accounts')->where('id', $account->id)->update(['reserved_minor' => 5]);

    expect(e39_error(fn () => e39_payouts()->approve($payout, e39_admin())))->not->toBeNull()->and($payout->refresh()->status)->toBe('requested');
});

// ───────────────────────────── BA10: الصرف ─────────────────────────────

test('BA10-01: settling moves the money from reserved to paid, records who paid and the reference, and notifies once', function () {
    [$creator, , $account] = e39_funded_creator(3);
    $admin = e39_admin();
    $payout = e39_request($creator, '250.00');
    e39_payouts()->approve($payout, $admin);
    $paid = e39_payouts()->settle($payout->refresh(), $admin, 'TRX-2026-0001');
    $entry = CreatorEarningEntry::query()->where('creator_payout_id', $payout->id)->where('type', 'payout_settle')->firstOrFail();

    expect($paid->status)->toBe('paid')->and($paid->paid_by)->toBe($admin->id)->and($paid->paid_at)->not->toBeNull()->and($paid->external_reference)->toBe('TRX-2026-0001')
        ->and(e39_balances($account))->toBe([75000, 0, 25000])->and($entry->reserved_delta_minor)->toBe(-25000)->and($entry->paid_delta_minor)->toBe(25000)
        ->and(e39_notes($creator, 'creator_payout_paid'))->toHaveCount(1)->and($payout->refresh()->open_account_id)->toBeNull()
        ->and(OperationalAuditLog::query()->where('action', 'creator_payout_paid')->count())->toBe(1);
});

test('BA10-02: settling needs its own permission - review rights alone are not enough', function () {
    [$creator] = e39_funded_creator(3);
    $payout = e39_request($creator, '100.00');
    $reviewer = e39_staff_with(['creator-finance.review-payouts']);
    e39_payouts()->approve($payout, $reviewer);

    expect(e39_error(fn () => e39_payouts()->settle($payout->refresh(), $reviewer, 'REF-F-6001')))->not->toBeNull()
        ->and(e39_error(fn () => e39_payouts()->settle($payout->refresh(), e39_plain_staff(), 'REF-F-6001')))->not->toBeNull()
        ->and(e39_payouts()->settle($payout->refresh(), e39_staff_with(['creator-finance.settle-payouts']), 'REF-F-6001')->status)->toBe('paid');
});

test('BA10-03: only an approved request can be settled', function () {
    [$creator] = e39_funded_creator(3);
    $admin = e39_admin();
    $payout = e39_request($creator, '100.00');

    expect(e39_error(fn () => e39_payouts()->settle($payout, $admin, 'REF-G-7001')))->not->toBeNull();
    e39_payouts()->startReview($payout->refresh(), $admin);
    expect(e39_error(fn () => e39_payouts()->settle($payout->refresh(), $admin, 'REF-G-7001')))->not->toBeNull();
    e39_payouts()->reject($payout->refresh(), $admin, 'رفض لأسباب تشغيلية معتمدة');
    expect(e39_error(fn () => e39_payouts()->settle($payout->refresh(), $admin, 'REF-G-7001')))->not->toBeNull();

    $cancelled = e39_request($creator, '100.00');
    e39_payouts()->cancel($creator, $cancelled);

    expect(e39_error(fn () => e39_payouts()->settle($cancelled->refresh(), $admin, 'REF-G-7002')))->not->toBeNull();
});

test('BA10-04: settling again with the same reference is a no-op, with another reference it is refused', function () {
    [$creator, , $account] = e39_funded_creator(3);
    $admin = e39_admin();
    $payout = e39_request($creator, '100.00');
    e39_payouts()->approve($payout, $admin);
    e39_payouts()->settle($payout->refresh(), $admin, 'REF-H-8001');
    $again = e39_payouts()->settle($payout->refresh(), $admin, 'REF-H-8001');

    expect($again->status)->toBe('paid')->and(e39_balances($account))->toBe([90000, 0, 10000])->and(e39_notes($creator, 'creator_payout_paid'))->toHaveCount(1)
        ->and(CreatorEarningEntry::query()->where('type', 'payout_settle')->count())->toBe(1)
        ->and(e39_error(fn () => e39_payouts()->settle($payout->refresh(), $admin, 'REF-H-9999')))->not->toBeNull();
});

test('BA10-05: an external reference is globally unique and the database enforces it', function () {
    [$creator, , $account] = e39_funded_creator(3);
    $admin = e39_admin();
    $first = e39_request($creator, '30.00');
    e39_payouts()->approve($first, $admin);
    e39_payouts()->settle($first->refresh(), $admin, 'REF-I-1001');
    $second = e39_request($creator, '30.00');
    e39_payouts()->approve($second, $admin);

    expect(e39_error(fn () => e39_payouts()->settle($second->refresh(), $admin, 'REF-I-1001')))->not->toBeNull()
        ->and(e39_error(fn () => e39_payouts()->settle($second->refresh(), $admin, '  REF-I-1001  ')))->not->toBeNull()
        ->and($second->refresh()->status)->toBe('approved');

    expect(fn () => DB::table('creator_payouts')->where('id', $second->id)->update(['external_reference' => 'REF-I-1001']))->toThrow(QueryException::class);
});

test('BA10-06: reference format is validated - length, characters and whitespace normalization', function () {
    $provider = new ManualCreatorPayoutProvider;

    expect($provider->normalizeReference("  TRX   2026 / 55  "))->toBe('TRX 2026 / 55')->and($provider->normalizeReference('حوالة-٢٠٢٦/١٢'))->toBe('حوالة-٢٠٢٦/١٢');

    foreach (['', 'abc', str_repeat('A', 121), '-start', 'bad;char', 'DROP TABLE', "line\nbreak<script>", 'a@b.c'] as $bad) {
        if ($bad === 'DROP TABLE') {
            expect($provider->normalizeReference($bad))->toBe('DROP TABLE'); // حروف ومسافة فقط: صالح تقنيًا، وهو نص عادي لا ينفَّذ

            continue;
        }

        expect(fn () => $provider->normalizeReference($bad))->toThrow(CreatorFinanceException::class);
    }
});

test('BA10-07: settling is blocked while paused, on hold, frozen or suspended', function () {
    [$creator, $profile, $account] = e39_funded_creator(3);
    $admin = e39_admin();
    $payout = e39_request($creator, '100.00');
    e39_payouts()->approve($payout, $admin);

    $blocks = [
        [fn () => e39_monetization()->pause($account, $admin, 'إيقاف مؤقت للمراجعة'), fn () => e39_monetization()->enable($profile, $admin)],
        [fn () => e39_monetization()->placeHold($account->refresh(), $admin, 'payout_dispute', 'نزاع على الدفعة قيد النظر'), fn () => e39_monetization()->releaseHold($account->refresh(), $admin, 'تمت تسوية النزاع كاملًا')],
        [fn () => $creator->forceFill(['is_frozen' => true])->save(), fn () => $creator->forceFill(['is_frozen' => false])->save()],
        [fn () => DB::table('creator_profiles')->where('id', $profile->id)->update(['status' => 'suspended']), fn () => DB::table('creator_profiles')->where('id', $profile->id)->update(['status' => 'approved'])],
    ];

    foreach ($blocks as $i => [$apply, $undo]) {
        $apply();
        expect(e39_error(fn () => e39_payouts()->settle($payout->refresh(), $admin, 'REF-J-1001')))->not->toBeNull("block {$i}")->and($payout->refresh()->status)->toBe('approved');
        $undo();
    }

    expect(e39_payouts()->settle($payout->refresh(), $admin, 'REF-J-1001')->status)->toBe('paid');
});

test('BA10-08: separation of duties is optional and, when enabled, the approver cannot also settle', function () {
    [$creator] = e39_funded_creator(3);
    $approver = e39_admin();
    $other = e39_admin();
    $payout = e39_request($creator, '100.00');
    e39_payouts()->approve($payout, $approver);

    config(['creator_monetization.payout.require_separate_settler' => true]);

    expect(e39_error(fn () => e39_payouts()->settle($payout->refresh(), $approver, 'REF-K-1001')))->not->toBeNull()->and($payout->refresh()->status)->toBe('approved')
        ->and(e39_payouts()->settle($payout->refresh(), $other, 'REF-K-1001')->status)->toBe('paid');

    config(['creator_monetization.payout.require_separate_settler' => false]);
    $next = e39_request($creator, '30.00');
    e39_payouts()->approve($next, $approver);

    expect(e39_payouts()->settle($next->refresh(), $approver, 'REF-K-1002')->status)->toBe('paid');
});

test('BA10-09: settling refuses when the reserved balance does not cover the payout', function () {
    [$creator, , $account] = e39_funded_creator(3);
    $admin = e39_admin();
    $payout = e39_request($creator, '100.00');
    e39_payouts()->approve($payout, $admin);
    DB::table('creator_earning_accounts')->where('id', $account->id)->update(['reserved_minor' => 1]);

    expect(e39_error(fn () => e39_payouts()->settle($payout->refresh(), $admin, 'REF-L-1001')))->not->toBeNull()->and($payout->refresh()->status)->toBe('approved');
});

test('BA10-10: the payout model enforces its state machine and immutability', function () {
    [$creator] = e39_funded_creator(3);
    $admin = e39_admin();
    $payout = e39_request($creator, '100.00');

    expect(fn () => $payout->forceFill(['status' => 'paid'])->save())->toThrow(LogicException::class);

    $payout->refresh();
    expect(fn () => $payout->forceFill(['amount_minor' => 1])->save())->toThrow(LogicException::class)
        ->and(fn () => $payout->forceFill(['currency' => 'EUR'])->save())->toThrow(LogicException::class)
        ->and(fn () => $payout->forceFill(['user_id' => e16User()->id])->save())->toThrow(LogicException::class)
        ->and(fn () => $payout->forceFill(['method_code' => 'other'])->save())->toThrow(LogicException::class)
        ->and(fn () => $payout->delete())->toThrow(LogicException::class);

    e39_payouts()->approve($payout->refresh(), $admin);
    e39_payouts()->settle($payout->refresh(), $admin, 'REF-M-1001');
    $paid = $payout->refresh();

    expect(fn () => $paid->forceFill(['status' => 'rejected'])->save())->toThrow(LogicException::class)
        ->and(fn () => $paid->forceFill(['external_reference' => 'REF-M-9999'])->save())->toThrow(LogicException::class)
        ->and(fn () => $paid->forceFill(['review_note' => 'تعديل بعد الصرف'])->save())->toThrow(LogicException::class);
});

test('BA10-11: no column anywhere in the E39 tables can hold a bank destination or credential', function () {
    $forbidden = ['iban', 'swift', 'account_number', 'bank', 'card', 'pan', 'cvv', 'destination', 'beneficiary', 'wallet_address', 'address', 'password', 'secret', 'token', 'credential', 'api_key', 'phone', 'email', 'tax', 'ssn'];

    foreach (['creator_revenue_periods', 'creator_revenue_allocations', 'creator_earning_accounts', 'creator_earning_entries', 'creator_payouts'] as $table) {
        foreach (Schema::getColumnListing($table) as $column) {
            foreach ($forbidden as $word) {
                expect(str_contains($column, $word))->toBeFalse("{$table}.{$column} looks like it stores {$word}");
            }
        }
    }
});

test('BA10-12: a full lifecycle leaves the reconciler completely clean', function () {
    [$creator, , $account] = e39_funded_creator(3);
    $admin = e39_admin();
    $a = e39_request($creator, '100.00');
    e39_payouts()->reject($a, $admin, 'رفض لأسباب تشغيلية معتمدة');
    $b = e39_request($creator, '250.00');
    e39_payouts()->approve($b, $admin);
    e39_payouts()->settle($b->refresh(), $admin, 'REF-N-1001');
    $c = e39_request($creator, '50.00');
    e39_payouts()->cancel($creator, $c);
    e39_request($creator, '75.50');
    e39_ledger()->adjust($account->refresh(), $admin, 'debit', '10.00', 'correction', 'تصحيح خطأ حسابي موثّق');

    $report = app(CreatorFinanceReconciler::class)->run();

    expect($report['findings'])->toBe([])->and($report['remaining'])->toBe(0)->and($report['checked_accounts'])->toBe(1);
});

test('BA10-13: the payout provider is the manual one, never dispatches automatically and is separate from the inbound payment provider', function () {
    $provider = app(CreatorPayoutProvider::class);
    $source = file_get_contents(base_path('app/Services/CreatorFinance/CreatorPayoutService.php'));

    expect($provider)->toBeInstanceOf(ManualCreatorPayoutProvider::class)->and($provider->code())->toBe('manual')->and($provider->dispatchesAutomatically())->toBeFalse()
        ->and($source)->not->toContain('SponsorshipPayment')->and($source)->not->toContain('PaymentProvider\\')->and($source)->not->toContain('Http::')
        ->and(is_subclass_of(ManualCreatorPayoutProvider::class, \App\Services\Payments\PaymentProvider::class))->toBeFalse();
});

test('BA10-14: payouts never touch sponsorship payments or sponsorship tables', function () {
    $before = [DB::table('sponsorship_payments')->count(), DB::table('sponsorship_contributions')->count(), DB::table('sponsorship_reward_bonuses')->count()];
    [$creator] = e39_funded_creator(3);
    $admin = e39_admin();
    $payout = e39_request($creator, '100.00');
    e39_payouts()->approve($payout, $admin);
    e39_payouts()->settle($payout->refresh(), $admin, 'REF-O-1001');

    expect([DB::table('sponsorship_payments')->count(), DB::table('sponsorship_contributions')->count(), DB::table('sponsorship_reward_bonuses')->count()])->toBe($before);
});

// ───────────────────────────── بوابة التسييل والتعليق ─────────────────────────────

test('BA13-01: monetization can only be enabled for an approved, non-frozen creator by someone with the right permission, never for oneself', function () {
    $admin = e39_admin();
    [$user, $profile] = e39_creator($admin, false);

    expect(e39_error(fn () => e39_monetization()->enable($profile, e39_plain_staff())))->not->toBeNull()
        ->and(e39_error(fn () => e39_monetization()->enable($profile, e39_staff_with(['creator-finance.view']))))->not->toBeNull();

    $user->forceFill(['is_frozen' => true])->save();
    expect(e39_error(fn () => e39_monetization()->enable($profile, $admin)))->not->toBeNull();
    $user->forceFill(['is_frozen' => false])->save();

    DB::table('creator_profiles')->where('id', $profile->id)->update(['status' => 'pending']);
    expect(e39_error(fn () => e39_monetization()->enable($profile->refresh(), $admin)))->not->toBeNull();
    DB::table('creator_profiles')->where('id', $profile->id)->update(['status' => 'approved']);

    $user->assignRole('administrator');
    expect(e39_error(fn () => e39_monetization()->enable($profile->refresh(), $user->refresh())))->not->toBeNull()->and(CreatorEarningAccount::query()->count())->toBe(0);

    expect(e39_monetization()->enable($profile->refresh(), $admin)->monetization_status)->toBe('enabled');
});

test('BA13-02: pause is audited and idempotent, needs a note and the permission, and resume restores the account', function () {
    [, $profile, $account] = e39_creator();
    $admin = e39_admin();

    expect(e39_error(fn () => e39_monetization()->pause($account, $admin, 'قصير')))->not->toBeNull()
        ->and(e39_error(fn () => e39_monetization()->pause($account, e39_plain_staff(), 'ملاحظة كافية للإيقاف')))->not->toBeNull();

    $paused = e39_monetization()->pause($account, $admin, 'ملاحظة كافية للإيقاف');
    e39_monetization()->pause($account->refresh(), $admin, 'ملاحظة كافية للإيقاف');
    $resumed = e39_monetization()->enable($profile, $admin);

    expect($paused->monetization_status)->toBe('paused')->and($resumed->monetization_status)->toBe('enabled')
        ->and(OperationalAuditLog::query()->where('action', 'creator_monetization_paused')->count())->toBe(1)
        ->and(OperationalAuditLog::query()->where('action', 'creator_monetization_resumed')->count())->toBe(1);
});

test('BA13-03: a payout hold needs a closed-list reason, a note and the hold permission and is idempotent per reason', function () {
    [, , $account] = e39_creator();
    $admin = e39_admin();

    expect(e39_error(fn () => e39_monetization()->placeHold($account, $admin, 'made_up', 'ملاحظة كافية للتعليق')))->not->toBeNull()
        ->and(e39_error(fn () => e39_monetization()->placeHold($account, $admin, 'investigation', 'قصير')))->not->toBeNull()
        ->and(e39_error(fn () => e39_monetization()->placeHold($account, e39_staff_with(['creator-finance.view']), 'investigation', 'ملاحظة كافية للتعليق')))->not->toBeNull();

    $held = e39_monetization()->placeHold($account, $admin, 'investigation', 'ملاحظة كافية للتعليق');
    e39_monetization()->placeHold($account->refresh(), $admin, 'investigation', 'ملاحظة كافية للتعليق');

    expect($held->isOnHold())->toBeTrue()->and($held->hold_placed_by)->toBe($admin->id)
        ->and(e39_error(fn () => e39_monetization()->placeHold($account->refresh(), $admin, 'payout_dispute', 'سبب آخر مختلف تمامًا')))->not->toBeNull()
        ->and(OperationalAuditLog::query()->where('action', 'creator_payout_hold_placed')->count())->toBe(1);

    expect(e39_error(fn () => e39_monetization()->releaseHold($account->refresh(), $admin, 'قصير')))->not->toBeNull();

    $released = e39_monetization()->releaseHold($account->refresh(), $admin, 'انتهى التحقيق دون مخالفة');
    e39_monetization()->releaseHold($account->refresh(), $admin, 'انتهى التحقيق دون مخالفة');

    expect($released->isOnHold())->toBeFalse()->and($released->hold_note)->toBeNull()->and(OperationalAuditLog::query()->where('action', 'creator_payout_hold_released')->count())->toBe(1);
});

test('BA13-04: a hold can reference an E38 moderation case by number but never copies its details and an unknown number is refused', function () {
    [$user, , $account] = e39_creator();
    [$case] = e39_e38_case_on($user);
    $admin = e39_admin();

    expect(e39_error(fn () => e39_monetization()->placeHold($account, $admin, 'moderation_case', 'مرتبط بحالة إشراف قائمة', 'MC-NOPE-0001')))->not->toBeNull();

    $held = e39_monetization()->placeHold($account, $admin, 'moderation_case', 'مرتبط بحالة إشراف قائمة', $case->case_number);

    expect($held->hold_case_id)->toBe($case->id)->and($held->holdCase->case_number)->toBe($case->case_number)
        ->and(array_values(array_filter(Schema::getColumnListing('creator_earning_accounts'), fn ($c) => str_contains($c, 'summary') || str_contains($c, 'evidence'))))->toBe([]);
});

test('BA13-05: a hold blocks payouts but never blocks accrual - revenue shares still land in the ledger', function () {
    $admin = e39_admin();
    [$creator, , $account] = e39_creator($admin);
    e39_loose_policy();
    e39_monetization()->placeHold($account, $admin, 'investigation', 'تحقيق جارٍ في الحساب');
    e39_solve([e39_work($creator)], 3);
    e39_finalize(e39_review($admin), $admin);

    expect(e39_balances($account))->toBe([100000, 0, 0])->and(e39_error(fn () => e39_request($creator, '30.00')))->not->toBeNull();
});

test('BA13-06: reports and fraud flags alone never create a hold or change the account', function () {
    [$creator, , $account] = e39_funded_creator(3);
    $work = App\Models\CreatorPuzzle::query()->where('creator_profile_id', $account->creator_profile_id)->firstOrFail();
    e38_puzzle_report(null, $work, 'inappropriate');
    e38_puzzle_report(null, $work, 'spam');
    e38_fraud_flag($creator, 'multiple_accounts', 'high');

    expect($account->refresh()->isOnHold())->toBeFalse()->and($account->isEnabled())->toBeTrue()->and(FraudFlag::query()->where('user_id', $creator->id)->count())->toBe(1)
        ->and(e39_request($creator, '30.00')->status)->toBe('requested');
});

// ───────────────────────────── تكامل E38 ─────────────────────────────

test('BA14-01: an E38 account freeze blocks requests, approval and settlement, rejection stays possible and unfreezing restores the flow', function () {
    [$creator, , $account] = e39_funded_creator(3);
    $admin = e39_admin();
    $open = e39_request($creator, '100.00');
    e39_payouts()->approve($open, $admin);
    [$case, $mod] = e39_e38_case_on($creator);
    $action = e38_apply($case, $mod, 'freeze_user');

    expect($creator->refresh()->is_frozen)->toBeTrue()
        ->and(e39_error(fn () => e39_payouts()->settle($open->refresh(), $admin, 'REF-P-1001')))->not->toBeNull()
        ->and(e39_error(fn () => e39_request($creator, '30.00')))->not->toBeNull();

    $unfreeze = e38_apply($case->fresh(), $mod, 'unfreeze_user');

    expect($creator->refresh()->is_frozen)->toBeFalse()->and(e39_payouts()->settle($open->refresh(), $admin, 'REF-P-1001')->status)->toBe('paid')->and($action->id)->not->toBe($unfreeze->id);
});

test('BA14-02: an E38 freeze still lets staff reject an open request so the money is returned', function () {
    [$creator, , $account] = e39_funded_creator(3);
    $admin = e39_admin();
    $open = e39_request($creator, '100.00');
    [$case, $mod] = e39_e38_case_on($creator);
    e38_apply($case, $mod, 'freeze_user');

    expect(e39_payouts()->reject($open->refresh(), $admin, 'رُفض الطلب بسبب تجميد الحساب')->status)->toBe('rejected')->and(e39_balances($account))->toBe([100000, 0, 0])
        ->and(e39_notes($creator, 'creator_payout_rejected'))->toHaveCount(0);
});

test('BA14-03: an E38 creator suspension blocks approval and settlement until the creator is restored', function () {
    [$creator, $profile, $account] = e39_funded_creator(3);
    $admin = e39_admin();
    $work = App\Models\CreatorPuzzle::query()->where('creator_profile_id', $profile->id)->firstOrFail();
    $report = e38_puzzle_report(null, $work, 'inappropriate');
    $mod = e38_admin();
    $case = e38_open([$report], $mod);
    e38_cases()->claim($case, $mod);
    $open = e39_request($creator, '100.00');

    e38_apply($case->fresh(), $mod, 'suspend_creator');

    expect($profile->refresh()->isSuspended())->toBeTrue()->and(e39_error(fn () => e39_payouts()->approve($open->refresh(), $admin)))->not->toBeNull();

    e38_apply($case->fresh(), $mod, 'restore_creator');

    expect($profile->refresh()->isApproved())->toBeTrue()->and(e39_payouts()->approve($open->refresh(), $admin)->status)->toBe('approved');
});

test('BA14-04: an E38 content hide does not block accrual or payouts - monetization has its own gate', function () {
    [$creator, $profile, $account] = e39_funded_creator(3);
    $work = App\Models\CreatorPuzzle::query()->where('creator_profile_id', $profile->id)->firstOrFail();
    $report = e38_puzzle_report(null, $work, 'inappropriate');
    $mod = e38_admin();
    $case = e38_open([$report], $mod);
    e38_cases()->claim($case, $mod);
    e38_apply($case->fresh(), $mod, 'hide_content');

    expect(e39_request($creator, '100.00')->status)->toBe('requested')->and(e39_balances($account))->toBe([90000, 10000, 0]);
});
