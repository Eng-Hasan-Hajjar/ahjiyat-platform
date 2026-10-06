<?php

require_once __DIR__.'/TeamTestHelpers.php';

use App\Filament\Pages\AnalyticsCenter;
use App\Filament\Resources\TeamResource;
use App\Filament\Resources\TeamResource\Pages\ListTeams;
use App\Filament\Resources\TeamResource\Pages\ViewTeam;
use App\Filament\Resources\TeamResource\RelationManagers\MembersRelationManager;
use App\Models\CompetitiveEventTeamResult;
use App\Models\OperationalAuditLog;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Analytics\TeamAnalyticsService;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

beforeEach(function () {
    e17Freeze();
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});
afterEach(fn () => Carbon::setTestNow());

function e19Admin(array $permissions = [], ?string $role = null): User
{
    $user = e16User();
    $permissions && $user->givePermissionTo($permissions);
    $role && $user->assignRole($role);

    return $user;
}

test('E12: the team permissions exist once in the registry and the administrator receives them', function () {
    foreach (['teams.view', 'teams.manage', 'teams.deactivate'] as $permission) {
        expect(config('permissions.teams.permissions'))->toHaveKey($permission);
    }
    $admin = e19Admin([], 'administrator');

    foreach (['teams.view', 'teams.manage', 'teams.deactivate'] as $permission) {
        expect($admin->can($permission))->toBeTrue();
    }
    expect(collect(config('permissions'))->flatMap(fn ($g) => array_keys($g['permissions'] ?? []))->duplicates()->all())->toBe([]);
});

test('E10/E11: the admin lists and inspects teams but cannot create, edit or delete them raw - the policy and the resource refuse', function () {
    $team = e19Team(null, ['name' => 'فريق الإدارة']);
    e19Member($team);
    $this->actingAs(e19Admin(['teams.view']));

    Livewire::test(ListTeams::class)->assertOk()->assertCanSeeTableRecords([$team])->assertSee('فريق الإدارة')->assertSee('2 / 50');
    expect(TeamResource::canCreate())->toBeFalse()->and(Gate::allows('update', $team))->toBeFalse()->and(Gate::allows('delete', $team))->toBeFalse()->and(Gate::allows('view', $team))->toBeTrue();
    Livewire::test(ViewTeam::class, ['record' => $team->getRouteKey()])->assertOk()->assertSee('فريق الإدارة')->assertSee($team->owner->name);

    $this->actingAs(e16User());
    expect(Gate::allows('view', $team))->toBeFalse()->and(Gate::allows('viewAny', Team::class))->toBeFalse();
});

test('E10/E13: deactivating and reactivating run through the audited domain service - only with teams.deactivate', function () {
    $team = e19Team();
    $this->actingAs(e19Admin(['teams.view']));
    Livewire::test(ListTeams::class)->assertTableActionHidden('deactivate', $team);

    $this->actingAs(e19Admin(['teams.view', 'teams.deactivate']));
    Livewire::test(ListTeams::class)->assertTableActionVisible('deactivate', $team)->callTableAction('deactivate', $team)->assertHasNoTableActionErrors();
    expect($team->refresh()->is_active)->toBeFalse()->and(OperationalAuditLog::where('action', 'team_admin_deactivated')->count())->toBe(1);

    Livewire::test(ListTeams::class)->assertTableActionHidden('deactivate', $team)->assertTableActionVisible('reactivate', $team)->callTableAction('reactivate', $team);
    expect($team->refresh()->is_active)->toBeTrue()->and(OperationalAuditLog::where('action', 'team_admin_reactivated')->count())->toBe(1);
});

test('E19/E10: a frozen owner never dissolves the team, and an admin with teams.manage moves ownership to a current member - audited, one owner', function () {
    $team = e19Team();
    $heir = e19Member($team);
    $team->owner->forceFill(['is_frozen' => true])->save();
    $oldOwner = $team->owner;
    $team->refresh();                          // العدّاد طازج: الاختبار يمرّر النموذج نفسه للإجراء (كان بعدّاد 1 قبل إضافة العضو)

    $this->actingAs(e19Admin(['teams.view']));
    Livewire::test(ListTeams::class)->assertTableActionHidden('transfer', $team);

    $this->actingAs(e19Admin(['teams.view', 'teams.manage']));
    Livewire::test(ListTeams::class)->assertTableActionVisible('transfer', $team)->callTableAction('transfer', $team, data: ['user_id' => $heir->id])->assertHasNoTableActionErrors();

    expect($team->refresh()->owner_id)->toBe($heir->id)->and(e19Role($team, $heir))->toBe('owner')->and(e19Role($team, $oldOwner))->toBe('admin')->and($team->is_active)->toBeTrue()
        ->and(TeamMembership::where('team_id', $team->id)->where('role', 'owner')->count())->toBe(1)->and(OperationalAuditLog::where('action', 'team_ownership_transferred')->count())->toBe(1);
});

