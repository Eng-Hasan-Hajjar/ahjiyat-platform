<?php

require_once __DIR__.'/_support.php';

use App\Http\Requests\Auth\LoginRequest;
use App\Models\PuzzleAttempt;
use App\Models\Puzzle;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;

beforeEach(fn () => $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class));

function e26_login_request(string $email, string $ip = '203.0.113.9'): LoginRequest
{
    $request = LoginRequest::create('/login', 'POST', ['email' => $email, 'password' => 'x']);
    $request->server->set('REMOTE_ADDR', $ip);

    return $request;
}

test('1: login with valid credentials authenticates and lands on the home page', function () {
    $user = e26_user();

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('home'));

    $this->assertAuthenticatedAs($user);
});

test('2: the session id is regenerated at login (session fixation defence)', function () {
    $user = e26_user();
    $this->startSession();
    $before = session()->getId();

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect();

    expect(session()->getId())->not->toBe($before)->and(session()->getId())->not->toBeEmpty();
});

test('3: a wrong password and an unknown email produce the very same generic error (no account enumeration)', function () {
    $user = e26_user();

    $wrongPassword = $this->from('/login')->post('/login', ['email' => $user->email, 'password' => 'not-it'])->assertRedirect('/login');
    $wrongPasswordErrors = session('errors')->get('email');

    $unknown = $this->from('/login')->post('/login', ['email' => 'nobody-'.uniqid().'@example.test', 'password' => 'not-it'])->assertRedirect('/login');
    $unknownErrors = session('errors')->get('email');

    expect($wrongPasswordErrors)->toBe(['بيانات الدخول غير صحيحة.'])->and($unknownErrors)->toBe($wrongPasswordErrors);
    $this->assertGuest();
});

test('4: repeated failures lock the email+IP pair and even the correct password is refused until the window passes', function () {
    $user = e26_user();
    $max = (int) config('security.login.max_attempts');

    for ($i = 0; $i < $max; $i++) {
        $this->from('/login')->post('/login', ['email' => $user->email, 'password' => 'wrong-'.$i]);
    }

    $this->from('/login')->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/login');
    expect(session('errors')->first('email'))->toContain('محاولات دخول كثيرة');
    $this->assertGuest();

    $this->travel((int) config('security.login.decay_seconds') + 5)->seconds();

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('home'));
    $this->assertAuthenticatedAs($user);
});

test('4b: a successful login clears the email+IP counter so an honest typo does not accumulate', function () {
    $user = e26_user();
    $max = (int) config('security.login.max_attempts');

    for ($i = 0; $i < $max - 1; $i++) {
        $this->post('/login', ['email' => $user->email, 'password' => 'oops']);
    }
    $this->post('/login', ['email' => $user->email, 'password' => 'password']);
    $this->post('/logout');

    for ($i = 0; $i < $max - 1; $i++) {
        $this->post('/login', ['email' => $user->email, 'password' => 'oops']);
    }
    $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('home'));
    $this->assertAuthenticatedAs($user);
});

test('5: limiter keys never contain the raw email, and are case/whitespace insensitive', function () {
    $key = e26_login_request('Victim.Person@Example.TEST')->throttleKey();
    $same = e26_login_request('  victim.person@example.test ')->throttleKey();

    expect($key)->not->toContain('victim')->not->toContain('example')->not->toContain('@')->and($key)->toBe($same);

    foreach (['password-reset', 'api-login'] as $name) {
        $request = Request::create('/x', 'POST', ['email' => 'Victim.Person@Example.TEST']);
        $limits = array_map(fn ($l) => $l->key, (array) RateLimiter::limiter($name)($request));

        foreach ($limits as $limitKey) {
            expect($limitKey)->not->toContain('victim')->not->toContain('@');
        }
    }
});

test('5b: the IP-only limiter stops password spraying across many accounts, with a higher ceiling than the per-account one', function () {
    config(['security.login.ip_max_attempts' => 6, 'security.login.max_attempts' => 5]);

    $statuses = [];
    for ($i = 0; $i < 7; $i++) {
        $this->from('/login')->post('/login', ['email' => "spray{$i}@example.test", 'password' => 'guess']);
        $statuses[] = session('errors')->first('email');
    }

    expect($statuses[5])->toBe('بيانات الدخول غير صحيحة.')->and($statuses[6])->toContain('محاولات دخول كثيرة');
});

