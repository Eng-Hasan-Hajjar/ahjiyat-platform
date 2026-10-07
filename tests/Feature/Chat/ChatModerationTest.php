<?php

require_once __DIR__.'/ChatTestHelpers.php';

use App\Filament\Resources\ChatMessageReportResource;
use App\Filament\Resources\ChatMessageReportResource\Pages\ListChatMessageReports;
use App\Filament\Resources\ChatMessageReportResource\Pages\ViewChatMessageReport;
use App\Filament\Resources\ChatMuteResource;
use App\Models\ChatMessage;
use App\Models\ChatMessageReport;
use App\Models\ChatMute;
use App\Models\ChatThread;
use App\Models\OperationalAuditLog;
use App\Services\Chat\ChatException;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    Carbon::setTestNow(now());
});
afterEach(fn () => Carbon::setTestNow());

function chatPost($test, $user, string $body): int
{
    Carbon::setTestNow(now()->addSeconds(11));

    return $test->actingAs($user)->postJson(route('community.chat.send'), ['body' => $body])->assertCreated()->json('message.id');
}

test('31/E1: a user reports a message with a closed category - the report is stored pending and only the reporter and message are linked', function () {
    [$author, $reporter] = [e16User(), e16User()];
    $id = chatPost($this, $author, 'رسالة مسيئة');

    $this->actingAs($reporter)->postJson(route('chat.messages.report', $id), ['category' => 'harassment', 'details' => 'تتنمّر عليّ'])->assertCreated();
    $report = ChatMessageReport::firstOrFail();

    expect($report->status)->toBe('pending')->and($report->category)->toBe('harassment')->and($report->details)->toBe('تتنمّر عليّ')->and($report->reporter_id)->toBe($reporter->id)->and($report->chat_message_id)->toBe($id)
        ->and($report->reviewed_by_id)->toBeNull();
    $this->actingAs($reporter)->postJson(route('chat.messages.report', chatPost($this, $author, 'أخرى')), ['category' => 'made-up'])->assertStatus(422);
    expect(ChatMessageReport::count())->toBe(1);
});

test('32/E4: reporting your own message is refused, and so is reporting a message you cannot see', function () {
    [$a, $b] = chatFriends();
    $mine = chatPost($this, $a, 'رسالتي');
    $dm = chatDm($this, $a, $b, 'خاصة')->json('message.id');

    $this->actingAs($a)->postJson(route('chat.messages.report', $mine), ['category' => 'spam'])->assertStatus(422)->assertJsonPath('reason', 'own_message');
    $this->actingAs(e16User())->postJson(route('chat.messages.report', $dm), ['category' => 'spam'])->assertNotFound();
    expect(ChatMessageReport::count())->toBe(0);
});

test('33/E3: the same user cannot report the same message twice - refused at the service and by the database', function () {
    [$author, $reporter] = [e16User(), e16User()];
    $id = chatPost($this, $author, 'مزعجة');

    $this->actingAs($reporter)->postJson(route('chat.messages.report', $id), ['category' => 'spam'])->assertCreated();
    $this->actingAs($reporter)->postJson(route('chat.messages.report', $id), ['category' => 'scam'])->assertStatus(409)->assertJsonPath('reason', 'already_reported');
    expect(ChatMessageReport::count())->toBe(1);

    $raw = fn () => (new ChatMessageReport)->forceFill(['chat_message_id' => $id, 'reporter_id' => $reporter->id, 'category' => 'spam', 'status' => 'pending'])->save();
    expect($raw)->toThrow(\Illuminate\Database\UniqueConstraintViolationException::class);
    $other = e16User();
    $this->actingAs($other)->postJson(route('chat.messages.report', $id), ['category' => 'spam'])->assertCreated();      // مبلِّغ آخر مسموح
    expect(ChatMessageReport::count())->toBe(2);
});

test('34: unauthorized moderation is denied - a plain user, and a viewer who has only chat.reports.view - for hiding, muting and reviewing', function () {
    [$author, $plain] = [e16User(), e16User()];
    $viewer = chatModerator(['chat.reports.view']);
    $id = chatPost($this, $author, 'رسالة');
    $this->actingAs($plain)->postJson(route('chat.messages.report', $id), ['category' => 'spam'])->assertCreated();
    $report = ChatMessageReport::firstOrFail();

    foreach ([$plain, $viewer] as $nobody) {
        $this->actingAs($nobody)->postJson(route('chat.messages.hide', $id), ['reason' => 'محاولة'])->assertForbidden();
        expect(fn () => chatMutes()->mute($nobody, $author, '1h', 'سبب'))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class)
            ->and(fn () => app(\App\Services\Chat\ChatReportService::class)->review($nobody, $report, 'dismissed'))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    }
    expect(ChatMessage::find($id)->hidden_at)->toBeNull()->and(ChatMute::count())->toBe(0)->and($report->refresh()->status)->toBe('pending')->and(OperationalAuditLog::whereIn('action', ['chat_message_hidden', 'chat_user_muted', 'chat_report_reviewed'])->count())->toBe(0);
});

