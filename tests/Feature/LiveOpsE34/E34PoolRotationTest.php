<?php

use App\Models\PlayerProgression;
use App\Models\PuzzleCategory;
use App\Models\QuestDefinition;
use App\Models\UserMissionAssignment;
use App\Services\Engagement\QuestPeriodService;
use App\Services\Engagement\QuestService;
use App\Services\LiveOps\LiveOpsException;
use App\Services\LiveOps\MissionAssignmentService;
use App\Services\LiveOps\MissionPoolService;
use Illuminate\Support\Carbon;

require_once __DIR__.'/_support.php';

beforeEach(function () {
    e34_seed();
    Carbon::setTestNow(Carbon::parse('2026-10-14 09:00:00', 'UTC')); // أربعاء: الأسبوع 42
});
afterEach(fn () => Carbon::setTestNow());

function e34_assigned(\App\Models\User $u, ?string $key = null): array
{
    return UserMissionAssignment::query()->where('user_id', $u->id)
        ->when($key, fn ($q) => $q->where('period_key', $key))
        ->orderBy('quest_definition_id')->pluck('quest_definition_id')->map(fn ($i) => (int) $i)->all();
}

test('Z2-10: a new pool is created inactive, and activating an empty pool is refused with a clear reason', function () {
    $admin = e34_admin();
    $pool = e34_pool([], [], false, $admin);

    expect($pool->is_active)->toBeFalse();

    try {
        app(MissionPoolService::class)->activate($admin, $pool);
        $this->fail('activation of an empty pool must be refused');
    } catch (LiveOpsException $e) {
        expect($e->reason)->toBe('empty_pool');
    }

    expect($pool->fresh()->is_active)->toBeFalse();
});

test('Z2-11: pooled missions are invisible to everyone until assigned, including while their pool is inactive; global ones are unaffected', function () {
    $u = e34_user();
    $global = e34_quest();
    [$a, $b] = e34_quests(2);
    e34_pool([$a, $b], [], false); // معطّلة

    expect(e34_visible_ids($u))->toBe([$global->id])
        ->and(e34_visible_via_isVisible($u))->toBe([$global->id])
        ->and(UserMissionAssignment::query()->count())->toBe(0);
});

test('Z2-12: an active pool assigns exactly selection_count missions per user, deterministically across fresh service instances', function () {
    $u = e34_user();
    $pool = e34_pool(e34_quests(6), ['selection_count' => 3]);
    $period = app(QuestPeriodService::class)->dailyContext();
    $snapshot = app(MissionAssignmentService::class)->snapshot()['pools'][$pool->id];

    $picked1 = array_map(fn ($q) => $q->id, app(MissionAssignmentService::class)->select($u, $snapshot, $period));
    $picked2 = array_map(fn ($q) => $q->id, app(MissionAssignmentService::class)->select($u, $snapshot, $period));
    $assigned = app(MissionAssignmentService::class)->assignedQuestIds($u, $period);

    expect($picked1)->toBe($picked2)->and($assigned)->toHaveCount(3)->and($assigned)->toEqualCanonicalizing($picked1);
    expect(app(MissionAssignmentService::class)->assignedQuestIds($u, $period))->toBe($assigned); // فتح ثانٍ: لا صفوف جديدة
    expect(e34_assigned($u))->toHaveCount(3);
});

test('Z2-13: different users receive different selections from the same pool, each with the configured size', function () {
    $pool = e34_pool(e34_quests(8), ['selection_count' => 3]);
    $sets = [];

    foreach (range(1, 12) as $_) {
        $u = e34_user();
        $ids = e34_visible_ids($u);
        expect($ids)->toHaveCount(3);
        $sets[implode(',', $ids)] = true;
    }

    expect(count($sets))->toBeGreaterThan(1)->and($pool->fresh()->is_active)->toBeTrue();
});

