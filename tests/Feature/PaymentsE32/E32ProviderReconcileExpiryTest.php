<?php

use App\Models\SponsorshipContribution;
use App\Models\SponsorshipPayment;
use App\Models\SponsorshipProgram;
use App\Models\User;
use App\Services\Payments\PaymentException;
use App\Services\Payments\PaymentInit;
use App\Services\Payments\PaymentProvider;
use App\Services\Payments\PaymentProviderRegistry;
use App\Services\Payments\PaymentReconciliationService;
use App\Services\Payments\ProviderCallback;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/_support.php';

/*
| E32 (AK9 + AK10 + AK11) - عقد المزوّد (مزوّد وهمي داخل الاختبار فقط - لا بوابة حقيقية ولا شبكة)، المطابقة المالية للقراءة فقط، والانتهاء المجدول.
*/

if (! class_exists('E32FakeGateway')) {
    /** مزوّد إلكتروني وهمي يبرهن أن العقد كافٍ: توقيع header بسيط يحاكي HMAC المزوّد الحقيقي. */
    class E32FakeGateway implements PaymentProvider
    {
        public static bool $enabled = true;

        public function key(): string
        {
            return 'fakepay';
        }

        public function label(): string
        {
            return 'بوابة وهمية';
        }

        public function isEnabled(): bool
        {
            return self::$enabled;
        }

        public function supportsRedirect(): bool
        {
            return true;
        }

        public function supportsWebhook(): bool
        {
            return true;
        }

        public function supportsRefund(): bool
        {
            return true;
        }

        public function currencies(): ?array
        {
            return null;
        }

        public function createPayment(SponsorshipPayment $payment): PaymentInit
        {
            return new PaymentInit('ext-'.$payment->reference, 'https://gateway.invalid/pay/'.$payment->reference);
        }

        public function verifyCallback(Request $request): ProviderCallback
        {
            if ($request->header('X-Fake-Signature') !== 'valid-signature') {
                throw new PaymentException('invalid signature');
            }

            return new ProviderCallback((string) $request->input('event'), (string) $request->input('ref'), (string) $request->input('state'), $request->input('amount') === null ? null : (int) $request->input('amount'), $request->input('currency'));
        }

        public function refundReference(SponsorshipPayment $payment): ?string
        {
            return 'refund-'.$payment->provider_reference;
        }

        public function status(SponsorshipPayment $payment): string
        {
            return (string) $payment->status;
        }
    }
}

beforeEach(function () {
    e32_seed();
    E32FakeGateway::$enabled = true;
    config(['payments.providers.fakepay' => ['enabled' => true, 'class' => E32FakeGateway::class], 'payments.webhooks.enabled' => true]);
    app(PaymentProviderRegistry::class)->forget();
});

function e32_gateway_payment(string $amount = '25', string $currency = 'USD'): SponsorshipPayment
{
    return e32_payments()->createRequest(e16User(), e32_program(), $amount, $currency, false, null, 'fakepay');
}

function e32_hook(string $ref, string $state = 'paid', string $event = 'evt-1', ?int $amount = null, ?string $currency = null, string $signature = 'valid-signature', string $provider = 'fakepay')
{
    return test()->withHeaders(['X-Fake-Signature' => $signature])->postJson('/api/payments/webhooks/'.$provider, array_filter(['event' => $event, 'ref' => $ref, 'state' => $state, 'amount' => $amount, 'currency' => $currency, 'secret_token' => 'SUPERSECRET-XYZ-123'], fn ($v) => $v !== null));
}

// ------------------------------------------------------------------ AK9: عقد المزوّد

test('E32-77: an unknown or malformed provider key is rejected before anything is created', function () {
    $user = e16User();
    $program = e32_program();

    foreach (['nope', '../etc/passwd', 'FAKE PAY', '', 'stripe'] as $key) {
        expect(fn () => e32_payments()->createRequest($user, $program, '10', 'USD', false, null, $key === '' ? 'zzz' : $key))->toThrow(PaymentException::class);
    }

    expect(SponsorshipPayment::query()->count())->toBe(0);
});

test('E32-78: a disabled provider is rejected and hidden from checkout', function () {
    E32FakeGateway::$enabled = false;
    $registry = app(PaymentProviderRegistry::class);

    expect(fn () => e32_payments()->createRequest(e16User(), e32_program(), '10', 'USD', false, null, 'fakepay'))->toThrow(PaymentException::class)
        ->and($registry->enabledKeys())->toBe(['manual'])->and(SponsorshipPayment::query()->count())->toBe(0);

    E32FakeGateway::$enabled = true;
    expect($registry->enabledKeys())->toContain('fakepay');

    config(['payments.enabled' => false]);
    expect(fn () => e32_gateway_payment())->toThrow(PaymentException::class);
});

