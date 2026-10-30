<?php

use App\Models\SeasonWriterEntry;
use App\Models\SeasonWriterStarAllocation;
use App\Models\SeasonWriterVoteBudget;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/_support.php';

/*
| E44 / التصويت: ميزانية واحدة (10 نجوم) لكل حساب في الموسم، توزيع 0..10 لكل كاتب، أهلية خاصة بالكاتب (دليل لعب على سلسلة مربوطة به)،
| منع التصويت للنفس، الطاقم بالصلاحية، إعادة التوزيع قبل الإغلاق وتجميده بعده، والمجاميع المخزَّنة تطابق الدفتر دائمًا.
*/

beforeEach(fn () => e44_seed());
afterEach(fn () => Carbon::setTestNow());

// ───────────────────────────── المسار الأساسي والميزانية ─────────────────────────────

test('E44-VT-01: a player who played a linked series votes; the budget is created lazily and the aggregates update atomically', function () {
    $league = e44_league(2);
    [$a] = $league['writers'];

    expect(SeasonWriterVoteBudget::query()->count())->toBe(0);

    [$voter, $result] = e44_vote($a, 4);

    expect($result)->toMatchArray(['stars' => 4, 'delta' => 4, 'spent' => 4, 'remaining' => 6, 'total' => 10, 'writer_stars_total' => 4, 'writer_voters' => 1]);

    $entry = SeasonWriterEntry::query()->findOrFail($a['entry']->id);
    $budget = SeasonWriterVoteBudget::query()->where('user_id', $voter->id)->firstOrFail();

    expect((int) $entry->stars_total)->toBe(4)
        ->and((int) $entry->voters_count)->toBe(1)
        ->and($entry->first_star_at)->not->toBeNull()
        ->and($entry->last_star_at)->not->toBeNull()
        ->and((int) $budget->total_stars)->toBe(10)
        ->and((int) $budget->spent_stars)->toBe(4)
        ->and(SeasonWriterVoteBudget::query()->count())->toBe(1);
});

test('E44-VT-02: an incorrect answer counts as play evidence just like a correct one', function () {
    $league = e44_league(1);
    $writer = $league['writers'][0];
    $wrongPlayer = e44_player_of($writer, false);

    expect(DB::table('community_puzzle_attempts')->where('user_id', $wrongPlayer->id)->where('is_correct', false)->exists())->toBeTrue();

    [, $result] = e44_vote($writer, 2, $wrongPlayer);

    expect($result['stars'])->toBe(2)
        ->and(e44_stars($writer))->toBe(2);
});

test('E44-VT-03: without play evidence the vote is refused and nothing — not even a budget row — is created', function () {
    $league = e44_league(1);
    $writer = $league['writers'][0];
    $stranger = e16User();

    expect(e44_reason(fn () => e44_voting()->allocate($stranger, $writer['entry']->fresh(), 3)))->toBe('no_play_evidence')
        ->and(SeasonWriterVoteBudget::query()->count())->toBe(0)
        ->and(SeasonWriterStarAllocation::query()->count())->toBe(0)
        ->and(e44_stars($writer))->toBe(0);
});

test('E44-VT-04: eligibility is writer-specific — playing A’s series does not allow voting for B', function () {
    $league = e44_league(2);
    [$a, $b] = $league['writers'];
    $player = e44_player_of($a);

    expect(e44_voting()->allocate($player, $a['entry']->fresh(), 3)['stars'])->toBe(3)
        ->and(e44_reason(fn () => e44_voting()->allocate($player, $b['entry']->fresh(), 3)))->toBe('no_play_evidence')
        ->and(e44_stars($b))->toBe(0);

    // بعد أن يلعب سلسلة B يصبح مؤهَّلًا لها هي الأخرى.
    e30s_solve($player, $b['works'][0]);
    expect(e44_voting()->allocate($player, $b['entry']->fresh(), 2)['stars'])->toBe(2);
});

test('E44-VT-05: playing a work of the writer that is NOT in a series linked for this season gives no eligibility', function () {
    $league = e44_league(1);
    $writer = $league['writers'][0];
    $unlinkedWorks = e30s_works($writer['user'], 2);
    e30s_published($writer['user'], $unlinkedWorks); // منشورة لكن غير مربوطة بالموسم
    $player = e16User();
    e30s_solve($player, $unlinkedWorks[0]);

    expect(e44_reason(fn () => e44_voting()->allocate($player, $writer['entry']->fresh(), 2)))->toBe('no_play_evidence');
});

