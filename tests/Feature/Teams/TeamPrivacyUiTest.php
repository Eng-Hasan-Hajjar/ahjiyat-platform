<?php

require_once __DIR__.'/TeamTestHelpers.php';

use App\Models\Team;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(fn () => e17Freeze());
afterEach(fn () => Carbon::setTestNow());

test('39: a private team never exposes its roster to the public or to non-members - and is absent from the directory - but members see it', function () {
    $team = e19Team(null, ['name' => 'فريق سري', 'visibility' => 'private']);
    $member = e19Member($team, e16User(['name' => 'Secret Member']));
    $outsider = e16User();

    foreach ([null, $outsider] as $viewer) {
        $page = ($viewer ? $this->actingAs($viewer) : $this)->get(route('teams.show', $team))->assertOk()->assertSee('قائمة أعضاء هذا الفريق خاصة')->assertDontSee('Secret Member');
        expect($page->viewData('roster'))->toHaveCount(0);
    }
    $this->actingAs($member)->get(route('teams.show', $team))->assertOk()->assertSee('Secret Member')->assertDontSee('قائمة أعضاء هذا الفريق خاصة');
    $this->get(route('teams.index'))->assertOk()->assertDontSee('فريق سري');
});

test('40/41/42: a public team shows only safe fields - names, roles, counts - and never an email, a phone or any private field', function () {
    $team = e19Team(e16User(['name' => 'Owner Person', 'email' => 'owner.private@secret.test']), ['name' => 'فريق علني', 'description' => 'وصف عام']);
    $member = e19Member($team, e16User(['name' => 'Member Person', 'email' => 'member.private@secret.test']));
    if (Schema::hasColumn('users', 'phone')) {
        DB::table('users')->whereIn('id', [$team->owner_id, $member->id])->update(['phone' => '+905551234567']);
    }

    foreach ([route('teams.show', $team), route('teams.index'), route('teams.leaderboard')] as $url) {
        $html = $this->actingAs(e16User())->get($url)->assertOk()->getContent();
        expect($html)->not->toContain('secret.test')->and($html)->not->toContain('+905551234567')->and($html)->not->toMatch('/is_frozen|idempotency|competitive-reward|remember_token|email_verified|team_memberships/i');
    }
    $this->get(route('teams.show', $team))->assertOk()->assertSee('Owner Person')->assertSee('Member Person')->assertSee('مالك')->assertSee('2 / 50 عضو')->assertSee('وصف عام');

    // صفحة الإدارة والدعوات كذلك.
    $manage = $this->actingAs($team->owner)->get(route('teams.manage', $team))->assertOk()->getContent();
    expect($manage)->not->toContain('secret.test');
    e19Invites()->invite($team->owner, $team, $invitee = e16User(['email' => 'invitee.private@secret.test']));
    expect($this->actingAs($invitee)->get(route('teams.invitations'))->assertOk()->getContent())->not->toContain('secret.test');
});

test('43/C10: the public profile shows the team badge only for an active public team - never for a private or deactivated one', function () {
    $public = e19Team(null, ['name' => 'فريق الملف']);
    $member = e19Member($public);
    $private = e19Team(null, ['name' => 'فريق خاص جدًا', 'visibility' => 'private']);
    $privateMember = e19Member($private);
    $dead = e19Team(null, ['name' => 'فريق معطّل']);
    $deadMember = e19Member($dead);
    e19Teams()->deactivate($dead->owner, $dead);

    $this->get(route('players.show', $member))->assertOk()->assertSee('فريق الملف')->assertSee(route('teams.show', $public), false);
    $this->get(route('players.show', $privateMember))->assertOk()->assertDontSee('فريق خاص جدًا');
    $this->get(route('players.show', $deadMember))->assertOk()->assertDontSee('فريق معطّل');
    $this->get(route('players.show', e16User()))->assertOk()->assertDontSee('الفريق:');
});

