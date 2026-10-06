<?php

require_once __DIR__.'/TeamTestHelpers.php';

use App\Models\TeamInvitation;
use App\Models\TeamMembership;
use App\Services\Social\BlockService;
use App\Services\Teams\TeamException;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;

beforeEach(fn () => e17Freeze());
afterEach(fn () => Carbon::setTestNow());

test('17: the owner and an admin send invitations - pending, expiring after the configured days, with a unique pending key', function () {
    $team = e19Team();
    $admin = e19Member($team, null, 'admin');
    [$a, $b] = [e16User(), e16User()];

    $byOwner = e19Invites()->invite($team->owner, $team, $a);
    $byAdmin = e19Invites()->invite($admin, $team, $b);

    expect($byOwner->status)->toBe('pending')->and($byOwner->invited_by)->toBe($team->owner_id)->and($byAdmin->invited_by)->toBe($admin->id)
        ->and($byOwner->expires_at->equalTo(now()->addDays(7)))->toBeTrue()->and($byOwner->pending_key)->toBe("{$team->id}:{$a->id}")->and($byOwner->public_id)->toHaveLength(26);
});

test('18: a plain member, an outsider and a stranger team admin cannot invite', function () {
    $team = e19Team();
    $member = e19Member($team);
    $otherAdmin = e19Team()->owner;

    foreach ([$member, e16User(), $otherAdmin] as $actor) {
        expect(fn () => e19Invites()->invite($actor, $team, e16User()))->toThrow(TeamException::class, 'مالك الفريق أو مشرفيه');
    }
    expect(TeamInvitation::count())->toBe(0);
});

test('19: a second pending invitation for the same team and user is refused - at the service and by the database', function () {
    $team = e19Team();
    $target = e16User();
    e19Invites()->invite($team->owner, $team, $target);

    expect(fn () => e19Invites()->invite($team->owner, $team, $target))->toThrow(TeamException::class, 'معلّقة')
        ->and(fn () => TeamInvitation::create(['team_id' => $team->id, 'invited_user_id' => $target->id, 'status' => 'pending', 'pending_key' => "{$team->id}:{$target->id}", 'expires_at' => now()->addDay()]))
        ->toThrow(UniqueConstraintViolationException::class);
    expect(TeamInvitation::where('status', 'pending')->count())->toBe(1);
});

test('20/B5: nobody invites themselves, an existing member, a user already in another team, or an unverified/frozen account - one neutral message for all', function () {
    $team = e19Team();
    $member = e19Member($team);
    $other = e19Team()->owner;
    $unverified = e16User();
    $unverified->forceFill(['email_verified_at' => null])->save();
    $frozen = e16User();
    $frozen->forceFill(['is_frozen' => true])->save();

    expect(fn () => e19Invites()->invite($team->owner, $team, $team->owner))->toThrow(TeamException::class, 'نفسك');
    $messages = [];

    foreach ([$member, $other, $unverified, $frozen] as $target) {
        try {
            e19Invites()->invite($team->owner, $team, $target);
        } catch (TeamException $e) {
            $messages[] = $e->getMessage();
        }
    }

    expect($messages)->toBe(array_fill(0, 4, 'لا يمكن دعوة هذا المستخدم.'))->and(TeamInvitation::count())->toBe(0);
});

test('21: an expired invitation cannot be accepted, is materialized as expired, and the user can be invited again afterwards', function () {
    $team = e19Team();
    $target = e16User();
    $invitation = e19Invites()->invite($team->owner, $team, $target);

    Carbon::setTestNow(now()->addDays(8));

    expect(fn () => e19Invites()->accept($target, $invitation->refresh()))->toThrow(TeamException::class, 'انتهت')
        ->and($invitation->refresh()->status)->toBe('expired')->and($invitation->pending_key)->toBeNull()->and(e19Role($team, $target))->toBeNull();

    $fresh = e19Invites()->invite($team->owner, $team, $target);            // مفتاح الدعوة المنتهية تحرَّر
    expect($fresh->status)->toBe('pending')->and($fresh->id)->not->toBe($invitation->id);
});