test('E44-VT-06: only attempts made after the series was linked and the writer joined count (earlier attempts give no eligibility)', function () {
    $league = e44_league(1);
    $writer = $league['writers'][0];
    $work = $writer['works'][0];

    $early = e16User();
    e39_attempt($early, $work, true, '2026-12-10 11:59:59'); // قبل الانضمام/الربط (12:00:00)

    expect(e44_reason(fn () => e44_voting()->allocate($early, $writer['entry']->fresh(), 2)))->toBe('no_play_evidence');

    $onTime = e16User();
    e39_attempt($onTime, $work, true, '2026-12-10 12:00:30');

    expect(e44_voting()->allocate($onTime, $writer['entry']->fresh(), 2)['stars'])->toBe(2);

    $beforeSeason = e16User();
    e39_attempt($beforeSeason, $work, true, '2026-11-29 10:00:00'); // قبل بداية الموسم

    expect(e44_reason(fn () => e44_voting()->allocate($beforeSeason, $writer['entry']->fresh(), 2)))->toBe('no_play_evidence');
});

test('E44-VT-07: voting for yourself is refused for everyone — writer, staff with vote permission, even administrators', function () {
    $league = e44_league(2);
    [$a] = $league['writers'];

    expect(e44_reason(fn () => e44_voting()->allocate($a['user'], $a['entry']->fresh(), 5)))->toBe('self_vote');

    $a['user']->givePermissionTo('seasonal-recognition.vote');
    expect(e44_reason(fn () => e44_voting()->allocate($a['user']->fresh(), $a['entry']->fresh(), 5)))->toBe('self_vote');

    $a['user']->assignRole('administrator');
    expect(e44_reason(fn () => e44_voting()->allocate($a['user']->fresh(), $a['entry']->fresh(), 5)))->toBe('self_vote')
        ->and(e44_stars($a))->toBe(0)
        ->and(SeasonWriterStarAllocation::query()->count())->toBe(0);
});

test('E44-VT-08: an active writer may vote for other writers without playing, but never for themself', function () {
    $league = e44_league(3);
    [$a, $b, $c] = $league['writers'];

    $result = e44_voting()->allocate($a['user'], $b['entry']->fresh(), 4);
    expect($result['stars'])->toBe(4)
        ->and(e44_voting()->allocate($a['user'], $c['entry']->fresh(), 6)['remaining'])->toBe(0)
        ->and(e44_reason(fn () => e44_voting()->allocate($a['user'], $a['entry']->fresh(), 1)))->toBe('self_vote');
});

test('E44-VT-09: staff with seasonal-recognition.vote may vote for any writer; staff with only view may not; moderators have vote by default', function () {
    $league = e44_league(2);
    [$a, $b] = $league['writers'];

    $viewOnly = e44_staff(['seasonal-recognition.view']);
    expect(e44_reason(fn () => e44_voting()->allocate($viewOnly, $a['entry']->fresh(), 2)))->toBe('no_play_evidence');

    $staff = e44_staff_voter();
    expect(e44_voting()->allocate($staff, $a['entry']->fresh(), 5)['stars'])->toBe(5)
        ->and(e44_voting()->allocate($staff, $b['entry']->fresh(), 5)['remaining'])->toBe(0);

    $moderator = e28_reviewer('moderator');
    expect(e44_voting()->allocate($moderator, $a['entry']->fresh(), 1)['stars'])->toBe(1)
        ->and(e44_stars($a))->toBe(6);
});

test('E44-VT-10: the budget is one 10-star pool per account per season — splitting across writers cannot exceed it', function () {
    $league = e44_league(3);
    [$a, $b, $c] = $league['writers'];
    $staff = e44_staff_voter();

    e44_voting()->allocate($staff, $a['entry']->fresh(), 6);
    e44_voting()->allocate($staff, $b['entry']->fresh(), 4);

    expect(e44_reason(fn () => e44_voting()->allocate($staff, $c['entry']->fresh(), 1)))->toBe('budget_exceeded')
        ->and(e44_reason(fn () => e44_voting()->allocate($staff, $a['entry']->fresh(), 7)))->toBe('budget_exceeded')
        ->and(SeasonWriterVoteBudget::query()->where('user_id', $staff->id)->count())->toBe(1)
        ->and((int) SeasonWriterVoteBudget::query()->where('user_id', $staff->id)->value('spent_stars'))->toBe(10)
        ->and(e44_stars($c))->toBe(0)
        ->and(e44_stars($a))->toBe(6);
});

