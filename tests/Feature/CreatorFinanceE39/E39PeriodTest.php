<?php

use App\Models\CreatorEarningEntry;
use App\Models\CreatorRevenueAllocation;
use App\Models\CreatorRevenuePeriod;
use App\Models\OperationalAuditLog;
use App\Services\CreatorFinance\CreatorFinanceException;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/_support.php';

beforeEach(fn () => e39_seed());

// ───────────────────────────── BA2: إنشاء وتحقق الفترة ─────────────────────────────

test('BA2-01: a valid period is created as a draft in the single accounting currency', function () {
    $period = e39_period();

    expect($period->status)->toBe('draft')->and($period->currency)->toBe('USD')->and($period->pool_minor)->toBe(100000)->and($period->public_id)->toHaveLength(26)
        ->and($period->created_by)->not->toBeNull();
});

test('BA2-02: the label is required, bounded and free of control characters', function () {
    foreach (['', '   ', str_repeat('ا', 121), "عنوان\x00خفي"] as $label) {
        expect(fn () => e39_period(null, ['label' => $label]))->toThrow(CreatorFinanceException::class);
    }

    expect(e39_period(null, ['label' => str_repeat('ا', 120)])->label)->toHaveLength(120);
});

test('BA2-03: the end must follow the start and the length must stay within the configured bounds', function () {
    expect(fn () => e39_period(null, ['starts_at' => '2026-11-10', 'ends_at' => '2026-11-01']))->toThrow(CreatorFinanceException::class)
        ->and(fn () => e39_period(null, ['starts_at' => '2026-11-01 00:00:00', 'ends_at' => '2026-11-01 10:00:00']))->toThrow(CreatorFinanceException::class)
        ->and(fn () => e39_period(null, ['starts_at' => '2026-01-01', 'ends_at' => '2026-06-01']))->toThrow(CreatorFinanceException::class)
        ->and(fn () => e39_period(null, ['starts_at' => 'غير تاريخ', 'ends_at' => '2026-06-01']))->toThrow(CreatorFinanceException::class);
});

test('BA2-04: the pool is parsed strictly - ambiguous or malformed amounts are refused', function () {
    foreach (['', 'abc', '1,000', '-5', '1e3', '0', '0.00', '12.345', ' 1 2 ', '+5'] as $pool) {
        expect(fn () => e39_period(null, ['pool' => $pool]))->toThrow(CreatorFinanceException::class);
    }

    expect(e39_period(null, ['pool' => '12.5'])->pool_minor)->toBe(1250);
});

test('BA2-05: Arabic-Indic digits and the Arabic decimal separator are accepted for the pool', function () {
    expect(e39_period(null, ['pool' => '١٢٫٥٠'])->pool_minor)->toBe(1250);
});

test('BA2-06: a pool above the configured ceiling is refused', function () {
    config(['creator_monetization.periods.max_pool_minor' => 500000]);

    expect(fn () => e39_period(null, ['pool' => '5000.01']))->toThrow(CreatorFinanceException::class)
        ->and(e39_period(null, ['pool' => '5000.00'])->pool_minor)->toBe(500000);
});

test('BA2-07: overlapping non-cancelled periods are refused, adjacent ones are fine, cancelled ones do not block', function () {
    $admin = e39_admin();
    $first = e39_period($admin);

    expect(fn () => e39_period($admin, ['starts_at' => '2026-11-05', 'ends_at' => '2026-11-15']))->toThrow(CreatorFinanceException::class);

    $adjacent = e39_period($admin, ['label' => 'التالية', 'starts_at' => '2026-11-10 00:00:00', 'ends_at' => '2026-11-18 00:00:00']);
    expect($adjacent->status)->toBe('draft');

    e39_periods()->cancel($first, $admin, 'إلغاء تجريبي للاختبار');
    expect(e39_period($admin, ['label' => 'بديلة'])->status)->toBe('draft');
});

