<?php

require_once __DIR__.'/RewardTestHelpers.php';

use App\Filament\Pages\AnalyticsCenter;
use App\Filament\Pages\OperationsCenter;
use App\Filament\Resources\CompetitiveEventResource\Pages\CreateCompetitiveEvent;
use App\Filament\Resources\CompetitiveEventResource\Pages\EditCompetitiveEvent;
use App\Filament\Resources\CompetitiveEventResource\Pages\ListCompetitiveEvents;
use App\Filament\Resources\CompetitiveEventResource\RelationManagers\RewardRulesRelationManager;
use App\Models\CompetitiveEvent;
use App\Models\CompetitiveRewardGrant;
use App\Models\CompetitiveRewardRule;
use App\Models\OperationalAuditLog;
use App\Models\User;
use App\Services\Analytics\CompetitiveAnalyticsService;
use App\Support\AnalyticsPeriod;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    e17Freeze();
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});
afterEach(fn () => Carbon::setTestNow());

function e18Admin(array $permissions = [], ?string $role = null): User
{
    $user = e16User();
    $permissions && $user->givePermissionTo($permissions);
    $role && $user->assignRole($role);

    return $user;
}

function e18Manager(CompetitiveEvent $event)
{
    return Livewire::test(RewardRulesRelationManager::class, ['ownerRecord' => $event, 'pageClass' => EditCompetitiveEvent::class]);
}

/** حدث معتمَد بقاعدة عملة فاشلة: عملته غير قابلة للكسب وقت التوزيع. */
function e18FailedGrants(): array
{
    $event = e18Event();
    $currency = e18Currency();
    e18Rule($event, ['min_rank' => 1, 'max_rank' => 2, 'reward_type' => 'currency', 'currency_id' => $currency->id, 'amount' => 100]);
    $users = [e16User(), e16User()];
    $currency->update(['is_earnable' => false]);
    e18Run($event, [[$users[0], E17_ANSWER, 10_000], [$users[1], E17_ANSWER, 20_000]]);
    $currency->update(['is_earnable' => true]);

    return [$event->refresh(), $users, $currency];
}

test('E5: the reward permissions and the analytics permission exist once in the registry and the administrator receives them', function () {
    foreach (['rewards.view', 'rewards.manage', 'rewards.retry'] as $ability) {
        expect(config('permissions.competitive_events.permissions'))->toHaveKey("competitive_events.{$ability}");
    }
    expect(config('permissions.analytics.permissions'))->toHaveKey('analytics.competitive');
    $admin = e18Admin([], 'administrator');

    foreach (['competitive_events.rewards.view', 'competitive_events.rewards.manage', 'competitive_events.rewards.retry', 'analytics.competitive'] as $permission) {
        expect($admin->can($permission))->toBeTrue();
    }
    $all = collect(config('permissions'))->flatMap(fn ($g) => array_keys($g['permissions'] ?? []));
    expect($all->duplicates()->all())->toBe([]); // لا تكرار بالسجل
});

test('1/E1: an authorized admin creates a rank reward rule from the event page by name - no raw ids - and it is audited', function () {
    $this->actingAs(e18Admin(['competitive_events.rewards.view', 'competitive_events.rewards.manage']));
    $event = e18Event();
    $currency = e18Currency(['name' => 'جواهر البطولة']);

    e18Manager($event)->assertOk()->callTableAction('create', data: [
        'kind' => 'rank', 'min_rank' => 1, 'max_rank' => 3, 'reward_type' => 'currency', 'currency_id' => $currency->id, 'amount' => 250, 'sort_order' => 0, 'is_active' => true,
    ])->assertHasNoTableActionErrors();

    $rule = CompetitiveRewardRule::sole();
    expect($rule->competitive_event_id)->toBe($event->id)->and($rule->rewardLabel())->toBe('250 جواهر البطولة')->and($rule->store_item_id)->toBeNull()
        ->and(OperationalAuditLog::where('action', 'competitive_reward_rule_created')->count())->toBe(1);
    e18Manager($event)->assertCanSeeTableRecords([$rule]);
});

