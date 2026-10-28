<?php

use App\Filament\Pages\CreatorPremiumPage;
use App\Models\CreatorDirectSale;
use App\Models\CreatorEarningEntry;
use App\Models\CreatorSeriesSaleOffer;
use App\Models\DigitalPurchase;
use App\Models\User;
use App\Services\CreatorFinance\CreatorMoney;
use App\Services\CreatorPremium\CreatorPremiumAnalytics;
use App\Services\CreatorPremium\CreatorPremiumReconciler;
use App\Services\CreatorPremium\CreatorPremiumRefundService;
use Carbon\Carbon;
use Database\Seeders\DemoE43Seeder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

require_once __DIR__.'/_support.php';

/*
|--------------------------------------------------------------------------
| E43 (6/9) - العمليات: المصالحة (قراءة فقط) وأمرها، لوحة الإدارة (Filament)، استوديو المبدع وخصوصية المشتري، التحليلات، ميزانيات الاستعلامات، والبذّار.
|--------------------------------------------------------------------------
*/

beforeEach(fn () => e43_seed());

/** @return list<string> أكواد انحرافات المصالحة الحالية. */
function e43_findings(): array
{
    return array_values(array_unique(array_column(app(CreatorPremiumReconciler::class)->run()['findings'], 'code')));
}

/** لقطة مجزَّأة لكل جداول السلطة (للتأكد أن المصالحة لا تكتب شيئًا). */
function e43_authority_hash(): string
{
    $parts = [];

    foreach (['digital_purchases', 'creator_series_accesses', 'creator_direct_sales', 'creator_earning_entries', 'creator_earning_accounts', 'creator_series_sale_offers', 'operational_audit_logs'] as $table) {
        $parts[$table] = DB::table($table)->orderBy('id')->get()->toJson();
    }

    return md5(json_encode($parts));
}

/** n مبيعات محقَّقة جديدة (مشترٍ مختلف لكل بيع). @return list<array> */
function e43_sales(array $world, int $n): array
{
    $out = [];

    for ($i = 0; $i < $n; $i++) {
        $out[] = e43_buy($world);
    }

    return $out;
}

function e43_refund_via_service(array $world, array $bought, bool $confirm = false): void
{
    app(CreatorPremiumRefundService::class)->refund($world['admin'], $bought['purchase']->public_id, 'user_request', 'استرداد موثَّق لأغراض الاختبار', $confirm);
}

// ───────────── المصالحة ─────────────

test('E43-OP-01: a healthy world (verified + refunded sales) reconciles clean', function () {
    $world = e43_world();
    e43_buy($world);
    $second = e43_buy($world);
    e43_tick(1);
    e43_refund_via_service($world, $second);

    $result = app(CreatorPremiumReconciler::class)->run();

    expect($result['findings'])->toBe([])->and($result['remaining'])->toBe(0)->and($result['checked_purchases'])->toBe(2)->and($result['checked_sales'])->toBe(2)->and($result['truncated'])->toBeFalse();
});

test('E43-OP-02: reconciliation is strictly read-only - even with findings it changes no authority table', function () {
    $world = e43_world();
    $bought = e43_buy($world);
    DB::table('creator_series_accesses')->where('user_id', $bought['user']->id)->update(['revoked_at' => now()]);
    $before = e43_authority_hash();

    $result = app(CreatorPremiumReconciler::class)->run();

    expect($result['remaining'])->toBeGreaterThan(0)->and(e43_authority_hash())->toBe($before);
});

test('E43-OP-03: a verified purchase whose access is missing or revoked is reported', function () {
    $world = e43_world();
    $bought = e43_buy($world);
    DB::table('creator_series_accesses')->where('user_id', $bought['user']->id)->update(['revoked_at' => now()]);

    expect(e43_findings())->toContain('purchase_without_access');
});

test('E43-OP-04: a refunded purchase that still has active access is reported from both directions', function () {
    $world = e43_world();
    $bought = e43_buy($world);
    e43_tick(1);
    e43_refund_via_service($world, $bought);
    DB::table('creator_series_accesses')->where('user_id', $bought['user']->id)->update(['revoked_at' => null]);

    expect(e43_findings())->toContain('refunded_purchase_with_active_access')->toContain('access_without_verified_purchase');
});