test('BA2-08: updating is allowed in draft only and re-checks overlap while ignoring itself', function () {
    $admin = e39_admin();
    $a = e39_period($admin);
    $b = e39_period($admin, ['label' => 'ب', 'starts_at' => '2026-11-10 00:00:00', 'ends_at' => '2026-11-18 00:00:00']);

    $same = e39_periods()->update($a, $admin, ['label' => 'معدّلة', 'starts_at' => '2026-11-01', 'ends_at' => '2026-11-09', 'pool' => '5.00']);
    expect($same->label)->toBe('معدّلة')->and($same->pool_minor)->toBe(500);

    expect(fn () => e39_periods()->update($b, $admin, ['label' => 'ب', 'starts_at' => '2026-11-05', 'ends_at' => '2026-11-15', 'pool' => '5.00']))->toThrow(CreatorFinanceException::class);

    e39_periods()->submitForReview($a, $admin);
    expect(fn () => e39_periods()->update($a->refresh(), $admin, ['label' => 'x', 'starts_at' => '2026-11-01', 'ends_at' => '2026-11-09', 'pool' => '5.00']))->toThrow(CreatorFinanceException::class);
});

test('BA2-09: submitting for review stores an aggregate summary and is idempotent', function () {
    e39_loose_policy();
    $admin = e39_admin();
    [$creator] = e39_creator($admin);
    e39_solve([e39_work($creator)], 2);
    $period = e39_period($admin);

    $first = e39_periods()->submitForReview($period, $admin);
    $again = e39_periods()->submitForReview($first, $admin);

    expect($first->status)->toBe('review')->and($first->review_summary['eligible_creators'])->toBe(1)->and($again->status)->toBe('review')->and(OperationalAuditLog::query()->where('action', 'creator_revenue_period_review')->count())->toBe(1);
});

test('BA2-10: sending back to draft clears the review summary and allows editing again', function () {
    $admin = e39_admin();
    $period = e39_review($admin);
    $back = e39_periods()->sendBack($period, $admin);

    expect($back->status)->toBe('draft')->and($back->review_summary)->toBeNull()->and($back->reviewed_at)->toBeNull();
});

test('BA2-11: cancelling needs a real reason, is idempotent, and a finalized period can never be cancelled', function () {
    $admin = e39_admin();
    $period = e39_period($admin);

    expect(fn () => e39_periods()->cancel($period, $admin, 'قصير'))->toThrow(CreatorFinanceException::class);

    $cancelled = e39_periods()->cancel($period, $admin, 'سبب إلغاء كافٍ وواضح');
    expect($cancelled->status)->toBe('cancelled')->and(e39_periods()->cancel($cancelled, $admin, 'سبب إلغاء كافٍ وواضح')->status)->toBe('cancelled');

    $final = e39_finalize(null, $admin);
    expect(fn () => e39_periods()->cancel($final, $admin, 'محاولة إلغاء مرفوضة'))->toThrow(CreatorFinanceException::class);
});

// ───────────────────────────── BA5: الإقفال ─────────────────────────────

test('BA5-01: only a period in review can be finalized', function () {
    $admin = e39_admin();

    expect(fn () => e39_periods()->finalize(e39_period($admin), $admin))->toThrow(CreatorFinanceException::class);
});

test('BA5-02: a period that has not ended (plus the settling delay) cannot be finalized', function () {
    $admin = e39_admin();
    $period = e39_review($admin, ['starts_at' => '2026-11-15 00:00:00', 'ends_at' => '2026-11-20 11:30:00']);

    expect(fn () => e39_periods()->finalize($period, $admin))->toThrow(CreatorFinanceException::class);

    Carbon::setTestNow(Carbon::parse('2026-11-20 12:31:00'));
    expect(e39_periods()->finalize($period->refresh(), $admin)->status)->toBe('finalized');
});

