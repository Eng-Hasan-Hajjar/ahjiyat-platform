<?php

use App\Models\CommerceProviderEvent;
use App\Models\DigitalPurchase;
use App\Services\Commerce\CommerceProviderState;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/_support.php';

/*
|--------------------------------------------------------------------------
| E41 (BE6 + BE7) - التأكيد الآلي عند تطابق تام فقط، والتسلسل غير المرتّب
|--------------------------------------------------------------------------
*/

beforeEach(fn () => e41_seed());

test('BE6-36: an exact paid event verifies the order through the one shared verify path and opens Premium', function () {
    $h = e41_hosted();

    $result = e41_pay($h['session']);
    $order = $h['purchase']->fresh();
    $row = e40_row($h['user'], $h['build']['pass']);

    expect($result)->toMatchArray(['result' => 'applied', 'detail' => 'verified'])->and($order->status)->toBe(DigitalPurchase::STATUS_VERIFIED)->and($order->verified_at)->not->toBeNull()
        ->and($order->verified_by)->toBeNull()->and($order->provider_status)->toBe('paid')
        ->and($row->premium_unlocked_at)->not->toBeNull()->and((int) $row->premium_purchase_id)->toBe($order->id)
        ->and(DB::table('digital_purchase_events')->where('digital_purchase_id', $order->id)->where('event', 'verified')->value('note'))->toContain('provider');
});

test('BE6-37: Premium unlocks exactly once', function () {
    $h = e41_hosted();
    e41_pay($h['session']);
    $unlockedAt = e40_row($h['user'], $h['build']['pass'])->premium_unlocked_at;

    \Carbon\Carbon::setTestNow(now()->addHour());
    e41_deliver($h['session'], 'payment.paid', [], 'again');

    expect(e40_row($h['user'], $h['build']['pass'])->premium_unlocked_at->equalTo($unlockedAt))->toBeTrue()
        ->and(DB::table('digital_purchase_events')->where('digital_purchase_id', $h['purchase']->id)->where('event', 'verified')->count())->toBe(1);
});

test('BE6-38: a duplicate paid event (new event id, same payment) causes no second unlock and is ignored', function () {
    $h = e41_hosted();
    e41_pay($h['session']);

    $second = e41_deliver($h['session'], 'payment.paid', [], 'dup-1');
    $third = e41_deliver($h['session'], 'payment.paid', [], 'dup-2');

    expect($second)->toMatchArray(['result' => 'ignored', 'detail' => 'already_verified'])->and($third['result'])->toBe('ignored')
        ->and($h['purchase']->fresh()->status)->toBe(DigitalPurchase::STATUS_VERIFIED)->and(DB::table('user_pass_progress')->whereNotNull('premium_unlocked_at')->count())->toBe(1)
        ->and(CommerceProviderEvent::query()->count())->toBe(3);
});

test('BE6-39: duplicate paid events send no duplicate notification', function () {
    $h = e41_hosted();
    e41_pay($h['session']);
    e41_deliver($h['session'], 'payment.paid', [], 'dup-1');
    $event = e41_sandbox()->signedEvent($h['session'], 'payment.paid');
    e41_webhooks()->handle('sandbox', $event['payload'], $event['headers']);

    expect(e40_notes($h['user'], 'pass_premium_unlocked'))->toHaveCount(1)->and(e40_notes($h['user'], 'commerce_action_required'))->toHaveCount(0);
});

test('BE6-40: the provider payment and session references are stored on the order', function () {
    $h = e41_hosted();
    e41_pay($h['session']);
    $order = $h['purchase']->fresh();

    expect($order->provider_session_id)->toBe($h['session'])->and($order->provider_payment_reference)->toStartWith('sbxpay_')->and($order->provider_payment_reference)->toHaveLength(31);
});

test('BE6-41: the provider paid timestamp is stored from the event', function () {
    $h = e41_hosted();
    $event = e41_sandbox()->simulate($h['session'], CommerceProviderState::Paid, [], null);
    e41_webhooks()->handle('sandbox', $event['payload'], $event['headers']);
    $order = $h['purchase']->fresh();

    expect($order->provider_paid_at)->not->toBeNull()->and($order->provider_paid_at->equalTo(now()->startOfSecond()))->toBeTrue();
});

test('BE6-42: an amount mismatch never unlocks — it raises a review flag, tells the buyer it is under review, and keeps the order awaiting', function () {
    $h = e41_hosted();

    $result = e41_pay($h['session'], ['amount_minor' => 499]);
    $order = $h['purchase']->fresh();

    expect($result)->toMatchArray(['result' => 'needs_review', 'detail' => 'amount_mismatch'])->and($order->status)->toBe(DigitalPurchase::STATUS_AWAITING)
        ->and($order->review_flag)->toBe('amount_mismatch')->and($order->needsReview())->toBeTrue()->and($order->provider_status)->toBe('paid')
        ->and(e40_row($h['user'], $h['build']['pass'])?->premium_unlocked_at)->toBeNull()
        ->and(e40_notes($h['user'], 'commerce_action_required'))->toHaveCount(1)->and(e40_notes($h['user'], 'pass_premium_unlocked'))->toHaveCount(0);

    // مبلغ أكبر من اللازم كذلك ليس «مطابقًا».
    $h2 = e41_hosted($h['build'], e40_player($h['build']['pass']));
    expect(e41_pay($h2['session'], ['amount_minor' => 501])['detail'])->toBe('amount_mismatch');
});

