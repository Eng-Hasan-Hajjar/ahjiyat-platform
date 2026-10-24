<?php

use App\Filament\Pages\AhjiyatPassPurchasesPage;
use App\Models\DigitalPurchase;
use App\Models\UserInventoryItem;
use App\Models\UserPassRewardClaim;
use App\Services\Commerce\CommerceProviderState;
use App\Services\Commerce\SandboxCommercePaymentProvider;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/_support.php';

/*
|--------------------------------------------------------------------------
| E41 (BE8 + BE9) - المزوّد اليدوي (E40) بلا تغيير، واسترداد كامل عبر المزوّد
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    e41_seed();
    Storage::fake('local');
});

function e41_track(array $over = []): array
{
    return e40_build($over + ['tiers' => 12, 'step' => 100, 'free_tiers' => range(1, 12), 'premium_tiers' => range(1, 12), 'missions' => [['difficulty' => 'easy', 'points' => 100, 'max' => 14]]]);
}

test('BE8-53: the E40 manual flow still works end to end — request, submit a transfer reference, finance verifies, Premium opens', function () {
    config(['commerce.default_provider' => 'manual']);
    $b = e41_world();
    $user = e40_player($b['pass']);

    $order = e40_premium()->request($user, $b['pass']);
    $submitted = e40_premium()->submit($user, $order, 'TRX-MANUAL-1', 'تحويل');
    $verified = e40_premium()->verify($b['admin'], $submitted, 'تحقق يدوي');

    expect($order->provider)->toBe('manual')->and($submitted->status)->toBe('submitted')->and($verified->status)->toBe('verified')->and($verified->verified_by)->toBe($b['admin']->id)
        ->and(e40_row($user, $b['pass'])->premium_unlocked_at)->not->toBeNull()->and(SandboxCommercePaymentProvider::counter('checkout_calls'))->toBe(0);

    // ومع مزوّد افتراضي مستضاف يبقى التحويل اليدوي خيارًا صريحًا للمشتري.
    config(['commerce.default_provider' => 'sandbox']);
    $other = e40_player($b['pass']);
    $manual = e40_premium()->request($other, $b['pass'], null, true);

    expect($manual->provider)->toBe('manual');
});

test('BE8-54: proof rules are unchanged — private hashed proof, unsupported files refused, and a hosted order refuses a manual submission', function () {
    config(['commerce.default_provider' => 'manual']);
    $b = e41_world();
    $user = e40_player($b['pass']);
    $order = e40_premium()->request($user, $b['pass']);

    expect(e40_reason(fn () => e40_premium()->submit($user, $order, 'TRX-BAD', null, UploadedFile::fake()->create('proof.exe', 10, 'application/x-msdownload'))))->toBe('proof_invalid');

    $ok = e40_premium()->submit($user, $order, 'TRX-GOOD', null, UploadedFile::fake()->image('proof.png', 40, 40));

    expect($ok->proof_sha256)->toHaveLength(64)->and(Storage::disk('local')->exists($ok->proof_path))->toBeTrue()->and(Storage::disk('public')->exists($ok->proof_path))->toBeFalse();

    config(['commerce.default_provider' => 'sandbox']);
    $buyer = e40_player($b['pass']);
    $hosted = e40_premium()->request($buyer, $b['pass']);

    expect($hosted->provider)->toBe('sandbox')->and(e40_reason(fn () => e40_premium()->submit($buyer, $hosted, 'TRX-NO', null)))->toBe('hosted_purchase');
});

test('BE8-55: finance verifies a manual purchase from the page exactly as before', function () {
    config(['commerce.default_provider' => 'manual']);
    $b = e41_world();
    $buyer = e40_player($b['pass']);
    $order = e40_premium()->submit($buyer, e40_premium()->request($buyer, $b['pass']), 'TRX-PAGE-1', null);
    $this->actingAs(e40_staff_with(['passes.view-purchases', 'passes.verify-purchases']));

    Livewire::test(AhjiyatPassPurchasesPage::class)->call('show', $order->public_id)->set('reviewNote', 'مطابقة المبلغ')->call('verify');

    expect($order->fresh()->status)->toBe('verified')->and(e40_row($buyer, $b['pass'])->premium_unlocked_at)->not->toBeNull();
});

test('BE8-56: manual refund semantics are unchanged — recorded in the platform after an external refund, with no provider call', function () {
    config(['commerce.default_provider' => 'manual']);
    $b = e41_track();
    $user = e40_player();
    $order = e40_buy_premium($user, $b['pass']);

    $refunded = e40_premium()->refund($b['admin'], $order, 'user_request', 'استرداد خارجي تم يدويًا');

    expect($refunded->status)->toBe('refunded')->and($refunded->provider_refund_reference)->toBeNull()->and($refunded->refund_requested_at)->toBeNull()
        ->and(SandboxCommercePaymentProvider::counter('refund_calls'))->toBe(0)->and(e40_row($user, $b['pass'])->premium_unlocked_at)->toBeNull()
        ->and(e40_reason(fn () => e40_premium()->refund($b['admin'], $order->fresh(), 'user_request', 'ثانية')))->not->toBeNull();
});

test('BE8-57: the manual provider has no hosted redirect — checkout is refused and the page shows the manual form, not the pay button', function () {
    config(['commerce.default_provider' => 'manual']);
    $b = e41_world();
    $user = e40_player($b['pass']);
    $order = e40_premium()->request($user, $b['pass']);

    expect(e41_reason(fn () => e41_checkout()->start($user, $order)))->toBe('not_hosted');

    $this->actingAs($user)->post(route('commerce.checkout.start', $order->public_id))->assertRedirect(route('pass.orders.show', $order->public_id))->assertSessionHas('error');
    $this->actingAs($user)->get(route('pass.orders.show', $order->public_id))->assertOk()->assertSee('data-pass-submit', false)->assertDontSee('data-order-continue', false)->assertDontSee('data-pass-hosted', false);
});

test('BE9-58: the provider refund is invoked exactly once; a second attempt after success is refused without calling the provider', function () {
    $h = e41_paid(e41_track());

    $refunded = e40_premium()->refund(e40_admin(), $h['purchase'], 'user_request', 'طلب المشتري');

    expect($refunded->status)->toBe('refunded')->and(SandboxCommercePaymentProvider::counter('refund_calls'))->toBe(1)->and(SandboxCommercePaymentProvider::counter('refunds_effective'))->toBe(1)
        ->and(e40_reason(fn () => e40_premium()->refund(e40_admin(), $h['purchase']->fresh(), 'user_request', 'ثانية')))->not->toBeNull()
        ->and(SandboxCommercePaymentProvider::counter('refund_calls'))->toBe(1);
});

test('BE9-59: a failed provider refund never marks the order refunded — Premium stays, the error is recorded, a retry later succeeds', function () {
    $b = e41_track();
    $h = e41_paid($b);
    SandboxCommercePaymentProvider::forceRefundMode('failed');

    $reason = e40_reason(fn () => e40_premium()->refund($b['admin'], $h['purchase'], 'user_request', 'طلب المشتري'));
    $order = $h['purchase']->fresh();

    expect($reason)->toBe('refund_failed')->and($order->status)->toBe('verified')->and($order->refund_error)->toBe('sandbox_refund_failed')->and($order->refunded_at)->toBeNull()
        ->and(e40_row($h['user'], $b['pass'])->premium_unlocked_at)->not->toBeNull()
        ->and(DB::table('digital_purchase_events')->where('digital_purchase_id', $order->id)->where('event', 'refund_failed')->count())->toBe(1);

    SandboxCommercePaymentProvider::forceRefundMode(null);
    $done = e40_premium()->refund($b['admin'], $order, 'user_request', 'طلب المشتري');

    expect($done->status)->toBe('refunded')->and($done->refund_error)->toBeNull()->and((int) $done->refund_attempts)->toBe(2);
});

test('BE9-60: a confirmed provider refund marks the order refunded once, stores the refund reference and notifies once', function () {
    $b = e41_track();
    $h = e41_paid($b);

    $done = e40_premium()->refund($b['admin'], $h['purchase'], 'payment_issue', 'دفعة معكوسة');

    expect($done->status)->toBe('refunded')->and($done->provider_refund_reference)->toStartWith('sbxre_')->and($done->provider_status)->toBe('refunded')->and($done->refunded_at)->not->toBeNull()
        ->and((int) $done->refunded_by)->toBe($b['admin']->id)
        ->and(DB::table('digital_purchase_events')->where('digital_purchase_id', $done->id)->where('event', 'refunded')->count())->toBe(1)
        ->and(e40_notes($h['user'], 'commerce_refunded'))->toHaveCount(1);
});

test('BE9-61: duplicate refund attempts are safe — a pending refund blocks a second click, the stable provider key makes a retry idempotent, and the refunded webhook completes it once', function () {
    $b = e41_track();
    $h = e41_paid($b);
    SandboxCommercePaymentProvider::forceRefundMode('pending');

    $first = e40_premium()->refund($b['admin'], $h['purchase'], 'user_request', 'طلب المشتري');

    expect($first->status)->toBe('verified')->and($first->refund_requested_at)->not->toBeNull()
        ->and(e40_reason(fn () => e40_premium()->refund($b['admin'], $first->fresh(), 'user_request', 'نقرة ثانية')))->toBe('refund_in_progress')
        ->and(SandboxCommercePaymentProvider::counter('refund_calls'))->toBe(1);

    // بعد انقضاء نافذة «قيد التنفيذ» تُعاد المحاولة بالمفتاح الثابت نفسه ⇒ المزوّد يعيد الاسترداد نفسه (مرة فعّالة واحدة).
    \Carbon\Carbon::setTestNow(now()->addMinutes(5));
    e40_premium()->refund($b['admin'], $first->fresh(), 'user_request', 'إعادة');

    expect(SandboxCommercePaymentProvider::counter('refund_calls'))->toBe(2)->and(SandboxCommercePaymentProvider::counter('refunds_effective'))->toBe(1);

    $done = e41_deliver($h['session'], 'payment.refunded', [], 'wh-refund');
    $again = e41_deliver($h['session'], 'payment.refunded', [], 'wh-refund-2');

    expect($done['result'])->toBe('applied')->and($again['result'])->toBe('ignored')->and($h['purchase']->fresh()->status)->toBe('refunded')
        ->and(DB::table('digital_purchase_events')->where('digital_purchase_id', $h['purchase']->id)->where('event', 'refunded')->count())->toBe(1)->and(e40_notes($h['user'], 'commerce_refunded'))->toHaveCount(1);
});

test('BE9-62: Premium access reversal follows E40 — premium locks, free progress stays', function () {
    $b = e41_track();
    $user = e40_player();
    e40_grant($user, $b['pass'], $b['quests'][0], 4);
    $h = e41_paid($b, $user);

    e40_premium()->refund($b['admin'], $h['purchase'], 'user_request', 'طلب المشتري');
    $row = e40_row($user, $b['pass']);

    expect($row->premium_unlocked_at)->toBeNull()->and($row->premium_purchase_id)->toBeNull()->and($row->pass_points)->toBe(400)->and($row->highest_tier)->toBe(5)
        ->and(e40_reason(fn () => e40_claims()->claim($user, $b['pass'], (int) $b['rewards'][2]['premium']->id)))->toBe('premium_required');
});

test('BE9-63: the claimed-reward policy is preserved — confirmation is required BEFORE the provider is called, and items are never pulled', function () {
    $b = e41_track();
    $user = e40_player();
    e40_grant($user, $b['pass'], $b['quests'][0], 4);
    $h = e41_paid($b, $user);
    e40_claims()->collectAll($user, $b['pass']);
    $claims = UserPassRewardClaim::query()->where('track', 'premium')->count();
    $items = (int) UserInventoryItem::query()->where('user_id', $user->id)->sum('quantity');

    expect($claims)->toBe(5)->and(e40_reason(fn () => e40_premium()->refund($b['admin'], $h['purchase']->fresh(), 'fraud', 'مراجعة مالية')))->toBe('claimed_requires_confirmation')
        ->and(SandboxCommercePaymentProvider::counter('refund_calls'))->toBe(0)->and($h['purchase']->fresh()->status)->toBe('verified');

    $done = e40_premium()->refund($b['admin'], $h['purchase']->fresh(), 'fraud', 'مراجعة مالية', true);

    expect($done->status)->toBe('refunded')->and($done->refund_kept_items)->toBe(5)->and((int) UserInventoryItem::query()->where('user_id', $user->id)->sum('quantity'))->toBe($items)
        ->and(UserPassRewardClaim::query()->where('track', 'premium')->count())->toBe($claims);
});

test('BE9-64: the historical purchase is retained after a refund — row, events, ledger rows, and the buyer cannot be deleted', function () {
    $b = e41_track();
    $h = e41_paid($b);
    e40_premium()->refund($b['admin'], $h['purchase'], 'duplicate', 'طلب مكرر');

    expect(DigitalPurchase::query()->find($h['purchase']->id)->status)->toBe('refunded')->and(DB::table('digital_purchase_events')->where('digital_purchase_id', $h['purchase']->id)->count())->toBeGreaterThanOrEqual(5)
        ->and(\App\Models\CommerceProviderEvent::query()->where('digital_purchase_id', $h['purchase']->id)->count())->toBe(1)
        ->and(fn () => DB::table('users')->where('id', $h['user']->id)->delete())->toThrow(\Illuminate\Database\QueryException::class)
        ->and(fn () => DB::table('digital_purchases')->where('id', $h['purchase']->id)->delete())->toThrow(\Illuminate\Database\QueryException::class)
        ->and(DB::table('digital_purchases')->where('id', $h['purchase']->id)->exists())->toBeTrue();
});

test('BE9-x: a provider-initiated refund (no admin request) refunds a verified order with no claims, or raises a review flag when items were claimed', function () {
    $b = e41_track();
    $clean = e41_paid($b);
    e41_deliver($clean['session'], 'payment.refunded', [], 'ext-refund');

    expect($clean['purchase']->fresh()->status)->toBe('refunded')->and(e40_row($clean['user'], $b['pass'])->premium_unlocked_at)->toBeNull();

    $user = e40_player();
    e40_grant($user, $b['pass'], $b['quests'][0], 4);
    $claimed = e41_paid($b, $user);
    e40_claims()->collectAll($user, $b['pass']);
    $result = e41_deliver($claimed['session'], 'payment.refunded', [], 'ext-refund-2');
    $order = $claimed['purchase']->fresh();

    expect($result['result'])->toBe('needs_review')->and($order->status)->toBe('verified')->and($order->review_flag)->toBe('refund_claimed_items')->and($order->provider_status)->toBe('refunded')
        ->and(e40_notes($user, 'commerce_action_required'))->toHaveCount(1);
});