test('E44-VT-11: holding several capacities (writer + staff + player) never multiplies the single budget', function () {
    $league = e44_league(3);
    [$a, $b, $c] = $league['writers'];
    $multi = $a['user'];
    $multi->givePermissionTo('seasonal-recognition.vote');
    $multi = $multi->fresh();
    e30s_solve($multi, $b['works'][0]);

    e44_voting()->allocate($multi, $b['entry']->fresh(), 7);
    e44_voting()->allocate($multi, $c['entry']->fresh(), 3);

    expect(e44_reason(fn () => e44_voting()->allocate($multi, $b['entry']->fresh(), 8)))->toBe('budget_exceeded')
        ->and(SeasonWriterVoteBudget::query()->where('user_id', $multi->id)->count())->toBe(1)
        ->and((int) SeasonWriterVoteBudget::query()->where('user_id', $multi->id)->value('total_stars'))->toBe(10);
});

test('E44-VT-12: reallocation before closing is allowed, frees budget, and zero withdraws the allocation row', function () {
    $league = e44_league(2);
    [$a, $b] = $league['writers'];
    $staff = e44_staff_voter();

    e44_voting()->allocate($staff, $a['entry']->fresh(), 8);
    $down = e44_voting()->allocate($staff, $a['entry']->fresh(), 3);

    expect($down)->toMatchArray(['stars' => 3, 'delta' => -5, 'spent' => 3, 'remaining' => 7, 'writer_stars_total' => 3]);

    $up = e44_voting()->allocate($staff, $b['entry']->fresh(), 7);
    expect($up['remaining'])->toBe(0);

    $zero = e44_voting()->allocate($staff, $a['entry']->fresh(), 0);

    expect($zero)->toMatchArray(['stars' => 0, 'delta' => -3, 'remaining' => 3, 'writer_stars_total' => 0, 'writer_voters' => 0])
        ->and(SeasonWriterStarAllocation::query()->where('writer_entry_id', $a['entry']->id)->exists())->toBeFalse()
        ->and(SeasonWriterStarAllocation::query()->where('writer_entry_id', $b['entry']->id)->count())->toBe(1);
});

test('E44-VT-13: repeating the same allocation changes nothing (no delta, no extra row, no timestamp churn on the aggregate)', function () {
    $league = e44_league(1);
    $writer = $league['writers'][0];
    [$voter] = e44_vote($writer, 5);

    $before = SeasonWriterEntry::query()->findOrFail($writer['entry']->id);
    e44_tick(5);
    $again = e44_voting()->allocate($voter, $writer['entry']->fresh(), 5);
    $after = SeasonWriterEntry::query()->findOrFail($writer['entry']->id);

    expect($again['delta'])->toBe(0)
        ->and((int) $after->stars_total)->toBe(5)
        ->and((int) $after->voters_count)->toBe(1)
        ->and($after->last_star_at->equalTo($before->last_star_at))->toBeTrue()
        ->and(SeasonWriterStarAllocation::query()->count())->toBe(1);
});

test('E44-VT-14: star counts outside 0..10 are rejected and a single writer can take the whole budget', function () {
    $league = e44_league(1);
    $writer = $league['writers'][0];
    $player = e44_player_of($writer);

    foreach ([-1, 11, 100] as $bad) {
        expect(e44_reason(fn () => e44_voting()->allocate($player, $writer['entry']->fresh(), $bad)))->toBe('invalid_stars');
    }

    expect(SeasonWriterVoteBudget::query()->count())->toBe(0)
        ->and(e44_voting()->allocate($player, $writer['entry']->fresh(), 10)['remaining'])->toBe(0);
});

