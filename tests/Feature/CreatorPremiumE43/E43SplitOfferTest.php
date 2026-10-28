<?php

use App\Models\CreatorSeriesSaleOffer;
use App\Services\CreatorPremium\CreatorPremiumPolicy;
use App\Services\CreatorSeries\CreatorSeriesException;
use App\Services\CreatorSeries\CreatorSeriesService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/_support.php';

/*
|--------------------------------------------------------------------------
| E43 (1/9) - قسمة الإيراد بنقاط الأساس (قاعدة التدوير والتجاوز) + عروض البيع ودورة حياتها + الخيار A للعملة + إجراءات الإدارة
|--------------------------------------------------------------------------
*/

beforeEach(fn () => e43_seed());

// ───────────── القسمة ─────────────

test('E43-SP-01: creator share is floor(gross*bps/10000) and the two shares ALWAYS sum to the gross (every amount 1..1200 plus edge values)', function () {
    $policy = app(CreatorPremiumPolicy::class);

    foreach (array_merge(range(1, 1200), [9999, 10000, 10001, 99999, 100000, 123457, 999999999]) as $gross) {
        $s = $policy->split($gross);

        expect($s['creator_minor'] + $s['platform_minor'])->toBe($gross)
            ->and($s['creator_minor'])->toBe(intdiv($gross * 7000, 10000))
            ->and($s['creator_bps'] + $s['platform_bps'])->toBe(10000)
            ->and($s['rule'])->toBe('creator_floor');
    }
});

test('E43-SP-02: documented examples (the remainder always goes to the platform, never to a float)', function () {
    $policy = app(CreatorPremiumPolicy::class);

    expect($policy->split(500))->toMatchArray(['creator_minor' => 350, 'platform_minor' => 150])
        ->and($policy->split(999))->toMatchArray(['creator_minor' => 699, 'platform_minor' => 300])
        ->and($policy->split(3))->toMatchArray(['creator_minor' => 2, 'platform_minor' => 1])
        ->and($policy->split(1))->toMatchArray(['creator_minor' => 0, 'platform_minor' => 1]);
});

test('E43-SP-03: no integer overflow at the top of the range (exact against independent big-integer arithmetic)', function () {
    $policy = app(CreatorPremiumPolicy::class);

    expect($policy->split(PHP_INT_MAX))->toMatchArray(['creator_minor' => 6456360425798343064, 'platform_minor' => 2767011611056432743])
        ->and($policy->split(9_000_000_000_000_000_000))->toMatchArray(['creator_minor' => 6_300_000_000_000_000_000, 'platform_minor' => 2_700_000_000_000_000_000]);

    config(['creator_premium.sales.creator_share_bps' => 6500, 'creator_premium.sales.platform_share_bps' => 3500]);
    expect($policy->split(PHP_INT_MAX))->toMatchArray(['creator_minor' => 5995191823955604274, 'platform_minor' => 3228180212899171533]);

    config(['creator_premium.sales.creator_share_bps' => 9999, 'creator_premium.sales.platform_share_bps' => 1]);
    expect($policy->split(PHP_INT_MAX))->toMatchArray(['creator_minor' => 9222449699651090329, 'platform_minor' => 922337203685478]);
});

test('E43-SP-04: an invalid share policy is refused instead of producing a wrong split', function () {
    $policy = app(CreatorPremiumPolicy::class);

    foreach ([[7000, 2000], [0, 10000], [10001, -1]] as [$creator, $platform]) {
        config(['creator_premium.sales.creator_share_bps' => $creator, 'creator_premium.sales.platform_share_bps' => $platform]);
        expect(e43_reason(fn () => $policy->split(500)))->toBe('share_policy_invalid');
    }

    config(['creator_premium.sales.creator_share_bps' => 7000, 'creator_premium.sales.platform_share_bps' => 3000]);
    expect(e43_reason(fn () => $policy->split(0)))->toBe('share_policy_invalid');
});

