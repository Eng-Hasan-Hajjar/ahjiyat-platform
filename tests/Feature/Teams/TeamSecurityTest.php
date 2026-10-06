<?php

require_once __DIR__.'/TeamTestHelpers.php';

use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\TeamJoinRequest;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Teams\TeamException;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

beforeEach(fn () => e17Freeze());
afterEach(fn () => Carbon::setTestNow());

/** بصمة كل حالة الفرق: أي تغيير غير مرغوب يظهر. */
function e19State(): string
{
    return json_encode([
        DB::table('teams')->orderBy('id')->get()->all(), DB::table('team_memberships')->orderBy('id')->get()->all(),
        DB::table('team_invitations')->orderBy('id')->get()->all(), DB::table('team_join_requests')->orderBy('id')->get()->all(),
    ]);
}

/** عالَم للهجوم: فريقي A (مالكي attacker) وفريق ضحية C بأعضاء ودعوة وطلب. */
function e19World(): array
{
    $a = e19Team(null, ['name' => 'فريق المهاجم', 'join_policy' => 'request']);
    $c = e19Team(null, ['name' => 'فريق الضحية', 'join_policy' => 'request']);
    $cAdmin = e19Member($c, null, 'admin');
    $cMember = e19Member($c);
    $inv = e19Invites()->invite($c->owner, $c, $invitee = e16User());
    $req = e19Requests()->create($asker = e16User(), $c);

    return compact('a', 'c', 'cAdmin', 'cMember', 'inv', 'req', 'invitee', 'asker');
}

test('67: cross-team IDOR - the owner of team A and a plain member of team C can do NOTHING to team C - every action is 403 and nothing changes', function () {
    ['a' => $a, 'c' => $c, 'cMember' => $cMember, 'inv' => $inv, 'req' => $req] = e19World();
    $target = e16User();
    $aMember = e19Member($a);                       // يُنشأ قبل البصمة: أي تغيّر لاحق يعني أن الهجوم نجح
    $before = e19State();

    $attempts = fn () => [
        ['post', route('teams.invitations.store', [$c, $target])],
        ['delete', route('teams.invitations.cancel', [$c, $inv])],
        ['post', route('teams.requests.accept', [$c, $req])],
        ['post', route('teams.requests.decline', [$c, $req])],
        ['delete', route('teams.members.remove', [$c, $cMember])],
        ['patch', route('teams.members.role', [$c, $cMember]), ['role' => 'admin']],
        ['post', route('teams.transfer', [$c, $cMember])],
        ['patch', route('teams.update', $c), ['name' => 'مخطوف']],
        ['post', route('teams.deactivate', $c)],
        ['get', route('teams.manage', $c)],
    ];

    foreach ([$a->owner, $aMember] as $attacker) {
        foreach ($attempts() as $item) {
            [$verb, $url, $data] = array_pad($item, 3, []);
            $this->actingAs($attacker)->{$verb}($url, $data)->assertForbidden();
        }
    }
    // وعضو عادي بفريق الضحية نفسه: لا يدير شيئًا (يملك الدور member فقط).
    foreach (array_slice($attempts(), 0, 9) as $item) {
        [$verb, $url, $data] = array_pad($item, 3, []);
        $this->actingAs($cMember)->{$verb}($url, $data)->assertForbidden();
    }

    expect(e19State())->toBe($before);
});