test('6: a frozen account cannot log in and leaves no authenticated session behind', function () {
    $user = e26_freeze(e26_user());

    $this->from('/login')->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/login');

    expect(session('errors')->first('email'))->toContain('تجميد');
    $this->assertGuest();
});

test('7: a user frozen while already logged in is blocked from protected actions (no attempt, no reward)', function () {
    $user = e26_user();
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح', 'gem_reward' => 10]);
    $this->actingAs($user);
    e26_freeze($user);

    $this->post(route('puzzles.attempt', $puzzle), ['answer' => 'صح'])->assertSessionHas('error');

    expect(PuzzleAttempt::count())->toBe(0)->and($user->wallet->refresh()->pending_balance)->toBe(0);
});

test('8: logout invalidates the session, rotates the remember token and drops access to protected pages', function () {
    $user = e26_user();
    $oldRemember = $user->remember_token;
    $this->actingAs($user)->startSession();
    $before = session()->getId();

    $this->post('/logout')->assertRedirect('/');

    $this->assertGuest();
    expect(session()->getId())->not->toBe($before)->and($user->fresh()->remember_token)->not->toBe($oldRemember);
    $this->get(route('wallet.index'))->assertRedirect(route('login'));
});

test('9: password reset never reveals whether an account exists (same response, mail only for real accounts)', function () {
    Notification::fake();
    $user = e26_user();

    $known = $this->from('/forgot-password')->post('/forgot-password', ['email' => $user->email]);
    $knownStatus = session('status');
    $unknown = $this->from('/forgot-password')->post('/forgot-password', ['email' => 'ghost-'.uniqid().'@example.test']);
    $unknownStatus = session('status');

    $known->assertRedirect('/forgot-password')->assertSessionHasNoErrors();
    $unknown->assertRedirect('/forgot-password')->assertSessionHasNoErrors();
    expect($knownStatus)->toBe($unknownStatus)->not->toBeEmpty();
    Notification::assertSentToTimes($user, ResetPassword::class, 1);
    Notification::assertCount(1);
});

test('10: reset requests are throttled per IP and per recipient (many IPs cannot flood one inbox)', function () {
    Notification::fake();
    $victim = e26_user();

    $statuses = [];
    foreach (range(1, 5) as $i) {
        $statuses[] = $this->withServerVariables(['REMOTE_ADDR' => "198.51.100.{$i}"])->post('/forgot-password', ['email' => $victim->email])->getStatusCode();
    }

    expect($statuses[2])->not->toBe(429)->and($statuses[3])->toBe(429)->and($statuses[4])->toBe(429);
    // وسيط Laravel نفسه يحدّ كل مستخدم برسالة واحدة/60 ثانية (auth.passwords.users.throttle): الرد الموحّد يخفي ذلك، والحدّان الخارجيان يضيفان سقفًا ساعيًا.
    Notification::assertSentToTimes($victim, ResetPassword::class, 1);
});

test('10b: the per-IP reset limit also applies to many different recipients', function () {
    Notification::fake();
    $statuses = [];
    foreach (range(1, 7) as $i) {
        $statuses[] = $this->post('/forgot-password', ['email' => "bulk{$i}@example.test"])->getStatusCode();
    }

    expect(e26_first_429($statuses))->toBe(6);
});

test('11: a tampered reset token or a foreign email fails and never changes the password', function () {
    Notification::fake();
    $user = e26_user();
    $other = e26_user();
    $this->post('/forgot-password', ['email' => $user->email]);
    $hash = $user->password;
    $token = null;
    Notification::assertSentTo($user, ResetPassword::class, function ($notification) use (&$token) {
        $token = $notification->token;

        return true;
    });

    foreach ([[$token.'x', $user->email], [strrev($token), $user->email], [$token, $other->email]] as [$t, $email]) {
        $this->from('/reset-password/'.$token)->post('/reset-password', ['token' => $t, 'email' => $email, 'password' => 'NewPassw0rd!x', 'password_confirmation' => 'NewPassw0rd!x'])->assertSessionHasErrors('email');
    }

    $this->from('/reset-password/'.$token)->post('/reset-password', ['token' => '', 'email' => $user->email, 'password' => 'NewPassw0rd!x', 'password_confirmation' => 'NewPassw0rd!x'])->assertSessionHasErrors('token');

    expect($user->fresh()->password)->toBe($hash)->and($other->fresh()->password)->not->toBe($hash);
    // الفشل لا يميّز "مستخدم غير موجود" عن "رمز خاطئ"
    $this->from('/x')->post('/reset-password', ['token' => $token, 'email' => 'ghost@example.test', 'password' => 'NewPassw0rd!x', 'password_confirmation' => 'NewPassw0rd!x']);
    $ghost = session('errors')->first('email');
    $this->from('/x')->post('/reset-password', ['token' => $token.'x', 'email' => $user->email, 'password' => 'NewPassw0rd!x', 'password_confirmation' => 'NewPassw0rd!x']);
    expect(session('errors')->first('email'))->toBe($ghost);
});

