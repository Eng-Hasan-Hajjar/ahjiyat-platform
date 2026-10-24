<?php

use App\Models\CommerceProviderEvent;
use App\Models\DigitalPurchase;
use App\Services\Commerce\CommerceProviderState;
use App\Services\Commerce\CommerceReconciler;
use App\Services\Sponsorship\SponsorshipMoney;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/_support.php';

/*
|--------------------------------------------------------------------------
| E41 (BE10 + BE11) - الإيصالات وسجل المشتريات (للمالك وبلا أسرار) ومطابقة التجارة (قراءة فقط)
|--------------------------------------------------------------------------
*/

beforeEach(fn () => e41_seed());

function e41_snapshot(): array
{
    return [
        'purchases' => DB::table('digital_purchases')->orderBy('id')->get()->toJson(),
        'events' => DB::table('digital_purchase_events')->orderBy('id')->get()->toJson(),
        'ledger' => DB::table('commerce_provider_events')->orderBy('id')->get()->toJson(),
        'progress' => DB::table('user_pass_progress')->orderBy('id')->get()->toJson(),
    ];
}

function e41_codes(array $report): array
{
    return collect($report['findings'])->pluck('code')->unique()->values()->all();
}

// ───────────────────────────── BE10: الإيصالات ─────────────────────────────

test('BE10-65: the owner sees the receipt of a paid order', function () {
    $h = e41_paid();

    $this->actingAs($h['user'])->get(route('commerce.receipts.show', $h['purchase']->public_id))->assertOk()->assertSee('إيصال شراء', false)->assertSee('data-commerce-receipt', false)
        ->assertSee('ليس فاتورة ضريبية', false);
});

test('BE10-66: IDOR is blocked — another user gets 404, and an unpaid order has no receipt even for its owner', function () {
    $h = e41_paid();
    $open = e41_hosted($h['build'], e40_player($h['build']['pass']));
    $stranger = e40_player();

    $this->actingAs($stranger)->get(route('commerce.receipts.show', $h['purchase']->public_id))->assertNotFound();
    $this->actingAs($open['user'])->get(route('commerce.receipts.show', $open['purchase']->public_id))->assertNotFound();
    $this->actingAs($open['user'])->get(route('commerce.receipts.show', $h['purchase']->public_id))->assertNotFound();
});

test('BE10-67: the receipt shows the purchase reference and product', function () {
    $h = e41_paid();

    $this->actingAs($h['user'])->get(route('commerce.receipts.show', $h['purchase']->public_id))->assertOk()
        ->assertSee($h['purchase']->reference_code, false)->assertSee($h['purchase']->product_name, false);
});

test('BE10-68: the amount is displayed correctly — including a large amount in minor units', function () {
    $h = e41_paid();
    DB::table('digital_purchases')->where('id', $h['purchase']->id)->update(['amount_minor' => 4_294_967_295 + 123]);
    $label = SponsorshipMoney::format(4_294_967_295 + 123, 'USD');

    $this->actingAs($h['user'])->get(route('commerce.receipts.show', $h['purchase']->public_id))->assertOk()->assertSee($label, false);
    expect($label)->toContain('42,949,674.18');
});

test('BE10-69: the currency is displayed', function () {
    $h = e41_paid();
    DB::table('digital_purchases')->where('id', $h['purchase']->id)->update(['currency' => 'TRY']);

    $response = $this->actingAs($h['user'])->get(route('commerce.receipts.show', $h['purchase']->public_id))->assertOk();

    $response->assertSee('data-receipt-currency', false)->assertSee('TRY', false)->assertSee(SponsorshipMoney::format(500, 'TRY'), false);
});

test('BE10-70: the receipt and history never show a secret — no webhook secret, session id, payment id, checkout URL or fingerprint', function () {
    $h = e41_paid();
    $order = $h['purchase']->fresh();
    $secrets = [(string) config('commerce.providers.sandbox.webhook_secret'), (string) $order->provider_session_id, (string) $order->provider_payment_reference, (string) $order->checkout_url, (string) CommerceProviderEvent::query()->value('payload_sha256'), 'sandbox-not-a-secret'];

    $receipt = $this->actingAs($h['user'])->get(route('commerce.receipts.show', $order->public_id))->assertOk()->getContent();
    $history = $this->actingAs($h['user'])->get(route('commerce.purchases.index'))->assertOk()->getContent();
    $status = $this->actingAs($h['user'])->getJson(route('commerce.orders.status', $order->public_id))->getContent();

    foreach ($secrets as $secret) {
        expect($receipt)->not->toContain($secret)->and($history)->not->toContain($secret)->and($status)->not->toContain($secret);
    }
});