test('E32-79/80: webhooks are closed by default; the manual provider and unknown providers have no webhook; a bad signature changes nothing', function () {
    $payment = e32_gateway_payment();

    config(['payments.webhooks.enabled' => false]);
    e32_hook($payment->provider_reference)->assertNotFound();

    config(['payments.webhooks.enabled' => true]);
    e32_hook($payment->provider_reference, provider: 'manual')->assertNotFound();
    e32_hook($payment->provider_reference, provider: 'unknown')->assertNotFound();
    e32_hook($payment->provider_reference, provider: '..%2Fetc')->assertNotFound();
    e32_hook($payment->provider_reference, signature: 'forged')->assertStatus(400);

    expect($payment->fresh()->status)->toBe('awaiting_payment')->and(SponsorshipContribution::query()->count())->toBe(0);
    $this->get('/api/payments/webhooks/fakepay')->assertStatus(405);
});

test('E32-80b: an oversized webhook body is refused and the endpoint needs no CSRF token (signature is the authentication)', function () {
    $payment = e32_gateway_payment();
    $original = $this->app['env'];
    $this->app['env'] = 'local';

    try {
        $this->withHeaders(['X-Fake-Signature' => 'valid-signature'])->postJson('/api/payments/webhooks/fakepay', ['event' => 'e', 'ref' => $payment->provider_reference, 'state' => 'paid', 'pad' => str_repeat('x', 70000)])->assertStatus(413);
        e32_hook($payment->provider_reference, 'paid', 'evt-csrf')->assertOk();
    } finally {
        $this->app['env'] = $original;
    }

    expect($payment->fresh()->status)->toBe('verified');
});

test('E32-81: provider references are unique per provider (service and database)', function () {
    $a = e32_gateway_payment();
    $b = e32_gateway_payment();

    expect($a->provider_reference)->not->toBe($b->provider_reference);
    expect(fn () => DB::table('sponsorship_payments')->where('id', $b->id)->update(['provider_reference' => $a->provider_reference]))->toThrow(QueryException::class);

    // مزوّد يُرجع المرجع نفسه لطلبين: الثاني يُلغى بأمان ولا يكرّر المرجع.
    $same = new class extends E32FakeGateway
    {
        public function createPayment(SponsorshipPayment $payment): PaymentInit
        {
            return new PaymentInit('ext-fixed-1');
        }
    };
    app(PaymentProviderRegistry::class)->swap('fakepay', $same);
    $first = e32_payments()->createRequest(e16User(), e32_program(), '10', 'USD', false, null, 'fakepay');
    expect($first->provider_reference)->toBe('ext-fixed-1');
    expect(fn () => e32_payments()->createRequest(e16User(), e32_program(), '10', 'USD', false, null, 'fakepay'))->toThrow(PaymentException::class);
    expect(SponsorshipPayment::query()->where('provider_reference', 'ext-fixed-1')->count())->toBe(1)->and(SponsorshipPayment::query()->where('status', 'cancelled')->count())->toBe(1);
});

test('E32-82: a replayed paid callback is idempotent end to end (one contribution, one audit, one notification) and the raw body/secret is never stored', function () {
    $payment = e32_gateway_payment('25');
    $owner = $payment->user;

    e32_hook($payment->provider_reference, 'paid', 'evt-9', 2500, 'USD')->assertOk()->assertJson(['ok' => true, 'result' => 'applied']);
    e32_hook($payment->provider_reference, 'paid', 'evt-9', 2500, 'USD')->assertOk()->assertJson(['result' => 'duplicate']);
    e32_hook($payment->provider_reference, 'paid', 'evt-10', 2500, 'USD')->assertOk()->assertJson(['result' => 'duplicate']);

    expect($payment->fresh()->status)->toBe('verified')->and($payment->fresh()->verified_by)->toBeNull()->and(SponsorshipContribution::query()->count())->toBe(1)
        ->and(e32_audit('payment_verified'))->toBe(1)->and(e32_notes($owner, 'support_confirmed'))->toHaveCount(1)->and(e32_funding($payment->program))->toBe(2500);

    $dump = json_encode([DB::table('operational_audit_logs')->get(), DB::table('sponsorship_payments')->get(), DB::table('notifications')->get()]);
    expect(str_contains((string) $dump, 'SUPERSECRET-XYZ-123'))->toBeFalse();
});