test('E44-VT-15: first_star_at is set once; last_star_at moves with every change; voters are counted once per voter', function () {
    $league = e44_league(1);
    $writer = $league['writers'][0];
    [$v1] = e44_vote($writer, 2);
    $first = SeasonWriterEntry::query()->findOrFail($writer['entry']->id);

    e44_tick(10);
    e44_vote($writer, 3);
    e44_voting()->allocate($v1, $writer['entry']->fresh(), 4);
    $after = SeasonWriterEntry::query()->findOrFail($writer['entry']->id);

    expect((int) $after->stars_total)->toBe(7)
        ->and((int) $after->voters_count)->toBe(2)
        ->and($after->first_star_at->equalTo($first->first_star_at))->toBeTrue()
        ->and($after->last_star_at->greaterThan($first->last_star_at))->toBeTrue();
});

// ───────────────────────────── النافذة ─────────────────────────────

test('E44-VT-16: voting is refused before it is configured, before it opens, at/after close and once finalized', function () {
    $league = e44_league(1, ['open' => false]);
    $writer = $league['writers'][0];
    $season = $league['season'];
    $staff = e44_staff_voter();

    expect(e44_reason(fn () => e44_voting()->allocate($staff, $writer['entry']->fresh(), 1)))->toBe('not_configured');

    e44_settings()->saveWriterSettings($season, ['opens_at' => '2026-12-12 00:00:00', 'closes_at' => '2026-12-20 12:00:00'], $league['admin']);
    expect(e44_reason(fn () => e44_voting()->allocate($staff, $writer['entry']->fresh(), 1)))->toBe('not_open_yet');

    Carbon::setTestNow(Carbon::parse('2026-12-12 00:00:00'));
    expect(e44_voting()->allocate($staff, $writer['entry']->fresh(), 1)['stars'])->toBe(1);

    Carbon::setTestNow(Carbon::parse('2026-12-20 11:59:59'));
    expect(e44_voting()->allocate($staff, $writer['entry']->fresh(), 2)['stars'])->toBe(2);

    Carbon::setTestNow(Carbon::parse('2026-12-20 12:00:00'));
    expect(e44_reason(fn () => e44_voting()->allocate($staff, $writer['entry']->fresh(), 3)))->toBe('closed');

    e44_finalizer()->finalize($season, $league['admin']);
    expect(e44_reason(fn () => e44_voting()->allocate($staff, $writer['entry']->fresh(), 3)))->toBe('finalized');
});

test('E44-VT-17: after the vote closes the allocations are frozen — no change and no withdrawal', function () {
    $league = e44_league(2);
    [$a, $b] = $league['writers'];
    [$voter] = e44_vote($a, 5);

    e44_after_close();

    expect(e44_reason(fn () => e44_voting()->allocate($voter, $a['entry']->fresh(), 9)))->toBe('closed')
        ->and(e44_reason(fn () => e44_voting()->allocate($voter, $a['entry']->fresh(), 0)))->toBe('closed')
        ->and(e44_reason(fn () => e44_voting()->allocate($voter, $b['entry']->fresh(), 1)))->toBe('closed')
        ->and(e44_stars($a))->toBe(5)
        ->and(e44_ledger_stars($a))->toBe(5);
});

test('E44-VT-18: unverified and frozen accounts cannot vote', function () {
    $league = e44_league(1);
    $writer = $league['writers'][0];

    $unverified = e16User(['email_verified_at' => null]);
    $unverified->givePermissionTo('seasonal-recognition.vote');

    $frozen = e44_staff_voter();
    $frozen->forceFill(['is_frozen' => true])->save();

    expect(e44_reason(fn () => e44_voting()->allocate($unverified->fresh(), $writer['entry']->fresh(), 2)))->toBe('unverified')
        ->and(e44_reason(fn () => e44_voting()->allocate($frozen->fresh(), $writer['entry']->fresh(), 2)))->toBe('frozen')
        ->and(SeasonWriterVoteBudget::query()->count())->toBe(0)
        ->and(e44_stars($writer))->toBe(0);
});

