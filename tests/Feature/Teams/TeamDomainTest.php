<?php

require_once __DIR__.'/TeamTestHelpers.php';

use App\Models\OperationalAuditLog;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Teams\TeamException;
use App\Services\Teams\TeamNaming;
use Illuminate\Database\UniqueConstraintViolationException;

test('1/2/3: a user creates a team and becomes its owner, with a real owner membership', function () {
    $user = e16User();

    $team = e19Teams()->create($user, ['name' => '  فريق   الأحجيات  ', 'description' => '<b>مرحبا</b> بالجميع', 'visibility' => 'public', 'join_policy' => 'request', 'max_members' => 5]);

    expect($team->name)->toBe('فريق الأحجيات')                                    // الاسم مطبَّع (مسافات)
        ->and($team->owner_id)->toBe($user->id)->and($team->members_count)->toBe(1)->and($team->is_active)->toBeTrue()
        ->and($team->description)->toBe('مرحبا بالجميع')                           // الوسوم تُجرَّد
        ->and(e19Role($team, $user))->toBe('owner')->and(e19Count($team))->toBe(1)
        ->and(TeamMembership::where('team_id', $team->id)->whereNotNull('owner_team_id')->count())->toBe(1);
});

test('4: the same user cannot be a member twice - the database itself refuses a duplicate membership', function () {
    $team = e19Team();
    $member = e19Member($team);

    expect(fn () => e19Members()->addMember($team, $member))->toThrow(TeamException::class, 'عضو في فريق بالفعل')
        ->and(fn () => TeamMembership::create(['team_id' => $team->id, 'user_id' => $member->id, 'role' => 'member', 'joined_at' => now()]))->toThrow(UniqueConstraintViolationException::class);
    expect(e19Count($team))->toBe(2)->and($team->refresh()->members_count)->toBe(2);
});

test('5: one team per user - joining or creating a second team is refused, at the service AND at the database', function () {
    $user = e16User();
    $a = e19Team($user);
    $b = e19Team();

    expect(fn () => e19Members()->joinOpen($user, $b))->toThrow(TeamException::class)
        ->and(fn () => e19Teams()->create($user, ['name' => 'فريق ثانٍ']))->toThrow(TeamException::class, 'عضو في فريق بالفعل');

    // تجاوز فحص الخدمة عمدًا: القيد UNIQUE(user_id) نفسه يمنع، والعدّاد لا ينجرف.
    $count = $b->refresh()->members_count;
    expect(fn () => e19Members()->addMember($b, $user, 'member', checkEligibility: false))->toThrow(TeamException::class)
        ->and($b->refresh()->members_count)->toBe($count)->and(TeamMembership::where('user_id', $user->id)->count())->toBe(1);
});

test('6/7: max_members is honored, and a stale read that still sees a free seat cannot exceed it (the atomic UPDATE decides)', function () {
    $team = e19Team(null, ['max_members' => 3]);
    e19Member($team);
    $stale = Team::find($team->id);                 // قراءة قديمة: 2 من 3
    e19Member($team);                               // امتلأ الآن (3 من 3)

    expect($stale->members_count)->toBe(2)->and($stale->isFull())->toBeFalse()               // النسخة القديمة ترى مقعدًا
        ->and(fn () => e19Members()->addMember($stale, e16User()))->toThrow(TeamException::class, 'اكتمل')
        ->and(e19Count($team))->toBe(3)->and($team->refresh()->members_count)->toBe(3);
});

test('the capacity never exceeds the absolute cap even with no max_members', function () {
    config(['teams.max_members_cap' => 3]);
    $team = e19Team();
    e19Member($team);
    e19Member($team);

    expect($team->refresh()->capacity())->toBe(3)->and(fn () => e19Members()->addMember($team, e16User()))->toThrow(TeamException::class, 'اكتمل');
});

test('8/9: a member leaves freely, the owner cannot leave before transferring ownership or deactivating', function () {
    $team = e19Team();
    $member = e19Member($team);

    e19Members()->leave($member);
    expect(e19Role($team, $member))->toBeNull()->and($team->refresh()->members_count)->toBe(1);

    expect(fn () => e19Members()->leave($team->owner))->toThrow(TeamException::class, 'المالك لا يغادر')
        ->and(fn () => e19Members()->leave(e16User()))->toThrow(TeamException::class, 'لست عضوًا');
    expect(e19Role($team, $team->owner))->toBe('owner');
});

