<?php

use App\Models\MissionPool;
use App\Models\QuestDefinition;
use App\Models\User;
use App\Models\UserMissionAssignment;
use App\Services\Engagement\QuestPeriodService;
use App\Services\LiveOps\MissionAssignmentService;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/_support.php';

beforeEach(function () {
    e34_seed();
    Carbon::setTestNow(Carbon::parse('2026-10-14 09:00:00', 'UTC'));
});
afterEach(fn () => Carbon::setTestNow());

function e34_row(User $u, int $poolId, int $questId, string $key, int $slot = 0): array
{
    return ['user_id' => $u->id, 'mission_pool_id' => $poolId, 'quest_definition_id' => $questId, 'period_key' => $key, 'slot' => $slot, 'assigned_at' => now(), 'created_at' => now(), 'updated_at' => now()];
}

test('Z10-01: the database itself forbids the same mission twice for a player in a period', function () {
    $u = e34_user();
    $pool = e34_pool(e34_quests(2), []);
    $quest = $pool->quests()->first();

    DB::table('user_mission_assignments')->insert(e34_row($u, $pool->id, $quest->id, 'daily:2026-10-14'));

    expect(fn () => DB::table('user_mission_assignments')->insert(e34_row($u, $pool->id, $quest->id, 'daily:2026-10-14')))->toThrow(UniqueConstraintViolationException::class);

    // نفس المهمة بفترة أخرى أو مستخدم آخر مسموح
    DB::table('user_mission_assignments')->insert(e34_row($u, $pool->id, $quest->id, 'daily:2026-10-15'));
    DB::table('user_mission_assignments')->insert(e34_row(e34_user(), $pool->id, $quest->id, 'daily:2026-10-14'));
    expect(UserMissionAssignment::query()->count())->toBe(3);
});

test('Z10-02: repeated assignment is idempotent and never adds rows once a snapshot exists', function () {
    $u = e34_user();
    $pool = e34_pool(e34_quests(5), ['selection_count' => 3]);
    $svc = app(MissionAssignmentService::class);
    $ctx = app(QuestPeriodService::class)->dailyContext();
    $snapshot = $svc->snapshot()['pools'][$pool->id];

    $a = $svc->assignWithinLock($u, $snapshot, $ctx);
    $b = app(MissionAssignmentService::class)->assignWithinLock($u, $snapshot, $ctx);
    $c = app(MissionAssignmentService::class)->assignedQuestIds($u, $ctx);

    expect($a)->toHaveCount(3)->and($b)->toBe($a)->and($c)->toBe($a)->and(UserMissionAssignment::query()->where('user_id', $u->id)->count())->toBe(3);
});

test('Z10-03: a competing writer that stores the same deterministic selection between the check and the insert causes no duplicates', function () {
    $u = e34_user();
    $pool = e34_pool(e34_quests(6), ['selection_count' => 3]);
    $svc = app(MissionAssignmentService::class);
    $ctx = app(QuestPeriodService::class)->dailyContext();
    $snapshot = $svc->snapshot()['pools'][$pool->id];

    $winnerRows = collect($svc->select($u, $snapshot, $ctx))->values()->map(fn ($q, $i) => e34_row($u, $pool->id, $q->id, $ctx->periodKey, $i))->all();

    $fired = false;
    DB::listen(function ($q) use (&$fired, $winnerRows) {
        // أول قراءة لجدول الإسناد داخل القفل هي إعادة الفحص؛ بعدها "يفوز" كاتب آخر بالإدراج قبل إدراجنا.
        if (! $fired && str_starts_with(strtolower($q->sql), 'select') && str_contains($q->sql, 'user_mission_assignments')) {
            $fired = true;
            DB::table('user_mission_assignments')->insert($winnerRows);
        }
    });

    $ids = app(MissionAssignmentService::class)->assignWithinLock($u, $snapshot, $ctx);

    expect($fired)->toBeTrue()->and($ids)->toHaveCount(3)->and(UserMissionAssignment::query()->where('user_id', $u->id)->count())->toBe(3);
});