test('BE6-43: a currency mismatch never unlocks', function () {
    $h = e41_hosted();

    $result = e41_pay($h['session'], ['currency' => 'EUR']);

    expect($result)->toMatchArray(['result' => 'needs_review', 'detail' => 'currency_mismatch'])->and($h['purchase']->fresh()->review_flag)->toBe('currency_mismatch')
        ->and($h['purchase']->fresh()->status)->toBe(DigitalPurchase::STATUS_AWAITING)->and(e40_row($h['user'], $h['build']['pass'])?->premium_unlocked_at)->toBeNull();
});

test('BE6-44: a wrong reference never unlocks — right session with a foreign reference, or foreign session with this reference', function () {
    $h = e41_hosted();
    $other = e41_hosted($h['build'], e40_player($h['build']['pass']));

    $wrong = e41_pay($h['session'], ['reference' => $other['purchase']->reference_code]);

    expect($wrong)->toMatchArray(['result' => 'needs_review', 'detail' => 'reference_conflict'])->and($h['purchase']->fresh()->status)->toBe(DigitalPurchase::STATUS_AWAITING)
        ->and($other['purchase']->fresh()->status)->toBe(DigitalPurchase::STATUS_AWAITING)->and(e40_row($h['user'], $h['build']['pass'])?->premium_unlocked_at)->toBeNull();

    $third = e41_hosted($h['build'], e40_player($h['build']['pass']));
    $event = e41_sandbox()->simulate($third['session'], CommerceProviderState::Paid, ['session_id' => 'sbx_'.str_repeat('c', 24), 'reference' => $third['purchase']->reference_code]);
    $result = e41_webhooks()->handle('sandbox', $event['payload'], $event['headers']);

    expect($result['result'])->toBe('needs_review')->and($third['purchase']->fresh()->status)->toBe(DigitalPurchase::STATUS_AWAITING);
});

test('BE6-45: a paid event for an unknown purchase unlocks nothing and goes to review with no purchase attached', function () {
    $before = DB::table('user_pass_progress')->whereNotNull('premium_unlocked_at')->count();
    $event = e41_sandbox()->signedEvent('sbx_'.str_repeat('d', 24), 'payment.paid', ['reference' => 'GHOST12345', 'amount_minor' => 500, 'currency' => 'USD']);

    $result = e41_webhooks()->handle('sandbox', $event['payload'], $event['headers']);

    expect($result)->toMatchArray(['result' => 'needs_review', 'detail' => 'unknown_purchase'])->and(DB::table('user_pass_progress')->whereNotNull('premium_unlocked_at')->count())->toBe($before)
        ->and(CommerceProviderEvent::query()->firstOrFail()->digital_purchase_id)->toBeNull();
});

test('BE6-46: a paid event on an invalid state is handled deterministically — a purchase of another provider is rejected, and the answer repeats as a duplicate', function () {
    $b = e41_world();
    $user = e40_player($b['pass']);
    $manual = e40_premium()->request($user, $b['pass'], null, true);
    $event = e41_sandbox()->signedEvent('sbx_'.str_repeat('e', 24), 'payment.paid', ['reference' => $manual->reference_code, 'amount_minor' => 500, 'currency' => 'USD']);

    $first = e41_webhooks()->handle('sandbox', $event['payload'], $event['headers']);
    $second = e41_webhooks()->handle('sandbox', $event['payload'], $event['headers']);

    expect($manual->provider)->toBe('manual')->and($first)->toMatchArray(['result' => 'rejected', 'detail' => 'provider_mismatch'])->and($second['result'])->toBe('duplicate')
        ->and($manual->fresh()->status)->toBe(DigitalPurchase::STATUS_AWAITING)->and(e40_row($user, $b['pass'])?->premium_unlocked_at)->toBeNull();
});

test('BE6-x: failed then paid on the same session still verifies once (the buyer retried inside the session) and the failure notice is sent once', function () {
    $h = e41_hosted();
    $failed = e41_state(CommerceProviderState::Failed, $h['session']);

    expect($failed['result'])->toBe('applied')->and($h['purchase']->fresh()->provider_status)->toBe('failed')->and($h['purchase']->fresh()->status)->toBe(DigitalPurchase::STATUS_AWAITING)
        ->and(e40_notes($h['user'], 'commerce_failed'))->toHaveCount(1);

    e41_pay($h['session']);

    expect($h['purchase']->fresh()->status)->toBe(DigitalPurchase::STATUS_VERIFIED)->and($h['purchase']->fresh()->provider_status)->toBe('paid');
});

test('BE7-47: a pending event after paid cannot regress the state', function () {
    $h = e41_paid();

    $result = e41_deliver($h['session'], 'payment.pending', [], 'late-pending');

    expect($result['result'])->toBe('ignored')->and($h['purchase']->fresh()->status)->toBe(DigitalPurchase::STATUS_VERIFIED)->and($h['purchase']->fresh()->provider_status)->toBe('paid');
});

