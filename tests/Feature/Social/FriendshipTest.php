<?php

require_once __DIR__.'/SocialTestHelpers.php';

use App\Events\FriendRequestCreated;
use App\Events\FriendshipAccepted;
use App\Models\Friendship;
use App\Models\User;
use App\Services\Social\BlockService;
use App\Services\Social\FriendActionResult as R;
use App\Services\Social\FriendRelation;
use App\Services\Social\FriendshipService;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;

afterEach(fn () => Carbon::setTestNow());

test('1: user A sends a friend request to B - one pending row, requester A, addressee B', function () {
    [$a, $b] = [e16User(), e16User()];

    expect(e16Svc()->sendRequest($a, $b))->toBe(R::Created);
    $row = e16Rows($a, $b)->sole();

    expect($row->status)->toBe('pending')->and($row->requester_id)->toBe($a->id)->and($row->addressee_id)->toBe($b->id)
        ->and($row->pair_key)->toBe(min($a->id, $b->id).':'.max($a->id, $b->id))->and($row->accepted_at)->toBeNull();
});

test('2: a self request is rejected (service and HTTP) and the model refuses a self row', function () {
    $a = e16User();

    expect(e16Svc()->sendRequest($a, $a))->toBe(R::Self);
    $this->actingAs($a)->post(route('friends.requests.store', $a))->assertSessionHas('error');
    expect(Friendship::count())->toBe(0);
    expect(fn () => Friendship::create(['requester_id' => $a->id, 'addressee_id' => $a->id, 'status' => 'pending']))->toThrow(InvalidArgumentException::class);
});

test('3: a duplicate request is refused - still exactly one row', function () {
    [$a, $b] = [e16User(), e16User()];

    e16Svc()->sendRequest($a, $b);

    expect(e16Svc()->sendRequest($a, $b))->toBe(R::AlreadyPending)->and(e16Rows($a, $b))->toHaveCount(1);
});

