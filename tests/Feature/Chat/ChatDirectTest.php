<?php

require_once __DIR__.'/ChatTestHelpers.php';

use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Services\Chat\ChatException;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;

afterEach(fn () => Carbon::setTestNow());

test('1: friends can open a direct chat and the room is created only at the first send - opening does not create anything', function () {
    [$a, $b] = chatFriends();

    $this->actingAs($a)->get(route('messages.direct', $b))->assertOk()->assertSee('بسمة');
    expect(ChatThread::count())->toBe(0);

    chatDm($this, $a, $b, 'أهلًا بسمة')->assertCreated()->assertJsonPath('message.body', 'أهلًا بسمة')->assertJsonPath('message.mine', true);
    $thread = ChatThread::firstOrFail();

    expect($thread->type)->toBe('direct')->and(ChatMessage::count())->toBe(1)->and($thread->last_message_id)->toBe(ChatMessage::first()->id)->and($thread->last_message_at)->not->toBeNull();
    $config = $this->actingAs($b)->get(route('messages.direct', $a))->assertOk()->viewData('config');
    expect(collect($config['messages'])->pluck('body')->all())->toBe(['أهلًا بسمة']);
});

test('2: non-friends cannot open, create or send - nothing is created', function () {
    [$a] = chatFriends();
    $stranger = e16User();

    $this->actingAs($a)->get(route('messages.direct', $stranger))->assertForbidden();
    chatDm($this, $a, $stranger)->assertForbidden()->assertJsonPath('reason', 'not_friends');
    expect(ChatThread::count())->toBe(0)->and(ChatMessage::count())->toBe(0);
});

test('3/A3: reversed users reuse the same canonical thread, and the database itself refuses a second room or a non-canonical pair', function () {
    [$a, $b] = chatFriends();
    chatDm($this, $a, $b, 'من أ');
    chatDm($this, $b, $a, 'من ب');

    $thread = ChatThread::firstOrFail();
    expect(ChatThread::count())->toBe(1)->and(ChatMessage::where('chat_thread_id', $thread->id)->count())->toBe(2)
        ->and((int) $thread->direct_user_one_id)->toBeLessThan((int) $thread->direct_user_two_id)->and($thread->direct_key)->toBe(min($a->id, $b->id).':'.max($a->id, $b->id));

    $dup = fn (array $attrs) => (new ChatThread)->forceFill(['type' => 'direct', 'is_active' => true] + $attrs)->save();
    $one = min($a->id, $b->id);
    $two = max($a->id, $b->id);

    expect(fn () => $dup(['direct_user_one_id' => $one, 'direct_user_two_id' => $two, 'direct_key' => "{$one}:{$two}"]))->toThrow(UniqueConstraintViolationException::class)
        ->and(fn () => $dup(['direct_user_one_id' => $two, 'direct_user_two_id' => $one, 'direct_key' => "{$two}:{$one}"]))->toThrow(InvalidArgumentException::class, 'غير قانونية')
        ->and(fn () => $thread->forceFill(['direct_user_two_id' => e16User()->id])->save())->toThrow(InvalidArgumentException::class, 'ثابتة');
    expect(ChatThread::count())->toBe(1);
});

test('4/B2: chatting with yourself is denied on every path', function () {
    $a = e16User();

    $this->actingAs($a)->get(route('messages.direct', $a))->assertForbidden();
    chatDm($this, $a, $a)->assertForbidden();
    expect(fn () => chatThreads()->directOrCreate($a, $a))->toThrow(ChatException::class, 'نفسك')->and(ChatThread::count())->toBe(0);
});

test('5/B3: a block in either direction prevents sending, for the blocker and the blocked', function () {
    [$a, $b] = chatFriends();
    chatDm($this, $a, $b, 'قبل الحظر');
    chatBlocks()->block($a, $b);

    chatDm($this, $a, $b, 'بعد الحظر')->assertForbidden()->assertJsonPath('reason', 'blocked');
    chatDm($this, $b, $a, 'من المحظور')->assertForbidden()->assertJsonPath('reason', 'blocked');
    expect(ChatMessage::count())->toBe(1);
});

test('6/B4: the history survives a block and stays readable - only the composer is disabled with the reason', function () {
    [$a, $b] = chatFriends();
    chatDm($this, $a, $b, 'رسالة قديمة');
    chatBlocks()->block($a, $b);

    $config = $this->actingAs($a)->get(route('messages.direct', $b))->assertOk()->viewData('config');
    expect($config['caps']['blocker_message'])->toBe('لا يمكنك إرسال رسائل بسبب الحظر.')->and($config['caps']['send'])->toBeFalse()->and($config['caps']['blocker'])->toBe('blocked')->and(collect($config['messages'])->pluck('body')->all())->toBe(['رسالة قديمة']);
});

test('7/B5: removing the friendship keeps the history readable for both and disables new messages with a clear reason', function () {
    [$a, $b] = chatFriends();
    chatDm($this, $a, $b, 'ما زلنا أصدقاء');
    e16Svc()->remove($a, $b);

    foreach ([[$a, $b], [$b, $a]] as [$x, $y]) {
        $config = $this->actingAs($x)->get(route('messages.direct', $y))->assertOk()->viewData('config');
        expect($config['caps'])->toMatchArray(['send' => false, 'blocker' => 'not_friends'])->and($config['caps']['blocker_message'])->toBe('لا يمكنك إرسال رسائل لأنكما لم تعودا أصدقاء.')
            ->and(collect($config['messages'])->pluck('body')->all())->toBe(['ما زلنا أصدقاء']);
    }

    chatDm($this, $a, $b)->assertForbidden()->assertJsonPath('reason', 'not_friends');
});

