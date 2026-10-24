<?php

use App\Filament\Pages\AhjiyatPassPurchasesPage;
use App\Filament\Pages\CommerceOperationsPage;
use App\Models\CommerceProviderEvent;
use App\Models\DigitalPurchase;
use App\Services\Commerce\CommerceAnalyticsService;
use App\Services\Commerce\CommerceHealthService;
use App\Services\Commerce\CommerceMaintenanceService;
use App\Services\Commerce\CommerceNotifier;
use App\Services\Commerce\CommerceProviderState;
use App\Services\Commerce\SandboxCommercePaymentProvider;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

require_once __DIR__.'/_support.php';
require_once __DIR__.'/../OperationsE25/_support.php';

/*
|--------------------------------------------------------------------------
| E41 - لوحة الإدارة والعمليات: المراجعة المالية، صحة المزوّدين، التحليلات، الصيانة المجدولة، فحص الإنتاج
|--------------------------------------------------------------------------
*/

beforeEach(fn () => e41_seed());

/** طلب مستضاف مدفوع بمبلغ غير مطابق ⇒ علم مراجعة مالية (لم يُفتح المميز). */
function e41_flagged(?array $build = null): array
{
    $h = e41_hosted($build);
    e41_pay($h['session'], ['amount_minor' => 499]);

    return ['purchase' => $h['purchase']->fresh()] + $h;
}

function e41_page(?\App\Models\User $staff = null): \Livewire\Features\SupportTesting\Testable
{
    test()->actingAs($staff ?? e40_admin());

    return Livewire::test(AhjiyatPassPurchasesPage::class);
}

// ───────────────────────── صفحة مشتريات المميز: المراجعة المالية ─────────────────────────

test('OPS-1: accepting a flagged payment needs a documented note (>=5 chars), then unlocks through the one shared path and resolves the flag', function () {
    $f = e41_flagged();
    $order = $f['purchase'];

    expect($order->needsReview())->toBeTrue()->and($order->status)->toBe(DigitalPurchase::STATUS_AWAITING);

    foreach (['', 'ok', '    '] as $note) {
        e41_page()->call('show', $order->public_id)->set('reviewNote', $note)->call('verify');
        expect($order->fresh()->status)->toBe(DigitalPurchase::STATUS_AWAITING)->and(e40_row($f['user'], $f['build']['pass'])?->premium_unlocked_at)->toBeNull();
    }

    e41_page()->call('show', $order->public_id)->set('reviewNote', 'تمت المطابقة مع كشف المزوّد')->call('verify');
    $after = $order->fresh();

    expect($after->status)->toBe(DigitalPurchase::STATUS_VERIFIED)->and($after->needsReview())->toBeFalse()->and($after->review_resolved_at)->not->toBeNull()
        ->and(e40_row($f['user'], $f['build']['pass'])->premium_unlocked_at)->not->toBeNull()
        ->and(e40_notes($f['user'], 'pass_premium_unlocked'))->toHaveCount(1);

    // مرة ثانية: لا أثر مضاعف.
    e41_page()->call('show', $order->public_id)->set('reviewNote', 'محاولة مكررة للتحقق')->call('verify');
    expect(e40_notes($f['user'], 'pass_premium_unlocked'))->toHaveCount(1)->and($order->fresh()->verified_at->eq($after->verified_at))->toBeTrue();
});

test('OPS-2: dismissing a review needs a note, closes the flag without unlocking, and the purchase leaves the review tab', function () {
    $f = e41_flagged();
    $order = $f['purchase'];

    e41_page()->call('show', $order->public_id)->set('reviewNote', 'قص')->call('dismissReview');
    expect($order->fresh()->needsReview())->toBeTrue();

    $page = e41_page()->call('show', $order->public_id)->set('reviewNote', 'عولج خارج المنصة وأُغلق')->call('dismissReview');
    $after = $order->fresh();

    expect($after->needsReview())->toBeFalse()->and($after->review_resolution)->toBe('dismissed')->and($after->status)->toBe(DigitalPurchase::STATUS_AWAITING)
        ->and(e40_row($f['user'], $f['build']['pass'])?->premium_unlocked_at)->toBeNull();

    $ids = $page->set('tab', 'review')->instance()->data()['page']->pluck('id')->all();
    expect($ids)->not->toContain($order->id);
});

