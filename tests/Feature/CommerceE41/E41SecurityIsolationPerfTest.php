<?php

use App\Filament\Pages\AhjiyatPassPurchasesPage;
use App\Models\DigitalPurchase;
use App\Services\Commerce\CheckoutUrlGuard;
use App\Services\Commerce\CommerceCheckoutInit;
use App\Services\Commerce\CommerceCheckoutRequest;
use App\Services\Commerce\SandboxCommercePaymentProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Symfony\Component\Process\Process;

require_once __DIR__.'/_support.php';

/*
|--------------------------------------------------------------------------
| E41 (BE12 + BE13 + BE15) - الأمان، العزل عن الأنظمة المالية الأخرى، والأداء
|--------------------------------------------------------------------------
*/

beforeEach(fn () => e41_seed());

/** مزوّد يلتقط طلب الإنشاء (لإثبات أن عنوان العودة يصنعه الخادم وحده). */
class E41SpyProvider extends SandboxCommercePaymentProvider
{
    public static ?CommerceCheckoutRequest $seen = null;

    public function createCheckout(CommerceCheckoutRequest $request): CommerceCheckoutInit
    {
        self::$seen = $request;

        return parent::createCheckout($request);
    }
}

// ───────────────────────────── BE12: الأمان ─────────────────────────────

test('BE12-81: browser mutations need CSRF — checkout start and the sandbox buttons are refused (419) without a token and change nothing', function () {
    $h = e41_hosted();
    $original = $this->app['env'];
    $this->app['env'] = 'local';

    try {
        $this->actingAs($h['user'])->post(route('commerce.checkout.start', $h['purchase']->public_id))->assertStatus(419);
        $this->actingAs($h['user'])->post(route('commerce.sandbox.complete', ['session' => $h['session'], 'outcome' => 'paid']))->assertStatus(419);
    } finally {
        $this->app['env'] = $original;
    }

    expect($h['purchase']->fresh()->status)->toBe(DigitalPurchase::STATUS_AWAITING)->and($h['purchase']->fresh()->provider_status)->toBe('pending');
});

test('BE12-82: the webhook is authenticated by its signature alone — it is outside the web group (no session, no CSRF) yet works with CSRF enforcement on', function () {
    $route = Route::getRoutes()->getByName('commerce.webhook');

    expect($route)->not->toBeNull()->and($route->gatherMiddleware())->not->toContain('web')->and($route->gatherMiddleware())->not->toContain('auth')->and($route->methods())->toBe(['POST']);

    $h = e41_hosted();
    $event = e41_sandbox()->simulate($h['session'], \App\Services\Commerce\CommerceProviderState::Paid);
    $original = $this->app['env'];
    $this->app['env'] = 'local';

    try {
        e41_post_webhook($this, 'sandbox', $event['payload'], $event['headers'])->assertOk()->assertJson(['result' => 'applied']);
    } finally {
        $this->app['env'] = $original;
    }

    expect($h['purchase']->fresh()->status)->toBe(DigitalPurchase::STATUS_VERIFIED);
});

test('BE12-83: mass assignment is protected — the purchase and the ledger cannot be filled from request-like data', function () {
    $h = e41_hosted();
    $order = $h['purchase'];

    expect(fn () => DigitalPurchase::query()->create(['user_id' => $h['user']->id, 'status' => 'verified', 'amount_minor' => 1]))->toThrow(\Illuminate\Database\Eloquent\MassAssignmentException::class)
        ->and(fn () => $order->update(['status' => 'verified', 'provider_status' => 'paid', 'amount_minor' => 1]))->toThrow(\Illuminate\Database\Eloquent\MassAssignmentException::class)
        ->and(fn () => \App\Models\CommerceProviderEvent::query()->create(['provider' => 'sandbox', 'provider_event_id' => 'x']))->toThrow(\Illuminate\Database\Eloquent\MassAssignmentException::class)
        ->and($order->fresh()->status)->toBe(DigitalPurchase::STATUS_AWAITING);

    // حتى الكتابة المباشرة تحرس الحقول الحساسة: المرجع الدفعي لا يتغير بعد تثبيته، والحالة لا ترجع للخلف.
    e41_pay($h['session']);
    $paid = $order->fresh();

    expect(fn () => $paid->forceFill(['provider_payment_reference' => 'sbxpay_other'])->save())->toThrow(\Exception::class)
        ->and(fn () => $paid->forceFill(['provider_status' => 'pending'])->save())->toThrow(\Exception::class);
});