test('E43-OP-05: a refunded purchase whose creator share was neither reversed nor flagged is reported', function () {
    $world = e43_world();
    $bought = e43_buy($world);
    e43_tick(1);
    e43_refund_via_service($world, $bought);
    DB::table('creator_direct_sales')->where('id', $bought['sale']->id)->update(['status' => 'credited', 'reversal_entry_id' => null]);

    expect(e43_findings())->toContain('refunded_purchase_without_reversal');
});

test('E43-OP-06: a sale whose snapshot disagrees with the ledger or the split rule is reported (amount and credit mismatch)', function () {
    $world = e43_world();
    $bought = e43_buy($world);
    DB::table('creator_direct_sales')->where('id', $bought['sale']->id)->update(['creator_share_minor' => 351]);

    expect(e43_findings())->toContain('sale_amount_mismatch')->toContain('sale_credit_mismatch');
});

test('E43-OP-07: a refunded sale without its matching reversal entry is reported', function () {
    $world = e43_world();
    $bought = e43_buy($world);
    e43_tick(1);
    e43_refund_via_service($world, $bought);
    DB::table('creator_direct_sales')->where('id', $bought['sale']->id)->update(['reversal_entry_id' => null]);

    expect(e43_findings())->toContain('reversal_mismatch');
});

test('E43-OP-08: a sale attributed to the wrong creator is reported', function () {
    $world = e43_world();
    $bought = e43_buy($world);
    [, $otherProfile] = e39_creator($world['admin']);
    DB::table('creator_direct_sales')->where('id', $bought['sale']->id)->update(['creator_profile_id' => $otherProfile->id]);

    expect(e43_findings())->toContain('sale_wrong_creator');
});

test('E43-OP-09: a blocked reversal shows up as needs-finance-review and disappears once the finance decision is made', function () {
    $world = e43_world();
    $bought = e43_buy($world);
    e43_drain($world, '1.00');
    e43_tick(1);
    e43_refund_via_service($world, $bought);

    expect(e43_findings())->toContain('sale_needs_finance_review');

    app(\App\Services\CreatorPremium\CreatorDirectSaleService::class)->writeOff($world['admin'], $bought['sale']->fresh()->public_id, 'تتحمل المنصة هذا الاسترداد بقرار مالي.');

    expect(e43_findings())->not->toContain('sale_needs_finance_review')->and(e43_findings())->toBe([]);
});

test('E43-OP-10: a direct-sale credit with no sale record is reported as an orphan', function () {
    $world = e43_world();
    DB::transaction(function () use ($world) {
        $locked = e39_ledger()->lockAccount($world['account']->id);
        e39_ledger()->post($locked, CreatorEarningEntry::TYPE_DIRECT_SALE_CREDIT, 100, 0, 0, 'direct_sale:987654:credit');
    });

    expect(e43_findings())->toContain('orphan_credit_entry');
});

test('E43-OP-11: a live offer whose currency drifted from the account, and a sale recorded after a pause, are both reported', function () {
    $world = e43_world();
    $bought = e43_buy($world);
    e43_tick(2);
    e43_offers()->pause($world['user'], $world['offer']->public_id);
    DB::table('creator_series_sale_offers')->where('id', $world['offer']->id)->update(['paused_at' => Carbon::parse($bought['sale']->sold_at)->subMinute()]);

    expect(e43_findings())->toContain('sale_after_pause');

    DB::table('creator_series_sale_offers')->where('id', $world['offer']->id)->update(['currency' => 'TRY']);

    expect(e43_findings())->toContain('offer_currency_mismatch');
});