test('67b: mixing resources across teams fails - an invitation, a request or a member of team C through the route of team A is 404 or a refusal, never an action', function () {
    ['a' => $a, 'c' => $c, 'cMember' => $cMember, 'inv' => $inv, 'req' => $req, 'invitee' => $invitee] = e19World();
    $before = e19State();

    $this->actingAs($a->owner)->delete(route('teams.invitations.cancel', [$a, $inv]))->assertNotFound();
    $this->actingAs($a->owner)->post(route('teams.requests.accept', [$a, $req]))->assertNotFound();
    $this->actingAs($a->owner)->post(route('teams.requests.decline', [$a, $req]))->assertNotFound();
    $this->actingAs($a->owner)->delete(route('teams.members.remove', [$a, $cMember]))->assertRedirect()->assertSessionHas('error');
    $this->actingAs($a->owner)->post(route('teams.transfer', [$a, $cMember]))->assertRedirect()->assertSessionHas('error');   // ليس عضوًا بفريقي
    // دعوة غيري: لا تُرى ولا تُقبل ولا تُرفض (404، لا 403 يكشف وجودها).
    $this->actingAs($a->owner)->post(route('teams.invitations.accept', $inv))->assertNotFound();
    $this->actingAs($a->owner)->post(route('teams.invitations.decline', $inv))->assertNotFound();

    expect(e19State())->toBe($before)->and($inv->refresh()->status)->toBe('pending');
    $this->actingAs($invitee)->post(route('teams.invitations.accept', $inv))->assertRedirect(route('teams.show', $c));                // صاحبها يقبل
    expect(e19Role($c, $invitee))->toBe('member');
});

test('68: guests can mutate nothing - every mutating route redirects to login and no state changes', function () {
    ['a' => $a, 'c' => $c, 'cMember' => $cMember, 'inv' => $inv, 'req' => $req] = e19World();
    $before = e19State();

    foreach ([
        ['post', route('teams.store'), ['name' => 'فريق زائر']], ['post', route('teams.join', $c)], ['post', route('teams.requests.store', $c)], ['delete', route('teams.requests.cancel', $c)],
        ['delete', route('teams.leave', $c)], ['patch', route('teams.update', $c), ['name' => 'x']], ['post', route('teams.deactivate', $c)],
        ['post', route('teams.invitations.store', [$c, $cMember])], ['delete', route('teams.invitations.cancel', [$c, $inv])], ['post', route('teams.requests.accept', [$c, $req])],
        ['post', route('teams.requests.decline', [$c, $req])], ['delete', route('teams.members.remove', [$c, $cMember])], ['patch', route('teams.members.role', [$c, $cMember]), ['role' => 'admin']],
        ['post', route('teams.transfer', [$c, $cMember])], ['post', route('teams.invitations.accept', $inv)], ['post', route('teams.invitations.decline', $inv)],
    ] as $item) {
        [$verb, $url, $data] = array_pad($item, 3, []);
        $this->{$verb}($url, $data)->assertRedirect(route('login'));
    }
    expect(e19State())->toBe($before);
});

test('mutations also require a verified email - an unverified user is sent to the verification notice', function () {
    $team = e19Team();
    $user = e16User();
    $user->forceFill(['email_verified_at' => null])->save();
    $before = e19State();

    foreach ([['post', route('teams.store'), ['name' => 'فريق غير موثق']], ['post', route('teams.join', $team)], ['post', route('teams.requests.store', $team)]] as $item) {
        [$verb, $url, $data] = array_pad($item, 3, []);
        $this->actingAs($user)->{$verb}($url, $data)->assertRedirect(route('verification.notice'));
    }
    expect(e19State())->toBe($before);
});

test('69/70: a user cannot submit role, owner_id, members_count, is_active or a team id - creation and settings take only whitelisted fields', function () {
    $user = e16User();
    $other = e16User();

    $this->actingAs($user)->post(route('teams.store'), [
        'name' => 'فريق صادق', 'description' => 'وصف', 'owner_id' => $other->id, 'role' => 'admin', 'members_count' => 50, 'is_active' => false, 'slug' => 'hijacked', 'team_id' => 1, 'max_members' => 5,
    ])->assertRedirect();
    $team = Team::where('name', 'فريق صادق')->firstOrFail();

    expect($team->owner_id)->toBe($user->id)->and($team->members_count)->toBe(1)->and($team->is_active)->toBeTrue()->and($team->slug)->not->toBe('hijacked')->and(e19Role($team, $user))->toBe('owner')
        ->and(TeamMembership::where('user_id', $other->id)->count())->toBe(0);

    $this->actingAs($user)->patch(route('teams.update', $team), ['description' => 'جديد', 'owner_id' => $other->id, 'members_count' => 0, 'is_active' => false, 'slug' => 'x', 'role' => 'owner'])->assertRedirect();
    expect($team->refresh()->owner_id)->toBe($user->id)->and($team->is_active)->toBeTrue()->and($team->description)->toBe('جديد')->and($team->members_count)->toBe(1);

    // role=owner / admin عبر نقطة الأدوار: يرفضه التحقق، ولا مالك ثانٍ.
    $member = e19Member($team);
    foreach (['owner', 'god', ''] as $role) {
        $this->actingAs($user)->patch(route('teams.members.role', [$team, $member]), ['role' => $role])->assertSessionHasErrors('role');
    }
    expect(e19Role($team, $member))->toBe('member')->and(TeamMembership::where('team_id', $team->id)->where('role', 'owner')->count())->toBe(1);
});