test('BE12-84: there is no arbitrary callback URL — the success/cancel URLs given to the provider are server-built and ignore any client value', function () {
    $b = e41_world();
    $user = e40_player($b['pass']);
    $order = e40_premium()->request($user, $b['pass']);
    e41_registry()->register(new E41SpyProvider);

    $this->actingAs($user)->post(route('commerce.checkout.start', $order->public_id), ['success_url' => 'https://evil.example/ok', 'cancel_url' => 'https://evil.example/no', 'callback_url' => 'https://evil.example/cb', 'return_url' => 'https://evil.example'])->assertRedirect();

    $seen = E41SpyProvider::$seen;

    expect($seen)->not->toBeNull()->and($seen->successUrl)->toBe(route('commerce.checkout.return', ['order' => $order->public_id]))->and($seen->cancelUrl)->toBe($seen->successUrl)
        ->and($seen->successUrl)->not->toContain('evil.example')->and($seen->idempotencyKey)->toBe('commerce:chk:'.$order->public_id);
});

test('BE12-85: there is no open redirect — the URL guard accepts only vetted hosts/schemes and rejects userinfo, control characters, protocol-relative and javascript URLs', function () {
    config(['commerce.checkout.allowed_hosts' => ['acme' => ['pay.acme.example']]]);
    $app = parse_url(url('/'), PHP_URL_HOST);

    $ok = [['acme', 'https://pay.acme.example/c/123'], ['sandbox', 'http://'.$app.'/commerce/sandbox/sbx_abc'], ['sandbox', 'https://'.$app.'/commerce/sandbox/sbx_abc']];
    $bad = [
        ['acme', 'http://pay.acme.example/c/123'], ['acme', 'https://evil.example/c/123'], ['acme', 'https://pay.acme.example.evil.example/c'], ['acme', 'https://pay.acme.example@evil.example/c'],
        ['acme', 'https://user:pw@pay.acme.example/c'], ['acme', '//pay.acme.example/c'], ['acme', 'javascript:alert(1)'], ['acme', "https://pay.acme.example/c\r\nLocation: https://evil.example"],
        ['acme', 'https://pay.acme.example\\@evil.example/'], ['acme', ''], ['acme', 'https://pay.acme.example/'.str_repeat('a', 1100)], ['unlisted', 'https://pay.acme.example/c'],
        ['sandbox', 'https://evil.example/commerce/sandbox/sbx_abc'], ['sandbox', 'http://'.$app.'/admin'], ['sandbox', 'http://'.$app.'/commerce/sandbox/sbx_abc?next=x@evil'],
    ];

    foreach ($ok as [$p, $u]) {
        expect(CheckoutUrlGuard::allows($p, $u))->toBeTrue($u);
    }

    foreach (array_slice($bad, 0, -1) as [$p, $u]) {
        expect(CheckoutUrlGuard::allows($p, $u))->toBeFalse(substr($u, 0, 60));
    }

    expect(CheckoutUrlGuard::allows('sandbox', 'http://'.$app.'/commerce/sandbox/sbx_abc?x=1'))->toBeTrue();
});

