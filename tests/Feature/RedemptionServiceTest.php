<?php

use App\Models\FraudFlag;
use App\Models\Puzzle;
use App\Models\PuzzleAttempt;
use App\Models\RedemptionRequest;
use App\Models\User;
use App\Services\RedemptionService;

// يجهّز مستخدماً "مؤهلاً بالكامل" للاستبدال وفق كل ضوابط config('gems.eligibility')
// حتى نقدر نختبر كل شرط لوحده بكسره عمداً بدل تكراره بكل اختبار.
function makeEligibleUser(): User
{
    $eligibility = config('gems.eligibility');

    $user = User::factory()->create([
        'created_at' => now()->subDays($eligibility['min_account_age_days'] + 1),
    ]);

    for ($i = 0; $i < $eligibility['min_puzzles_solved']; $i++) {
        PuzzleAttempt::create([
            'user_id' => $user->id,
            'puzzle_id' => Puzzle::factory()->create()->id,
            'attempt_number' => 1,
            'is_correct' => true,
        ]);
    }

    $user->wallet->update(['available_balance' => config('gems.min_redemption')]);

    return $user->fresh();
}

beforeEach(function () {
    $this->redemptions = app(RedemptionService::class);
});

test('a fully qualifying user is eligible for redemption', function () {
    $user = makeEligibleUser();

    $eligibility = $this->redemptions->checkEligibility($user);

    expect($eligibility['eligible'])->toBeTrue()
        ->and($eligibility['reasons'])->toBeEmpty();
});

test('an unverified email blocks eligibility', function () {
    $user = makeEligibleUser();
    $user->update(['email_verified_at' => null]);

    $eligibility = $this->redemptions->checkEligibility($user->fresh());

    expect($eligibility['eligible'])->toBeFalse()
        ->and($eligibility['reasons'])->not->toBeEmpty();
});

test('a too-new account blocks eligibility', function () {
    $user = makeEligibleUser();
    $user->update(['created_at' => now()]);

    $eligibility = $this->redemptions->checkEligibility($user->fresh());

    expect($eligibility['eligible'])->toBeFalse();
});

test('not enough solved puzzles blocks eligibility', function () {
    $user = makeEligibleUser();
    PuzzleAttempt::where('user_id', $user->id)->delete();

    $eligibility = $this->redemptions->checkEligibility($user->fresh());

    expect($eligibility['eligible'])->toBeFalse();
});

test('an unresolved fraud flag blocks eligibility', function () {
    $user = makeEligibleUser();
    FraudFlag::create([
        'user_id' => $user->id,
        'reason' => 'نشاط غير طبيعي',
        'severity' => 'medium',
        'resolved' => false,
    ]);

    $eligibility = $this->redemptions->checkEligibility($user->fresh());

    expect($eligibility['eligible'])->toBeFalse();
});

test('insufficient available balance blocks eligibility', function () {
    $user = makeEligibleUser();
    $user->wallet->update(['available_balance' => 1]);

    $eligibility = $this->redemptions->checkEligibility($user->fresh());

    expect($eligibility['eligible'])->toBeFalse();
});

test('requesting redemption creates a pending request and reserves the gems immediately', function () {
    $user = makeEligibleUser();
    $amount = config('gems.min_redemption');

    $request = $this->redemptions->requestRedemption($user, $amount, 'قسيمة شحن رصيد');

    expect($request->status)->toBe(RedemptionRequest::STATUS_PENDING)
        ->and($request->gems_amount)->toBe($amount);

    $user->wallet->refresh();
    expect($user->wallet->available_balance)->toBe(0);
});

test('requesting redemption while ineligible throws and reserves nothing', function () {
    $user = User::factory()->create(); // مستخدم جديد غير مؤهل إطلاقاً

    expect(fn () => $this->redemptions->requestRedemption($user, 10000, 'أي شيء'))
        ->toThrow(RuntimeException::class);

    expect(RedemptionRequest::where('user_id', $user->id)->count())->toBe(0);
});

test('rejecting a request refunds the reserved gems back to the available balance', function () {
    $user = makeEligibleUser();
    $admin = User::factory()->admin()->create();
    $amount = config('gems.min_redemption');

    $request = $this->redemptions->requestRedemption($user, $amount, 'قسيمة شحن رصيد');

    $this->redemptions->reject($request, $admin, 'بيانات غير مكتملة');

    $user->wallet->refresh();
    expect($user->wallet->available_balance)->toBe($amount)
        ->and($request->fresh()->status)->toBe(RedemptionRequest::STATUS_REJECTED);
});

test('approving then fulfilling a request updates status and lifetime redeemed without a second refund', function () {
    $user = makeEligibleUser();
    $admin = User::factory()->admin()->create();
    $amount = config('gems.min_redemption');

    $request = $this->redemptions->requestRedemption($user, $amount, 'قسيمة شحن رصيد');

    $this->redemptions->approve($request, $admin, 'تمت الموافقة');
    expect($request->fresh()->status)->toBe(RedemptionRequest::STATUS_APPROVED);

    $this->redemptions->markFulfilled($request, $admin, 'تم الإرسال للعميل');

    $user->wallet->refresh();
    expect($request->fresh()->status)->toBe(RedemptionRequest::STATUS_FULFILLED)
        ->and($user->wallet->lifetime_redeemed)->toBe($amount)
        // الرصيد المتاح يبقى صفراً - الجواهر خُصمت عند تقديم الطلب ولا تُخصم مرة ثانية
        ->and($user->wallet->available_balance)->toBe(0);
});