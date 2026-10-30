<?php

use App\Models\StoreItem;
use App\Services\SeasonalRecognition\SeasonSupporterLeaderboardService;
use App\Services\SeasonalRecognition\SeasonWriterReconciler;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

require_once __DIR__.'/_support.php';

/*
|--------------------------------------------------------------------------
| E44 - حدود دوري الكتّاب وتقدير الداعمين في القاعدة نفسها. سائق-محايد: SQLite افتراضيًا وMySQL 8 عبر phpunit.mysql.xml.dist (خطوة CI المخصصة).
| تثبت: فرادة إعدادات الموسم وعضوية الكاتب (موسم/ملف، مستخدم/موسم) وربط السلاسل، ميزانية ناخب واحدة وتوزيع واحد لكل (كاتب، ناخب) مع تعايش NULL وتصفير الناخب المحذوف،
| فرادة الجوائز (كاتب، بند)، فرادة الباقات والبطاقات (دفع واحد لبطاقة واحدة)، فرادة مفتاح مصدر القيد وعكس واحد لكل قيد وتعايش NULL، قيد الإحصاء الفريد،
| سياسة المفاتيح الأجنبية (RESTRICT للتاريخ؛ SET NULL لمراجع الفاعلين؛ CASCADE لصف إعدادات الموسم وحده)، النص العربي والرموز التعبيرية حتى حدود الأعمدة،
| دقّة الأعداد الصحيحة الموقَّعة وغير الموقَّعة، ترتيب لوحة الداعمين بالفهرس نفسه (NULL أخيرًا)، الفهارس المسماة، ولا float/decimal،
| ثم مسار كامل للدوري ولنقاط الدعم على المحرك الحقيقي. السباق الحقيقي بين عمليات PHP في tools/e44/concurrency-probe.php.
|--------------------------------------------------------------------------
*/

const E44_DB_TABLES = [
    'season_recognition_settings', 'season_writer_entries', 'season_writer_series', 'season_writer_vote_budgets', 'season_writer_star_allocations', 'season_writer_awards',
    'season_supporter_packages', 'season_supporter_cards', 'season_support_point_entries', 'season_supporter_stats',
];

/** مراجع الفاعلين وما شابهها: SET NULL، وصف إعدادات الموسم يُحذف مع موسمه. كل ما سواها RESTRICT. */
const E44_DB_NON_RESTRICT = [
    'season_recognition_settings' => ['season_id' => 'cascade', 'previous_season_id' => 'set null', 'writers_finalized_by' => 'set null', 'supporters_finalized_by' => 'set null'],
    'season_writer_entries' => ['added_by' => 'set null'],
    'season_writer_series' => ['linked_by' => 'set null'],
    'season_writer_vote_budgets' => ['user_id' => 'set null'],
    'season_writer_star_allocations' => ['voter_user_id' => 'set null'],
    'season_writer_awards' => ['store_item_id' => 'set null', 'settled_by' => 'set null'],
    'season_supporter_stats' => ['social_updated_by' => 'set null'],
];

beforeEach(fn () => e44_seed());
afterEach(fn () => Carbon::setTestNow());

function e44db_violates(callable $call): bool
{
    try {
        DB::transaction($call);
    } catch (QueryException) {
        return true;
    }

    return false;
}

/** صف خام من جدول بلا معرّفه (للاستنساخ وقياس القيود لا الخدمة). */
function e44db_row(string $table, int $id): array
{
    $row = (array) DB::table($table)->where('id', $id)->first();
    unset($row['id']);

    return $row;
}

/** جائزة خام لكاتب (للتحقق من القيود لا من الخدمة). */
function e44db_award(array $league, array $over = []): array
{
    $writer = $league['writers'][0];

    return $over + [
        'public_id' => (string) Str::ulid(), 'season_id' => $league['season']->id, 'writer_entry_id' => $writer['entry']->id, 'user_id' => $writer['user']->id,
        'plan_index' => 0, 'placement' => 'first_place', 'kind' => 'monetary', 'label' => 'جائزة', 'amount_minor' => 5000, 'currency' => 'USD', 'status' => 'pending_finance',
        'created_at' => now(), 'updated_at' => now(),
    ];
}

// ───────────────────────────── الدوري ─────────────────────────────

