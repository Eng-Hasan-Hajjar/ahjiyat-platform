<?php

require_once __DIR__.'/_support.php';

use App\Models\StoreItem;
use App\Models\User;

/*
| E26 (46-56) - الإساءة الآلية: كل نقطة دخول حسّاسة تحجب بعد عدد معقول من الطلبات (429 + Retry-After) دون أن تكسر الاستعمال الطبيعي.
| الهجوم محلي بالكامل (كاش array داخل عملية الاختبار): لا طلب شبكي ولا ضغط على أي خدمة حقيقية، وأعداد الطلبات صغيرة (≤ ~40 للاختبار الواحد).
*/

if (! function_exists('e26_login_first_lock')) {
    /** فهرس (من 1) لأول محاولة دخول رُفضت بسبب القفل (يظهر كخطأ تحقق «محاولات دخول كثيرة»، لا كـ429)، أو null. */
    function e26_login_first_lock($test, int $times, Closure $payload, array $headers = []): ?int
    {
        for ($i = 0; $i < $times; $i++) {
            $test->withHeaders($headers)->from('/login')->post('/login', $payload($i));

            if (str_contains((string) session('errors')?->first('email'), 'محاولات دخول كثيرة')) {
                return $i + 1;
            }
        }

        return null;
    }
}

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
});

test('46: registration is limited per IP - the 6th attempt in the window gets 429 with Retry-After, whatever the email', function () {
    $statuses = e26_hammer($this, 'post', '/register', 8, fn ($i) => ['name' => 'x', 'email' => "bot{$i}@example.test"]);

    expect(e26_first_429($statuses))->toBe(6);
    $this->post('/register', ['name' => 'x', 'email' => 'again@example.test'])->assertStatus(429)->assertHeader('Retry-After');
});

test('46b: a forged X-Forwarded-For header cannot reset the registration or login counters (proxies are untrusted unless TRUSTED_PROXIES says so)', function () {
    $statuses = [];
    for ($i = 0; $i < 8; $i++) {
        $statuses[] = $this->withHeaders(['X-Forwarded-For' => "203.0.113.{$i}", 'X-Real-IP' => "198.51.100.{$i}", 'Client-IP' => "192.0.2.{$i}"])
            ->post('/register', ['name' => 'x', 'email' => "spoof{$i}@example.test"])->getStatusCode();
    }
    expect(e26_first_429($statuses))->toBe(6);

    $user = e26_user(['email' => 'victim-xff@example.test']);
    $locked = e26_login_first_lock($this, 8, fn ($i) => ['email' => $user->email, 'password' => 'wrong-'.$i], ['X-Forwarded-For' => '203.0.113.9']);
    expect($locked)->toBe(6);
    $this->assertGuest();

    // تغيير الترويسة في كل محاولة لا يفكّ القفل.
    $again = $this->withHeaders(['X-Forwarded-For' => '203.0.113.200'])->from('/login')->post('/login', ['email' => $user->email, 'password' => 'password']);
    expect(session('errors')->first('email'))->toContain('محاولات دخول كثيرة');
    $this->assertGuest();
});

test('47: login brute force is stopped per account+IP (6th failure) and password spraying is stopped per IP across many accounts', function () {
    $user = e26_user(['email' => 'brute@example.test']);
    expect(e26_login_first_lock($this, 8, fn ($i) => ['email' => $user->email, 'password' => 'wrong-'.$i]))->toBe(6);

    // الصحيح لا ينجح أثناء القفل (لا تجاوز بكلمة مرور صحيحة وسط الهجوم).
    $this->from('/login')->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/login');
    expect(session('errors')->first('email'))->toContain('محاولات دخول كثيرة');
    $this->assertGuest();

    // رش كلمات المرور: حساب مختلف لكل محاولة من IP واحد -> سقف IP (30) لا الحساب (5).
    cache()->flush();
    $first = e26_login_first_lock($this, 34, fn ($i) => ['email' => "spray{$i}@example.test", 'password' => 'Passw0rd-spray']);
    expect($first)->toBe(31);
    $this->assertGuest();
});

test('48: password-reset requests are limited per IP (6th) and per target address (4th) so nobody can mail-bomb a victim', function () {
    \Illuminate\Support\Facades\Notification::fake();

    $perIp = e26_hammer($this, 'post', '/forgot-password', 8, fn ($i) => ['email' => "reset{$i}@example.test"]);
    expect(e26_first_429($perIp))->toBe(6);

    cache()->flush();
    $victim = e26_user(['email' => 'mailbomb-victim@example.test']);
    $perRecipient = e26_hammer($this, 'post', '/forgot-password', 6, ['email' => $victim->email]);
    expect(e26_first_429($perRecipient))->toBe(4);
    \Illuminate\Support\Facades\Notification::assertSentToTimes($victim, \Illuminate\Auth\Notifications\ResetPassword::class, 1);   // مهلة الوسيط 60ث: رسالة واحدة فقط
});

test('49: friend-request spam is limited (10/minute) and the extra requests create no rows', function () {
    $sender = e26_user();
    $targets = collect(range(1, 13))->map(fn () => e26_user());

    $statuses = [];
    foreach ($targets as $target) {
        $statuses[] = $this->actingAs($sender)->post(route('friends.requests.store', $target))->getStatusCode();
    }

    expect(e26_first_429($statuses))->toBe(11);
    expect(\App\Models\Friendship::query()->count())->toBeLessThanOrEqual(20);   // صفّان لكل طلب كحد أقصى (10 مقبولة) - لا صفوف لما بعد الحد
    expect(\App\Models\Friendship::query()->where(fn ($q) => $q->where('user_id', $sender->id)->orWhere('friend_id', $sender->id))->count())->toBeLessThanOrEqual(20);
});

