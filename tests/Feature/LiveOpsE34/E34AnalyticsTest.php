<?php

use App\Models\CompetitiveEventParticipant;
use App\Models\Puzzle;
use App\Models\PuzzleAttempt;
use App\Models\User;
use App\Models\UserQuestProgress;
use App\Services\Engagement\QuestPeriodService;
use App\Services\LiveOps\RetentionAnalytics;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/_support.php';

beforeEach(function () {
    e34_seed();
    Carbon::setTestNow(Carbon::parse('2026-10-20 12:00:00', 'UTC'));
});
afterEach(fn () => Carbon::setTestNow());

function e34_an(?int $days = 30): array
{
    RetentionAnalytics::flush();

    return app(RetentionAnalytics::class)->overview($days);
}

/** محاولة رسمية صحيحة بتاريخ محدد (بلا مرور بالمسار الكامل: نحن نقيس القراءة التحليلية لا اللعب). */
function e34_play(User $u, string $at): void
{
    $puzzle = Puzzle::factory()->create();
    PuzzleAttempt::query()->forceCreate(['user_id' => $u->id, 'puzzle_id' => $puzzle->id, 'attempt_number' => 1, 'is_correct' => true, 'used_hint' => false, 'created_at' => $at, 'updated_at' => $at]);
}

function e34_signup(int $daysAgo, int $n): array
{
    return collect(range(1, $n))->map(function () use ($daysAgo) {
        $u = e34_user();
        DB::table('users')->where('id', $u->id)->update(['created_at' => now()->subDays($daysAgo)->setTime(12, 0)]);

        return $u->fresh();
    })->all();
}

test('Z9-01: mission completion is completed over exposed rows per period type, and an empty window shows no fake percentage', function () {
    expect(e34_an()['mission_completion'])->toBe(['daily' => ['total' => 0, 'completed' => 0, 'rate' => null], 'weekly' => ['total' => 0, 'completed' => 0, 'rate' => null]]);

    $q = e34_quest();
    $w = e34_weekly();
    foreach ([[true], [false], [false]] as $i => [$done]) {
        UserQuestProgress::factory()->create(['user_id' => e34_user()->id, 'quest_definition_id' => $q->id, 'period_key' => 'daily:2026-10-20', 'period_start' => now()->startOfDay(), 'completed_at' => $done ? now() : null]);
    }
    UserQuestProgress::factory()->create(['user_id' => e34_user()->id, 'quest_definition_id' => $w->id, 'period_type' => 'weekly', 'period_key' => 'weekly:2026-43', 'period_start' => now()->startOfWeek(), 'completed_at' => now()]);

    $m = e34_an()['mission_completion'];
    expect($m['daily'])->toBe(['total' => 3, 'completed' => 1, 'rate' => 0.3333])->and($m['weekly'])->toBe(['total' => 1, 'completed' => 1, 'rate' => 1.0]);
});

test('Z9-02: D1 and D7 are exact-day retention on matured cohorts, and D30 is empty until a cohort has matured', function () {
    $cohort = e34_signup(10, 6);
    // D1: 3 لاعبين لعبوا يوم التسجيل+1 بالضبط؛ لاعب لعب +2 فقط (لا يُحسب D1)؛ D7: 2 لعبوا +7 بالضبط
    foreach ([0, 1, 2] as $i) {
        e34_play($cohort[$i], now()->subDays(9)->setTime(10, 0)->toDateTimeString());
    }
    e34_play($cohort[3], now()->subDays(8)->setTime(10, 0)->toDateTimeString());
    foreach ([0, 4] as $i) {
        e34_play($cohort[$i], now()->subDays(3)->setTime(10, 0)->toDateTimeString());
    }
    e34_play($cohort[5], now()->subDays(10)->setTime(20, 0)->toDateTimeString()); // يوم التسجيل نفسه: ليس احتفاظًا

    $r = e34_an()['retention'];

    expect($r['d1'])->toBe(['cohort' => 6, 'retained' => 3, 'rate' => 0.5])
        ->and($r['d7'])->toBe(['cohort' => 6, 'retained' => 2, 'rate' => 0.3333])
        ->and($r['d30'])->toMatchArray(['cohort' => 0, 'retained' => 0, 'rate' => null]);
});

test('Z9-03: a cohort below the minimum size never produces a misleading percentage, and unmatured signups are excluded', function () {
    $tiny = e34_signup(10, 2);
    foreach ($tiny as $u) {
        e34_play($u, now()->subDays(9)->setTime(10, 0)->toDateTimeString());
    }
    e34_signup(0, 8); // سجّلوا اليوم: D1 لم ينضج فلا يدخلون الفوج

    $r = e34_an()['retention'];

    expect($r['d1']['cohort'])->toBe(2)->and($r['d1']['retained'])->toBe(2)->and($r['d1']['rate'])->toBeNull();
});

