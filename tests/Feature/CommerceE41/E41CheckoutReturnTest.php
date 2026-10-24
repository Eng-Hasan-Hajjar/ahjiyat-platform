<?php

use App\Models\DigitalPurchase;
use App\Services\Commerce\CommerceCheckoutRequest;
use App\Services\Commerce\CommerceCheckoutInit;
use App\Services\Commerce\SandboxCommercePaymentProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/_support.php';

/*
|--------------------------------------------------------------------------
| E41 (BE2 + BE3) - بدء الدفع المستضاف بسلطة الخادم، وصفحة العودة (عرض فقط)
|--------------------------------------------------------------------------
*/

beforeEach(fn () => e41_seed());

/** مزوّد يعيد عنوان صفحة دفع خارجيًا خبيثًا (لاختبار الحارس). */
class E41EvilCheckoutProvider extends SandboxCommercePaymentProvider
{
    public function createCheckout(CommerceCheckoutRequest $request): CommerceCheckoutInit
    {
        return new CommerceCheckoutInit('sbx_'.substr(hash('sha256', $request->idempotencyKey), 0, 24), 'https://evil.example/pay?x=1', now()->addHour()->toImmutable());
    }
}

test('BE2-7: the authenticated owner starts a hosted checkout and is redirected to the provider page', function () {
    $b = e41_world();
    $user = e40_player($b['pass']);
    $order = e40_premium()->request($user, $b['pass']);

    $response = $this->actingAs($user)->post(route('commerce.checkout.start', $order->public_id));
    $fresh = $order->fresh();

    $response->assertRedirect($fresh->checkout_url);
    expect($fresh->provider_session_id)->toStartWith('sbx_')->and($fresh->checkout_url)->toBe(route('commerce.sandbox.pay', ['session' => $fresh->provider_session_id]))
        ->and($fresh->status)->toBe(DigitalPurchase::STATUS_AWAITING)->and($fresh->provider_status)->toBe('pending')->and((int) $fresh->checkout_generation)->toBe(0)
        ->and(DB::table('digital_purchase_events')->where('digital_purchase_id', $order->id)->where('event', 'checkout_created')->count())->toBe(1);

    $this->get(route('commerce.sandbox.pay', ['session' => $fresh->provider_session_id]))->assertOk()->assertSee('محاكاة', false);
});

test('BE2-8: the price is server-authoritative — a client amount is ignored and the provider session carries the stored price', function () {
    $b = e41_world(['price' => 500]);
    $user = e40_player($b['pass']);
    $order = e40_premium()->request($user, $b['pass']);

    $this->actingAs($user)->post(route('commerce.checkout.start', $order->public_id), ['amount_minor' => 1, 'amount' => '0.01', 'price' => 1, 'total' => 1])->assertRedirect();

    $session = Cache::get('commerce:sandbox:session:'.$order->fresh()->provider_session_id);

    expect((int) $order->fresh()->amount_minor)->toBe(500)->and($session['amount_minor'])->toBe(500);
});

test('BE2-9: the currency is server-authoritative — a client currency is ignored', function () {
    $b = e41_world(['currency' => 'USD']);
    $user = e40_player($b['pass']);
    $order = e40_premium()->request($user, $b['pass']);

    $this->actingAs($user)->post(route('commerce.checkout.start', $order->public_id), ['currency' => 'TRY'])->assertRedirect();

    $session = Cache::get('commerce:sandbox:session:'.$order->fresh()->provider_session_id);

    expect($order->fresh()->currency)->toBe('USD')->and($session['currency'])->toBe('USD');
});

test('BE2-10: the client cannot alter the user — another user cannot start (404) and a user_id field is ignored', function () {
    $b = e41_world();
    $owner = e40_player($b['pass']);
    $attacker = e40_player($b['pass']);
    $order = e40_premium()->request($owner, $b['pass']);

    $this->actingAs($attacker)->post(route('commerce.checkout.start', $order->public_id), ['user_id' => $owner->id])->assertNotFound();
    expect($order->fresh()->provider_session_id)->toBeNull();

    $this->actingAs($owner)->post(route('commerce.checkout.start', $order->public_id), ['user_id' => $attacker->id])->assertRedirect();
    expect((int) $order->fresh()->user_id)->toBe($owner->id);
});

test('BE2-11: the client cannot alter the product — product fields are ignored and the premium pass purchase stays what it was issued as', function () {
    $b = e41_world();
    $user = e40_player($b['pass']);
    $order = e40_premium()->request($user, $b['pass']);
    $before = $order->only(['product_type', 'product_id', 'product_public_id', 'product_name']);

    $this->actingAs($user)->post(route('commerce.checkout.start', $order->public_id), ['product_type' => 'other', 'product_id' => 999, 'product_name' => 'Hacked'])->assertRedirect();

    expect($order->fresh()->only(['product_type', 'product_id', 'product_public_id', 'product_name']))->toBe($before);
});

