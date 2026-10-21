<?php

use App\Exceptions\StoreItemInvariantViolation;
use App\Filament\Pages\LiveOpsAnalyticsPage;
use App\Filament\Pages\LiveOpsPlannerPage;
use App\Filament\Resources\MissionPoolResource;
use App\Filament\Resources\MissionPoolResource\Pages\CreateMissionPool;
use App\Filament\Resources\MissionPoolResource\Pages\ListMissionPools;
use App\Filament\Resources\QuestDefinitionResource;
use App\Models\Campaign;
use App\Models\Currency;
use App\Models\MissionPool;
use App\Models\OperationalAuditLog;
use App\Models\QuestDefinition;
use App\Models\UserMissionAssignment;
use App\Services\LiveOps\LiveOpsException;
use App\Services\LiveOps\LiveOpsPlannerService;
use App\Services\LiveOps\MissionPoolService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

require_once __DIR__.'/_support.php';

beforeEach(function () {
    e34_seed();
    Carbon::setTestNow(Carbon::parse('2026-10-14 09:00:00', 'UTC'));
});
afterEach(fn () => Carbon::setTestNow());

function e34_reason(\Closure $fn): ?string
{
    try {
        $fn();
    } catch (LiveOpsException $e) {
        return $e->reason;
    }

    return null;
}

function e34_warning_codes(): array
{
    return collect(app(LiveOpsPlannerService::class)->overview()['warnings'])->pluck('code')->unique()->values()->all();
}

test('Z8-01: permissions are gated by role and policy - view for moderators, manage for managers/admins, never delete', function () {
    $mod = e34_moderator();
    $manager = e34_manager();
    $admin = e34_admin();
    $player = e34_user();
    $pool = e34_pool([], [], false);

    expect(Gate::forUser($player)->allows('viewAny', MissionPool::class))->toBeFalse()
        ->and(Gate::forUser($mod)->allows('viewAny', MissionPool::class))->toBeTrue()
        ->and(Gate::forUser($mod)->allows('create', MissionPool::class))->toBeFalse()
        ->and(Gate::forUser($mod)->allows('update', $pool))->toBeFalse()
        ->and(Gate::forUser($manager)->allows('create', MissionPool::class))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('update', $pool))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('delete', $pool))->toBeFalse()
        ->and(Gate::forUser($manager)->allows('delete', $pool))->toBeFalse();

    expect(e34_reason(fn () => app(MissionPoolService::class)->create($mod, ['name' => 'x', 'slug' => 'x', 'period_type' => 'daily'])))->toBe('forbidden')
        ->and(e34_reason(fn () => app(MissionPoolService::class)->activate($player, $pool)))->toBe('forbidden');
});

test('Z8-02: LiveOps actions are rate limited per admin', function () {
    config(['liveops.limits.action_per_minute' => 3]);
    RateLimiter::clear('liveops-action:x');
    $admin = e34_admin();
    $svc = app(MissionPoolService::class);
    $pool = e34_pool([], [], false, $admin);   // create = 1
    $svc->update($admin, $pool, ['name' => 'a', 'slug' => $pool->slug, 'period_type' => 'daily']); // 2
    $svc->update($admin, $pool, ['name' => 'b', 'slug' => $pool->slug, 'period_type' => 'daily']); // 3

    expect(e34_reason(fn () => $svc->update($admin, $pool, ['name' => 'c', 'slug' => $pool->slug, 'period_type' => 'daily'])))->toBe('rate_limited')
        ->and($pool->fresh()->name)->toBe('b');
});