test('OPS-3: refunding an unmatched payment goes through the provider once, resolves the flag as refunded, and a stray double click does not refund twice', function () {
    $f = e41_flagged();
    $order = $f['purchase'];

    e41_page()->call('show', $order->public_id)->set('refundNote', 'x')->call('refundUnmatched');
    expect(SandboxCommercePaymentProvider::counter('refund_calls'))->toBe(0);

    e41_page()->call('show', $order->public_id)->set('refundNote', 'دفعة بمبلغ غير مطابق')->call('refundUnmatched');
    $after = $order->fresh();

    expect(SandboxCommercePaymentProvider::counter('refund_calls'))->toBe(1)->and($after->review_resolution)->toBe('refunded')->and($after->provider_status)->toBe('refunded')
        ->and($after->provider_refund_reference)->not->toBeNull()->and(e40_row($f['user'], $f['build']['pass'])?->premium_unlocked_at)->toBeNull()
        ->and(e40_notes($f['user'], 'commerce_refunded'))->toHaveCount(1);

    e41_page()->call('show', $order->public_id)->set('refundNote', 'نقرة مكررة على الاسترداد')->call('refundUnmatched');

    expect(SandboxCommercePaymentProvider::counter('refund_calls'))->toBe(1)->and(e40_notes($f['user'], 'commerce_refunded'))->toHaveCount(1);
});

test('OPS-4: manual sync pulls the provider state through the trusted path, and a provider outage changes nothing and surfaces no secret', function () {
    $h = e41_hosted();
    e41_sandbox()->simulate($h['session'], CommerceProviderState::Paid); // المزوّد دُفع عنده ولم يصل webhook

    SandboxCommercePaymentProvider::simulateOutage(true);
    e41_page()->call('show', $h['purchase']->public_id)->call('syncNow');
    expect($h['purchase']->fresh()->status)->toBe(DigitalPurchase::STATUS_AWAITING);

    SandboxCommercePaymentProvider::simulateOutage(false);
    $page = e41_page()->call('show', $h['purchase']->public_id);
    expect($page->instance()->data()['canSync'])->toBeTrue();
    $page->call('syncNow');

    expect($h['purchase']->fresh()->status)->toBe(DigitalPurchase::STATUS_VERIFIED)->and(e40_notes($h['user'], 'pass_premium_unlocked'))->toHaveCount(1);

    // مزامنة مكررة: لا شيء جديد.
    $page->call('syncNow');
    expect(e40_notes($h['user'], 'pass_premium_unlocked'))->toHaveCount(1);
});

test('OPS-5: permissions are separate — viewers cannot verify/dismiss/refund/sync, verifiers cannot refund, plain staff cannot open the pages', function () {
    $f = e41_flagged();
    $order = $f['purchase'];
    $viewer = e40_staff_with(['passes.view-purchases']);
    $verifier = e40_staff_with(['passes.view-purchases', 'passes.verify-purchases']);

    e41_page($viewer)->call('show', $order->public_id)->set('reviewNote', 'محاولة بلا صلاحية')->call('verify')->call('dismissReview')->call('syncNow')
        ->set('refundNote', 'محاولة بلا صلاحية')->call('refundUnmatched');
    expect($order->fresh()->status)->toBe(DigitalPurchase::STATUS_AWAITING)->and($order->fresh()->needsReview())->toBeTrue()->and(SandboxCommercePaymentProvider::counter('refund_calls'))->toBe(0);

    e41_page($verifier)->call('show', $order->public_id)->set('refundNote', 'محاولة بلا صلاحية استرداد')->call('refundUnmatched');
    expect(SandboxCommercePaymentProvider::counter('refund_calls'))->toBe(0)->and($order->fresh()->needsReview())->toBeTrue();

    $plain = e40_plain_staff();
    $this->actingAs($plain)->get(AhjiyatPassPurchasesPage::getUrl())->assertForbidden();
    $this->actingAs($plain)->get(CommerceOperationsPage::getUrl())->assertForbidden();
});

test('OPS-5b: a reviewer holding only the view permission can open both pages over HTTP (and sees no action it cannot perform)', function () {
    $f = e41_flagged();
    $viewer = e40_plain_staff();
    $viewer->givePermissionTo('passes.view-purchases');

    $this->actingAs($viewer)->get(CommerceOperationsPage::getUrl())->assertOk()->assertSee('data-commerce-providers', false);
    $this->actingAs($viewer)->get(AhjiyatPassPurchasesPage::getUrl())->assertOk();

    $html = e41_page($viewer)->call('show', $f['purchase']->public_id)->html();
    expect($html)->not->toContain('data-purchase-verify-reviewed')->and($html)->not->toContain('data-purchase-refund-unmatched');
});