test('E44-DB-01: one settings row per season, one writer entry per (season, profile) and per (user, season), and unique public ids', function () {
    $league = e44_league(2, ['open' => false]);
    $season = $league['season'];
    $first = $league['writers'][0];
    $fresh = e28_creator();
    $freshProfile = $fresh->creatorProfile()->firstOrFail();
    $row = e44db_row('season_writer_entries', $first['entry']->id);
    $clone = fn (array $over) => array_merge($row, ['public_id' => (string) Str::ulid()], $over);

    expect(e44db_violates(fn () => DB::table('season_recognition_settings')->insert(['season_id' => $season->id, 'created_at' => now(), 'updated_at' => now()])))->toBeTrue()
        ->and(e44db_violates(fn () => DB::table('season_writer_entries')->insert($clone([]))))->toBeTrue()
        ->and(e44db_violates(fn () => DB::table('season_writer_entries')->insert($clone(['creator_profile_id' => $freshProfile->id]))))->toBeTrue() // المستخدم نفسه في الموسم نفسه
        ->and(e44db_violates(fn () => DB::table('season_writer_entries')->insert($clone(['user_id' => $fresh->id]))))->toBeTrue() // الملف نفسه في الموسم نفسه
        ->and(e44db_violates(fn () => DB::table('season_writer_entries')->insert($clone(['creator_profile_id' => $freshProfile->id, 'user_id' => $fresh->id, 'public_id' => $first['entry']->public_id]))))->toBeTrue()
        ->and(e44db_violates(fn () => DB::table('season_writer_entries')->insert($clone(['creator_profile_id' => $freshProfile->id, 'user_id' => $fresh->id]))))->toBeFalse()
        ->and(DB::table('season_writer_entries')->where('season_id', $season->id)->count())->toBe(3);
});

test('E44-DB-02: a series link is unique per writer and the roster, series, profiles, users and seasons behind it cannot be deleted', function () {
    $league = e44_league(1, ['open' => false]);
    $writer = $league['writers'][0];
    $link = e44db_row('season_writer_series', (int) DB::table('season_writer_series')->where('writer_entry_id', $writer['entry']->id)->value('id'));

    expect(e44db_violates(fn () => DB::table('season_writer_series')->insert($link)))->toBeTrue()
        ->and(e44db_violates(fn () => DB::table('season_writer_series')->insert(array_merge($link, ['creator_series_id' => 987654]))))->toBeTrue()
        ->and(e44db_violates(fn () => DB::table('creator_series')->where('id', $writer['series']->id)->delete()))->toBeTrue()
        ->and(e44db_violates(fn () => DB::table('season_writer_entries')->where('id', $writer['entry']->id)->delete()))->toBeTrue()
        ->and(e44db_violates(fn () => DB::table('creator_profiles')->where('id', $writer['profile']->id)->delete()))->toBeTrue()
        ->and(e44db_violates(fn () => DB::table('users')->where('id', $writer['user']->id)->delete()))->toBeTrue()
        ->and(e44db_violates(fn () => DB::table('seasons')->where('id', $league['season']->id)->delete()))->toBeTrue()
        ->and(DB::table('season_writer_series')->where('writer_entry_id', $writer['entry']->id)->count())->toBe(1);
});