test('the hourly command expires stale invitations idempotently and frees their keys', function () {
    $team = e19Team();
    $targets = [e16User(), e16User()];
    foreach ($targets as $t) {
        e19Invites()->invite($team->owner, $team, $t);
    }
    Carbon::setTestNow(now()->addDays(8));

    $this->artisan('teams:process-lifecycle')->assertExitCode(0);
    $this->artisan('teams:process-lifecycle')->assertExitCode(0);

    expect(TeamInvitation::where('status', 'expired')->count())->toBe(2)->and(TeamInvitation::whereNotNull('pending_key')->count())->toBe(0);
});

test('22/23: only the invited user accepts, and accepting twice is idempotent - one membership, one event, one notification', function () {
    \Illuminate\Support\Facades\Event::fake([\App\Events\TeamInvitationAccepted::class]);
    $team = e19Team();
    $target = e16User();
    $stranger = e16User();
    $invitation = e19Invites()->invite($team->owner, $team, $target);

    expect(fn () => e19Invites()->accept($stranger, $invitation))->toThrow(TeamException::class, 'ليست لك');

    $first = e19Invites()->accept($target, $invitation->refresh());
    $second = e19Invites()->accept($target, $invitation->refresh());

    expect($first->id)->toBe($second->id)->and(e19Count($team))->toBe(2)->and($team->refresh()->members_count)->toBe(2)->and($invitation->refresh()->status)->toBe('accepted')
        ->and($invitation->pending_key)->toBeNull();
    \Illuminate\Support\Facades\Event::assertDispatchedTimes(\App\Events\TeamInvitationAccepted::class, 1);
});

test('24: capacity is re-checked at acceptance - a team that filled up refuses, and the invitation stays pending (the claim rolls back)', function () {
    $team = e19Team(null, ['max_members' => 2]);
    $target = e16User();
    $invitation = e19Invites()->invite($team->owner, $team, $target);
    e19Member($team);                                   // امتلأ بعد إرسال الدعوة

    expect(fn () => e19Invites()->accept($target, $invitation))->toThrow(TeamException::class, 'اكتمل')
        ->and($invitation->refresh()->status)->toBe('pending')->and($invitation->pending_key)->not->toBeNull()
        ->and(e19Role($team, $target))->toBeNull()->and($team->refresh()->members_count)->toBe(2);
});

test('25: a user who joined another team meanwhile cannot accept - the stale invitation was cancelled, and direct join racing an invitation never yields two teams', function () {
    $teamA = e19Team();
    $teamB = e19Team(null, ['join_policy' => 'open']);
    $user = e16User();
    $invitation = e19Invites()->invite($teamA->owner, $teamA, $user);

    e19Members()->joinOpen($user, $teamB);                // الانضمام المباشر يسبق قبول الدعوة

    expect($invitation->refresh()->status)->toBe('cancelled')->and($invitation->pending_key)->toBeNull()
        ->and(fn () => e19Invites()->accept($user, $invitation))->toThrow(TeamException::class)
        ->and(TeamMembership::where('user_id', $user->id)->count())->toBe(1)->and(e19Role($teamB, $user))->toBe('member')->and(e19Role($teamA, $user))->toBeNull();

    // وحتى لو بقيت الدعوة معلّقة بالقاعدة (حالة سباق)، القبول يرفضه UNIQUE(user_id) والفحص اللحظي، ولا ينجرف العدّاد.
    $inv2 = TeamInvitation::create(['team_id' => $teamA->id, 'invited_user_id' => $user->id, 'status' => 'pending', 'pending_key' => "{$teamA->id}:{$user->id}", 'expires_at' => now()->addDay()]);
    $before = $teamA->refresh()->members_count;
    expect(fn () => e19Invites()->accept($user, $inv2))->toThrow(TeamException::class, 'عضو في فريق بالفعل')
        ->and($inv2->refresh()->status)->toBe('pending')->and($teamA->refresh()->members_count)->toBe($before);
});