test('OPS-6: filters (provider/status/currency/dates) and the review tab narrow the list; hostile filter values are ignored, never executed', function () {
    $b = e41_world();
    $paid = e41_hosted($b);
    e41_pay($paid['session']);
    $flagged = e41_hosted($b);
    e41_pay($flagged['session'], ['amount_minor' => 499]);
    $open = e41_hosted($b);
    DB::table('digital_purchases')->where('id', $open['purchase']->id)->update(['currency' => 'TRY']);

    $ids = fn ($page) => $page->instance()->data()['page']->pluck('id')->sort()->values()->all();
    $page = e41_page()->set('tab', 'all');

    expect($ids($page))->toBe(collect([$paid['purchase']->id, $flagged['purchase']->id, $open['purchase']->id])->sort()->values()->all())
        ->and($ids($page->set('tab', 'review')))->toBe([$flagged['purchase']->id])
        ->and($ids($page->set('tab', 'all')->set('filterStatus', 'verified')))->toBe([$paid['purchase']->id])
        ->and($ids($page->set('filterStatus', '')->set('filterCurrency', 'TRY')))->toBe([$open['purchase']->id])
        ->and($ids($page->set('filterCurrency', '')->set('filterProvider', 'manual')))->toBe([])
        ->and($ids($page->set('filterProvider', 'sandbox')->set('filterFrom', '2026-12-10')->set('filterTo', '2026-12-10')))->toHaveCount(3)
        ->and($ids($page->set('filterFrom', '2027-01-01')->set('filterTo', '')))->toBe([]);

    $before = DigitalPurchase::query()->count();
    $page->set('filterFrom', '')->set('filterProvider', "x' OR '1'='1")->set('filterStatus', 'verified; DROP TABLE digital_purchases')->set('filterCurrency', 'usd" OR 1=1')->set('filterTo', '2026-13-45 OR 1');

    expect($ids($page))->toHaveCount(3)->and(DigitalPurchase::query()->count())->toBe($before);
});

test('OPS-7: the detail view shows provider references, the append-only ledger and the review box — never the checkout URL, secrets or raw payloads', function () {
    $f = e41_flagged();
    $page = e41_page()->call('show', $f['purchase']->public_id);
    $html = $page->html();

    foreach (['data-purchase-provider', 'data-purchase-ledger', 'data-purchase-review-flag', 'data-purchase-verify-reviewed', 'data-purchase-dismiss', 'data-purchase-refund-unmatched'] as $marker) {
        expect($html)->toContain($marker);
    }

    expect($html)->not->toContain($f['url'])->and($html)->not->toContain('sandbox-not-a-secret')->and($html)->not->toContain((string) config('app.key'))
        ->and($html)->not->toContain('payload_sha256')->and($html)->toContain($f['purchase']->provider_session_id);
});

// ───────────────────────── صفحة عمليات الدفع ─────────────────────────

test('OPS-8: the operations page lists provider health as configured/missing with no secret, per-currency money with no mixed total, and nothing mutates', function () {
    $b = e41_world();
    $usd = e41_hosted($b);
    e41_pay($usd['session']);
    $try = e41_hosted($b);
    e41_pay($try['session']);
    DB::table('digital_purchases')->where('id', $try['purchase']->id)->update(['currency' => 'TRY', 'amount_minor' => 12345]);
    $snapshot = DB::table('digital_purchases')->orderBy('id')->get()->toJson();

    $this->actingAs(e40_staff_with(['passes.view-purchases']));
    $page = Livewire::test(CommerceOperationsPage::class)->assertSuccessful();
    $html = $page->html();

    foreach (['data-commerce-providers', 'data-commerce-currencies', 'data-commerce-funnel', 'data-commerce-rates'] as $marker) {
        expect($html)->toContain($marker);
    }

    $stats = $page->instance()->data()['stats'];

    expect(array_keys($stats['currencies']))->toBe(['TRY', 'USD'])->and($stats['currencies']['USD']['gross_minor'])->toBe(500)->and($stats['currencies']['TRY']['gross_minor'])->toBe(12345)
        ->and($stats)->not->toHaveKey('total_minor')->and($stats)->not->toHaveKey('gross_minor')
        ->and($html)->not->toContain('sandbox-not-a-secret')->and($html)->not->toContain((string) config('app.key'))->and($html)->not->toContain($usd['purchase']->user->email)
        ->and(DB::table('digital_purchases')->orderBy('id')->get()->toJson())->toBe($snapshot);
});