test('E32-82b: callbacks that do not match (amount, currency, unknown reference) never auto-verify; failed and refunded callbacks apply once', function () {
    $mismatch = e32_gateway_payment('25');
    e32_hook($mismatch->provider_reference, 'paid', 'evt-a', 999, 'USD')->assertOk()->assertJson(['result' => 'needs_review']);
    expect($mismatch->fresh()->status)->toBe('awaiting_payment')->and(e32_audit('payment_callback_needs_review'))->toBe(1);

    $foreign = e32_payments()->createRequest(e16User(), e32_program(), '10', 'EUR', false, null, 'fakepay');
    e32_hook($foreign->provider_reference, 'paid', 'evt-b', 1000, 'EUR')->assertOk()->assertJson(['result' => 'needs_review']);
    expect($foreign->fresh()->status)->toBe('awaiting_payment');

    e32_hook('ext-does-not-exist', 'paid', 'evt-c')->assertOk()->assertJson(['result' => 'ignored']);

    $failed = e32_gateway_payment('30');
    e32_hook($failed->provider_reference, 'failed', 'evt-d')->assertOk()->assertJson(['result' => 'applied']);
    e32_hook($failed->provider_reference, 'failed', 'evt-d')->assertOk()->assertJson(['result' => 'duplicate']);
    expect($failed->fresh()->status)->toBe('rejected');

    $paid = e32_gateway_payment('40');
    e32_hook($paid->provider_reference, 'paid', 'evt-e', 4000, 'USD')->assertOk();
    e32_hook($paid->provider_reference, 'refunded', 'evt-f')->assertOk()->assertJson(['result' => 'applied']);
    e32_hook($paid->provider_reference, 'refunded', 'evt-f')->assertOk()->assertJson(['result' => 'duplicate']);
    expect($paid->fresh()->status)->toBe('refunded')->and(e32_contribution($paid)->status)->toBe('refunded')->and(e32_funding($paid->program))->toBe(0);
});

test('E32-79b: the manual provider contract - no redirect, no webhook, no automatic refund, no callback', function () {
    $manual = app(PaymentProviderRegistry::class)->get('manual');

    expect($manual->supportsWebhook())->toBeFalse()->and($manual->supportsRedirect())->toBeFalse()->and($manual->supportsRefund())->toBeFalse()->and($manual->key())->toBe('manual')
        ->and(fn () => $manual->verifyCallback(Request::create('/x')))->toThrow(PaymentException::class)
        ->and($manual->createPayment(e32_request())->providerReference)->toBeNull();
});

// ------------------------------------------------------------------ AK10: المطابقة المالية

test('E32-83: a clean system reports zero mismatches (service and command)', function () {
    e32_verified();
    e32_submitted();
    e32_payments()->refund(e32_finance(), e32_verified(), 'استرداد نظيف');

    expect(app(PaymentReconciliationService::class)->run())->toBe([]);
    $this->artisan('sponsorship:reconcile-payments')->expectsOutputToContain('mismatches=0')->assertExitCode(0);
});

test('E32-84: a verified payment without a contribution is detected', function () {
    $payment = e32_submitted();
    DB::table('sponsorship_payments')->where('id', $payment->id)->update(['status' => 'verified']);

    $codes = array_column(app(PaymentReconciliationService::class)->run(), 'code');
    expect($codes)->toContain('verified_without_contribution');
    $this->artisan('sponsorship:reconcile-payments')->assertExitCode(1);
});

test('E32-85: an amount or currency mismatch between payment and contribution is detected', function () {
    $a = e32_verified();
    DB::table('sponsorship_payments')->where('id', $a->id)->update(['amount_minor' => 1]);
    $b = e32_verified();
    DB::table('sponsorship_payments')->where('id', $b->id)->update(['currency' => 'EUR']);

    $codes = array_column(app(PaymentReconciliationService::class)->run(), 'code');
    expect($codes)->toContain('amount_mismatch')->toContain('currency_mismatch');
});

test('E32-86: refund-state and confirmation mismatches are detected, and an orphan materialized contribution is detected', function () {
    $a = e32_verified();
    DB::table('sponsorship_payments')->where('id', $a->id)->update(['status' => 'refunded']);   // الطلب مسترد والمساهمة ما زالت مؤكَّدة

    $b = e32_verified();
    DB::table('sponsorship_contributions')->where('id', $b->sponsorship_contribution_id)->update(['status' => 'cancelled']);   // طلب محقَّق ومساهمته ملغاة

    $c = e32_verified();
    DB::table('sponsorship_payments')->where('id', $c->id)->update(['sponsorship_contribution_id' => null, 'status' => 'rejected']);   // مساهمة يتيمة

    $codes = array_column(app(PaymentReconciliationService::class)->run(), 'code');
    expect($codes)->toContain('refund_state_mismatch')->toContain('contribution_not_confirmed')->toContain('orphan_materialized_contribution');
});