test('BA5-03: finalizing writes allocations, ledger entries, account snapshots and period totals in one go', function () {
    [$creator, $profile, $account, $period] = e39_funded_creator(3);

    expect($period->allocated_minor)->toBe(100000)->and($period->unallocated_minor)->toBe(0)->and($period->eligible_creators)->toBe(1)->and($period->total_units)->toBe(3)
        ->and($period->finalized_at)->not->toBeNull()->and($period->finalized_by)->not->toBeNull()
        ->and(CreatorRevenueAllocation::query()->count())->toBe(1)
        ->and(CreatorEarningEntry::query()->where('type', 'revenue_share')->sum('available_delta_minor'))->toBe(100000)
        ->and($account->available_minor)->toBe(100000);
});

test('BA5-04: finalizing twice is a no-op - no new allocation, entry or audit row', function () {
    $admin = e39_admin();
    e39_loose_policy();
    [$creator] = e39_creator($admin);
    e39_solve([e39_work($creator)], 3);
    $period = e39_finalize(e39_review($admin), $admin);
    $counts = e39_counts();
    $audit = OperationalAuditLog::query()->count();

    $again = e39_periods()->finalize($period, $admin);
    $stale = e39_periods()->finalize(CreatorRevenuePeriod::query()->find($period->id), $admin);

    expect($again->id)->toBe($period->id)->and($stale->status)->toBe('finalized')->and(e39_counts())->toBe($counts)->and(OperationalAuditLog::query()->count())->toBe($audit);
});

test('BA5-05: a finalized period is immutable at the model level', function () {
    $period = e39_finalize();

    foreach (['label' => 'تغيير', 'pool_minor' => 5, 'status' => 'cancelled', 'allocated_minor' => 1, 'note' => 'x'] as $column => $value) {
        expect(fn () => $period->forceFill([$column => $value])->save())->toThrow(LogicException::class);
    }

    expect(fn () => $period->delete())->toThrow(LogicException::class);
});

test('BA5-06: allocations are append only', function () {
    [, , , $period] = e39_funded_creator(3);
    $allocation = CreatorRevenueAllocation::query()->firstOrFail();

    expect(fn () => $allocation->forceFill(['allocated_minor' => 1])->save())->toThrow(LogicException::class)
        ->and(fn () => $allocation->delete())->toThrow(LogicException::class);
});