test('E11: the members relation manager is read-only - the owner cannot be removed from it, other members only through the audited service, and only with teams.manage', function () {
    $team = e19Team();
    $admin = e19Member($team, null, 'admin');
    $member = e19Member($team);
    $ownerRow = TeamMembership::where('team_id', $team->id)->where('role', 'owner')->first();
    $memberRow = TeamMembership::where('user_id', $member->id)->first();
    $manager = fn () => Livewire::test(MembersRelationManager::class, ['ownerRecord' => $team, 'pageClass' => ViewTeam::class]);

    $this->actingAs(e19Admin(['teams.view']));
    $manager()->assertCanSeeTableRecords([$ownerRow, $memberRow])->assertTableActionHidden('remove', $memberRow);
    expect(MembersRelationManager::canViewForRecord($team, ViewTeam::class))->toBeTrue();

    $this->actingAs(e19Admin(['teams.view', 'teams.manage']));
    $manager()->assertTableActionHidden('remove', $ownerRow)->assertTableActionVisible('remove', $memberRow)->callTableAction('remove', $memberRow);

    expect(e19Role($team, $member))->toBeNull()->and(e19Role($team, $team->owner))->toBe('owner')->and($team->refresh()->members_count)->toBe(2)
        ->and(OperationalAuditLog::where('action', 'team_admin_member_removed')->count())->toBe(1)
        ->and(e16Notes('team_member_removed', $member))->toHaveCount(1);

    $this->actingAs(e16User());
    expect(MembersRelationManager::canViewForRecord($team, ViewTeam::class))->toBeFalse();
    expect(e19Role($team, $admin))->toBe('admin');
});

test('75/76: the analytics count teams, active teams, members in teams, the average size, pending invitations (unexpired) and pending join requests correctly', function () {
    $a = e19Team(null, ['join_policy' => 'request']);
    e19Member($a);
    e19Member($a);
    $b = e19Team(null, ['join_policy' => 'request']);
    e19Member($b);
    $dead = e19Team();
    e19Member($dead);
    e19Teams()->deactivate($dead->owner, $dead);

    e19Invites()->invite($a->owner, $a, e16User());
    e19Invites()->invite($a->owner, $a, e16User());
    e19Requests()->create(e16User(), $b);
    Carbon::setTestNow(now()->addDays(8));
    e19Invites()->invite($b->owner, $b, e16User());        // الدعوتان الأوليان انتهتا الآن

    $o = app(TeamAnalyticsService::class)->overview();

    expect($o)->toMatchArray(['total' => 3, 'active' => 2, 'members_in_teams' => 5, 'avg_size' => 2.5, 'pending_invitations' => 1, 'pending_requests' => 1, 'competing_teams' => 0, 'top_teams' => []]);
});

test('77: the competitive team figures come from the stored final rankings - competing teams and the top teams by wins', function () {
    [$gold, $silver, $quiet] = [e19Team(null, ['name' => 'ذهب']), e19Team(null, ['name' => 'فضة']), e19Team(null, ['name' => 'هادئ'])];
    foreach (range(1, 3) as $i) {
        $event = e19Complete(e17Event(['starts_at' => now()->subDays(10), 'ends_at' => now()->subDays(5)]));
        e19Result($event, e16User(), $gold, 1900, 9000);
        e19Result($event, e16User(), $silver, $i === 3 ? 1950 : 1500, 9000);
        e19Ranking()->finalize($event);
    }

    $o = app(TeamAnalyticsService::class)->overview();

    expect($o['competing_teams'])->toBe(2)->and($o['top_teams'])->toBe([['name' => 'ذهب', 'wins' => 2, 'events' => 3], ['name' => 'فضة', 'wins' => 1, 'events' => 3]])
        ->and(CompetitiveEventTeamResult::count())->toBe(6);
});

test('78/E14: the analytics expose aggregates only - no email, name of a member, id or private field - and the tab is gated by the existing competitive analytics permission', function () {
    $team = e19Team(e16User(['email' => 'private.owner@secret.test', 'name' => 'Private Owner']));
    e19Member($team, e16User(['email' => 'private.member@secret.test']));
    e19Invites()->invite($team->owner, $team, e16User(['email' => 'invitee@secret.test']));

    $json = json_encode(app(TeamAnalyticsService::class)->overview(), JSON_UNESCAPED_UNICODE);
    expect($json)->not->toContain('secret.test')->and($json)->not->toContain('Private Owner')->and($json)->not->toMatch('/"(id|user_id|owner_id|email|phone)"/');

    $this->actingAs(e19Admin(['analytics.view']));
    expect(Livewire::test(AnalyticsCenter::class)->instance()->competitiveAnalytics())->toBeNull();

    $this->actingAs(e19Admin(['analytics.view', 'analytics.competitive']));
    $data = Livewire::test(AnalyticsCenter::class)->instance()->competitiveAnalytics();
    expect($data)->toHaveKey('teams')->and($data['teams'])->toMatchArray(['total' => 1, 'active' => 1, 'members_in_teams' => 2]);
    Livewire::test(AnalyticsCenter::class)->set('activeTab', 'competitive')->assertSee('إجمالي الفرق')->assertSee('متوسط حجم الفريق')->assertDontSee('secret.test');
});

test('E10: a team admin action never edits pivot rows raw - the resource exposes only services, and no page creates, edits or deletes a team or membership', function () {
    $pages = array_keys(TeamResource::getPages());
    expect($pages)->toBe(['index', 'view']);

    foreach ([app_path('Filament/Resources/TeamResource.php'), app_path('Filament/Resources/TeamResource/RelationManagers/MembersRelationManager.php')] as $file) {
        $code = e19Code($file);
        expect($code)->not->toContain('DeleteAction')->and($code)->not->toContain('EditAction')->and($code)->not->toContain('CreateAction')->and($code)->not->toContain('->delete(')
            ->and($code)->not->toContain('TeamMembership::create')->and($code)->not->toContain('->update(');
    }
    expect((new \App\Filament\Resources\TeamResource\RelationManagers\MembersRelationManager)->isReadOnly())->toBeTrue();
});