test('44/C2/C3: the directory searches by team name only (wildcards literal), paginates 12 per page, lists public active teams, and exposes only slugs', function () {
    foreach (range(1, 14) as $i) {
        e19Team(null, ['name' => sprintf('Alpha Team %02d', $i)]);
    }
    e19Team(null, ['name' => 'نجوم_100']);
    e19Team(null, ['name' => 'Hidden Gem', 'visibility' => 'private']);
    $dead = e19Team(null, ['name' => 'Alpha Dead']);
    e19Teams()->deactivate($dead->owner, $dead);

    $p1 = $this->get(route('teams.index'))->assertOk();
    $p2 = $this->get(route('teams.index', ['page' => 2]))->assertOk();
    expect($p1->viewData('teams')->count())->toBe(12)->and($p1->viewData('teams')->total())->toBe(15)->and($p2->viewData('teams')->count())->toBe(3);

    $this->get(route('teams.index', ['q' => 'alpha team 0']))->assertOk()->assertSee('Alpha Team 01')->assertDontSee('Alpha Team 12')->assertDontSee('نجوم');
    $this->get(route('teams.index', ['q' => 'م_1']))->assertOk()->assertSee('نجوم_100')->assertDontSee('Alpha Team');          // _ حرفية لا "أي حرف"
    $this->get(route('teams.index', ['q' => '_']))->assertOk()->assertSee('نجوم_100')->assertDontSee('Alpha Team 01');
    $this->get(route('teams.index', ['q' => '%']))->assertOk()->assertDontSee('نجوم_100')->assertDontSee('Alpha Team 01');   // % حرفية: لا اسم يحويها ولا تطابق الكل
    $this->get(route('teams.index', ['q' => 'Hidden']))->assertOk()->assertDontSee('Hidden Gem');
    expect($this->get(route('teams.index', ['q' => 'Alpha Dead']))->assertOk()->viewData('teams')->total())->toBe(0);      // الفريق المعطَّل خارج الدليل (الاسم يظهر فقط بخانة البحث)

    $html = $p1->getContent();
    preg_match_all('#href="[^"]*/teams/([^"/]+)"#', $html, $m);
    expect(collect($m[1])->reject(fn ($s) => in_array($s, ['leaderboard', 'create', 'mine', 'invitations'], true))->every(fn ($s) => ! ctype_digit($s)))->toBeTrue();    // لا معرّفات رقمية داخلية
});

test('45/E16/E17: team content is escaped everywhere - tags are stripped on input and any stored markup is rendered inert on output', function () {
    $team = e19Team(null, ['name' => 'فريق آمن', 'description' => '<script>alert(1)</script>وصف <b>غامق</b><img src=x onerror=alert(2)>']);
    expect($team->description)->not->toContain('<')->and($team->description)->toContain('وصف');

    // حتى لو دخل markup خام مباشرةً بالقاعدة، يُهرَّب عند العرض.
    DB::table('teams')->where('id', $team->id)->update(['description' => '<img src=x onerror=alert(1)><script>boom()</script>']);
    DB::table('teams')->where('id', $team->id)->update(['name' => '<b>x</b>']);
    $team->refresh();

    foreach ([route('teams.show', $team), route('teams.index'), route('teams.leaderboard')] as $url) {
        $html = $this->get($url)->getContent();
        expect($html)->not->toContain('<img src=x')->and($html)->not->toContain('<script>boom()')->and($html)->not->toContain('<b>x</b>');
    }
    $this->get(route('teams.show', $team))->assertSee('&lt;img src=x onerror=alert(1)&gt;', false);

    $code = '';
    foreach (glob(resource_path('views/teams/*.blade.php')) as $view) {
        $code .= file_get_contents($view);
    }
    expect($code)->not->toContain('{!!');
});

test('C8/C13: the join button follows the real state - guest, open, request, invite-only, full, requested, invited, member, owner, other team, inactive', function () {
    $open = e19Team(null, ['join_policy' => 'open']);
    $request = e19Team(null, ['join_policy' => 'request']);
    $inviteOnly = e19Team(null, ['join_policy' => 'invite_only']);
    $full = e19Team(null, ['join_policy' => 'open', 'max_members' => 2]);
    e19Member($full);
    $viewer = e16User();

    $this->get(route('teams.show', $open))->assertSee('سجّل الدخول للانضمام');
    $this->actingAs($viewer)->get(route('teams.show', $open))->assertSee('انضم الآن')->assertSee(route('teams.join', $open), false);
    $this->actingAs($viewer)->get(route('teams.show', $request))->assertSee('طلب انضمام');
    $this->actingAs($viewer)->get(route('teams.show', $inviteOnly))->assertSee('الانضمام بدعوة فقط');
    $this->actingAs($viewer)->get(route('teams.show', $full))->assertSee('اكتمل عدد الأعضاء')->assertDontSee('انضم الآن');

    e19Requests()->create($viewer, $request);
    $this->actingAs($viewer)->get(route('teams.show', $request))->assertSee('إلغاء طلب الانضمام');

    e19Invites()->invite($inviteOnly->owner, $inviteOnly, $viewer);
    $this->actingAs($viewer)->get(route('teams.show', $inviteOnly))->assertSee('لديك دعوة لهذا الفريق');

    $this->actingAs($open->owner)->get(route('teams.show', $open))->assertSee('إدارة الفريق')->assertDontSee('مغادرة الفريق');          // المالك لا يغادر
    $member = e19Member($open);
    $this->actingAs($member)->get(route('teams.show', $open))->assertSee('مغادرة الفريق')->assertDontSee('إدارة الفريق');
    $this->actingAs($member)->get(route('teams.show', $request))->assertSee('أنت عضو في فريق آخر');

    e19Teams()->deactivate($request->owner, $request);
    $this->actingAs(e16User())->get(route('teams.show', $request))->assertSee('غير مفعَّل')->assertDontSee(route('teams.requests.store', $request), false);   // لا نموذج طلب (نص "بطلب انضمام" شارة فقط)
});

