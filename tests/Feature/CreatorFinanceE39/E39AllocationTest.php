<?php

use App\Models\CreatorEarningAccount;
use App\Models\CreatorProfile;
use App\Models\CreatorRevenueAllocation;
use App\Models\User;
use App\Services\Creators\CommunityPlayService;
use App\Services\CreatorFinance\CreatorFinanceException;
use App\Services\CreatorFinance\CreatorMoney;
use App\Services\CreatorFinance\QualifiedUnitsQuery;
use App\Services\CreatorFinance\RevenueAllocator;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/_support.php';

beforeEach(fn () => e39_seed());

const E39_MAX = 2_000_000_000;

function e39_in(array $units): array
{
    $out = [];

    foreach ($units as $id => $u) {
        $out[] = ['creator_profile_id' => $id, 'qualified_units' => $u];
    }

    return $out;
}

// ───────────────────────────── BA4: التوزيع بأكبر باقٍ (دالة صرفة) ─────────────────────────────

test('BA4-01: an even split of an indivisible pool gives the extra unit to the smallest creator id', function () {
    $r = RevenueAllocator::allocate(100, e39_in([3 => 1, 1 => 1, 2 => 1]), E39_MAX);

    expect($r['allocations'][1]['allocated_minor'])->toBe(34)->and($r['allocations'][2]['allocated_minor'])->toBe(33)->and($r['allocations'][3]['allocated_minor'])->toBe(33)
        ->and($r['allocations'][1]['bonus'])->toBe(1)->and($r['allocations'][2]['bonus'])->toBe(0);
});

test('BA4-02: the allocated total equals the pool exactly for 300 pseudo-random scenarios (no loss, no surplus)', function () {
    mt_srand(39);

    for ($i = 0; $i < 300; $i++) {
        $n = mt_rand(1, 25);
        $units = [];

        for ($c = 1; $c <= $n; $c++) {
            $units[$c * 3] = mt_rand(1, 5000);
        }

        $pool = mt_rand(0, 5_000_000);
        $r = RevenueAllocator::allocate($pool, e39_in($units), E39_MAX);

        expect(array_sum(array_column($r['allocations'], 'allocated_minor')))->toBe($pool)->and($r['unallocated_minor'])->toBe(0);
    }
});

test('BA4-03: every share is either the floor or the floor plus one unit of the exact proportional share', function () {
    mt_srand(7);
    $units = [1 => 13, 2 => 7, 3 => 29, 4 => 1, 5 => 50];
    $pool = 12345;
    $total = array_sum($units);
    $r = RevenueAllocator::allocate($pool, e39_in($units), E39_MAX);

    foreach ($units as $id => $u) {
        $floor = intdiv($pool * $u, $total);
        expect($r['allocations'][$id]['allocated_minor'])->toBeIn([$floor, $floor + 1]);
    }
});

test('BA4-04: largest remainder decides who receives the leftover units, ties break by creator id ascending', function () {
    // 10 على 7:2 ← 7.77..:2.22.. ؛ الأكبر باقيًا هو الأول.
    $r = RevenueAllocator::allocate(10, e39_in([5 => 7, 6 => 2]), E39_MAX);
    expect($r['allocations'][5]['allocated_minor'])->toBe(8)->and($r['allocations'][6]['allocated_minor'])->toBe(2);

    // 2 على 1:1:1 ← وحدتان إضافيتان لأصغر معرّفين.
    $r = RevenueAllocator::allocate(2, e39_in([9 => 1, 4 => 1, 7 => 1]), E39_MAX);
    expect($r['allocations'][4]['allocated_minor'])->toBe(1)->and($r['allocations'][7]['allocated_minor'])->toBe(1)->and($r['allocations'][9]['allocated_minor'])->toBe(0);
});

test('BA4-05: the result does not depend on the input order (determinism)', function () {
    $units = [1 => 3, 2 => 3, 3 => 3, 4 => 5, 5 => 1];
    $base = RevenueAllocator::allocate(1000, e39_in($units), E39_MAX);

    for ($i = 0; $i < 10; $i++) {
        $shuffled = $units;
        $keys = array_keys($shuffled);
        shuffle($keys);
        $input = [];

        foreach ($keys as $k) {
            $input[] = ['creator_profile_id' => $k, 'qualified_units' => $units[$k]];
        }

        expect(RevenueAllocator::allocate(1000, $input, E39_MAX))->toBe($base);
    }
});