test('E44-DB-03: one budget per (season, voter) and one allocation per (writer, voter); erased voters coexist as NULL and the star totals survive', function () {
    $league = e44_league(2);
    $season = $league['season'];
    [$a, $b] = $league['writers'];
    $voter = e16User();
    $now = now();
    $budget = fn (array $over = []) => $over + ['season_id' => $season->id, 'user_id' => $voter->id, 'total_stars' => 10, 'spent_stars' => 4, 'created_at' => $now, 'updated_at' => $now];
    $alloc = fn (array $over = []) => $over + ['season_id' => $season->id, 'writer_entry_id' => $a['entry']->id, 'voter_user_id' => $voter->id, 'stars' => 4, 'created_at' => $now, 'updated_at' => $now];

    DB::table('season_writer_vote_budgets')->insert($budget());
    DB::table('season_writer_star_allocations')->insert($alloc());

    expect(e44db_violates(fn () => DB::table('season_writer_vote_budgets')->insert($budget())))->toBeTrue()
        ->and(e44db_violates(fn () => DB::table('season_writer_star_allocations')->insert($alloc(['stars' => 2]))))->toBeTrue()
        ->and(e44db_violates(fn () => DB::table('season_writer_star_allocations')->insert($alloc(['writer_entry_id' => 987654]))))->toBeTrue()
        ->and(e44db_violates(fn () => DB::table('season_writer_star_allocations')->insert($alloc(['writer_entry_id' => $b['entry']->id, 'stars' => 3]))))->toBeFalse()
        ->and(e44db_violates(fn () => DB::table('season_writer_vote_budgets')->insert($budget(['user_id' => null]))))->toBeFalse()
        ->and(e44db_violates(fn () => DB::table('season_writer_vote_budgets')->insert($budget(['user_id' => null]))))->toBeFalse()
        ->and(e44db_violates(fn () => DB::table('season_writer_star_allocations')->where('writer_entry_id', $a['entry']->id)->delete()))->toBeFalse() // حذف توزيع لا يمسّ التاريخ الباقي
        ->and(e44db_violates(fn () => DB::table('season_writer_entries')->where('id', $b['entry']->id)->delete()))->toBeTrue(); // وللكاتب توزيع قائم

    // محو حساب الناخب (حق المحو) يصفّر مرجعه ويُبقي النجوم.
    DB::table('season_writer_star_allocations')->insert($alloc());

    expect(e44db_violates(fn () => DB::table('users')->where('id', $voter->id)->delete()))->toBeFalse()
        ->and(DB::table('season_writer_vote_budgets')->where('season_id', $season->id)->whereNull('user_id')->count())->toBe(3)
        ->and((int) DB::table('season_writer_star_allocations')->where('season_id', $season->id)->whereNull('voter_user_id')->sum('stars'))->toBe(7);
});

test('E44-DB-04: awards are unique per (writer, plan item), hold exact minor-unit amounts, and protect the roster and the winner; a removed store item is just forgotten', function () {
    $league = e44_league(1, ['open' => false]);
    $writer = $league['writers'][0];
    $existing = DB::table('season_writer_awards')->insertGetId(e44db_award($league));
    $row = e44db_row('season_writer_awards', $existing);
    $item = StoreItem::factory()->create();

    expect(e44db_violates(fn () => DB::table('season_writer_awards')->insert(e44db_award($league))))->toBeTrue() // البند نفسه للكاتب نفسه
        ->and(e44db_violates(fn () => DB::table('season_writer_awards')->insert(e44db_award($league, ['plan_index' => 1, 'public_id' => $row['public_id']]))))->toBeTrue()
        ->and(e44db_violates(fn () => DB::table('season_writer_awards')->insert(e44db_award($league, ['plan_index' => 1, 'amount_minor' => PHP_INT_MAX]))))->toBeFalse()
        ->and((string) DB::table('season_writer_awards')->where('plan_index', 1)->value('amount_minor'))->toBe((string) PHP_INT_MAX)
        ->and(e44db_violates(fn () => DB::table('season_writer_awards')->insert(e44db_award($league, ['plan_index' => 2, 'kind' => 'cosmetic', 'amount_minor' => null, 'currency' => null, 'store_item_id' => $item->id]))))->toBeFalse()
        ->and(e44db_violates(fn () => DB::table('season_writer_entries')->where('id', $writer['entry']->id)->delete()))->toBeTrue()
        ->and(e44db_violates(fn () => DB::table('users')->where('id', $writer['user']->id)->delete()))->toBeTrue()
        ->and(e44db_violates(fn () => DB::table('store_items')->where('id', $item->id)->delete()))->toBeFalse()
        ->and(DB::table('season_writer_awards')->where('plan_index', 2)->value('store_item_id'))->toBeNull();
});

// ───────────────────────────── الداعمون ─────────────────────────────

