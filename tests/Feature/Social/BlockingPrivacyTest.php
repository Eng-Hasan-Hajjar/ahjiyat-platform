<?php

require_once __DIR__.'/SocialTestHelpers.php';

use App\Models\Friendship;
use App\Models\User;
use App\Models\UserBlock;
use App\Services\Social\BlockService;
use App\Services\Social\FriendActionResult as R;
use App\Services\Social\FriendRelation;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;

test('12: a user can block another user (HTTP) - one block row, idempotent', function () {
    [$a, $b] = [e16User(), e16User()];

    $this->actingAs($a)->post(route('friends.blocks.store', $b))->assertSessionHas('success');
    $this->actingAs($a)->post(route('friends.blocks.store', $b))->assertSessionHas('success'); // Idempotent

    expect(UserBlock::where('blocker_id', $a->id)->where('blocked_id', $b->id)->count())->toBe(1)->and(UserBlock::count())->toBe(1);
});

test('13: self block is refused', function () {
    $a = e16User();

    expect(app(BlockService::class)->block($a, $a))->toBe(R::Self);
    $this->actingAs($a)->post(route('friends.blocks.store', $a))->assertSessionHas('error');
    expect(UserBlock::count())->toBe(0);
});

test('14: blocking removes an existing friendship', function () {
    [$a, $b] = [e16User(), e16User()];
    e16Befriend($a, $b);

    app(BlockService::class)->block($a, $b);

    expect(e16Rows($a, $b))->toHaveCount(0)->and(e16Svc()->relationBetween($a, $b))->toBe(FriendRelation::BlockedByMe);
});

test('15: blocking cancels a pending request in either direction', function (string $blockerIsRequester) {
    [$a, $b] = [e16User(), e16User()];
    e16Svc()->sendRequest($a, $b);

    $blockerIsRequester === 'yes' ? app(BlockService::class)->block($a, $b) : app(BlockService::class)->block($b, $a);

    expect(e16Rows($a, $b))->toHaveCount(0);
})->with(['requester blocks' => ['yes'], 'addressee blocks' => ['no']]);

test('16: after a block no request can be sent in either direction, and an existing one cannot be accepted', function () {
    [$a, $b] = [e16User(), e16User()];
    app(BlockService::class)->block($a, $b);

    expect(e16Svc()->sendRequest($a, $b))->toBe(R::Unavailable)->and(e16Svc()->sendRequest($b, $a))->toBe(R::Unavailable)->and(Friendship::count())->toBe(0);
    $this->actingAs($b)->post(route('friends.requests.store', $a))->assertSessionHas('error');

    // سباق: طلب قائم ثم يُدخَل حظر بلا مروره بالخدمة => القبول يُرفض أيضًا.
    DB::table('user_blocks')->delete();
    e16Svc()->sendRequest($a, $b);
    DB::table('user_blocks')->insert(['blocker_id' => $b->id, 'blocked_id' => $a->id, 'created_at' => now()]);

    expect(e16Svc()->accept($b, $a))->toBe(R::Unavailable)->and(e16Rows($a, $b)->first()->status)->toBe('pending');
});

test('17/18: unblocking never restores the old friendship, and a brand-new request is possible afterwards', function () {
    [$a, $b] = [e16User(), e16User()];
    e16Befriend($a, $b);
    app(BlockService::class)->block($a, $b);

    $this->actingAs($a)->delete(route('friends.blocks.destroy', $b))->assertSessionHas('success');

    expect(UserBlock::count())->toBe(0)->and(e16Rows($a, $b))->toHaveCount(0)->and(e16Svc()->relationBetween($a, $b))->toBe(FriendRelation::None);
    expect(e16Svc()->sendRequest($a, $b))->toBe(R::Created)->and(e16Rows($a, $b)->first()->status)->toBe('pending');
});

test('only the blocker can unblock; unblocking someone not blocked is a harmless no-op', function () {
    [$a, $b] = [e16User(), e16User()];
    app(BlockService::class)->block($a, $b);

    expect(app(BlockService::class)->unblock($b, $a))->toBe(R::NotFound)->and(UserBlock::count())->toBe(1)
        ->and(app(BlockService::class)->unblock($a, e16User()))->toBe(R::NotFound);
});

test('blocking deletes no old notification and no progress, and a blocked user is never told', function () {
    [$a, $b] = [e16User(), e16User()];
    e16Svc()->sendRequest($a, $b);
    $notes = DatabaseNotification::count();
    $snapshot = e16Snapshot();

    app(BlockService::class)->block($b, $a);

    expect(DatabaseNotification::count())->toBe($notes)->and(e16Snapshot())->toBe($snapshot)
        ->and(DatabaseNotification::where('notifiable_id', $a->id)->count())->toBe(0); // لا إشعار للمحظور
});

test('19: friend_requests_enabled=false blocks NEW requests (service and HTTP) with a neutral message', function () {
    [$a, $b] = [e16User(), e16User(['friend_requests_enabled' => false])];

    expect(e16Svc()->sendRequest($a, $b))->toBe(R::Unavailable)->and(Friendship::count())->toBe(0);
    $this->actingAs($a)->post(route('friends.requests.store', $b))->assertSessionHas('error', R::Unavailable->message());
});

test('20: existing friendships and pending incoming requests are unaffected when requests are disabled', function () {
    [$a, $b, $c] = [e16User(), e16User(), e16User()];
    e16Befriend($a, $b);
    e16Svc()->sendRequest($c, $a);

    $this->actingAs($a)->patch(route('friends.settings'), ['friend_requests_enabled' => 0])->assertSessionHas('success');

    expect($a->fresh()->friend_requests_enabled)->toBeFalse()
        ->and(e16Svc()->relationBetween($b, $a))->toBe(FriendRelation::Friends)           // الصديق باقٍ
        ->and(e16Svc()->accept($a, $c))->toBe(R::Accepted)                                 // الطلب الوارد قبل التعطيل يُقبل
        ->and(e16Svc()->remove($a, $b))->toBe(R::Removed);                                 // وما زال بالإمكان إزالته
});

test('the privacy setting is validated and written only for the authenticated user (no mass assignment)', function () {
    [$a, $b] = [e16User(), e16User()];

    $this->actingAs($a)->patch(route('friends.settings'), [])->assertSessionHasErrors('friend_requests_enabled');
    $this->actingAs($a)->patch(route('friends.settings'), ['friend_requests_enabled' => 0, 'user_id' => $b->id, 'id' => $b->id, 'email' => 'x@y.z']);

    expect($a->fresh()->friend_requests_enabled)->toBeFalse()->and($b->fresh()->friend_requests_enabled)->toBeTrue()->and($a->fresh()->email)->not->toBe('x@y.z');
});

test('discovery follows the existing profile privacy: a private profile cannot be requested, members-only can by a member', function () {
    $a = e16User();
    $private = e16User(['profile_visibility' => User::VISIBILITY_PRIVATE]);
    $members = e16User(['profile_visibility' => User::VISIBILITY_MEMBERS]);

    expect(e16Svc()->sendRequest($a, $private))->toBe(R::Unavailable)->and(e16Svc()->sendRequest($a, $members))->toBe(R::Created);
});

test('a frozen account cannot receive new requests', function () {
    [$a, $frozen] = [e16User(), e16User(['is_frozen' => true])];

    expect(e16Svc()->sendRequest($a, $frozen))->toBe(R::Unavailable);
});
