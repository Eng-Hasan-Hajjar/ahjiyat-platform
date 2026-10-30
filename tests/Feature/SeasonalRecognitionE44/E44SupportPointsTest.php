<?php

use App\Models\SeasonSupportPointEntry;
use App\Models\SeasonSupporterStat;
use App\Models\StoreItem;
use App\Models\StoreItemPrice;
use App\Models\StorePurchase;
use App\Services\SeasonalRecognition\SeasonSupportSeasonResolver;
use App\Services\SeasonalRecognition\StorePurchaseSupportService;
use App\Services\Store\StorePurchaseService;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

require_once __DIR__.'/_support.php';

/*
| E44 / نقاط الدعم من متجر العملات الداخلية: لقطة النقاط عند الإنشاء، المنح عند التنفيذ الفعلي فقط، موسم واحد مؤهَّل بلا تخمين، عكس واحد باللقطة الأصلية عند الاسترداد،
| مطابقة الدفتر مقابل الإحصاءات، وعزل النقاط عن المال/الجواهر/الخبرة/تمريرة الموسم/أرباح المبدعين.
*/

beforeEach(fn () => e44_seed());
afterEach(fn () => Carbon::setTestNow());

/** موسم مباشر واحد بتقدير داعمين مُفعَّل. @return array{0: \App\Models\Season, 1: \App\Models\User} */
function e44_sp_world(): array
{
    $admin = e44_admin();
    $season = e44_season();
    e44_settings()->setSupportersEnabled($season, true, $admin);

    return [$season->fresh(), $admin];
}

function e44_sp_snapshot(StorePurchase $purchase): int
{
    return (int) StorePurchase::query()->whereKey($purchase->id)->value('support_points_snapshot');
}

test('E44-SP-01: a fulfilled store purchase grants its price-level support points to the single eligible live season — and touches nothing else', function () {
    [$season] = e44_sp_world();
    $buyer = e16User();
    $store = e44_store_item(50, 10);
    $before = Arr::except(e44_foreign_counts(), ['currency_transactions']);

    $purchase = e44_buy($buyer, $store);

    expect($purchase->status)->toBe(StorePurchase::STATUS_FULFILLED)
        ->and(e44_sp_snapshot($purchase))->toBe(50);

    $entries = e44_entries($season, $buyer);

    expect($entries)->toHaveCount(1);

    $entry = $entries->first();

    expect($entry->source_type)->toBe('store_purchase')
        ->and((int) $entry->source_id)->toBe((int) $purchase->id)
        ->and($entry->source_key)->toBe('store_purchase:'.$purchase->id.':grant')
        ->and($entry->reason)->toBe('grant')
        ->and((int) $entry->points_delta)->toBe(50)
        ->and($entry->is_public)->toBeTrue()
        ->and($entry->reverses_entry_id)->toBeNull();

    $stat = e44_stat($season, $buyer);

    expect((int) $stat->points_total)->toBe(50)
        ->and((int) $stat->public_points_total)->toBe(50)
        ->and($stat->first_point_at)->not->toBeNull()
        ->and(Arr::except(e44_foreign_counts(), ['currency_transactions']))->toBe($before)
        ->and(e44_supporter_reconciler()->inspect($season))->toBe([]);
});

test('E44-SP-02: a price level with zero support points (the default) grants nothing, and the purchase still succeeds', function () {
    [$season] = e44_sp_world();
    $buyer = e16User();
    $store = e44_store_item(0, 10);

    $purchase = e44_buy($buyer, $store);

    expect($purchase->status)->toBe(StorePurchase::STATUS_FULFILLED)
        ->and(e44_sp_snapshot($purchase))->toBe(0)
        ->and(e44_entries($season))->toHaveCount(0)
        ->and(e44_stat($season, $buyer))->toBeNull()
        ->and(app(StorePurchaseSupportService::class)->onFulfilled($purchase->id))->toBe('no_points');

    // الصف القديم/الافتراضي: عمود نقاط الدعم 0 دون تحديد.
    expect((int) StoreItemPrice::factory()->create()->fresh()->support_points)->toBe(0);
});

