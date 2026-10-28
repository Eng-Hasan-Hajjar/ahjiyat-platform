<?php

use App\Models\CreatorEarningEntry;
use App\Models\CreatorSeriesAccess;
use App\Services\CreatorPremium\CreatorPremiumPolicy;
use App\Services\CreatorPremium\CreatorPremiumRefundService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

require_once __DIR__.'/_support.php';

/*
|--------------------------------------------------------------------------
| E43 - حدود السوق المميز في القاعدة نفسها. سائق-محايد: SQLite افتراضيًا وMySQL 8 عبر phpunit.mysql.xml.dist (خطوة mysql-parity في CI).
| تثبت: عرض حيّ واحد لكل سلسلة (live_series_id) مع تعايش NULL بعد السحب، وصول واحد لكل (مستخدم، سلسلة) ولكل شراء، بيع واحد لكل شراء وقيد دائن/عاكس فريد، فرادة source_key في دفتر E39،
| سياسة المفاتيح الأجنبية (RESTRICT: التاريخ التجاري/المحاسبي لا يُحذف تتابعيًا)، النص العربي والرموز التعبيرية حتى حدود الأعمدة، دقّة الأعداد الصحيحة الكبيرة وحتمية قاعدة الباقي، الفهارس المسماة، ولا float/decimal.
| السباق الحقيقي بين عمليات PHP في tools/e43/concurrency-probe.php.
|--------------------------------------------------------------------------
*/

beforeEach(fn () => e43_seed());

function e43db_violates(callable $call): bool
{
    try {
        DB::transaction($call);
    } catch (QueryException) {
        return true;
    }

    return false;
}

/** صف عرض خام (للتحقق من القيود لا من الخدمة). */
function e43db_offer_row(array $world, array $over = []): array
{
    return $over + [
        'public_id' => (string) Str::ulid(), 'creator_series_id' => $world['series']->id, 'creator_profile_id' => $world['profile']->id, 'status' => 'retired', 'live_series_id' => null,
        'price_minor' => 500, 'currency' => 'USD', 'created_at' => now(), 'updated_at' => now(),
    ];
}

/** طلب شراء إضافي مفتوح (يكفي كمفتاح أجنبي) لمشترٍ جديد على السلسلة نفسها. */
function e43db_purchase_id(array $world): int
{
    return e43_order(e16User(), $world['series'])['purchase']->id;
}

/** قيد دائن جديد بمفتاح مصدر معطى (عبر الدفتر الرسمي). */
function e43db_credit_entry(array $world, string $key, int $amount = 350): int
{
    return (int) DB::transaction(fn () => e39_ledger()->post(e39_ledger()->lockAccount($world['account']->id), CreatorEarningEntry::TYPE_DIRECT_SALE_CREDIT, $amount, 0, 0, $key)->getKey());
}

/** يستنسخ صف بيع لشراء آخر بقيد دائن آخر (لقياس القيود لا الخدمة). */
function e43db_clone_sale(object $sale, int $purchaseId, int $creditEntryId, array $over = []): array
{
    $row = (array) $sale;
    unset($row['id']);

    return $over + array_merge($row, ['public_id' => (string) Str::ulid(), 'digital_purchase_id' => $purchaseId, 'credit_entry_id' => $creditEntryId, 'reversal_entry_id' => null]);
}

test('E43-DB-01: one live offer per series is a database rule, and NULL live_series_id lets any number of retired rows coexist', function () {
    $world = e43_world();
    $series = $world['series'];

    expect((int) DB::table('creator_series_sale_offers')->where('id', $world['offer']->id)->value('live_series_id'))->toBe($series->id)
        ->and(e43db_violates(fn () => DB::table('creator_series_sale_offers')->insert(e43db_offer_row($world, ['status' => 'draft', 'live_series_id' => $series->id]))))->toBeTrue();

    // سحب العرض يفرّغ المفتاح فيتعايش أي عدد من الصفوف المسحوبة، ويُفتح عرض حيّ جديد.
    e43_offers()->retire($world['user'], $world['offer']->public_id);

    expect(DB::table('creator_series_sale_offers')->where('id', $world['offer']->id)->value('live_series_id'))->toBeNull()
        ->and(e43db_violates(fn () => DB::table('creator_series_sale_offers')->insert(e43db_offer_row($world))))->toBeFalse()
        ->and(e43db_violates(fn () => DB::table('creator_series_sale_offers')->insert(e43db_offer_row($world))))->toBeFalse()
        ->and(e43db_violates(fn () => DB::table('creator_series_sale_offers')->insert(e43db_offer_row($world, ['status' => 'draft', 'live_series_id' => $series->id]))))->toBeFalse()
        ->and(e43db_violates(fn () => DB::table('creator_series_sale_offers')->insert(e43db_offer_row($world, ['status' => 'draft', 'live_series_id' => $series->id]))))->toBeTrue()
        ->and(DB::table('creator_series_sale_offers')->where('creator_series_id', $series->id)->count())->toBe(4);
});