test('E44-VT-19: a withdrawn writer takes no new stars, but voters may still pull theirs back', function () {
    $league = e44_league(2);
    [$a, $b] = $league['writers'];
    [$voter] = e44_vote($a, 6);
    e44_roster()->removeWriter($a['entry']->fresh(), $league['admin']);

    $other = e44_staff_voter();

    expect(e44_reason(fn () => e44_voting()->allocate($other, $a['entry']->fresh(), 3)))->toBe('entry_inactive')
        ->and(e44_reason(fn () => e44_voting()->allocate($voter, $a['entry']->fresh(), 8)))->toBe('entry_inactive');

    $pulled = e44_voting()->allocate($voter, $a['entry']->fresh(), 2);

    expect($pulled['delta'])->toBe(-4)
        ->and(e44_stars($a))->toBe(2)
        ->and(e44_voting()->allocate($other, $b['entry']->fresh(), 5)['remaining'])->toBe(5);
});

// ───────────────────────────── السلامة والعزل ─────────────────────────────

test('E44-VT-20: after a deterministic storm of mixed operations, stored aggregates equal the allocation ledger exactly', function () {
    $league = e44_league(3);
    $writers = $league['writers'];
    $voters = [];

    for ($i = 0; $i < 5; $i++) {
        $voters[] = e44_staff_voter();
    }

    mt_srand(44);

    for ($i = 0; $i < 60; $i++) {
        $voter = $voters[mt_rand(0, 4)];
        $writer = $writers[mt_rand(0, 2)];
        $stars = mt_rand(0, 10);

        try {
            e44_voting()->allocate($voter, $writer['entry']->fresh(), $stars);
        } catch (\App\Services\SeasonalRecognition\SeasonalRecognitionException $e) {
            expect($e->reason)->toBe('budget_exceeded');
        }
    }

    foreach ($writers as $writer) {
        $entry = SeasonWriterEntry::query()->findOrFail($writer['entry']->id);
        $ledger = DB::table('season_writer_star_allocations')->where('writer_entry_id', $entry->id);

        expect((int) $entry->stars_total)->toBe((int) $ledger->sum('stars'))
            ->and((int) $entry->voters_count)->toBe((int) $ledger->count());
    }

    foreach ($voters as $voter) {
        $spent = (int) DB::table('season_writer_star_allocations')->where('voter_user_id', $voter->id)->sum('stars');
        $budget = SeasonWriterVoteBudget::query()->where('user_id', $voter->id)->first();

        expect($spent)->toBeLessThanOrEqual(10)
            ->and((int) ($budget?->spent_stars ?? 0))->toBe($spent);
    }

    expect(app(\App\Services\SeasonalRecognition\SeasonWriterReconciler::class)->inspect($league['season']))->toBe([]);
});