test('E44-SP-03: with the global supporters flag off at purchase time the snapshot is zero — turning it on later never back-grants', function () {
    [$season] = e44_sp_world();
    config(['seasonal_recognition.supporters.enabled' => false]);

    $buyer = e16User();
    $purchase = e44_buy($buyer, e44_store_item(50, 10));

    config(['seasonal_recognition.supporters.enabled' => true]);

    expect($purchase->status)->toBe(StorePurchase::STATUS_FULFILLED)
        ->and(e44_sp_snapshot($purchase))->toBe(0)
        ->and(app(StorePurchaseSupportService::class)->onFulfilled($purchase->id))->toBe('no_points')
        ->and(e44_entries($season))->toHaveCount(0);
});

test('E44-SP-04: no eligible season (season flag off) — the purchase succeeds with a snapshot but grants no points until a supervisor backfills it explicitly', function () {
    $admin = e44_admin();
    $season = e44_season(); // الدوري/الداعمون غير مفعَّلين لهذا الموسم
    $buyer = e16User();

    $purchase = e44_buy($buyer, e44_store_item(40, 10));

    expect($purchase->status)->toBe(StorePurchase::STATUS_FULFILLED)
        ->and(e44_sp_snapshot($purchase))->toBe(40)
        ->and(e44_entries($season))->toHaveCount(0)
        ->and(app(SeasonSupportSeasonResolver::class)->resolve()['status'])->toBe(SeasonSupportSeasonResolver::NONE)
        ->and(app(StorePurchaseSupportService::class)->onFulfilled($purchase->id))->toBe('no_season');

    // المشرف يفعّل الموسم ثم يمنح صراحةً: صلاحية manage، ومرة واحدة.
    e44_settings()->setSupportersEnabled($season, true, $admin);

    expect(e44_reason(fn () => e44_supporter_reconciler()->backfillStorePurchase($season->fresh(), $purchase, e44_staff(['seasonal-recognition.view']))))->toBe('forbidden')
        ->and(e44_supporter_reconciler()->backfillStorePurchase($season->fresh(), $purchase, $admin))->toBeTrue()
        ->and(e44_supporter_reconciler()->backfillStorePurchase($season->fresh(), $purchase, $admin))->toBeFalse()
        ->and(e44_user_points($season, $buyer))->toBe(40)
        ->and(e44_audit('seasonal_support_backfilled'))->toBe(1)
        ->and(e44_supporter_reconciler()->inspect($season))->toBe([]);

    // لا يُمنح ما لا يستوجب: لقطة صفرية.
    $free = e44_buy(e16User(), e44_store_item(0, 10));
    expect(e44_reason(fn () => e44_supporter_reconciler()->backfillStorePurchase($season->fresh(), $free, $admin)))->toBe('not_grantable');
});

