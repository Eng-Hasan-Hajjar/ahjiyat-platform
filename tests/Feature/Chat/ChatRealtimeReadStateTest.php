<?php

require_once __DIR__.'/ChatTestHelpers.php';

use App\Events\Chat\ChatMessageSent;
use App\Events\Chat\ChatMessageUpdated;
use App\Models\ChatMessage;
use App\Models\ChatReadState;
use App\Models\ChatThread;
use App\Services\Chat\ChatUnreadService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

beforeEach(fn () => $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class));
afterEach(fn () => Carbon::setTestNow());

/** ناشر يفشل دائمًا (Reverb متوقف). */
class ChatFailingBroadcaster extends \Illuminate\Broadcasting\Broadcasters\Broadcaster
{
    public function auth($request)
    {
        return null;
    }

    public function validAuthenticationResponse($request, $result)
    {
        return null;
    }

    public function broadcast(array $channels, $event, array $payload = [])
    {
        throw new \RuntimeException('Reverb unreachable');
    }
}

test('38: the broadcast happens only after the message is saved - the listener already finds the row - and denied sends and failed validation broadcast nothing', function () {
    [$a, $b] = chatFriends();
    $existedAtBroadcast = null;
    Event::listen(ChatMessageSent::class, function ($event) use (&$existedAtBroadcast) {
        $existedAtBroadcast = ChatMessage::query()->whereKey($event->payload['id'])->exists();
    });

    $id = chatDm($this, $a, $b, 'ستُبَثّ بعد الحفظ')->json('message.id');
    expect($existedAtBroadcast)->toBeTrue();

    Event::fake([ChatMessageSent::class, ChatMessageUpdated::class]);
    chatBlocks()->block($a, $b);
    chatDm($this, $a, $b, 'ممنوعة')->assertForbidden();                          // حظر: لا بثّ
    chatDm($this, $b, $a, '   ')->assertStatus(422);                              // فارغة: لا بثّ
    Event::assertNotDispatched(ChatMessageSent::class);

    $this->actingAs($a)->patchJson(route('chat.messages.update', $id), ['body' => 'تعديل'])->assertForbidden();   // محظور لا يعدّل
    Event::assertNotDispatched(ChatMessageUpdated::class);
});

test('38b: sending, editing, deleting and moderating each broadcast the right event on the private room channel', function () {
    $mod = chatModerator();
    $u = e16User();
    Carbon::setTestNow(now());
    Event::fake([ChatMessageSent::class, ChatMessageUpdated::class]);

    $id = $this->actingAs($u)->postJson(route('community.chat.send'), ['body' => 'أولى'])->assertCreated()->json('message.id');
    $this->actingAs($u)->patchJson(route('chat.messages.update', $id), ['body' => 'معدَّلة'])->assertOk();
    $this->actingAs($mod)->postJson(route('chat.messages.hide', $id), ['reason' => 'سبب'])->assertOk();
    $this->actingAs($mod)->postJson(route('chat.messages.restore', $id), ['reason' => 'سبب'])->assertOk();
    $this->actingAs($u)->deleteJson(route('chat.messages.destroy', $id))->assertOk();

    $thread = chatGlobal();
    Event::assertDispatchedTimes(ChatMessageSent::class, 1);
    Event::assertDispatchedTimes(ChatMessageUpdated::class, 4);
    Event::assertDispatched(ChatMessageSent::class, fn ($e) => $e->threadPublicId === $thread->public_id && $e->broadcastAs() === 'message.sent' && $e->broadcastOn()[0] instanceof \Illuminate\Broadcasting\PrivateChannel
        && $e->broadcastOn()[0]->name === 'private-chat.'.$thread->public_id);
});

test('39/40: a private direct channel authorizes only its two participants - guests, third users and unknown rooms are refused', function () {
    [$a, $b] = chatFriends();
    $c = e16User();
    chatDm($this, $a, $b, 'x');
    $channel = 'chat.'.ChatThread::firstOrFail()->public_id;

    expect(chatChannelAllows($a, $channel))->toBeTrue()->and(chatChannelAllows($b, $channel))->toBeTrue()->and(chatChannelAllows($c, $channel))->toBeFalse()
        ->and(chatChannelAllows(null, $channel))->toBeFalse()->and(chatChannelAllows($a, 'chat.01ZZZZZZZZZZZZZZZZZZZZZZZZ'))->toBeFalse();

    chatBlocks()->block($a, $b);                                                    // الحظر لا يقطع القراءة (التاريخ يبقى)
    expect(chatChannelAllows($a, $channel))->toBeTrue();
});