test('BA5-07: the database rejects a second allocation for the same creator in the same period', function () {
    [, $profile, , $period] = e39_funded_creator(3);

    expect(fn () => DB::table('creator_revenue_allocations')->insert([
        'public_id' => (string) Str::ulid(), 'creator_revenue_period_id' => $period->id, 'creator_profile_id' => $profile->id, 'user_id' => $profile->user_id,
        'raw_units' => 1, 'qualified_units' => 1, 'unique_players' => 1, 'period_total_units' => 1, 'allocated_minor' => 1, 'remainder_bonus_minor' => 0, 'currency' => 'USD', 'created_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('BA5-08: with no eligible creator the whole pool stays unallocated and nothing reaches the ledger', function () {
    $admin = e39_admin();
    $period = e39_finalize(null, $admin);

    expect($period->allocated_minor)->toBe(0)->and($period->unallocated_minor)->toBe(100000)->and($period->eligible_creators)->toBe(0)
        ->and(CreatorRevenueAllocation::query()->count())->toBe(0)->and(CreatorEarningEntry::query()->count())->toBe(0);
});

test('BA5-09: several creators split the pool exactly and each ledger entry equals its allocation', function () {
    e39_loose_policy();
    $admin = e39_admin();
    $units = [3, 5, 7, 11];
    $profiles = [];

    foreach ($units as $n) {
        [$user, $profile] = e39_creator($admin);
        e39_solve([e39_work($user)], $n);
        $profiles[] = $profile;
    }

    $period = e39_finalize(e39_review($admin, ['pool' => '333.33']), $admin);

    expect($period->allocated_minor)->toBe(33333)->and(CreatorRevenueAllocation::query()->sum('allocated_minor'))->toBe(33333)->and(CreatorEarningEntry::query()->sum('available_delta_minor'))->toBe(33333);

    foreach (CreatorRevenueAllocation::query()->get() as $allocation) {
        $entry = CreatorEarningEntry::query()->where('creator_revenue_allocation_id', $allocation->id)->firstOrFail();
        expect($entry->available_delta_minor)->toBe($allocation->allocated_minor)->and($entry->source_key)->toBe('revenue:'.$period->public_id.':'.$allocation->creator_profile_id);
    }
});

test('BA5-10: ineligible creators receive nothing while eligible ones are paid in full', function () {
    e39_loose_policy(2, 2);
    $admin = e39_admin();
    [$ok, $okProfile] = e39_creator($admin);
    [$small, $smallProfile] = e39_creator($admin);
    e39_solve([e39_work($ok)], 4);
    e39_solve([e39_work($small)], 1);

    $period = e39_finalize(e39_review($admin), $admin);

    expect($period->eligible_creators)->toBe(1)->and(CreatorRevenueAllocation::query()->where('creator_profile_id', $smallProfile->id)->exists())->toBeFalse()
        ->and(CreatorRevenueAllocation::query()->where('creator_profile_id', $okProfile->id)->value('allocated_minor'))->toBe(100000);
});

test('BA5-11: the policy snapshot freezes the rules used at finalization even if the config changes afterwards', function () {
    e39_loose_policy(1, 1, 3);
    $period = e39_finalize();
    $snapshot = $period->policy_snapshot;
    config(['creator_monetization.allocation.per_user_per_creator_cap' => 99, 'creator_monetization.allocation.min_units_per_creator' => 99]);

    expect($snapshot['per_user_per_creator_cap'])->toBe(3)->and($snapshot['min_units_per_creator'])->toBe(1)->and($snapshot['method'])->toBe('largest_remainder')
        ->and($snapshot['tie_break'])->toBe('creator_profile_id_asc')->and($period->fresh()->policy_snapshot)->toBe($snapshot);
});

test('BA5-12: a creator frozen at finalization time is left out while the others are paid', function () {
    e39_loose_policy();
    $admin = e39_admin();
    [$a] = e39_creator($admin);
    [$b, $bProfile] = e39_creator($admin);
    e39_solve([e39_work($a)], 2);
    e39_solve([e39_work($b)], 2);
    $period = e39_review($admin);
    $b->forceFill(['is_frozen' => true])->save();

    $final = e39_periods()->finalize($period, $admin);

    expect($final->eligible_creators)->toBe(1)->and(CreatorRevenueAllocation::query()->where('creator_profile_id', $bProfile->id)->exists())->toBeFalse();
});

test('BA5-13: late data inside the period is included because the result is computed at finalization, not at review', function () {
    e39_loose_policy();
    $admin = e39_admin();
    [$creator] = e39_creator($admin);
    $work = e39_work($creator);
    e39_solve([$work], 1);
    $period = e39_review($admin);
    e39_solve([$work], 2);

    $final = e39_periods()->finalize($period, $admin);

    expect($final->total_units)->toBe(3);
});

test('BA5-14: a tiny pool can give a creator zero - the allocation row exists but no ledger entry and no notification', function () {
    e39_loose_policy();
    $admin = e39_admin();
    [$a] = e39_creator($admin);
    [$b] = e39_creator($admin);
    e39_solve([e39_work($a)], 1);
    e39_solve([e39_work($b)], 1);
    $period = e39_finalize(e39_review($admin, ['pool' => '0.01']), $admin);

    expect(CreatorRevenueAllocation::query()->count())->toBe(2)->and(CreatorRevenueAllocation::query()->where('allocated_minor', 0)->count())->toBe(1)
        ->and(CreatorEarningEntry::query()->count())->toBe(1)->and(e39_notes(null, 'creator_statement_ready'))->toHaveCount(1);
});

test('BA5-15: every lifecycle step leaves an audit record', function () {
    $admin = e39_admin();
    e39_finalize(null, $admin);
    $actions = OperationalAuditLog::query()->where('action', 'like', 'creator_revenue_period_%')->pluck('action')->all();

    expect($actions)->toContain('creator_revenue_period_created', 'creator_revenue_period_review', 'creator_revenue_period_finalized');
});

test('BA5-16: finalization notifies each paid creator once, with no amounts in the text', function () {
    [$creator, , , $period] = e39_funded_creator(3);
    $rows = e39_notes($creator, 'creator_statement_ready');

    expect($rows)->toHaveCount(1)->and($rows[0]->data['type'])->toBe('creator_statement_ready')->and($rows[0]->data['body'])->not->toMatch('/\d/')->and($rows[0]->data['title'])->not->toMatch('/\d/');
});

test('BA5-17: a notification failure never fails or rolls back the finalization', function () {
    e39_loose_policy();
    $admin = e39_admin();
    [$creator] = e39_creator($admin);
    e39_solve([e39_work($creator)], 2);
    $period = e39_review($admin);
    $this->mock(\App\Services\Notifications\NotificationDispatcher::class, function ($mock) {
        $mock->shouldReceive('dispatch')->andThrow(new RuntimeException('boom'));
    });
    $this->app->forgetInstance(\App\Services\CreatorFinance\CreatorFinanceNotifier::class);
    $this->app->forgetInstance(\App\Services\CreatorFinance\CreatorRevenuePeriodService::class);

    $final = app(\App\Services\CreatorFinance\CreatorRevenuePeriodService::class)->finalize($period, $admin);

    expect($final->status)->toBe('finalized')->and(CreatorEarningEntry::query()->count())->toBe(1);
});

test('BA5-18: the period delete is refused and previewing never writes anything', function () {
    e39_loose_policy();
    $admin = e39_admin();
    [$creator] = e39_creator($admin);
    e39_solve([e39_work($creator)], 2);
    $period = e39_review($admin);
    $counts = e39_counts();
    $audit = OperationalAuditLog::query()->count();

    e39_periods()->preview($period);

    expect(e39_counts())->toBe($counts)->and(OperationalAuditLog::query()->count())->toBe($audit)->and(fn () => $period->delete())->toThrow(LogicException::class);
});

test('BA5-19: previewing a finalized period returns the stored numbers', function () {
    [, , , $period] = e39_funded_creator(3);
    $preview = e39_periods()->preview($period->refresh());

    expect($preview['finalized'])->toBeTrue()->and($preview['summary']['allocated_minor'])->toBe(100000)->and($preview['rows'])->toHaveCount(1);
});

test('BA5-20: the preview before finalization matches the finalized result exactly', function () {
    e39_loose_policy();
    $admin = e39_admin();

    foreach ([2, 3, 4] as $n) {
        [$user] = e39_creator($admin);
        e39_solve([e39_work($user)], $n);
    }

    $period = e39_review($admin, ['pool' => '100.01']);
    $preview = collect(e39_periods()->preview($period)['rows'])->pluck('allocated_minor', 'creator_profile_id')->all();
    e39_periods()->finalize($period, $admin);
    $final = CreatorRevenueAllocation::query()->pluck('allocated_minor', 'creator_profile_id')->all();

    expect($final)->toBe($preview);
});

test('BA5-21: creating, reviewing and finalizing each demand their own permission', function () {
    $plain = e39_plain_staff();

    expect(fn () => e39_periods()->create($plain, ['label' => 'x', 'starts_at' => '2026-11-01', 'ends_at' => '2026-11-10', 'pool' => '1.00']))->toThrow(CreatorFinanceException::class);

    $manager = e39_staff_with(['creator-finance.manage-periods']);
    $period = e39_periods()->create($manager, ['label' => 'x', 'starts_at' => '2026-11-01', 'ends_at' => '2026-11-10', 'pool' => '1.00']);
    e39_periods()->submitForReview($period, $manager);

    expect(fn () => e39_periods()->finalize($period->refresh(), $manager))->toThrow(CreatorFinanceException::class);
});