test('E44-SP-05: more than one eligible live season is never guessed — no grant, an audit entry and a log alert, a reconciliation finding, and a supervisor resolves it', function () {
    $admin = e44_admin();
    $a = e44_season();
    $b = e44_season();
    e44_settings()->setSupportersEnabled($a, true, $admin);
    e44_settings()->setSupportersEnabled($b, true, $admin);
    Log::spy();

    $buyer = e16User();
    $purchase = e44_buy($buyer, e44_store_item(60, 10));

    expect($purchase->status)->toBe(StorePurchase::STATUS_FULFILLED)
        ->and(e44_sp_snapshot($purchase))->toBe(60)
        ->and(e44_entries($a))->toHaveCount(0)
        ->and(e44_entries($b))->toHaveCount(0)
        ->and(app(SeasonSupportSeasonResolver::class)->resolve()['status'])->toBe(SeasonSupportSeasonResolver::AMBIGUOUS)
        ->and(e44_audit('seasonal_support_ambiguous_season'))->toBe(1);

    Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains((string) $message, 'E44'))->once();

    foreach ([$a, $b] as $season) {
        $findings = e44_supporter_reconciler()->inspect($season->fresh());
        expect(array_column($findings, 'code'))->toBe(['store_purchase_without_grant'])
            ->and($findings[0]['purchase_id'])->toBe((int) $purchase->id);
    }

    // المشرف يحدد الموسم صراحةً.
    expect(e44_supporter_reconciler()->backfillStorePurchase($a->fresh(), $purchase, $admin))->toBeTrue()
        ->and(e44_user_points($a, $buyer))->toBe(60)
        ->and(e44_user_points($b, $buyer))->toBe(0)
        ->and(e44_supporter_reconciler()->inspect($a->fresh()))->toBe([])
        ->and(e44_supporter_reconciler()->inspect($b->fresh()))->toBe([]);

    // وبعد تعطيل أحد الموسمين تعود الشراءات الجديدة إلى المنح التلقائي للموسم الوحيد المؤهَّل.
    e44_settings()->setSupportersEnabled($b, false, $admin);
    $next = e44_buy($buyer, e44_store_item(10, 10));

    expect(e44_user_points($a, $buyer))->toBe(70)
        ->and(e44_entries($a, $buyer))->toHaveCount(2)
        ->and(e44_sp_snapshot($next))->toBe(10);
});

test('E44-SP-06: a pending manual order grants nothing until it is actually fulfilled; a delivered manual order is not refundable here; a pending one refunds with no points ever involved', function () {
    [$season, $admin] = e44_sp_world();
    $buyer = e16User();
    $item = StoreItem::factory()->manual()->create();
    $price = StoreItemPrice::factory()->create(['store_item_id' => $item->id, 'amount' => 10, 'support_points' => 30])->fresh();

    $pending = e44_buy($buyer, ['item' => $item, 'price' => $price]);

    expect($pending->status)->toBe(StorePurchase::STATUS_PENDING_FULFILLMENT)
        ->and(e44_sp_snapshot($pending))->toBe(30)
        ->and(e44_entries($season))->toHaveCount(0);

    app(StorePurchaseService::class)->fulfillManually($pending, $admin, 'سُلِّم', 'تم التسليم');

    expect(e44_user_points($season, $buyer))->toBe(30)
        ->and(e44_entries($season, $buyer))->toHaveCount(1)
        ->and(fn () => app(StorePurchaseService::class)->refund($pending->fresh(), $admin, 'سبب'))->toThrow(RuntimeException::class)
        ->and(e44_user_points($season, $buyer))->toBe(30);

    // طلب يدوي معلّق يُسترد قبل التسليم: لا منح سابق فلا عكس.
    $other = e16User();
    $second = e44_buy($other, ['item' => $item, 'price' => $price]);
    app(StorePurchaseService::class)->refund($second, $admin, 'إلغاء قبل التسليم');

    expect($second->fresh()->status)->toBe(StorePurchase::STATUS_REFUNDED)
        ->and(e44_stat($season, $other))->toBeNull()
        ->and(e44_entries($season, $other))->toHaveCount(0)
        ->and(app(StorePurchaseSupportService::class)->onRefunded($second->id))->toBeFalse();
});

