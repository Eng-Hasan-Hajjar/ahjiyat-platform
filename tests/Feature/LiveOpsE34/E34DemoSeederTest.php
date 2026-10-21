<?php

use App\Models\Campaign;
use App\Models\CurrencyTransaction;
use App\Models\GemTransaction;
use App\Models\MissionPool;
use App\Models\PlayerStreak;
use App\Models\QuestDefinition;
use App\Models\User;
use Database\Seeders\DemoE34Seeder;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/_support.php';

beforeEach(fn () => e34_seed());

/** @return array<string,int> */
function e34_demo_counts(): array
{
    return [
        'users' => User::query()->count(),
        'quests' => QuestDefinition::query()->count(),
        'pools' => MissionPool::query()->count(),
        'members' => DB::table('mission_pool_quests')->count(),
        'campaigns' => Campaign::query()->count(),
        'streaks' => PlayerStreak::query()->count(),
        'assignments' => DB::table('user_mission_assignments')->count(),
        'audit' => DB::table('operational_audit_logs')->count(),
    ];
}

test('Z12-01: the demo seeder is idempotent - a second and third run change no table and create no duplicates', function () {
    $this->seed(DemoE34Seeder::class);
    $first = e34_demo_counts();
    $this->seed(DemoE34Seeder::class);
    $this->seed(DemoE34Seeder::class);

    expect(e34_demo_counts())->toBe($first)
        ->and(MissionPool::query()->where('slug', 'demo-e34-daily-pool')->count())->toBe(1)
        ->and(QuestDefinition::query()->where('internal_key', 'like', 'demo_e34_%')->count())->toBe(6)
        ->and(Campaign::query()->whereIn('slug', ['demo-e34-live', 'demo-e34-upcoming'])->count())->toBe(2)
        ->and(User::query()->whereIn('email', [DemoE34Seeder::ADMIN_EMAIL, DemoE34Seeder::STREAK_EMAIL, DemoE34Seeder::COMEBACK_EMAIL])->count())->toBe(3);
});

test('Z12-02: the seeded pool is active with four members and was activated through the official service (audit entries exist), with no currency or XP written', function () {
    $this->seed(DemoE34Seeder::class);
    $pool = MissionPool::query()->where('slug', 'demo-e34-daily-pool')->firstOrFail();

    expect($pool->is_active)->toBeTrue()
        ->and((int) $pool->selection_count)->toBe(2)
        ->and(DB::table('mission_pool_quests')->where('mission_pool_id', $pool->id)->count())->toBe(4)
        ->and(DB::table('operational_audit_logs')->where('action', 'mission_pool_activated')->count())->toBe(1)
        ->and(GemTransaction::query()->count())->toBe(0)
        ->and(CurrencyTransaction::query()->count())->toBe(0);
});

test('Z12-03: demo accounts are .test addresses and the seeder refuses to run in production', function () {
    $this->seed(DemoE34Seeder::class);

    foreach ([DemoE34Seeder::ADMIN_EMAIL, DemoE34Seeder::STREAK_EMAIL, DemoE34Seeder::COMEBACK_EMAIL] as $email) {
        expect($email)->toEndWith('@liveops.test');
    }

    app()['env'] = 'production';

    try {
        expect(fn () => (new DemoE34Seeder)->run())->toThrow(RuntimeException::class);
    } finally {
        app()['env'] = 'testing';
    }
});

test('Z12-04: the demo scenarios actually render - admin planner, streak player sees an active streak, comeback player sees the comeback card', function () {
    $this->seed(DemoE34Seeder::class);

    $admin = User::query()->where('email', DemoE34Seeder::ADMIN_EMAIL)->firstOrFail();
    $this->actingAs($admin)->get('/admin/liveops-planner')->assertOk();
    $this->actingAs($admin)->get('/admin/mission-pools')->assertOk();

    $streak = User::query()->where('email', DemoE34Seeder::STREAK_EMAIL)->firstOrFail();
    $this->actingAs($streak)->get('/missions')->assertOk()->assertSee('7');

    $comeback = User::query()->where('email', DemoE34Seeder::COMEBACK_EMAIL)->firstOrFail();
    $this->actingAs($comeback)->get('/missions')->assertOk()->assertSee('مرحبًا بعودتك');

    $this->get('/events')->assertOk()->assertSee('حملة تجريبية جارية')->assertSee('حملة تجريبية قادمة');
});