test('E43-SP-05: a share too small to credit blocks fulfillment into review instead of crediting zero', function () {
    config(['creator_premium.sales.min_price_minor' => 1]);
    $world = e43_world(['price' => '0.01']);
    $order = e43_order(e16User(), $world['series']);

    e41_pay($order['session']);
    $purchase = $order['purchase']->fresh();

    expect($purchase->status)->not->toBe('verified')->and($purchase->review_flag)->toBe('fulfillment_blocked')
        ->and(DB::table('creator_direct_sales')->count())->toBe(0)->and(DB::table('creator_series_accesses')->count())->toBe(0);
});

// ───────────── السعر والعملة ─────────────

test('E43-SP-06: price bounds and text are validated on the server (minor units, integer, within configured range)', function () {
    $world = e43_world(['offer' => false]);

    foreach (['0.50', '1000.01', 'abc', '-5', '5.123', ''] as $bad) {
        expect(e43_reason(fn () => e43_offers()->createDraft($world['user'], $world['series']->public_id, $bad)))->toBe('price_invalid', "price [{$bad}]");
    }

    $offer = e43_offers()->createDraft($world['user'], $world['series']->public_id, '1.00');
    expect($offer->price_minor)->toBe(100)->and($offer->currency)->toBe('USD')->and($offer->status)->toBe('draft')->and($offer->first_activated_at)->toBeNull();
});

test('E43-SP-07: Option A - the offer currency is always the creator earning-account currency; a mismatch blocks activation and selling', function () {
    $world = e43_world(['offer' => false]);
    $offer = e43_offer($world['user'], $world['series'], '5.00', false);
    expect($offer->currency)->toBe($world['account']->currency);

    DB::table('creator_earning_accounts')->where('id', $world['account']->id)->update(['currency' => 'EUR']);

    expect(e43_reason(fn () => e43_offers()->activate($world['user'], $offer->public_id)))->toBe('currency_mismatch')
        ->and($offer->fresh()->status)->toBe('draft');

    DB::table('creator_earning_accounts')->where('id', $world['account']->id)->update(['currency' => 'USD']);
    $active = e43_offers()->activate($world['user'], $offer->public_id);
    expect($active->status)->toBe('active');

    DB::table('creator_earning_accounts')->where('id', $world['account']->id)->update(['currency' => 'EUR']);
    expect(e43_offers()->sellBlocker($active->fresh()))->not->toBeNull()
        ->and(e43_reason(fn () => e43_purchases()->request(e16User(), $world['series'])))->toBe('sales_closed')
        ->and(DB::table('digital_purchases')->count())->toBe(0);
});

test('E43-SP-08: the currency of a draft follows the account until first activation, then it is frozen', function () {
    $world = e43_world(['offer' => false]);
    $draft = e43_offer($world['user'], $world['series'], '5.00', false);

    DB::table('creator_earning_accounts')->where('id', $world['account']->id)->update(['currency' => 'EUR']);
    $draft = e43_offers()->updatePrice($world['user'], $draft->public_id, '6.00');
    expect($draft->currency)->toBe('EUR');

    DB::table('creator_earning_accounts')->where('id', $world['account']->id)->update(['currency' => 'USD']);
    $draft = e43_offers()->updatePrice($world['user'], $draft->public_id, '7.00');
    $active = e43_offers()->activate($world['user'], $draft->public_id);

    expect($active->currency)->toBe('USD')->and($active->first_activated_at)->not->toBeNull();

    $frozen = CreatorSeriesSaleOffer::query()->find($active->id);
    $frozen->currency = 'EUR';
    expect(fn () => $frozen->save())->toThrow(LogicException::class);
});

// ───────────── دورة الحياة ─────────────