test('E44-DB-05: one package per (season, tier), one card per payment, and the package, payment, buyer and season behind a card cannot be deleted', function () {
    $w = e44_supporters_world();
    $user = e16User();
    $card = e44_buy_card($user, $w['packages']['gold']);
    $other = e44_buy_card(e16User(), $w['packages']['silver']);
    $cardRow = e44db_row('season_supporter_cards', $card->id);
    $packageRow = e44db_row('season_supporter_packages', $w['packages']['gold']->id);

    expect(e44db_violates(fn () => DB::table('season_supporter_packages')->insert($packageRow)))->toBeTrue()
        ->and(e44db_violates(fn () => DB::table('season_supporter_cards')->insert(array_merge($cardRow, ['public_id' => (string) Str::ulid()]))))->toBeTrue() // الدفع نفسه
        ->and(e44db_violates(fn () => DB::table('season_supporter_cards')->insert(array_merge($cardRow, ['sponsorship_payment_id' => $other->sponsorship_payment_id, 'public_id' => $card->public_id]))))->toBeTrue()
        ->and(e44db_violates(fn () => DB::table('season_supporter_cards')->insert(array_merge($cardRow, ['sponsorship_payment_id' => 987654, 'public_id' => (string) Str::ulid()]))))->toBeTrue()
        ->and(e44db_violates(fn () => DB::table('season_supporter_packages')->where('id', $card->package_id)->delete()))->toBeTrue()
        ->and(e44db_violates(fn () => DB::table('sponsorship_payments')->where('id', $card->sponsorship_payment_id)->delete()))->toBeTrue()
        ->and(e44db_violates(fn () => DB::table('users')->where('id', $user->id)->delete()))->toBeTrue()
        ->and(e44db_violates(fn () => DB::table('seasons')->where('id', $w['season']->id)->delete()))->toBeTrue()
        ->and(DB::table('season_supporter_cards')->where('season_id', $w['season']->id)->count())->toBe(2);
});

test('E44-DB-06: the points ledger has a unique source key, one reversal per entry, coexisting NULL reversals, a restrictive self reference and exact signed bigints', function () {
    $w = e44_supporters_world();
    $user = e16User();
    $grant = e44_points()->grant($w['season']->id, $user->id, 'supporter_card', 910001, 100, true);
    $reversal = e44_points()->reverse('supporter_card', 910001);
    $grantRow = e44db_row('season_support_point_entries', $grant->id);
    $reversalRow = e44db_row('season_support_point_entries', $reversal->id);

    expect($grant)->not->toBeNull()->and($reversal)->not->toBeNull()
        ->and(e44db_violates(fn () => DB::table('season_support_point_entries')->insert($grantRow)))->toBeTrue() // مفتاح المصدر نفسه
        ->and(e44db_violates(fn () => DB::table('season_support_point_entries')->insert(array_merge($reversalRow, ['source_key' => 'extra:reversal'])))) ->toBeTrue() // عكس ثانٍ للقيد نفسه
        ->and(e44db_violates(fn () => DB::table('season_support_point_entries')->insert(array_merge($reversalRow, ['source_key' => 'ghost:reversal', 'reverses_entry_id' => 987654]))))->toBeTrue()
        ->and(e44db_violates(fn () => DB::table('season_support_point_entries')->insert(array_merge($grantRow, ['source_key' => 'extra:grant:1']))))->toBeFalse() // NULL يتعايش
        ->and(e44db_violates(fn () => DB::table('season_support_point_entries')->insert(array_merge($grantRow, ['source_key' => 'extra:grant:2']))))->toBeFalse()
        ->and(e44db_violates(fn () => DB::table('season_support_point_entries')->where('id', $grant->id)->delete()))->toBeTrue() // له عكس
        ->and(e44db_violates(fn () => DB::table('users')->where('id', $user->id)->delete()))->toBeTrue()
        ->and(e44db_violates(fn () => DB::table('seasons')->where('id', $w['season']->id)->delete()))->toBeTrue();

    foreach ([PHP_INT_MAX, -PHP_INT_MAX, 0] as $value) {
        DB::table('season_support_point_entries')->where('id', $grant->id)->update(['points_delta' => $value]);

        expect((string) DB::table('season_support_point_entries')->where('id', $grant->id)->value('points_delta'))->toBe((string) $value);
    }
});