test('BE10-71: internal review notes and decision messages never leak to the buyer', function () {
    $h = e41_paid();
    DB::table('digital_purchases')->where('id', $h['purchase']->id)->update(['review_note' => 'ملاحظة داخلية سرية للمالية', 'decision_message' => 'رسالة قرار داخلية', 'refund_requested_note' => 'ملاحظة استرداد داخلية']);

    foreach ([route('commerce.receipts.show', $h['purchase']->public_id), route('commerce.purchases.index'), route('commerce.checkout.return', $h['purchase']->public_id)] as $url) {
        $this->actingAs($h['user'])->get($url)->assertOk()->assertDontSee('ملاحظة داخلية سرية للمالية', false)->assertDontSee('ملاحظة استرداد داخلية', false);
    }
});

test('BE10-72: no proof path or hash leaks in any buyer-facing page', function () {
    $h = e41_paid();
    DB::table('digital_purchases')->where('id', $h['purchase']->id)->update(['proof_path' => 'payment-proofs/secret-proof-123.png', 'proof_sha256' => str_repeat('ab', 32), 'proof_mime' => 'image/png']);

    foreach ([route('commerce.receipts.show', $h['purchase']->public_id), route('commerce.purchases.index'), route('commerce.checkout.return', $h['purchase']->public_id)] as $url) {
        $this->actingAs($h['user'])->get($url)->assertOk()->assertDontSee('secret-proof-123', false)->assertDontSee(str_repeat('ab', 32), false);
    }
});

test('BE10-x: the history lists only the buyer\'s own orders, paginated, with receipt links for paid orders only', function () {
    config(['commerce.limits.history_per_page' => 2]);
    $h = e41_paid();
    $other = e41_paid($h['build'], e40_player($h['build']['pass']));
    // طلبات إضافية للمالك نفسه عبر إدراج مباشر (مغلقة) لاختبار الترقيم.
    foreach (range(1, 3) as $i) {
        $row = (array) DB::table('digital_purchases')->where('id', $h['purchase']->id)->first();
        unset($row['id']);
        DB::table('digital_purchases')->insert(array_merge($row, ['public_id' => (string) \Illuminate\Support\Str::ulid(), 'reference_code' => 'HIST00000'.$i, 'request_key' => null, 'open_key' => null, 'status' => 'cancelled', 'provider_session_id' => null, 'provider_payment_reference' => null, 'provider_refund_reference' => null]));
    }

    $page = $this->actingAs($h['user'])->get(route('commerce.purchases.index'))->assertOk();

    $page->assertDontSee($other['purchase']->reference_code, false)->assertSee('data-commerce-history', false);
    expect(substr_count($page->getContent(), 'data-commerce-row='))->toBe(2);
    $page->assertSee('page=2', false);

    $all = $this->actingAs($h['user'])->get(route('commerce.purchases.index').'?page=2')->getContent().$page->getContent();
    expect(substr_count($all, 'data-commerce-receipt-link'))->toBe(1);
});

// ───────────────────────────── BE11: المطابقة ─────────────────────────────

test('BE11-73: reconcile is read-only by default — anomalies are reported and nothing in the financial history changes', function () {
    $h = e41_paid();
    DB::table('digital_purchases')->where('id', $h['purchase']->id)->update(['provider_status' => 'pending']);
    DB::table('user_pass_progress')->update(['premium_unlocked_at' => null]);
    $before = e41_snapshot();

    $exit = Artisan::call('commerce:reconcile');
    $json = Artisan::call('commerce:reconcile', ['--json' => true]);

    expect($exit)->toBe(1)->and($json)->toBe(1)->and(e41_snapshot())->toBe($before);

    $clean = e41_snapshot();
    DB::table('user_pass_progress')->update(['premium_unlocked_at' => now()]);
    DB::table('digital_purchases')->where('id', $h['purchase']->id)->update(['provider_status' => 'paid']);

    expect(Artisan::call('commerce:reconcile'))->toBe(0);
});