test('C7/C8: the management page shows controls by the real role only - owner everything, admin members/requests/invitations, member and outsiders get 403', function () {
    $team = e19Team(null, ['join_policy' => 'request']);
    $admin = e19Member($team, null, 'admin');
    $member = e19Member($team);
    $other = e19Member($team);
    e19Requests()->create(e16User(['name' => 'Asker One']), $team);

    $own = $this->actingAs($team->owner)->get(route('teams.manage', $team))->assertOk();
    $own->assertSee('إعدادات الفريق')->assertSee('نقل الملكية')->assertSee('ترقية إلى مشرف')->assertSee('تعطيل الفريق')->assertSee('Asker One')->assertSee(route('teams.members.remove', [$team, $member]), false);

    $adm = $this->actingAs($admin)->get(route('teams.manage', $team))->assertOk();
    $adm->assertDontSee('إعدادات الفريق')->assertDontSee('نقل الملكية')->assertDontSee('ترقية إلى مشرف')->assertDontSee('تعطيل الفريق')->assertSee(route('teams.members.remove', [$team, $member]), false)
        ->assertDontSee(route('teams.members.remove', [$team, $team->owner]), false)->assertDontSee(route('teams.members.remove', [$team, $admin]), false)->assertSee('Asker One');

    $this->actingAs($member)->get(route('teams.manage', $team))->assertForbidden();
    $this->actingAs(e16User())->get(route('teams.manage', $team))->assertForbidden();
    auth()->forgetGuards();                                                         // actingAs يبقى سارياً: زائر حقيقي للفحص التالي
    $this->get(route('teams.manage', $team))->assertRedirect(route('login'));
    expect($other->exists)->toBeTrue();

    // تعطيل الفريق: الصفحة للقراءة فقط.
    e19Teams()->deactivate($team->owner, $team);
    $this->actingAs($team->owner)->get(route('teams.manage', $team))->assertOk()->assertSee('للقراءة فقط')->assertDontSee('إعدادات الفريق')->assertDontSee('دعوة لاعب');
});

test('the invite search on the management page finds players by name, hides existing team members from invitation, and requires the manage permission', function () {
    $team = e19Team();
    $free = e16User(['name' => 'Zed Findable']);
    $busy = e16User(['name' => 'Zed Busy']);
    e19Team($busy);
    $member = e19Member($team);

    $page = $this->actingAs($team->owner)->get(route('teams.manage', [$team, 'q' => 'Zed']))->assertOk()->assertSee('Zed Findable')->assertSee('Zed Busy')->assertSee('عضو بفريق');
    $page->assertSee(route('teams.invitations.store', [$team, $free]), false)->assertDontSee(route('teams.invitations.store', [$team, $busy]), false);

    // مجرد عضو لا يرى البحث (403 للصفحة أصلًا).
    $this->actingAs($member)->get(route('teams.manage', [$team, 'q' => 'Zed']))->assertForbidden();
});

test('the invitations page lists only my pending, unexpired invitations', function () {
    $team = e19Team(null, ['name' => 'فريق دعوتي']);
    $other = e19Team(null, ['name' => 'فريق غيري']);
    $me = e16User();
    $someone = e16User();
    e19Invites()->invite($team->owner, $team, $me);
    e19Invites()->invite($other->owner, $other, $someone);

    $this->actingAs($me)->get(route('teams.invitations'))->assertOk()->assertSee('فريق دعوتي')->assertDontSee('فريق غيري');
    Carbon::setTestNow(now()->addDays(8));
    $this->actingAs($me)->get(route('teams.invitations'))->assertOk()->assertSee('لا دعوات معلّقة');
    auth()->forgetGuards();
    $this->get(route('teams.invitations'))->assertRedirect(route('login'));
});

test('C12/C2: the navigation links the teams directory, and the literal pages are not swallowed by the slug route', function () {
    $verified = e16User();

    $this->get(route('competitions.index'))->assertOk()->assertSee(route('teams.index'), false);
    $this->actingAs($verified)->get('/teams/create')->assertOk()->assertSee('إنشاء فريق');
    $this->actingAs($verified)->get('/teams/leaderboard')->assertOk()->assertSee('جدول الفرق');
    $this->actingAs($verified)->get('/teams/invitations')->assertOk();
    $this->actingAs($verified)->get('/teams/mine')->assertRedirect(route('teams.index'));
    auth()->forgetGuards();
    $this->get('/teams/create')->assertRedirect(route('login'));
    expect(Team::where('slug', 'create')->exists())->toBeFalse();

    $team = e19Team($verified);
    $this->actingAs($verified)->get('/teams/mine')->assertRedirect(route('teams.show', $team));
    $this->actingAs($verified)->get('/teams/create')->assertRedirect(route('teams.show', $team));        // له فريق بالفعل
});
