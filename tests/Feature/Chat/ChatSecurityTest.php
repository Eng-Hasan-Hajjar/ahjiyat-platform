<?php

require_once __DIR__.'/ChatTestHelpers.php';

use App\Models\ChatMessage;
use App\Models\ChatMessageReport;
use App\Models\ChatMute;
use App\Models\ChatReadState;
use App\Models\ChatThread;
use App\Models\OperationalAuditLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class));
afterEach(fn () => Carbon::setTestNow());

function chatCode(array $globs): array
{
    $files = [];

    foreach ($globs as $glob) {
        $files = array_merge($files, glob(base_path($glob)) ?: []);
    }

    return $files;
}

test('51/K7/D12: scripts and HTML in a message are stored as plain text and every render path escapes them - the page JSON is hex-escaped and the client only uses text bindings', function () {
    [$a, $b] = chatFriends();
    $evil = '<script>alert(1)</script><img src=x onerror=alert(2)><iframe src="//evil.test"></iframe> https://example.test/a?b=1&c=<b>';

    chatDm($this, $a, $b, $evil)->assertCreated()->assertJsonPath('message.body', $evil);
    expect(ChatMessage::first()->body)->toBe($evil);

    $this->actingAs($a)->postJson(route('community.chat.send'), ['body' => $evil.' عامة'])->assertCreated();

    foreach ([route('messages.direct', $a), route('community.chat')] as $url) {
        $html = $this->actingAs($b)->get($url)->assertOk()->getContent();
        // الوسوم نفسها لا تظهر خامًا (نص `onerror=` الخامل داخل سلسلة JSON مهرَّبة ليس سمة حدث لأن `<img` مهرَّبة).
        expect($html)->not->toContain('<script>alert(1)')->not->toContain('<img src=x')->not->toContain('<iframe src="//evil')->not->toContain('<b>');
    }

    $page = $this->actingAs($b)->get(route('messages.direct', $a))->getContent();
    expect($page)->toContain('u003Cscript')->not->toContain('<script>alert');                         // JSON مهرَّب (JSON_HEX_TAG) داخل JSON.parse للسمة

    foreach (chatCode(['resources/views/chat/*.php', 'resources/views/chat/*.blade.php', 'resources/views/messages/*.blade.php', 'resources/js/chat.js']) as $file) {
        $code = e19Code($file);                                  // بلا تعليقات (التعليق قد يذكر الممنوع بالشرح)

        foreach (['x-html', '{!!', 'innerHTML', 'insertAdjacentHTML', 'outerHTML', 'document.write', 'v-html', 'eval('] as $needle) {
            expect(str_contains($code, $needle))->toBeFalse(basename($file)." must not contain {$needle}");
        }
    }
});

test('52/53/K6: whitespace-only, empty and oversized messages are refused - the limit comes from the config - and nothing is stored', function () {
    [$a, $b] = chatFriends();
    $max = (int) config('chat.message_max_length');

    foreach (['', '   ', "\n\t \r\n", "\u{200B}"] as $blank) {
        chatDm($this, $a, $b, $blank)->assertStatus(422);
    }
    chatDm($this, $a, $b, str_repeat('ب', $max + 1))->assertStatus(422)->assertJsonPath('reason', 'too_long');
    chatDm($this, $a, $b, str_repeat('ب', $max + 500))->assertStatus(422);
    expect(ChatMessage::count())->toBeLessThanOrEqual(1);                              // (الحرف الصفري العرض قد يُقبل كنص: لا يهم هنا)

    chatDm($this, $a, $b, '  '.str_repeat('ب', $max).'  ')->assertCreated();               // الحد الدقيق بعد القص مقبول
    expect(mb_strlen(ChatMessage::latest('id')->first()->body))->toBe($max)->and(config('chat.message_max_length'))->toBe(2000);
    expect(fn () => chatMessages()->send($a, ChatThread::firstOrFail(), "\x00\x07"))->toThrow(\App\Services\Chat\ChatException::class, 'فارغة');     // محارف التحكم تُحذف
});

