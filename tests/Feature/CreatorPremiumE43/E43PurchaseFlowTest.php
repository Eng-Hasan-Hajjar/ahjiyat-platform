<?php

use App\Models\CreatorDirectSale;
use App\Models\CreatorEarningAccount;
use App\Models\CreatorEarningEntry;
use App\Models\CreatorSeries;
use App\Models\CreatorSeriesAccess;
use App\Models\CreatorSeriesSaleOffer;
use App\Models\DigitalPurchase;
use App\Services\Commerce\CommerceProductRegistry;
use App\Services\CreatorFinance\CreatorLedgerService;
use App\Services\CreatorFinance\CreatorMonetizationService;
use App\Services\CreatorPremium\CreatorDirectSaleService;
use App\Services\CreatorPremium\CreatorPremiumNotifier;
use App\Services\CreatorPremium\CreatorPremiumPolicy;
use App\Services\CreatorPremium\CreatorPremiumRefundService;
use App\Services\CreatorPremium\CreatorSeriesAccessProductHandler;
use App\Services\CreatorSeries\CreatorSeriesVisibilityService;
use App\Services\OperationalAuditService;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/_support.php';

/*
|--------------------------------------------------------------------------
| E43 (3/9) - طلب الشراء من الخادم (السعر/العملة/المبدع)، idempotency، معاملة التحقق الواحدة (وصول + قيد دفتر + لقطة بيع)، التحقق المكرر،
| الحالات المحجوبة ⇒ مراجعة مالية (لا فتح صامت)، التحقق بعد المراجعة، الذرّية عند الفشل، الإشعارات، وعزل الاقتصاد.
|--------------------------------------------------------------------------
*/

beforeEach(fn () => e43_seed());

// ───────────── الطلب ─────────────

test('E43-PF-01: the order snapshot comes entirely from the server (series, price, currency, provider) and is hosted-only', function () {
    $world = e43_world(['price' => '12.34']);
    $buyer = e16User();

    $purchase = e43_purchases()->request($buyer, $world['series']);

    expect($purchase->product_type)->toBe('creator_series_access')->and($purchase->product_id)->toBe($world['series']->id)->and($purchase->product_public_id)->toBe($world['series']->public_id)
        ->and($purchase->amount_minor)->toBe(1234)->and($purchase->currency)->toBe('USD')->and($purchase->status)->toBe(DigitalPurchase::STATUS_AWAITING)
        ->and($purchase->provider)->toBe('sandbox')->and($purchase->reference_code)->toStartWith('CSA-')->and($purchase->user_id)->toBe($buyer->id)
        ->and($purchase->expires_at)->not->toBeNull()
        ->and($purchase->open_key)->toBe(\App\Services\CreatorPremium\CreatorSeriesPurchaseService::openKey($world['series']->id, $buyer->id));
});

test('E43-PF-02: requesting twice returns the same open order (no second order, no silent repricing)', function () {
    $world = e43_world(['price' => '5.00']);
    $buyer = e16User();
    $first = e43_purchases()->request($buyer, $world['series']);

    e43_tick();
    e43_offers()->updatePrice($world['user'], $world['offer']->public_id, '9.00');
    $second = e43_purchases()->request($buyer, $world['series'], 'another-key-12345');

    expect($second->id)->toBe($first->id)->and($second->amount_minor)->toBe(500)->and(DigitalPurchase::query()->where('user_id', $buyer->id)->count())->toBe(1);
});

test('E43-PF-03: the same request_key returns the same order; a key reused for another series never returns that other series order', function () {
    $world = e43_world();
    $other = e30s_published($world['user'], e30s_works($world['user'], 2));
    e43_offer($world['user'], $other, '3.00');
    $buyer = e16User();

    $a = e43_purchases()->request($buyer, $world['series'], 'shared-key-0001');
    $again = e43_purchases()->request($buyer, $world['series'], 'shared-key-0001');
    $b = e43_purchases()->request($buyer, $other, 'shared-key-0001');

    expect($again->id)->toBe($a->id)->and($b->id)->not->toBe($a->id)->and($b->product_id)->toBe($other->id)->and($b->amount_minor)->toBe(300);
});

