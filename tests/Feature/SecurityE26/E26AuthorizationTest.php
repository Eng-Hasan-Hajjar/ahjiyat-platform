<?php

require_once __DIR__.'/_support.php';
require_once __DIR__.'/../TeamCompetition/TeamCompetitionTestHelpers.php';

use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\CompetitiveEventResult;
use App\Models\Friendship;
use App\Models\GameSession;
use App\Models\Puzzle;
use App\Models\Team;
use App\Models\TeamChallenge;
use App\Models\TeamInvitation;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\AuthorizationSafetyService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    e17Freeze();
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
});
afterEach(fn () => Carbon::setTestNow());

/** بصمة حالة الفرق/الدعوات/الطلبات/التحديات: أي تغيير غير مرغوب يظهر. */
function e26_team_state(): string
{
    return json_encode([
        DB::table('teams')->orderBy('id')->get()->all(), DB::table('team_memberships')->orderBy('id')->get()->all(),
        DB::table('team_invitations')->orderBy('id')->get()->all(), DB::table('team_join_requests')->orderBy('id')->get()->all(),
        DB::table('team_challenges')->orderBy('id')->get()->all(),
    ]);
}

test('13: a user cannot mutate another user or elevate themselves through the profile endpoints (forged id/role/frozen/password are ignored)', function () {
    $attacker = e26_user(['name' => 'Attacker']);
    $victim = e26_user(['name' => 'Victim'])->fresh();
    $victimBefore = $victim->only(['name', 'email', 'password', 'is_frozen', 'role']);

    $this->actingAs($attacker)->from('/profile')->patch('/profile', [
        'name' => 'Attacker 2', 'email' => $attacker->email,
        'id' => $victim->id, 'user_id' => $victim->id, 'public_id' => $victim->public_id,
        'is_frozen' => 1, 'role' => 'admin', 'password' => 'Hacked-Passw0rd!', 'email_verified_at' => null,
    ])->assertSessionHasNoErrors();

    expect($victim->fresh()->only(['name', 'email', 'password', 'is_frozen', 'role']))->toBe($victimBefore);
    $attacker->refresh();
    expect($attacker->name)->toBe('Attacker 2')->and((bool) $attacker->is_frozen)->toBeFalse()->and($attacker->role)->not->toBe('admin')
        ->and(Hash::check('password', $attacker->password))->toBeTrue()->and($attacker->hasVerifiedEmail())->toBeTrue()
        ->and($attacker->hasRole('super-admin') || $attacker->hasRole('administrator'))->toBeFalse();
});

test('14: friend-request IDOR - a third user cannot accept, decline or cancel a request between two other users', function () {
    $b = e26_user();
    $c = e26_user();
    $a = e26_user();
    e16Svc()->sendRequest($b, $c);
    $before = Friendship::query()->get()->toJson();

    foreach ([['post', 'accept', $b], ['post', 'decline', $b], ['delete', 'cancel', $b], ['post', 'accept', $c], ['post', 'decline', $c], ['delete', 'cancel', $c]] as [$method, $action, $other]) {
        $this->actingAs($a)->{$method}(route("friends.requests.{$action}", $other));
    }

    expect(Friendship::query()->get()->toJson())->toBe($before)->and(e16Svc()->friendsCount($a))->toBe(0)->and(e16Svc()->friendsCount($c))->toBe(0);
});

test('15: team-management IDOR - the owner of team A and a plain member of team C can do nothing to team C', function () {
    $a = e19Team(null, ['name' => 'فريق أ']);
    $c = e19Team(null, ['name' => 'فريق ج']);
    $cMember = e19Member($c);
    $cAdmin = e19Member($c, null, 'admin');
    $victim = e19Member($c);
    $before = e26_team_state();

    foreach ([$a->owner, $cMember] as $attacker) {
        $this->actingAs($attacker)->patch(route('teams.update', $c), ['name' => 'Hijacked'])->assertForbidden();
        $this->actingAs($attacker)->post(route('teams.deactivate', $c))->assertForbidden();
        $this->actingAs($attacker)->patch(route('teams.members.role', [$c, $victim]), ['role' => 'admin'])->assertForbidden();
        $this->actingAs($attacker)->delete(route('teams.members.remove', [$c, $victim]))->assertForbidden();
        $this->actingAs($attacker)->post(route('teams.transfer', [$c, $cAdmin]))->assertForbidden();
        $this->actingAs($attacker)->get(route('teams.manage', $c))->assertForbidden();
    }

    expect(e26_team_state())->toBe($before);
});