test('8/B6: becoming friends again reuses the old thread and sending works again', function () {
    [$a, $b] = chatFriends();
    chatDm($this, $a, $b, 'الأولى');
    $thread = ChatThread::firstOrFail();
    e16Svc()->remove($a, $b);
    e16Befriend($a, $b);

    chatDm($this, $b, $a, 'عدنا')->assertCreated();
    expect(ChatThread::count())->toBe(1)->and(ChatMessage::where('chat_thread_id', $thread->id)->count())->toBe(2);
});

test('9/B7: a third user cannot read, page through, mark read, or subscribe to a conversation that is not theirs', function () {
    [$a, $b] = chatFriends();
    $c = e16User();
    e16Befriend($c, $a);                                   // صديق لأحدهما: لا يعني وصولًا لمحادثة الآخرين
    chatDm($this, $a, $b, 'سر بين أ وب');
    $thread = ChatThread::firstOrFail();

    $this->actingAs($c)->get(route('messages.direct', $b))->assertForbidden();                              // لا غرفة بينه وبين ب ولا صداقة
    $this->actingAs($c)->get(route('messages.direct', $a))->assertOk()->assertDontSee('سر بين أ وب');        // غرفته مع أ فارغة
    $this->actingAs($c)->getJson(route('chat.messages', $thread))->assertNotFound();
    $this->actingAs($c)->postJson(route('chat.read', $thread))->assertNotFound();
    $this->actingAs($c)->patchJson(route('chat.messages.update', ChatMessage::first()), ['body' => 'x'])->assertNotFound();
    expect(chatChannelAllows($c, 'chat.'.$thread->public_id))->toBeFalse()->and(chatChannelAllows($a, 'chat.'.$thread->public_id))->toBeTrue()->and(chatChannelAllows($b, 'chat.'.$thread->public_id))->toBeTrue()
        ->and(\App\Models\ChatReadState::where('user_id', $c->id)->exists())->toBeFalse();
});

test('10/A9/A10: editing your own message inside the window works and shows the edited marker', function () {
    [$a, $b] = chatFriends();
    $id = chatDm($this, $a, $b, 'نص أول')->json('message.id');

    $this->actingAs($a)->patchJson(route('chat.messages.update', $id), ['body' => 'نص معدَّل'])->assertOk()->assertJsonPath('message.body', 'نص معدَّل')->assertJsonPath('message.edited', true);
    expect(ChatMessage::find($id)->edited_at)->not->toBeNull()->and(ChatMessage::find($id)->body)->toBe('نص معدَّل');
});

test('11: editing outside the 15 minute window is denied and the body is unchanged', function () {
    [$a, $b] = chatFriends();
    Carbon::setTestNow(now());
    $id = chatDm($this, $a, $b, 'لن يتغير')->json('message.id');
    Carbon::setTestNow(now()->addMinutes(16));

    $this->actingAs($a)->patchJson(route('chat.messages.update', $id), ['body' => 'متأخر'])->assertStatus(422)->assertJsonPath('reason', 'edit_window');
    expect(ChatMessage::find($id)->body)->toBe('لن يتغير')->and(ChatMessage::find($id)->edited_at)->toBeNull();
});

test('12/A11: deleting your own message leaves a tombstone - the row and text stay for review, the UI shows the deleted notice, and it can be repeated safely', function () {
    [$a, $b] = chatFriends();
    $id = chatDm($this, $a, $b, 'سأحذفها')->json('message.id');

    $this->actingAs($a)->deleteJson(route('chat.messages.destroy', $id))->assertOk()->assertJsonPath('message.state', 'deleted')->assertJsonPath('message.body', null);
    $this->actingAs($a)->deleteJson(route('chat.messages.destroy', $id))->assertOk();
    $row = ChatMessage::find($id);
    expect($row)->not->toBeNull()->and($row->deleted_at)->not->toBeNull()->and($row->body)->toBe('سأحذفها');

    $messages = $this->actingAs($b)->getJson(route('chat.messages', ChatThread::first()))->assertOk()->json('messages');
    expect($messages[0]['state'])->toBe('deleted')->and($messages[0]['body'])->toBeNull();
    expect(fn () => $row->delete())->toThrow(InvalidArgumentException::class, 'لا حذف فعلي');
});

test('13: nobody can edit or delete another persons message', function () {
    [$a, $b] = chatFriends();
    $id = chatDm($this, $a, $b, 'لي وحدي')->json('message.id');

    $this->actingAs($b)->patchJson(route('chat.messages.update', $id), ['body' => 'تلاعب'])->assertForbidden()->assertJsonPath('reason', 'not_owner');
    $this->actingAs($b)->deleteJson(route('chat.messages.destroy', $id))->assertForbidden();
    expect(ChatMessage::find($id)->body)->toBe('لي وحدي')->and(ChatMessage::find($id)->deleted_at)->toBeNull();
});