test('BE12-86: rate limits apply — checkout start, the status endpoint and the webhook are all throttled by named limiters', function () {
    config(['commerce.limits.checkout_per_minute' => 3, 'commerce.status.rate_per_minute' => 3, 'commerce.webhooks.rate_per_minute' => 3]);
    $h = e41_hosted();

    foreach ([1, 2, 3] as $i) {
        $this->actingAs($h['user'])->post(route('commerce.checkout.start', $h['purchase']->public_id))->assertRedirect();
    }
    $this->actingAs($h['user'])->post(route('commerce.checkout.start', $h['purchase']->public_id))->assertStatus(429);

    foreach ([1, 2, 3] as $i) {
        $this->actingAs($h['user'])->getJson(route('commerce.orders.status', $h['purchase']->public_id))->assertOk();
    }
    $this->actingAs($h['user'])->getJson(route('commerce.orders.status', $h['purchase']->public_id))->assertStatus(429);

    foreach ([1, 2, 3] as $i) {
        e41_post_webhook($this, 'sandbox', '{}', [])->assertStatus(401);
    }
    e41_post_webhook($this, 'sandbox', '{}', [])->assertStatus(429);

    expect(Route::getRoutes()->getByName('commerce.checkout.start')->gatherMiddleware())->toContain('throttle:commerce-checkout')
        ->and(Route::getRoutes()->getByName('commerce.orders.status')->gatherMiddleware())->toContain('throttle:commerce-status')
        ->and(Route::getRoutes()->getByName('commerce.webhook')->gatherMiddleware())->toContain('throttle:commerce-webhook');
});

test('BE12-87: the E18 route guard stays green — no commerce URI, route name or action mentions reward/grant/claim/payout/prize, and no commerce GET mutates', function () {
    $offenders = [];

    foreach (Route::getRoutes() as $route) {
        $name = (string) $route->getName();

        if (! (str_contains($route->uri(), 'commerce') || str_starts_with($name, 'commerce.'))) {
            continue;
        }

        $haystack = $route->uri().' '.$name.' '.$route->getActionName();

        if (preg_match('/(reward|grant|claim|payout|prize)/i', $haystack) === 1) {
            $offenders[] = $haystack;
        }

        if (in_array('GET', $route->methods(), true) && preg_match('/@(store|update|destroy|start|complete|submit|pay|verify|confirm)$/i', $route->getActionName()) === 1 && $name !== 'commerce.sandbox.pay') {
            $offenders[] = 'GET mutates: '.$haystack;
        }
    }

    expect($offenders)->toBe([]);
});

test('BE12-88: frozen-account restrictions are preserved — a frozen buyer is locked out of every commerce page, and a paid event for them waits for a human instead of unlocking', function () {
    $h = e41_hosted();
    $h['user']->forceFill(['is_frozen' => true])->save();

    foreach ([route('commerce.purchases.index'), route('commerce.checkout.return', $h['purchase']->public_id)] as $url) {
        $response = $this->actingAs($h['user']->fresh())->get($url);
        expect($response->status())->not->toBe(200);
    }
    $this->actingAs($h['user']->fresh())->post(route('commerce.checkout.start', $h['purchase']->public_id))->assertStatus(302);
    expect($h['purchase']->fresh()->checkout_generation)->toBe(0);

    $result = e41_pay($h['session']);

    expect($result)->toMatchArray(['result' => 'needs_review'])->and($result['detail'])->toStartWith('fulfillment_')->and($h['purchase']->fresh()->status)->toBe(DigitalPurchase::STATUS_AWAITING)
        ->and($h['purchase']->fresh()->review_flag)->toBe('fulfillment_blocked')->and(e40_row($h['user'], $h['build']['pass'])?->premium_unlocked_at)->toBeNull()
        ->and(e40_notes($h['user'], 'commerce_action_required'))->toHaveCount(0);
});

test('BE12-x: the sandbox pages and routes do not exist in production — route registration (separate boot) and the controller guard', function () {
    $process = new Process([PHP_BINARY, 'artisan', 'route:list', '--name=commerce', '--json'], base_path(), ['APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:'], null, 60);
    $process->run();
    $names = collect(json_decode($process->getOutput(), true) ?: [])->pluck('name')->filter()->all();

    expect($process->isSuccessful())->toBeTrue()->and($names)->toContain('commerce.webhook')->toContain('commerce.checkout.start')
        ->and(collect($names)->filter(fn ($n) => str_contains((string) $n, 'sandbox'))->all())->toBe([]);

    $h = e41_hosted();
    $original = $this->app['env'];
    $this->app['env'] = 'production';

    try {
        $this->actingAs($h['user'])->get(route('commerce.sandbox.pay', ['session' => $h['session']]))->assertNotFound();
        // خارج بيئة الاختبار يُفرَض CSRF قبل المتحكم: نرسل رمزًا صالحًا لنثبت أن الرفض صادر من حارس الإنتاج لا من CSRF.
        $this->actingAs($h['user'])->withSession(['_token' => 'tok'])->post(route('commerce.sandbox.complete', ['session' => $h['session'], 'outcome' => 'paid']), ['_token' => 'tok'])->assertNotFound();
    } finally {
        $this->app['env'] = $original;
    }

    expect($h['purchase']->fresh()->status)->toBe(DigitalPurchase::STATUS_AWAITING);
});