test('E43-SP-09: lifecycle - draft → active → paused → active → retired; first_activated_at is written once and survives pause/resume', function () {
    $world = e43_world(['offer' => false]);
    $draft = e43_offer($world['user'], $world['series'], '5.00', false);

    e43_tick();
    $active = e43_offers()->activate($world['user'], $draft->public_id);
    $first = $active->first_activated_at;
    expect($first)->not->toBeNull()->and(e43_offers()->activate($world['user'], $draft->public_id)->first_activated_at->equalTo($first))->toBeTrue();

    e43_tick(5);
    $paused = e43_offers()->pause($world['user'], $draft->public_id);
    expect($paused->status)->toBe('paused')->and($paused->pause_reason)->toBe('creator');

    e43_tick(5);
    $resumed = e43_offers()->activate($world['user'], $draft->public_id);
    expect($resumed->status)->toBe('active')->and($resumed->first_activated_at->equalTo($first))->toBeTrue()->and($resumed->pause_reason)->toBeNull();

    $retired = e43_offers()->retire($world['user'], $draft->public_id);
    expect($retired->status)->toBe('retired')->and($retired->retired_at)->not->toBeNull()->and($retired->live_series_id)->toBeNull()
        ->and(e43_reason(fn () => e43_offers()->activate($world['user'], $draft->public_id)))->toBe('offer_retired');
});

test('E43-SP-10: only one live offer per series (DB-enforced); after retiring a new offer may be created and the old row stays as history', function () {
    $world = e43_world(['offer' => false]);
    $first = e43_offer($world['user'], $world['series'], '5.00');

    expect(e43_reason(fn () => e43_offers()->createDraft($world['user'], $world['series']->public_id, '9.00')))->toBe('offer_exists');

    e43_offers()->retire($world['user'], $first->public_id);
    $second = e43_offers()->createDraft($world['user'], $world['series']->public_id, '9.00');

    expect($second->id)->not->toBe($first->id)->and(CreatorSeriesSaleOffer::query()->where('creator_series_id', $world['series']->id)->count())->toBe(2)
        ->and(fn () => DB::table('creator_series_sale_offers')->insert([
            'public_id' => (string) Str::ulid(), 'creator_series_id' => $world['series']->id, 'creator_profile_id' => $world['profile']->id,
            'status' => 'draft', 'live_series_id' => $world['series']->id, 'price_minor' => 500, 'currency' => 'USD', 'created_at' => now(), 'updated_at' => now(),
        ]))->toThrow(\Illuminate\Database\QueryException::class);
});

test('E43-SP-11: an offer is never deleted and transitions outside the state machine are refused', function () {
    $world = e43_world();
    $offer = CreatorSeriesSaleOffer::query()->find($world['offer']->id);

    expect(fn () => $offer->delete())->toThrow(LogicException::class);

    e43_offers()->retire($world['user'], $offer->public_id);
    $retired = CreatorSeriesSaleOffer::query()->find($offer->id);
    $retired->status = 'active';

    expect(fn () => $retired->save())->toThrow(LogicException::class);
});

test('E43-SP-12: price changes apply to future purchases only; an existing order keeps its snapshot price', function () {
    $world = e43_world(['price' => '5.00']);
    $buyerA = e16User();
    $orderA = e43_order($buyerA, $world['series']);

    e43_tick();
    e43_offers()->updatePrice($world['user'], $world['offer']->public_id, '8.00');
    $orderB = e43_order(e16User(), $world['series']);

    expect($orderA['purchase']->amount_minor)->toBe(500)->and($orderB['purchase']->amount_minor)->toBe(800);

    // الطلب القديم يُدفع بسعره المحفوظ ويتحقق بتطابق تام، والحصة تُحسب على اللقطة لا على السعر الجديد.
    e41_pay($orderA['session']);
    $sale = DB::table('creator_direct_sales')->where('digital_purchase_id', $orderA['purchase']->id)->first();

    expect($orderA['purchase']->fresh()->status)->toBe('verified')->and((int) $sale->gross_minor)->toBe(500)->and((int) $sale->creator_share_minor)->toBe(350);
});

test('E43-SP-13: offers need an enabled monetization account; creating while monetization is off is refused', function () {
    $admin = e39_admin();
    [$user] = e39_creator($admin, false);
    $series = e30s_published($user, e30s_works($user, 2));

    expect(e43_reason(fn () => e43_offers()->createDraft($user, $series->public_id, '5.00')))->toBe('monetization_required');
});

