<?php

use App\Models\CreatorRevenueAllocation;
use App\Services\CreatorFinance\CreatorFinanceAnalytics;
use App\Services\CreatorFinance\QualifiedUnitsQuery;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

require_once __DIR__.'/_support.php';

/*
| E39 (BA17) - حدود مالية المبدعين في القاعدة نفسها. سائق-محايدة: SQLite افتراضيًا وMySQL 8 عبر phpunit.mysql.xml.dist (مهمة mysql-parity في CI).
| تثبت أن «تخصيص واحد لكل (فترة، مبدع)»، «حساب واحد لكل مبدع»، «قيد واحد لكل source_key»، «طلب مفتوح واحد لكل حساب»، «مرجع صرف واحد عالميًا» قيود قاعدة بيانات تتعايش فيها NULL،
| وسياسة المفاتيح الأجنبية (التاريخ المالي لا يُحذف تتابعيًا)، والنص العربي حتى حدود الأعمدة، ودقة الأعداد الصحيحة الكبيرة (SUM/INT)، وحتمية توزيع الكسور، والفترة نصف المفتوحة،
| والفهارس المسماة لا تعتمد على كود التطبيق وحده.
*/

beforeEach(fn () => e39_seed());

function e39_violates(callable $call): bool
{
    try {
        DB::transaction($call);
    } catch (QueryException) {
        return true;
    }

    return false;
}

test('BA17-01: one allocation per period and creator, one account per creator and per user, one entry per source key, one open payout per account and one external reference overall are database constraints', function () {
    [$creator, $profile, $account, $period] = e39_funded_creator(3);
    $payout = e39_request($creator, '100.00');
    $allocation = CreatorRevenueAllocation::query()->firstOrFail();

    $dupAllocation = function () use ($allocation) {
        $row = (array) DB::table('creator_revenue_allocations')->where('id', $allocation->id)->first();
        unset($row['id']);
        $row['public_id'] = (string) Str::ulid();
        DB::table('creator_revenue_allocations')->insert($row);
    };
    $dupAccount = function () use ($account) {
        $row = (array) DB::table('creator_earning_accounts')->where('id', $account->id)->first();
        unset($row['id']);
        $row['public_id'] = (string) Str::ulid();
        DB::table('creator_earning_accounts')->insert($row);
    };
    $dupEntry = function () use ($account) {
        $row = (array) DB::table('creator_earning_entries')->where('creator_earning_account_id', $account->id)->orderBy('id')->first();
        unset($row['id']);
        $row['public_id'] = (string) Str::ulid();
        DB::table('creator_earning_entries')->insert($row);
    };
    $dupOpen = function () use ($payout) {
        $row = (array) DB::table('creator_payouts')->where('id', $payout->id)->first();
        unset($row['id']);
        $row['public_id'] = (string) Str::ulid();
        DB::table('creator_payouts')->insert($row);
    };

    expect(e39_violates($dupAllocation))->toBeTrue()->and(e39_violates($dupAccount))->toBeTrue()->and(e39_violates($dupEntry))->toBeTrue()->and(e39_violates($dupOpen))->toBeTrue()
        ->and(DB::table('creator_revenue_allocations')->count())->toBe(1)->and(DB::table('creator_earning_accounts')->count())->toBe(1)->and(DB::table('creator_payouts')->count())->toBe(1);
});

test('BA17-02: NULL open markers and NULL references coexist while real duplicates collide', function () {
    [$creator, , $account] = e39_funded_creator(3);
    $admin = e39_admin();
    $refs = [];

    foreach (['REF-U-1001', 'REF-U-1002'] as $ref) {
        $payout = e39_request($creator, '30.00');
        e39_payouts()->approve($payout, $admin);
        e39_payouts()->settle($payout->refresh(), $admin, $ref);
        $refs[] = $payout->id;
    }

    $cancelled = e39_request($creator, '30.00');
    e39_payouts()->cancel($creator, $cancelled);
    $rejected = e39_request($creator, '30.00');
    e39_payouts()->reject($rejected, $admin, 'رفض لأسباب تشغيلية معتمدة');

    expect(DB::table('creator_payouts')->whereNull('open_account_id')->count())->toBe(4)->and(DB::table('creator_payouts')->whereNull('external_reference')->count())->toBe(2)
        ->and(e39_violates(fn () => DB::table('creator_payouts')->where('id', $cancelled->id)->update(['external_reference' => 'REF-U-1001'])))->toBeTrue()
        ->and(e39_violates(fn () => DB::table('creator_payouts')->where('id', $cancelled->id)->update(['open_account_id' => $account->id])))->toBeFalse();
});

