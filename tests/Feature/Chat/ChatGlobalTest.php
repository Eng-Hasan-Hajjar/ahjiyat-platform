<?php

require_once __DIR__.'/ChatTestHelpers.php';

use App\Models\ChatMessage;
use App\Services\Chat\ChatAccess;
use Carbon\Carbon;

beforeEach(fn () => $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class));
afterEach(fn () => Carbon::setTestNow());

function chatSay($test, $user, string $body)
{
    return $test->actingAs($user)->postJson(route('community.chat.send'), ['body' => $body]);
}

test('22: an authenticated verified user reads the one global room and the room exists once', function () {
    $u = e16User();

    $config = $this->actingAs($u)->get(route('community.chat'))->assertOk()->viewData('config');
    expect($config['type'])->toBe('global')->and($config['caps']['send'])->toBeTrue()->and(\App\Models\ChatThread::where('type', 'global')->count())->toBe(1);
    $this->actingAs($u)->get(route('community.chat'))->assertOk();
    expect(\App\Models\ChatThread::where('type', 'global')->count())->toBe(1)
        ->and(fn () => (new \App\Models\ChatThread)->forceFill(['type' => 'global', 'slug' => 'other-room', 'is_active' => true])->save())->toThrow(InvalidArgumentException::class, 'واحدة')
        ->and(fn () => (new \App\Models\ChatThread)->forceFill(['type' => 'global', 'slug' => 'global', 'is_active' => true])->save())->toThrow(\Illuminate\Database\UniqueConstraintViolationException::class);
});

test('23: guests cannot read or send in the global room', function () {
    $this->get(route('community.chat'))->assertRedirect(route('login'));
    $this->postJson(route('community.chat.send'), ['body' => 'ضيف'])->assertUnauthorized();
    expect(ChatMessage::count())->toBe(0);
});

test('24/K2: an unverified user is denied everywhere - the page redirects and the service refuses with the reason', function () {
    $u = e16User(['email_verified_at' => null]);

    $this->actingAs($u)->get(route('community.chat'))->assertRedirect();
    expect(app(ChatAccess::class)->sendBlocker($u, chatGlobal()))->toBe('unverified')->and(app(ChatAccess::class)->canRead($u, chatGlobal()))->toBeFalse();
    expect(fn () => chatMessages()->send($u, chatGlobal(), 'غير موثَّق'))->toThrow(\App\Services\Chat\ChatException::class, 'وثّق بريدك');
    expect(ChatMessage::count())->toBe(0);
});

test('25/K1: a frozen user cannot send - blocked by the account middleware and by the chat policy itself', function () {
    $u = e16User(['is_frozen' => true]);

    $status = chatSay($this, $u, 'مجمَّد')->getStatusCode();
    expect($status)->not->toBe(201)->and(app(ChatAccess::class)->sendBlocker($u, chatGlobal()))->toBe('frozen')->and(ChatMessage::count())->toBe(0);
    expect(fn () => chatMessages()->send($u, chatGlobal(), 'مجمَّد'))->toThrow(\App\Services\Chat\ChatException::class, 'مجمَّد');
});

test('26/D8: a muted user can still read the global room but cannot send - and a muted user can still delete their own earlier messages', function () {
    $mod = chatModerator();
    $u = e16User();
    $old = chatSay($this, $u, 'قبل الكتم')->assertCreated()->json('message.id');
    chatMutes()->mute($mod, $u, '1h', 'إزعاج متكرر');

    $this->actingAs($u)->get(route('community.chat'))->assertOk();
    chatSay($this, $u, 'بعد الكتم')->assertForbidden()->assertJsonPath('reason', 'muted');
    $config = $this->actingAs($u)->get(route('community.chat'))->viewData('config');
    expect($config['caps'])->toMatchArray(['send' => false, 'blocker' => 'muted'])->and(ChatMessage::count())->toBe(1);
    $this->actingAs($u)->deleteJson(route('chat.messages.destroy', $old))->assertOk();
});