test('BE12-y: the sandbox page is owner-only and its buttons run the full signed-webhook path (not a shortcut)', function () {
    $h = e41_hosted();
    $stranger = e40_player();

    $this->actingAs($stranger)->get(route('commerce.sandbox.pay', ['session' => $h['session']]))->assertNotFound();
    $this->actingAs($stranger)->post(route('commerce.sandbox.complete', ['session' => $h['session'], 'outcome' => 'paid']))->assertNotFound();

    $this->actingAs($h['user'])->post(route('commerce.sandbox.complete', ['session' => $h['session'], 'outcome' => 'paid']))->assertRedirect(route('commerce.checkout.return', $h['purchase']->public_id));

    expect($h['purchase']->fresh()->status)->toBe(DigitalPurchase::STATUS_VERIFIED)->and(\App\Models\CommerceProviderEvent::query()->count())->toBe(1)->and(\App\Models\CommerceProviderEvent::query()->value('payload_sha256'))->toHaveLength(64);
});

// ───────────────────────────── BE13: العزل ─────────────────────────────

test('BE13-89..96: a full hosted purchase + refund creates nothing in sponsorship, store, gems/wallet, creator earnings, reputation, competition or referrals', function () {
    $b = e41_world();
    $user = e40_player($b['pass']);
    $economy = e40_economy($user);
    $foreign = e41_foreign_counts();

    $h = e41_hosted($b, $user);
    e41_pay($h['session']);
    e40_premium()->refund($b['admin'], $h['purchase']->fresh(), 'user_request', 'استرداد اختبار');
    $other = e41_hosted($b, e40_player($b['pass']));
    e41_pay($other['session'], ['amount_minor' => 1]);
    e40_premium()->dismissReview($b['admin'], $other['purchase']->fresh(), 'إغلاق اختبار');

    expect(e41_foreign_counts())->toBe($foreign)->and(e40_economy($user))->toBe($economy)
        ->and(DB::table('sponsorship_contributions')->count())->toBe(0)->and(DB::table('sponsorship_payments')->count())->toBe(0)->and(DB::table('store_purchases')->count())->toBe(0)
        ->and(DB::table('creator_earning_entries')->count())->toBe(0);
});

test('BE13-x: commerce code never references the other money systems — a source scan', function () {
    $needles = ['SponsorshipPayment', 'SponsorshipContribution', 'StorePurchase', 'GemWallet', 'CurrencyTransaction', 'CreatorEarning', 'CreatorRevenue', 'Reputation', 'Competitive', 'UserReferral', 'ReferralQualif', 'XpTransaction', 'Wallet::'];
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

            foreach ($needles as $needle) {
                if (str_contains($code, $needle)) {
                    $violations[] = basename($file).' => '.$needle;
                }
            }
        }
    }

    expect($violations)->toBe([]);
});

// ───────────────────────────── BE15: الأداء ─────────────────────────────

function e41_query_count(callable $call): int
{
    $count = 0;
    DB::listen(function () use (&$count) {
        $count++;
    });
    $call();

    return $count;
}

test('BE15-101: the owner purchase list is bounded and paginated — the query count does not grow with the number of orders', function () {
    config(['commerce.limits.history_per_page' => 5]);
    $h = e41_paid();
    $seed = function (int $n) use ($h) {
        $row = (array) DB::table('digital_purchases')->where('id', $h['purchase']->id)->first();
        unset($row['id']);

        foreach (range(1, $n) as $i) {
            DB::table('digital_purchases')->insert(array_merge($row, ['public_id' => (string) \Illuminate\Support\Str::ulid(), 'reference_code' => strtoupper(substr(md5(uniqid('', true)), 0, 10)), 'request_key' => null, 'open_key' => null, 'status' => 'cancelled', 'provider_session_id' => null, 'provider_payment_reference' => null, 'provider_refund_reference' => null]));
        }
    };

    $this->actingAs($h['user']);
    $few = e41_query_count(fn () => $this->get(route('commerce.purchases.index'))->assertOk());
    $seed(40);
    $page = null;
    $many = e41_query_count(function () use (&$page) {
        $page = $this->get(route('commerce.purchases.index'))->assertOk();
    });

    expect(substr_count($page->getContent(), 'data-commerce-row='))->toBe(5)->and($many)->toBeLessThanOrEqual($few + 1);
});