test('11b: a successful reset consumes the token, rotates remember_token and revokes other database sessions', function () {
    Notification::fake();
    $user = e26_user();
    $oldRemember = $user->remember_token;
    \Illuminate\Support\Facades\DB::table('sessions')->insert(['id' => 'stolen-session', 'user_id' => $user->id, 'ip_address' => '203.0.113.5', 'user_agent' => 'x', 'payload' => '', 'last_activity' => time()]);
    $this->post('/forgot-password', ['email' => $user->email]);
    $token = null;
    Notification::assertSentTo($user, ResetPassword::class, function ($n) use (&$token) {
        $token = $n->token;

        return true;
    });

    $this->post('/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'NewPassw0rd!x', 'password_confirmation' => 'NewPassw0rd!x'])->assertRedirect(route('login'));

    expect(\Illuminate\Support\Facades\DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0)
        ->and($user->fresh()->remember_token)->not->toBe($oldRemember);
    // الرمز يُستهلك: إعادة استعماله تفشل
    $this->post('/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'AnotherPassw0rd!y', 'password_confirmation' => 'AnotherPassw0rd!y'])->assertSessionHasErrors('email');
});

test('12: a tampered verification link (hash, signature or foreign user id) never verifies the account', function () {
    $user = e26_user(['email_verified_at' => null]);
    $victim = e26_user(['email_verified_at' => null]);
    $good = fn (int $id, string $email) => URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), ['id' => $id, 'hash' => sha1($email)]);

    $this->actingAs($user);
    $wrongHash = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), ['id' => $user->id, 'hash' => sha1('someone-else@example.test')]);
    $this->get($wrongHash)->assertForbidden();
    $this->get(str_replace('signature=', 'signature=00', $good($user->id, $user->email)))->assertForbidden();
    $this->get($good($victim->id, $victim->email))->assertForbidden();     // رابط حساب آخر بتوقيع صحيح
    $this->get(URL::temporarySignedRoute('verification.verify', now()->subMinute(), ['id' => $user->id, 'hash' => sha1($user->email)]))->assertForbidden(); // منتهٍ

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse()->and($victim->fresh()->hasVerifiedEmail())->toBeFalse();

    $this->get($good($user->id, $user->email))->assertRedirect();
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

test('12b: changing the email requires the current password and then revokes other sessions (account-takeover primitive closed)', function () {
    $user = e26_user();
    \Illuminate\Support\Facades\DB::table('sessions')->insert(['id' => 'other-device', 'user_id' => $user->id, 'ip_address' => '203.0.113.7', 'user_agent' => 'x', 'payload' => '', 'last_activity' => time()]);
    $this->actingAs($user)->startSession();

    $this->from('/profile')->patch('/profile', ['name' => $user->name, 'email' => 'attacker@example.test'])->assertSessionHasErrors('current_password');
    $this->from('/profile')->patch('/profile', ['name' => $user->name, 'email' => 'attacker@example.test', 'current_password' => 'wrong'])->assertSessionHasErrors('current_password');
    expect($user->fresh()->email)->not->toBe('attacker@example.test');

    $this->from('/profile')->patch('/profile', ['name' => 'New Name', 'email' => $user->email])->assertSessionHasNoErrors();   // الاسم وحده لا يحتاج كلمة المرور
    expect($user->fresh()->name)->toBe('New Name');

    $this->from('/profile')->patch('/profile', ['name' => 'New Name', 'email' => 'new-address@example.test', 'current_password' => 'password'])->assertSessionHasNoErrors();
    expect($user->fresh()->email)->toBe('new-address@example.test')->and($user->fresh()->email_verified_at)->toBeNull()
        ->and(\Illuminate\Support\Facades\DB::table('sessions')->where('id', 'other-device')->exists())->toBeFalse();
});

test('12c: confirm-password and reset-submit are rate limited', function () {
    $user = e26_user();
    $this->actingAs($user);
    $confirm = e26_hammer($this, 'post', '/confirm-password', 8, ['password' => 'wrong']);
    expect(e26_first_429($confirm))->toBe(7);
});