test('E43-PF-04: a stale awaiting order is expired and replaced by a fresh one', function () {
    $world = e43_world();
    $buyer = e16User();
    $old = e43_purchases()->request($buyer, $world['series']);
    DB::table('digital_purchases')->where('id', $old->id)->update(['expires_at' => now()->subMinute()]);

    $new = e43_purchases()->request($buyer, $world['series']);

    expect($new->id)->not->toBe($old->id)->and($old->fresh()->status)->toBe(DigitalPurchase::STATUS_EXPIRED)->and($new->status)->toBe(DigitalPurchase::STATUS_AWAITING);
});

test('E43-PF-05: refusals - creator, frozen, owner, hidden series, no offer, paused offer, feature off, commerce off, no hosted provider', function () {
    $world = e43_world();
    $other = e30s_published($world['user'], e30s_works($world['user'], 2));
    $frozen = e16User(['is_frozen' => true]);
    $owner = e43_buy($world)['user'];

    expect(e43_reason(fn () => e43_purchases()->request($world['user'], $world['series'])))->toBe('own_series')
        ->and(e43_reason(fn () => e43_purchases()->request($frozen, $world['series'])))->toBe('frozen')
        ->and(e43_reason(fn () => e43_purchases()->request($owner, $world['series'])))->toBe('already_owned')
        ->and(e43_reason(fn () => e43_purchases()->request(e16User(), $other)))->toBe('not_for_sale');

    e43_offers()->pause($world['user'], $world['offer']->public_id);
    expect(e43_reason(fn () => e43_purchases()->request(e16User(), $world['series'])))->toBe('sales_closed');
    e43_offers()->activate($world['user'], $world['offer']->public_id);

    DB::table('creator_series')->where('id', $world['series']->id)->update(['hidden_at' => now()]);
    expect(e43_reason(fn () => e43_purchases()->request(e16User(), $world['series'])))->toBe('series_unavailable');
    DB::table('creator_series')->where('id', $world['series']->id)->update(['hidden_at' => null]);

    config(['commerce.enabled' => false]);
    expect(e43_reason(fn () => e43_purchases()->request(e16User(), $world['series'])))->toBe('commerce_disabled');
    config(['commerce.enabled' => true]);

    config(['commerce.providers.sandbox.enabled' => false]);
    e41_registry()->forget('sandbox');
    expect(e43_reason(fn () => e43_purchases()->request(e16User(), $world['series'])))->toBe('provider_disabled');
    config(['commerce.providers.sandbox.enabled' => true]);
    e41_registry()->forget('sandbox');

    config(['creator_premium.enabled' => false]);
    expect(e43_reason(fn () => e43_purchases()->request(e16User(), $world['series'])))->toBe('disabled');
});

test('E43-PF-06: HTTP order route - price, currency and creator come from the server only, then redirect to the hosted provider', function () {
    $world = e43_world(['price' => '5.00']);
    $buyer = e16User();

    $response = $this->actingAs($buyer)->post(route('series.premium.order', $world['series']->public_id), [
        'request_key' => 'http-key-000001', 'amount_minor' => 1, 'price' => '0.01', 'currency' => 'EUR', 'creator_profile_id' => 999, 'digital_purchase_id' => 1, 'gross_minor' => 1,
    ]);

    $response->assertRedirect();
    $purchase = DigitalPurchase::query()->where('user_id', $buyer->id)->firstOrFail();

    expect($response->headers->get('Location'))->toContain('/commerce/sandbox/')->and($purchase->amount_minor)->toBe(500)->and($purchase->currency)->toBe('USD')
        ->and($purchase->product_id)->toBe($world['series']->id)->and($purchase->provider_session_id)->not->toBeNull();
});