test('16: team-invitation IDOR - a user the invitation is not addressed to cannot accept it, and a manager cannot cancel another team invitation', function () {
    $c = e19Team(null, ['name' => 'فريق ج']);
    $other = e19Team(null, ['name' => 'فريق آخر']);
    $invitee = e26_user();
    $intruder = e26_user();
    $invitation = e19Invites()->invite($c->owner, $c, $invitee);
    $before = e26_team_state();

    $this->actingAs($intruder)->post(route('teams.invitations.accept', $invitation))->assertStatus(404);
    $this->actingAs($intruder)->post(route('teams.invitations.decline', $invitation))->assertStatus(404);
    $this->actingAs($other->owner)->delete(route('teams.invitations.cancel', [$other, $invitation]))->assertNotFound();   // دعوة فريق آخر عبر مسار فريقي
    $this->actingAs($other->owner)->delete(route('teams.invitations.cancel', [$c, $invitation]))->assertForbidden();       // عبر مسار فريقها هي

    expect(e26_team_state())->toBe($before)->and(TeamMembership::where('user_id', $intruder->id)->exists())->toBeFalse();
});

test('17: team-challenge IDOR - owners and members of unrelated teams can read or act on nothing of another pair of teams', function () {
    [$a, $b, $am, $bm] = e20Pair();
    [$c, $d, $cm, $dm] = e20Pair();
    $pending = e20Create($c, $d, [$cm[0]]);
    $before = e26_team_state();

    foreach ([$a->owner, $am[1]] as $attacker) {
        $this->actingAs($attacker)->get(route('teams.challenges.show', $pending))->assertNotFound();
        $this->actingAs($attacker)->post(route('teams.challenges.accept', $pending), ['roster' => e20Ids([$attacker])])->assertNotFound();
        $this->actingAs($attacker)->post(route('teams.challenges.decline', $pending))->assertNotFound();
        $this->actingAs($attacker)->delete(route('teams.challenges.cancel', $pending))->assertNotFound();
        $this->actingAs($attacker)->put(route('teams.challenges.roster', $pending), ['roster' => e20Ids([$attacker])])->assertNotFound();
    }

    expect(e26_team_state())->toBe($before);
});

test('18: direct-chat IDOR - a non-participant gets 404 on a thread history and read-marking, even with the real public id', function () {
    [$a, $b] = chatFriends();
    $intruder = e26_user();
    chatDm($this, $a, $b, 'سر بين اثنين')->assertCreated();
    $thread = ChatThread::query()->firstOrFail();

    $this->actingAs($intruder)->getJson(route('chat.messages', $thread))->assertNotFound();
    $this->actingAs($intruder)->postJson(route('chat.read', $thread))->assertNotFound();
    $this->actingAs($intruder)->get(route('messages.direct', $a))->assertStatus(403)->assertDontSee('سر بين اثنين');
});

test('19: team chat - an outsider, and a member who left, can neither read nor write', function () {
    [$team, $owner, $admin, $member] = chatTeam();
    $outsider = e26_user();
    chatThreads()->forTeam($team);

    expect($this->actingAs($outsider)->get(route('teams.chat', $team))->status())->toBeIn([403, 404])
        ->and($this->actingAs($outsider)->postJson(route('teams.chat.send', $team), ['body' => 'تسلل'])->status())->toBeIn([403, 404]);
    expect(ChatMessage::count())->toBe(0);

    e19Members()->leave($member, $team);
    expect($this->actingAs($member->refresh())->get(route('teams.chat', $team))->status())->toBeIn([403, 404])
        ->and($this->actingAs($member)->postJson(route('teams.chat.send', $team), ['body' => 'خرجت'])->status())->toBeIn([403, 404]);
    expect(ChatMessage::count())->toBe(0);
});

test('20: only the author can edit or delete a message - another member, an admin of the room and an outsider are all refused and the body is untouched', function () {
    [$team, $owner, $admin, $member] = chatTeam();
    $outsider = e26_user();
    $this->actingAs($member)->postJson(route('teams.chat.send', $team), ['body' => 'رسالتي'])->assertCreated();
    $message = ChatMessage::sole();

    foreach ([$owner, $admin, $outsider] as $other) {
        $this->actingAs($other)->patchJson(route('chat.messages.update', $message), ['body' => 'معدّلة'])->assertStatus($other->is($outsider) ? 404 : 403);
        $this->actingAs($other)->deleteJson(route('chat.messages.destroy', $message))->assertStatus($other->is($outsider) ? 404 : 403);
    }

    $message->refresh();
    expect($message->body)->toBe('رسالتي')->and($message->deleted_at)->toBeNull();
    $this->actingAs($member)->patchJson(route('chat.messages.update', $message), ['body' => 'عدّلتها'])->assertOk();
    expect($message->refresh()->body)->toBe('عدّلتها');
});