test('E43-DB-02: an offer public id is globally unique and an offer must point at a real series and profile', function () {
    $world = e43_world();
    $id = (string) Str::ulid();
    DB::table('creator_series_sale_offers')->insert(e43db_offer_row($world, ['public_id' => $id]));

    expect(e43db_violates(fn () => DB::table('creator_series_sale_offers')->insert(e43db_offer_row($world, ['public_id' => $id]))))->toBeTrue()
        ->and(e43db_violates(fn () => DB::table('creator_series_sale_offers')->insert(e43db_offer_row($world, ['creator_series_id' => 987654]))))->toBeTrue()
        ->and(e43db_violates(fn () => DB::table('creator_series_sale_offers')->insert(e43db_offer_row($world, ['creator_profile_id' => 987654]))))->toBeTrue();
});

test('E43-DB-03: one access row per (user, series) and one access per source purchase - the database refuses a second ownership row', function () {
    $world = e43_world();
    $bought = e43_buy($world);
    $access = DB::table('creator_series_accesses')->where('user_id', $bought['user']->id)->first();
    $extra = e43db_purchase_id($world);
    $clone = fn (array $over) => array_merge((array) $access, ['public_id' => (string) Str::ulid()], $over, ['id' => null]);
    $insert = function (array $over) use ($clone) {
        $row = $clone($over);
        unset($row['id']);

        return DB::table('creator_series_accesses')->insert($row);
    };

    expect(e43db_violates(fn () => $insert(['source_digital_purchase_id' => $extra])))->toBeTrue() // المستخدم نفسه + السلسلة نفسها
        ->and(e43db_violates(fn () => $insert(['user_id' => e16User()->id])))->toBeTrue() // الشراء المصدر نفسه لمستخدم آخر
        ->and(e43db_violates(fn () => $insert(['user_id' => e16User()->id, 'source_digital_purchase_id' => $extra])))->toBeFalse()
        ->and(CreatorSeriesAccess::query()->where('creator_series_id', $world['series']->id)->count())->toBe(2);
});

test('E43-DB-04: one sale per purchase, one credit entry per sale and one reversal entry per sale - NULL reversals coexist', function () {
    $world = e43_world();
    $bought = e43_buy($world);
    $sale = DB::table('creator_direct_sales')->where('id', $bought['sale']->id)->first();
    [$p2, $p3] = [e43db_purchase_id($world), e43db_purchase_id($world)];
    [$e2, $e3] = [e43db_credit_entry($world, 'direct_sale:910001:credit'), e43db_credit_entry($world, 'direct_sale:910002:credit')];

    expect(e43db_violates(fn () => DB::table('creator_direct_sales')->insert(e43db_clone_sale($sale, $bought['purchase']->id, $e2))))->toBeTrue() // الشراء نفسه
        ->and(e43db_violates(fn () => DB::table('creator_direct_sales')->insert(e43db_clone_sale($sale, $p2, $sale->credit_entry_id))))->toBeTrue() // القيد الدائن نفسه
        ->and(e43db_violates(fn () => DB::table('creator_direct_sales')->insert(e43db_clone_sale($sale, $p2, $e2))))->toBeFalse() // بيعان بلا عكس: NULL يتعايش
        ->and(e43db_violates(fn () => DB::table('creator_direct_sales')->insert(e43db_clone_sale($sale, $p3, $e3, ['reversal_entry_id' => $e2]))))->toBeFalse()
        ->and(DB::table('creator_direct_sales')->count())->toBe(3);

    $p4 = e43db_purchase_id($world);
    $e4 = e43db_credit_entry($world, 'direct_sale:910003:credit');

    expect(e43db_violates(fn () => DB::table('creator_direct_sales')->insert(e43db_clone_sale($sale, $p4, $e4, ['reversal_entry_id' => $e2]))))->toBeTrue(); // العكس نفسه لبيعين
});