test('E43-PF-07: HTTP order route guards - guests, unknown/hidden series, wrong verb, and refusals come back as a flash message', function () {
    $world = e43_world();
    $url = route('series.premium.order', $world['series']->public_id);

    $this->post($url)->assertRedirect(route('login'));
    $this->get($url)->assertStatus(405);
    $this->actingAs(e16User())->post(route('series.premium.order', '01ARZ3NDEKTSV4RRFFQ69G5FAV'))->assertNotFound();

    $this->actingAs($world['user'])->post($url)->assertRedirect(route('series.show', $world['series']->public_id))->assertSessionHas('error');

    DB::table('creator_series')->where('id', $world['series']->id)->update(['hidden_at' => now()]);
    $this->actingAs(e16User())->post($url)->assertNotFound();
});

test('E43-PF-08: the order route is throttled by the shared commerce-checkout limiter', function () {
    $world = e43_world();
    $middleware = collect(app('router')->getRoutes()->getByName('series.premium.order')->gatherMiddleware());

    expect($middleware->contains(fn ($m) => str_contains((string) $m, 'throttle:commerce-checkout')))->toBeTrue()
        ->and($middleware->contains('auth'))->toBeTrue()->and($middleware->contains('verified'))->toBeTrue();
});

// ───────────── التحقق ─────────────

test('E43-PF-09: a verified payment atomically grants access, credits the creator ledger and snapshots the sale (one transaction)', function () {
    $world = e43_world(['price' => '5.00']);
    $before = e43_available($world['account']);
    $bought = e43_buy($world);
    $purchase = $bought['purchase'];
    $sale = $bought['sale'];
    $account = $world['account']->fresh();
    $entry = CreatorEarningEntry::query()->find($sale->credit_entry_id);

    expect($bought['result'])->toMatchArray(['result' => 'applied', 'detail' => 'verified'])->and($purchase->status)->toBe(DigitalPurchase::STATUS_VERIFIED)
        ->and(e43_access_row($bought['user'], $world['series'])?->isActive())->toBeTrue()
        ->and(e43_access_row($bought['user'], $world['series'])->source_digital_purchase_id)->toBe($purchase->id)
        ->and($sale->gross_minor)->toBe(500)->and($sale->creator_share_minor)->toBe(350)->and($sale->platform_share_minor)->toBe(150)->and($sale->currency)->toBe('USD')
        ->and($sale->creator_bps)->toBe(7000)->and($sale->platform_bps)->toBe(3000)->and($sale->rounding_rule)->toBe('creator_floor')->and($sale->status)->toBe('credited')
        ->and($sale->creator_profile_id)->toBe($world['profile']->id)->and($sale->series_public_id)->toBe($world['series']->public_id)
        ->and($entry->type)->toBe('direct_sale_credit')->and($entry->available_delta_minor)->toBe(350)->and($entry->reserved_delta_minor)->toBe(0)->and($entry->paid_delta_minor)->toBe(0)
        ->and($entry->source_key)->toBe('direct_sale:'.$purchase->id.':credit')
        ->and(e43_available($account))->toBe($before + 350)
        ->and(e39_ledger()->ledgerBalances($account->id)['available'])->toBe((int) $account->available_minor);
});

test('E43-PF-10: duplicate or replayed provider events never double-grant, double-credit or double-notify', function () {
    $world = e43_world();
    $bought = e43_buy($world);
    $entries = DB::table('creator_earning_entries')->count();

    $dup = e41_deliver($bought['session'], 'payment.paid', [], 'dup-1');
    $same = e41_sandbox()->signedEvent($bought['session'], 'payment.paid', [], 'dup-2');
    e41_webhooks()->handle('sandbox', $same['payload'], $same['headers']);
    e41_webhooks()->handle('sandbox', $same['payload'], $same['headers']);

    expect($dup['result'])->toBe('ignored')->and(DB::table('creator_series_accesses')->count())->toBe(1)->and(DB::table('creator_direct_sales')->count())->toBe(1)
        ->and(DB::table('creator_earning_entries')->count())->toBe($entries)
        ->and(e43_notes($bought['user'], 'premium_series_access_granted')->count())->toBe(1)
        ->and(e43_notes($world['user'], 'creator_premium_sale')->count())->toBe(1)
        ->and(DB::table('digital_purchase_events')->where('digital_purchase_id', $bought['purchase']->id)->where('event', 'verified')->count())->toBe(1);
});