test('Z10-04: when another writer already assigned the period, the in-lock recheck returns their rows and adds nothing', function () {
    $u = e34_user();
    $pool = e34_pool(e34_quests(6), ['selection_count' => 2]);
    $svc = app(MissionAssignmentService::class);
    $ctx = app(QuestPeriodService::class)->dailyContext();
    $other = $pool->quests()->orderByDesc('quest_definitions.id')->limit(2)->get();

    foreach ($other as $i => $q) {
        DB::table('user_mission_assignments')->insert(e34_row($u, $pool->id, $q->id, $ctx->periodKey, $i));
    }

    $ids = $svc->assignWithinLock($u, $svc->snapshot()['pools'][$pool->id], $ctx);

    expect($ids)->toEqualCanonicalizing($other->pluck('id')->all())->and(UserMissionAssignment::query()->where('user_id', $u->id)->count())->toBe(2);
});

test('Z10-05: the assignment locks the player row inside a transaction before it reads or writes assignments', function () {
    $u = e34_user();
    $pool = e34_pool(e34_quests(3), ['selection_count' => 2]);
    $svc = app(MissionAssignmentService::class);
    $ctx = app(QuestPeriodService::class)->dailyContext();
    $log = [];
    $base = DB::transactionLevel(); // الاختبارات تعمل داخل معاملة RefreshDatabase؛ المقارنة نسبية لها

    DB::listen(function ($q) use (&$log) {
        $sql = strtolower($q->sql);

        if (str_contains($sql, 'from "users"') || str_contains($sql, 'from `users`')) {
            $log[] = ['users', DB::transactionLevel()];
        } elseif (str_contains($sql, 'user_mission_assignments')) {
            $log[] = [str_starts_with($sql, 'select') ? 'read' : 'write', DB::transactionLevel()];
        }
    });

    $svc->assignWithinLock($u, $svc->snapshot()['pools'][$pool->id], $ctx);

    expect($log[0])->toBe(['users', $base + 1])->and(collect($log)->every(fn ($e) => $e[1] >= $base + 1))->toBeTrue()
        ->and(collect($log)->pluck(0)->all())->toContain('write');
});

test('Z10-06: delete rules - restrict on pools with history, cascade on users, quests and pool membership', function () {
    $u = e34_user();
    $pool = e34_pool(e34_quests(3), ['selection_count' => 2]);
    e34_visible_ids($u);
    expect(UserMissionAssignment::query()->where('user_id', $u->id)->count())->toBe(2);

    expect(fn () => DB::table('mission_pools')->where('id', $pool->id)->delete())->toThrow(QueryException::class);

    $assignedQuest = UserMissionAssignment::query()->where('user_id', $u->id)->value('quest_definition_id');
    QuestDefinition::query()->whereKey($assignedQuest)->first()->delete();   // لا تقدّم بعد ⇒ يُسمح بحذفها
    expect(UserMissionAssignment::query()->where('quest_definition_id', $assignedQuest)->count())->toBe(0)
        ->and(DB::table('mission_pool_quests')->where('quest_definition_id', $assignedQuest)->count())->toBe(0);

    DB::table('users')->where('id', $u->id)->delete();
    expect(UserMissionAssignment::query()->where('user_id', $u->id)->count())->toBe(0)->and(MissionPool::query()->whereKey($pool->id)->exists())->toBeTrue();
});