test('71: GET never mutates - the mutating URLs answer 405 to GET, and every readable team page leaves the state untouched', function () {
    ['a' => $a, 'c' => $c, 'cMember' => $cMember, 'inv' => $inv, 'req' => $req] = e19World();
    $before = e19State();

    foreach ([route('teams.join', $c), route('teams.requests.store', $c), route('teams.leave', $c), route('teams.deactivate', $c), route('teams.invitations.accept', $inv),
        route('teams.requests.accept', [$c, $req]), route('teams.members.remove', [$c, $cMember]), route('teams.transfer', [$c, $cMember])] as $url) {
        $this->actingAs($c->owner)->get($url)->assertStatus(405);
    }
    foreach ([route('teams.index'), route('teams.show', $c), route('teams.leaderboard'), route('teams.create'), route('teams.invitations'), route('teams.manage', $c), route('teams.mine')] as $url) {
        $this->actingAs($c->owner)->get($url);
    }

    expect(e19State())->toBe($before);
    $getRoutes = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with((string) $r->getName(), 'teams.') && in_array('GET', $r->methods(), true))->map->getName()->sort()->values()->all();
    expect($getRoutes)->toBe(['teams.create', 'teams.index', 'teams.invitations', 'teams.leaderboard', 'teams.manage', 'teams.mine', 'teams.show']);
});

test('CSRF: every mutating team route sits in the web group, and every POST form on the team pages carries a token', function () {
    foreach (collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with((string) $r->getName(), 'teams.') && ! in_array('GET', $r->methods(), true)) as $route) {
        expect($route->gatherMiddleware())->toContain('web');
    }

    ['a' => $a, 'c' => $c] = e19World();
    $member = e19Member($c);
    foreach ([[$c->owner, route('teams.manage', $c)], [$member, route('teams.show', $c)], [e16User(), route('teams.show', $c)], [e16User(), route('teams.create')]] as [$user, $url]) {
        $html = $this->actingAs($user)->get($url)->assertOk()->getContent();
        preg_match_all('#<form\b[^>]*method="POST"[^>]*>.*?</form>#is', $html, $forms);

        foreach ($forms[0] as $form) {
            if (str_contains($form, route('logout'))) {
                continue;                                                  // نموذج الخروج بالتخطيط
            }
            expect($form)->toContain('name="_token"');
        }
    }
});

test('72: unauthorized platform actions are refused - ordinary users and users without the permission get 403 and nothing changes', function () {
    ['c' => $c, 'cMember' => $cMember] = e19World();
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    $plain = e16User();
    $viewer = e16User();
    $viewer->givePermissionTo('teams.view');
    $before = e19State();

    foreach ([$plain, $viewer, $c->owner] as $user) {
        expect(fn () => e19Teams()->adminSetActive($user, $c, false))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class)
            ->and(fn () => e19Teams()->adminTransferOwnership($user, $c, $cMember))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class)
            ->and(fn () => e19Members()->adminRemove($user, $c, $cMember))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    }

    expect(e19State())->toBe($before);
});

