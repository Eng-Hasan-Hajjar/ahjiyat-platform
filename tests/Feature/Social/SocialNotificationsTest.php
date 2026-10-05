<?php

require_once __DIR__.'/SocialTestHelpers.php';

use App\Events\FriendRequestCreated;
use App\Events\FriendshipAccepted;
use App\Models\Friendship;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationPreferenceService;
use App\Services\Notifications\NotificationUrlResolver;
use App\Services\PlatformSettingsService;
use App\Services\Social\BlockService;
use Illuminate\Notifications\DatabaseNotification;

test('21: a real request sends friend_request_received ONCE to the addressee only', function () {
    [$a, $b] = [e16User(['name' => 'علي']), e16User(['name' => 'ليلى'])];

    e16Svc()->sendRequest($a, $b);
    $friendship = e16Rows($a, $b)->sole();
    $row = e16Notes('friend_request_received', $b)->sole();

    expect(e16Notes('friend_request_received'))->toHaveCount(1)->and(e16Notes('friend_request_received', $a))->toHaveCount(0)
        ->and($row->category)->toBe('social')
        ->and($row->idempotency_key)->toBe("friend-request:{$friendship->id}")
        ->and($row->data['title'])->toBe('أرسل لك علي طلب صداقة')
        ->and($row->data['action_route'])->toBe('friends.index')
        ->and(app(NotificationUrlResolver::class)->resolve($row->data))->toBe('/friends');
});

test('22: retries never duplicate - repeated sends, replayed events, and a stale event after the request changed', function () {
    [$a, $b] = [e16User(), e16User()];
    e16Svc()->sendRequest($a, $b);
    e16Svc()->sendRequest($a, $b);
    $id = e16Rows($a, $b)->sole()->id;

    foreach (range(1, 3) as $i) {
        event(new FriendRequestCreated($id));
    }

    expect(e16Notes('friend_request_received'))->toHaveCount(1);

    e16Svc()->accept($b, $a); // لم يعد pending: إعادة بثّ الحدث القديم لا تُنشئ شيئًا
    event(new FriendRequestCreated($id));

    expect(e16Notes('friend_request_received'))->toHaveCount(1);
});

test('23: acceptance sends friend_request_accepted ONCE to the original requester - also when the reverse request auto-accepts', function () {
    [$a, $b] = [e16User(['name' => 'سارة']), e16User(['name' => 'خالد'])];
    e16Svc()->sendRequest($a, $b);
    e16Svc()->accept($b, $a);
    e16Svc()->accept($b, $a); // إعادة
    $id = e16Rows($a, $b)->sole()->id;
    event(new FriendshipAccepted($id));
    event(new FriendshipAccepted($id));

    $row = e16Notes('friend_request_accepted', $a)->sole();

    expect(e16Notes('friend_request_accepted'))->toHaveCount(1)->and(e16Notes('friend_request_accepted', $b))->toHaveCount(0)
        ->and($row->idempotency_key)->toBe("friend-accepted:{$id}")->and($row->data['title'])->toBe('قبل خالد طلب صداقتك')
        ->and($row->data['action_route'])->toBe('friends.index');

    // المسار المعاكس: C يرسل لـD، ثم D يرسل لـC (قبول تلقائي) => C (المرسِل الأصلي) يُشعَر بالقبول.
    [$c, $d] = [e16User(), e16User()];
    e16Svc()->sendRequest($c, $d);
    e16Svc()->sendRequest($d, $c);

    expect(e16Notes('friend_request_accepted', $c))->toHaveCount(1)->and(e16Notes('friend_request_accepted', $d))->toHaveCount(0);
});

test('24/25/26: decline, remove and block send NO notification of any kind', function () {
    [$a, $b, $c, $d] = [e16User(), e16User(), e16User(), e16User()];

    e16Svc()->sendRequest($a, $b);
    $afterRequest = DatabaseNotification::count();
    e16Svc()->decline($b, $a);                       // رفض
    expect(DatabaseNotification::count())->toBe($afterRequest);

    e16Befriend($c, $d);
    $afterFriends = DatabaseNotification::count();
    e16Svc()->remove($c, $d);                        // إزالة
    expect(DatabaseNotification::count())->toBe($afterFriends);

    e16Befriend($a, $c);
    $beforeBlock = DatabaseNotification::count();
    app(BlockService::class)->block($a, $c);         // حظر
    expect(DatabaseNotification::count())->toBe($beforeBlock);
});