test('35/36/D7/D10: a moderator hides and restores a global message with a mandatory reason - others see a hidden notice without the text, the moderator still sees it to decide', function () {
    [$author, $other] = [e16User(), e16User()];
    $mod = chatModerator();
    $id = chatPost($this, $author, 'محتوى مخالف');

    $this->actingAs($mod)->postJson(route('chat.messages.hide', $id), ['reason' => ''])->assertStatus(422);
    $this->actingAs($mod)->postJson(route('chat.messages.hide', $id), ['reason' => 'إساءة لفظية'])->assertOk()->assertJsonPath('message.state', 'hidden')->assertJsonPath('message.moderation_body', 'محتوى مخالف');

    $seen = collect($this->actingAs($other)->getJson(route('chat.messages', chatGlobal()))->json('messages'))->firstWhere('id', $id);
    expect($seen['state'])->toBe('hidden')->and($seen['body'])->toBeNull()->and($seen['moderation_body'])->toBeNull()->and(ChatMessage::find($id)->body)->toBe('محتوى مخالف');

    $this->actingAs($mod)->postJson(route('chat.messages.restore', $id), ['reason' => 'قرار خاطئ'])->assertOk()->assertJsonPath('message.state', 'normal')->assertJsonPath('message.body', 'محتوى مخالف');
    expect(ChatMessage::find($id)->hidden_at)->toBeNull()->and(ChatMessage::find($id)->hidden_reason)->toBeNull();
});

test('37/D11: moderation is audited - hide, restore, mute, unmute and report review - but ordinary messages never are, and the audit holds no message text', function () {
    [$author, $reporter] = [e16User(), e16User()];
    $mod = chatModerator();
    $before = OperationalAuditLog::count();
    $id = chatPost($this, $author, 'نص حساس لا يُسجَّل');
    chatPost($this, $reporter, 'رسالة عادية أخرى');
    expect(OperationalAuditLog::count())->toBe($before);                                       // لا تدقيق لرسائل عادية

    $this->actingAs($reporter)->postJson(route('chat.messages.report', $id), ['category' => 'spam'])->assertCreated();
    chatModeration()->hide($mod, ChatMessage::find($id), 'سبب أول');
    chatModeration()->restore($mod, ChatMessage::find($id)->refresh(), 'سبب ثانٍ');
    chatMutes()->mute($mod, $author, '1h', 'سبب الكتم');
    chatMutes()->unmute($mod, $author);
    app(\App\Services\Chat\ChatReportService::class)->review($mod, ChatMessageReport::firstOrFail(), 'actioned');

    $actions = OperationalAuditLog::whereIn('action', ['chat_message_hidden', 'chat_message_restored', 'chat_user_muted', 'chat_user_unmuted', 'chat_report_reviewed'])->pluck('action')->sort()->values()->all();
    expect($actions)->toBe(['chat_message_hidden', 'chat_message_restored', 'chat_report_reviewed', 'chat_user_muted', 'chat_user_unmuted']);
    expect(json_encode(OperationalAuditLog::query()->get()->toArray(), JSON_UNESCAPED_UNICODE))->not->toContain('نص حساس لا يُسجَّل');
});

function chatModeration(): \App\Services\Chat\ChatModerationService
{
    return app(\App\Services\Chat\ChatModerationService::class);
}

