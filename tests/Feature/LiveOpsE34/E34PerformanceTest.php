<?php

use App\Models\Campaign;
use App\Models\QuestDefinition;
use App\Models\User;
use App\Services\Engagement\QuestPeriodService;
use App\Services\LiveOps\Calendar\EventCalendarService;
use App\Services\LiveOps\LiveOpsPlannerService;
use App\Services\LiveOps\MissionAssignmentService;
use App\Services\LiveOps\MissionCenterService;
use App\Services\LiveOps\RetentionAnalytics;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/_support.php';

beforeEach(fn () => e34_seed());

/** @return array{0:int,1:int} [كل الاستعلامات، المنسوبة لجداول E34 فقط] */
function e34_split_queries(\Closure $fn): array
{
    $all = 0;
    $e34 = 0;
    DB::listen(function ($q) use (&$all, &$e34) {
        $all++;
        if (preg_match('/mission_pool|user_mission_assignments/', $q->sql)) {
            $e34++;
        }
    });
    $fn();

    return [$all, $e34];
}

test('Z11-01: the E34 share of a mission page load is a small constant independent of pool size', function () {
    $small = e34_user();
    $big = e34_user();

    e34_pool(e34_quests(4), ['selection_count' => 2]);
    [, $smallPool] = e34_split_queries(fn () => $this->actingAs($small)->get('/missions')->assertOk());

    e34_pool(e34_quests(30), ['selection_count' => 2, 'priority' => 1]);
    [, $bigPool] = e34_split_queries(fn () => $this->actingAs($big)->get('/missions')->assertOk());

    expect($smallPool)->toBeLessThanOrEqual(12)->and($bigPool)->toBeLessThanOrEqual(12)->and(abs($bigPool - $smallPool))->toBeLessThanOrEqual(2);
});

test('Z11-02: no pools means almost no extra work - the pool snapshot costs at most two reads and none once cached', function () {
    e34_quests(3);
    $u = e34_user();

    [, $none] = e34_split_queries(fn () => $this->actingAs($u)->get('/missions')->assertOk());
    expect($none)->toBeLessThanOrEqual(2);

    config(['liveops.pools.cache_seconds' => 300]);
    MissionAssignmentService::flushSnapshot();
    app(MissionAssignmentService::class)->snapshot();

    [, $cached] = e34_split_queries(fn () => app(MissionAssignmentService::class)->snapshot()); // مثيل جديد، كاش دافئ
    expect($cached)->toBe(0);

    $raw = json_encode(Cache::get(MissionAssignmentService::CACHE_KEY));
    expect($raw)->not->toContain('user_id')->not->toContain('email');
});

test('Z11-03: repeated visibility checks for one player and period reuse the assignment lookup (memoised), not one query per mission', function () {
    $pool = e34_pool(e34_quests(15), ['selection_count' => 3]);
    $quests = QuestDefinition::query()->get();
    $svc = app(MissionAssignmentService::class);
    $ctx = app(QuestPeriodService::class)->dailyContext();
    $u = e34_user();

    $n = e34_count_queries(function () use ($quests, $svc, $u, $ctx) {
        foreach ($quests as $q) {
            $svc->isAllowed($u, $q, $ctx);
        }
    });

    expect($n)->toBeLessThanOrEqual(12)->and($pool->fresh()->is_active)->toBeTrue();
});

test('Z11-04: the page cost per visible mission stays within the existing E13 self-healing budget (regression guard, no N+1 added by E34)', function () {
    $costs = [];

    foreach ([3, 12] as $n) {
        QuestDefinition::query()->update(['is_active' => false]); // لا حذف: صفوف التقدّم تشير إليها (FK)
        e34_quests($n);
        $u = e34_user();
        $this->actingAs($u)->get('/missions')->assertOk();                                   // يُنشئ صفوف التقدّم
        $costs[$n] = e34_count_queries(fn () => $this->actingAs($u)->get('/missions')->assertOk());  // الفتح الدافئ
    }

    $perQuest = ($costs[12] - $costs[3]) / 9;
    expect($perQuest)->toBeLessThanOrEqual(5.0)->and($costs[3])->toBeLessThanOrEqual(70);
});