test('E43-SP-14: a creator cannot sell a series they do not own, and only published non-archived series can be sold', function () {
    $world = e43_world(['offer' => false]);
    [$other] = e39_creator(e39_admin());

    expect(fn () => e43_offers()->createDraft($other, $world['series']->public_id, '5.00'))->toThrow(ModelNotFoundException::class);

    $draftRevision = e30s_draft($world['user']);
    expect(e43_reason(fn () => e43_offers()->createDraft($world['user'], $draftRevision->series()->value('public_id'), '5.00')))->toBe('series_not_published');
});

// ───────────── المفتاح والإدارة ─────────────

test('E43-SP-15: with CREATOR_PREMIUM_ENABLED off nothing new can be activated or purchased, but existing history and ownership stay intact', function () {
    $world = e43_world();
    $bought = e43_buy($world);
    $second = e30s_published($world['user'], e30s_works($world['user'], 2));
    $draft = e43_offer($world['user'], $second, '3.00', false);

    config(['creator_premium.enabled' => false]);

    expect(e43_reason(fn () => e43_offers()->activate($world['user'], $draft->public_id)))->toBe('disabled')
        ->and(e43_reason(fn () => e43_purchases()->request(e16User(), $world['series'])))->toBe('disabled')
        ->and(e43_access_row($bought['user'], $world['series'])?->isActive())->toBeTrue()
        ->and(e43_access_service()->decide($bought['user'], $world['works'][0])->allowed)->toBeTrue()
        ->and(DB::table('creator_direct_sales')->count())->toBe(1);
});

test('E43-SP-16: admin pause/resume needs permission and a note, and a creator cannot undo an admin pause', function () {
    $world = e43_world();
    $plain = e39_plain_staff();

    expect(e43_reason(fn () => e43_offers()->pauseByAdmin($plain, $world['offer']->public_id, 'سبب كافٍ للإيقاف')))->toBe('forbidden')
        ->and(e43_reason(fn () => e43_offers()->pauseByAdmin($world['admin'], $world['offer']->public_id, 'قصير')))->toBe('note_required');

    $paused = e43_offers()->pauseByAdmin($world['admin'], $world['offer']->public_id, 'مراجعة شكوى من المشترين');
    expect($paused->status)->toBe('paused')->and($paused->pause_reason)->toBe('admin')
        ->and(e43_reason(fn () => e43_offers()->activate($world['user'], $paused->public_id)))->toBe('paused_by_admin');

    $resumed = e43_offers()->resumeByAdmin($world['admin'], $paused->public_id, 'اكتملت المراجعة');
    expect($resumed->status)->toBe('active')->and($resumed->pause_reason)->toBeNull();
});

test('E43-SP-17: a series with a live (active or paused) offer cannot be archived until the offer is retired', function () {
    $world = e43_world();

    expect(fn () => app(CreatorSeriesService::class)->archive($world['user'], $world['series']->public_id))->toThrow(CreatorSeriesException::class);

    e43_offers()->pause($world['user'], $world['offer']->public_id);
    expect(fn () => app(CreatorSeriesService::class)->archive($world['user'], $world['series']->public_id))->toThrow(CreatorSeriesException::class);

    e43_offers()->retire($world['user'], $world['offer']->public_id);
    expect(app(CreatorSeriesService::class)->archive($world['user'], $world['series']->public_id)->isArchived())->toBeTrue();
});

test('E43-SP-18: offer changes are audited with the acting user', function () {
    $world = e43_world();
    e43_offers()->pause($world['user'], $world['offer']->public_id);
    e43_offers()->activate($world['user'], $world['offer']->public_id);
    e43_offers()->updatePrice($world['user'], $world['offer']->public_id, '6.00');

    $rows = DB::table('operational_audit_logs')->whereIn('action', ['creator_premium_offer_created', 'creator_premium_offer_activated', 'creator_premium_offer_paused', 'creator_premium_offer_resumed', 'creator_premium_offer_price_changed'])->get();

    expect($rows->pluck('action')->all())->toContain('creator_premium_offer_created', 'creator_premium_offer_activated', 'creator_premium_offer_paused', 'creator_premium_offer_resumed', 'creator_premium_offer_price_changed')
        ->and($rows->pluck('actor_user_id')->unique()->all())->toBe([$world['user']->id]);
});