test('Z8-03: admin screens render for the right roles and are forbidden to players', function () {
    $admin = e34_admin();
    $mod = e34_moderator();
    $player = e34_user();
    $pool = e34_pool(e34_quests(2), []);
    e34_quest(['name' => 'مهمة للقائمة']);

    foreach (['/admin/mission-pools', '/admin/mission-pools/create', "/admin/mission-pools/{$pool->public_id}/edit", '/admin/liveops-planner', '/admin/liveops-analytics', '/admin/quest-definitions'] as $url) {
        $this->flushSession();
        $this->actingAs($admin)->get($url)->assertOk();
    }

    $this->flushSession();
    $this->actingAs($mod)->get('/admin/mission-pools')->assertOk();
    $this->flushSession();
    $this->actingAs($mod)->get('/admin/liveops-planner')->assertOk();
    $this->flushSession();
    $this->actingAs($mod)->get('/admin/mission-pools/create')->assertForbidden();

    foreach (['/admin/mission-pools', '/admin/liveops-planner', '/admin/liveops-analytics'] as $url) {
        $this->flushSession();
        $this->actingAs($player)->get($url)->assertForbidden();
    }
});

test('Z8-04: creating a pool through the Filament form goes through the service - inactive, audited, validated', function () {
    $admin = e34_admin();
    $this->actingAs($admin);

    Livewire::test(CreateMissionPool::class)
        ->fillForm(['name' => 'مجموعة الاختبار', 'slug' => 'bad slug!', 'period_type' => 'daily', 'selection_count' => 3, 'eligibility_rule' => 'all', 'priority' => 100])
        ->call('create')->assertHasFormErrors(['slug']);

    expect(MissionPool::query()->count())->toBe(0);

    Livewire::test(CreateMissionPool::class)
        ->fillForm(['name' => 'مجموعة الاختبار', 'slug' => 'test-pool', 'period_type' => 'daily', 'selection_count' => 3, 'eligibility_rule' => 'all', 'priority' => 100])
        ->call('create')->assertHasNoFormErrors();

    $pool = MissionPool::query()->where('slug', 'test-pool')->first();
    expect($pool)->not->toBeNull()->and($pool->is_active)->toBeFalse()->and($pool->created_by)->toBe($admin->id)
        ->and(OperationalAuditLog::query()->where('action', 'mission_pool_created')->where('actor_user_id', $admin->id)->count())->toBe(1);
});

test('Z8-05: the list screen activates and deactivates pools through the service and reports refusals instead of crashing', function () {
    $admin = e34_admin();
    $this->actingAs($admin);
    $filled = e34_pool(e34_quests(2), [], false, $admin);
    $empty = e34_pool([], [], false, $admin);

    Livewire::test(ListMissionPools::class)
        ->callTableAction('activate', $filled)
        ->assertHasNoTableActionErrors();
    expect($filled->fresh()->is_active)->toBeTrue();

    Livewire::test(ListMissionPools::class)->callTableAction('deactivate', $filled->fresh());
    expect($filled->fresh()->is_active)->toBeFalse();

    Livewire::test(ListMissionPools::class)->callTableAction('activate', $empty);
    expect($empty->fresh()->is_active)->toBeFalse(); // رُفض (مجموعة فارغة) بإشعار لا بانهيار

    expect(OperationalAuditLog::query()->whereIn('action', ['mission_pool_activated', 'mission_pool_deactivated'])->count())->toBe(2);
});

test('Z8-06: activation is refused only when the theoretical concurrent missions exceed the hard cap; softer overload is just a warning', function () {
    config(['liveops.budget.max_concurrent_missions_warn' => 3, 'liveops.budget.max_concurrent_missions_hard' => 5]);
    e34_quests(2);                                  // عامتان
    $admin = e34_admin();
    $ok = e34_pool(e34_quests(4), ['selection_count' => 2], true, $admin);          // 2 + 2 = 4: فوق التحذير (3) دون الصلب (5)

    expect($ok->is_active)->toBeTrue()->and(e34_warning_codes())->toContain('too_many_missions')->not->toContain('missions_over_hard_cap');

    $second = e34_pool(e34_quests(5), ['selection_count' => 4, 'priority' => 5], false, $admin);   // أفضل مجموعة تصبح 2 + 4 = 6 > 5
    expect(e34_reason(fn () => app(MissionPoolService::class)->activate($admin, $second)))->toBe('hard_cap')
        ->and($second->fresh()->is_active)->toBeFalse();
});