test('E43-PF-11: a failure inside the verification transaction leaves no trace; re-delivery then applies exactly once', function () {
    $world = e43_world();
    $buyer = e16User();
    $order = e43_order($buyer, $world['series']);

    $failing = new class(app(CreatorLedgerService::class), app(CreatorPremiumPolicy::class), app(OperationalAuditService::class)) extends CreatorDirectSaleService
    {
        public function record(DigitalPurchase $purchase, CreatorSeries $series, CreatorSeriesSaleOffer $offer, CreatorEarningAccount $lockedAccount): CreatorDirectSale
        {
            throw new RuntimeException('probe-injected-failure'); // بعد منح الوصول مباشرة داخل الـsavepoint نفسها
        }
    };
    app(CommerceProductRegistry::class)->register(new CreatorSeriesAccessProductHandler($failing, app(CreatorPremiumPolicy::class), app(CreatorSeriesVisibilityService::class), app(CreatorPremiumNotifier::class)));

    $event = e41_sandbox()->simulate($order['session'], \App\Services\Commerce\CommerceProviderState::Paid, [], 'inject-1');
    expect(fn () => e41_webhooks()->handle('sandbox', $event['payload'], $event['headers']))->toThrow(RuntimeException::class);

    expect(DB::table('creator_series_accesses')->count())->toBe(0)->and(DB::table('creator_direct_sales')->count())->toBe(0)
        ->and(DB::table('creator_earning_entries')->where('type', 'direct_sale_credit')->count())->toBe(0)
        ->and($order['purchase']->fresh()->status)->toBe(DigitalPurchase::STATUS_AWAITING)->and(e43_notes($buyer, 'premium_series_access_granted')->count())->toBe(0);

    app(CommerceProductRegistry::class)->register(app(CreatorSeriesAccessProductHandler::class));
    $result = e41_webhooks()->handle('sandbox', $event['payload'], $event['headers']);

    expect($result['result'])->toBe('applied')->and(DB::table('creator_series_accesses')->count())->toBe(1)->and(DB::table('creator_direct_sales')->count())->toBe(1)
        ->and(DB::table('creator_earning_entries')->where('type', 'direct_sale_credit')->count())->toBe(1);
});

test('E43-PF-12: money arrived but fulfillment is blocked ⇒ a financial review flag (never a silent grant, never a lost payment)', function () {
    $cases = [
        'monetization paused' => fn ($w, $b) => app(CreatorMonetizationService::class)->pause($w['account'], $w['admin'], 'إيقاف تجريبي للتسييل'),
        'offer paused by admin' => fn ($w, $b) => e43_offers()->pauseByAdmin($w['admin'], $w['offer']->public_id, 'إيقاف للمراجعة'),
        'offer retired' => fn ($w, $b) => e43_offers()->retire($w['user'], $w['offer']->public_id),
        'buyer frozen' => fn ($w, $b) => $b->forceFill(['is_frozen' => true])->save(),
        'series hidden' => fn ($w, $b) => DB::table('creator_series')->where('id', $w['series']->id)->update(['hidden_at' => now()]),
        'currency mismatch' => fn ($w, $b) => DB::table('creator_earning_accounts')->where('id', $w['account']->id)->update(['currency' => 'EUR']),
    ];

    foreach ($cases as $label => $break) {
        $world = e43_world();
        $buyer = e16User();
        $order = e43_order($buyer, $world['series']);
        $available = e43_available($world['account']);
        $break($world, $buyer);

        e41_pay($order['session']);
        $purchase = $order['purchase']->fresh();

        expect($purchase->status)->toBe(DigitalPurchase::STATUS_AWAITING, $label)->and($purchase->review_flag)->toBe('fulfillment_blocked', $label)
            ->and(CreatorSeriesAccess::query()->where('user_id', $buyer->id)->count())->toBe(0, $label)
            ->and(CreatorDirectSale::query()->where('digital_purchase_id', $purchase->id)->count())->toBe(0, $label)
            ->and(DB::table('creator_earning_entries')->where('source_key', 'like', 'direct_sale:%')->count())->toBe(0, $label)
            ->and(e43_available($world['account']))->toBe($available, $label);
    }
});