test('E44-SP-07: refunding appends exactly ONE negative reversal that mirrors the original snapshot — not today\'s price or settings — and is idempotent', function () {
    [$season, $admin] = e44_sp_world();
    $buyer = e16User();
    $store = e44_store_item(50, 10);
    $purchase = e44_buy($buyer, $store);

    // الإعدادات تتغير بعد الشراء: السعر الحالي والسقف لا يؤثران على ما يُعكس.
    $store['price']->update(['support_points' => 999]);
    config(['seasonal_recognition.supporters.max_support_points_per_purchase' => 10]);

    app(StorePurchaseService::class)->refund($purchase, $admin, 'طلب العميل');

    $entries = e44_entries($season, $buyer);

    expect($entries)->toHaveCount(2);

    [$grant, $reversal] = [$entries[0], $entries[1]];

    expect((int) $grant->points_delta)->toBe(50)
        ->and($reversal->reason)->toBe('reversal')
        ->and((int) $reversal->points_delta)->toBe(-50)
        ->and((int) $reversal->reverses_entry_id)->toBe((int) $grant->id)
        ->and($reversal->source_key)->toBe('store_purchase:'.$purchase->id.':reversal')
        ->and($reversal->is_public)->toBe($grant->is_public)
        ->and((int) e44_stat($season, $buyer)->points_total)->toBe(0)
        ->and((int) e44_stat($season, $buyer)->public_points_total)->toBe(0);

    expect(app(StorePurchaseSupportService::class)->onRefunded($purchase->id))->toBeFalse()
        ->and(e44_entries($season, $buyer))->toHaveCount(2)
        ->and(e44_supporter_reconciler()->inspect($season))->toBe([])
        ->and(fn () => app(StorePurchaseService::class)->refund($purchase->fresh(), $admin, 'مرة ثانية'))->toThrow(RuntimeException::class);
});

test('E44-SP-08: a repeated or concurrent event for one purchase can never double-grant — service, ledger key and DB uniqueness all agree', function () {
    [$season] = e44_sp_world();
    $buyer = e16User();
    $purchase = e44_buy($buyer, e44_store_item(25, 10));
    $support = app(StorePurchaseSupportService::class);

    expect($support->onFulfilled($purchase->id))->toBe('duplicate')
        ->and($support->onFulfilled($purchase->id))->toBe('duplicate')
        ->and(e44_points()->grant($season->id, $buyer->id, SeasonSupportPointEntry::SOURCE_STORE_PURCHASE, $purchase->id, 25, true))->toBeNull()
        ->and(e44_entries($season, $buyer))->toHaveCount(1)
        ->and(e44_user_points($season, $buyer))->toBe(25);

    expect(fn () => DB::table('season_support_point_entries')->insert([
        'season_id' => $season->id, 'user_id' => $buyer->id, 'source_type' => 'store_purchase', 'source_id' => $purchase->id,
        'source_key' => 'store_purchase:'.$purchase->id.':grant', 'reason' => 'grant', 'points_delta' => 25, 'is_public' => 1, 'created_at' => now(),
    ]))->toThrow(\Illuminate\Database\UniqueConstraintViolationException::class);

    $original = e44_entries($season, $buyer)->first();

    // العكس الأول لهذا الأصل مقبول فعلًا (insert يعيد true؛ أي استثناء يُفشل الاختبار).
    expect(DB::table('season_support_point_entries')->insert([
        'season_id' => $season->id, 'user_id' => $buyer->id, 'source_type' => 'store_purchase', 'source_id' => 9999,
        'source_key' => 'store_purchase:9999:reversal', 'reason' => 'reversal', 'points_delta' => -25, 'is_public' => 1, 'reverses_entry_id' => $original->id, 'created_at' => now(),
    ]))->toBeTrue();

    // عكس ثانٍ لنفس الأصل مرفوض بفرادة reverses_entry_id.
    expect(fn () => DB::table('season_support_point_entries')->insert([
        'season_id' => $season->id, 'user_id' => $buyer->id, 'source_type' => 'store_purchase', 'source_id' => 9998,
        'source_key' => 'store_purchase:9998:reversal', 'reason' => 'reversal', 'points_delta' => -25, 'is_public' => 1, 'reverses_entry_id' => $original->id, 'created_at' => now(),
    ]))->toThrow(\Illuminate\Database\UniqueConstraintViolationException::class);
});

