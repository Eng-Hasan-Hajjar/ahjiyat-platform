<?php

require_once __DIR__.'/ChatTestHelpers.php';

use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\OperationalAuditLog;
use App\Services\Chat\ChatException;

beforeEach(fn () => $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class));

function chatTeamRoom(): array
{
    $team = e19Team(null, ['name' => 'فريق دردشة '.\Illuminate\Support\Str::random(4), 'join_policy' => 'open']);
    $admin = e19Member($team, null, 'admin');
    $member = e19Member($team);

    return [$team->refresh(), $team->owner, $admin, $member];
}

test('14/15: a current member reads and sends in the one team room - the room is created lazily and only once', function () {
    [$team, $owner, $admin, $member] = chatTeamRoom();

    $this->actingAs($member)->get(route('teams.chat', $team))->assertOk();
    $this->actingAs($member)->postJson(route('teams.chat.send', $team), ['body' => 'أهلًا بالفريق'])->assertCreated()->assertJsonPath('message.body', 'أهلًا بالفريق');
    $this->actingAs($owner)->postJson(route('teams.chat.send', $team), ['body' => 'أهلًا بك'])->assertCreated();

    expect(ChatThread::where('type', 'team')->count())->toBe(1)->and(ChatMessage::count())->toBe(2);
    $config = $this->actingAs($admin)->get(route('teams.chat', $team))->viewData('config');
    expect(collect($config['messages'])->pluck('body')->all())->toBe(['أهلًا بالفريق', 'أهلًا بك'])->and($config['type'])->toBe('team');

    // غرفة فريق ثانية مستحيلة بالقاعدة.
    expect(fn () => (new ChatThread)->forceFill(['type' => 'team', 'team_id' => $team->id, 'is_active' => true])->save())->toThrow(\Illuminate\Database\UniqueConstraintViolationException::class);
});

test('16: a non-member cannot read or send - the room is invisible (404) and nothing is created for them', function () {
    [$team] = chatTeamRoom();
    $outsider = e16User();

    $this->actingAs($outsider)->get(route('teams.chat', $team))->assertNotFound();
    $this->actingAs($outsider)->postJson(route('teams.chat.send', $team), ['body' => 'تطفّل', 'team_id' => $team->id, 'chat_thread_id' => 1])->assertNotFound();
    expect(ChatMessage::count())->toBe(0);
});

test('17/C3: leaving the team removes access at once - reading, paging, sending and the private channel - while the history stays in the database', function () {
    [$team, , , $member] = chatTeamRoom();
    $this->actingAs($member)->postJson(route('teams.chat.send', $team), ['body' => 'قبل المغادرة'])->assertCreated();
    $thread = ChatThread::firstOrFail();
    expect(chatChannelAllows($member, 'chat.'.$thread->public_id))->toBeTrue();

    e19Members()->leave($member);

    $this->actingAs($member)->get(route('teams.chat', $team))->assertNotFound();
    $this->actingAs($member)->postJson(route('teams.chat.send', $team), ['body' => 'بعد المغادرة'])->assertNotFound();
    $this->actingAs($member)->getJson(route('chat.messages', $thread))->assertNotFound();
    $this->actingAs($member)->postJson(route('chat.read', $thread))->assertNotFound();
    expect(chatChannelAllows($member, 'chat.'.$thread->public_id))->toBeFalse()->and(ChatMessage::count())->toBe(1);
});

test('18/C4: rejoining restores access and the member sees the current team history (documented baseline)', function () {
    [$team, , , $member] = chatTeamRoom();
    $this->actingAs($member)->postJson(route('teams.chat.send', $team), ['body' => 'رسالة قديمة'])->assertCreated();
    e19Members()->leave($member);
    e19Members()->joinOpen($member->refresh(), $team->refresh());

    $config = $this->actingAs($member)->get(route('teams.chat', $team))->assertOk()->viewData('config');
    expect(collect($config['messages'])->pluck('body')->all())->toBe(['رسالة قديمة'])->and($config['caps']['send'])->toBeTrue();
    $this->actingAs($member)->postJson(route('teams.chat.send', $team), ['body' => 'عدتُ'])->assertCreated();
});