test('41: a team channel authorizes only current members - leaving ends the right at once', function () {
    $team = e19Team(null, ['join_policy' => 'open']);
    $member = e19Member($team);
    $outsider = e16User();
    $this->actingAs($member)->postJson(route('teams.chat.send', $team), ['body' => 'فريق'])->assertCreated();
    $channel = 'chat.'.ChatThread::where('type', 'team')->firstOrFail()->public_id;

    expect(chatChannelAllows($member, $channel))->toBeTrue()->and(chatChannelAllows($team->owner, $channel))->toBeTrue()->and(chatChannelAllows($outsider, $channel))->toBeFalse();
    e19Members()->leave($member);
    expect(chatChannelAllows($member, $channel))->toBeFalse();
});

test('42/F13: the global channel is private too - verified users only - never an anonymous public channel', function () {
    $ok = e16User();
    $channel = 'chat.'.chatGlobal()->public_id;

    expect(chatChannelAllows($ok, $channel))->toBeTrue()->and(chatChannelAllows(e16User(['email_verified_at' => null]), $channel))->toBeFalse()->and(chatChannelAllows(e16User(['is_frozen' => true]), $channel))->toBeFalse()
        ->and(chatChannelAllows(null, $channel))->toBeFalse();
    foreach ([ChatMessageSent::class, ChatMessageUpdated::class] as $class) {
        expect((new $class('X', []))->broadcastOn()[0])->toBeInstanceOf(\Illuminate\Broadcasting\PrivateChannel::class);          // لا Channel عامة بأي حدث
    }

    expect(config('broadcasting.connections.reverb.driver'))->toBe('reverb')->and(config('broadcasting.connections.reverb.options.host'))->toBe(env('REVERB_HOST'))
        ->and(file_get_contents(base_path('routes/channels.php')))->toContain("Broadcast::channel('chat.{thread}'")->not->toContain('new Channel(');
});

test('43: the broadcast payload is public-safe - exact fields only - no email, phone, security or moderation fields', function () {
    $u = e16User(['name' => 'مرسل', 'email' => 'leaky.secret@example.test']);
    $captured = null;
    Event::listen(ChatMessageSent::class, function ($event) use (&$captured) {
        $captured = $event;
    });
    $this->actingAs($u)->postJson(route('community.chat.send'), ['body' => 'رسالة عامة'])->assertCreated();

    $payload = $captured->broadcastWith();
    expect(array_keys($payload))->toBe(['id', 'thread', 'sender', 'state', 'body', 'edited', 'created_at'])->and(array_keys($payload['sender']))->toBe(['public_id', 'name'])
        ->and(json_encode($payload, JSON_UNESCAPED_UNICODE))->not->toContain('leaky.secret')->not->toContain('email')->not->toContain('phone')->not->toContain('is_frozen')->not->toContain('hidden_')->not->toContain('password');
});

test('44/F11: the persisted message does not depend on the broadcast succeeding - a dead Reverb loses nothing and logs no message text', function () {
    Broadcast::extend('failing', fn () => new ChatFailingBroadcaster);
    config(['broadcasting.default' => 'failing', 'broadcasting.connections.failing' => ['driver' => 'failing']]);
    Log::spy();
    $u = e16User();

    $this->actingAs($u)->postJson(route('community.chat.send'), ['body' => 'نص لا يجوز تسريبه بالسجلات'])->assertCreated()->assertJsonPath('message.body', 'نص لا يجوز تسريبه بالسجلات');
    expect(ChatMessage::count())->toBe(1)->and(ChatMessage::first()->body)->toBe('نص لا يجوز تسريبه بالسجلات');
    Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context = []) => $message === 'chat.broadcast_failed' && ! str_contains(json_encode($context, JSON_UNESCAPED_UNICODE), 'نص لا يجوز'))->atLeast()->once();

    $this->actingAs($u)->patchJson(route('chat.messages.update', ChatMessage::first()), ['body' => 'تعديل بلا Reverb'])->assertOk();      // التعديل كذلك
    expect(ChatMessage::first()->body)->toBe('تعديل بلا Reverb');
});