test('26: cancelling an invitation - owner and admin only, never a member, never through another team', function () {
    $team = e19Team();
    $admin = e19Member($team, null, 'admin');
    $member = e19Member($team);
    $other = e19Team();
    $i1 = e19Invites()->invite($team->owner, $team, e16User());
    $i2 = e19Invites()->invite($team->owner, $team, e16User());

    expect(fn () => e19Invites()->cancel($member, $team, $i1))->toThrow(TeamException::class, 'مالك الفريق أو مشرفيه')
        ->and(fn () => e19Invites()->cancel($other->owner, $team, $i1))->toThrow(TeamException::class)
        ->and(fn () => e19Invites()->cancel($other->owner, $other, $i1))->toThrow(TeamException::class, 'غير موجودة');     // دعوة فريق آخر

    expect(e19Invites()->cancel($admin, $team, $i1)->status)->toBe('cancelled')->and(e19Invites()->cancel($team->owner, $team, $i2)->status)->toBe('cancelled')
        ->and(fn () => e19Invites()->cancel($team->owner, $team, $i1))->toThrow(TeamException::class, 'لا يمكن إلغاء');
});

test('27/B16: declining works, only for the invited user, and a quick decline/re-invite loop is stopped by the cooldown (not forever)', function () {
    $team = e19Team();
    $target = e16User();
    $invitation = e19Invites()->invite($team->owner, $team, $target);

    expect(fn () => e19Invites()->decline(e16User(), $invitation))->toThrow(TeamException::class, 'ليست لك');
    expect(e19Invites()->decline($target, $invitation)->status)->toBe('declined')->and(fn () => e19Invites()->decline($target, $invitation))->toThrow(TeamException::class);

    expect(fn () => e19Invites()->invite($team->owner, $team, $target))->toThrow(TeamException::class, 'لاحقًا');      // cooldown
    Carbon::setTestNow(now()->addMinutes(11));
    expect(e19Invites()->invite($team->owner, $team, $target)->status)->toBe('pending');                                  // انتهى

    // الإلغاء يبدأ cooldown أيضًا.
    $c = e16User();
    $inv = e19Invites()->invite($team->owner, $team, $c);
    e19Invites()->cancel($team->owner, $team, $inv);
    expect(fn () => e19Invites()->invite($team->owner, $team, $c))->toThrow(TeamException::class, 'لاحقًا');
});

test('28/B13: a block in either direction between the inviter and the target prevents the invitation - with the same neutral message - and never ejects current members', function () {
    $team = e19Team();
    $existing = e19Member($team);
    $a = e16User();
    $b = e16User();

    app(BlockService::class)->block($team->owner, $a);            // المالك حظر a
    app(BlockService::class)->block($b, $team->owner);            // b حظر المالك

    $m1 = $m2 = null;
    try { e19Invites()->invite($team->owner, $team, $a); } catch (TeamException $e) { $m1 = $e->getMessage(); }
    try { e19Invites()->invite($team->owner, $team, $b); } catch (TeamException $e) { $m2 = $e->getMessage(); }

    expect($m1)->toBe('لا يمكن دعوة هذا المستخدم.')->and($m2)->toBe($m1)->and(TeamInvitation::count())->toBe(0);

    // لا طرد تلقائي لعضو حالي بسبب حظر لاحق.
    app(BlockService::class)->block($team->owner, $existing);
    expect(e19Role($team, $existing))->toBe('member');
});

test('an inactive team sends no invitation, and an invitation to a team that became inactive is cancelled and cannot be accepted', function () {
    $team = e19Team();
    $target = e16User();
    $invitation = e19Invites()->invite($team->owner, $team, $target);

    e19Teams()->deactivate($team->owner, $team);

    expect($invitation->refresh()->status)->toBe('cancelled')->and(fn () => e19Invites()->invite($team->owner, $team, e16User()))->toThrow(TeamException::class, 'غير مفعَّل')
        ->and(fn () => e19Invites()->accept($target, $invitation))->toThrow(TeamException::class)->and(e19Role($team, $target))->toBeNull();
});