test('E7/B9: a reported direct message exposes only that message to a moderator - never the conversation - and an unreported private message cannot be touched at all', function () {
    [$a, $b] = chatFriends();
    $first = chatDm($this, $a, $b, 'رسالة خاصة أولى سرية')->json('message.id');
    $bad = chatDm($this, $b, $a, 'رسالة مسيئة مبلَّغ عنها')->json('message.id');
    chatDm($this, $a, $b, 'رسالة خاصة أخيرة سرية');
    $mod = chatModerator();
    $thread = ChatThread::firstOrFail();

    // المشرف ليس طرفًا: لا قراءة ولا صفحات ولا غرفة، ولا إخفاء لرسالة لم يُبلَّغ عنها.
    $this->actingAs($mod)->getJson(route('chat.messages', $thread))->assertNotFound();
    expect(fn () => chatModeration()->hide($mod, ChatMessage::find($first), 'محاولة تصفّح'))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);

    $this->actingAs($a)->postJson(route('chat.messages.report', $bad), ['category' => 'harassment'])->assertCreated();
    $report = ChatMessageReport::firstOrFail();

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs($mod);
    Livewire::test(ViewChatMessageReport::class, ['record' => $report->getRouteKey()])->assertSee('رسالة مسيئة مبلَّغ عنها')->assertSee('محادثة خاصة')->assertDontSee('رسالة خاصة أولى سرية')->assertDontSee('رسالة خاصة أخيرة سرية');
    Livewire::test(ListChatMessageReports::class)->assertCanSeeTableRecords([$report])->assertDontSee('رسالة خاصة أولى سرية');

    chatModeration()->hide($mod, ChatMessage::find($bad), 'إساءة مؤكَّدة');                  // استثناء الإشراف: مبلَّغ عنها فقط
    expect(ChatMessage::find($bad)->hidden_at)->not->toBeNull()->and(ChatMessage::find($first)->hidden_at)->toBeNull();
});

test('E6/E8/N: the report center is permission based - view needs chat.reports.view, actions need chat.moderate - and the actions work and audit', function () {
    [$author, $reporter] = [e16User(), e16User()];
    $viewer = chatModerator(['chat.reports.view']);
    $mod = chatModerator();
    $plain = e16User();
    $id = chatPost($this, $author, 'مخالفة');
    $this->actingAs($reporter)->postJson(route('chat.messages.report', $id), ['category' => 'spam'])->assertCreated();
    $report = ChatMessageReport::firstOrFail();
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->actingAs($plain);
    expect(Gate::allows('viewAny', ChatMessageReport::class))->toBeFalse()->and(Gate::allows('viewAny', ChatMute::class))->toBeFalse();

    $this->actingAs($viewer);
    expect(Gate::allows('viewAny', ChatMessageReport::class))->toBeTrue()->and(Gate::allows('viewAny', ChatMute::class))->toBeFalse()->and(ChatMessageReportResource::canCreate())->toBeFalse()->and(Gate::allows('update', $report))->toBeFalse();
    Livewire::test(ListChatMessageReports::class)->assertCanSeeTableRecords([$report])->assertTableActionHidden('hide', $report)->assertTableActionHidden('mute', $report)->assertTableActionHidden('dismiss', $report);

    $this->actingAs($mod);
    Livewire::test(ListChatMessageReports::class)->assertTableActionVisible('hide', $report)->callTableAction('hide', $report, data: ['reason' => 'مخالفة صريحة'])->assertHasNoTableActionErrors();
    expect(ChatMessage::find($id)->hidden_at)->not->toBeNull()->and($report->refresh()->status)->toBe('actioned')->and(OperationalAuditLog::where('action', 'chat_message_hidden')->count())->toBe(1);

    Livewire::test(ListChatMessageReports::class)->filterTable('status', 'actioned')->assertTableActionVisible('mute', $report)->callTableAction('mute', $report, data: ['duration' => '24h', 'reason' => 'تكرار المخالفة'])->assertHasNoTableActionErrors();
    $mute = ChatMute::firstOrFail();
    expect($mute->user_id)->toBe($author->id)->and($mute->expires_at->isFuture())->toBeTrue();

    expect(Gate::allows('viewAny', ChatMute::class))->toBeTrue();
    Livewire::test(\App\Filament\Resources\ChatMuteResource\Pages\ListChatMutes::class)->assertCanSeeTableRecords([$mute])->callTableAction('unmute', $mute)->assertHasNoTableActionErrors();
    expect(ChatMute::active()->count())->toBe(0)->and(ChatMuteResource::canCreate())->toBeFalse();
});

test('N4: there is no admin browser for direct messages - no message or thread resource exists, and no Filament file queries the messages or threads tables', function () {
    foreach (glob(app_path('Filament/Resources/*.php')) as $file) {
        expect(basename($file))->not->toMatch('/^Chat(Message|Thread)Resource\.php$/');
    }

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Filament'), FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $code = e19Code($file->getPathname());
            expect(preg_match('/\bChatThread\b|\bChatMessage::|chat_messages|chat_threads/', $code))->toBe(0, basename($file->getPathname()).' must not browse chat rooms');
        }
    }
});