test('Z2-14: a snapshot is stable mid-period against pool edits and deactivation, and the next period picks up the new pool state', function () {
    $u = e34_user();
    $pool = e34_pool(e34_quests(4), ['selection_count' => 2]);
    $before = e34_visible_ids($u);
    $key = app(QuestPeriodService::class)->dailyContext()->periodKey;

    $admin = e34_admin();
    $svc = app(MissionPoolService::class);
    $newcomer = e34_quest();
    $svc->attach($admin, $pool, $newcomer);
    $svc->update($admin, $pool, ['name' => 'اسم جديد', 'slug' => $pool->slug, 'period_type' => 'daily', 'selection_count' => 4]);
    $svc->deactivate($admin, $pool);

    expect(e34_visible_ids($u))->toBe($before)->and(e34_assigned($u, $key))->toEqualCanonicalizing($before);

    // اليوم التالي: المجموعة معطّلة فلا إسناد جديد من مجموعة (المهام المجمّعة لا تظهر)
    Carbon::setTestNow(Carbon::parse('2026-10-15 09:00:00', 'UTC'));
    expect(e34_visible_ids($u))->toBe([]);

    $svc->activate($admin, $pool->fresh());
    $next = e34_visible_ids($u);
    expect($next)->toHaveCount(4)->and(e34_assigned($u, 'daily:2026-10-15'))->toHaveCount(4);
});

test('Z2-15: global missions stay visible next to the pool picks (backward compatible)', function () {
    $u = e34_user();
    $global = e34_quests(2);
    e34_pool(e34_quests(5), ['selection_count' => 2]);

    $ids = e34_visible_ids($u);

    expect($ids)->toHaveCount(4)->and(array_values(array_intersect($ids, array_map(fn ($q) => $q->id, $global))))->toHaveCount(2);
});

test('Z2-16: with no pools nothing is assigned and no assignment query path is needed for plain missions', function () {
    $u = e34_user();
    e34_quests(3);

    expect(e34_visible_ids($u))->toHaveCount(3)->and(UserMissionAssignment::query()->count())->toBe(0);

    $svc = app(MissionAssignmentService::class);
    $plain = QuestDefinition::query()->first();
    expect($svc->isPooled($plain))->toBeFalse()->and($svc->isAllowed($u, $plain, app(QuestPeriodService::class)->dailyContext()))->toBeTrue();
});

test('Z2-17: eligibility rules route players to the first pool they qualify for, with the "all" pool as a fallback', function () {
    $newbie = e34_user([], 3);
    $veteran = e34_user([], 40);
    $returner = e34_user([], 60);
    e34_streak($returner, 2, 9, now()->subDays(10)->toDateString());
    $expert = e34_user([], 60);
    PlayerProgression::factory()->create(['user_id' => $expert->id, 'current_level' => 6]);

    $np = e34_pool(e34_quests(2), ['eligibility_rule' => 'new_player', 'priority' => 10, 'selection_count' => 2]);
    $rt = e34_pool(e34_quests(2), ['eligibility_rule' => 'returning', 'priority' => 20, 'selection_count' => 2]);
    $ex = e34_pool(e34_quests(2), ['eligibility_rule' => 'experienced', 'priority' => 30, 'selection_count' => 2]);
    $all = e34_pool(e34_quests(2), ['eligibility_rule' => 'all', 'priority' => 999, 'selection_count' => 2]);

    $poolOf = function ($u) {
        e34_visible_ids($u);

        return UserMissionAssignment::query()->where('user_id', $u->id)->distinct()->pluck('mission_pool_id')->map(fn ($i) => (int) $i)->all();
    };

    expect($poolOf($newbie))->toBe([$np->id])
        ->and($poolOf($returner))->toBe([$rt->id])
        ->and($poolOf($expert))->toBe([$ex->id])
        ->and($poolOf($veteran))->toBe([$all->id]);
});

test('Z2-18: a pool outside its own window (not yet started, or already ended) is never used', function () {
    $u = e34_user();
    e34_pool(e34_quests(2), ['starts_at' => now()->addDay(), 'priority' => 1]);
    e34_pool(e34_quests(2), ['ends_at' => now()->subHour(), 'starts_at' => now()->subDays(3), 'priority' => 2]);

    expect(e34_visible_ids($u))->toBe([])->and(UserMissionAssignment::query()->count())->toBe(0);

    $live = e34_pool(e34_quests(2), ['starts_at' => now()->subHour(), 'ends_at' => now()->addDay(), 'priority' => 3]);
    expect(e34_visible_ids($u))->toHaveCount(2)->and(UserMissionAssignment::query()->first()->mission_pool_id)->toBe($live->id);
});

test('Z2-19: only active, in-window missions of the right period are selectable', function () {
    $u = e34_user();
    $ok = e34_quest();
    $inactive = e34_quest(['is_active' => false]);
    $future = e34_quest(['starts_at' => now()->addDay()]);
    $expired = e34_quest(['ends_at' => now()->subHour()]);
    e34_pool([$ok, $inactive, $future, $expired], ['selection_count' => 4]);

    expect(e34_visible_ids($u))->toBe([$ok->id]);
});

