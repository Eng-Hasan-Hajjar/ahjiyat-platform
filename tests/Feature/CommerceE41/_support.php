<?php

use App\Models\AhjiyatPass;
use App\Models\CommerceProviderEvent;
use App\Models\DigitalPurchase;
use App\Models\User;
use App\Services\Commerce\CommerceCheckoutService;
use App\Services\Commerce\CommercePaymentProviderRegistry;
use App\Services\Commerce\CommerceProviderState;
use App\Services\Commerce\CommerceWebhookException;
use App\Services\Commerce\CommerceWebhookService;
use App\Services\Commerce\SandboxCommercePaymentProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
| E41 - مساعدات مشتركة لاختبارات التجارة الاستهلاكية. ليست ملف اختبار (لا تنتهي بـTest.php) فتُحمَّل بـrequire_once.
| تعيد استخدام مساعدات E40 (بناء المسار/اللاعب/الإدارة) وتضيف مزوّد sandbox التجريبي؛ كاش array وطابور sync وبلا أي شبكة.
| الزمن المثبَّت من e40_seed: now = 2026-12-10 12:00.
*/

require_once __DIR__.'/../AhjiyatPassE40/_support.php';

if (! function_exists('e41_seed')) {
    function e41_seed(array $config = []): void
    {
        e40_seed();
        SandboxCommercePaymentProvider::resetState();
        config(array_merge([
            'commerce.enabled' => true,
            'commerce.default_provider' => 'sandbox',
            'commerce.fallback_provider' => 'manual',
            'commerce.webhooks.enabled' => true,
            'commerce.providers.sandbox.enabled' => true,
            'commerce.providers.sandbox.accept_webhooks' => true,
            'commerce.providers.sandbox.refund_mode' => 'succeeded',
            'commerce.notifications.enabled' => true,
        ], $config));
        app(CommercePaymentProviderRegistry::class)->forget('sandbox');
    }

    function e41_registry(): CommercePaymentProviderRegistry
    {
        return app(CommercePaymentProviderRegistry::class);
    }

    function e41_sandbox(): SandboxCommercePaymentProvider
    {
        /** @var SandboxCommercePaymentProvider $p */
        $p = e41_registry()->get('sandbox');

        return $p;
    }

    function e41_checkout(): CommerceCheckoutService
    {
        return app(CommerceCheckoutService::class);
    }

    function e41_webhooks(): CommerceWebhookService
    {
        return app(CommerceWebhookService::class);
    }

    /** مسار منشور بسعر 5.00 USD وشرائح متنوعة (يعيد مصفوفة e40_build). */
    function e41_world(array $o = []): array
    {
        return e40_build($o + ['tiers' => 4, 'step' => 100, 'free_tiers' => [2, 4], 'premium_tiers' => [1, 3], 'price' => 500, 'currency' => 'USD']);
    }

    /** طلب مستضاف (sandbox) بدأ فيه checkout. @return array{purchase: DigitalPurchase, session: string, url: string, user: User, build: array} */
    function e41_hosted(?array $build = null, ?User $user = null): array
    {
        $build ??= e41_world();
        $user ??= e40_player($build['pass']);
        $purchase = e40_premium()->request($user, $build['pass']);
        $started = e41_checkout()->start($user, $purchase);

        return ['purchase' => $purchase->fresh(), 'session' => $started['session_id'], 'url' => $started['url'], 'user' => $user, 'build' => $build];
    }

    /** يمرّر حدثًا موقَّعًا للمزوّد التجريبي عبر المعالج الكامل. @return array{result: string, detail: ?string} */
    function e41_deliver(string $session, string $type, array $override = [], ?string $nonce = null, ?int $timestamp = null): array
    {
        $event = e41_sandbox()->signedEvent($session, $type, $override, $nonce, $timestamp);

        return e41_webhooks()->handle('sandbox', $event['payload'], $event['headers']);
    }

    /** يحاكي دفع المزوّد (حالة المزوّد تصعد) ثم يسلّم الحدث الموقَّع. */
    function e41_pay(string $session, array $override = [], ?string $nonce = null): array
    {
        $event = e41_sandbox()->simulate($session, CommerceProviderState::Paid, $override, $nonce);

        return e41_webhooks()->handle('sandbox', $event['payload'], $event['headers']);
    }

    function e41_state(CommerceProviderState $state, string $session, array $override = [], ?string $nonce = null): array
    {
        $event = e41_sandbox()->simulate($session, $state, $override, $nonce);

        return e41_webhooks()->handle('sandbox', $event['payload'], $event['headers']);
    }

    /** طلب مستضاف مدفوع ومحقَّق عبر المزوّد. */
    function e41_paid(?array $build = null, ?User $user = null): array
    {
        $h = e41_hosted($build, $user);
        e41_pay($h['session']);

        return ['purchase' => $h['purchase']->fresh()] + $h;
    }

    /** HTTP: يرسل جسمًا خامًا إلى مسار webhook. */
    function e41_post_webhook($test, string $provider, string $payload, array $headers = [])
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', (string) $name))] = $value;
        }

        return $test->call('POST', '/api/commerce/webhooks/'.$provider, [], [], [], $server, $payload);
    }

    function e41_reason(callable $call): ?string
    {
        try {
            $call();
        } catch (\App\Services\Commerce\CommerceException|CommerceWebhookException|\App\Services\AhjiyatPass\PassException $e) {
            return $e->reason;
        }

        return null;
    }

    /** لقطة صفوف الجداول التي يجب ألا تتغير بسبب التجارة (عزل). */
    function e41_foreign_counts(): array
    {
        return e40_foreign_counts() + [
            'store_purchases' => DB::table('store_purchases')->count(),
            'currency_transactions' => DB::table('currency_transactions')->count(),
            'wallets_available' => (int) DB::table('wallets')->sum('available_balance'),
            'user_referrals' => DB::table('user_referrals')->count(),
        ];
    }

    function e41_ledger(?string $eventId = null)
    {
        $q = CommerceProviderEvent::query();

        return $eventId === null ? $q->get() : $q->where('provider_event_id', $eventId)->get();
    }
}