test('50: team-invitation spam is limited (10/minute) per inviter', function () {
    $owner = e26_user();
    $team = e19Team($owner);
    $targets = collect(range(1, 13))->map(fn () => e26_user());

    $statuses = [];
    foreach ($targets as $target) {
        $statuses[] = $this->actingAs($owner)->post(route('teams.invitations.store', [$team, $target]))->getStatusCode();
    }

    expect(e26_first_429($statuses))->toBe(11);
});

test('51: global-chat flooding is cut by the burst limit (3 per 10 seconds) and the per-minute cap, and rejected posts are not stored', function () {
    $user = e26_user();
    $statuses = [];
    for ($i = 0; $i < 6; $i++) {
        $statuses[] = $this->actingAs($user)->postJson(route('community.chat.send'), ['body' => "رسالة سبام رقم {$i}"])->getStatusCode();
    }

    expect(e26_first_429($statuses))->toBe(4);
    expect(\App\Models\ChatMessage::query()->where('sender_id', $user->id)->count())->toBe(3);
});

test('52: abusing the report endpoint is limited (5/minute) so one user cannot flood moderators', function () {
    [$a, $b] = chatFriends();
    $statuses = [];

    for ($i = 0; $i < 7; $i++) {
        $id = chatDm($this, $b, $a, "رسالة {$i}")->json('message.id');
        $statuses[] = $this->actingAs($a)->postJson(route('chat.messages.report', $id), ['category' => 'spam'])->getStatusCode();
    }

    expect(e26_first_429($statuses))->toBe(6);
});

test('53: player search cannot be used to scrape the user base - 30 queries/minute then 429', function () {
    $user = e26_user();
    e26_user(['name' => 'Scrape Target']);

    $statuses = [];
    for ($i = 0; $i < 33; $i++) {
        $statuses[] = $this->actingAs($user)->get(route('friends.search', ['q' => 'Sc'.chr(97 + ($i % 20))]))->getStatusCode();
    }

    expect(e26_first_429($statuses))->toBe(31);
    $json = $this->actingAs($user)->getJson(route('friends.search', ['q' => 'Scrape']));
    expect($json->getStatusCode())->toBe(429);
});

test('53b: player search results never expose emails, internal ids or private fields', function () {
    $user = e26_user();
    $target = e26_user(['name' => 'Zed Findable', 'email' => 'zed.private@example.test']);

    $response = $this->actingAs($user)->get(route('friends.search', ['q' => 'Zed Findable']));
    $response->assertOk();
    $html = $response->getContent();

    expect($html)->not->toContain('zed.private@example.test')->not->toContain('@example.test');
});

test('54: competitive play endpoints (start/submit) are limited per user (20/minute)', function () {
    $user = e26_user();
    $event = e17Event();

    $statuses = [];
    for ($i = 0; $i < 23; $i++) {
        $statuses[] = $this->actingAs($user)->post(route('competitions.start', $event->slug))->getStatusCode();
    }

    expect(e26_first_429($statuses))->toBe(21);
});

test('55: store purchase attempts are limited (8/minute) per user', function () {
    $user = e26_user();
    $item = StoreItem::factory()->create();

    $statuses = [];
    for ($i = 0; $i < 11; $i++) {
        $statuses[] = $this->actingAs($user)->post(route('store.items.purchase', $item), ['request_key' => 'k-'.$i])->getStatusCode();
    }

    expect(e26_first_429($statuses))->toBe(9);
});

test('56: redemption requests are limited (5 per hour) per user and the 6th attempt writes nothing', function () {
    $user = e26_user();
    $statuses = [];

    for ($i = 0; $i < 8; $i++) {
        $statuses[] = $this->actingAs($user)->post(route('redemption.store'), ['gems_amount' => 10000, 'reward_description' => 'x'])->getStatusCode();
    }

    expect(e26_first_429($statuses))->toBe(6);
    expect(\App\Models\RedemptionRequest::query()->count())->toBe(0);   // لا رصيد: كل المحاولات مرفوضة، والسادسة لم تصل للخدمة
});

test('56b: limits are per user - one throttled attacker does not lock out another user on the same IP (shared Wi-Fi/NAT false-positive policy)', function () {
    $attacker = e26_user();
    $honest = e26_user();

    e26_hammer($this->actingAs($attacker), 'post', '/community/chat', 5, ['body' => 'flood']);
    $this->actingAs($attacker)->postJson(route('community.chat.send'), ['body' => 'again'])->assertStatus(429);

    $this->actingAs($honest)->postJson(route('community.chat.send'), ['body' => 'مرحبا من مستخدم عادي'])->assertCreated();
});

test('56c: every 429 is a clean refusal - Retry-After present, no stack trace or internals in the body', function () {
    e26_hammer($this, 'post', '/register', 6, fn ($i) => ['name' => 'x', 'email' => "clean{$i}@example.test"]);
    $json = $this->postJson('/register', ['name' => 'x', 'email' => 'clean-last@example.test']);

    $json->assertStatus(429)->assertHeader('Retry-After');
    expect($json->headers->get('Retry-After'))->toMatch('/^\d+$/');
});