test('OPS-9: the in-page check is opt-in and read-only — nothing is checked until asked, and running it writes nothing', function () {
    $f = e41_flagged();
    DB::table('digital_purchases')->where('id', $f['purchase']->id)->update(['provider_payment_reference' => null]);
    $before = [DB::table('digital_purchases')->get()->toJson(), DB::table('commerce_provider_events')->count(), DB::table('operational_audit_logs')->count()];

    $this->actingAs(e40_staff_with(['passes.view-purchases']));
    $page = Livewire::test(CommerceOperationsPage::class);
    expect($page->instance()->data()['report'])->toBeNull()->and($page->html())->not->toContain('data-commerce-report');

    $page->call('runCheck');
    $report = $page->instance()->data()['report'];

    expect($report)->toHaveKeys(['findings', 'counts'])->and($page->html())->toContain('data-commerce-report')
        ->and([DB::table('digital_purchases')->get()->toJson(), DB::table('commerce_provider_events')->count(), DB::table('operational_audit_logs')->count()])->toBe($before);
});

test('OPS-10: events for an unknown purchase appear in the unmatched list (no payload, no secret) and never create a purchase', function () {
    $unknown = e41_sandbox()->simulate('sbx_'.str_repeat('a', 24), CommerceProviderState::Paid);
    $result = e41_webhooks()->handle('sandbox', $unknown['payload'], $unknown['headers']);

    expect($result['result'])->toBeIn(['ignored', 'needs_review']);

    $this->actingAs(e40_staff_with(['passes.view-purchases']));
    $page = Livewire::test(CommerceOperationsPage::class);
    $rows = $page->instance()->data()['unmatched'];

    expect($rows)->toHaveCount(1)->and($rows->first()->digital_purchase_id)->toBeNull()->and($page->html())->toContain('data-commerce-unmatched')->and($page->html())->not->toContain($unknown['payload'])
        ->and(DigitalPurchase::query()->count())->toBe(0);
});

test('OPS-11: provider health counts recent signature failures and queues without ever exposing configuration values', function () {
    $b = e41_world();
    e41_hosted($b);
    e41_flagged($b);

    foreach ([1, 2] as $i) {
        e41_post_webhook($this, 'sandbox', '{"x":'.$i.'}', ['X-Commerce-Signature' => 't=1,v1=bad'])->assertStatus(401);
    }

    $health = collect(app(CommerceHealthService::class)->providers())->keyBy('key');
    $sandbox = $health['sandbox'];

    expect($sandbox['configured'])->toBeTrue()->and($sandbox['enabled'])->toBeTrue()->and($sandbox['recent_failures'])->toBeGreaterThanOrEqual(2)->and($sandbox['awaiting'])->toBe(1)->and($sandbox['needs_review'])->toBe(1)
        ->and($sandbox['mode'])->toBe('sandbox')->and($health['manual']['redirect'])->toBeFalse();

    $json = json_encode($health->all());
    expect($json)->not->toContain('sandbox-not-a-secret')->and($json)->not->toContain((string) config('app.key'));

    Artisan::call('commerce:provider-health', ['--json' => true]);
    $out = json_decode(Artisan::output(), true);

    expect($out)->toBeArray()->and(Artisan::output())->not->toContain('sandbox-not-a-secret');
});

test('OPS-12: analytics are aggregate-only — rates per provider, review-flag counts, no individuals, no references', function () {
    $b = e41_world();
    $ok = e41_hosted($b);
    e41_pay($ok['session']);
    $bad = e41_hosted($b);
    e41_state(CommerceProviderState::Failed, $bad['session']);
    $flag = e41_hosted($b);
    e41_pay($flag['session'], ['amount_minor' => 499]);
    e41_hosted($b); // مهجور (بانتظار الدفع)

    $stats = app(CommerceAnalyticsService::class)->summary();
    $provider = $stats['by_provider']['sandbox'];

    expect($stats['created'])->toBe(4)->and($provider['created'])->toBe(4)->and($provider['verified'])->toBe(1)->and($provider['conversion_rate'])->toBe(0.25)
        ->and($provider['states']['failed'])->toBe(1)->and($stats['review_flags'])->toBe(['amount_mismatch' => 1])->and($stats['currencies']['USD']['gross_minor'])->toBe(500);

    $json = json_encode($stats);

    foreach ([$ok['user']->email, $ok['user']->name, $ok['purchase']->reference_code, $ok['purchase']->provider_session_id, (string) $ok['purchase']->public_id] as $needle) {
        expect($json)->not->toContain((string) $needle);
    }

    expect(app(CommerceAnalyticsService::class)->summary(1)['window_days'])->toBe(1)->and(app(CommerceAnalyticsService::class)->summary(9999)['window_days'])->toBe(365);
});