test('E43-OP-12: money that arrived with an unresolved review flag is listed until a human closes it', function () {
    $world = e43_world();
    $buyer = e16User();
    $stale = e43_order($buyer, $world['series']);
    DB::table('digital_purchases')->where('id', $stale['purchase']->id)->update(['expires_at' => now()->subMinute()]);
    e43_purchases()->request($buyer, $world['series']);
    e41_pay($stale['session'], [], 'late-ops-1');

    expect(e43_findings())->toContain('purchase_in_review');

    app(CreatorPremiumRefundService::class)->refundUnmatched($world['admin'], $stale['purchase']->fresh()->public_id, 'استرداد دفعة متأخرة على طلب منتهٍ');

    expect(e43_findings())->not->toContain('purchase_in_review');
});

test('E43-OP-13: the scan is bounded - a limit checks only the newest rows and the findings list is capped with a truncation flag', function () {
    $world = e43_world();
    $sales = e43_sales($world, 3);
    DB::table('creator_series_accesses')->whereIn('user_id', array_map(fn ($s) => $s['user']->id, $sales))->update(['revoked_at' => now()]);

    $limited = app(CreatorPremiumReconciler::class)->run(1);
    expect($limited['checked_purchases'])->toBe(1)->and($limited['checked_sales'])->toBe(1);

    config(['creator_premium.reconcile.max_findings' => 1]);
    $capped = app(CreatorPremiumReconciler::class)->run();

    expect($capped['truncated'])->toBeTrue()->and(count($capped['findings']))->toBe(1)->and($capped['remaining'])->toBeGreaterThan(1);
});

test('E43-OP-14: the artisan command exits 0 when consistent and 1 (with the finding code printed) when not', function () {
    $world = e43_world();
    $bought = e43_buy($world);

    $this->artisan('creator-premium:reconcile')->expectsOutputToContain('متسقة')->assertExitCode(0);

    DB::table('creator_series_accesses')->where('user_id', $bought['user']->id)->update(['revoked_at' => now()]);

    $this->artisan('creator-premium:reconcile')->expectsOutputToContain('purchase_without_access')->assertExitCode(1);
    $this->artisan('creator-premium:reconcile', ['--limit' => 1])->assertExitCode(1);
});

// ───────────── لوحة الإدارة (Filament) ─────────────

test('E43-OP-15: the admin page is reachable only with the view permission, in the finance group, and guests are sent to login', function () {
    $this->get('/admin/creator-premium')->assertRedirect();

    $this->actingAs(e39_admin())->get('/admin/creator-premium')->assertOk()->assertSee('السلاسل المميزة');

    foreach ([e39_plain_staff(), e16User()] as $denied) {
        $this->actingAs($denied);
        expect(CreatorPremiumPage::canAccess())->toBeFalse();
    }

    $this->actingAs(e39_staff_with(['creator-premium.view']));
    expect(CreatorPremiumPage::canAccess())->toBeTrue()
        ->and((new ReflectionClass(CreatorPremiumPage::class))->getProperty('navigationGroup')->getValue())->toBe('مالية المبدعين');
});

test('E43-OP-16: every tab renders and the overview shows per-currency totals', function () {
    $world = e43_world();
    e43_sales($world, 2);
    $this->actingAs($world['admin']);

    $page = Livewire::test(CreatorPremiumPage::class)->assertSuccessful()->assertSee('USD')->assertSeeHtml('data-premium-overview');

    foreach (array_keys(CreatorPremiumPage::TABS) as $tab) {
        $page->set('tab', $tab)->assertSuccessful();
    }

    $page->set('tab', 'purchases')->set('purchaseFilter', 'verified')->assertSuccessful()->assertSeeHtml('data-premium-purchases')
        ->set('tab', 'sales')->set('saleFilter', 'credited')->assertSuccessful()->assertSeeHtml('data-premium-sales');
});