test('E44-SP-09: the point ledger is append-only and validates every row — no edits, no deletes, no foreign sources, no non-positive grants', function () {
    [$season] = e44_sp_world();
    $buyer = e16User();
    e44_buy($buyer, e44_store_item(25, 10));
    $entry = e44_entries($season, $buyer)->first();

    expect(fn () => $entry->forceFill(['points_delta' => 1])->save())->toThrow(LogicException::class)
        ->and(fn () => $entry->delete())->toThrow(LogicException::class)
        ->and(e44_points()->grant($season->id, $buyer->id, 'store_purchase', 777, 0, true))->toBeNull()
        ->and(e44_points()->grant($season->id, $buyer->id, 'store_purchase', 777, -5, true))->toBeNull();

    $make = fn (array $attrs) => (new SeasonSupportPointEntry)->forceFill($attrs + [
        'season_id' => $season->id, 'user_id' => $buyer->id, 'source_type' => 'store_purchase', 'source_id' => 5000, 'source_key' => 'k'.random_int(1, 999999),
        'reason' => 'grant', 'points_delta' => 5, 'is_public' => true,
    ])->save();

    expect(fn () => $make(['source_type' => 'gift']))->toThrow(LogicException::class)
        ->and(fn () => $make(['reason' => 'bonus']))->toThrow(LogicException::class)
        ->and(fn () => $make(['points_delta' => 0]))->toThrow(LogicException::class)
        ->and(fn () => $make(['points_delta' => -3]))->toThrow(LogicException::class)
        ->and(fn () => $make(['reason' => 'reversal', 'points_delta' => 3, 'reverses_entry_id' => $entry->id]))->toThrow(LogicException::class)
        ->and(fn () => $make(['reason' => 'reversal', 'points_delta' => -3]))->toThrow(LogicException::class);

    // السقف: منح يتجاوزه مرفوض في النموذج.
    config(['seasonal_recognition.supporters.max_support_points_per_purchase' => 100]);
    expect(fn () => e44_points()->grant($season->id, $buyer->id, 'store_purchase', 6000, 101, true))->toThrow(LogicException::class)
        ->and(e44_user_points($season, $buyer))->toBe(25);
});

test('E44-SP-10: the per-purchase cap clamps the snapshot at creation', function () {
    [$season] = e44_sp_world();
    config(['seasonal_recognition.supporters.max_support_points_per_purchase' => 100]);
    $buyer = e16User();

    $purchase = e44_buy($buyer, e44_store_item(500, 10));

    expect(e44_sp_snapshot($purchase))->toBe(100)
        ->and(e44_user_points($season, $buyer))->toBe(100);
});

test('E44-SP-11: the reconciler reports stat drift, negative totals and missing stat rows; rebuilding is audited, permissioned, idempotent and refused after finalization', function () {
    [$season, $admin] = e44_sp_world();
    $buyer = e16User();
    e44_buy($buyer, e44_store_item(50, 10));
    $rec = e44_supporter_reconciler();

    expect($rec->inspect($season))->toBe([]);

    DB::table('season_supporter_stats')->where('season_id', $season->id)->where('user_id', $buyer->id)->update(['points_total' => 999, 'public_points_total' => 7]);

    $findings = $rec->inspect($season);

    expect(array_column($findings, 'code'))->toBe(['stat_points_mismatch'])
        ->and($findings[0]['stored'])->toBe(999)->and($findings[0]['ledger'])->toBe(50)->and($findings[0]['stored_public'])->toBe(7)->and($findings[0]['ledger_public'])->toBe(50);

    DB::table('season_supporter_stats')->where('season_id', $season->id)->where('user_id', $buyer->id)->update(['points_total' => -5]);
    expect(array_column($rec->inspect($season), 'code'))->toBe(['negative_total']);

    expect(e44_reason(fn () => $rec->rebuildStats($season, e44_staff(['seasonal-recognition.manage']))))->toBe('forbidden')
        ->and($rec->rebuildStats($season, $admin))->toBe(1)
        ->and($rec->inspect($season))->toBe([])
        ->and(e44_user_points($season, $buyer))->toBe(50)
        ->and(e44_public_points($season, $buyer))->toBe(50)
        ->and($rec->rebuildStats($season, $admin))->toBe(0)
        ->and(e44_audit('seasonal_supporters_stats_rebuilt'))->toBe(1);

    DB::table('season_supporter_stats')->where('season_id', $season->id)->where('user_id', $buyer->id)->delete();
    expect(array_column($rec->inspect($season), 'code'))->toContain('entry_without_stat');

    // بعد إنهاء الترتيب لا إعادة بناء.
    DB::table('season_recognition_settings')->where('season_id', $season->id)->update(['supporters_finalized_at' => now()]);
    expect(e44_reason(fn () => $rec->rebuildStats($season, $admin)))->toBe('finalized');
});