test('BA4-06: the same input twice gives identical output', function () {
    $in = e39_in([1 => 17, 2 => 31, 3 => 5]);

    expect(RevenueAllocator::allocate(999, $in, E39_MAX))->toBe(RevenueAllocator::allocate(999, $in, E39_MAX));
});

test('BA4-07: no eligible creators leaves the whole pool unallocated', function () {
    $r = RevenueAllocator::allocate(5000, [], E39_MAX);

    expect($r['allocations'])->toBe([])->and($r['allocated_minor'])->toBe(0)->and($r['unallocated_minor'])->toBe(5000)->and($r['total_units'])->toBe(0);
});

test('BA4-08: zero-unit rows are ignored and a single creator receives everything', function () {
    $r = RevenueAllocator::allocate(777, e39_in([1 => 0, 2 => 4]), E39_MAX);

    expect(array_keys($r['allocations']))->toBe([2])->and($r['allocations'][2]['allocated_minor'])->toBe(777)->and($r['allocations'][2]['bonus'])->toBe(0);
});

test('BA4-09: a zero pool allocates zero to everyone and leaves nothing unallocated', function () {
    $r = RevenueAllocator::allocate(0, e39_in([1 => 3, 2 => 4]), E39_MAX);

    expect($r['allocations'][1]['allocated_minor'])->toBe(0)->and($r['allocations'][2]['allocated_minor'])->toBe(0)->and($r['unallocated_minor'])->toBe(0);
});

test('BA4-10: very large pools and unit totals never overflow 64-bit integers', function () {
    $r = RevenueAllocator::allocate(99_999_999_999, e39_in([5 => 1_999_999_999, 6 => 1]), E39_MAX);

    expect($r['allocations'][5]['allocated_minor'] + $r['allocations'][6]['allocated_minor'])->toBe(99_999_999_999)
        ->and($r['allocations'][5]['allocated_minor'])->toBeInt()->and($r['allocations'][6]['allocated_minor'])->toBeInt();
});

test('BA4-11: totals above the safe unit ceiling and negative pools are refused', function () {
    expect(fn () => RevenueAllocator::allocate(100, e39_in([1 => 2_000_000_001]), E39_MAX))->toThrow(CreatorFinanceException::class)
        ->and(fn () => RevenueAllocator::allocate(-1, e39_in([1 => 1]), E39_MAX))->toThrow(CreatorFinanceException::class);
});

test('BA4-12: mulDiv rejects impossible arguments instead of returning nonsense', function () {
    expect(fn () => CreatorMoney::mulDiv(10, 5, 4))->toThrow(CreatorFinanceException::class)
        ->and(fn () => CreatorMoney::mulDiv(10, 1, 0))->toThrow(CreatorFinanceException::class)
        ->and(CreatorMoney::mulDiv(100, 1, 3))->toBe([33, 1]);
});

test('BA4-13: only integers are used - the allocator returns int shares and the schema has no float column', function () {
    $r = RevenueAllocator::allocate(1001, e39_in([1 => 1, 2 => 2]), E39_MAX);

    foreach ($r['allocations'] as $a) {
        expect($a['allocated_minor'])->toBeInt();
    }

    foreach (['creator_revenue_periods', 'creator_revenue_allocations', 'creator_earning_accounts', 'creator_earning_entries', 'creator_payouts'] as $table) {
        foreach (DB::getSchemaBuilder()->getColumns($table) as $column) {
            expect($column['type_name'])->not->toBeIn(['float', 'double', 'decimal', 'numeric', 'real']);
        }
    }
});

// ───────────────────────────── BA3: الوحدات المؤهَّلة ─────────────────────────────

