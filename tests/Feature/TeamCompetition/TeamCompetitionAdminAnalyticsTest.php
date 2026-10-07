<?php

require_once __DIR__.'/TeamCompetitionTestHelpers.php';

use App\Filament\Pages\AnalyticsCenter;
use App\Filament\Pages\OperationsCenter;
use App\Filament\Resources\TeamChallengeResource;
use App\Filament\Resources\TeamChallengeResource\Pages\ListTeamChallenges;
use App\Filament\Resources\TeamChallengeResource\Pages\ViewTeamChallenge;
use App\Filament\Resources\TeamChallengeResource\RelationManagers\ParticipantsRelationManager;
use App\Filament\Resources\TeamChampionshipResource\Pages\CreateTeamChampionship;
use App\Filament\Resources\TeamChampionshipResource\Pages\EditTeamChampionship;
use App\Filament\Resources\TeamChampionshipResource\Pages\ListTeamChampionships;
use App\Filament\Resources\TeamChampionshipResource\RelationManagers\EventsRelationManager;
use App\Models\OperationalAuditLog;
use App\Models\TeamChallenge;
use App\Models\TeamChampionship;
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

test('E17: the four new permissions exist once in the registry and the administrator receives them', function () {
    foreach (['view', 'manage', 'publish'] as $a) {
        expect(config('permissions.team_championships.permissions'))->toHaveKey("team_championships.{$a}");
    }
    expect(config('permissions.team_challenges.permissions'))->toHaveKey('team_challenges.view');
    $admin = e20Admin([], 'administrator');

    foreach (['team_championships.view', 'team_championships.manage', 'team_championships.publish', 'team_challenges.view'] as $p) {
        expect($admin->can($p))->toBeTrue();
    }
    expect(collect(config('permissions'))->flatMap(fn ($g) => array_keys($g['permissions'] ?? []))->duplicates()->all())->toBe([]);
});

test('E13: the admin inspects team challenges but can create, edit, delete or set a winner for none - the policy and the resource are view-only, and the participants manager is read-only', function () {
    [$a, $b, $am, $bm] = e20Pair();
    $done = e20Match($a, $b, 5_000, 40_000);
    $this->actingAs(e20Admin(['team_challenges.view']));

    Livewire::test(ListTeamChallenges::class)->assertOk()->assertCanSeeTableRecords([$done])->assertSee($a->name)->assertSee($b->name);
    Livewire::test(ViewTeamChallenge::class, ['record' => $done->getRouteKey()])->assertOk()->assertSee($a->name);
    Livewire::test(ParticipantsRelationManager::class, ['ownerRecord' => $done, 'pageClass' => ViewTeamChallenge::class])->assertOk()->assertCanSeeTableRecords($done->participants);
    expect(TeamChallengeResource::canCreate())->toBeFalse()->and(Gate::allows('update', $done))->toBeFalse()->and(Gate::allows('delete', $done))->toBeFalse()->and(Gate::allows('view', $done))->toBeTrue()
        ->and((new ParticipantsRelationManager)->isReadOnly())->toBeTrue()->and(array_keys(TeamChallengeResource::getPages()))->toBe(['index', 'view']);

    $this->actingAs(e16User());
    expect(Gate::allows('viewAny', TeamChallenge::class))->toBeFalse()->and(Gate::allows('view', $done))->toBeFalse();
    foreach ([app_path('Filament/Resources/TeamChallengeResource.php'), app_path('Filament/Resources/TeamChallengeResource/RelationManagers/ParticipantsRelationManager.php')] as $file) {
        $code = e19Code($file);
        expect($code)->not->toContain('DeleteAction')->and($code)->not->toContain('EditAction')->and($code)->not->toContain('CreateAction')->and($code)->not->toContain('winner_team_id =')->and($code)->not->toContain('->update(');
    }
});

test('E14/E15: an admin with manage creates and edits a draft championship through the domain service - a view-only admin cannot create - and the status is never taken from the form', function () {
    $this->actingAs(e20Admin(['team_championships.view']));
    expect(Gate::allows('create', TeamChampionship::class))->toBeFalse();

    $this->actingAs(e20Admin(['team_championships.view', 'team_championships.manage']));
    expect(Gate::allows('create', TeamChampionship::class))->toBeTrue();
    Livewire::test(CreateTeamChampionship::class)->fillForm([
        'title' => 'بطولة من اللوحة', 'slug' => 'panel-cup', 'description' => 'وصف', 'starts_at' => now()->addDay()->format('Y-m-d H:i:s'), 'ends_at' => now()->addDays(9)->format('Y-m-d H:i:s'),
    ])->call('create')->assertHasNoFormErrors();

    $champ = TeamChampionship::where('slug', 'panel-cup')->firstOrFail();
    expect($champ->status)->toBe('draft')->and($champ->champion_team_id)->toBeNull();

    Livewire::test(EditTeamChampionship::class, ['record' => $champ->getRouteKey()])->fillForm(['title' => 'عنوان جديد', 'is_featured' => true])->call('save')->assertHasNoFormErrors();
    expect($champ->refresh()->title)->toBe('عنوان جديد')->and($champ->is_featured)->toBeTrue()->and($champ->status)->toBe('draft');
});

