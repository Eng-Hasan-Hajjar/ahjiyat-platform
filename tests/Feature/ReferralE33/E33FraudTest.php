<?php

use App\Models\FraudFlag;
use App\Models\OperationalAuditLog;
use App\Models\User;
use App\Models\UserReferral;
use App\Services\Referrals\ReferralException;
use App\Services\Referrals\ReferralReviewService;
use App\Services\Referrals\ReferralRiskService;

require_once __DIR__.'/_support.php';

beforeEach(fn () => e33_seed());

test('AF6-38: a shared IP alone is only a signal - the referral still qualifies and nobody is flagged or frozen', function () {
    $referrer = e33_user();
    [$referred, $ref] = e33_attr($referrer);
    e33_share_network($referrer, $referred, ip: true, device: false);

    $after = e33_act($referred);

    expect($after->status)->toBe(UserReferral::STATUS_QUALIFIED)
        ->and($after->risk_signals)->toBe(['shared_ip'])
        ->and($referrer->fresh()->is_frozen)->toBeFalse()->and($referred->fresh()->is_frozen)->toBeFalse()
        ->and(FraudFlag::query()->count())->toBe(0);
});

test('AF6-39: a shared device fingerprint alone is only a signal too', function () {
    $referrer = e33_user();
    [$referred] = e33_attr($referrer);
    e33_share_network($referrer, $referred, ip: false, device: true);

    $after = e33_act($referred);

    expect($after->status)->toBe(UserReferral::STATUS_QUALIFIED)->and($after->risk_signals)->toBe(['shared_device'])->and(FraudFlag::query()->count())->toBe(0);
});

test('AF6-40: several weak signals together hold the referral and the reward for review - without freezing, flagging or punishing anyone', function () {
    $referrer = e33_user();
    [$referred, $ref] = e33_attr($referrer, e33_reward_campaign());
    e33_share_network($referrer, $referred);

    $after = e33_act($referred);

    expect($after->status)->toBe(UserReferral::STATUS_PENDING_REVIEW)
        ->and($after->held_at)->not->toBeNull()
        ->and($after->qualified_at)->toBeNull()
        ->and($after->reward_status)->toBe(UserReferral::REWARD_NONE)
        ->and($after->risk_signals)->toEqualCanonicalizing(['shared_ip', 'shared_device'])
        ->and(e33_ledger())->toHaveCount(0)
        ->and($referrer->fresh()->is_frozen)->toBeFalse()->and($referred->fresh()->is_frozen)->toBeFalse()
        ->and(FraudFlag::query()->count())->toBe(0)
        ->and(\Illuminate\Notifications\DatabaseNotification::query()->count())->toBe(0);

    // لا تأهيل تلقائي لاحقًا للمعلّقة (لا بالحدث ولا بالأمر الدوري)
    e33_act($referred);
    $this->artisan('referrals:process')->assertSuccessful();
    expect($ref->fresh()->status)->toBe(UserReferral::STATUS_PENDING_REVIEW)->and(e33_ledger())->toHaveCount(0);
});

test('AF6-41: a strong signal holds the referral on its own - an open high fraud flag or a frozen referrer - while resolved or medium flags do not', function () {
    $svc = app(\App\Services\FraudDetectionService::class);

    $a = e33_user();
    $svc->flag($a, 'abnormal_earn_rate', 'high', 'x');
    [$u1] = e33_attr($a);
    $held = e33_act($u1);
    expect($held->status)->toBe(UserReferral::STATUS_PENDING_REVIEW)->and($held->risk_signals)->toContain('referrer_high_fraud_flag');

    $b = e33_user();
    $flag = $svc->flag($b, 'abnormal_earn_rate', 'high', 'x');
    $svc->resolve($flag, e33_admin(), 'ok');
    [$u2] = e33_attr($b);
    expect(e33_act($u2)->status)->toBe(UserReferral::STATUS_QUALIFIED);

    $c = e33_user();
    $svc->flag($c, 'ip_shared', 'medium', 'x');
    [$u3] = e33_attr($c);
    expect(e33_act($u3)->status)->toBe(UserReferral::STATUS_QUALIFIED);

    $d = e33_user();
    [$u4] = e33_attr($d);
    $d->forceFill(['is_frozen' => true])->save();
    $frozenHold = e33_act($u4);
    expect($frozenHold->status)->toBe(UserReferral::STATUS_PENDING_REVIEW)->and($frozenHold->risk_signals)->toContain('referrer_frozen')->and($d->fresh()->is_frozen)->toBeTrue();
});