test('45/G4: opening a room marks it read up to the latest message - the read state is one row per room and user', function () {
    [$a, $b] = chatFriends();
    $ids = [chatDm($this, $b, $a, 'ب1')->json('message.id'), chatDm($this, $b, $a, 'ب2')->json('message.id'), chatDm($this, $b, $a, 'ب3')->json('message.id')];
    $thread = ChatThread::firstOrFail();
    expect(app(ChatUnreadService::class)->forThread($a, $thread))->toBe(3);

    $this->actingAs($a)->postJson(route('chat.read', $thread))->assertOk()->assertJsonPath('unread', 0);
    $state = ChatReadState::where('chat_thread_id', $thread->id)->where('user_id', $a->id)->firstOrFail();
    expect($state->last_read_message_id)->toBe($ids[2])->and($state->last_read_at)->not->toBeNull()->and(ChatReadState::where('user_id', $a->id)->count())->toBe(1);
});

test('46/G3: the direct unread count is exact - others only, partial reads, tombstones excluded - and replying marks everything before it as read', function () {
    [$a, $b] = chatFriends();
    $m1 = chatDm($this, $b, $a, 'ب1')->json('message.id');
    chatDm($this, $b, $a, 'ب2');
    $gone = chatDm($this, $b, $a, 'ب3 ستُحذف')->json('message.id');
    $thread = ChatThread::firstOrFail();
    $unread = app(ChatUnreadService::class);

    expect($unread->forThread($a, $thread))->toBe(3)->and($unread->forThread($b, $thread))->toBe(0);           // رسائلي لا تُحسب غير مقروءة عندي
    $this->actingAs($b)->deleteJson(route('chat.messages.destroy', $gone))->assertOk();                          // المحذوفة لا تُحسب
    expect($unread->forThread($a, $thread))->toBe(2);

    $this->actingAs($a)->postJson(route('chat.read', $thread), ['up_to' => $m1])->assertOk()->assertJsonPath('unread', 1);
    expect($unread->total($a))->toBe(1);

    chatDm($this, $a, $b, 'ردّي');                                                                               // الردّ = رأيت ما قبله
    expect($unread->forThread($a, $thread))->toBe(0)->and($unread->forThread($b, $thread))->toBe(1);            // ب لم يقرأ ردّي بعد
    chatDm($this, $b, $a, 'ب4');
    expect($unread->forThread($a, $thread))->toBe(1);
});

test('47: the team unread count is per member and excludes the memberss own messages', function () {
    $team = e19Team(null, ['join_policy' => 'open']);
    $member = e19Member($team);
    $this->actingAs($member)->postJson(route('teams.chat.send', $team), ['body' => 'م1'])->assertCreated();
    $this->actingAs($member)->postJson(route('teams.chat.send', $team), ['body' => 'م2'])->assertCreated();
    $thread = ChatThread::where('type', 'team')->firstOrFail();
    $unread = app(ChatUnreadService::class);

    expect($unread->forThread($member, $thread))->toBe(0)->and($unread->forThread($team->owner, $thread))->toBe(2);
    $this->actingAs($team->owner)->postJson(route('chat.read', $thread))->assertOk()->assertJsonPath('unread', 0);
    expect($unread->forThread($team->owner, $thread))->toBe(0);
    $outsider = e16User();
    expect($unread->forThread($outsider, $thread))->toBe(0);                                          // لا غير مقروء لمن لا يقرأ
});