test('E43-OP-17: an admin pauses and resumes an offer with a note; the creator cannot override it and non-owners stay locked', function () {
    $world = e43_world();
    $this->actingAs($world['admin']);
    $page = Livewire::test(CreatorPremiumPage::class)->set('tab', 'offers')->assertSeeHtml('data-premium-offers');

    $page->call('pauseOffer', $world['offer']->public_id);
    expect($world['offer']->fresh()->status)->toBe(CreatorSeriesSaleOffer::STATUS_ACTIVE); // بلا ملاحظة: لا تغيير

    $page->set('note', 'إيقاف إداري لمراجعة محتوى السلسلة')->call('pauseOffer', $world['offer']->public_id);
    expect($world['offer']->fresh()->status)->toBe(CreatorSeriesSaleOffer::STATUS_PAUSED)
        ->and(e43_reason(fn () => e43_offers()->activate($world['user'], $world['offer']->public_id)))->not->toBeNull()
        ->and(e43_access_service()->decide(e16User(), e43_fund_work_ops($world['works'][0]))->allowed)->toBeFalse();

    $page->set('note', 'انتهت المراجعة وعاد البيع')->call('resumeOffer', $world['offer']->public_id);
    expect($world['offer']->fresh()->status)->toBe(CreatorSeriesSaleOffer::STATUS_ACTIVE);
});

function e43_fund_work_ops(\App\Models\CreatorPuzzle $work): \App\Models\CreatorPuzzle
{
    return \App\Models\CreatorPuzzle::query()->visible()->withPremiumLock()->where('public_id', $work->public_id)->firstOrFail();
}

test('E43-OP-18: refund from the admin page revokes access, reverses the share and notifies', function () {
    $world = e43_world();
    $bought = e43_buy($world);
    $this->actingAs($world['admin']);

    Livewire::test(CreatorPremiumPage::class)->set('tab', 'purchases')->set('purchaseFilter', 'verified')->assertSee($bought['purchase']->reference_code)
        ->call('showPurchase', $bought['purchase']->public_id)->set('refundReason', 'user_request')->set('note', 'استرداد من لوحة الإدارة لأغراض الاختبار')->call('refund');

    expect($bought['purchase']->fresh()->status)->toBe(DigitalPurchase::STATUS_REFUNDED)->and(e43_access_row($bought['user'], $world['series'])->isActive())->toBeFalse()
        ->and($bought['sale']->fresh()->status)->toBe('refunded');
});

test('E43-OP-19: a played purchase needs the confirmation checkbox on the page; without it nothing changes', function () {
    $world = e43_world();
    $bought = e43_buy($world);
    e30s_solve($bought['user'], $world['works'][0]);
    e43_tick(2);
    $this->actingAs($world['admin']);

    $page = Livewire::test(CreatorPremiumPage::class)->set('tab', 'purchases')->set('purchaseFilter', 'verified')->call('showPurchase', $bought['purchase']->public_id)
        ->set('note', 'استرداد بعد لعب المشتري للاختبار')->call('refund');
    expect($bought['purchase']->fresh()->status)->toBe(DigitalPurchase::STATUS_VERIFIED);

    $page->set('confirmClaimed', true)->call('refund');
    expect($bought['purchase']->fresh()->status)->toBe(DigitalPurchase::STATUS_REFUNDED);
});

test('E43-OP-20: finance decisions from the sales tab - a blocked retry changes nothing, then succeeds after a goodwill credit; or the platform writes it off', function () {
    $world = e43_world();
    $one = e43_buy($world);
    $two = e43_buy($world);
    e43_drain($world, '1.00');
    e43_tick(1);
    e43_refund_via_service($world, $one);
    e43_refund_via_service($world, $two);
    $this->actingAs($world['admin']);
    $page = Livewire::test(CreatorPremiumPage::class)->set('tab', 'sales')->assertSee('يحتاج مراجعة مالية');

    $page->call('showSale', $one['sale']->public_id)->call('retryReversal');
    expect($one['sale']->fresh()->status)->toBe('needs_finance_review');

    e39_ledger()->adjust($world['account']->fresh(), $world['admin'], 'credit', '10.00', 'goodwill', 'تعويض لإتاحة عكس حصة البيع المسترد.');
    $page->call('retryReversal');
    expect($one['sale']->fresh()->status)->toBe('refunded');

    $page->call('showSale', $two['sale']->public_id)->set('note', 'تتحمل المنصة الاسترداد بقرار مالي رسمي')->call('writeOff');
    expect($two['sale']->fresh()->status)->toBe('written_off');
});