test('2/E5: without rewards.manage the form is hidden; without rewards.view the whole manager is not even shown', function () {
    $event = e18Event();
    e18Rule($event);

    $this->actingAs(e18Admin(['competitive_events.rewards.view']));
    e18Manager($event)->assertOk()->assertTableActionHidden('create')->assertCountTableRecords(1);

    $nobody = e18Admin(['competitive_events.view']);
    $this->actingAs($nobody);
    expect(RewardRulesRelationManager::canViewForRecord($event, EditCompetitiveEvent::class))->toBeFalse();
    $this->actingAs(e18Admin(['competitive_events.rewards.view']));
    expect(RewardRulesRelationManager::canViewForRecord($event, EditCompetitiveEvent::class))->toBeTrue();
});

test('3/4/5: invalid input from the admin form (overlap, bad range) is refused with a clear message and creates nothing', function () {
    $this->actingAs(e18Admin([], 'administrator'));
    $event = e18Event();
    e18Rule($event, ['min_rank' => 1, 'max_rank' => 3]);

    foreach ([['min_rank' => 2, 'max_rank' => 4], ['min_rank' => 6, 'max_rank' => 5]] as $range) {
        e18Manager($event)->callTableAction('create', data: $range + ['kind' => 'rank', 'reward_type' => 'xp', 'amount' => 10, 'sort_order' => 0, 'is_active' => true])
            ->assertNotified();
    }

    expect(CompetitiveRewardRule::count())->toBe(1);
});

test('6/E2: rules are read-only once the event starts and after finalization - even for a super admin', function () {
    $this->actingAs(e18Admin([], 'super-admin'));
    $event = e18Event();
    $rule = e18Rule($event);

    e18Manager($event)->assertTableActionVisible('create')->assertTableActionVisible('edit', $rule)->assertTableActionVisible('delete', $rule);

    e17Forward(2 * 3600_000); // بدأت
    e18Manager($event->refresh())->assertTableActionHidden('create')->assertTableActionHidden('edit', $rule)->assertTableActionHidden('delete', $rule);

    e17PlayEvent(e16User(), $event);
    e17Forward(5 * 3600_000);
    app(\App\Services\Competitive\CompetitiveEventFinalizer::class)->finalize($event->refresh());
    e18Manager($event->refresh())->assertTableActionHidden('create')->assertTableActionHidden('edit', $rule);
    expect($rule->refresh()->amount)->toBe(50);
});

test('E3/E4: the event list shows eligible, granted, pending and failed counts, and only a permitted admin can retry only the failed ones', function () {
    [$event, [$a, $b]] = e18FailedGrants();

    $this->actingAs(e18Admin(['competitive_events.view', 'competitive_events.rewards.view']));
    $list = Livewire::test(ListCompetitiveEvents::class)->assertCanSeeTableRecords([$event])->assertSee('مؤهَّلون 2 · ممنوحة 0 · معلّقة 0 · فاشلة 2')->assertTableActionHidden('retry_rewards', $event);

    $this->actingAs(e18Admin(['competitive_events.view', 'competitive_events.rewards.view', 'competitive_events.rewards.retry']));
    Livewire::test(ListCompetitiveEvents::class)->assertTableActionVisible('retry_rewards', $event)->callTableAction('retry_rewards', $event)->assertHasNoTableActionErrors()->assertNotified();

    expect(CompetitiveRewardGrant::where('status', 'granted')->count())->toBe(2)->and(OperationalAuditLog::where('action', 'competitive_rewards_retry')->count())->toBe(1);
    Livewire::test(ListCompetitiveEvents::class)->assertSee('مؤهَّلون 2 · ممنوحة 2 · معلّقة 0 · فاشلة 0')->assertTableActionHidden('retry_rewards', $event->refresh()); // لا فاشل: لا زر
});

test('E18: the operations center shows the failed competitive grants count only to those allowed to see rewards', function () {
    e18FailedGrants();

    $this->actingAs(e18Admin(['operations.dashboard_view', 'competitive_events.rewards.view']));
    expect(Livewire::test(OperationsCenter::class)->instance()->getFailedCompetitiveGrantsCount())->toBe(2);
    Livewire::test(OperationsCenter::class)->assertSee('جوائز تنافسية فاشلة');

    $this->actingAs(e18Admin(['operations.dashboard_view']));
    expect(Livewire::test(OperationsCenter::class)->instance()->getFailedCompetitiveGrantsCount())->toBeNull();
});