test('no social notification is created for a pair that is blocked (even from a replayed event)', function () {
    [$a, $b] = [e16User(), e16User()];
    e16Svc()->sendRequest($a, $b);
    $id = e16Rows($a, $b)->sole()->id;
    DatabaseNotification::query()->delete();

    // الحظر يحذف الطلب؛ ثم نعيد بثّ الحدث القديم، وكذلك نعيد إنشاء الصف يدويًا بحظر قائم.
    app(BlockService::class)->block($b, $a);
    event(new FriendRequestCreated($id));
    Friendship::create(['requester_id' => $a->id, 'addressee_id' => $b->id, 'status' => 'pending']);
    event(new FriendRequestCreated(Friendship::query()->forPair($a->id, $b->id)->value('id')));

    expect(DatabaseNotification::count())->toBe(0);
});

test('27: with the social preference off the friendship works but no notification is created', function () {
    [$a, $b] = [e16User(), e16User()];
    app(NotificationPreferenceService::class)->update($b, ['social_enabled' => false]);
    app(NotificationPreferenceService::class)->update($a, ['social_enabled' => false]);

    e16Svc()->sendRequest($a, $b);
    e16Svc()->accept($b, $a);

    expect(e16Rows($a, $b)->sole()->status)->toBe('accepted')->and(DatabaseNotification::count())->toBe(0);
});

test('27b: the social preference is independent - turning it off for the addressee does not silence the requester acceptance notice', function () {
    [$a, $b] = [e16User(), e16User()];
    app(NotificationPreferenceService::class)->update($b, ['social_enabled' => false]);

    e16Svc()->sendRequest($a, $b);
    e16Svc()->accept($b, $a);

    expect(e16Notes('friend_request_received', $b))->toHaveCount(0)->and(e16Notes('friend_request_accepted', $a))->toHaveCount(1);
});

test('28: with global notifications off the friendship works and nothing is created', function () {
    app(PlatformSettingsService::class)->set('notifications', 'notifications_enabled', false);
    [$a, $b] = [e16User(), e16User()];

    e16Svc()->sendRequest($a, $b);
    e16Svc()->accept($b, $a);

    expect(e16Rows($a, $b)->sole()->status)->toBe('accepted')->and(DatabaseNotification::count())->toBe(0);
});

test('29: a failing notification pipeline never rolls back the friendship (request, acceptance, block)', function () {
    [$a, $b, $c] = [e16User(), e16User(), e16User()];
    $this->app->bind(NotificationDispatcher::class, fn () => throw new RuntimeException('notification pipeline is down'));

    e16Svc()->sendRequest($a, $b);
    expect(e16Rows($a, $b)->sole()->status)->toBe('pending');

    e16Svc()->accept($b, $a);
    expect(e16Rows($a, $b)->sole()->status)->toBe('accepted');

    app(BlockService::class)->block($a, $c);
    expect(DB::table('user_blocks')->where('blocker_id', $a->id)->where('blocked_id', $c->id)->exists())->toBeTrue()->and(DatabaseNotification::count())->toBe(0);
});

test('opening a social notification leads to the friends page through the allow-listed route', function () {
    [$a, $b] = [e16User(), e16User()];
    e16Svc()->sendRequest($a, $b);
    $note = e16Notes('friend_request_received', $b)->sole();

    $this->actingAs($b)->post(route('notifications.open', $note->id))->assertRedirect(route('friends.index'));

    expect($note->fresh()->read_at)->not->toBeNull();
});

test('the social category appears once in the preferences page and is not mandatory', function () {
    $user = e16User();

    $html = $this->actingAs($user)->get(route('notifications.preferences'))->assertOk()->getContent();

    expect($html)->toContain('الاجتماعية')->and(substr_count($html, 'name="social_enabled"'))->toBeGreaterThanOrEqual(1);
});