test('Z11-05: the home widget and dashboard cost the same whether there are 3 or 20 missions', function () {
    $counts = [];

    foreach ([3, 20] as $n) {
        QuestDefinition::query()->update(['is_active' => false]); // لا حذف: صفوف التقدّم تشير إليها (FK)
        e34_quests($n);
        $u = e34_user();
        $this->actingAs($u)->get('/')->assertOk(); // إحماء (كاشات الإعدادات/الجلسة) كي لا يُحسب على أول قياس
        $counts[$n] = [
            e34_count_queries(fn () => app(MissionCenterService::class)->widgetFor($u)),
            e34_count_queries(fn () => $this->actingAs($u)->get('/')->assertOk()),
        ];
    }

    expect($counts[3][0])->toBeLessThanOrEqual(20)->and(abs($counts[20][0] - $counts[3][0]))->toBeLessThanOrEqual(1)
        ->and(abs($counts[20][1] - $counts[3][1]))->toBeLessThanOrEqual(3);
});

test('Z11-06: the calendar is one cached snapshot - constant uncached cost, zero cached cost, no per-event queries', function () {
    $uncached = [];
    $page = [];

    foreach ([5, 30] as $n) {
        Campaign::query()->delete();
        foreach (range(1, $n) as $_) {
            Campaign::withoutEvents(fn () => Campaign::factory()->create(['starts_at' => now()->subDay(), 'ends_at' => now()->addDays(3)]));
        }

        config(['liveops.calendar.cache_seconds' => 0]);
        $uncached[$n] = e34_count_queries(fn () => app(EventCalendarService::class)->items());

        config(['liveops.calendar.cache_seconds' => 60]);
        EventCalendarService::flush();
        app(EventCalendarService::class)->items();
        expect(e34_count_queries(fn () => app(EventCalendarService::class)->items()))->toBe(0);
        $this->get('/events')->assertOk(); // إحماء
        $page[$n] = e34_count_queries(fn () => $this->get('/events')->assertOk());
    }

    expect($uncached[5])->toBeLessThanOrEqual(8)->and($uncached[30])->toBe($uncached[5])
        ->and($page[5])->toBeLessThanOrEqual(12)->and($page[30])->toBe($page[5]);
});

test('Z11-07: the planner and the analytics page issue a constant number of queries regardless of data volume', function () {
    $planner = [];
    $analytics = [];

    foreach ([3, 30] as $n) {
        QuestDefinition::query()->update(['is_active' => false]); // لا حذف: صفوف التقدّم تشير إليها (FK)
        e34_quests($n);
        $planner[$n] = e34_count_queries(fn () => app(LiveOpsPlannerService::class)->overview());
    }

    foreach ([10, 60] as $n) {
        foreach (range(1, $n) as $_) {
            e34_streak(e34_user(), 2, 2, now()->toDateString());
        }
        RetentionAnalytics::flush();
        $analytics[$n] = e34_count_queries(fn () => app(RetentionAnalytics::class)->overview(30));
    }

    expect($planner[3])->toBeLessThanOrEqual(15)->and($planner[30])->toBe($planner[3])
        ->and($analytics[10])->toBeLessThanOrEqual(30)->and($analytics[60])->toBe($analytics[10]);
});

test('Z11-08: scale smoke - a hundred players are assigned from a large pool with bounded writes and no duplicate rows', function () {
    $pool = e34_pool(e34_quests(40), ['selection_count' => 3]);
    $users = collect(range(1, 100))->map(fn () => e34_user());

    foreach ($users as $u) {
        e34_visible_ids($u);
    }

    $rows = DB::table('user_mission_assignments');
    expect((clone $rows)->count())->toBe(300)
        ->and((clone $rows)->select('user_id', 'quest_definition_id', 'period_key')->distinct()->get()->count())->toBe(300)
        ->and(DB::table('user_mission_assignments')->where('mission_pool_id', $pool->id)->count())->toBe(300);
});