test('BE11-74: it detects a verified purchase that never opened Premium', function () {
    $h = e41_paid();
    DB::table('user_pass_progress')->update(['premium_unlocked_at' => null, 'premium_purchase_id' => null]);

    $report = app(CommerceReconciler::class)->run();

    expect(e41_codes($report))->toContain('verified_purchase_not_unlocked');
});

test('BE11-75: it detects Premium access with no verified purchase', function () {
    $b = e41_world();
    $user = e40_player($b['pass']);
    DB::table('user_pass_progress')->where('user_id', $user->id)->update(['premium_unlocked_at' => now(), 'premium_purchase_id' => null]);

    $report = app(CommerceReconciler::class)->run();

    expect(e41_codes($report))->toContain('premium_without_verified_purchase');
    // …ولا يُبلَّغ عند حصر المطابقة بمزوّد (الصف بلا شراء مرتبط).
    expect(e41_codes(app(CommerceReconciler::class)->run(['provider' => 'sandbox'])))->not->toContain('premium_without_verified_purchase');
});

test('BE11-76: it detects provider/local mismatches — provider paid while local awaits, and local verified while the provider does not confirm', function () {
    $a = e41_hosted();
    DB::table('digital_purchases')->where('id', $a['purchase']->id)->update(['provider_status' => 'paid']);
    $b = e41_paid($a['build'], e40_player($a['build']['pass']));
    DB::table('digital_purchases')->where('id', $b['purchase']->id)->update(['provider_status' => 'pending']);

    $report = app(CommerceReconciler::class)->run();

    expect(e41_codes($report))->toContain('provider_paid_local_awaiting')->toContain('verified_provider_not_paid');
    expect(collect($report['findings'])->firstWhere('code', 'provider_paid_local_awaiting')['severity'])->toBe('critical');

    // علم مراجعة قائم ⇒ معروف (info) لا critical.
    DB::table('digital_purchases')->where('id', $a['purchase']->id)->update(['review_flag' => 'amount_mismatch', 'review_flagged_at' => now()]);
    $known = app(CommerceReconciler::class)->run(['purchase' => $a['purchase']->reference_code]);

    expect(collect($known['findings'])->firstWhere('code', 'provider_paid_under_review')['severity'])->toBe('info');

    // قرار «تجاهل» موثَّق من مراجع ⇒ معروف أيضًا (info بكود مستقل)، فلا يبقى إنذار critical دائمًا؛ وطلب بلا أي علم يبقى critical.
    e40_premium()->dismissReview(e40_admin(), $a['purchase']->fresh(), 'عولج خارج المنصة وأُغلق');
    $dismissed = app(CommerceReconciler::class)->run(['purchase' => $a['purchase']->reference_code]);

    expect(collect($dismissed['findings'])->firstWhere('code', 'provider_paid_dismissed')['severity'])->toBe('info')->and(e41_codes($dismissed))->not->toContain('provider_paid_local_awaiting');

    DB::table('digital_purchases')->where('id', $a['purchase']->id)->update(['review_flag' => null, 'review_flagged_at' => null, 'review_resolved_at' => null, 'review_resolution' => null]);

    expect(e41_codes(app(CommerceReconciler::class)->run(['purchase' => $a['purchase']->reference_code])))->toContain('provider_paid_local_awaiting');
});

test('BE11-77: it detects an amount mismatch — an applied paid event that differs from the verified order, and an open amount review', function () {
    $h = e41_paid();
    DB::table('commerce_provider_events')->where('digital_purchase_id', $h['purchase']->id)->update(['amount_minor' => 1]);
    $g = e41_hosted($h['build'], e40_player($h['build']['pass']));
    e41_pay($g['session'], ['amount_minor' => 499]);

    $report = app(CommerceReconciler::class)->run();

    expect(e41_codes($report))->toContain('applied_amount_or_currency_mismatch')->toContain('amount_mismatch_open');
});

test('BE11-78: it detects a currency mismatch — an applied paid event in another currency, and an open currency review', function () {
    $h = e41_paid();
    DB::table('commerce_provider_events')->where('digital_purchase_id', $h['purchase']->id)->update(['currency' => 'EUR']);
    $g = e41_hosted($h['build'], e40_player($h['build']['pass']));
    e41_pay($g['session'], ['currency' => 'TRY']);

    $report = app(CommerceReconciler::class)->run();

    expect(e41_codes($report))->toContain('applied_amount_or_currency_mismatch')->toContain('currency_mismatch_open');
});