test('E44-DB-07: one stat row per (season, user), signed totals stay visible to reconciliation, and the owner cannot be deleted', function () {
    $w = e44_supporters_world();
    $user = e16User();
    e44_points()->grant($w['season']->id, $user->id, 'supporter_card', 910002, 250, true);
    $stat = e44_stat($w['season'], $user);
    $row = e44db_row('season_supporter_stats', $stat->id);

    expect(e44db_violates(fn () => DB::table('season_supporter_stats')->insert($row)))->toBeTrue()
        ->and(e44db_violates(fn () => DB::table('season_supporter_stats')->insert(array_merge($row, ['user_id' => 987654]))))->toBeTrue()
        ->and(e44db_violates(fn () => DB::table('users')->where('id', $user->id)->delete()))->toBeTrue()
        ->and(e44db_violates(fn () => DB::table('seasons')->where('id', $w['season']->id)->delete()))->toBeTrue();

    foreach ([-5, PHP_INT_MAX] as $value) {
        DB::table('season_supporter_stats')->where('id', $stat->id)->update(['points_total' => $value, 'public_points_total' => $value, 'final_points' => $value]);
        $stored = DB::table('season_supporter_stats')->where('id', $stat->id)->first();

        expect((string) $stored->points_total)->toBe((string) $value)->and((string) $stored->public_points_total)->toBe((string) $value)->and((string) $stored->final_points)->toBe((string) $value);
    }

    // الصف المكسور عمدًا تراه المطابقة ولا يُخفيه قيد النوع.
    DB::table('season_supporter_stats')->where('id', $stat->id)->update(['points_total' => -5, 'public_points_total' => 0, 'final_points' => null]);

    expect(array_column(e44_supporter_reconciler()->inspect($w['season']), 'code'))->toContain('negative_total');
});

// ───────────────────────────── السياسة والمخطط ─────────────────────────────

test('E44-DB-08: the live database agrees - history is RESTRICT, actor references are SET NULL, and only the settings row cascades with its season', function () {
    foreach (E44_DB_TABLES as $table) {
        $foreignKeys = Schema::getForeignKeys($table);
        expect($foreignKeys)->not->toBeEmpty();

        foreach ($foreignKeys as $fk) {
            $column = $fk['columns'][0];
            $expected = E44_DB_NON_RESTRICT[$table][$column] ?? 'restrict';
            $actual = strtolower((string) $fk['on_delete']);

            expect(in_array($actual, $expected === 'restrict' ? ['restrict', 'no action'] : [$expected], true))->toBeTrue("{$table}.{$column} on_delete={$actual} expected={$expected}");
        }
    }

    $guard = \App\Services\Database\FinancialHistoryGuard::HISTORY_TABLES;

    // الجداول ذات الالتزام المالي أو الصلة بالمستخدم فقط (نقاط الدعم ليست مالًا فلا يحرسها حارس التاريخ المالي).
    foreach (['season_writer_entries', 'season_writer_awards', 'season_supporter_cards'] as $table) {
        expect($guard)->toHaveKey($table)->and($guard[$table][0])->toBe('user_id');
    }

    expect($guard)->not->toHaveKey('season_support_point_entries')->not->toHaveKey('season_supporter_stats');
});

test('E44-DB-09: Arabic text and emoji round-trip up to the column limits (package name 60, label 120, notes 300, reward plan JSON)', function () {
    $league = e44_league(1, ['open' => false]);
    $w = e44_supporters_world();
    $user = e16User();
    e44_points()->grant($w['season']->id, $user->id, 'supporter_card', 910003, 100, true);
    $stat = e44_stat($w['season'], $user);
    $awardId = DB::table('season_writer_awards')->insertGetId(e44db_award($league));

    $arabic60 = str_repeat('ع', 60);
    $emoji60 = str_repeat('🧩', 60);
    $note = str_repeat('نص ', 99).'😀🧩';
    $label = str_repeat('م', 120);
    $emojiLabel = str_repeat('🏆', 120);

    DB::table('season_supporter_packages')->where('id', $w['packages']['silver']->id)->update(['name' => $arabic60]);
    expect(DB::table('season_supporter_packages')->where('id', $w['packages']['silver']->id)->value('name'))->toBe($arabic60);
    DB::table('season_supporter_packages')->where('id', $w['packages']['silver']->id)->update(['name' => $emoji60]);
    expect(DB::table('season_supporter_packages')->where('id', $w['packages']['silver']->id)->value('name'))->toBe($emoji60);

    DB::table('season_supporter_stats')->where('id', $stat->id)->update(['social_note' => $note]);
    DB::table('season_writer_awards')->where('id', $awardId)->update(['label' => $label, 'note' => $note, 'settlement_ref' => $emojiLabel]);
    $award = DB::table('season_writer_awards')->where('id', $awardId)->first();

    expect(DB::table('season_supporter_stats')->where('id', $stat->id)->value('social_note'))->toBe($note)
        ->and($award->label)->toBe($label)->and($award->note)->toBe($note)->and($award->settlement_ref)->toBe($emojiLabel);

    $item = e44_cosmetic();
    e44_enable($league['season'], ['reward_plan' => [['placement' => 'first_place', 'kind' => 'cosmetic', 'label' => 'شارة المركز الأول 🏆', 'store_item_id' => $item->id]]]);
    $plan = e44_settings()->settingFor($league['season'])->writers_reward_plan;

    expect($plan[0]['label'])->toBe('شارة المركز الأول 🏆')->and((int) $plan[0]['store_item_id'])->toBe((int) $item->id);
});