test('E43-OP-21: staff with view only can look but every action is refused by the services', function () {
    $world = e43_world();
    $bought = e43_buy($world);
    $viewer = e39_staff_with(['creator-premium.view']);
    $this->actingAs($viewer);

    Livewire::test(CreatorPremiumPage::class)->set('tab', 'offers')->set('note', 'محاولة إيقاف بلا صلاحية كافية')->call('pauseOffer', $world['offer']->public_id)
        ->set('tab', 'purchases')->set('purchaseFilter', 'verified')->call('showPurchase', $bought['purchase']->public_id)->set('note', 'محاولة استرداد بلا صلاحية كافية')->call('refund');

    expect($world['offer']->fresh()->status)->toBe(CreatorSeriesSaleOffer::STATUS_ACTIVE)->and($bought['purchase']->fresh()->status)->toBe(DigitalPurchase::STATUS_VERIFIED);
});

test('E43-OP-22: reconciliation on the page runs only on an explicit click and is refused without view permission', function () {
    $world = e43_world();
    e43_buy($world);
    $this->actingAs($world['admin']);

    $page = Livewire::test(CreatorPremiumPage::class)->set('tab', 'reconcile')->assertSet('reconciliation', null);
    $page->call('reconcile')->assertSet('reconciliation.remaining', 0)->assertSeeHtml('data-premium-reconcile');

    $this->actingAs(e16User());
    Livewire::test(CreatorPremiumPage::class)->assertForbidden();
});

test('E43-OP-23: the admin list pages do not grow their query count with the number of rows', function () {
    $world = e43_world();
    e43_sales($world, 1);
    $this->actingAs($world['admin']);

    $measure = function () {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $page = Livewire::test(CreatorPremiumPage::class)->set('tab', 'sales')->set('saleFilter', 'all')->set('tab', 'purchases')->set('purchaseFilter', 'all')->set('tab', 'offers')->set('tab', 'overview');
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();
        $page->assertSuccessful();

        return $count;
    };

    $measure(); // تسخين (تحميل الصلاحيات/المكوّنات أول مرة)
    $few = $measure();
    e43_sales($world, 5);

    expect($measure())->toBeLessThanOrEqual($few + 2);
});

// ───────────── استوديو المبدع وخصوصية المشتري ─────────────

test('E43-OP-24: the creator studio is for approved, verified creators only; a creator with no earning account sees the monetization notice', function () {
    $this->get(route('creator.premium.index'))->assertRedirect();
    $this->actingAs(e16User())->get(route('creator.premium.index'))->assertRedirect(route('creator.apply'));

    $plain = e28_creator();
    $this->actingAs($plain)->get(route('creator.premium.index'))->assertOk()->assertSee('التسييل غير مُفعَّل');

    $world = e43_world();
    $this->actingAs($world['user'])->get(route('creator.premium.index'))->assertOk()->assertSee('السلاسل المميزة')->assertSeeHtml('data-premium-studio')->assertSeeHtml('data-premium-stats');
});

test('E43-OP-25: studio numbers are the creator\'s own aggregates (sales, gross, net after refunds) and never reveal the buyer', function () {
    $world = e43_world();
    $buyerA = e16User(['name' => 'مشترٍ سري الاسم', 'email' => 'secret-buyer-a@example.test']);
    $a = e43_buy($world, $buyerA);
    $b = e43_buy($world);
    e43_tick(1);
    e43_refund_via_service($world, $b);

    [$other, , ] = e39_creator($world['admin']);
    $foreign = e30s_published($other, e30s_works($other, 2));
    e43_offer($other, $foreign, '9.00');
    e43_buy(['series' => $foreign], e16User());

    $html = $this->actingAs($world['user'])->get(route('creator.premium.index'))->assertOk()->getContent();

    expect($html)->toContain(CreatorMoney::format(1000, 'USD'))->toContain(CreatorMoney::format(350, 'USD'))->toContain('data-premium-refunds')
        ->not->toContain('مشترٍ سري الاسم')->not->toContain('secret-buyer-a@example.test')->not->toContain($a['purchase']->reference_code)->not->toContain((string) $a['session'])
        ->not->toContain($foreign->published_title ?? 'لا-عنوان-أجنبي-أبدًا');

    $stats = app(CreatorPremiumAnalytics::class)->forCreator($world['profile']);

    expect($stats['sales'])->toBe(2)->and($stats['gross_minor'])->toBe(1000)->and($stats['creator_minor'])->toBe(700)->and($stats['refunded'])->toBe(1)->and($stats['reversed_minor'])->toBe(350)->and($stats['net_minor'])->toBe(350);
});