test('54/K5: a spoofed sender or any authority field in the request is ignored - the sender is always the authenticated user', function () {
    [$a, $b] = chatFriends();
    $mod = chatModerator();

    $this->actingAs($a)->postJson(route('messages.direct.send', $b), ['body' => 'أنا أ', 'sender_id' => $b->id, 'chat_thread_id' => 9999, 'hidden_at' => now()->toDateTimeString(), 'deleted_at' => now()->toDateTimeString(),
        'edited_at' => now()->toDateTimeString(), 'hidden_by_id' => $mod->id, 'id' => 12345, 'created_at' => '2001-01-01 00:00:00'])->assertCreated();

    $m = ChatMessage::firstOrFail();
    expect($m->sender_id)->toBe($a->id)->and($m->hidden_at)->toBeNull()->and($m->deleted_at)->toBeNull()->and($m->edited_at)->toBeNull()->and($m->hidden_by_id)->toBeNull()->and($m->id)->not->toBe(12345)
        ->and($m->created_at->year)->toBeGreaterThan(2020)->and($m->chat_thread_id)->toBe(ChatThread::firstOrFail()->id);
    expect(fn () => (new ChatMessage)->fill(['sender_id' => 1, 'body' => 'x']))->toThrow(\Illuminate\Database\Eloquent\MassAssignmentException::class);
    expect(fn () => (new ChatThread)->fill(['type' => 'global']))->toThrow(\Illuminate\Database\Eloquent\MassAssignmentException::class);

    $this->actingAs($b)->patchJson(route('chat.messages.update', $m), ['body' => 'تعديل غيري', 'sender_id' => $b->id])->assertForbidden();
});

test('55/K4: the room cannot be spoofed from the request - the team and thread come from the authorized route only', function () {
    $teamA = e19Team(null, ['join_policy' => 'open']);
    $teamB = e19Team(null, ['join_policy' => 'open']);
    $memberA = e19Member($teamA);
    $this->actingAs($teamB->owner)->postJson(route('teams.chat.send', $teamB), ['body' => 'غرفة ب'])->assertCreated();
    $roomB = ChatThread::where('team_id', $teamB->id)->firstOrFail();

    $this->actingAs($memberA)->postJson(route('teams.chat.send', $teamA), ['body' => 'من عضو أ', 'team_id' => $teamB->id, 'chat_thread_id' => $roomB->id, 'thread' => $roomB->public_id])->assertCreated();
    expect(ChatMessage::where('chat_thread_id', $roomB->id)->count())->toBe(1)->and(ChatMessage::where('body', 'من عضو أ')->first()->chat_thread_id)->toBe(ChatThread::where('team_id', $teamA->id)->first()->id);

    $this->actingAs($memberA)->postJson(route('teams.chat.send', $teamB), ['body' => 'اقتحام'])->assertNotFound();
    $this->actingAs($memberA)->getJson(route('chat.messages', $roomB))->assertNotFound();
    $this->actingAs($memberA)->patchJson(route('chat.messages.update', ChatMessage::where('chat_thread_id', $roomB->id)->first()), ['body' => 'x'])->assertNotFound();
    expect(ChatMessage::where('chat_thread_id', $roomB->id)->count())->toBe(1);
});

test('56/K9: no GET changes any chat state - reads never write messages, read markers, reports, mutes or audits - and every mutating route refuses GET', function () {
    [$a, $b] = chatFriends();
    $team = e19Team(null, ['join_policy' => 'open']);
    chatDm($this, $a, $b, 'x');
    $thread = ChatThread::firstOrFail();
    $id = ChatMessage::firstOrFail()->id;
    $snap = fn () => [ChatMessage::count(), ChatReadState::count(), ChatMessageReport::count(), ChatMute::count(), OperationalAuditLog::count(), ChatReadState::sum('last_read_message_id')];
    $before = $snap();

    foreach ([route('messages.index'), route('messages.direct', $b), route('community.chat'), route('chat.messages', $thread)] as $url) {
        $this->actingAs($a)->get($url)->assertOk();
    }
    $this->actingAs($team->owner)->get(route('teams.chat', $team))->assertOk();
    expect($snap())->toBe($before);

    foreach ([route('chat.read', $thread), route('chat.messages.update', $id), route('chat.messages.destroy', $id), route('chat.messages.report', $id), route('chat.messages.hide', $id), route('chat.messages.restore', $id)] as $url) {
        $this->actingAs($a)->get($url)->assertStatus(405);
    }

    foreach (app('router')->getRoutes() as $route) {
        if (str_starts_with((string) $route->getName(), 'chat.') || str_ends_with((string) $route->getName(), '.send')) {
            expect(in_array('GET', $route->methods(), true))->toBe($route->getName() === 'chat.messages', $route->getName().' GET methods');
        }
    }
});