test('BE2-12: request_key is idempotent — the same key returns the same order and the same hosted session', function () {
    $b = e41_world();
    $user = e40_player($b['pass']);

    $first = e40_premium()->request($user, $b['pass'], 'key-abc-123');
    $second = e40_premium()->request($user, $b['pass'], 'key-abc-123');
    $a = e41_checkout()->start($user, $first);
    $c = e41_checkout()->start($user, $second);

    expect($second->id)->toBe($first->id)->and($c['session_id'])->toBe($a['session_id'])->and($c['reused'])->toBeTrue()->and(DigitalPurchase::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(SandboxCommercePaymentProvider::counter('sessions_created'))->toBe(1);
});

test('BE2-13: the open-order invariant is preserved — one open order per player and pass, however many checkouts start', function () {
    $b = e41_world();
    $user = e40_player($b['pass']);

    $one = e40_premium()->request($user, $b['pass']);
    $two = e40_premium()->request($user, $b['pass']);
    e41_checkout()->start($user, $one);
    e41_checkout()->start($user, $two);

    expect($two->id)->toBe($one->id)->and(DigitalPurchase::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(fn () => DB::table('digital_purchases')->insert(collect((array) DB::table('digital_purchases')->first())->except('id')->merge(['public_id' => (string) \Illuminate\Support\Str::ulid(), 'reference_code' => 'DUPE123456', 'request_key' => null])->all()))->toThrow(\Illuminate\Database\QueryException::class);
});

test('BE2-14: duplicate checkout starts are safe — the provider session is created once and the order carries one session', function () {
    $h = e41_hosted();

    $again = e41_checkout()->start($h['user'], $h['purchase']);
    $third = e41_checkout()->start($h['user'], $h['purchase']->fresh());

    expect($again['session_id'])->toBe($h['session'])->and($third['session_id'])->toBe($h['session'])
        ->and(SandboxCommercePaymentProvider::counter('sessions_created'))->toBe(1)
        ->and(DB::table('digital_purchase_events')->where('digital_purchase_id', $h['purchase']->id)->where('event', 'checkout_created')->count())->toBe(1);
});

test('BE2-15: the provider idempotency key is stable per order and generation, and carries no secret or time', function () {
    $h = e41_hosted();
    $svc = e41_checkout();

    expect($svc->idempotencyKey($h['purchase'], 0))->toBe($svc->idempotencyKey($h['purchase']->fresh(), 0))
        ->and($svc->idempotencyKey($h['purchase'], 1))->not->toBe($svc->idempotencyKey($h['purchase'], 0))
        ->and($svc->idempotencyKey($h['purchase'], 0))->toBe('commerce:chk:'.$h['purchase']->public_id)
        ->and($svc->idempotencyKey($h['purchase'], 0))->not->toContain((string) config('commerce.providers.sandbox.webhook_secret'));
});

test('BE2-16: the hosted URL comes from the provider only — a provider URL on a foreign host is refused and nothing is stored', function () {
    $b = e41_world();
    $user = e40_player($b['pass']);
    $order = e40_premium()->request($user, $b['pass']);
    e41_registry()->register(new E41EvilCheckoutProvider);

    expect(e41_reason(fn () => e41_checkout()->start($user, $order)))->toBe('invalid_checkout')
        ->and($order->fresh()->checkout_url)->toBeNull()->and($order->fresh()->provider_session_id)->toBeNull();

    $this->actingAs($user)->post(route('commerce.checkout.start', $order->public_id))->assertRedirect(route('pass.orders.show', $order->public_id))->assertSessionHas('error');
});

test('BE2-17: there is no arbitrary redirect — a tampered stored URL is never followed and query parameters never choose the destination', function () {
    $h = e41_hosted();
    DB::table('digital_purchases')->where('id', $h['purchase']->id)->update(['checkout_url' => 'https://evil.example/steal']);

    expect(e41_checkout()->safeRedirectUrl($h['purchase']->fresh()))->toBeNull();

    $this->actingAs($h['user'])->post(route('commerce.checkout.start', $h['purchase']->public_id), ['redirect' => 'https://evil.example', 'next' => 'https://evil.example', 'return_url' => 'https://evil.example'])
        ->assertRedirect();

    $location = $this->actingAs($h['user'])->post(route('commerce.checkout.start', $h['purchase']->public_id), ['redirect' => 'https://evil.example'])->headers->get('Location');

    expect($location)->not->toContain('evil.example')->and($location)->toContain('/commerce/sandbox/');
});

test('BE3-18: the browser return does not verify the order — only the trusted provider event does', function () {
    $h = e41_hosted();

    $this->actingAs($h['user'])->get(route('commerce.checkout.return', $h['purchase']->public_id))->assertOk()->assertSee('جارٍ التحقق', false)->assertDontSee('تم تفعيل المسار المميز', false);

    expect($h['purchase']->fresh()->status)->toBe(DigitalPurchase::STATUS_AWAITING)->and(e40_row($h['user'], $h['build']['pass'])?->premium_unlocked_at)->toBeNull();
});

test('BE3-19: a forged success parameter is ignored', function () {
    $h = e41_hosted();
    $before = DB::table('digital_purchase_events')->count();

    $this->actingAs($h['user'])->get(route('commerce.checkout.return', $h['purchase']->public_id).'?success=1&status=success&result=ok&payment_status=succeeded')
        ->assertOk()->assertDontSee('تم تفعيل المسار المميز', false);

    expect($h['purchase']->fresh()->status)->toBe(DigitalPurchase::STATUS_AWAITING)->and(DB::table('digital_purchase_events')->count())->toBe($before);
});

test('BE3-20: a forged paid parameter (with amount and reference) is ignored', function () {
    $h = e41_hosted();

    $this->actingAs($h['user'])->get(route('commerce.checkout.return', $h['purchase']->public_id).'?paid=true&amount=500&currency=USD&ref='.$h['purchase']->reference_code.'&session_id='.$h['session'])
        ->assertOk()->assertDontSee('تم تفعيل المسار المميز', false);

    expect($h['purchase']->fresh()->status)->toBe(DigitalPurchase::STATUS_AWAITING)->and($h['purchase']->fresh()->provider_status)->toBe('pending')
        ->and(e40_row($h['user'], $h['build']['pass'])?->premium_unlocked_at)->toBeNull()->and(\App\Models\CommerceProviderEvent::query()->count())->toBe(0);
});

test('BE3-21: the owner sees the current authoritative status, on the page and the polling endpoint', function () {
    $h = e41_hosted();

    $this->actingAs($h['user'])->getJson(route('commerce.orders.status', $h['purchase']->public_id))->assertOk()
        ->assertJson(['status' => 'awaiting_payment', 'phase' => 'awaiting', 'final' => false, 'verified' => false])
        ->assertHeader('Cache-Control', 'no-store, private');

    e41_pay($h['session']);

    $this->actingAs($h['user'])->getJson(route('commerce.orders.status', $h['purchase']->public_id))->assertOk()->assertJson(['status' => 'verified', 'phase' => 'verified', 'final' => true, 'verified' => true]);
    $this->actingAs($h['user'])->get(route('commerce.checkout.return', $h['purchase']->public_id))->assertOk()->assertSee('تم تفعيل المسار المميز', false);
});

test('BE3-22: another user is denied the return page and the status endpoint', function () {
    $h = e41_hosted();
    $stranger = e40_player();

    $this->actingAs($stranger)->get(route('commerce.checkout.return', $h['purchase']->public_id))->assertNotFound();
    $this->actingAs($stranger)->getJson(route('commerce.orders.status', $h['purchase']->public_id))->assertNotFound();
    $this->actingAs($stranger)->get(route('commerce.receipts.show', $h['purchase']->public_id))->assertNotFound();
    $this->actingAs($stranger)->post(route('commerce.checkout.start', $h['purchase']->public_id))->assertNotFound();
});

test('BE3-22b: a guest is sent to login for every commerce page and the status endpoint', function () {
    $h = e41_hosted();

    $this->get(route('commerce.checkout.return', $h['purchase']->public_id))->assertRedirect(route('login'));
    $this->get(route('commerce.purchases.index'))->assertRedirect(route('login'));
    $this->post(route('commerce.checkout.start', $h['purchase']->public_id))->assertRedirect(route('login'));
    $this->getJson(route('commerce.orders.status', $h['purchase']->public_id))->assertUnauthorized();
});

test('BE2-x: checkout is refused for a frozen buyer, a verified order and an expired order; a failed provider session can be replaced (bounded)', function () {
    $h = e41_hosted();
    e41_state(\App\Services\Commerce\CommerceProviderState::Failed, $h['session']);

    // فشل عند المزوّد ⇒ استبدال صريح بجلسة جديدة (جيل 1) ثم جيل 2 ثم جيل 3 ثم يُرفض.
    $second = e41_checkout()->start($h['user'], $h['purchase']->fresh());
    expect($second['reused'])->toBeFalse()->and($second['session_id'])->not->toBe($h['session'])->and((int) $h['purchase']->fresh()->checkout_generation)->toBe(1)
        ->and($h['purchase']->fresh()->provider_status)->toBe('pending');

    e41_state(\App\Services\Commerce\CommerceProviderState::Failed, $second['session_id']);
    $third = e41_checkout()->start($h['user'], $h['purchase']->fresh());
    e41_state(\App\Services\Commerce\CommerceProviderState::Failed, $third['session_id']);
    $fourth = e41_checkout()->start($h['user'], $h['purchase']->fresh());
    e41_state(\App\Services\Commerce\CommerceProviderState::Failed, $fourth['session_id']);

    expect(e41_reason(fn () => e41_checkout()->start($h['user'], $h['purchase']->fresh())))->toBe('too_many_sessions');

    $h2 = e41_hosted($h['build'], e40_player($h['build']['pass']));
    DB::table('digital_purchases')->where('id', $h2['purchase']->id)->update(['expires_at' => now()->subMinute()]);
    expect(e41_reason(fn () => e41_checkout()->start($h2['user'], $h2['purchase']->fresh())))->toBe('expired');

    $h['user']->forceFill(['is_frozen' => true])->save();
    expect(e41_reason(fn () => e41_checkout()->start($h['user']->fresh(), $h['purchase']->fresh())))->toBe('frozen');
});