test('48/G6: the global unread starts at zero for a room never opened, then counts only visible messages from others - not hidden, deleted, blocked or own', function () {
    [$u, $x, $y, $blocked] = [e16User(), e16User(), e16User(), e16User()];
    $mod = chatModerator();
    $unread = app(ChatUnreadService::class);
    Carbon::setTestNow(now());
    $post = function ($user, $body) {
        Carbon::setTestNow(now()->addSeconds(11));

        return $this->actingAs($user)->postJson(route('community.chat.send'), ['body' => $body])->assertCreated()->json('message.id');
    };

    $post($x, 'قديمة 1');
    $post($y, 'قديمة 2');
    $thread = chatGlobal();
    expect($unread->forThread($u, $thread))->toBe(0)->and($unread->total($u))->toBe(0);                   // لم يفتحها: لا شارة 99+

    $this->actingAs($u)->postJson(route('chat.read', $thread))->assertOk();
    chatBlocks()->block($u, $blocked);
    $post($u, 'رسالتي');                                                                                    // رسالتي لا تُحسب
    $hide = $post($x, 'ستُخفى');
    $del = $post($y, 'ستُحذف');
    $post($x, 'ظاهرة 1');
    $post($blocked, 'من محظور');
    $post($y, 'ظاهرة 2');
    $this->actingAs($mod)->postJson(route('chat.messages.hide', $hide), ['reason' => 'سبب'])->assertOk();
    $this->actingAs($y)->deleteJson(route('chat.messages.destroy', $del))->assertOk();

    expect($unread->forThread($u, $thread))->toBe(2);
    $this->actingAs($u)->postJson(route('chat.read', $thread))->assertJsonPath('unread', 0);
});

test('49: nobody can mark another users or foreign room read - a spoofed user_id is ignored', function () {
    [$a, $b] = chatFriends();
    $c = e16User();
    chatDm($this, $b, $a, 'للقراءة');
    chatDm($this, $b, $a, 'ثانية');
    $thread = ChatThread::firstOrFail();
    $bBefore = ChatReadState::where('user_id', $b->id)->where('chat_thread_id', $thread->id)->first()->only(['last_read_message_id', 'last_read_at']);

    $this->actingAs($c)->postJson(route('chat.read', $thread), ['user_id' => $a->id])->assertNotFound();
    $this->actingAs($a)->postJson(route('chat.read', $thread), ['user_id' => $b->id, 'chat_thread_id' => 999])->assertOk();
    expect(ChatReadState::where('user_id', $c->id)->count())->toBe(0)->and(ChatReadState::where('user_id', $b->id)->where('chat_thread_id', $thread->id)->first()->only(['last_read_message_id', 'last_read_at']))->toEqual($bBefore)
        ->and(ChatReadState::where('user_id', $a->id)->where('chat_thread_id', $thread->id)->value('last_read_message_id'))->toBe(ChatMessage::max('id'))->and(ChatReadState::count())->toBe(2);
});

test('50/G1: marking read is idempotent and monotonic - repeating changes nothing and an older marker never moves it back - and the database keeps one row per room and user', function () {
    [$a, $b] = chatFriends();
    $m1 = chatDm($this, $b, $a, 'ب1')->json('message.id');
    chatDm($this, $b, $a, 'ب2');
    $m3 = chatDm($this, $b, $a, 'ب3')->json('message.id');
    $thread = ChatThread::firstOrFail();

    foreach ([$m3, $m3, $m1, null] as $upTo) {
        $this->actingAs($a)->postJson(route('chat.read', $thread), $upTo === null ? [] : ['up_to' => $upTo])->assertOk();
    }
    expect(ChatReadState::where('chat_thread_id', $thread->id)->where('user_id', $a->id)->count())->toBe(1)->and(ChatReadState::where('user_id', $a->id)->value('last_read_message_id'))->toBe($m3);
    $raw = fn () => (new ChatReadState)->forceFill(['chat_thread_id' => $thread->id, 'user_id' => $a->id])->save();
    expect($raw)->toThrow(\Illuminate\Database\UniqueConstraintViolationException::class);
});

test('G7: the navigation badge shows the total unread (capped) for verified users and nothing for the rest - and never breaks a page', function () {
    [$a, $b] = chatFriends();
    chatDm($this, $b, $a, 'جديدة 1');
    chatDm($this, $b, $a, 'جديدة 2');

    $this->actingAs($a)->get(route('messages.index'))->assertOk()->assertSee('رسائل غير مقروءة')->assertSee('بسمة');
    $this->actingAs($b)->get(route('messages.index'))->assertOk()->assertDontSee('رسائل غير مقروءة');
    expect(app(ChatUnreadService::class)->total(e16User(['email_verified_at' => null])))->toBe(0);
});