test('Z8-07: pool membership enforces the period type, rejects duplicates and locks the period once the pool has missions', function () {
    $admin = e34_admin();
    $svc = app(MissionPoolService::class);
    $pool = e34_pool([], ['period_type' => 'daily'], false, $admin);
    $daily = e34_quest();
    $weekly = e34_weekly();

    expect(e34_reason(fn () => $svc->attach($admin, $pool, $weekly)))->toBe('period_mismatch');

    $svc->attach($admin, $pool, $daily);
    expect(e34_reason(fn () => $svc->attach($admin, $pool, $daily)))->toBe('already_member')
        ->and(e34_reason(fn () => $svc->update($admin, $pool, ['name' => $pool->name, 'slug' => $pool->slug, 'period_type' => 'weekly'])))->toBe('period_locked');

    $svc->detach($admin, $pool, $daily);
    expect($pool->quests()->count())->toBe(0);
});

test('Z8-08: removing a mission from its only pool makes it a global mission again, and existing snapshots stay as assigned', function () {
    $u = e34_user();
    $admin = e34_admin();
    $svc = app(MissionPoolService::class);
    [$a, $b, $c] = e34_quests(3);
    $pool = e34_pool([$a, $b, $c], ['selection_count' => 1], true, $admin);
    $mine = e34_visible_ids($u);
    $assignedRows = UserMissionAssignment::query()->where('user_id', $u->id)->count();
    $notMine = collect([$a->id, $b->id, $c->id])->diff($mine)->first();
    $detached = QuestDefinition::query()->find($notMine);

    $svc->detach($admin, $pool, $detached);

    // المهمة المُزالة صارت عامة (ظاهرة للجميع)، ولقطة اللاعب الحالية لم تتبدّل صفوفها
    expect(e34_visible_ids($u))->toEqualCanonicalizing([...$mine, $detached->id])
        ->and(UserMissionAssignment::query()->where('user_id', $u->id)->count())->toBe($assignedRows);
});

test('Z8-09: a pool with assignment history can only be deactivated; an unused one can be deleted through the service', function () {
    $admin = e34_admin();
    $svc = app(MissionPoolService::class);
    $used = e34_pool(e34_quests(2), [], true, $admin);
    e34_visible_ids(e34_user());

    expect(e34_reason(fn () => $svc->delete($admin, $used)))->toBe('has_history')->and($used->fresh())->not->toBeNull();

    $unused = e34_pool([], [], false, $admin);
    $svc->delete($admin, $unused);

    expect(MissionPool::query()->whereKey($unused->id)->exists())->toBeFalse()
        ->and(OperationalAuditLog::query()->where('action', 'mission_pool_deleted')->count())->toBe(1);
});

test('Z8-10: every pool change leaves an audit trail with the acting admin', function () {
    $admin = e34_admin();
    $svc = app(MissionPoolService::class);
    $q = e34_quest();
    $pool = e34_pool([$q], [], false, $admin);
    $svc->update($admin, $pool, ['name' => 'جديد', 'slug' => $pool->slug, 'period_type' => 'daily']);
    $svc->activate($admin, $pool);
    $svc->deactivate($admin, $pool);
    $svc->detach($admin, $pool, $q);

    $actions = OperationalAuditLog::query()->where('actor_user_id', $admin->id)->pluck('action')->all();
    expect($actions)->toContain('mission_pool_created')->toContain('mission_pool_quest_attached')->toContain('mission_pool_updated')
        ->toContain('mission_pool_activated')->toContain('mission_pool_deactivated')->toContain('mission_pool_quest_detached');
});