// ───────────────────────── الصيانة المجدولة والأوامر ─────────────────────────

test('OPS-13: the scheduled sync only touches stale hosted orders, is dry-run safe, isolates a provider outage, and never double-applies', function () {
    $b = e41_world();
    $old = e41_hosted($b);
    $young = e41_hosted($b);
    e41_sandbox()->simulate($old['session'], CommerceProviderState::Paid);
    e41_sandbox()->simulate($young['session'], CommerceProviderState::Paid);
    DB::table('digital_purchases')->where('id', $old['purchase']->id)->update(['created_at' => now()->subMinutes(30)]);

    $service = app(CommerceMaintenanceService::class);

    expect($service->run(true)['dry_run'])->toBeTrue()->and($old['purchase']->fresh()->status)->toBe(DigitalPurchase::STATUS_AWAITING);

    SandboxCommercePaymentProvider::simulateOutage(true);
    $down = $service->run();
    expect($down['unavailable'])->toBe(1)->and($old['purchase']->fresh()->status)->toBe(DigitalPurchase::STATUS_AWAITING);

    SandboxCommercePaymentProvider::simulateOutage(false);
    $ran = $service->run();

    expect($ran['applied'])->toBe(1)->and($old['purchase']->fresh()->status)->toBe(DigitalPurchase::STATUS_VERIFIED)->and($young['purchase']->fresh()->status)->toBe(DigitalPurchase::STATUS_AWAITING);

    $again = $service->run();
    expect($again['applied'])->toBe(0)->and(e40_notes($old['user'], 'pass_premium_unlocked'))->toHaveCount(1);
});

test('OPS-14: notification retry is idempotent — repeated maintenance and repeated notifier calls never duplicate a notice, and a frozen buyer gets none', function () {
    $b = e41_world();
    $paid = e41_paid($b);
    $flag = e41_flagged($b);
    $service = app(CommerceMaintenanceService::class);

    $service->run();
    $service->run();
    app(CommerceNotifier::class)->actionRequired($flag['purchase']->fresh());
    app(CommerceNotifier::class)->actionRequired($flag['purchase']->fresh());

    expect(e40_notes($paid['user'], 'pass_premium_unlocked'))->toHaveCount(1)->and(e40_notes($flag['user'], 'commerce_action_required'))->toHaveCount(1);

    $frozen = e41_flagged($b);
    $frozen['user']->forceFill(['is_frozen' => true])->save();
    app(CommerceNotifier::class)->refunded($frozen['purchase']->fresh());

    expect(e40_notes($frozen['user'], 'commerce_refunded'))->toHaveCount(0);
});

test('OPS-15: commerce:expire expires overdue unflagged orders only (dry-run first), commerce:process is schedulable, and neither command prints secrets', function () {
    $b = e41_world();
    $overdue = e41_hosted($b);
    $flagged = e41_flagged($b);
    DB::table('digital_purchases')->whereIn('id', [$overdue['purchase']->id, $flagged['purchase']->id])->update(['expires_at' => now()->subHour()]);

    Artisan::call('commerce:expire', ['--dry-run' => true]);
    expect($overdue['purchase']->fresh()->status)->toBe(DigitalPurchase::STATUS_AWAITING);

    Artisan::call('commerce:expire');
    expect($overdue['purchase']->fresh()->status)->toBe(DigitalPurchase::STATUS_EXPIRED)->and($flagged['purchase']->fresh()->status)->toBe(DigitalPurchase::STATUS_AWAITING)->and($flagged['purchase']->fresh()->needsReview())->toBeTrue();

    expect(Artisan::call('commerce:process', ['--dry-run' => true]))->toBe(0)->and(Artisan::output())->not->toContain('sandbox-not-a-secret');

    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'commerce:process'));
    expect($event)->not->toBeNull()->and($event->expression)->toBe('*/10 * * * *')->and($event->withoutOverlapping)->toBeTrue();
});