test('Z2-20: transparent personalization prefers open progress and recently played official categories, deterministically', function () {
    $category = PuzzleCategory::factory()->create();
    $plain = e34_quests(3, ['target_value' => 3]);
    $scoped = QuestDefinition::factory()->daily(3)->puzzlesSolvedInCategory($category->id, 3)->create();
    $pool = e34_pool([...$plain, $scoped], ['selection_count' => 1, 'personalized' => true]);

    // فئة رسمية لُعبت مؤخرًا ⇒ +2 للمهمة المرتبطة بها (الحلّ نفسه هو ما يُطلق الإسناد الكسول)
    $solver = e34_user();
    e34_solve($solver, $category);
    expect(e34_visible_ids($solver))->toBe([$scoped->id]);

    // تقدّم مفتوح قائم (+3) على مهمة عادية بدأها المستخدم قبل الإسناد يُفضَّل بلا أي إشارة أخرى
    $opener = e34_user();
    $open = $plain[1];
    $progress = app(QuestService::class)->progressFor($opener, $open, app(QuestPeriodService::class)->dailyContext());
    \App\Models\UserQuestProgress::query()->whereKey($progress->id)->update(['current_value' => 1]);

    expect(app(MissionAssignmentService::class)->assignedQuestIds($opener, app(QuestPeriodService::class)->dailyContext()))->toBe([$open->id]);

    // التخصيص مُطفأ على المجموعة: اختيار حتمي بالـhash فقط
    app(MissionPoolService::class)->update(e34_admin(), $pool, ['name' => $pool->name, 'slug' => $pool->slug, 'period_type' => 'daily', 'personalized' => false]);
    $other = e34_user();
    $snapshot = app(MissionAssignmentService::class)->snapshot()['pools'][$pool->id];
    $ctx = app(QuestPeriodService::class)->dailyContext();
    $a = array_map(fn ($q) => $q->id, app(MissionAssignmentService::class)->select($other, $snapshot, $ctx));
    $b = array_map(fn ($q) => $q->id, app(MissionAssignmentService::class)->select($other, $snapshot, $ctx));

    expect($a)->toBe($b)->toHaveCount(1)->and($snapshot['personalized'])->toBeFalse();
});

test('Z2-21: the first eligible pool wins for the period and a pool with no valid candidates is skipped', function () {
    $u = e34_user();
    $dead = e34_pool([e34_quest(['is_active' => false])], ['priority' => 1, 'selection_count' => 1]);
    $good = e34_pool(e34_quests(3), ['priority' => 2, 'selection_count' => 2]);
    e34_pool(e34_quests(3), ['priority' => 3, 'selection_count' => 2]);

    e34_visible_ids($u);

    expect(UserMissionAssignment::query()->where('user_id', $u->id)->distinct()->pluck('mission_pool_id')->all())->toBe([$good->id])
        ->and($dead->fresh()->is_active)->toBeTrue();
});

test('Z2-22: weekly pools rotate independently of daily ones and keep one snapshot for the whole week', function () {
    $u = e34_user();
    $weeklyPool = e34_pool([e34_weekly(), e34_weekly(), e34_weekly()], ['period_type' => 'weekly', 'selection_count' => 2]);
    $dailyPool = e34_pool(e34_quests(2), ['selection_count' => 1]);

    $week1 = e34_visible_ids($u, 'weekly');
    Carbon::setTestNow(Carbon::parse('2026-10-18 20:00:00', 'UTC')); // الأحد نفس الأسبوع (يبدأ الإثنين)
    expect(e34_visible_ids($u, 'weekly'))->toBe($week1)->and($week1)->toHaveCount(2);

    Carbon::setTestNow(Carbon::parse('2026-10-19 08:00:00', 'UTC')); // الإثنين: أسبوع جديد
    expect(e34_visible_ids($u, 'weekly'))->toHaveCount(2);
    $rows = UserMissionAssignment::query()->where('user_id', $u->id)->where('mission_pool_id', $weeklyPool->id)->get();
    expect($rows)->toHaveCount(4)->and($rows->pluck('period_key')->unique())->toHaveCount(2)
        ->and(UserMissionAssignment::query()->where('mission_pool_id', $dailyPool->id)->count())->toBe(0);

    expect($weeklyPool->fresh()->period_type)->toBe('weekly');
});