test('Z8-11: the planner flags overload, heavy rewards, invalid or stale windows, thin pools and duplicate overlapping campaigns', function () {
    config(['liveops.budget.max_concurrent_missions_warn' => 2, 'liveops.budget.max_concurrent_missions_hard' => 3, 'liveops.budget.daily_gems_warn' => 10]);
    e34_pool(e34_quests(1), ['selection_count' => 3]);                                         // مجموعة رقيقة (تُفعَّل قبل ازدحام المهام العامة)
    $currency = Currency::factory()->create();
    e34_quests(4, ['reward_currency_id' => $currency->id, 'reward_currency_amount' => 5]);    // 4 مهام عامة: فوق الصلب (3) وثقيلة (20 > 10)
    e34_quest(['name' => 'نافذة معكوسة', 'starts_at' => now()->addDays(3), 'ends_at' => now()->addDay()]);
    e34_quest(['name' => 'منتهية فعّالة', 'ends_at' => now()->subDay()]);
    Campaign::withoutEvents(function () {
        Campaign::factory()->create(['slug' => 'dup-1', 'title' => 'حملة مكررة', 'is_active' => true, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(5)]);
        Campaign::factory()->create(['slug' => 'dup-2', 'title' => 'حملة مكررة', 'is_active' => true, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(9)]);
    });

    $codes = e34_warning_codes();

    expect($codes)->toContain('missions_over_hard_cap')->toContain('reward_heavy_daily')->toContain('invalid_window')
        ->toContain('expired_but_active')->toContain('pool_thin')->toContain('duplicate_campaign_overlap');
});

test('Z8-12: a calm schedule produces no warnings and the planner and analytics pages are read-only', function () {
    e34_quests(2);
    $admin = e34_admin();
    $writes = fn () => \Illuminate\Support\Facades\DB::table('user_quest_progress')->count() + MissionPool::query()->count() + UserMissionAssignment::query()->count() + OperationalAuditLog::query()->count();
    $before = $writes();

    expect(e34_warning_codes())->toBe([]);

    $this->actingAs($admin);
    $planner = Livewire::test(LiveOpsPlannerPage::class)->assertSuccessful();
    expect($planner->instance()->data()['potential']['missions'])->toBe(2);

    $analytics = Livewire::test(LiveOpsAnalyticsPage::class)->assertSuccessful()->set('days', 9999);
    expect($analytics->instance()->data()['days'])->toBe(30);
    $analytics->set('days', 7);
    expect($analytics->instance()->data()['days'])->toBe(7)->and($writes())->toBe($before);
});

test('Z8-13: quest administration - pooled missions cannot change period, the schedule label is truthful, and the list shows pool usage', function () {
    $pooled = e34_quest(['name' => 'مهمة مجمّعة']);
    $pool = e34_pool([$pooled, e34_quest()], [], false);

    $pooled->period_type = QuestDefinition::PERIOD_WEEKLY;
    expect(fn () => $pooled->save())->toThrow(StoreItemInvariantViolation::class);

    $mk = fn (array $o) => QuestDefinition::factory()->make($o);
    expect(QuestDefinitionResource::scheduleLabel($mk([])))->toBe('جارية')
        ->and(QuestDefinitionResource::scheduleLabel($mk(['is_active' => false])))->toBe('معطّلة')
        ->and(QuestDefinitionResource::scheduleLabel($mk(['starts_at' => now()->addDay()])))->toBe('مجدولة')
        ->and(QuestDefinitionResource::scheduleLabel($mk(['ends_at' => now()->subDay()])))->toBe('منتهية');

    $this->actingAs(e34_admin());
    Livewire::test(\App\Filament\Resources\QuestDefinitionResource\Pages\ListQuestDefinitions::class)->assertSuccessful()->assertSee('مهمة مجمّعة');
    expect($pool->quests()->count())->toBe(2);
});

test('Z8-14: the pool resource keeps the E26 admin conventions - no delete, service-only writes, no raw model mutation in the resource', function () {
    expect(MissionPoolResource::canDelete(new MissionPool))->toBeFalse();

    foreach (glob(base_path('app/Filament/Resources/MissionPoolResource').'/{*,*/*}.php', GLOB_BRACE) as $file) {
        $code = e34_code_only($file);
        expect(preg_match('/MissionPool::(create|forceCreate|query\(\)->(create|update|delete))|->forceFill|DB::table\(/', $code))->toBe(0, basename($file));
    }
});