test('Z9-04: the streak distribution counts only effective streaks, so a broken streak is not counted as active', function () {
    $mk = function (int $current, int $daysAgo) {
        $u = e34_user();
        e34_streak($u, $current, max($current, 9), now()->subDays($daysAgo)->toDateString());
    };
    $mk(2, 0); $mk(5, 1); $mk(8, 0); $mk(6, 5); $mk(40, 9);

    $d = e34_an()['streak_distribution'];

    expect($d['1-2'])->toBe(1)->and($d['3-6'])->toBe(1)->and($d['7-13'])->toBe(1)->and($d['none_or_broken'])->toBe(2)
        ->and($d['30-59'])->toBe(0);
});

test('Z9-05: returners are players whose last play before the window was at least the comeback threshold before their first in-window play', function () {
    config(['liveops.comeback.min_inactive_days' => 7]);
    $gap = e34_user();
    e34_play($gap, now()->subDays(50)->toDateTimeString());
    e34_play($gap, now()->subDays(5)->toDateTimeString());
    $steady = e34_user();
    e34_play($steady, now()->subDays(31)->toDateTimeString());
    e34_play($steady, now()->subDays(29)->toDateTimeString());
    $newcomer = e34_user();
    e34_play($newcomer, now()->subDays(3)->toDateTimeString());

    $r = e34_an()['returners'];

    expect($r)->toMatchArray(['min_inactive_days' => 7, 'returners' => 1, 'active_in_window' => 3, 'rate' => 0.3333]);
    expect(e34_an(7)['returners']['active_in_window'])->toBe(2);
});

test('Z9-06: event participation counts distinct people, not registrations, and ignores community or non-official play', function () {
    $a = e34_user();
    $b = e34_user();
    $e1 = e17Event(['title' => 'منافسة 1', 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2)]);
    $e2 = e17Event(['title' => 'منافسة 2', 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2)]);

    foreach ([[$e1, $a], [$e2, $a], [$e1, $b]] as [$e, $u]) {
        CompetitiveEventParticipant::query()->create(['competitive_event_id' => $e->id, 'user_id' => $u->id, 'status' => 'registered', 'registered_at' => now()->subDay()]);
    }

    expect(e34_an()['event_participation'])->toMatchArray(['competitive_registrations' => 3, 'competitive_users' => 2, 'campaign_users' => 0]);
});

test('Z9-07: analytics are aggregates only - no identities, clamped windows, cached and flushed explicitly', function () {
    $u = e34_user(['email' => 'secret.person@example.test', 'name' => 'اسم سري']);
    e34_play($u, now()->subDays(2)->toDateTimeString());
    e34_streak($u, 3, 3, now()->toDateString());

    $json = json_encode(e34_an());
    expect($json)->not->toContain('secret.person')->not->toContain('اسم سري')->not->toContain('"user_id"')->not->toContain('"email"');

    expect(app(RetentionAnalytics::class)->overview(0)['window_days'])->toBe(1)
        ->and(app(RetentionAnalytics::class)->overview(99999)['window_days'])->toBe(180)
        ->and(app(RetentionAnalytics::class)->overview(-4)['window_days'])->toBe(1);

    config(['liveops.analytics.cache_seconds' => 120]);
    RetentionAnalytics::flush();
    $first = app(RetentionAnalytics::class)->overview(30);
    e34_play(e34_user(), now()->subDay()->toDateTimeString());
    expect(app(RetentionAnalytics::class)->overview(30)['returners'])->toBe($first['returners']);   // من الكاش
    RetentionAnalytics::flush();
    expect(app(RetentionAnalytics::class)->overview(30)['returners']['active_in_window'])->toBe($first['returners']['active_in_window'] + 1);
});

test('Z9-08: sign-in alone is never meaningful play - only correct official attempts move retention and returners', function () {
    $u = e34_user();
    DB::table('users')->where('id', $u->id)->update(['created_at' => now()->subDays(10)]);
    $this->actingAs($u)->get('/missions')->assertOk(); // "دخول" وتصفح

    $wrong = Puzzle::factory()->create();
    PuzzleAttempt::query()->forceCreate(['user_id' => $u->id, 'puzzle_id' => $wrong->id, 'attempt_number' => 1, 'is_correct' => false, 'used_hint' => false, 'created_at' => now()->subDays(9), 'updated_at' => now()->subDays(9)]);

    $a = e34_an();
    expect($a['retention']['d1']['retained'])->toBe(0)->and($a['returners']['active_in_window'])->toBe(0);
});