test('19/C5: the owner and the admin can hide a violating message with a reason - the text is never edited and stays for review - the hide is audited', function () {
    [$team, $owner, $admin, $member] = chatTeamRoom();
    $id = $this->actingAs($member)->postJson(route('teams.chat.send', $team), ['body' => 'رسالة مخالفة'])->json('message.id');
    $id2 = $this->actingAs($member)->postJson(route('teams.chat.send', $team), ['body' => 'مخالفة ثانية'])->json('message.id');

    $this->actingAs($owner)->postJson(route('chat.messages.hide', $id), ['reason' => 'ألفاظ غير لائقة'])->assertOk()->assertJsonPath('message.state', 'hidden')->assertJsonPath('message.body', null)->assertJsonPath('message.moderation_body', 'رسالة مخالفة');
    $this->actingAs($admin)->postJson(route('chat.messages.hide', $id2), ['reason' => 'إزعاج'])->assertOk()->assertJsonPath('message.state', 'hidden');
    expect(ChatMessage::find($id)->body)->toBe('رسالة مخالفة')->and(ChatMessage::find($id)->hidden_by_id)->toBe($owner->id)->and(ChatMessage::find($id)->hidden_reason)->toBe('ألفاظ غير لائقة')
        ->and(OperationalAuditLog::where('action', 'chat_message_hidden')->count())->toBe(2);

    // الأعضاء العاديون يرون الإخفاء بلا نص، والمشرف وحده يرى النص للاستعادة.
    $asMember = collect($this->actingAs($member)->get(route('teams.chat', $team))->viewData('config')['messages'])->firstWhere('id', $id);
    expect($asMember['body'])->toBeNull()->and($asMember['moderation_body'])->toBeNull();

    $this->actingAs($owner)->postJson(route('chat.messages.restore', $id), ['reason' => 'أُسيء الفهم'])->assertOk()->assertJsonPath('message.state', 'normal')->assertJsonPath('message.body', 'رسالة مخالفة');
    expect(fn () => chatMessages()->edit($owner, ChatMessage::find($id2), 'تعديل نص غيره'))->toThrow(ChatException::class, 'رسائلك فقط');       // لا تعديل لنص رسالة شخص آخر أبدًا
});

test('20/C6: a plain member cannot moderate - not even their own team room', function () {
    [$team, $owner, , $member] = chatTeamRoom();
    $id = $this->actingAs($owner)->postJson(route('teams.chat.send', $team), ['body' => 'رسالة المالك'])->json('message.id');

    $this->actingAs($member)->postJson(route('chat.messages.hide', $id), ['reason' => 'محاولة'])->assertForbidden();
    expect(ChatMessage::find($id)->hidden_at)->toBeNull()->and(OperationalAuditLog::where('action', 'chat_message_hidden')->count())->toBe(0);
});

test('21/C7: a deactivated team room is read-only for its current members with a clear reason', function () {
    [$team, $owner, , $member] = chatTeamRoom();
    $this->actingAs($member)->postJson(route('teams.chat.send', $team), ['body' => 'قبل التعطيل'])->assertCreated();
    e19Teams()->deactivate($owner, $team);

    $config = $this->actingAs($member)->get(route('teams.chat', $team->refresh()))->assertOk()->viewData('config');
    expect($config['caps'])->toMatchArray(['send' => false, 'blocker' => 'team_inactive'])->and(collect($config['messages'])->pluck('body')->all())->toBe(['قبل التعطيل']);
    $this->actingAs($member)->postJson(route('teams.chat.send', $team), ['body' => 'بعد التعطيل'])->assertForbidden()->assertJsonPath('reason', 'team_inactive');
    expect(ChatMessage::count())->toBe(1);
});