test('E44-DB-10: the store columns default to zero and hold the full unsigned 32-bit range', function () {
    $store = e44_store_item(0, 10);

    expect((int) $store['price']->support_points)->toBe(0);

    DB::table('store_item_prices')->where('id', $store['price']->id)->update(['support_points' => 4294967295]);
    expect((int) DB::table('store_item_prices')->where('id', $store['price']->id)->value('support_points'))->toBe(4294967295);

    DB::table('store_item_prices')->where('id', $store['price']->id)->update(['support_points' => 0]);
    $purchase = e44_buy(e16User(), $store);

    expect((int) DB::table('store_purchases')->where('id', $purchase->id)->value('support_points_snapshot'))->toBe(0);

    DB::table('store_purchases')->where('id', $purchase->id)->update(['support_points_snapshot' => 4294967295]);
    expect((int) DB::table('store_purchases')->where('id', $purchase->id)->value('support_points_snapshot'))->toBe(4294967295);
});

test('E44-DB-11: named indexes exist within the 64-character limit, and no float / decimal column was introduced', function () {
    $expected = [
        'season_recognition_settings' => ['season_recognition_settings_season_unique'],
        'season_writer_entries' => ['season_writer_entries_public_unique', 'season_writer_entries_season_creator_unique', 'season_writer_entries_user_season_unique'],
        'season_writer_series' => ['season_writer_series_pair_unique'],
        'season_writer_vote_budgets' => ['season_writer_budgets_voter_unique'],
        'season_writer_star_allocations' => ['season_writer_alloc_pair_unique', 'season_writer_alloc_voter_index'],
        'season_writer_awards' => ['season_writer_awards_public_unique', 'season_writer_awards_entry_plan_unique', 'season_writer_awards_season_status_index'],
        'season_supporter_packages' => ['season_supporter_packages_tier_unique'],
        'season_supporter_cards' => ['season_supporter_cards_public_unique', 'season_supporter_cards_payment_unique', 'season_supporter_cards_user_season_index', 'season_supporter_cards_season_status_index'],
        'season_support_point_entries' => ['season_support_entries_source_unique', 'season_support_entries_reverses_unique', 'season_support_entries_season_user_index'],
        'season_supporter_stats' => ['season_supporter_stats_user_unique', 'season_supporter_stats_rank_index'],
    ];

    foreach ($expected as $table => $names) {
        $actual = collect(Schema::getIndexes($table))->pluck('name')->all();

        foreach ($names as $name) {
            expect($actual)->toContain($name);
        }

        foreach ($actual as $name) {
            expect(strlen((string) $name))->toBeLessThanOrEqual(64, "{$table}.{$name}");
        }
    }

    foreach (E44_DB_TABLES as $table) {
        foreach (Schema::getColumns($table) as $column) {
            expect($column['type_name'])->not->toContain('float')->not->toContain('double')->not->toContain('decimal');
        }
    }

    foreach ([['store_item_prices', 'support_points'], ['store_purchases', 'support_points_snapshot']] as [$table, $name]) {
        $column = collect(Schema::getColumns($table))->firstWhere('name', $name);

        expect($column)->not->toBeNull()->and($column['type_name'])->toContain('int');
    }
});