test('E43-DB-05: the ledger source_key of a sale credit and reversal is unique and posting twice is a no-op', function () {
    $world = e43_world();
    $bought = e43_buy($world);
    e43_tick(1);
    app(CreatorPremiumRefundService::class)->refund($world['admin'], $bought['purchase']->public_id, 'user_request', 'استرداد لاختبار المخطط', false);
    $keys = DB::table('creator_earning_entries')->whereIn('type', ['direct_sale_credit', 'direct_sale_reversal'])->orderBy('id')->pluck('source_key')->all();

    expect($keys)->toBe(['direct_sale:'.$bought['purchase']->id.':credit', 'direct_sale:'.$bought['purchase']->id.':reversal']);

    $before = DB::table('creator_earning_entries')->count();
    $again = DB::transaction(fn () => e39_ledger()->post(e39_ledger()->lockAccount($world['account']->id), CreatorEarningEntry::TYPE_DIRECT_SALE_CREDIT, 350, 0, 0, $keys[0]));
    $row = (array) DB::table('creator_earning_entries')->where('source_key', $keys[0])->first();
    unset($row['id']);

    expect(DB::table('creator_earning_entries')->count())->toBe($before)->and($again->source_key)->toBe($keys[0])
        ->and(e43db_violates(fn () => DB::table('creator_earning_entries')->insert(array_merge($row, ['public_id' => (string) Str::ulid()]))))->toBeTrue();
});

test('E43-DB-06: foreign keys are RESTRICT - purchases, series, buyers, creators, accounts and ledger entries behind a sale or an access cannot be deleted', function () {
    $world = e43_world();
    $bought = e43_buy($world);
    $sale = DB::table('creator_direct_sales')->where('id', $bought['sale']->id)->first();

    expect(e43db_violates(fn () => DB::table('digital_purchases')->where('id', $bought['purchase']->id)->delete()))->toBeTrue()
        ->and(e43db_violates(fn () => DB::table('creator_series')->where('id', $world['series']->id)->delete()))->toBeTrue()
        ->and(e43db_violates(fn () => DB::table('users')->where('id', $bought['user']->id)->delete()))->toBeTrue()
        ->and(e43db_violates(fn () => DB::table('users')->where('id', $world['user']->id)->delete()))->toBeTrue()
        ->and(e43db_violates(fn () => DB::table('creator_profiles')->where('id', $world['profile']->id)->delete()))->toBeTrue()
        ->and(e43db_violates(fn () => DB::table('creator_earning_accounts')->where('id', $world['account']->id)->delete()))->toBeTrue()
        ->and(e43db_violates(fn () => DB::table('creator_earning_entries')->where('id', $sale->credit_entry_id)->delete()))->toBeTrue()
        ->and(e43db_violates(fn () => DB::table('creator_series_sale_offers')->where('id', $world['offer']->id)->delete()))->toBeTrue()
        ->and(DB::table('creator_series_accesses')->where('user_id', $bought['user']->id)->exists())->toBeTrue()
        ->and(DB::table('creator_direct_sales')->where('id', $sale->id)->exists())->toBeTrue();
});

test('E43-DB-07: Arabic text and emoji round-trip up to the column limits (series title 160, creator name 120, review note 300)', function () {
    $world = e43_world();
    $bought = e43_buy($world);
    $title = str_repeat('ع', 160);
    $name = str_repeat('م', 120);
    $note = str_repeat('نص ', 99).'😀🧩'; // ≈ 299 محرفًا بما فيها رموز تعبيرية رباعية البايت
    $emojiTitle = str_repeat('🧩', 160);

    DB::table('creator_direct_sales')->where('id', $bought['sale']->id)->update(['series_title' => $title, 'creator_display_name' => $name, 'review_note' => $note]);
    $row = DB::table('creator_direct_sales')->where('id', $bought['sale']->id)->first();

    expect($row->series_title)->toBe($title)->and($row->creator_display_name)->toBe($name)->and($row->review_note)->toBe($note);

    DB::table('creator_direct_sales')->where('id', $bought['sale']->id)->update(['series_title' => $emojiTitle]);

    expect(DB::table('creator_direct_sales')->where('id', $bought['sale']->id)->value('series_title'))->toBe($emojiTitle);
});