test('BE7-48: a failed event after paid cannot regress a verified order', function () {
    $h = e41_paid();

    $result = e41_deliver($h['session'], 'payment.failed', [], 'late-failed');
    $cancel = e41_deliver($h['session'], 'payment.cancelled', [], 'late-cancel');

    expect($result)->toMatchArray(['result' => 'ignored', 'detail' => 'stale_state'])->and($cancel['result'])->toBe('ignored')
        ->and($h['purchase']->fresh()->status)->toBe(DigitalPurchase::STATUS_VERIFIED)->and($h['purchase']->fresh()->provider_status)->toBe('paid')
        ->and(e40_row($h['user'], $h['build']['pass'])->premium_unlocked_at)->not->toBeNull()->and(e40_notes($h['user'], 'commerce_failed'))->toHaveCount(0);
});

test('BE7-49: a paid event after a refund cannot re-open the order — same payment is stale; a different payment goes to review', function () {
    $h = e41_paid();
    e40_premium()->refund(e40_admin(), $h['purchase']->fresh(), 'user_request', 'استرداد اختبار');
    expect($h['purchase']->fresh()->status)->toBe(DigitalPurchase::STATUS_REFUNDED);

    $stale = e41_deliver($h['session'], 'payment.paid', [], 'stale-paid');
    $other = e41_deliver($h['session'], 'payment.paid', ['payment_id' => 'sbxpay_other_payment'], 'other-paid');

    expect($stale)->toMatchArray(['result' => 'ignored', 'detail' => 'stale_paid_after_refund'])->and($other['result'])->toBe('needs_review')
        ->and($h['purchase']->fresh()->status)->toBe(DigitalPurchase::STATUS_REFUNDED)->and($h['purchase']->fresh()->review_flag)->toBe('paid_after_refund')
        ->and(e40_row($h['user'], $h['build']['pass'])->premium_unlocked_at)->toBeNull();
});

test('BE7-50: a duplicate refunded event is harmless', function () {
    $h = e41_paid();
    e40_premium()->refund(e40_admin(), $h['purchase']->fresh(), 'user_request', 'استرداد اختبار');
    $refundedAt = $h['purchase']->fresh()->refunded_at;

    $a = e41_deliver($h['session'], 'payment.refunded', [], 'ext-1');
    $b = e41_deliver($h['session'], 'payment.refunded', [], 'ext-2');

    expect($a)->toMatchArray(['result' => 'ignored', 'detail' => 'duplicate_refund'])->and($b['result'])->toBe('ignored')->and($h['purchase']->fresh()->refunded_at->equalTo($refundedAt))->toBeTrue()
        ->and(DB::table('digital_purchase_events')->where('digital_purchase_id', $h['purchase']->id)->where('event', 'refunded')->count())->toBe(1);
});

test('BE7-51: a locally cancelled order that later receives a paid event goes to review and never unlocks', function () {
    $h = e41_hosted();
    e40_premium()->cancelByOwner($h['user'], $h['purchase']);
    expect($h['purchase']->fresh()->status)->toBe(DigitalPurchase::STATUS_CANCELLED);

    $result = e41_pay($h['session']);
    $order = $h['purchase']->fresh();

    expect($result)->toMatchArray(['result' => 'needs_review', 'detail' => 'late_paid'])->and($order->status)->toBe(DigitalPurchase::STATUS_CANCELLED)->and($order->review_flag)->toBe('late_paid_cancelled')
        ->and(e40_row($h['user'], $h['build']['pass'])?->premium_unlocked_at)->toBeNull()->and($order->provider_payment_reference)->not->toBeNull();
});

test('BE7-52: a locally expired order that later receives a paid event goes to review and never unlocks; expiry skips a flagged order', function () {
    $h = e41_hosted();
    DB::table('digital_purchases')->where('id', $h['purchase']->id)->update(['expires_at' => now()->subMinute()]);
    e40_premium()->expireStale();
    expect($h['purchase']->fresh()->status)->toBe(DigitalPurchase::STATUS_EXPIRED);

    $result = e41_pay($h['session']);

    expect($result['result'])->toBe('needs_review')->and($h['purchase']->fresh()->review_flag)->toBe('late_paid_expired')->and($h['purchase']->fresh()->status)->toBe(DigitalPurchase::STATUS_EXPIRED)
        ->and(e40_row($h['user'], $h['build']['pass'])?->premium_unlocked_at)->toBeNull();

    // الطلب المعلَّم (مدفوع عند المزوّد وينتظر قرارًا) لا ينتهي آليًا.
    $g = e41_hosted($h['build'], e40_player($h['build']['pass']));
    e41_pay($g['session'], ['amount_minor' => 1]);
    DB::table('digital_purchases')->where('id', $g['purchase']->id)->update(['expires_at' => now()->subHour()]);

    expect(e40_premium()->expireStale())->toBe(0)->and($g['purchase']->fresh()->status)->toBe(DigitalPurchase::STATUS_AWAITING);
});
