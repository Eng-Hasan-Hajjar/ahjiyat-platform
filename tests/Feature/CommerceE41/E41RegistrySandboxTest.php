<?php

use App\Models\DigitalPurchase;
use App\Services\Commerce\CommerceEnvironment;
use App\Services\Commerce\CommerceException;
use App\Services\Commerce\ManualCommercePaymentProvider;
use App\Services\Commerce\SandboxCommercePaymentProvider;
use App\Services\Commerce\WebhookSignature;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/_support.php';

/*
|--------------------------------------------------------------------------
| E41 (BE1 + BE14) - سجل المزوّدين والمزوّد التجريبي (سلامة الإنتاج وبلا شبكة)
|--------------------------------------------------------------------------
*/

beforeEach(fn () => e41_seed());

test('BE1-1: the manual provider is registered and is the E40 provider (no redirect, no webhook, no refund)', function () {
    $manual = e41_registry()->get('manual');

    expect(e41_registry()->has('manual'))->toBeTrue()
        ->and($manual)->toBeInstanceOf(ManualCommercePaymentProvider::class)
        ->and($manual->supportsRedirect())->toBeFalse()->and($manual->supportsWebhook())->toBeFalse()->and($manual->supportsRefund())->toBeFalse()->and($manual->mode())->toBe('manual');
});

test('BE1-2: the sandbox provider is registered only where it is allowed (local/testing/CI) and never in production', function () {
    expect(e41_registry()->keys())->toContain('sandbox')->and(CommerceEnvironment::sandboxAllowed())->toBeTrue();

    $original = app()['env'];
    app()['env'] = 'production';
    app(\App\Services\Commerce\CommercePaymentProviderRegistry::class)->forget('sandbox');

    try {
        expect(CommerceEnvironment::sandboxAllowed())->toBeFalse()
            ->and(e41_registry()->has('sandbox'))->toBeFalse()
            ->and(e41_registry()->keys())->not->toContain('sandbox')
            ->and(e41_reason(fn () => e41_registry()->get('sandbox')))->toBe('unknown_provider');
    } finally {
        app()['env'] = $original;
        e41_registry()->forget('sandbox');
    }
});

test('BE1-3: an unknown provider key is rejected everywhere (registry, webhook entry)', function () {
    expect(e41_registry()->has('stripe-live'))->toBeFalse()
        ->and(e41_reason(fn () => e41_registry()->get('stripe-live')))->toBe('unknown_provider')
        ->and(e41_reason(fn () => e41_webhooks()->handle('stripe-live', '{}', [])))->toBe('unknown_provider');
});

test('BE1-4: a disabled provider cannot start a new checkout — new purchases fall back to the manual provider', function () {
    config(['commerce.providers.sandbox.enabled' => false]);
    e41_registry()->forget('sandbox');
    $b = e41_world();
    $user = e40_player($b['pass']);

    $order = e40_premium()->request($user, $b['pass']);

    expect($order->provider)->toBe('manual')
        ->and(e41_reason(fn () => e41_checkout()->start($user, $order)))->not->toBeNull();

    config(['commerce.default_provider' => 'sandbox', 'commerce.fallback_provider' => 'sandbox']);
    $other = e40_player($b['pass']);

    expect(e40_reason(fn () => e40_premium()->request($other, $b['pass'], null, false)))->toBe('provider_disabled');
});

test('BE1-5: a disabled provider still processes a historical callback for an order it already issued (money in flight is never lost)', function () {
    $h = e41_hosted();
    config(['commerce.providers.sandbox.enabled' => false]);
    e41_registry()->forget('sandbox');

    $result = e41_pay($h['session']);

    expect($result['result'])->toBe('applied')
        ->and($h['purchase']->fresh()->status)->toBe(DigitalPurchase::STATUS_VERIFIED);

    // …لكن حين يوقف المشغّل **استقبال** الأحداث نفسه يُرفض الاستقبال (قرار صريح منفصل عن إيقاف بدء الدفع).
    config(['commerce.providers.sandbox.enabled' => true]);
    e41_registry()->forget('sandbox');
    $second = e41_hosted($h['build']);
    config(['commerce.providers.sandbox.accept_webhooks' => false]);
    e41_registry()->forget('sandbox');

    expect(fn () => e41_deliver($second['session'], 'payment.paid'))->toThrow(\App\Services\Commerce\CommerceWebhookException::class);
});