test('E15/E18: publish, cancel and finalize are domain actions shown only with the publish permission - they run through the audited service and refuse early finalization with a notice', function () {
    [$a, $b] = [e19Team(), e19Team()];
    $admin = e20Admin([], 'administrator');
    $champ = e20Championship($admin, ['ends_at' => now()->addDays(2)]);
    e20Champs()->linkEvent($admin, $champ, e20Event([[$a, 1900], [$b, 1800]]));

    $this->actingAs(e20Admin(['team_championships.view', 'team_championships.manage']));
    Livewire::test(ListTeamChampionships::class)->assertCanSeeTableRecords([$champ])->assertTableActionHidden('publish', $champ)->assertTableActionHidden('cancel', $champ)->assertTableActionHidden('finalize', $champ);

    $this->actingAs($admin);
    Livewire::test(ListTeamChampionships::class)->assertTableActionVisible('publish', $champ)->callTableAction('publish', $champ)->assertHasNoTableActionErrors();
    expect($champ->refresh()->status)->toBe('published')->and(OperationalAuditLog::where('action', 'team_championship_published')->count())->toBe(1);

    Livewire::test(ListTeamChampionships::class)->assertTableActionHidden('publish', $champ)->assertTableActionHidden('finalize', $champ);          // لم تنتهِ بعد: لا اعتماد
    Carbon::setTestNow(now()->addDays(3));
    Livewire::test(ListTeamChampionships::class)->assertTableActionVisible('finalize', $champ->refresh())->callTableAction('finalize', $champ)->assertHasNoTableActionErrors();
    expect($champ->refresh()->status)->toBe('completed')->and($champ->champion_team_id)->toBe($a->id)->and(OperationalAuditLog::where('action', 'team_championship_finalized')->count())->toBe(1);

    Livewire::test(ListTeamChampionships::class)->assertTableActionHidden('cancel', $champ)->assertTableActionHidden('publish', $champ);         // معتمَدة: لا تغيير
});

test('E16: after publishing the dates and slug are disabled in the form and the events manager is locked - while a draft links and unlinks events through the service and refuses incompatible ones', function () {
    [$a, $b] = [e19Team(), e19Team()];
    $admin = e20Admin([], 'administrator');
    $champ = e20Championship($admin, ['ends_at' => now()->addDays(2)]);
    $good = e20Event([[$a, 1900], [$b, 1800]]);
    $draftEvent = e17Event([], 'draft');
    $this->actingAs($admin);

    $manager = fn () => Livewire::test(EventsRelationManager::class, ['ownerRecord' => $champ->refresh(), 'pageClass' => EditTeamChampionship::class]);
    $manager()->assertTableActionVisible('link')->callTableAction('link', data: ['event_id' => $good->id])->assertHasNoTableActionErrors();
    expect($champ->events()->count())->toBe(1);

    $manager()->callTableAction('link', data: ['event_id' => $draftEvent->id]);                    // غير متوافق: يُرفض بإشعار
    expect($champ->events()->count())->toBe(1);

    $manager()->assertCanSeeTableRecords([$good])->callTableAction('unlink', $good);
    expect($champ->events()->count())->toBe(0);
    $manager()->callTableAction('link', data: ['event_id' => $good->id]);
    e20Champs()->publish($admin, $champ);

    $manager()->assertTableActionHidden('link')->assertTableActionHidden('unlink', $good);
    Livewire::test(EditTeamChampionship::class, ['record' => $champ->getRouteKey()])->assertFormFieldIsDisabled('starts_at')->assertFormFieldIsDisabled('ends_at')->assertFormFieldIsDisabled('slug');
    expect($champ->refresh()->events()->count())->toBe(1);
});

test('only a draft championship can be deleted - a published, finished or cancelled one keeps its history', function () {
    $admin = e20Admin([], 'administrator');
    $draft = e20Championship($admin);
    [$champ] = [e20Completed([[e19Team(), 1900]], 'للحفظ')];
    $this->actingAs($admin);

    expect(Gate::allows('delete', $draft))->toBeTrue()->and(Gate::allows('delete', $champ))->toBeFalse();
    Livewire::test(ListTeamChampionships::class)->assertTableActionVisible('delete', $draft)->assertTableActionHidden('delete', $champ);
});