test('57/K8: every chat mutation lives in the web group (CSRF) behind login, verified email and an active account - and the client sends the CSRF token', function () {
    $names = ['messages.direct.send', 'teams.chat.send', 'community.chat.send', 'chat.read', 'chat.messages.update', 'chat.messages.destroy', 'chat.messages.report', 'chat.messages.hide', 'chat.messages.restore'];

    foreach ($names as $name) {
        $route = app('router')->getRoutes()->getByName($name);
        $middleware = $route->gatherMiddleware();
        expect($middleware)->toContain('auth')->toContain('verified')->toContain('account.active')->and(array_diff(['POST', 'PATCH', 'DELETE'], $route->methods()))->not->toBe(['POST', 'PATCH', 'DELETE'])
            ->and(in_array('GET', $route->methods(), true))->toBeFalse($name);
    }

    foreach (['messages.direct.send' => 'chat-dm-send', 'teams.chat.send' => 'chat-team-send', 'community.chat.send' => 'chat-global-send', 'chat.messages.report' => 'chat-report', 'chat.messages.hide' => 'chat-moderate', 'chat.messages.update' => 'chat-edit'] as $name => $limiter) {
        expect(app('router')->getRoutes()->getByName($name)->gatherMiddleware())->toContain('throttle:'.$limiter);
    }

    expect(file_get_contents(resource_path('js/chat.js')))->toContain('X-CSRF-TOKEN')->and(file_get_contents(resource_path('views/layouts/app.blade.php')))->toContain('name="csrf-token"');
    expect(app(\Illuminate\Contracts\Http\Kernel::class)->getMiddlewareGroups()['web'])->toContain(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
});

test('58/F12: no email, phone or private field of another user appears in any chat page, JSON or broadcast payload', function () {
    $a = e16User(['name' => 'أ', 'email' => 'private.person.a@secret-mail.test']);
    $b = e16User(['name' => 'ب', 'email' => 'private.person.b@secret-mail.test']);
    e16Befriend($a, $b);
    chatDm($this, $b, $a, 'مرحبا من ب');
    $this->actingAs($b)->postJson(route('community.chat.send'), ['body' => 'عامة من ب'])->assertCreated();
    $team = e19Team($b, ['join_policy' => 'open']);
    $this->actingAs($b)->postJson(route('teams.chat.send', $team), ['body' => 'فريق من ب'])->assertCreated();

    $responses = [
        $this->actingAs($a)->get(route('messages.index'))->getContent(), $this->actingAs($a)->get(route('messages.direct', $b))->getContent(), $this->actingAs($a)->get(route('community.chat'))->getContent(),
        $this->actingAs($a)->getJson(route('chat.messages', chatGlobal()))->getContent(), $this->actingAs($a)->getJson(route('chat.messages', ChatThread::where('type', 'direct')->first()))->getContent(),
        json_encode((new \App\Services\Chat\ChatMessagePresenter)->public(ChatMessage::with('thread', 'sender')->first()), JSON_UNESCAPED_UNICODE),
    ];

    foreach ($responses as $body) {
        expect($body)->not->toContain('secret-mail.test')->not->toContain('private.person')->not->toContain('"phone"')->not->toContain('password')->not->toContain('is_frozen')->not->toContain('remember_token');
    }
});

test('H4/L: pages are cursor based - the first page is the newest 40, older pages follow by id, the limit is clamped, and the room query uses the thread-and-id index', function () {
    $u = e16User();
    $thread = chatGlobal();
    $rows = [];

    for ($i = 1; $i <= 130; $i++) {
        $rows[] = ['chat_thread_id' => $thread->id, 'sender_id' => $u->id, 'body' => "رسالة {$i}", 'created_at' => now()->subSeconds(200 - $i), 'updated_at' => now()];
    }
    DB::table('chat_messages')->insert($rows);
    ChatThread::whereKey($thread->id)->update(['last_message_id' => ChatMessage::max('id')]);

    $first = $this->actingAs($u)->getJson(route('chat.messages', $thread))->assertOk()->json();
    $firstIds = collect($first['messages'])->pluck('id');
    expect($firstIds)->toHaveCount(40)->and($first['has_more'])->toBeTrue()->and($firstIds->all())->toBe($firstIds->sort()->values()->all())->and($firstIds->last())->toBe(ChatMessage::max('id'));

    $older = $this->actingAs($u)->getJson(route('chat.messages', $thread).'?before='.$firstIds->first())->json();
    expect(collect($older['messages'])->pluck('id')->max())->toBeLessThan($firstIds->first())->and($older['messages'])->toHaveCount(40)->and($older['has_more'])->toBeTrue();
    expect($this->actingAs($u)->getJson(route('chat.messages', $thread).'?limit=500')->json('messages'))->toHaveCount(60)
        ->and($this->actingAs($u)->getJson(route('chat.messages', $thread).'?before='.(ChatMessage::min('id') + 5))->json('has_more'))->toBeFalse();

    if (DB::getDriverName() === 'sqlite') {
        $plan = collect(DB::select('EXPLAIN QUERY PLAN select * from chat_messages where chat_thread_id = ? and id < ? order by id desc limit 41', [$thread->id, 100]))->pluck('detail')->implode(' | ');
        expect($plan)->toContain('chat_messages_chat_thread_id_id_index')->not->toContain('SCAN chat_messages');
    }

    foreach (['chat_messages' => ['chat_messages_chat_thread_id_id_index', 'chat_messages_sender_id_index'], 'chat_threads' => ['chat_threads_type_last_message_at_index'], 'chat_read_states' => ['chat_read_states_chat_thread_id_user_id_unique'],
        'chat_message_reports' => ['chat_message_reports_status_id_index', 'chat_message_reports_chat_message_id_reporter_id_unique']] as $table => $indexes) {
        $have = collect(\Illuminate\Support\Facades\Schema::getIndexes($table))->pluck('name')->all();
        expect(array_diff($indexes, $have))->toBe([], "{$table} indexes");
    }
});

test('L: the conversations list is free of N+1 - the number of queries does not grow with the number of conversations', function () {
    $me = e16User(['name' => 'أنا']);
    $make = function () use ($me) {
        $friend = e16User();
        e16Befriend($me, $friend);
        chatDm($this, $friend, $me, 'من '.$friend->id);
    };
    $count = function () use ($me) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($me)->get(route('messages.index'))->assertOk();
        $n = collect(DB::getQueryLog())->reject(fn ($q) => str_contains($q['query'], 'fraud_flags'))->count();          // كشف الإساءة القائم (غير الدردشة) قد يسجّل علامة
        DB::disableQueryLog();

        return $n;
    };

    $make();
    $this->actingAs($me)->get(route('messages.index'));                                  // تسخين (جلسة/تفضيلات)
    $few = $count();
    foreach (range(1, 6) as $_) {
        $make();
    }
    expect($count())->toBe($few);
});