test('BA3-01: a correct answer counts as one unit per (player, work) however many correct rows exist', function () {
    [$creator, $profile] = e39_creator();
    $work = e39_work($creator);
    $player = e16User();
    e39_attempt($player, $work, true, '2026-11-02 10:00:00', 1);
    e39_attempt($player, $work, true, '2026-11-03 10:00:00', 2);

    $rows = app(QualifiedUnitsQuery::class)->perCreator(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-10'), 3);

    expect($rows)->toHaveCount(1)->and($rows[0]['raw_units'])->toBe(1)->and($rows[0]['qualified_units'])->toBe(1)->and($rows[0]['unique_players'])->toBe(1);
});

test('BA3-02: wrong answers never count', function () {
    [$creator] = e39_creator();
    $work = e39_work($creator);
    e39_attempt(e16User(), $work, false);

    expect(app(QualifiedUnitsQuery::class)->perCreator(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-10'), 3))->toBe([]);
});

test('BA3-03: the period is half open - the start instant counts and the end instant does not', function () {
    [$creator] = e39_creator();
    $work = e39_work($creator);
    $a = e16User();
    $b = e16User();
    $c = e16User();
    e39_attempt($a, $work, true, '2026-11-01 00:00:00');
    e39_attempt($b, $work, true, '2026-11-10 00:00:00');
    e39_attempt($c, $work, true, '2026-10-31 23:59:59');

    $rows = app(QualifiedUnitsQuery::class)->perCreator(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-10'), 3);

    expect($rows[0]['qualified_units'])->toBe(1)->and($rows[0]['unique_players'])->toBe(1);
});

test('BA3-04: the creator playing their own work is excluded', function () {
    [$creator] = e39_creator();
    $work = e39_work($creator);
    e39_attempt($creator, $work, true);

    expect(app(QualifiedUnitsQuery::class)->perCreator(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-10'), 3))->toBe([]);
});

test('BA3-05: frozen players are excluded', function () {
    [$creator] = e39_creator();
    $work = e39_work($creator);
    $frozen = e16User();
    $frozen->forceFill(['is_frozen' => true])->save();
    e39_attempt($frozen, $work, true);
    e39_attempt(e16User(), $work, true);

    $rows = app(QualifiedUnitsQuery::class)->perCreator(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-10'), 3);

    expect($rows[0]['unique_players'])->toBe(1);
});

test('BA3-06: works hidden by moderation or by suspension earn nothing, archived works still count', function () {
    [$creator] = e39_creator();
    $visible = e39_work($creator);
    $hidden = e39_work($creator);
    $archived = e39_work($creator);
    $hidden->forceFill(['hidden_at' => now(), 'hidden_reason' => 'moderation'])->save();
    $archived->forceFill(['archived_at' => now()])->save();
    $player = e16User();

    foreach ([$visible, $hidden, $archived] as $w) {
        e39_attempt($player, $w, true);
    }

    $rows = app(QualifiedUnitsQuery::class)->perCreator(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-10'), 9);

    expect($rows[0]['qualified_units'])->toBe(2);
});

test('BA3-07: the per player cap trims one player to the cap but keeps the raw count for transparency', function () {
    [$creator] = e39_creator();
    $works = [e39_work($creator), e39_work($creator), e39_work($creator), e39_work($creator), e39_work($creator)];
    $player = e16User();

    foreach ($works as $w) {
        e39_attempt($player, $w, true);
    }

    $rows = app(QualifiedUnitsQuery::class)->perCreator(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-10'), 3);

    expect($rows[0]['raw_units'])->toBe(5)->and($rows[0]['qualified_units'])->toBe(3)->and($rows[0]['unique_players'])->toBe(1);
});

test('BA3-08: the cap applies per player independently and many players add up', function () {
    [$creator] = e39_creator();
    $works = [e39_work($creator), e39_work($creator), e39_work($creator), e39_work($creator)];
    e39_solve($works, 3);

    $rows = app(QualifiedUnitsQuery::class)->perCreator(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-10'), 2);

    expect($rows[0]['raw_units'])->toBe(12)->and($rows[0]['qualified_units'])->toBe(6)->and($rows[0]['unique_players'])->toBe(3);
});

test('BA3-09: units of different creators are never mixed', function () {
    [$c1] = e39_creator();
    [$c2] = e39_creator();
    e39_solve([e39_work($c1)], 2);
    e39_solve([e39_work($c2)], 5);

    $rows = collect(app(QualifiedUnitsQuery::class)->perCreator(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-10'), 3))->pluck('qualified_units')->sort()->values()->all();

    expect($rows)->toBe([2, 5]);
});

test('BA3-10: the query is a single aggregate statement regardless of the number of attempts', function () {
    [$creator] = e39_creator();
    e39_solve([e39_work($creator), e39_work($creator)], 15);
    DB::enableQueryLog();
    DB::flushQueryLog();
    app(QualifiedUnitsQuery::class)->perCreator(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-10'), 3);
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($count)->toBe(1);
});

test('BA3-11: a real solve through the official play service becomes a countable unit', function () {
    [$creator] = e39_creator();
    $work = e39_work($creator);
    $player = e16User();
    Carbon::setTestNow(Carbon::parse('2026-11-05 10:00:00'));
    $result = app(CommunityPlayService::class)->attempt($player, $work, 'القلم');
    Carbon::setTestNow(Carbon::parse('2026-11-20 12:00:00'));

    $rows = app(QualifiedUnitsQuery::class)->perCreator(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-10'), 3);

    expect($result['correct'])->toBeTrue()->and($rows[0]['qualified_units'])->toBe(1);
});

test('BA3-12: the period preview classifies every excluded creator with a clear reason', function () {
    e39_loose_policy(2, 2, 3);
    $admin = e39_admin();
    [$ok, $okProfile] = e39_creator($admin);
    [$notEnrolled] = e39_creator($admin, false);
    [$paused, $pausedProfile, $pausedAccount] = e39_creator($admin);
    [$few, $fewProfile] = e39_creator($admin);
    [$suspended, $suspendedProfile] = e39_creator($admin);
    [$frozen] = e39_creator($admin);

    $workOk = e39_work($ok);
    e39_solve([$workOk], 3);
    e39_solve([e39_work($notEnrolled)], 3);
    e39_solve([e39_work($paused)], 3);
    e39_solve([e39_work($few)], 1);
    e39_solve([e39_work($suspended)], 3);
    e39_solve([e39_work($frozen)], 3);

    e39_monetization()->pause($pausedAccount, $admin, 'إيقاف تجريبي للاختبار');
    $suspendedProfile->forceFill(['status' => CreatorProfile::STATUS_SUSPENDED])->save();
    $frozen->forceFill(['is_frozen' => true])->save();

    $period = e39_period($admin);
    $preview = e39_periods()->preview($period);
    $byProfile = collect($preview['rows'])->keyBy('creator_profile_id');

    expect($byProfile[$okProfile->id]['eligible'])->toBeTrue()
        ->and($byProfile[$notEnrolled->creatorProfile->id]['reason'])->toBe('not_enrolled')
        ->and($byProfile[$pausedProfile->id]['reason'])->toBe('paused')
        ->and($byProfile[$fewProfile->id]['reason'])->toBeIn(['below_min_units', 'below_min_players'])
        ->and($byProfile[$suspendedProfile->id]['reason'])->toBe('profile_not_approved')
        ->and($byProfile[$frozen->creatorProfile->id]['reason'])->toBe('creator_frozen')
        ->and($preview['summary']['eligible_creators'])->toBe(1);
});

test('BA3-13: followers, ratings and reputation never change the allocation (no popularity input)', function () {
    e39_loose_policy();
    $admin = e39_admin();
    [$c1, $p1] = e39_creator($admin);
    [$c2, $p2] = e39_creator($admin);
    e39_solve([e39_work($c1)], 3);
    e39_solve([e39_work($c2)], 3);
    $period = e39_period($admin);
    $before = collect(e39_periods()->preview($period)['rows'])->pluck('allocated_minor', 'creator_profile_id')->all();

    foreach (range(1, 6) as $i) {
        DB::table('creator_follows')->insert(['user_id' => e16User()->id, 'creator_profile_id' => $p1->id, 'created_at' => now()]);
    }

    $after = collect(e39_periods()->preview($period)['rows'])->pluck('allocated_minor', 'creator_profile_id')->all();

    expect($after)->toBe($before)->and($before[$p1->id])->toBe($before[$p2->id]);
});