test('E44-SP-12: the reconciler catches a reversal that does not mirror its original and a refunded purchase that was never reversed — the latter is repaired by the idempotent reversal', function () {
    [$season, $admin] = e44_sp_world();
    $rec = e44_supporter_reconciler();

    // (أ) عكس لا يطابق أصله.
    $one = e44_buy(e16User(), e44_store_item(50, 10));
    app(StorePurchaseService::class)->refund($one, $admin, 'طلب العميل');
    expect($rec->inspect($season))->toBe([]);

    DB::table('season_support_point_entries')->where('reason', 'reversal')->update(['points_delta' => -10]);
    $codes = array_column($rec->inspect($season), 'code');

    expect($codes)->toContain('reversal_mismatch')->toContain('stat_points_mismatch');

    // (ب) استرداد دون عكس (تجاوز المراقب عمدًا).
    $buyer = e16User();
    $two = e44_buy($buyer, e44_store_item(20, 10));
    DB::table('store_purchases')->where('id', $two->id)->update(['status' => StorePurchase::STATUS_REFUNDED]);

    $missing = collect($rec->inspect($season))->firstWhere('code', 'store_purchase_refunded_not_reversed');

    expect($missing)->not->toBeNull()
        ->and($missing['purchase_id'])->toBe((int) $two->id)
        ->and(app(StorePurchaseSupportService::class)->onRefunded($two->id))->toBeTrue()
        ->and(e44_user_points($season, $buyer))->toBe(0)
        ->and(collect($rec->inspect($season))->firstWhere('code', 'store_purchase_refunded_not_reversed'))->toBeNull();
});

test('E44-SP-13: a failure inside the support bridge never fails or rolls back the purchase', function () {
    [$season] = e44_sp_world();
    $buyer = e16User();

    // يكسر الجسر: خدمة نقاط تُلقي استثناءً عند المنح (يُستبدل ارتباطها قبل الشراء؛ المراقب يُحل من الحاوية عند كل حدث).
    $broken = new class(app(\App\Services\SeasonalRecognition\SeasonRecognitionSettingsService::class), app(SeasonSupportSeasonResolver::class), new class extends \App\Services\SeasonalRecognition\SeasonSupportPointService
    {
        public function grant(int $seasonId, int $userId, string $sourceType, int $sourceId, int $points, bool $isPublic): ?SeasonSupportPointEntry
        {
            throw new RuntimeException('boom');
        }
    }, app(\App\Services\SeasonalRecognition\SeasonalRecognitionBroadcaster::class), app(\App\Services\OperationalAuditService::class)) extends StorePurchaseSupportService {};

    app()->instance(StorePurchaseSupportService::class, $broken);

    $purchase = e44_buy($buyer, e44_store_item(50, 10));

    expect($purchase->status)->toBe(StorePurchase::STATUS_FULFILLED)
        ->and(e44_sp_snapshot($purchase))->toBe(50)
        ->and(e44_entries($season))->toHaveCount(0)
        ->and(StorePurchase::query()->whereKey($purchase->id)->exists())->toBeTrue()
        ->and($broken->onFulfilled($purchase->id))->toBe('failed');
});