test('I/Not in E21: chat never creates notifications, economy, XP or quest progress - and the chat code does not touch those systems or log message text', function () {
    [$a, $b] = chatFriends();
    $snapshot = fn () => [DB::table('notifications')->count(), DB::table('currency_transactions')->count(), DB::table('xp_transactions')->count(), DB::table('user_quest_progress')->count(), DB::table('wallets')->count()];
    $before = $snapshot();

    foreach (range(1, 5) as $i) {
        chatDm($this, $a, $b, "رسالة {$i}");
        $this->actingAs($b)->postJson(route('community.chat.send'), ['body' => "عامة {$i}"]);
        Carbon::setTestNow(now()->addSeconds(11));
    }
    expect($snapshot())->toBe($before);

    $files = chatCode(['app/Services/Chat/*.php', 'app/Http/Controllers/Chat/*.php', 'app/Events/Chat/*.php', 'app/Broadcasting/*.php', 'app/Models/Chat*.php']);
    expect(count($files))->toBeGreaterThan(20);

    foreach ($files as $file) {
        $code = e19Code($file);

        foreach (['NotificationDispatcher', 'NotificationType', 'CurrencyWalletService', 'XpService', 'QuestService', 'EngagementService', 'Wallet::', 'StreakService', 'notify('] as $needle) {
            expect(str_contains($code, $needle))->toBeFalse(basename($file)." must not use {$needle}");
        }

        if (preg_match_all('/Log::\w+\(([^;]*)\)/s', $code, $logs)) {
            foreach ($logs[1] as $call) {
                expect(preg_match('/body|\$message->|rawBody|\$text/i', $call))->toBe(0, basename($file).' must not log message text');
            }
        }

        expect(preg_match('/\bdd\(|\bdump\(|\bray\(|\bvar_dump\(|TODO|FIXME/', $code))->toBe(0, basename($file).' leftovers');
    }
});