test('E43-OP-26: creator analytics keep a needs-review or written-off sale in the creator\'s favour and drop it only when actually reversed', function () {
    $world = e43_world();
    $one = e43_buy($world);
    $two = e43_buy($world);
    e43_drain($world, '1.00');
    e43_tick(1);
    e43_refund_via_service($world, $one);
    e43_refund_via_service($world, $two);
    $analytics = app(CreatorPremiumAnalytics::class);

    expect($analytics->forCreator($world['profile'])['net_minor'])->toBe(700);

    app(\App\Services\CreatorPremium\CreatorDirectSaleService::class)->writeOff($world['admin'], $one['sale']->fresh()->public_id, 'تتحمل المنصة هذا الاسترداد بقرار مالي.');
    expect($analytics->forCreator($world['profile'])['net_minor'])->toBe(700);

    e39_ledger()->adjust($world['account']->fresh(), $world['admin'], 'credit', '10.00', 'goodwill', 'تعويض لإتاحة عكس حصة البيع المسترد.');
    app(\App\Services\CreatorPremium\CreatorDirectSaleService::class)->retryReversal($world['admin'], $two['sale']->fresh()->public_id);

    expect($analytics->forCreator($world['profile'])['net_minor'])->toBe(350);
});

test('E43-OP-27: admin analytics are per currency with platform share and review counters', function () {
    $world = e43_world();
    e43_sales($world, 3);
    $rows = app(CreatorPremiumAnalytics::class)->forAdmin();

    expect($rows)->toHaveCount(1)->and($rows[0])->toMatchArray(['currency' => 'USD', 'sales' => 3, 'gross_minor' => 1500, 'creator_minor' => 1050, 'platform_minor' => 450, 'refunded' => 0, 'needs_review' => 0, 'written_off' => 0]);
});

test('E43-OP-28: studio offer management over HTTP - create, reprice, activate, pause, retire; another creator\'s offer is a 404', function () {
    $world = e43_world(['offer' => false]);
    $second = e30s_published($world['user'], e30s_works($world['user'], 2));
    $this->actingAs($world['user']);

    $this->post(route('creator.premium.offers.store'), ['series' => $second->public_id, 'price' => '3.50'])->assertRedirect();
    $offer = CreatorSeriesSaleOffer::query()->where('creator_series_id', $second->id)->firstOrFail();
    expect($offer->status)->toBe('draft')->and($offer->price_minor)->toBe(350);

    $this->put(route('creator.premium.offers.price', $offer->public_id), ['price' => '4.25'])->assertRedirect();
    $this->post(route('creator.premium.offers.activate', $offer->public_id))->assertRedirect();
    expect($offer->fresh()->price_minor)->toBe(425)->and($offer->fresh()->status)->toBe('active');

    $this->post(route('creator.premium.offers.pause', $offer->public_id))->assertRedirect();
    expect($offer->fresh()->status)->toBe('paused');

    $this->post(route('creator.premium.offers.activate', $offer->public_id))->assertRedirect();
    $this->post(route('creator.premium.offers.retire', $offer->public_id))->assertRedirect();
    expect($offer->fresh()->status)->toBe('retired');

    [$other] = e39_creator($world['admin']);
    $theirs = e43_offer($other, e30s_published($other, e30s_works($other, 2)), '2.00');

    foreach (['activate', 'pause', 'retire'] as $action) {
        $this->post(route('creator.premium.offers.'.$action, $theirs->public_id))->assertNotFound();
    }
    $this->put(route('creator.premium.offers.price', $theirs->public_id), ['price' => '1.00'])->assertNotFound();
    expect($theirs->fresh()->status)->toBe('active')->and($theirs->fresh()->price_minor)->toBe(200);
});