test('10/11/12: the owner transfers ownership to a current member only - the old owner becomes admin and there is exactly one owner', function () {
    $team = e19Team();
    $old = $team->owner;
    $heir = e19Member($team);
    $outsider = e16User();

    expect(fn () => e19Teams()->transferOwnership($old, $team, $outsider))->toThrow(TeamException::class, 'عضوًا حاليًا')
        ->and(fn () => e19Teams()->transferOwnership($old, $team, $old))->toThrow(TeamException::class, 'المالك بالفعل')
        ->and(fn () => e19Teams()->transferOwnership($heir, $team, $old))->toThrow(TeamException::class, 'للمالك وحده');

    e19Teams()->transferOwnership($old, $team, $heir);

    expect(e19Role($team, $heir))->toBe('owner')->and(e19Role($team, $old))->toBe('admin')->and($team->refresh()->owner_id)->toBe($heir->id)
        ->and(TeamMembership::where('team_id', $team->id)->where('role', 'owner')->count())->toBe(1)
        ->and(TeamMembership::where('team_id', $team->id)->whereNotNull('owner_team_id')->count())->toBe(1)
        ->and(OperationalAuditLog::where('action', 'team_ownership_transferred')->count())->toBe(1);
});

test('transferring ownership twice is refused - the old owner is no longer the owner and nothing flips back', function () {
    $team = e19Team();
    $old = $team->owner;
    $a = e19Member($team);
    $b = e19Member($team);

    e19Teams()->transferOwnership($old, $team, $a);

    expect(fn () => e19Teams()->transferOwnership($old, $team, $b))->toThrow(TeamException::class, 'للمالك وحده')
        ->and($team->refresh()->owner_id)->toBe($a->id)->and(TeamMembership::where('team_id', $team->id)->where('role', 'owner')->count())->toBe(1)
        ->and(e19Role($team, $b))->toBe('member');
});

test('13/14/15: the removal hierarchy - an admin never removes the owner or another admin, removes members; a member removes nobody; the owner removes admins and members', function () {
    $team = e19Team();
    $owner = $team->owner;
    $admin = e19Member($team, null, 'admin');
    $admin2 = e19Member($team, null, 'admin');
    $m1 = e19Member($team);
    $m2 = e19Member($team);

    expect(fn () => e19Members()->remove($admin, $team, $owner))->toThrow(TeamException::class)
        ->and(fn () => e19Members()->remove($admin, $team, $admin2))->toThrow(TeamException::class)
        ->and(fn () => e19Members()->remove($m1, $team, $m2))->toThrow(TeamException::class, 'صلاحية')
        ->and(fn () => e19Members()->remove($owner, $team, $owner))->toThrow(TeamException::class, 'مغادرة');

    e19Members()->remove($admin, $team, $m1);
    e19Members()->remove($owner, $team, $admin2);

    expect(e19Role($team, $m1))->toBeNull()->and(e19Role($team, $admin2))->toBeNull()->and(e19Role($team, $m2))->toBe('member')->and(e19Role($team, $owner))->toBe('owner')
        ->and($team->refresh()->members_count)->toBe(3);
});