test('E43-DB-08: big integers are exact end to end and the remainder rule is deterministic (creator + platform = gross for every stored row)', function () {
    $world = e43_world();
    $bought = e43_buy($world);
    $policy = app(CreatorPremiumPolicy::class);

    foreach ([1, 3, 9999, 10001, 123457, 9_000_000_000_000_000_000, PHP_INT_MAX] as $gross) {
        $split = $policy->split($gross);
        DB::table('creator_direct_sales')->where('id', $bought['sale']->id)->update(['gross_minor' => $gross, 'creator_share_minor' => $split['creator_minor'], 'platform_share_minor' => $split['platform_minor']]);
        $row = DB::table('creator_direct_sales')->where('id', $bought['sale']->id)->first();

        expect((string) $row->gross_minor)->toBe((string) $gross)->and((string) $row->creator_share_minor)->toBe((string) $split['creator_minor'])->and((string) $row->platform_share_minor)->toBe((string) $split['platform_minor'])
            ->and(DB::table('creator_direct_sales')->where('id', $bought['sale']->id)->whereRaw('creator_share_minor + platform_share_minor = gross_minor')->exists())->toBeTrue();
    }

    DB::table('creator_series_sale_offers')->where('id', $world['offer']->id)->update(['price_minor' => PHP_INT_MAX]);

    expect((string) DB::table('creator_series_sale_offers')->where('id', $world['offer']->id)->value('price_minor'))->toBe((string) PHP_INT_MAX);
});

test('E43-DB-09: the full buy, play, refund path works against the real column widths (status, reasons, source keys, product type)', function () {
    $world = e43_world();
    $bought = e43_buy($world);
    e30s_solve($bought['user'], $world['works'][0]);
    e43_tick(2);
    app(CreatorPremiumRefundService::class)->refund($world['admin'], $bought['purchase']->public_id, 'content_issue', 'استرداد بعد اللعب لاختبار أعراض الأعمدة', true);

    $sale = DB::table('creator_direct_sales')->where('id', $bought['sale']->id)->first();
    $access = DB::table('creator_series_accesses')->where('user_id', $bought['user']->id)->first();

    expect($sale->status)->toBe('refunded')->and($access->revoke_reason)->toBe('refund')->and(DB::table('digital_purchases')->where('id', $bought['purchase']->id)->value('product_type'))->toBe('creator_series_access')
        ->and(DB::table('creator_earning_entries')->where('type', 'direct_sale_reversal')->value('available_delta_minor'))->toBe(-350);

    // حالة المراجعة المالية (أطول قيمة حالة) تُخزَّن كاملة.
    $second = e43_buy($world);
    e43_drain($world, '1.00');
    e43_tick(2);
    app(CreatorPremiumRefundService::class)->refund($world['admin'], $second['purchase']->public_id, 'user_request', 'استرداد غير قابل للعكس لاختبار الأعمدة', false);

    expect(DB::table('creator_direct_sales')->where('id', $second['sale']->id)->value('status'))->toBe('needs_finance_review')
        ->and(DB::table('creator_direct_sales')->where('id', $second['sale']->id)->value('review_reason'))->toBe('reversal_blocked');
});

test('E43-DB-10: named indexes exist and no float / decimal column was introduced', function () {
    $indexNames = fn (string $table) => collect(Schema::getIndexes($table))->pluck('name')->all();

    expect($indexNames('creator_series_sale_offers'))->toContain('creator_series_sale_offers_public_unique')->toContain('creator_series_sale_offers_live_unique')
        ->toContain('creator_series_sale_offers_series_status_index')->toContain('creator_series_sale_offers_creator_status_index')
        ->and($indexNames('creator_series_accesses'))->toContain('creator_series_accesses_public_unique')->toContain('creator_series_accesses_owner_unique')
        ->toContain('creator_series_accesses_purchase_unique')->toContain('creator_series_accesses_series_revoked_index')
        ->and($indexNames('creator_direct_sales'))->toContain('creator_direct_sales_public_unique')->toContain('creator_direct_sales_purchase_unique')->toContain('creator_direct_sales_credit_unique')
        ->toContain('creator_direct_sales_reversal_unique')->toContain('creator_direct_sales_creator_sold_index')->toContain('creator_direct_sales_series_sold_index')->toContain('creator_direct_sales_status_sold_index');

    foreach (['creator_series_sale_offers', 'creator_series_accesses', 'creator_direct_sales'] as $table) {
        foreach (Schema::getColumns($table) as $column) {
            expect($column['type_name'])->not->toContain('float')->not->toContain('double')->not->toContain('decimal', $table.'.'.$column['name']);
        }
    }
});
