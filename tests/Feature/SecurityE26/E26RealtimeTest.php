<?php

require_once __DIR__.'/_support.php';

use App\Events\Chat\ChatMessageSent;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;

/*
| E26 (41-45) - أمان القنوات اللحظية. كل شيء محلي: توقيع مصادقة القناة يُحسب بمفتاح اختبار وهمي بلا أي اتصال شبكي بـReverb.
| مسار الهجوم: مستخدم مصادَق (أو ضيف) يطلب اشتراكًا بقناة خاصة ليست له عبر POST /broadcasting/auth الحقيقي (لا استدعاء للدالة داخليًا).
*/

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
});

if (! function_exists('e26_use_real_broadcaster')) {
    /** يحوّل البثّ إلى سائق reverb بمفاتيح وهمية (التوقيع محلي)، ويعيد تسجيل قنوات routes/channels.php عليه. */
    function e26_use_real_broadcaster(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'e26-test-key',
            'broadcasting.connections.reverb.secret' => 'e26-test-secret-not-real-0123456789',
            'broadcasting.connections.reverb.app_id' => '26',
            'broadcasting.connections.reverb.options.host' => 'localhost',
            'broadcasting.connections.reverb.options.port' => 8080,
            'broadcasting.connections.reverb.options.scheme' => 'http',
            'broadcasting.connections.reverb.options.useTLS' => false,
        ]);
        Broadcast::forgetDrivers();
        require base_path('routes/channels.php');
    }

    function e26_subscribe($test, ?\App\Models\User $user, string $channel)
    {
        $request = $user ? $test->actingAs($user) : $test;

        return $request->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $channel], ['Accept' => 'application/json']);
    }
}

test('41: an unauthorized user cannot subscribe to someone else\'s private chat channel - guests, outsiders, forged and unknown channels are all refused, participants succeed', function () {
    e26_use_real_broadcaster();
    [$a, $b] = chatFriends();
    $thread = chatThreads()->directOrCreate($a, $b);
    $channel = 'private-chat.'.$thread->public_id;
    $outsider = e26_user();

    e26_subscribe($this, null, $channel)->assertForbidden();              // ضيف
    e26_subscribe($this, $outsider, $channel)->assertForbidden();         // مستخدم موثَّق غريب
    e26_subscribe($this, $outsider, 'private-chat.'.str_repeat('z', 26))->assertForbidden();   // معرّف مختلق
    e26_subscribe($this, $outsider, 'private-chat.1')->assertForbidden(); // محاولة تخمين معرّف تسلسلي
    e26_subscribe($this, $outsider, 'private-chat.')->assertForbidden();

    $ok = e26_subscribe($this, $a, $channel);                              // الطرف الأول يمرّ
    $ok->assertOk();
    expect($ok->json('auth'))->toStartWith('e26-test-key:');
    e26_subscribe($this, $b, $channel)->assertOk();                        // الطرف الثاني يمرّ

    // لا يوجد تخويل لقنوات خاصة غير معرَّفة (لا قنوات user.{id} ولا presence خفية).
    foreach (['private-user.'.$a->getKey(), 'private-App.Models.User.'.$a->getKey(), 'presence-chat.'.$thread->public_id, 'private-admin', 'private-wallet.'.$a->getKey()] as $other) {
        e26_subscribe($this, $a, $other)->assertForbidden();
    }
});

test('41b: the channel name cannot be coaxed past the guard with an unprefixed (public-looking) name or odd casing', function () {
    e26_use_real_broadcaster();
    [$a, $b] = chatFriends();
    $thread = chatThreads()->directOrCreate($a, $b);
    $outsider = e26_user();

    foreach (['chat.'.$thread->public_id, 'PRIVATE-chat.'.$thread->public_id, ' private-chat.'.$thread->public_id, 'private-chat.'.$thread->public_id.'/../x', 'private-chat.'.strtoupper($thread->public_id)] as $channel) {
        $response = e26_subscribe($this, $outsider, $channel);
        expect($response->getStatusCode())->toBeIn([401, 403, 404, 422], "channel [{$channel}] must not authorize an outsider");
        expect((string) $response->getContent())->not->toContain('e26-test-key:');
    }
});

test('41c: presence-* and every non-private-chat channel name is refused before any authorization runs (regression: presence-chat.{id} used to leak the internal numeric user id)', function () {
    e26_use_real_broadcaster();
    [$a, $b] = chatFriends();
    $thread = chatThreads()->directOrCreate($a, $b);

    $response = e26_subscribe($this, $a, 'presence-chat.'.$thread->public_id);
    $response->assertForbidden();
    expect($response->json('auth'))->toBeNull()->and($response->json('channel_data'))->toBeNull();

    foreach (['chat.'.$thread->public_id, 'private-chat', 'private-chatter.'.$thread->public_id, 'private-chat.'.$thread->public_id.'.x', ''] as $name) {
        e26_subscribe($this, $a, $name)->assertForbidden();
    }

    expect(file_get_contents(base_path('bootstrap/app.php')))->toContain('RestrictBroadcastChannels::class');
});