test('A5: the owner row can never be removed, demoted or replaced by raw model operations - only the atomic transfer may change it', function () {
    $team = e19Team();
    $ownerRow = TeamMembership::where('team_id', $team->id)->where('role', 'owner')->first();
    $member = e19Member($team);
    $memberRow = TeamMembership::where('user_id', $member->id)->first();

    expect(fn () => $ownerRow->delete())->toThrow(InvalidArgumentException::class, 'المالك')
        ->and(fn () => $ownerRow->update(['role' => 'member']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $memberRow->update(['role' => 'owner']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $memberRow->update(['role' => 'god']))->toThrow(InvalidArgumentException::class, 'غير صالح');

    // وتغيير الدور بالخدمة: للمالك، بين admin وmember فقط، ولا يُلمس المالك.
    expect(fn () => e19Members()->changeRole($member, $team, $team->owner, 'member'))->toThrow(TeamException::class, 'للمالك وحده')
        ->and(fn () => e19Members()->changeRole($team->owner, $team, $team->owner, 'member'))->toThrow(TeamException::class, 'إلا بنقل')
        ->and(fn () => e19Members()->changeRole($team->owner, $team, $member, 'owner'))->toThrow(TeamException::class, 'غير صالح');
    expect(e19Members()->changeRole($team->owner, $team, $member, 'admin')->role)->toBe('admin');
});

test('16/A10: a deactivated team accepts no member and keeps its history - pending invitations and requests are cancelled', function () {
    $owner = e16User();
    $team = e19Team($owner, ['join_policy' => 'request']);
    $pendingUser = e16User();
    $invited = e16User();
    e19Requests()->create($pendingUser, $team);
    e19Invites()->invite($owner, $team, $invited);

    e19Teams()->deactivate($owner, $team);

    expect($team->refresh()->is_active)->toBeFalse()->and(fn () => e19Members()->addMember($team, e16User()))->toThrow(TeamException::class, 'غير مفعَّل')
        ->and(e19Members()->teamIdFor($owner))->toBeNull()                                  // لا لقطة لفريق معطَّل
        ->and(\App\Models\TeamInvitation::where('team_id', $team->id)->where('status', 'pending')->count())->toBe(0)
        ->and(\App\Models\TeamJoinRequest::where('team_id', $team->id)->where('status', 'pending')->count())->toBe(0)
        ->and(e19Role($team, $owner))->toBe('owner')                                       // التاريخ باقٍ
        ->and(OperationalAuditLog::where('action', 'team_deactivated')->count())->toBe(1);
    expect(fn () => e19Teams()->updateSettings($owner, $team, ['description' => 'x']))->toThrow(TeamException::class, 'غير مفعَّل');
});

test('A8: team names are normalized, validated and unique regardless of case and spacing', function () {
    e19Team(null, ['name' => 'Puzzle Masters']);

    expect(TeamNaming::error('  puzzle   masters '))->toContain('مستخدم')                  // نفس الاسم بصياغة مختلفة
        ->and(TeamNaming::error('ab'))->toContain('بين 3 و40')
        ->and(TeamNaming::error(str_repeat('ا', 41)))->toContain('بين 3 و40')
        ->and(TeamNaming::error('<script>alert(1)</script>'))->toContain('أحرف وأرقام')
        ->and(TeamNaming::error('فريق #1'))->toContain('أحرف وأرقام')
        ->and(TeamNaming::error('فريق النجوم 7'))->toBeNull()
        ->and(TeamNaming::error('Star_Team-9'))->toBeNull();
    expect(fn () => e19Teams()->create(e16User(), ['name' => 'PUZZLE MASTERS']))->toThrow(TeamException::class, 'مستخدم');
});

test('A9/E18: slugs are unique, never reserved, never empty for Arabic names, and immutable after creation', function () {
    $a = e19Team(null, ['name' => 'فريق الأحجيات']);
    $b = e19Team(null, ['name' => 'فريق-الأحجيات']);
    $reserved = e19Team(null, ['name' => 'Create']);
    $admin = e19Team(null, ['name' => 'Admin']);

    expect($a->slug)->not->toBeEmpty()->and($b->slug)->not->toBe($a->slug)
        ->and($reserved->slug)->toBe('create-2')->and($admin->slug)->toBe('admin-2')
        ->and(Team::whereIn('slug', config('teams.reserved_slugs'))->count())->toBe(0);

    $slug = $a->slug;
    e19Teams()->updateSettings($a->owner, $a, ['name' => 'اسم جديد تمامًا']);
    expect($a->refresh()->slug)->toBe($slug)->and($a->name)->toBe('اسم جديد تمامًا');
    expect(fn () => $a->update(['slug' => 'changed']))->toThrow(InvalidArgumentException::class, 'ثابت');
});

test('settings validation: visibility, policy and capacity come from closed sets, capacity never drops below the current members, unknown keys are ignored', function () {
    $team = e19Team(null, ['max_members' => 10]);
    $owner = $team->owner;
    e19Member($team);
    e19Member($team);

    expect(fn () => e19Teams()->updateSettings($owner, $team, ['visibility' => 'secret']))->toThrow(TeamException::class)
        ->and(fn () => e19Teams()->updateSettings($owner, $team, ['join_policy' => 'anyone']))->toThrow(TeamException::class)
        ->and(fn () => e19Teams()->updateSettings($owner, $team, ['max_members' => 2]))->toThrow(TeamException::class, 'لا تقل')      // 3 أعضاء حاليًا
        ->and(fn () => e19Teams()->updateSettings($owner, $team, ['max_members' => 9999]))->toThrow(TeamException::class);

    $updated = e19Teams()->updateSettings($owner, $team, ['visibility' => 'private', 'join_policy' => 'invite_only', 'max_members' => 3, 'owner_id' => 999, 'role' => 'owner', 'members_count' => 0, 'is_active' => false]);

    expect($updated->visibility)->toBe('private')->and($updated->join_policy)->toBe('invite_only')->and($updated->max_members)->toBe(3)
        ->and($updated->owner_id)->toBe($owner->id)->and($updated->members_count)->toBe(3)->and($updated->is_active)->toBeTrue();   // المفاتيح الغريبة لم تؤثر
    $existing = TeamMembership::where('team_id', $team->id)->where('role', 'member')->first()->user;   // الفريق ممتلئ (3 من 3): نستعمل عضوًا موجودًا
    expect(fn () => e19Teams()->updateSettings($existing, $team, ['description' => 'x']))->toThrow(TeamException::class, 'للمالك وحده');
});

test('an unverified or frozen account can neither create nor join a team', function () {
    $team = e19Team();
    $unverified = e16User();
    $unverified->forceFill(['email_verified_at' => null])->save();
    $frozen = e16User();
    $frozen->forceFill(['is_frozen' => true])->save();

    foreach ([$unverified, $frozen] as $user) {
        expect(fn () => e19Teams()->create($user, ['name' => 'فريق '.$user->id]))->toThrow(TeamException::class, 'غير مؤهَّل')
            ->and(fn () => e19Members()->joinOpen($user, $team))->toThrow(TeamException::class, 'غير مؤهَّل');
    }
});

test('E19/E20: a frozen owner never dissolves the team, and deleting an owner account transfers ownership or archives - never an orphan', function () {
    // مالك مجمَّد: الفريق يبقى كما هو.
    $team = e19Team();
    $team->owner->forceFill(['is_frozen' => true])->save();
    expect($team->refresh()->is_active)->toBeTrue()->and(e19Role($team, $team->owner))->toBe('owner');

    // حذف حساب مالك: أقدم مشرف أولًا ثم أقدم عضو.
    $t = e19Team();
    $owner = $t->owner;
    $member = e19Member($t);
    $admin = e19Member($t, null, 'admin');
    $owner->delete();

    expect($t->refresh()->owner_id)->toBe($admin->id)->and(e19Role($t, $admin))->toBe('owner')->and(e19Role($t, $member))->toBe('member')
        ->and(TeamMembership::where('team_id', $t->id)->where('role', 'owner')->count())->toBe(1)->and($t->is_active)->toBeTrue()
        ->and(OperationalAuditLog::where('action', 'team_ownership_auto_transferred')->count())->toBe(1);

    // مالك وحيد: الفريق يُؤرشَف (لا يتيم) والتاريخ يبقى.
    $solo = e19Team();
    $solo->owner->delete();
    expect($solo->refresh()->is_active)->toBeFalse()->and($solo->owner_id)->toBeNull()->and(Team::whereKey($solo->id)->exists())->toBeTrue()
        ->and(OperationalAuditLog::where('action', 'team_auto_archived')->count())->toBe(1);
});

test('the hourly lifecycle repairs a drifted member counter (user deletion cascades memberships without decrementing)', function () {
    $team = e19Team();
    $m = e19Member($team);
    $m->delete();                                    // العضوية حُذفت بالتتابع بلا إنقاص العدّاد
    expect($team->refresh()->members_count)->toBe(2)->and(e19Count($team))->toBe(1);

    $this->artisan('teams:process-lifecycle')->assertExitCode(0);
    $this->artisan('teams:process-lifecycle')->assertExitCode(0);   // Idempotent

    expect($team->refresh()->members_count)->toBe(1);
});