test('BA17-03: the financial history is never deleted by cascade - periods, allocations, accounts, entries, payouts, profiles and users are RESTRICTed', function () {
    [$creator, $profile, $account, $period] = e39_funded_creator(3);
    $payout = e39_request($creator, '100.00');
    $allocation = DB::table('creator_revenue_allocations')->first();
    $before = e39_counts();

    expect(e39_violates(fn () => DB::table('creator_revenue_periods')->where('id', $period->id)->delete()))->toBeTrue()
        ->and(e39_violates(fn () => DB::table('creator_revenue_allocations')->where('id', $allocation->id)->delete()))->toBeTrue()
        ->and(e39_violates(fn () => DB::table('creator_earning_accounts')->where('id', $account->id)->delete()))->toBeTrue()
        ->and(e39_violates(fn () => DB::table('creator_payouts')->where('id', $payout->id)->delete()))->toBeTrue()
        ->and(e39_violates(fn () => DB::table('creator_profiles')->where('id', $profile->id)->delete()))->toBeTrue()
        ->and(e39_violates(fn () => DB::table('users')->where('id', $creator->id)->delete()))->toBeTrue()
        ->and(e39_counts())->toBe($before);
});

test('BA17-04: deleting a staff account keeps the record and only clears the staff references', function () {
    $admin = e39_admin();
    [$creator, , $account] = e39_creator($admin);
    e39_loose_policy();
    e39_solve([e39_work($creator)], 3);
    $period = e39_finalize(e39_review($admin), $admin);
    $payout = e39_request($creator, '100.00');
    $approver = e39_admin();
    e39_payouts()->approve($payout, $approver);
    $before = e39_counts();

    DB::table('users')->whereIn('id', [$admin->id, $approver->id])->delete();

    expect(e39_counts())->toBe($before)->and(DB::table('creator_revenue_periods')->value('finalized_by'))->toBeNull()->and(DB::table('creator_revenue_periods')->value('created_by'))->toBeNull()
        ->and(DB::table('creator_payouts')->value('approved_by'))->toBeNull()->and(DB::table('creator_earning_accounts')->value('monetization_changed_by'))->toBeNull()
        ->and(DB::table('creator_earning_entries')->whereNull('actor_id')->count())->toBeGreaterThanOrEqual(1);
});

test('BA17-05: Arabic text survives a database round trip up to the exact column limits', function () {
    [$creator, , $account, $period] = e39_funded_creator(3);
    $label = str_repeat('ع', 120);
    $note500 = str_repeat('ن', 500);
    $message = str_repeat('ر', 300);
    $reference = str_repeat('م', 120);

    DB::table('creator_revenue_periods')->where('id', $period->id)->update(['label' => $label, 'note' => $note500]);
    $payout = e39_request($creator, '30.00');
    DB::table('creator_earning_accounts')->where('id', $account->id)->update(['hold_reason_code' => 'investigation', 'hold_note' => $note500]);
    DB::table('creator_payouts')->where('id', $payout->id)->update(['decision_message' => $message, 'external_reference' => $reference, 'review_note' => $note500]);
    DB::table('creator_earning_entries')->where('creator_earning_account_id', $account->id)->update(['note' => $note500]);

    expect(DB::table('creator_revenue_periods')->value('label'))->toBe($label)->and(mb_strlen((string) DB::table('creator_revenue_periods')->value('note')))->toBe(500)
        ->and(DB::table('creator_earning_accounts')->value('hold_note'))->toBe($note500)->and(DB::table('creator_payouts')->value('decision_message'))->toBe($message)
        ->and(DB::table('creator_payouts')->value('external_reference'))->toBe($reference)->and(DB::table('creator_earning_entries')->value('note'))->toBe($note500);
});

test('BA17-06: big integers stay exact in columns, SUM aggregates and the analytics overview', function () {
    [$creator, , $account] = e39_funded_creator(3);
    $big = 4_000_000_000_000;

    foreach ([1, 2] as $i) {
        DB::table('creator_earning_entries')->insert([
            'public_id' => (string) Str::ulid(), 'creator_earning_account_id' => $account->id, 'creator_profile_id' => $account->creator_profile_id, 'user_id' => $account->user_id,
            'type' => 'adjustment_credit', 'available_delta_minor' => $big, 'reserved_delta_minor' => 0, 'paid_delta_minor' => 0, 'currency' => 'USD', 'source_key' => 'big:'.$i, 'created_at' => now(),
        ]);
    }

    $balances = e39_ledger()->ledgerBalances((int) $account->id);
    DB::table('creator_earning_accounts')->where('id', $account->id)->update(['available_minor' => $balances['available'], 'paid_lifetime_minor' => 3_000_000_000_001]);
    $overview = app(CreatorFinanceAnalytics::class)->overview();

    expect($balances['available'])->toBe(100000 + 2 * $big)->and($overview['liability']['available_minor'])->toBe(100000 + 2 * $big)->and($overview['liability']['paid_lifetime_minor'])->toBe(3_000_000_000_001)
        ->and(DB::table('creator_earning_accounts')->value('available_minor'))->toBe(100000 + 2 * $big)->and(DB::table('creator_earning_entries')->where('source_key', 'big:2')->value('available_delta_minor'))->toBe($big);
});