test('73: a deactivated team accepts no mutation over HTTP - management is read-only, joins and requests are refused', function () {
    $team = e19Team(null, ['join_policy' => 'request']);
    $admin = e19Member($team, null, 'admin');
    $member = e19Member($team);
    e19Teams()->deactivate($team->owner, $team);
    $before = e19State();
    $target = e16User();

    foreach ([$team->owner, $admin] as $manager) {
        $this->actingAs($manager)->post(route('teams.invitations.store', [$team, $target]))->assertForbidden();
        $this->actingAs($manager)->delete(route('teams.members.remove', [$team, $member]))->assertForbidden();
    }
    $this->actingAs($team->owner)->patch(route('teams.update', $team), ['name' => 'اسم جديد'])->assertForbidden();
    $this->actingAs($team->owner)->post(route('teams.transfer', [$team, $member]))->assertForbidden();
    $this->actingAs($target)->post(route('teams.requests.store', $team))->assertRedirect()->assertSessionHas('error');
    $this->actingAs($target)->post(route('teams.join', $team))->assertRedirect()->assertSessionHas('error');

    expect(e19State())->toBe($before);
});

test('74: a frozen account cannot act on teams over HTTP, an existing frozen member stays a member, and an admin can still move a frozen owner team', function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    $team = e19Team(null, ['join_policy' => 'open']);
    $heir = e19Member($team);
    $frozen = e16User();
    $frozen->forceFill(['is_frozen' => true])->save();
    $team->owner->forceFill(['is_frozen' => true])->save();

    foreach ([$frozen, $team->owner] as $user) {
        $res = $this->actingAs($user)->post(route('teams.join', $team));
        expect($res->status())->not->toBe(200);
    }
    expect(e19Role($team, $frozen))->toBeNull()->and(e19Role($team, $team->owner))->toBe('owner')->and($team->refresh()->is_active)->toBeTrue();

    $admin = e16User();
    $admin->givePermissionTo('teams.manage');
    e19Teams()->adminTransferOwnership($admin, $team, $heir);                       // الإدارة تتدخل (لا تفكيك تلقائي)
    expect(e19Role($team, $heir))->toBe('owner')->and(\App\Models\OperationalAuditLog::where('action', 'team_ownership_transferred')->count())->toBe(1);
});

test('rate limits protect team creation, invitations and joins - the limit answers 429 and the state stops changing', function () {
    $user = e16User();
    foreach (range(1, 2) as $i) {
        $this->actingAs($user)->post(route('teams.store'), ['name' => 'فريق الحد '.$i]);
    }
    $this->actingAs($user)->post(route('teams.store'), ['name' => 'فريق الحد 3'])->assertStatus(429);                       // 2 في الدقيقة

    $team = e19Team();
    $joinUser = e16User();
    e19Team($joinUser);
    foreach (range(1, 8) as $i) {
        $this->actingAs($joinUser)->post(route('teams.join', $team));
    }
    $this->actingAs($joinUser)->post(route('teams.join', $team))->assertStatus(429);                                          // 8 في الدقيقة

    $inviter = e19Team()->owner;
    $inviterTeam = Team::where('owner_id', $inviter->id)->first();
    foreach (range(1, 10) as $i) {
        $this->actingAs($inviter)->post(route('teams.invitations.store', [$inviterTeam, e16User()]));
    }
    $this->actingAs($inviter)->post(route('teams.invitations.store', [$inviterTeam, e16User()]))->assertStatus(429);          // 10 في الدقيقة
    expect(TeamInvitation::where('team_id', $inviterTeam->id)->count())->toBe(10);
});

test('invitation tokens are unguessable ULIDs - never sequential ids', function () {
    ['inv' => $inv, 'req' => $req] = e19World();

    expect($inv->public_id)->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/')->and($req->public_id)->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/');
    $this->actingAs(e16User())->post("/teams/invitations/{$inv->id}/accept")->assertNotFound();               // المعرّف الرقمي لا يعمل
});

