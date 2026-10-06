<?php

require_once __DIR__.'/TeamTestHelpers.php';

use App\Models\TeamJoinRequest;
use App\Models\TeamMembership;
use App\Services\Social\BlockService;
use App\Services\Teams\TeamException;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;

beforeEach(fn () => e17Freeze());
afterEach(fn () => Carbon::setTestNow());

function e19RequestTeam(array $attrs = [])
{
    return e19Team(null, $attrs + ['join_policy' => 'request']);
}

test('29/30/31: the join policy decides - request-policy takes requests, invite-only takes none, open joins directly (and takes no request)', function () {
    $requestTeam = e19RequestTeam();
    $inviteOnly = e19Team(null, ['join_policy' => 'invite_only']);
    $open = e19Team(null, ['join_policy' => 'open']);
    $user = e16User();

    $request = e19Requests()->create($user, $requestTeam);
    expect($request->status)->toBe('pending')->and($request->pending_key)->toBe("{$requestTeam->id}:{$user->id}")->and($request->public_id)->toHaveLength(26);

    expect(fn () => e19Requests()->create(e16User(), $inviteOnly))->toThrow(TeamException::class, 'بدعوة فقط')
        ->and(fn () => e19Requests()->create(e16User(), $open))->toThrow(TeamException::class, 'انضم مباشرة')
        ->and(fn () => e19Members()->joinOpen(e16User(), $requestTeam))->toThrow(TeamException::class, 'لا يقبل الانضمام المباشر')
        ->and(fn () => e19Members()->joinOpen(e16User(), $inviteOnly))->toThrow(TeamException::class);

    $joiner = e16User();
    expect(e19Members()->joinOpen($joiner, $open)->role)->toBe('member')->and(e19Count($open))->toBe(2);
});

test('32: a duplicate pending request is refused - at the service and by the database', function () {
    $team = e19RequestTeam();
    $user = e16User();
    e19Requests()->create($user, $team);

    expect(fn () => e19Requests()->create($user, $team))->toThrow(TeamException::class, 'معلّق')
        ->and(fn () => TeamJoinRequest::create(['team_id' => $team->id, 'user_id' => $user->id, 'status' => 'pending', 'pending_key' => "{$team->id}:{$user->id}"]))->toThrow(UniqueConstraintViolationException::class);
    expect(TeamJoinRequest::where('status', 'pending')->count())->toBe(1);
});

test('33: the requester cancels their own pending request (and may ask again) - nobody cancels it for them', function () {
    $team = e19RequestTeam();
    $user = e16User();
    e19Requests()->create($user, $team);

    expect(fn () => e19Requests()->cancel(e16User(), $team))->toThrow(TeamException::class, 'لا يوجد طلب')
        ->and(e19Requests()->cancel($user, $team)->status)->toBe('cancelled')->and(fn () => e19Requests()->cancel($user, $team))->toThrow(TeamException::class);

    $again = e19Requests()->create($user, $team);
    expect($again->status)->toBe('pending')->and(TeamJoinRequest::where('user_id', $user->id)->count())->toBe(2);
});

test('34/35: the owner and an admin accept requests - a member, an outsider and another team admin cannot', function () {
    $team = e19RequestTeam();
    $admin = e19Member($team, null, 'admin');
    $member = e19Member($team);
    $other = e19Team();
    [$r1, $r2] = [e19Requests()->create(e16User(), $team), e19Requests()->create(e16User(), $team)];

    foreach ([$member, e16User(), $other->owner] as $actor) {
        expect(fn () => e19Requests()->accept($actor, $team, $r1))->toThrow(TeamException::class, 'مالك الفريق أو مشرفيه')
            ->and(fn () => e19Requests()->decline($actor, $team, $r1))->toThrow(TeamException::class);
    }
    expect(fn () => e19Requests()->accept($other->owner, $other, $r1))->toThrow(TeamException::class, 'غير موجود');          // طلب فريق آخر

    e19Requests()->accept($admin, $team, $r1);
    e19Requests()->accept($team->owner, $team, $r2);

    expect(e19Role($team, $r1->user))->toBe('member')->and(e19Role($team, $r2->user))->toBe('member')->and($r1->refresh()->status)->toBe('accepted')
        ->and($r1->decided_by)->toBe($admin->id);
});