test('E43-PF-13: after the cause is fixed an authorized reviewer verifies through the single shared path (access + credit + notifications)', function () {
    $world = e43_world();
    $buyer = e16User();
    $order = e43_order($buyer, $world['series']);
    app(CreatorMonetizationService::class)->pause($world['account'], $world['admin'], 'إيقاف تجريبي للتسييل');
    e41_pay($order['session']);

    expect(e43_reason(fn () => app(CreatorPremiumRefundService::class)->verifyReviewed($world['admin'], $order['purchase']->public_id, 'محاولة قبل معالجة السبب')))->toBe('monetization_off');

    app(CreatorMonetizationService::class)->enable($world['profile'], $world['admin'], 'استئناف التسييل');
    $verified = app(CreatorPremiumRefundService::class)->verifyReviewed($world['admin'], $order['purchase']->public_id, 'تمت معالجة سبب الحجب');

    expect($verified->status)->toBe(DigitalPurchase::STATUS_VERIFIED)->and($verified->verified_by)->toBe($world['admin']->id)
        ->and(e43_access_row($buyer, $world['series'])?->isActive())->toBeTrue()
        ->and(CreatorDirectSale::query()->where('digital_purchase_id', $verified->id)->value('creator_share_minor'))->toBe(350)
        ->and(e43_notes($buyer, 'premium_series_access_granted')->count())->toBe(1);
});

test('E43-PF-14: review decisions need permission and a note; mismatched amounts are never verifiable; parties cannot process their own purchase', function () {
    $world = e43_world();
    $buyer = e16User();
    $order = e43_order($buyer, $world['series']);
    e41_pay($order['session'], ['amount_minor' => 499], 'short-1');
    $purchase = $order['purchase']->fresh();
    $service = app(CreatorPremiumRefundService::class);

    expect($purchase->review_flag)->toBe('amount_mismatch')
        ->and(e43_reason(fn () => $service->verifyReviewed($world['admin'], $purchase->public_id, 'محاولة قبول رغم المبلغ')))->toBe('flag_not_verifiable')
        ->and(e43_reason(fn () => $service->dismissReview(e39_plain_staff(), $purchase->public_id, 'سبب كافٍ للإغلاق')))->toBe('forbidden')
        ->and(e43_reason(fn () => $service->dismissReview($world['admin'], $purchase->public_id, 'قصير')))->toBe('note_required');

    $buyerAdmin = $buyer;
    $buyerAdmin->assignRole('administrator');
    expect(e43_reason(fn () => $service->dismissReview($buyerAdmin->fresh(), $purchase->public_id, 'محاولة معالجة طلبي')))->toBe('own_account');

    $dismissed = $service->dismissReview($world['admin'], $purchase->public_id, 'مبلغ ناقص؛ يُسوّى خارج النظام');
    expect($dismissed->review_resolved_at)->not->toBeNull()->and($dismissed->status)->toBe(DigitalPurchase::STATUS_AWAITING)
        ->and(CreatorSeriesAccess::query()->count())->toBe(0);
});

