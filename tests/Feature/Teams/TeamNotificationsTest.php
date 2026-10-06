<?php

require_once __DIR__.'/TeamTestHelpers.php';

use App\Events\TeamInvitationAccepted;
use App\Events\TeamInvitationCreated;
use App\Models\TeamInvitation;
use App\Models\TeamMembership;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationPreferenceService;
use App\Services\Notifications\NotificationUrlResolver;
use App\Services\PlatformSettingsService;
use App\Services\Social\BlockService;
use Carbon\Carbon;
use Illuminate\Notifications\DatabaseNotification;

beforeEach(fn () => e17Freeze());
afterEach(fn () => Carbon::setTestNow());

function e19Note(string $type, $user)
{
    return e16Notes($type, $user);
}

test('59/E7/E8: an invitation sends ONE notification to the invited user with a semantic key and a safe internal link - replays never duplicate', function () {
    $team = e19Team(null, ['name' => 'فريق الإشعارات']);
    $target = e16User();

    $invitation = e19Invites()->invite($team->owner, $team, $target);
    $note = e19Note('team_invitation_received', $target)->sole();

    expect($note->idempotency_key)->toBe("team-invite:{$invitation->id}")->and($note->category)->toBe('social')
        ->and($note->data['title'])->toBe("دعاك {$team->owner->name} للانضمام إلى فريق «فريق الإشعارات»")
        ->and(app(NotificationUrlResolver::class)->resolve($note->data))->toBe('/teams/invitations');

    foreach (range(1, 3) as $i) {
        event(new TeamInvitationCreated($invitation->id));
    }
    expect(e19Note('team_invitation_received', $target))->toHaveCount(1);
});

test('60: accepting an invitation notifies the inviter ONCE - to the management page, or to the team page if they are no longer a manager', function () {
    $team = e19Team();
    $admin = e19Member($team, null, 'admin');
    [$a, $b] = [e16User(), e16User()];
    $ia = e19Invites()->invite($admin, $team, $a);
    $ib = e19Invites()->invite($admin, $team, $b);

    e19Invites()->accept($a, $ia);
    e19Invites()->accept($a, $ia->refresh());                                   // إعادة: لا إشعار ثانٍ
    $note = e19Note('team_invitation_accepted', $admin)->sole();

    expect($note->idempotency_key)->toBe("team-invite-accepted:{$ia->id}")->and(app(NotificationUrlResolver::class)->resolve($note->data))->toBe("/teams/{$team->slug}/manage")
        ->and(e19Note('team_invitation_accepted', $team->owner))->toHaveCount(0);   // الداعي وحده

    e19Members()->changeRole($team->owner, $team, $admin, 'member');            // لم يعد مشرفًا
    e19Invites()->accept($b, $ib);
    $second = e19Note('team_invitation_accepted', $admin)->firstWhere('idempotency_key', "team-invite-accepted:{$ib->id}");     // بالمفتاح الدلالي: معرّفات الإشعار ULID لا تُرتَّب داخل المللي ثانية

    expect(app(NotificationUrlResolver::class)->resolve($second->data))->toBe("/teams/{$team->slug}");
});

test('61: a join request notifies the owner and every admin once each - never plain members, never a manager blocked with the requester', function () {
    $team = e19Team(null, ['join_policy' => 'request']);
    $admin = e19Member($team, null, 'admin');
    $blockedAdmin = e19Member($team, null, 'admin');
    $member = e19Member($team);
    $requester = e16User();

    $request = e19Requests()->create($requester, $team);
    DatabaseNotification::query()->delete();
    app(BlockService::class)->block($blockedAdmin, $requester);      // حظر نشأ بعد الطلب: لا يُشعَر من بينه وبين الطالب حظر
    event(new \App\Events\TeamJoinRequestCreated($request->id));

    expect(e19Note('team_join_request_received', $team->owner))->toHaveCount(1)->and(e19Note('team_join_request_received', $admin))->toHaveCount(1)
        ->and(e19Note('team_join_request_received', $member))->toHaveCount(0)->and(e19Note('team_join_request_received', $blockedAdmin))->toHaveCount(0)
        ->and(e19Note('team_join_request_received', $team->owner)->sole()->idempotency_key)->toBe("team-join-request:{$request->id}")
        ->and(app(NotificationUrlResolver::class)->resolve(e19Note('team_join_request_received', $team->owner)->sole()->data))->toBe("/teams/{$team->slug}/manage");

    event(new \App\Events\TeamJoinRequestCreated($request->id));
    expect(e19Note('team_join_request_received', $team->owner))->toHaveCount(1);
});