test('85/86: the analytics count challenges created, accepted, completed and expired, the draw rate, and the most active teams correctly', function () {
    [$a, $b, $c, $d] = [e19Team(), e19Team(), e19Team(), e19Team()];
    e20Match($a, $b, 5_000, 40_000);                               // مكتمل
    e20Match($a, $c, 15_000, 15_000);                              // مكتمل بتعادل
    e20Create($b, $c, [$b->owner]);                                // معلّق
    e20Accepted($a, $d, [$a->owner], [$d->owner]);                 // مقبول بلا لعب: يعتمده الأمر الدوري بتعادل صفري بعد المهلة
    $expired = e20Create($c, $d, [$c->owner]);
    Carbon::setTestNow(now()->addHours(73));
    $this->artisan('teams:process-lifecycle')->assertExitCode(0);

    $o = app(TeamAnalyticsService::class)->overview()['challenges'];

    expect($o)->toMatchArray(['created' => 5, 'accepted' => 3, 'completed' => 3, 'expired' => 2, 'draw_rate' => 66.7])
        ->and($o['most_active'][0])->toBe(['name' => $a->name, 'played' => 3])->and(collect($o['most_active'])->pluck('name')->all())->toContain($b->name, $c->name);
    expect($expired->refresh()->status)->toBe('expired');
});

test('87/88: the analytics count championships and their participants and rank the champions by titles', function () {
    [$x, $y, $z] = [e19Team(), e19Team(), e19Team()];
    e20Completed([[$x, 1900], [$y, 1800], [$z, 1700]], 'أولى');
    e20Completed([[$x, 1900], [$z, 1800]], 'ثانية');
    e20Completed([[$y, 1900], [$x, 1800]], 'ثالثة');
    $admin = e20Admin([], 'administrator');
    e20Championship($admin);                                                                        // مسودة: لا تُحتسب

    $o = app(TeamAnalyticsService::class)->overview()['championships'];

    expect($o['total'])->toBe(3)->and($o['completed'])->toBe(3)->and($o['participants'])->toBe(3)->and($o['champions'])->toBe([['name' => $x->name, 'titles' => 2], ['name' => $y->name, 'titles' => 1]]);
});

test('89/E20: the analytics expose aggregates only - no email, no player name, no id or private field - and the tab shows the new figures to those allowed', function () {
    [$a, $b] = [e19Team(e16User(['email' => 'private.owner@secret.test', 'name' => 'Private Owner Person'])), e19Team()];
    e20Match($a, $b, 5_000, 40_000);
    e20Completed([[$a, 1900], [$b, 1800]], 'بطولة التحليلات');

    $json = json_encode(app(TeamAnalyticsService::class)->overview(), JSON_UNESCAPED_UNICODE);
    expect($json)->not->toContain('secret.test')->and($json)->not->toContain('Private Owner Person')->and($json)->not->toMatch('/"(id|user_id|owner_id|email|phone|public_id)"/');

    $this->actingAs(e20Admin(['analytics.view']));
    expect(Livewire::test(AnalyticsCenter::class)->instance()->competitiveAnalytics())->toBeNull();
    $this->actingAs(e20Admin(['analytics.view', 'analytics.competitive']));
    Livewire::test(AnalyticsCenter::class)->set('activeTab', 'competitive')->assertSee('تحدّيات اكتملت')->assertSee('نسبة التعادل')->assertSee('فرق شاركت ببطولات')->assertDontSee('secret.test');
});

test('E21: the operations center flags accepted matches and published championships that are overdue for finalization - only for those allowed to see them', function () {
    [$a, $b] = [e19Team(), e19Team()];
    e20Accepted($a, $b, [$a->owner], [$b->owner]);
    $admin = e20Admin([], 'administrator');
    $champ = e20Championship($admin, ['ends_at' => now()->addDays(2)]);
    e20Champs()->linkEvent($admin, $champ, e20Event([[$a, 1900]]));
    e20Champs()->publish($admin, $champ);
    Carbon::setTestNow(now()->addDays(3));                                                          // تجاوزا مهلتيهما بأكثر من ساعة ولم يعالجهما الأمر الدوري

    $this->actingAs(e20Admin(['operations.dashboard_view', 'team_challenges.view']));
    expect(Livewire::test(OperationsCenter::class)->instance()->getOverdueTeamCompetitionCount())->toBe(2);
    Livewire::test(OperationsCenter::class)->assertSee('منافسات فرق متأخرة الاعتماد');

    $this->actingAs(e20Admin(['operations.dashboard_view']));
    expect(Livewire::test(OperationsCenter::class)->instance()->getOverdueTeamCompetitionCount())->toBeNull();
});