test('E43-PF-15: a second payment for the same buyer and series is blocked into review as already_owned (no second access, no second credit)', function () {
    $world = e43_world();
    $buyer = e16User();
    $stale = e43_order($buyer, $world['series']);
    DB::table('digital_purchases')->where('id', $stale['purchase']->id)->update(['expires_at' => now()->subMinute()]);

    $bought = e43_buy($world, $buyer); // يستبدل الطلب المنتهي بطلب جديد ويحقّقه
    $entries = DB::table('creator_earning_entries')->where('type', 'direct_sale_credit')->count();

    e41_pay($stale['session'], [], 'late-1'); // الدفعة المتأخرة على الطلب المنتهي
    $late = $stale['purchase']->fresh();

    expect($bought['purchase']->status)->toBe(DigitalPurchase::STATUS_VERIFIED)->and($late->needsReview())->toBeTrue()
        ->and(e43_reason(fn () => app(CreatorPremiumRefundService::class)->verifyReviewed($world['admin'], $late->public_id, 'محاولة تحقق من دفعة مكررة')))->toBeIn(['invalid_state', 'already_owned'])
        ->and(CreatorSeriesAccess::query()->where('user_id', $buyer->id)->count())->toBe(1)
        ->and(DB::table('creator_earning_entries')->where('type', 'direct_sale_credit')->count())->toBe($entries);
});

test('E43-PF-16: CREATOR_PREMIUM_ENABLED=false does not strand an already-paid order (fulfillment still completes)', function () {
    $world = e43_world();
    $buyer = e16User();
    $order = e43_order($buyer, $world['series']);

    config(['creator_premium.enabled' => false]);
    e41_pay($order['session']);

    expect($order['purchase']->fresh()->status)->toBe(DigitalPurchase::STATUS_VERIFIED)->and(e43_access_row($buyer, $world['series'])?->isActive())->toBeTrue();
});

// ───────────── الإشعارات والتاريخ والخصوصية والعزل ─────────────

test('E43-PF-17: notifications - buyer gets one access notice, creator gets one sale notice without any buyer identity', function () {
    $world = e43_world();
    $bought = e43_buy($world);

    $buyerNote = e43_notes($bought['user'], 'premium_series_access_granted')->first();
    $creatorNote = e43_notes($world['user'], 'creator_premium_sale')->first();
    $payload = json_encode($creatorNote->data, JSON_UNESCAPED_UNICODE);

    expect($buyerNote)->not->toBeNull()->and($creatorNote)->not->toBeNull()
        ->and($payload)->not->toContain($bought['user']->email)->not->toContain($bought['user']->name)
        ->and(e43_notes($world['user'], 'premium_series_access_granted')->count())->toBe(0);
});

test('E43-PF-18: the buyer sees the series purchase in history, receipt and return page with series copy and an open-series link', function () {
    $world = e43_world();
    $bought = e43_buy($world);

    $this->actingAs($bought['user'])->get(route('commerce.purchases.index'))->assertOk()->assertSee('CSA-')->assertSee(route('series.show', $world['series']->public_id), false);
    $this->actingAs($bought['user'])->get(route('commerce.receipts.show', $bought['purchase']->public_id))->assertOk()->assertSee($bought['purchase']->reference_code);

    $return = $this->actingAs($bought['user'])->get(route('commerce.checkout.return', $bought['purchase']->public_id))->assertOk()->getContent();
    expect($return)->toContain('افتح السلسلة');

    $status = $this->actingAs($bought['user'])->getJson(route('commerce.orders.status', $bought['purchase']->public_id))->assertOk()->json();
    expect($status['message'])->toContain('السلسلة');
});

test('E43-PF-19: another user cannot see someone else\'s series order, receipt or status', function () {
    $world = e43_world();
    $bought = e43_buy($world);
    $intruder = e16User();

    $this->actingAs($intruder)->get(route('commerce.receipts.show', $bought['purchase']->public_id))->assertNotFound();
    $this->actingAs($intruder)->getJson(route('commerce.orders.status', $bought['purchase']->public_id))->assertNotFound();
});

test('E43-PF-20: a premium purchase touches no gems, XP, currency, pass, season, competitive, referral, sponsorship, reputation or payout tables', function () {
    $world = e43_world();
    $before = e43_foreign_counts();
    $bought = e43_buy($world);

    expect(e43_foreign_counts())->toBe($before);

    // وصول المالك للعب لا يكسبه اقتصادًا (أحجيات المجتمع بلا جوائز بالبناء).
    e30s_solve($bought['user'], $world['works'][0]);
    expect(e43_foreign_counts())->toBe($before);
});