function e19ProductionFiles(): array
{
    return array_merge(
        glob(app_path('Services/Teams/*.php')), glob(app_path('Models/Team*.php')), [app_path('Models/CompetitiveEventTeamResult.php')],
        glob(app_path('Http/Controllers/Team*.php')), glob(app_path('Http/Requests/Team*.php')), glob(app_path('Listeners/SendTeam*.php')),
        [app_path('Listeners/QueueTeamRankingFinalization.php'), app_path('Jobs/ComputeTeamRankings.php'), app_path('Console/Commands/ProcessTeamsLifecycle.php'),
            app_path('Services/Analytics/TeamAnalyticsService.php'), app_path('Policies/TeamPolicy.php')],
        glob(app_path('Filament/Resources/TeamResource.php')), glob(app_path('Filament/Resources/TeamResource/*/*.php')),
        glob(app_path('Events/Team*.php')), glob(resource_path('views/teams/*.blade.php')), [resource_path('views/competitions/_teams-standings.blade.php')],
    );
}

test('static economy audit: teams are not a wallet, a currency, XP, quests, streaks, rewards or a multiplier - no E19 code touches any economy service', function () {
    $files = e19ProductionFiles();
    expect(count($files))->toBeGreaterThan(30);
    $forbidden = ['CurrencyWalletService', 'creditPending', 'creditAvailable', 'grantXp', 'XpService', 'InventoryService', 'EntitlementService', 'StorePurchase', 'CompetitiveRewardGrant',
        'CompetitiveRewardDistributionService', 'ProgressionRewardService', 'AchievementService', 'QuestService', 'StreakService', 'PuzzleAttemptService', 'wallet', 'multiplier', 'pending_balance', 'total_xp', 'treasury', 'donat'];

    foreach ($files as $file) {
        $code = e19Code($file);

        foreach ($forbidden as $needle) {
            expect(preg_match('/\b'.preg_quote($needle, '/').'/i', $code))->toBe(0, basename($file)." must not reference {$needle}");     // بداية كلمة: RequestService ليست QuestService
        }
    }
    foreach (['teams', 'team_memberships', 'team_invitations', 'team_join_requests', 'competitive_event_team_results'] as $table) {
        foreach (Schema::getColumnListing($table) as $column) {
            expect($column)->not->toMatch('/(^|_)(currency|wallet|balance|xp|reward|prize|cash|price|fee|donation|treasury|multiplier)(_|$)/i');     // رموز العمود (expires_at ليست xp)
        }
    }
});

test('static client-trust audit: no team controller reads owner, team, score or rank from the request - the only role input is the validated admin|member of the role endpoint', function () {
    foreach (glob(app_path('Http/Controllers/Team*.php')) as $file) {
        $code = e19Code($file);
        expect(preg_match('/\$request->(input|get|post|query|only|all|integer|string)\(\s*[\'"](owner|owner_id|team|team_id|score|rank|members_count|is_active|slug|status)[\'"]/', $code))->toBe(0, basename($file));
    }
    foreach (glob(app_path('Http/Requests/Team*.php')) as $file) {
        expect(e19Code($file))->not->toMatch('/owner|team_id|members_count|is_active|slug|role/');
    }
    $mgmt = e19Code(app_path('Http/Controllers/TeamManagementController.php'));
    expect(substr_count($mgmt, "'role'"))->toBe(2)->and($mgmt)->toContain("'in:admin,member'")->and($mgmt)->toContain("\$data['role']");   // validate + القراءة من المُتحقَّق منه فقط
});

test('static privacy and hygiene audit: no private fields in the team views, no raw HTML, no debug leftovers in any E19 file', function () {
    foreach (glob(resource_path('views/teams/*.blade.php')) as $view) {
        $code = e19Code($view);

        foreach (['->email', "'email'", '->phone', 'phone', 'wallet', 'password', 'is_frozen', 'idempotency', 'remember_token'] as $private) {
            expect(stripos($code, $private))->toBeFalse(basename($view)." must not touch {$private}");     // حقول فعلية: hasVerifiedEmail() ليست بريدًا
        }
        expect($code)->not->toContain('{!!');
    }
    foreach (e19ProductionFiles() as $file) {
        $code = e19Code($file);

        foreach (['/\bdd\(/', '/\bdump\(/', '/\bray\(/', '/\bvar_dump\(/', '/TODO/', '/FIXME/', '/console\.log/'] as $bad) {
            expect(preg_match($bad, $code))->toBe(0, basename($file)." must not match {$bad}");
        }
    }
});