// ───────────────────────── فحص الإنتاج ─────────────────────────

test('OPS-16: production:check fails when the sandbox provider is enabled or the default in production, passes with a real default, only warns outside production', function () {
    e25_as_production();
    config(['app.debug' => false, 'app.url' => 'https://ahjiyat.example.com', 'commerce.providers.sandbox.enabled' => true, 'commerce.default_provider' => 'sandbox']);
    [$code, $data] = e25_check();

    expect($code)->toBe(1)->and(e25_statuses($data, 'COMMERCE_SANDBOX'))->toBe(['FAIL']);

    config(['commerce.providers.sandbox.enabled' => false, 'commerce.default_provider' => 'manual', 'commerce.fallback_provider' => 'manual']);
    [, $clean] = e25_check();

    expect(e25_statuses($clean, 'COMMERCE_SANDBOX'))->toBe([])->and(e25_statuses($clean, 'COMMERCE_DEFAULT_PROVIDER'))->toBe(['PASS']);

    config(['commerce.default_provider' => 'sandbox']);
    config(['app.env' => 'local']);
    app()['env'] = 'local';
    [, $dev] = e25_check();

    expect(e25_statuses($dev, 'COMMERCE_DEFAULT_PROVIDER'))->toBe(['WARN'])->and(e25_statuses($dev, 'COMMERCE_SANDBOX'))->toBe([]);
});

test('OPS-17: while a provider refund is unresolved, a reviewer cannot confirm the payment or dismiss the flag — a recorded failure or a confirmed refund lifts the block', function () {
    $b = e41_world();
    $f = e41_flagged($b);
    $order = $f['purchase'];

    SandboxCommercePaymentProvider::forceRefundMode('pending');
    e41_page()->call('show', $order->public_id)->set('refundNote', 'استرداد قيد المعالجة')->call('refundUnmatched');
    $pending = $order->fresh();

    expect($pending->refund_requested_at)->not->toBeNull()->and($pending->needsReview())->toBeTrue()->and($pending->refund_error)->toBeNull();

    // لا تأكيد ولا تجاهل: نتيجة الاسترداد عند المزوّد مجهولة.
    e41_page()->call('show', $order->public_id)->set('reviewNote', 'تأكيد أثناء الاسترداد')->call('verify');
    e41_page()->call('show', $order->public_id)->set('reviewNote', 'تجاهل أثناء الاسترداد')->call('dismissReview');
    $still = $order->fresh();

    expect($still->status)->toBe(DigitalPurchase::STATUS_AWAITING)->and($still->needsReview())->toBeTrue()->and(e40_row($f['user'], $f['build']['pass'])?->premium_unlocked_at)->toBeNull()
        ->and(e41_reason(fn () => e40_premium()->verify(e40_admin(), $still, 'تأكيد أثناء الاسترداد')))->toBe('refund_in_progress')
        ->and(e41_reason(fn () => e40_premium()->dismissReview(e40_admin(), $still, 'تجاهل أثناء الاسترداد')))->toBe('refund_in_progress');

    // المزوّد يؤكّد الاسترداد بحدث موقَّع ⇒ يُغلق العلم باسترداد، دون فتح المميز.
    e41_state(CommerceProviderState::Refunded, $f['session']);
    $done = $order->fresh();

    expect($done->providerState())->toBe(CommerceProviderState::Refunded)->and(e40_row($f['user'], $f['build']['pass'])?->premium_unlocked_at)->toBeNull();

    // فشل مسجَّل (نتيجة معلومة) يرفع الحجب: تُسمح قرارات المراجعة.
    SandboxCommercePaymentProvider::forceRefundMode(null);
    $g = e41_flagged($b);
    SandboxCommercePaymentProvider::forceRefundMode('failed');
    e41_page()->call('show', $g['purchase']->public_id)->set('refundNote', 'استرداد سيفشل')->call('refundUnmatched');
    expect($g['purchase']->fresh()->refund_error)->not->toBeNull();

    e41_page()->call('show', $g['purchase']->public_id)->set('reviewNote', 'قرار بعد فشل الاسترداد')->call('dismissReview');

    expect($g['purchase']->fresh()->needsReview())->toBeFalse()->and($g['purchase']->fresh()->review_resolution)->toBe('dismissed');
    SandboxCommercePaymentProvider::forceRefundMode(null);
});