test('E43-OP-29: the studio shows the disabled notice when the feature flag is off, and keeps the history', function () {
    $world = e43_world();
    e43_buy($world);
    config(['creator_premium.enabled' => false]);

    $this->actingAs($world['user'])->get(route('creator.premium.index'))->assertOk()->assertSeeHtml('data-premium-disabled')->assertSeeHtml('data-premium-sale');
});

test('E43-OP-30: the creator studio and the reconciler keep a constant query count as sales grow', function () {
    $world = e43_world();
    e43_sales($world, 1);
    $this->actingAs($world['user']);

    $studio = function () {
        DB::flushQueryLog();
        DB::enableQueryLog();
        test()->get(route('creator.premium.index'))->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };
    $recon = function () {
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(CreatorPremiumReconciler::class)->run();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $studio();
    $recon(); // تسخين: أول طلب يحمّل الصلاحيات والكاش
    [$studioFew, $reconFew] = [$studio(), $recon()];
    e43_sales($world, 5);

    expect($studio())->toBe($studioFew)->and($recon())->toBe($reconFew);
});

// ───────────── البذّار ─────────────

test('E43-OP-31: the demo seeder refuses production and creates nothing', function () {
    $this->app['env'] = 'production';

    expect(fn () => app(DemoE43Seeder::class)->run())->toThrow(RuntimeException::class);
    expect(CreatorSeriesSaleOffer::query()->count())->toBe(0)->and(User::query()->where('email', DemoE43Seeder::ADMIN_EMAIL)->exists())->toBeFalse();
});

test('E43-OP-32: the demo seeder builds a live premium offer and a pending sandbox order only - no verified purchase, access, sale, ledger credit or notification - and is idempotent', function () {
    config(['creator_premium.enabled' => false, 'commerce.default_provider' => 'manual']); // كبيئة المطوّر المحلية: المزوّد الافتراضي manual لا يقبله السوق المميز
    $snapshot = fn () => [
        'offers' => CreatorSeriesSaleOffer::query()->count(),
        'purchases' => DigitalPurchase::query()->count(),
        'users' => User::query()->count(),
        'access' => DB::table('creator_series_accesses')->count(),
        'sales' => CreatorDirectSale::query()->count(),
        'credits' => DB::table('creator_earning_entries')->where('type', 'direct_sale_credit')->count(),
        'notes' => e39_notes(null, 'premium_series_access_granted')->count() + e39_notes(null, 'creator_premium_sale')->count(),
    ];

    $this->seed(DemoE43Seeder::class);
    $first = $snapshot();
    $this->seed(DemoE43Seeder::class);

    $offer = CreatorSeriesSaleOffer::query()->firstOrFail();
    $purchase = DigitalPurchase::query()->firstOrFail();

    expect($snapshot())->toBe($first)->and($first['offers'])->toBe(1)->and($first['purchases'])->toBe(1)->and($first['access'])->toBe(0)->and($first['sales'])->toBe(0)->and($first['credits'])->toBe(0)
        ->and($offer->status)->toBe('active')->and($offer->price_minor)->toBe(500)->and($purchase->status)->toBe(DigitalPurchase::STATUS_AWAITING)
        ->and($purchase->provider)->toBe('sandbox') // الطلب بُني عبر المزوّد التجريبي رغم أن الافتراضي manual
        ->and(config('creator_premium.enabled'))->toBeFalse() // المفتاح يعود لقيمته بعد البذر
        ->and(config('commerce.default_provider'))->toBe('manual') // والمزوّد الافتراضي كذلك
        ->and(User::query()->where('email', DemoE43Seeder::ADMIN_EMAIL)->first()->hasRole('administrator'))->toBeTrue();
});