test('AF6-42: a reviewer can approve a held referral once - the reward is then planned and delivered normally', function () {
    $referrer = e33_user();
    [$referred, $ref] = e33_attr($referrer, e33_reward_campaign());
    e33_share_network($referrer, $referred);
    e33_act($referred);
    $review = app(ReferralReviewService::class);

    expect(fn () => $review->approve(e33_user(), $ref->fresh()))->toThrow(ReferralException::class);
    expect(fn () => $review->approve(e33_manager(), $ref->fresh()))->toThrow(ReferralException::class); // مدير المحتوى بلا review

    $moderator = e33_moderator();
    $approved = $review->approve($moderator, $ref->fresh(), 'شبكة جامعة');

    expect($approved->status)->toBe(UserReferral::STATUS_REWARDED)
        ->and($approved->reviewed_by)->toBe($moderator->id)
        ->and($approved->review_note)->toBe('شبكة جامعة')
        ->and($approved->qualified_at)->not->toBeNull()
        ->and(e33_ledger())->toHaveCount(2)
        ->and(e33_wallet($referrer)['pending'])->toBe(20)
        ->and(OperationalAuditLog::query()->where('action', 'referral_review_approved')->count())->toBe(1);

    expect(fn () => $review->approve($moderator, $ref->fresh()))->toThrow(ReferralException::class);

    // غير الموقوفة لا تُعتمد
    [$plain] = e33_attr($referrer);
    expect(fn () => $review->approve($moderator, UserReferral::query()->where('referred_user_id', $plain->id)->first()))->toThrow(ReferralException::class);
});

test('AF6-43: disqualification needs a structured reason and never silently debits a rewarded referral', function () {
    $referrer = e33_user();
    $admin = e33_admin();
    $review = app(ReferralReviewService::class);

    [$rewardedUser, $rewarded] = e33_attr($referrer, e33_reward_campaign());
    e33_act($rewardedUser);
    $walletBefore = [e33_wallet($referrer), e33_wallet($rewardedUser)];
    $ledgerBefore = CurrencyTransactionCount();

    expect(fn () => $review->disqualify($admin, $rewarded->fresh(), 'not_a_reason'))->toThrow(ReferralException::class)
        ->and(fn () => $review->disqualify($admin, $rewarded->fresh(), 'fake_accounts'))->toThrow(ReferralException::class); // يلزم إقرار الإبقاء

    $done = $review->disqualify($admin, $rewarded->fresh(), 'fake_accounts', 'حسابات متكررة', true);

    expect($done->status)->toBe(UserReferral::STATUS_DISQUALIFIED)
        ->and($done->disqualified_reason)->toBe('fake_accounts')
        ->and($done->reviewed_by)->toBe($admin->id)
        ->and([e33_wallet($referrer), e33_wallet($rewardedUser)])->toBe($walletBefore)
        ->and(CurrencyTransactionCount())->toBe($ledgerBefore)
        ->and(e33_ledger())->toHaveCount(2); // الدفتر كما هو: لا خصم صامت
    $audit = OperationalAuditLog::query()->where('action', 'referral_review_disqualified')->latest('id')->first();
    expect($audit->metadata['ledger_untouched'])->toBeTrue()->and($audit->metadata['rewards_kept'])->toEqual(['referrer' => 20, 'referred' => 10]); // MySQL JSON يعيد ترتيب المفاتيح

    // لم تُكافأ بعد: الاستبعاد يوقف أي تسليم لاحق
    [$pendingUser, $pending] = e33_attr($referrer, e33_reward_campaign());
    $review->disqualify($admin, $pending->fresh(), 'policy_violation');
    e33_act($pendingUser);
    app(\App\Services\Referrals\ReferralRewardService::class)->deliver($pending->id);

    expect($pending->fresh()->reward_skip_reason)->toBe('disqualified')->and(e33_ledger())->toHaveCount(2)
        ->and(fn () => $review->disqualify($admin, $pending->fresh(), 'other'))->toThrow(ReferralException::class);
});

function CurrencyTransactionCount(): int
{
    return \App\Models\CurrencyTransaction::query()->count();
}

test('AF6-44: a burst of invitations plus a look-alike email hold the referral, and one signal alone does not', function () {
    $risk = app(ReferralRiskService::class);
    expect($risk->emailRoot('A.B+promo@Gmail.com'))->toBe('ab@gmail.com')
        ->and($risk->emailRoot('x.y@example.test'))->toBe('x.y@example.test')
        ->and($risk->emailRoot('x+1@example.test'))->toBe('x@example.test');

    $referrer = e33_user(['email' => 'ab@gmail.com']);
    foreach (range(1, 5) as $i) {
        e33_attr($referrer);
    }

    // السادسة بنفس الساعة (burst) وبريد مشابه (similar_email) ⇒ إشارتان ⇒ تعليق
    [$sixth, $ref] = e33_attr($referrer, null, ['email' => 'a.b+x@gmail.com']);
    $after = e33_act($sixth);
    expect($after->status)->toBe(UserReferral::STATUS_PENDING_REVIEW)->and($after->risk_signals)->toEqualCanonicalizing(['referrer_burst', 'similar_email']);

    // إجراء سريع جدًا وحده (fast_action) إشارة واحدة ⇒ يتأهل
    $solo = e33_user();
    [$fast] = e33_attr($solo, null, [], 0);
    DB::table('users')->where('id', $fast->id)->update(['created_at' => now()]);
    $quick = e33_act($fast->fresh());
    expect($quick->status)->toBe(UserReferral::STATUS_QUALIFIED)->and($quick->risk_signals)->toBe(['fast_action']);
});