test('E32-87: the reconciliation output exposes no secrets, names, emails, notes or proof paths', function () {
    $user = e16User(['name' => 'اسم-حساس-للمطابقة', 'email' => 'recon-secret@example.test']);
    $payment = e32_submitted($user);
    e32_payments()->reject(e32_finance(), e32_submitted(), 'other', null, 'ملاحظة-داخلية-حساسة');
    DB::table('sponsorship_payments')->where('id', $payment->id)->update(['status' => 'verified']);
    $path = $payment->fresh()->proof_path;

    $output = '';
    \Illuminate\Support\Facades\Artisan::call('sponsorship:reconcile-payments', ['--json' => true]);
    $output = \Illuminate\Support\Facades\Artisan::output();
    $json = json_decode($output, true);

    expect($json['mismatches'])->toBeGreaterThan(0)->and(array_keys($json['findings'][0]))->toBe(['code', 'payment', 'reference', 'contribution']);
    foreach (['اسم-حساس-للمطابقة', 'recon-secret@example.test', 'ملاحظة-داخلية-حساسة', $path, 'proof', 'payer_note', 'حوّلت من حسابي'] as $secret) {
        expect(str_contains($output, (string) $secret))->toBeFalse("reconcile leaked {$secret}");
    }
});

test('E32-88: reconciliation is read-only by default and with --dry-run (nothing is changed or repaired)', function () {
    $payment = e32_submitted();
    DB::table('sponsorship_payments')->where('id', $payment->id)->update(['status' => 'verified']);
    $snapshot = fn () => json_encode([DB::table('sponsorship_payments')->orderBy('id')->get(), DB::table('sponsorship_contributions')->orderBy('id')->get(), DB::table('operational_audit_logs')->count()]);
    $before = $snapshot();

    $this->artisan('sponsorship:reconcile-payments')->assertExitCode(1);
    $this->artisan('sponsorship:reconcile-payments', ['--dry-run' => true])->assertExitCode(1);
    $this->artisan('sponsorship:reconcile-payments', ['--json' => true])->assertExitCode(1);

    expect($snapshot())->toBe($before);
    $source = (string) file_get_contents(app_path('Services/Payments/PaymentReconciliationService.php'));
    expect($source)->not->toMatch('/->(update|delete|insert|forceFill|save)\(/');
});

// ------------------------------------------------------------------ AK11: الانتهاء

test('E32-89: a stale awaiting request expires after its window, audited, and stays final', function () {
    $payment = e32_request();
    $this->travel(49)->hours();

    expect(e32_payments()->expireStale())->toBe(1);

    $fresh = $payment->fresh();
    expect($fresh->status)->toBe('expired')->and($fresh->expired_at)->not->toBeNull()->and(e32_audit('payment_expired'))->toBe(1)->and(e32_funding($payment->program))->toBe(0);
    expect(fn () => e32_payments()->markTransferred($payment->user, $fresh, 'متأخر'))->toThrow(PaymentException::class);
});

test('E32-90: a fresh awaiting request stays open', function () {
    $payment = e32_request();
    $this->travel(47)->hours();

    expect(e32_payments()->expireStale())->toBe(0)->and($payment->fresh()->status)->toBe('awaiting_payment');
});

test('E32-91: submitted, verified and refunded payments never expire', function () {
    $submitted = e32_submitted();
    $verified = e32_verified();
    $refunded = e32_verified();
    e32_payments()->refund(e32_finance(), $refunded, 'استرداد قبل الانتهاء');
    $this->travel(400)->days();

    expect(e32_payments()->expireStale())->toBe(0)
        ->and($submitted->fresh()->status)->toBe('submitted')->and($verified->fresh()->status)->toBe('verified')->and($refunded->fresh()->status)->toBe('refunded');
});

test('E32-92: the scheduled command is idempotent, batch-bounded and registered on the scheduler', function () {
    $a = e32_request();
    $b = e32_request();
    $this->travel(49)->hours();

    $this->artisan('payments:expire-stale')->assertExitCode(0);
    expect(SponsorshipPayment::query()->where('status', 'expired')->count())->toBe(2);
    $this->artisan('payments:expire-stale')->assertExitCode(0);
    expect(SponsorshipPayment::query()->where('status', 'expired')->count())->toBe(2)->and(e32_audit('payment_expired'))->toBe(2);

    $events = collect(app(Schedule::class)->events())->map(fn ($e) => (string) $e->description)->all();
    expect($events)->toContain('payments-expire-stale');

    config(['payments.request.expire_batch' => 1]);
    e32_request();
    e32_request();
    $this->travel(49)->hours();
    expect(e32_payments()->expireStale())->toBe(1)->and(e32_payments()->expireStale())->toBe(1);
});