test('21: competitive submit spoof - forged score, rank, winner and duration in the body never reach the stored result', function () {
    $event = e17Event();
    $player = e26_user();
    $this->actingAs($player)->post(route('competitions.register', $event))->assertRedirect();
    $this->actingAs($player)->post(route('competitions.start', $event))->assertRedirect();
    e17Forward(20_000);

    $this->actingAs($player)->post(route('competitions.submit', $event), [
        'answer' => E17_ANSWER, 'score' => 999999, 'final_rank' => 1, 'rank' => 1, 'winner' => $player->id, 'duration_ms' => 1,
        'elapsed_seconds' => 0, 'is_correct' => 1, 'user_id' => 1, 'game_session_id' => 999, 'submission' => ['score' => 999999, 'moves' => 0],
    ]);

    $result = CompetitiveEventResult::query()->first();
    expect($result)->not->toBeNull()
        ->and($result->user_id)->toBe($player->id)
        ->and($result->score)->not->toBe(999999)
        ->and($result->duration_ms)->toBeGreaterThanOrEqual(19_000)
        ->and($result->game_session_id)->toBe(GameSession::query()->where('user_id', $player->id)->value('id'));
});

test('22: wallet spoof - balances and ownership in the request are ignored by every economy endpoint', function () {
    $user = e26_user();
    $victim = e26_user();
    $this->actingAs($user)->from('/redemption/create')->post(route('redemption.store'), [
        'gems_amount' => 999999, 'reward_description' => 'x', 'user_id' => $victim->id, 'wallet_id' => $victim->wallet->id, 'available_balance' => 999999,
    ])->assertSessionHasErrors('gems_amount');

    $this->actingAs($user)->patch('/profile', ['name' => 'N', 'email' => $user->email, 'available_balance' => 99999, 'pending_balance' => 99999]);

    expect($user->wallet->refresh()->available_balance)->toBe(0)->and($user->wallet->pending_balance)->toBe(0)
        ->and($victim->wallet->refresh()->available_balance)->toBe(0)->and(\App\Models\RedemptionRequest::count())->toBe(0);
});

test('23: reward spoof - a forged gems_awarded/gem_reward in the attempt body never changes the credited reward', function () {
    $user = e26_user();
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح', 'gem_reward' => 7, 'max_attempts' => 3]);

    $this->actingAs($user)->post(route('puzzles.attempt', $puzzle), ['answer' => 'صح', 'gem_reward' => 5000, 'gems_awarded' => 5000, 'reward' => 5000, 'amount' => 5000, 'currency_id' => 99])->assertSessionHas('success');

    expect($user->wallet->refresh()->pending_balance)->toBe(7);
});

test('24: ordinary users and guests cannot open the admin panel or its resources', function () {
    $user = e26_user();

    foreach (['/admin', '/admin/users', '/admin/settings', '/admin/puzzles', '/admin/redemption-requests'] as $path) {
        expect($this->actingAs($user)->get($path)->status())->toBeIn([403, 404])->and(auth()->check())->toBeTrue();
    }

    auth()->logout();
    foreach (['/admin', '/admin/users', '/admin/settings'] as $path) {
        $this->get($path)->assertRedirect();
    }
});

test('25: a limited admin cannot escalate - granting super-admin, or a role holding permissions they lack, is refused', function () {
    $support = e26_user();
    $support->assignRole('support');
    $support->givePermissionTo('roles.assign');
    $target = e26_user();
    $safety = app(AuthorizationSafetyService::class);

    expect(fn () => $safety->assertCanSyncRoles($support, $target, ['super-admin']))->toThrow(HttpException::class)
        ->and(fn () => $safety->assertCanSyncRoles($support, $support, ['administrator']))->toThrow(HttpException::class)
        ->and(fn () => $safety->assertCanSyncRoles($support, $target, ['content-manager']))->toThrow(HttpException::class);

    $normalAdmin = e26_user();
    $normalAdmin->assignRole('administrator');
    $superAdmin = e26_user();
    $superAdmin->assignRole('super-admin');
    expect(fn () => $safety->assertCanModifyUserAuthorization($normalAdmin, $superAdmin))->toThrow(HttpException::class);
});

test('26: the last super admin can be neither deleted nor stripped of the role', function () {
    $last = e26_user();
    $last->assignRole('super-admin');
    $safety = app(AuthorizationSafetyService::class);

    expect(fn () => $safety->assertCanDeleteUser($last))->toThrow(HttpException::class)
        ->and(fn () => $safety->assertCanSyncRoles($last, $last, []))->toThrow(HttpException::class);

    $second = e26_user();
    $second->assignRole('super-admin');
    $safety->assertCanDeleteUser($last);   // لم يعد الأخير
    expect(true)->toBeTrue();
});