test('Z10-07: the E34 tables carry exactly the planned unique constraints and covering indexes', function () {
    $idx = fn (string $t) => collect(Schema::getIndexes($t))->keyBy('name');
    $a = $idx('user_mission_assignments');
    $p = $idx('mission_pool_quests');
    $m = $idx('mission_pools');

    expect($a['user_mission_assignments_user_quest_period_unique']['unique'])->toBeTrue()
        ->and($a['user_mission_assignments_user_quest_period_unique']['columns'])->toBe(['user_id', 'quest_definition_id', 'period_key'])
        ->and($a['user_mission_assignments_user_period_idx']['columns'])->toBe(['user_id', 'period_key'])
        ->and($a['user_mission_assignments_pool_period_idx']['columns'])->toBe(['mission_pool_id', 'period_key'])
        ->and($p['mission_pool_quests_pool_quest_unique']['unique'])->toBeTrue()
        ->and($m['mission_pools_active_period_priority_idx']['columns'])->toBe(['is_active', 'period_type', 'priority']);

    foreach ([$a, $p, $m] as $set) {
        foreach ($set->keys() as $name) {
            expect(strlen($name))->toBeLessThanOrEqual(64);
        }
    }
});

test('Z10-08: [MySQL] referential actions, engine, charset and key lengths are the intended production ones', function () {
    $db = DB::connection();
    $schema = $db->getDatabaseName();

    $rules = collect($db->select('select TABLE_NAME t, REFERENCED_TABLE_NAME r, DELETE_RULE d from information_schema.REFERENTIAL_CONSTRAINTS where CONSTRAINT_SCHEMA = ? and TABLE_NAME in (?, ?, ?)', [$schema, 'user_mission_assignments', 'mission_pool_quests', 'mission_pools']))
        ->mapWithKeys(fn ($r) => [$r->t.'>'.$r->r => $r->d]);

    expect($rules['user_mission_assignments>users'])->toBe('CASCADE')
        ->and($rules['user_mission_assignments>mission_pools'])->toBe('RESTRICT')
        ->and($rules['user_mission_assignments>quest_definitions'])->toBe('CASCADE')
        ->and($rules['mission_pool_quests>mission_pools'])->toBe('CASCADE')
        ->and($rules['mission_pool_quests>quest_definitions'])->toBe('CASCADE')
        ->and($rules['mission_pools>users'])->toBe('SET NULL');

    $tables = collect($db->select('select TABLE_NAME t, ENGINE e, TABLE_COLLATION c from information_schema.TABLES where TABLE_SCHEMA = ? and TABLE_NAME in (?, ?, ?)', [$schema, 'user_mission_assignments', 'mission_pool_quests', 'mission_pools']));
    foreach ($tables as $t) {
        expect($t->e)->toBe('InnoDB')->and($t->c)->toStartWith('utf8mb4');
    }

    $cols = collect($db->select('select COLUMN_NAME n, COLUMN_TYPE t from information_schema.COLUMNS where TABLE_SCHEMA = ? and TABLE_NAME = ?', [$schema, 'user_mission_assignments']))->pluck('t', 'n');
    expect($cols['period_key'])->toBe('varchar(32)')->and($cols['slot'])->toStartWith('tinyint');
})->skip(fn () => DB::getDriverName() !== 'mysql', 'MySQL only');

test('Z10-09: [MySQL] insert-ignore on the unique key keeps exactly one row and the row lock statement is really issued', function () {
    $u = e34_user();
    $pool = e34_pool(e34_quests(2), []);
    $quest = $pool->quests()->first();
    $row = e34_row($u, $pool->id, $quest->id, 'daily:2026-10-14');

    UserMissionAssignment::query()->insertOrIgnore([$row]);
    UserMissionAssignment::query()->insertOrIgnore([$row]);
    expect(UserMissionAssignment::query()->count())->toBe(1);

    $sql = [];
    DB::listen(function ($q) use (&$sql) {
        $sql[] = strtolower($q->sql);
    });
    $svc = app(MissionAssignmentService::class);
    $svc->assignWithinLock(e34_user(), $svc->snapshot()['pools'][$pool->id], app(QuestPeriodService::class)->dailyContext());

    expect(collect($sql)->contains(fn ($s) => str_contains($s, 'for update') && str_contains($s, '`users`')))->toBeTrue()
        ->and(collect($sql)->contains(fn ($s) => str_contains($s, 'insert ignore into `user_mission_assignments`')))->toBeTrue();
})->skip(fn () => DB::getDriverName() !== 'mysql', 'MySQL only');