test('4: A->B then B->A never creates two rows - the reverse request accepts the existing one, and the database itself refuses a reverse duplicate', function () {
    [$a, $b] = [e16User(), e16User()];

    e16Svc()->sendRequest($a, $b);
    $reverse = e16Svc()->sendRequest($b, $a);

    expect($reverse)->toBe(R::AcceptedExisting)->and(e16Rows($a, $b))->toHaveCount(1)->and(e16Rows($a, $b)->first()->status)->toBe('accepted');

    // الحماية بقاعدة البيانات: إدخال صف معاكس مباشر مرفوض بقيد UNIQUE على pair_key.
    expect(fn () => DB::table('friendships')->insert([
        'requester_id' => $b->id, 'addressee_id' => $a->id, 'pair_key' => Friendship::pairKey($a->id, $b->id), 'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(UniqueConstraintViolationException::class);
});

test('4b: a race between check and insert (simulated) still yields ONE relation and resolves by the same policy', function () {
    Event::fake([FriendRequestCreated::class, FriendshipAccepted::class]);
    [$a, $b] = [e16User(), e16User()];
    Friendship::create(['requester_id' => $b->id, 'addressee_id' => $a->id, 'status' => 'pending']); // B سبقتنا بين الفحص والإدخال

    $racing = new class(app(BlockService::class)) extends FriendshipService
    {
        protected function lockedPair(User $a, User $b): ?Friendship
        {
            return null; // الفحص لا يرى الصف (فجوة السباق) فيصطدم الإدخال بقيد UNIQUE
        }
    };

    expect($racing->sendRequest($a, $b))->toBe(R::AcceptedExisting)->and(e16Rows($a, $b))->toHaveCount(1)->and(e16Rows($a, $b)->first()->status)->toBe('accepted');
    Event::assertDispatchedTimes(FriendshipAccepted::class, 1);
    Event::assertNotDispatched(FriendRequestCreated::class);
});

test('5: only the addressee can accept - the requester and a third user cannot', function () {
    [$a, $b, $c] = [e16User(), e16User(), e16User()];
    e16Svc()->sendRequest($a, $b);

    expect(e16Svc()->accept($a, $b))->toBe(R::NotAllowed)   // المرسِل لا يقبل طلبه
        ->and(e16Svc()->accept($c, $a))->toBe(R::NotFound)  // طرف ثالث: لا علاقة له بها
        ->and(e16Svc()->accept($c, $b))->toBe(R::NotFound)
        ->and(e16Rows($a, $b)->first()->status)->toBe('pending');

    expect(e16Svc()->accept($b, $a))->toBe(R::Accepted);
});

test('6: only the addressee can decline - requester and third user cannot; the request stays', function () {
    [$a, $b, $c] = [e16User(), e16User(), e16User()];
    e16Svc()->sendRequest($a, $b);

    expect(e16Svc()->decline($a, $b))->toBe(R::NotFound)->and(e16Svc()->decline($c, $a))->toBe(R::NotFound)->and(e16Rows($a, $b))->toHaveCount(1);
    expect(e16Svc()->decline($b, $a))->toBe(R::Declined)->and(e16Rows($a, $b))->toHaveCount(0);
});

test('7: only the requester can cancel - addressee and third user cannot', function () {
    [$a, $b, $c] = [e16User(), e16User(), e16User()];
    e16Svc()->sendRequest($a, $b);

    expect(e16Svc()->cancel($b, $a))->toBe(R::NotFound)->and(e16Svc()->cancel($c, $b))->toBe(R::NotFound)->and(e16Rows($a, $b))->toHaveCount(1);
    expect(e16Svc()->cancel($a, $b))->toBe(R::Cancelled)->and(e16Rows($a, $b))->toHaveCount(0);
});

test('8: acceptance makes the relation accepted with accepted_at, visible as Friends from both sides', function () {
    [$a, $b] = [e16User(), e16User()];
    e16Svc()->sendRequest($a, $b);

    expect(e16Svc()->accept($b, $a))->toBe(R::Accepted);
    $row = e16Rows($a, $b)->sole();

    expect($row->status)->toBe('accepted')->and($row->accepted_at)->not->toBeNull()
        ->and(e16Svc()->relationBetween($a, $b))->toBe(FriendRelation::Friends)->and(e16Svc()->relationBetween($b, $a))->toBe(FriendRelation::Friends)
        ->and(e16Svc()->friendIds($a))->toBe([$b->id])->and(e16Svc()->friendsCount($b))->toBe(1);
});

test('9: accepting twice is idempotent - no second row, no second event, accepted_at unchanged', function () {
    $accepted = 0;
    Event::listen(FriendshipAccepted::class, function () use (&$accepted) {
        $accepted++;
    });
    [$a, $b] = [e16User(), e16User()];
    e16Svc()->sendRequest($a, $b);

    e16Svc()->accept($b, $a);
    $firstAt = e16Rows($a, $b)->first()->accepted_at;
    Carbon::setTestNow(now()->addHour());

    expect(e16Svc()->accept($b, $a))->toBe(R::AlreadyFriends)->and(e16Rows($a, $b))->toHaveCount(1)->and($accepted)->toBe(1)
        ->and(e16Rows($a, $b)->first()->accepted_at->equalTo($firstAt))->toBeTrue();
});

test('10: either side can remove the friend; removal deletes no notification and no progress', function (string $remover) {
    [$a, $b] = [e16User(), e16User()];
    e16Befriend($a, $b);
    $notesBefore = DatabaseNotification::count();
    $snapshot = e16Snapshot();

    $actor = $remover === 'requester' ? $a : $b;
    $other = $remover === 'requester' ? $b : $a;

    expect(e16Svc()->remove($actor, $other))->toBe(R::Removed)->and(e16Rows($a, $b))->toHaveCount(0)
        ->and(DatabaseNotification::count())->toBe($notesBefore)->and(e16Snapshot())->toBe($snapshot)
        ->and(e16Svc()->relationBetween($a, $b))->toBe(FriendRelation::None);
})->with(['requester' => ['requester'], 'addressee' => ['addressee']]);

test('11: a third user cannot modify someone else relation - every action is scoped to the authenticated user', function () {
    [$a, $b, $c] = [e16User(), e16User(), e16User()];
    e16Befriend($a, $b);

    foreach ([
        fn () => $this->actingAs($c)->delete(route('friends.remove', $a)),
        fn () => $this->actingAs($c)->delete(route('friends.remove', $b)),
        fn () => $this->actingAs($c)->post(route('friends.requests.accept', $a)),
        fn () => $this->actingAs($c)->post(route('friends.requests.decline', $b)),
        fn () => $this->actingAs($c)->delete(route('friends.requests.cancel', $a)),
    ] as $attack) {
        $attack()->assertSessionHas('error');
    }

    expect(e16Rows($a, $b)->sole()->status)->toBe('accepted');
});

test('the relation is symmetric in the database: one row per pair regardless of who sent first (pair_key unique index exists)', function () {
    $unique = collect(Schema::getIndexes('friendships'))->first(fn ($i) => $i['unique'] && $i['columns'] === ['pair_key']);
    $blocksUnique = collect(Schema::getIndexes('user_blocks'))->first(fn ($i) => $i['unique'] && $i['columns'] === ['blocker_id', 'blocked_id']);

    expect($unique)->not->toBeNull()->and($blocksUnique)->not->toBeNull();
});

test('anti-spam: after a decline or a cancel the same sender cannot resend until the cooldown passes', function (string $how) {
    Carbon::setTestNow('2026-10-10 12:00:00');
    [$a, $b] = [e16User(), e16User()];
    e16Svc()->sendRequest($a, $b);
    $how === 'decline' ? e16Svc()->decline($b, $a) : e16Svc()->cancel($a, $b);

    expect(e16Svc()->sendRequest($a, $b))->toBe(R::Cooldown)->and(e16Rows($a, $b))->toHaveCount(0);

    Carbon::setTestNow(now()->addMinutes(61));

    expect(e16Svc()->sendRequest($a, $b))->toBe(R::Created);
})->with(['after decline' => ['decline'], 'after cancel' => ['cancel']]);

test('anti-spam: sending requests is rate limited (10 per minute) without blocking normal use', function () {
    RateLimiter::clear('friend-req-min:');
    $a = e16User();
    $targets = User::factory()->count(11)->create(['profile_visibility' => User::VISIBILITY_PUBLIC]);
    $statuses = [];

    foreach ($targets as $t) {
        $statuses[] = $this->actingAs($a)->post(route('friends.requests.store', $t))->getStatusCode();
    }

    expect(array_slice($statuses, 0, 10))->each->not->toBe(429)->and($statuses[10])->toBe(429)->and(Friendship::count())->toBe(10);
});

test('deleting a user removes their friendships and blocks - no orphan rows', function () {
    [$a, $b, $c] = [e16User(), e16User(), e16User()];
    e16Befriend($a, $b);
    e16Svc()->sendRequest($c, $a);
    app(BlockService::class)->block($a, $c);
    app(BlockService::class)->block($b, $a);

    $a->delete();

    expect(Friendship::count())->toBe(0)->and(DB::table('user_blocks')->count())->toBe(0);
});


test('defense in depth: the atomic acceptance UPDATE itself refuses a non-addressee and a stale already-accepted row, firing nothing', function () {
    $accepted = 0;
    Event::listen(FriendshipAccepted::class, function () use (&$accepted) {
        $accepted++;
    });
    [$a, $b] = [e16User(), e16User()];
    e16Svc()->sendRequest($a, $b);
    $stale = e16Rows($a, $b)->first();                     // نسخة قديمة بالذاكرة: ما زالت pending
    $acceptRow = new ReflectionMethod(FriendshipService::class, 'acceptRow');
    $acceptRow->setAccessible(true);

    expect($acceptRow->invoke(e16Svc(), $stale, $a))->toBeFalse()                       // المرسِل ليس المرسَل إليه
        ->and(e16Rows($a, $b)->first()->status)->toBe('pending')->and($accepted)->toBe(0);

    e16Svc()->accept($b, $a);                                                           // قُبل فعلًا (حدث واحد)
    expect($accepted)->toBe(1);

    expect($acceptRow->invoke(e16Svc(), $stale, $b))->toBeFalse()                       // سباق: القاعدة قُبلت قبل تحديثنا
        ->and($accepted)->toBe(1);
});

test('canSendTo contract: false for self, frozen, disabled, private profile and a block in EITHER direction; true otherwise', function () {
    [$a, $b] = [e16User(), e16User()];
    $svc = e16Svc();

    expect($svc->canSendTo($a, $b))->toBeTrue()->and($svc->canSendTo($a, $a))->toBeFalse()
        ->and($svc->canSendTo($a, e16User(['is_frozen' => true])))->toBeFalse()
        ->and($svc->canSendTo($a, e16User(['friend_requests_enabled' => false])))->toBeFalse()
        ->and($svc->canSendTo($a, e16User(['profile_visibility' => User::VISIBILITY_PRIVATE])))->toBeFalse();

    app(BlockService::class)->block($a, $b);
    expect($svc->canSendTo($a, $b))->toBeFalse()->and($svc->canSendTo($b, $a))->toBeFalse();
});