test('BE1-6: currency intersection is enforced — provider ∩ commerce ∩ pass; a currency the provider refuses falls back or fails closed', function () {
    config(['commerce.providers.sandbox.currencies' => ['EUR']]);
    e41_registry()->forget('sandbox');
    $b = e41_world(['currency' => 'USD']);
    $user = e40_player($b['pass']);

    expect(e40_premium()->request($user, $b['pass'])->provider)->toBe('manual');

    config(['commerce.allowed_currencies' => ['USD'], 'commerce.providers.sandbox.currencies' => ['USD', 'EUR']]);
    DB::table('ahjiyat_passes')->where('id', $b['pass']->id)->update(['currency' => 'EUR']);
    $buyer = e40_player();

    // EUR غير مسموح للتجارة ⇒ لا مزوّد (لا يدوي أيضًا) ⇒ فشل مغلق.
    expect(e40_reason(fn () => e40_premium()->request($buyer, $b['pass']->fresh())))->not->toBeNull();
    DB::table('ahjiyat_passes')->where('id', $b['pass']->id)->update(['currency' => 'USD']);

    config(['commerce.allowed_currencies' => ['USD', 'EUR', 'TRY']]);
    $ok = e40_player($b['pass']);
    config(['commerce.providers.sandbox.currencies' => ['USD']]);
    e41_registry()->forget('sandbox');

    expect(e40_premium()->request($ok, $b['pass'])->provider)->toBe('sandbox');
});

test('BE14-97: the sandbox works in the testing environment — checkout, signed paid event, verification', function () {
    $h = e41_hosted();

    expect($h['purchase']->provider)->toBe('sandbox')->and($h['session'])->toStartWith('sbx_')->and($h['url'])->toContain('/commerce/sandbox/'.$h['session']);

    e41_pay($h['session']);

    expect($h['purchase']->fresh()->status)->toBe(DigitalPurchase::STATUS_VERIFIED);
});

test('BE14-98: the sandbox refuses production — every operation, the checkout, webhook acceptance and the routes', function () {
    $h = e41_hosted();
    $original = app()['env'];
    app()['env'] = 'production';

    try {
        $sandbox = new SandboxCommercePaymentProvider;
        $request = new \App\Services\Commerce\CommerceCheckoutRequest($h['purchase'], 'key', 'https://example.test/ok', 'https://example.test/no', now()->addHour()->toImmutable());

        expect($sandbox->isEnabled())->toBeFalse()->and($sandbox->isConfigured())->toBeFalse()->and($sandbox->acceptsWebhooks())->toBeFalse()
            ->and(e41_reason(fn () => $sandbox->createCheckout($request)))->toBe('sandbox_forbidden')
            ->and(e41_reason(fn () => $sandbox->refund($h['purchase'], 'k')))->toBe('sandbox_forbidden')
            ->and(e41_reason(fn () => $sandbox->fetchStatus($h['purchase'])))->toBe('sandbox_forbidden')
            ->and(e41_reason(fn () => $sandbox->signedEvent($h['session'], 'payment.paid')))->toBe('sandbox_forbidden')
            ->and(e41_reason(fn () => e41_webhooks()->handle('sandbox', '{}', [])))->toBe('unknown_provider');
    } finally {
        app()['env'] = $original;
        e41_registry()->forget('sandbox');
    }

    expect($h['purchase']->fresh()->status)->toBe(DigitalPurchase::STATUS_AWAITING);
});

test('BE14-99: the sandbox signature is deterministic and verifies only with the fixed non-secret test key', function () {
    $secret = (string) config('commerce.providers.sandbox.webhook_secret');
    $h = e41_hosted();
    $a = e41_sandbox()->signedEvent($h['session'], 'payment.paid', [], 'n1', 1_700_000_000);
    $b = e41_sandbox()->signedEvent($h['session'], 'payment.paid', [], 'n1', 1_700_000_000);

    expect($secret)->toBe('sandbox-not-a-secret')
        ->and($a['payload'])->toBe($b['payload'])->and($a['headers'])->toBe($b['headers'])
        ->and($a['headers']['X-Commerce-Signature'])->toBe(WebhookSignature::header($secret, 1_700_000_000, $a['payload']))
        ->and($a['event_id'])->toBe(e41_sandbox()->signedEvent($h['session'], 'payment.paid', [], 'n1')['event_id'])
        ->and($a['event_id'])->not->toBe(e41_sandbox()->signedEvent($h['session'], 'payment.paid', [], 'n2')['event_id']);
});

test('BE14-100: no real network is ever touched — the commerce code has no HTTP client and a full flow makes zero outbound requests', function () {
    Http::fake();

    $h = e41_hosted();
    e41_pay($h['session']);
    e40_premium()->refund(e40_admin(), $h['purchase']->fresh(), 'user_request', 'استرداد اختبار');

    Http::assertNothingSent();

    $violations = [];

    foreach (['app/Services/Commerce', 'app/Http/Controllers/Commerce'] as $dir) {
        foreach (glob(base_path($dir).'/*.php') as $file) {
            $code = '';

            foreach (token_get_all((string) file_get_contents($file)) as $token) {
                if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                $code .= is_array($token) ? $token[1] : $token;
            }

            if (preg_match('/Http::|GuzzleHttp|curl_init|fsockopen|stream_socket_client|Illuminate\\Http\\Client/', $code) === 1) {
                $violations[] = basename($file);
            }
        }
    }

    expect($violations)->toBe([]);
});