test('36: capacity is re-checked at acceptance - a full team refuses and the request stays pending', function () {
    $team = e19RequestTeam(['max_members' => 2]);
    $request = e19Requests()->create(e16User(), $team);
    $other = e19Requests()->create(e16User(), $team);
    e19Requests()->accept($team->owner, $team, $other);          // آخر مقعد

    expect(fn () => e19Requests()->accept($team->owner, $team, $request))->toThrow(TeamException::class, 'اكتمل')
        ->and($request->refresh()->status)->toBe('pending')->and($request->pending_key)->not->toBeNull()->and(e19Count($team))->toBe(2)->and($team->refresh()->members_count)->toBe(2);
});

test('37: an accepted request creates exactly one membership - replaying the accept changes nothing', function () {
    $team = e19RequestTeam();
    $user = e16User();
    $request = e19Requests()->create($user, $team);

    e19Requests()->accept($team->owner, $team, $request);

    expect(fn () => e19Requests()->accept($team->owner, $team, $request->refresh()))->toThrow(TeamException::class, 'لم يعد')
        ->and(TeamMembership::where('user_id', $user->id)->count())->toBe(1)->and($team->refresh()->members_count)->toBe(2);
});

test('38: declining sends no notification and frees the key so the user may ask again later', function () {
    $team = e19RequestTeam();
    $user = e16User();
    $request = e19Requests()->create($user, $team);

    $declined = e19Requests()->decline($team->owner, $team, $request);

    expect($declined->status)->toBe('declined')->and($declined->pending_key)->toBeNull()->and($declined->decided_by)->toBe($team->owner_id)
        ->and(e16Notes('team_join_request_accepted', $user))->toHaveCount(0)->and(e19Role($team, $user))->toBeNull()
        ->and(fn () => e19Requests()->decline($team->owner, $team, $declined))->toThrow(TeamException::class, 'لم يعد');
    expect(e19Requests()->create($user, $team)->status)->toBe('pending');
});

test('a user already in a team cannot request, a user is limited in pending requests, and joining a team cancels the rest of their pending requests and invitations', function () {
    config(['teams.max_pending_requests_per_user' => 2]);
    $user = e16User();
    $teams = [e19RequestTeam(), e19RequestTeam(), e19RequestTeam()];
    e19Requests()->create($user, $teams[0]);
    e19Requests()->create($user, $teams[1]);

    expect(fn () => e19Requests()->create($user, $teams[2]))->toThrow(TeamException::class, 'طلبات انضمام معلّقة كثيرة');

    $invite = e19Invites()->invite($teams[2]->owner, $teams[2], $user);
    e19Invites()->accept($user, $invite);                                  // صار بفريق

    expect(TeamJoinRequest::where('user_id', $user->id)->where('status', 'pending')->count())->toBe(0)->and(TeamJoinRequest::where('user_id', $user->id)->where('status', 'cancelled')->count())->toBe(2)
        ->and(fn () => e19Requests()->create($user, e19RequestTeam()))->toThrow(TeamException::class, 'عضو في فريق بالفعل');
});

test('B13: a block with the owner or an admin prevents a request and an open join - and an inactive or unverified account cannot ask either', function () {
    $team = e19RequestTeam();
    $open = e19Team(null, ['join_policy' => 'open']);
    $admin = e19Member($team, null, 'admin');
    [$blockedByAdmin, $blocksOwner] = [e16User(), e16User()];
    app(BlockService::class)->block($admin, $blockedByAdmin);
    app(BlockService::class)->block($blocksOwner, $open->owner);

    expect(fn () => e19Requests()->create($blockedByAdmin, $team))->toThrow(TeamException::class, 'لا يمكن الانضمام')
        ->and(fn () => e19Members()->joinOpen($blocksOwner, $open))->toThrow(TeamException::class, 'لا يمكن الانضمام')
        ->and(TeamJoinRequest::count())->toBe(0);

    $unverified = e16User();
    $unverified->forceFill(['email_verified_at' => null])->save();
    expect(fn () => e19Requests()->create($unverified, $team))->toThrow(TeamException::class, 'غير مؤهَّل');

    e19Teams()->deactivate($team->owner, $team);
    expect(fn () => e19Requests()->create(e16User(), $team))->toThrow(TeamException::class, 'غير مفعَّل');
});