test('E44-VT-21: the database refuses a second allocation row for the same voter and writer, and a second budget for the same voter and season', function () {
    $league = e44_league(1);
    $writer = $league['writers'][0];
    [$voter] = e44_vote($writer, 2);

    expect(fn () => DB::table('season_writer_star_allocations')->insert([
        'season_id' => $league['season']->id, 'writer_entry_id' => $writer['entry']->id, 'voter_user_id' => $voter->id, 'stars' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(UniqueConstraintViolationException::class);

    expect(fn () => DB::table('season_writer_vote_budgets')->insert([
        'season_id' => $league['season']->id, 'user_id' => $voter->id, 'total_stars' => 10, 'spent_stars' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(UniqueConstraintViolationException::class);
});

test('E44-VT-22: voting never touches XP, gems, currencies, quests, the official attempts table, sponsorship or creator earnings, and is isolated from community ratings', function () {
    $league = e44_league(2);
    [$a, $b] = $league['writers'];
    $player = e44_player_of($a);
    $before = e44_foreign_counts();
    $ratingsBefore = DB::table('community_puzzle_ratings')->count();
    $world = e28_world($player);

    e44_voting()->allocate($player, $a['entry']->fresh(), 5);
    e44_voting()->allocate($player, $a['entry']->fresh(), 2);
    e44_voting()->allocate(e44_staff_voter(), $b['entry']->fresh(), 10);

    expect(e44_foreign_counts())->toBe($before)
        ->and(DB::table('community_puzzle_ratings')->count())->toBe($ratingsBefore)
        ->and(e28_world($player))->toBe($world);

    // الدفتر نفسه لا يحمل أي عمود عملة أو مبلغ (نجوم فقط).
    $columns = \Illuminate\Support\Facades\Schema::getColumnListing('season_writer_star_allocations');
    expect(array_intersect($columns, ['amount', 'amount_minor', 'currency', 'gems', 'xp', 'score']))->toBe([]);
});

test('E44-VT-23: the voter panel reports the budget, allocations and exactly the writers the voter may vote for', function () {
    $league = e44_league(3);
    [$a, $b, $c] = $league['writers'];
    $player = e44_player_of($b);
    e44_voting()->allocate($player, $b['entry']->fresh(), 4);

    $panel = e44_voting()->panel($player, $league['season']);

    expect($panel['open'])->toBeTrue()
        ->and($panel['state'])->toBe('open')
        ->and($panel['total'])->toBe(10)
        ->and($panel['spent'])->toBe(4)
        ->and($panel['remaining'])->toBe(6)
        ->and($panel['allocations'])->toBe([(int) $b['entry']->id => 4])
        ->and($panel['votable'])->toBe([(int) $b['entry']->id]);

    // لاعب جديد: ميزانية افتراضية كاملة ولا أحد يحق له التصويت.
    $new = e16User();
    $fresh = e44_voting()->panel($new, $league['season']);
    expect($fresh['remaining'])->toBe(10)->and($fresh['votable'])->toBe([]);

    // الكاتب يرى الآخرين فقط.
    $writerPanel = e44_voting()->panel($a['user'], $league['season']);
    expect($writerPanel['votable'])->toEqualCanonicalizing([(int) $b['entry']->id, (int) $c['entry']->id]);
});

test('E44-VT-24: the batch eligibility list agrees with the single-writer decision for every writer', function () {
    $league = e44_league(3);
    $writers = $league['writers'];
    $eligibility = app(\App\Services\SeasonalRecognition\SeasonWriterEligibilityService::class);
    $voters = [e44_player_of($writers[0]), $writers[1]['user'], e44_staff_voter(), e16User()];

    foreach ($voters as $voter) {
        $batch = $eligibility->votableEntryIds($voter, $league['season']);

        foreach ($writers as $writer) {
            $single = $eligibility->denial($voter, $writer['entry']->fresh()) === null;
            expect(in_array((int) $writer['entry']->id, $batch, true))->toBe($single);
        }
    }
});

test('E44-VT-25: the budget size is snapshotted at the first vote; later reads show that total', function () {
    $league = e44_league(1, ['open' => false]);
    e44_settings()->saveWriterSettings($league['season'], ['stars_per_voter' => 7], $league['admin']);
    e44_open($league['season'], [], $league['admin']);
    e44_tick(1);

    [$voter, $result] = e44_vote($league['writers'][0], 3);

    expect($result['total'])->toBe(7)
        ->and($result['remaining'])->toBe(4)
        ->and(e44_reason(fn () => e44_voting()->allocate($voter, $league['writers'][0]['entry']->fresh(), 8)))->toBe('budget_exceeded');
});

test('E44-VT-26: concurrent-style double submit — two stale copies of the same entry cannot overspend the budget', function () {
    $league = e44_league(2);
    [$a, $b] = $league['writers'];
    $staff = e44_staff_voter();
    $staleA = $a['entry']->fresh();
    $staleB = $b['entry']->fresh();

    e44_voting()->allocate($staff, $staleA, 6);

    // نسخة قديمة من B لا تعرف بالـ6 المصروفة: الخدمة تقرأ الميزانية مقفلة من القاعدة لا من الذاكرة.
    expect(e44_reason(fn () => e44_voting()->allocate($staff, $staleB, 6)))->toBe('budget_exceeded')
        ->and(e44_voting()->allocate($staff, $staleB, 4)['remaining'])->toBe(0);
});

test('E44-VT-27: only staff-category votes exist as a permission, never as a role name hard-coded in the eligibility code', function () {
    $code = e44_code('app/Services/SeasonalRecognition/SeasonWriterEligibilityService.php');

    expect(str_contains($code, "hasRole('"))->toBeFalse()
        ->and(str_contains($code, 'hasAnyRole'))->toBeFalse()
        ->and(str_contains($code, "'seasonal-recognition.vote'"))->toBeTrue()
        ->and(str_contains($code, 'moderator'))->toBeFalse()
        ->and(str_contains($code, 'administrator'))->toBeFalse();
});