test('BA17-07: the leftover cent goes to the lowest creator ids in the same order on every driver', function () {
    e39_loose_policy();
    $admin = e39_admin();
    $creators = [];

    for ($i = 0; $i < 3; $i++) {
        [$user, $profile] = e39_creator($admin);
        e39_solve([e39_work($user)], 1);
        $creators[] = $profile->id;
    }

    $period = e39_finalize(e39_review($admin, ['pool' => '1000.01']), $admin);
    $rows = DB::table('creator_revenue_allocations')->where('creator_revenue_period_id', $period->id)->orderBy('creator_profile_id')->pluck('allocated_minor', 'creator_profile_id')->all();

    expect(array_values($rows))->toBe([33334, 33334, 33333])->and(array_keys($rows))->toBe($creators)->and(array_sum($rows))->toBe(100001)->and((int) $period->refresh()->unallocated_minor)->toBe(0);
});

test('BA17-08: the period is half-open - an attempt exactly at the start counts and one exactly at the end does not', function () {
    $admin = e39_admin();
    [$creator, $profile] = e39_creator($admin);
    $work = e39_work($creator);

    foreach ([
        '2026-10-31 23:59:59' => false, '2026-11-01 00:00:00' => true, '2026-11-09 23:59:59' => true, '2026-11-10 00:00:00' => false,
    ] as $at => $counts) {
        e39_attempt(e16User(), $work, true, $at);
    }

    $rows = (new QualifiedUnitsQuery)->perCreator(Carbon::parse('2026-11-01 00:00:00'), Carbon::parse('2026-11-10 00:00:00'), 3);

    expect($rows)->toHaveCount(1)->and($rows[0]['qualified_units'])->toBe(2)->and($rows[0]['unique_players'])->toBe(2)->and($rows[0]['creator_profile_id'])->toBe($profile->id);
});

test('BA17-09: the named indexes the queues and statements rely on exist', function () {
    $expected = [
        'creator_revenue_periods' => ['creator_revenue_periods_public_unique', 'creator_revenue_periods_status_start_index', 'creator_revenue_periods_range_index'],
        'creator_revenue_allocations' => ['creator_revenue_alloc_period_creator_unique', 'creator_revenue_alloc_creator_period_index', 'creator_revenue_alloc_user_index'],
        'creator_earning_accounts' => ['creator_earning_accounts_profile_unique', 'creator_earning_accounts_user_unique', 'creator_earning_accounts_status_index'],
        'creator_payouts' => ['creator_payouts_open_account_unique', 'creator_payouts_reference_unique', 'creator_payouts_status_requested_index', 'creator_payouts_account_status_index'],
        'creator_earning_entries' => ['creator_earning_entries_source_unique', 'creator_earning_entries_account_id_index', 'creator_earning_entries_type_created_index'],
    ];

    foreach ($expected as $table => $names) {
        $present = collect(Schema::getIndexes($table))->pluck('name')->map(fn ($n) => strtolower((string) $n))->all();

        foreach ($names as $name) {
            expect(in_array(strtolower($name), $present, true))->toBeTrue("{$table} is missing {$name}");
        }
    }
});

test('BA17-10: every money and unit column is an integer type - no float, double or decimal anywhere in the E39 tables', function () {
    foreach (['creator_revenue_periods', 'creator_revenue_allocations', 'creator_earning_accounts', 'creator_earning_entries', 'creator_payouts'] as $table) {
        foreach (Schema::getColumns($table) as $column) {
            $type = strtolower((string) $column['type_name']);

            expect($type)->not->toContain('float')->not->toContain('double')->not->toContain('decimal')->not->toContain('numeric')->not->toContain('real');

            if (str_ends_with((string) $column['name'], '_minor') || str_contains((string) $column['name'], '_units') || str_ends_with((string) $column['name'], '_delta_minor')) {
                expect(str_contains($type, 'int'))->toBeTrue("{$table}.{$column['name']} must be an integer type");
            }
        }
    }
});

test('BA17-11: the account lock inside a transaction returns the committed row and a failed insert leaves the connection usable', function () {
    [, , $account] = e39_funded_creator(3);

    $seen = DB::transaction(fn () => e39_ledger()->lockAccount((int) $account->id)->available_minor);
    $failed = e39_violates(fn () => DB::table('creator_earning_entries')->insert([
        'public_id' => (string) Str::ulid(), 'creator_earning_account_id' => $account->id, 'creator_profile_id' => $account->creator_profile_id, 'user_id' => $account->user_id,
        'type' => 'adjustment_credit', 'available_delta_minor' => 1, 'reserved_delta_minor' => 0, 'paid_delta_minor' => 0, 'currency' => 'USD', 'source_key' => DB::table('creator_earning_entries')->value('source_key'), 'created_at' => now(),
    ]));

    expect($seen)->toBe(100000)->and($failed)->toBeTrue()->and(DB::table('creator_earning_entries')->count())->toBe(1)->and(e39_ledger()->adjust($account->refresh(), e39_admin(), 'credit', '1.00', 'goodwill', 'تعويض بعد فشل إدراج سابق')->available_delta_minor)->toBe(100);
});