test('42: blocked, non-member, removed and unverified users are refused on direct, team and global channels', function () {
    e26_use_real_broadcaster();

    // direct: غريب مرفوض حتى لو صديق لأحد الطرفين.
    [$a, $b] = chatFriends();
    $direct = chatThreads()->directOrCreate($a, $b);
    $c = e26_user();
    e16Befriend($a, $c);
    e26_subscribe($this, $c, 'private-chat.'.$direct->public_id)->assertForbidden();

    // team: غير عضو مرفوض، العضو مقبول، وبعد خروجه/إزالته يُقطع الاشتراك عند إعادة التخويل.
    [$team, $owner, $admin, $member] = chatTeam();
    $teamThread = chatThreads()->forTeam($team);
    $teamChannel = 'private-chat.'.$teamThread->public_id;
    $stranger = e26_user();

    e26_subscribe($this, $stranger, $teamChannel)->assertForbidden();
    e26_subscribe($this, $member, $teamChannel)->assertOk();
    app(\App\Services\Teams\TeamMembershipService::class)->leave($member);
    e26_subscribe($this, $member->refresh(), $teamChannel)->assertForbidden();

    // global: غير موثَّق البريد مرفوض (القراءة تتطلب بريدًا موثَّقًا غير مجمَّد).
    $global = chatGlobal();
    $unverified = e26_user(['email_verified_at' => null]);
    e26_subscribe($this, $unverified, 'private-chat.'.$global->public_id)->assertForbidden();
    e26_subscribe($this, $stranger, 'private-chat.'.$global->public_id)->assertOk();
});

test('43: the broadcast payload contains only public-safe fields - no email, phone, flags or moderation data - and goes only to a private channel', function () {
    $sender = e26_user(['name' => 'مرسل', 'email' => 'e26.leaky@example.test']);
    $captured = null;
    Event::listen(ChatMessageSent::class, function ($event) use (&$captured) {
        $captured = $event;
    });

    $this->actingAs($sender)->postJson(route('community.chat.send'), ['body' => 'رسالة <script>alert(1)</script>'])->assertCreated();

    expect($captured)->not->toBeNull();
    $payload = $captured->broadcastWith();
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);

    expect(array_keys($payload))->toBe(['id', 'thread', 'sender', 'state', 'body', 'edited', 'created_at']);
    expect(array_keys($payload['sender']))->toBe(['public_id', 'name']);
    foreach (['e26.leaky', 'email', 'phone', 'password', 'remember_token', 'is_frozen', 'hidden_', 'ip_address', 'user_agent'] as $needle) {
        expect($json)->not->toContain($needle);
    }

    foreach ($captured->broadcastOn() as $channel) {
        expect($channel)->toBeInstanceOf(\Illuminate\Broadcasting\PrivateChannel::class);   // لا قناة عامة ولا presence
        expect($channel->name)->toStartWith('private-chat.');
    }
});

test('44: a frozen user (or one frozen while subscribed) is refused when the channel is re-authorized, and the HTTP channel-auth endpoint is rate limited', function () {
    e26_use_real_broadcaster();
    [$a, $b] = chatFriends();
    $channel = 'private-chat.'.chatThreads()->directOrCreate($a, $b)->public_id;

    e26_subscribe($this, $a, $channel)->assertOk();
    e26_freeze($a);
    e26_subscribe($this, $a->refresh(), $channel)->assertForbidden();   // التجميد يقطع القراءة اللحظية نفسها (EnsureAccountIsNotFrozen أو ChatAccess)

    // حد المعدّل: 90/دقيقة لكل مستخدم/IP (يمنع تخمين معرّفات الغرف أو إغراق الاستعلامات).
    $user = e26_user();
    $statuses = e26_hammer($this, 'post', '/broadcasting/auth', 100, ['socket_id' => '1.2', 'channel_name' => 'private-chat.nope']);
    $first = e26_first_429($statuses);
    expect($first)->not->toBeNull()->and($first)->toBeGreaterThan(30);
});

test('45: Reverb is closed by default - client (whisper) events off and, in production, a wildcard origin is surfaced by production:check instead of passing silently', function () {
    expect(config('reverb.apps.apps.0.accept_client_events_from'))->toBe('none');
    expect(config('reverb.apps.apps.0.allowed_origins'))->toBe(['*']);    // الافتراضي المحلي لا يتغير (E21)

    e25_as_production();
    $reverb = [
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.app_id' => '26',
        'broadcasting.connections.reverb.key' => 'e26-test-key',
        'broadcasting.connections.reverb.secret' => 'e26-test-secret-not-real-0123456789',
        'reverb.apps.apps.0.options.scheme' => 'https',
        'reverb.servers.reverb.hostname' => 'ws.ahjiyat.example.com',
    ];

    [, $wildcard] = e25_check($reverb + ['reverb.apps.apps.0.allowed_origins' => ['*']]);
    expect(e25_statuses($wildcard, 'REVERB_ALLOWED_ORIGINS'))->toContain('WARN');

    [, $strict] = e25_check($reverb + ['reverb.apps.apps.0.allowed_origins' => ['ahjiyat.example.com']]);
    expect(e25_statuses($strict, 'REVERB_ALLOWED_ORIGINS'))->toContain('PASS')->not->toContain('WARN');

    // قيمة بيئة خاطئة لا تفتح أحداث العميل: لا يقبل التطبيق 'all' إلا إن ضُبطت صراحة.
    expect(file_get_contents(base_path('config/reverb.php')))->toContain("env('REVERB_APP_ACCEPT_CLIENT_EVENTS_FROM', 'none')");
});

test('45b: the front-end never publishes client events, so closing them breaks nothing (no whisper/trigger usage in resources/js)', function () {
    $offenders = [];
    $dir = base_path('resources/js');

    if (is_dir($dir)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && preg_match('/\.(js|mjs|ts)$/', $file->getFilename())) {
                $source = file_get_contents($file->getPathname());
                if (preg_match('/\.whisper\s*\(|\.trigger\s*\(\s*[\'"]client-/', $source)) {
                    $offenders[] = e26_relative($file->getPathname());
                }
            }
        }
    }

    expect($offenders)->toBe([]);
});