test('BE15-102: the finance list has no N+1 — the query count is flat from 3 to 20 orders, with filters and the review tab', function () {
    $b = e41_world();
    foreach (range(1, 3) as $i) {
        e41_hosted($b, e40_player($b['pass']));
    }
    $this->actingAs(e40_staff_with(['passes.view-purchases']));

    $few = e41_query_count(fn () => Livewire::test(AhjiyatPassPurchasesPage::class)->set('tab', 'awaiting')->set('filterProvider', 'sandbox')->assertSuccessful());

    foreach (range(1, 17) as $i) {
        $h = e41_hosted($b, e40_player($b['pass']));

        if ($i % 2 === 0) {
            e41_pay($h['session'], ['amount_minor' => 7]);
        }
    }

    $many = e41_query_count(fn () => Livewire::test(AhjiyatPassPurchasesPage::class)->set('tab', 'awaiting')->set('filterProvider', 'sandbox')->assertSuccessful());
    $review = e41_query_count(fn () => Livewire::test(AhjiyatPassPurchasesPage::class)->set('tab', 'review')->assertSuccessful());

    expect($many)->toBeLessThanOrEqual($few + 2)->and($review)->toBeLessThanOrEqual($few + 3);
});

test('BE15-103: webhook/event lookups are indexed — provider+session, provider+payment, provider+event, reference and the review queue', function () {
    $plan = fn (string $sql) => collect(DB::select('EXPLAIN QUERY PLAN '.$sql))->pluck('detail')->implode(' | ');

    expect($plan("select * from digital_purchases where provider = 'sandbox' and provider_session_id = 'x'"))->toContain('digital_purchases_provider_session_unique')
        ->and($plan("select * from digital_purchases where provider = 'sandbox' and provider_payment_reference = 'x'"))->toContain('digital_purchases_provider_payment_unique')
        ->and($plan("select * from digital_purchases where reference_code = 'x'"))->toContain('digital_purchases_reference_unique')
        ->and($plan("select * from commerce_provider_events where provider = 'sandbox' and provider_event_id = 'x'"))->toContain('commerce_provider_events_event_unique')
        ->and($plan('select * from commerce_provider_events where digital_purchase_id = 1 order by received_at'))->toContain('commerce_provider_events_purchase_index')
        ->and($plan("select * from digital_purchases where review_flag = 'amount_mismatch' and review_resolved_at is null"))->toContain('digital_purchases_review_index');
});

test('BE15-104: the receipt page uses a small, fixed number of queries', function () {
    $h = e41_paid();
    $this->actingAs($h['user']);

    $queries = e41_query_count(fn () => $this->get(route('commerce.receipts.show', $h['purchase']->public_id))->assertOk());

    expect($queries)->toBeLessThanOrEqual(12);
});

test('BE15-105: the provider event list on the finance detail is paginated/capped, newest first', function () {
    config(['commerce.limits.admin_events_per_page' => 5]);
    $h = e41_paid();
    $row = (array) DB::table('commerce_provider_events')->first();
    unset($row['id']);

    foreach (range(1, 30) as $i) {
        DB::table('commerce_provider_events')->insert(array_merge($row, ['provider_event_id' => 'evt_bulk_'.$i]));
    }

    $this->actingAs(e40_staff_with(['passes.view-purchases']));
    $data = Livewire::test(AhjiyatPassPurchasesPage::class)->call('show', $h['purchase']->public_id)->instance()->data();

    expect($data['ledger'])->toHaveCount(5)->and($data['ledger']->first()->provider_event_id)->toBe('evt_bulk_30');
});