test('E44-DB-12: the supporters ranking query agrees with the index - points DESC, earliest first point, then id, with NULL first-point last', function () {
    $w = e44_supporters_world();
    $base = now();
    $names = ['ثالث-وقتًا', 'متأخر', 'مبكر', 'بلا-وقت', 'متصدر'];
    $rows = [[100, $base->copy()->addMinutes(5)], [100, $base->copy()->addMinutes(2)], [100, $base->copy()->addMinute()], [100, null], [200, $base->copy()->addMinutes(9)]];

    foreach ($rows as $i => [$points, $first]) {
        $user = e16User(['name' => $names[$i]]);
        DB::table('season_supporter_stats')->insert([
            'season_id' => $w['season']->id, 'user_id' => $user->id, 'points_total' => $points, 'public_points_total' => $points, 'first_point_at' => $first,
            'created_at' => $base, 'updated_at' => $base,
        ]);
    }

    $order = app(SeasonSupporterLeaderboardService::class)->eligibleQuery((int) $w['season']->id)->limit(10)->get(['s.user_id', 'u.name'])->pluck('name')->all();

    expect($order)->toBe(['متصدر', 'مبكر', 'متأخر', 'ثالث-وقتًا', 'بلا-وقت']);
});

// ───────────────────────────── مسارات كاملة على المحرك الحقيقي ─────────────────────────────

test('E44-DB-13: the full writers path (vote, close, finalize, awards) runs on the real engine and reconciles to zero findings', function () {
    $item = e44_cosmetic();
    $league = e44_league(3, ['reward_plan' => [
        ['placement' => 'first_place', 'kind' => 'cosmetic', 'label' => 'شارة', 'store_item_id' => $item->id],
        ['placement' => 'second_place', 'kind' => 'monetary', 'label' => 'مكافأة', 'amount_minor' => 2500, 'currency' => 'USD'],
    ]]);

    e44_cast($league['writers'], [6, 4, 2]);
    e44_after_close();
    $result = e44_finalizer()->finalize($league['season'], $league['admin']);

    expect($result['finalized'])->toBeTrue()->and($result['writers'])->toBe(3)->and($result['podium'])->toBe(3)
        ->and(DB::table('season_writer_entries')->where('season_id', $league['season']->id)->orderBy('final_rank')->pluck('final_stars')->map(fn ($v) => (int) $v)->all())->toBe([6, 4, 2])
        ->and(DB::table('season_writer_awards')->where('season_id', $league['season']->id)->count())->toBe(2)
        ->and((string) DB::table('season_writer_awards')->where('kind', 'monetary')->value('amount_minor'))->toBe('2500')
        ->and(app(SeasonWriterReconciler::class)->inspect($league['season']))->toBe([]);
});

test('E44-DB-14: the full supporters path (card, store points, refund, finalize) runs on the real engine and reconciles to zero findings', function () {
    $w = e44_supporters_world();
    $buyer = e16User(['name' => 'مشترٍ-حقيقي']);
    $card = e44_buy_card($buyer, $w['packages']['diamond']);
    $store = e44_buy(e16User(), e44_store_item(40, 10));
    $second = e44_buy_card(e16User(), $w['packages']['silver']);
    e44_refund_card($second);

    expect(e44_user_points($w['season'], $buyer))->toBe(2500)
        ->and((int) DB::table('season_support_point_entries')->where('season_id', $w['season']->id)->sum('points_delta'))->toBe(2540)
        ->and(e44_supporter_reconciler()->inspect($w['season']))->toBe([]);

    Carbon::setTestNow(Carbon::parse('2026-12-31 00:00:00'));
    $result = e44_supporter_finalizer()->finalize($w['season'], $w['admin']);

    expect($result['finalized'])->toBeTrue()->and($result['ranked'])->toBe(2)
        ->and((int) e44_stat($w['season'], $buyer)->final_rank)->toBe(1)
        ->and(e44_stat($w['season'], $buyer)->final_card_tier_key)->toBe('diamond')
        ->and($card->fresh()->status)->toBe('active')
        ->and($store->fresh()->support_points_snapshot)->toBe(40)
        ->and(e44_supporter_reconciler()->inspect($w['season']))->toBe([]);
});