test('62: accepting a join request notifies the requester ONCE, leading to the team page', function () {
    $team = e19Team(null, ['join_policy' => 'request']);
    $user = e16User();
    $request = e19Requests()->create($user, $team);

    e19Requests()->accept($team->owner, $team, $request);
    event(new \App\Events\TeamJoinRequestAccepted($request->id));              // إعادة

    $note = e19Note('team_join_request_accepted', $user)->sole();
    expect($note->idempotency_key)->toBe("team-join-accepted:{$request->id}")->and($note->data['title'])->toBe("قُبل طلب انضمامك إلى فريق «{$team->name}»")
        ->and(app(NotificationUrlResolver::class)->resolve($note->data))->toBe("/teams/{$team->slug}");
});

test('63/E1/E2: declines, cancellations, expiry and leaving send nothing - only a removal sends one neutral informational notice with no remover name', function () {
    $team = e19Team(null, ['join_policy' => 'request']);
    $req = e16User();
    $declined = e19Requests()->create($req, $team);
    e19Requests()->decline($team->owner, $team, $declined);
    $inv = e19Invites()->invite($team->owner, $team, $invitee = e16User());
    e19Invites()->decline($invitee, $inv);
    $inv2 = e19Invites()->invite($team->owner, $team, $c = e16User());
    e19Invites()->cancel($team->owner, $team, $inv2);
    $leaver = e19Member($team);
    e19Members()->leave($leaver);
    $r2 = e19Requests()->create($d = e16User(), $team);
    e19Requests()->cancel($d, $team);

    expect(DatabaseNotification::where('notifiable_id', $req->id)->count())->toBe(0)->and(DatabaseNotification::where('notifiable_id', $invitee->id)->where('type_key', '!=', 'team_invitation_received')->count())->toBe(0)
        ->and(DatabaseNotification::where('notifiable_id', $leaver->id)->count())->toBe(0)->and(DatabaseNotification::where('notifiable_id', $d->id)->count())->toBe(0);

    $victim = e19Member($team);
    e19Members()->remove($team->owner, $team, $victim);
    $note = e19Note('team_member_removed', $victim)->sole();

    expect($note->data['title'])->toBe("لم تعد عضوًا في فريق «{$team->name}»")->and($note->data['body'])->not->toContain($team->owner->name)
        ->and(app(NotificationUrlResolver::class)->resolve($note->data))->toBe('/teams');
    expect(e19Note('team_member_removed', $team->owner))->toHaveCount(0);
});

test('64/E5: with the social preference off the membership still works and no team notification is created', function () {
    $team = e19Team(null, ['join_policy' => 'request']);
    $target = e16User();
    app(NotificationPreferenceService::class)->update($target, ['social_enabled' => false]);

    $invitation = e19Invites()->invite($team->owner, $team, $target);
    e19Invites()->accept($target, $invitation);
    $victim = e16User();
    app(NotificationPreferenceService::class)->update($victim, ['social_enabled' => false]);
    e19Members()->addMember($team, $victim);
    e19Members()->remove($team->owner, $team, $victim);

    expect(e19Role($team, $target))->toBe('member')->and(DatabaseNotification::where('notifiable_id', $target->id)->count())->toBe(0)->and(DatabaseNotification::where('notifiable_id', $victim->id)->count())->toBe(0);
});

test('65/E6: with global notifications off every team action works and nothing is created', function () {
    app(PlatformSettingsService::class)->set('notifications', 'notifications_enabled', false);
    $team = e19Team(null, ['join_policy' => 'request']);
    $a = e16User();
    $b = e16User();
    $invitation = e19Invites()->invite($team->owner, $team, $a);
    e19Invites()->accept($a, $invitation);
    $request = e19Requests()->create($b, $team);
    e19Requests()->accept($team->owner, $team, $request);
    e19Members()->remove($team->owner, $team, $b);

    expect(e19Count($team))->toBe(2)->and(DatabaseNotification::count())->toBe(0);
});