test('BE11-79: it detects refund mismatches in both directions', function () {
    $b = e41_world();
    $a = e41_paid($b);
    DB::table('digital_purchases')->where('id', $a['purchase']->id)->update(['provider_status' => 'refunded']);
    $c = e41_paid($b, e40_player($b['pass']));
    DB::table('digital_purchases')->where('id', $c['purchase']->id)->update(['status' => 'refunded', 'provider_status' => 'paid', 'refunded_at' => now()]);

    $report = app(CommerceReconciler::class)->run();

    expect(e41_codes($report))->toContain('refunded_provider_local_verified')->toContain('refunded_local_provider_paid');
});

test('BE11-80: findings are bounded — the cap truncates and says so, and the row limit applies', function () {
    config(['commerce.reconcile.max_findings' => 3]);
    $b = e41_world();

    foreach (range(1, 6) as $i) {
        $h = e41_hosted($b, e40_player($b['pass']));
        DB::table('digital_purchases')->where('id', $h['purchase']->id)->update(['provider_status' => 'paid']);
    }

    $report = app(CommerceReconciler::class)->run();

    expect($report['findings'])->toHaveCount(3)->and($report['truncated'])->toBeTrue()->and($report['counts']['provider_paid_local_awaiting'])->toBe(6);

    config(['commerce.reconcile.max_findings' => 200]);
    $limited = app(CommerceReconciler::class)->run(['limit' => 2]);

    expect($limited['counts']['provider_paid_local_awaiting'])->toBe(2);
});

test('BE11-x: --fix-derived only fills the mirror columns from the immutable ledger; --query only compares; scoping works', function () {
    $h = e41_paid();
    DB::table('digital_purchases')->where('id', $h['purchase']->id)->update(['provider_status' => null, 'provider_paid_at' => null]);
    $before = DB::table('digital_purchases')->where('id', $h['purchase']->id)->first();

    $report = app(CommerceReconciler::class)->run(['fix_derived' => true]);
    $after = DB::table('digital_purchases')->where('id', $h['purchase']->id)->first();

    expect($report['fixed'])->toBe(1)->and($after->provider_status)->toBe('paid')->and($after->provider_paid_at)->not->toBeNull()
        ->and($after->status)->toBe($before->status)->and($after->verified_at)->toBe($before->verified_at)
        ->and(DB::table('commerce_provider_events')->count())->toBe(1);

    // استعلام المزوّد: يقارن فقط.
    $g = e41_hosted($h['build'], e40_player($h['build']['pass']));
    e41_sandbox()->simulate($g['session'], CommerceProviderState::Paid);
    $snapshot = e41_snapshot();
    $queried = app(CommerceReconciler::class)->run(['query' => true]);

    expect($queried['queried'])->toBeGreaterThanOrEqual(1)->and(e41_codes($queried))->toContain('provider_query_differs')->and(e41_snapshot())->toBe($snapshot);

    expect(app(CommerceReconciler::class)->run(['provider' => 'manual'])['findings'])->toBe([]);
});

test('BE11-y: the commands are bounded, dry-run safe and never print secrets', function () {
    $h = e41_hosted();
    DB::table('digital_purchases')->where('id', $h['purchase']->id)->update(['created_at' => now()->subHour(), 'expires_at' => now()->subMinute()]);
    $before = e41_snapshot();

    expect(Artisan::call('commerce:process', ['--dry-run' => true]))->toBe(0)->and(e41_snapshot())->toBe($before);
    expect(Artisan::call('commerce:expire', ['--dry-run' => true]))->toBe(0)->and(e41_snapshot())->toBe($before)->and(Artisan::output())->toContain('1');

    Artisan::call('commerce:provider-health', ['--json' => true]);
    $json = Artisan::output();

    expect($json)->not->toContain((string) config('commerce.providers.sandbox.webhook_secret'))->and(json_decode($json, true))->toBeArray();

    Artisan::call('commerce:expire');
    expect($h['purchase']->fresh()->status)->toBe('expired');
});