test('E44-SP-14: support points are not money, gems, XP, season-pass points, competition score or creator earnings — no column, balance or ledger of those moves', function () {
    [$season] = e44_sp_world();
    $buyer = e16User();
    $before = Arr::except(e44_foreign_counts(), ['currency_transactions']);
    $money = e44_money_state($buyer);

    e44_buy($buyer, e44_store_item(80, 10));
    e44_points()->grant($season->id, $buyer->id, 'supporter_card', 123, 40, true);

    expect(e44_user_points($season, $buyer))->toBe(120)
        ->and(Arr::except(e44_foreign_counts(), ['currency_transactions']))->toBe($before);

    // لا تظهر في أي دفتر خارجي: عدد معاملات العملات = الشحن والشراء فقط (لا قيد نقاط دعم).
    $reasons = DB::table('currency_transactions')->where('user_id', $buyer->id)->pluck('reason')->all();
    expect(collect($reasons)->contains(fn ($r) => str_contains((string) $r, 'support_point') || str_contains((string) $r, 'seasonal')))->toBeFalse();
    expect(array_diff_key(e44_money_state($buyer), ['currency_transactions' => 1]))->toBe(array_diff_key($money, ['currency_transactions' => 1]));
});

test('E44-SP-15: stat rows are created lazily and never deleted, and the season/user pair is immutable', function () {
    [$season] = e44_sp_world();
    $buyer = e16User();
    e44_buy($buyer, e44_store_item(50, 10));
    $stat = e44_stat($season, $buyer);

    expect(fn () => $stat->delete())->toThrow(LogicException::class)
        ->and(fn () => $stat->forceFill(['user_id' => 999])->save())->toThrow(LogicException::class)
        ->and(fn () => $stat->forceFill(['season_id' => 999])->save())->toThrow(LogicException::class)
        ->and(SeasonSupporterStat::query()->where('season_id', $season->id)->count())->toBe(1);

    // الأقفال: قفل الإحصاء يُنشئ الصف كسولًا ويعيد الموجود نفسه.
    $other = e16User();
    DB::transaction(fn () => e44_points()->lockStat($season->id, $other->id));
    DB::transaction(fn () => e44_points()->lockStat($season->id, $other->id));

    expect(SeasonSupporterStat::query()->where('season_id', $season->id)->where('user_id', $other->id)->count())->toBe(1);
});

test('E44-SP-16: after the supporters ranking is finalized a late grant is refused without leaving any trace, and an empty unstamped stat row is not a reconciliation finding', function () {
    $w = e44_supporters_world();
    $season = $w['season'];
    $stamped = e16User();
    $late = e16User();
    e44_buy_card($stamped, $w['packages']['gold']);

    Carbon::setTestNow(Carbon::parse('2026-12-31 00:00:00'));
    e44_supporter_finalizer()->finalize($season, $w['admin']);

    $stats = fn () => SeasonSupporterStat::query()->where('season_id', $season->id)->count();
    $before = ['entries' => e44_entries($season)->count(), 'stats' => $stats(), 'points' => e44_user_points($season, $stamped)];

    // منح متأخر لمستخدم جديد ولمستخدم داخل اللقطة: يُرفض بلا قيد ولا صف إحصاء ولا تغيير في المجاميع.
    expect(e44_points()->grant($season->id, $late->id, SeasonSupportPointEntry::SOURCE_STORE_PURCHASE, 777001, 50, true))->toBeNull()
        ->and(e44_points()->grant($season->id, $stamped->id, SeasonSupportPointEntry::SOURCE_STORE_PURCHASE, 777002, 50, true))->toBeNull()
        ->and(e44_entries($season)->count())->toBe($before['entries'])->and($stats())->toBe($before['stats'])
        ->and(e44_user_points($season, $stamped))->toBe($before['points'])->and(e44_stat($season, $late))->toBeNull();

    // صف إحصاء كسول فارغ بلا ختم (قفل أُنشئ ولم يُمنح شيء) ليس «لقطة ناقصة»؛ الصف ذو القيود غير المختوم هو المخالفة الحقيقية (SB-07).
    e44_points()->lockStat($season->id, $late->id);

    expect($stats())->toBe($before['stats'] + 1)->and(e44_supporter_reconciler()->inspect($season))->toBe([]);
});