test('27/D9: a temporary mute expires on its own and sending works again - and unmuting lifts it at once', function () {
    $mod = chatModerator();
    $u = e16User();
    $v = e16User();
    Carbon::setTestNow(now());
    chatMutes()->mute($mod, $u, '10m', 'سبب');
    chatMutes()->mute($mod, $v, '24h', 'سبب');
    chatSay($this, $u, 'ممنوع')->assertForbidden();

    Carbon::setTestNow(now()->addMinutes(11));
    chatSay($this, $u, 'عدت بعد انتهاء المدة')->assertCreated();
    chatSay($this, $v, 'ما زلت مكتومًا')->assertForbidden();
    expect(chatMutes()->unmute($mod, $v))->toBe(1);
    chatSay($this, $v, 'رُفع الكتم')->assertCreated();
    expect(fn () => chatMutes()->mute($mod, $u, '3y', 'x'))->toThrow(\App\Services\Chat\ChatException::class, 'مدة الكتم')
        ->and(fn () => chatMutes()->mute($mod, $u, '1h', '   '))->toThrow(\App\Services\Chat\ChatException::class, 'سبب');
});

test('28/D5: the global rate limit holds - three per ten seconds as burst protection and ten per minute - and recovers after the window', function () {
    $burst = e16User();
    $steady = e16User();
    Carbon::setTestNow(now());

    foreach (['أ', 'ب', 'ج'] as $word) {
        chatSay($this, $burst, "رسالة {$word}")->assertCreated();
    }
    chatSay($this, $burst, 'رابعة بنفس اللحظة')->assertStatus(429);                       // حماية الدفعات: 3 كل 10 ثوانٍ

    // وتيرة هادئة (كل 4 ثوانٍ لا تتجاوز الدفعة) لكنها تبلغ 10 رسائل خلال الدقيقة: الحادية عشرة مرفوضة.
    for ($i = 1; $i <= 10; $i++) {
        chatSay($this, $steady, "وتيرة هادئة {$i}")->assertCreated();
        Carbon::setTestNow(now()->addSeconds(4));
    }
    chatSay($this, $steady, 'الحادية عشرة')->assertStatus(429);                            // 10/دقيقة

    Carbon::setTestNow(now()->addSeconds(61));
    chatSay($this, $steady, 'بعد انتهاء الدقيقة')->assertCreated();
    expect(ChatMessage::where('sender_id', $steady->id)->count())->toBe(11)->and(ChatMessage::where('sender_id', $burst->id)->count())->toBe(3);
});

test('29/D6: repeating the same text quickly is refused for the same user in the global room - another user or a later time is fine', function () {
    $u = e16User();
    $v = e16User();
    Carbon::setTestNow(now());

    chatSay($this, $u, 'رسالة مكررة')->assertCreated();
    chatSay($this, $u, '  رسالة مكررة  ')->assertStatus(422)->assertJsonPath('reason', 'duplicate');
    chatSay($this, $v, 'رسالة مكررة')->assertCreated();

    Carbon::setTestNow(now()->addSeconds(61));
    chatSay($this, $u, 'رسالة مكررة')->assertCreated();
    expect(ChatMessage::count())->toBe(3);
});

test('30/D4: a blocked userss global messages are filtered for the blocker (and by symmetry the blocked) - the source rows stay complete', function () {
    $a = e16User();
    $b = e16User();
    $c = e16User();
    Carbon::setTestNow(now());
    chatSay($this, $a, 'رسالة أ')->assertCreated();
    Carbon::setTestNow(now()->addSeconds(11));
    chatSay($this, $b, 'رسالة ب')->assertCreated();
    Carbon::setTestNow(now()->addSeconds(11));
    chatSay($this, $c, 'رسالة ج')->assertCreated();
    chatBlocks()->block($a, $b);

    $thread = chatGlobal();
    $bodies = fn ($user) => collect($this->actingAs($user)->getJson(route('chat.messages', $thread))->json('messages'))->pluck('body')->all();

    expect($bodies($a))->toBe(['رسالة أ', 'رسالة ج'])->and($bodies($b))->toBe(['رسالة ب', 'رسالة ج'])->and($bodies($c))->toBe(['رسالة أ', 'رسالة ب', 'رسالة ج']);
    expect(ChatMessage::count())->toBe(3);
    $config = $this->actingAs($a)->get(route('community.chat'))->viewData('config');
    expect($config['hidden_sender_ids'])->toBe([$b->public_id]);
});