test('66/E9: a failing notification pipeline never rolls back an invitation, an acceptance, a request, a removal or an ownership transfer', function () {
    $this->app->bind(NotificationDispatcher::class, fn () => throw new RuntimeException('notification pipeline is down'));
    $team = e19Team(null, ['join_policy' => 'request']);
    $a = e16User();
    $b = e16User();

    $invitation = e19Invites()->invite($team->owner, $team, $a);
    e19Invites()->accept($a, $invitation);
    $request = e19Requests()->create($b, $team);
    e19Requests()->accept($team->owner, $team, $request);
    e19Members()->remove($team->owner, $team, $b);
    e19Teams()->transferOwnership($team->owner, $team, $a);

    expect($invitation->refresh()->status)->toBe('accepted')->and($request->refresh()->status)->toBe('accepted')->and(e19Role($team, $a))->toBe('owner')
        ->and(e19Role($team, $b))->toBeNull()->and(DatabaseNotification::count())->toBe(0);
});

test('opening any team notification grants nothing and changes no team state', function () {
    $team = e19Team(null, ['join_policy' => 'request']);
    $target = e16User();
    e19Invites()->invite($team->owner, $team, $target);
    $note = e19Note('team_invitation_received', $target)->sole();
    $before = [TeamMembership::count(), TeamInvitation::where('status', 'pending')->count(), e17Snapshot()];

    $this->actingAs($target)->post(route('notifications.open', $note->id))->assertRedirect(route('teams.invitations'));

    expect([TeamMembership::count(), TeamInvitation::where('status', 'pending')->count(), e17Snapshot()])->toBe($before);   // الفتح لا يقبل الدعوة ولا يمنح شيئًا
});

test('the five team types are registered under the existing social category with real internal routes', function () {
    foreach (['TeamInvitationReceived', 'TeamInvitationAccepted', 'TeamJoinRequestReceived', 'TeamJoinRequestAccepted', 'TeamMemberRemoved'] as $case) {
        $type = constant("App\\Services\\Notifications\\NotificationType::{$case}");
        expect($type->category())->toBe(\App\Services\Notifications\NotificationCategory::Social)->and($type->allowedRoutes())->not->toBeEmpty();

        foreach ($type->allowedRoutes() as $route) {
            expect(\Illuminate\Support\Facades\Route::has($route))->toBeTrue();
        }
    }
    expect(count(\App\Services\Notifications\NotificationCategory::cases()))->toBe(8);        // 8 منذ E17: E19 لا تضيف فئة
});

test('E8: a replayed event for a record in the wrong state sends nothing - no false "accepted", no notice for a cancelled invitation or a declined request', function () {
    $team = e19Team(null, ['join_policy' => 'request']);
    $target = e16User();
    $pendingInvite = e19Invites()->invite($team->owner, $team, $target);
    $cancelled = e19Invites()->invite($team->owner, $team, $other = e16User());
    e19Invites()->cancel($team->owner, $team, $cancelled);
    $declinedRequest = e19Requests()->create($asker = e16User(), $team);
    e19Requests()->decline($team->owner, $team, $declinedRequest);
    $pendingRequest = e19Requests()->create($asker2 = e16User(), $team);
    DatabaseNotification::query()->delete();

    event(new TeamInvitationAccepted($pendingInvite->id));                       // الدعوة ما زالت معلّقة: لم تُقبل
    event(new \App\Events\TeamJoinRequestAccepted($declinedRequest->id));         // الطلب مرفوض
    event(new \App\Events\TeamJoinRequestAccepted($pendingRequest->id));          // الطلب معلّق
    event(new TeamInvitationCreated($cancelled->id));                             // دعوة ملغاة
    event(new \App\Events\TeamJoinRequestCreated($declinedRequest->id));          // طلب لم يعد معلّقًا
    event(new TeamInvitationCreated(999999));                                     // سجل غير موجود
    event(new \App\Events\TeamMemberRemoved(999999, 999999, 1));                  // فريق/مستخدم غير موجودَين

    expect(DatabaseNotification::count())->toBe(0);
});