test('E14/E15: the analytics report competitive counts and currency granted from the existing ledger - and are gated by their own permission', function () {
    $event = e18Event();
    $currency = e18Currency(['name' => 'عملة التحليلات']);
    e18Rule($event, ['min_rank' => 1, 'max_rank' => 2, 'reward_type' => 'currency', 'currency_id' => $currency->id, 'amount' => 100]);
    e18Rule($event, ['min_rank' => 3, 'max_rank' => 3, 'reward_type' => 'xp', 'amount' => 10]);
    $users = collect(range(1, 4))->map(fn () => e16User())->all();
    e18Run($event, array_map(fn ($u, $i) => [$u, E17_ANSWER, $i * 10_000], $users, range(1, 4)));
    e16User(); // مستخدم بلا مشاركة

    $o = app(CompetitiveAnalyticsService::class)->overview(AnalyticsPeriod::fromPreset('last_30_days'));

    expect($o)->toMatchArray(['events' => 1, 'finalized_events' => 1, 'participants' => 4, 'completed_results' => 4, 'unique_players' => 4, 'rewards_granted' => 3, 'rewards_failed_now' => 0])
        ->and($o['currency_granted'])->toBe([['currency' => 'عملة التحليلات', 'total' => 200]])
        ->and($o['top_events'])->toBe([['title' => $event->title, 'participants' => 4, 'status' => 'completed']]);

    $this->actingAs(e18Admin(['analytics.view']));
    expect(Livewire::test(AnalyticsCenter::class)->instance()->competitiveAnalytics())->toBeNull();
    $this->actingAs(e18Admin(['analytics.view', 'analytics.competitive']));
    expect(Livewire::test(AnalyticsCenter::class)->instance()->competitiveAnalytics())->toMatchArray(['events' => 1, 'rewards_granted' => 3]);
    Livewire::test(AnalyticsCenter::class)->set('activeTab', 'competitive')->assertSee('جوائز ممنوحة')->assertSee('عملة التحليلات');
});

test('the analytics read the economy ledger only - they compute no wallet balance and create no second ledger', function () {
    $code = file_get_contents(app_path('Services/Analytics/CompetitiveAnalyticsService.php'));
    $code = preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', $code);

    expect($code)->toContain("CurrencyTransaction::query()->where('reason', 'competitive_event_reward')")->and($code)->not->toContain('Wallet')->and($code)->not->toContain('pending_balance')
        ->and($code)->not->toContain('->create(')->and($code)->not->toContain('->insert(');
});

test('the create form rejects the reserved hall-of-fame slug', function () {
    $this->actingAs(e18Admin([], 'administrator'));
    $puzzle = e17Puzzle();

    Livewire::test(CreateCompetitiveEvent::class)->fillForm([
        'title' => 'حدث', 'slug' => 'hall-of-fame', 'puzzle_id' => $puzzle->id,
        'starts_at' => now()->addDay()->format('Y-m-d H:i:s'), 'ends_at' => now()->addDays(2)->format('Y-m-d H:i:s'),
    ])->call('create')->assertHasFormErrors(['slug']);

    expect(CompetitiveEvent::where('slug', 'hall-of-fame')->exists())->toBeFalse();
});

test('there is no admin screen or action that writes an arbitrary reward to a player - rules and automatic distribution are the only path (B19)', function () {
    foreach ([app_path('Filament/Resources/CompetitiveEventResource.php'), app_path('Filament/Resources/CompetitiveEventResource/RelationManagers/RewardRulesRelationManager.php')] as $file) {
        $code = file_get_contents($file);

        expect($code)->not->toContain('creditPending')->and($code)->not->toContain('creditAvailable')->and($code)->not->toContain('grantXp')->and($code)->not->toContain('CompetitiveRewardGrant::create')
            ->and($code)->not->toContain('user_id');
    }
});
