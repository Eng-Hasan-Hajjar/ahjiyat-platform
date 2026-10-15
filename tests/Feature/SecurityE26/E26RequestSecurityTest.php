<?php

require_once __DIR__.'/_support.php';

use App\Http\Middleware\TrustApplicationHosts;
use App\Models\SponsorCampaign;
use App\Models\SponsorCreative;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(fn () => $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class));
afterEach(fn () => Request::setTrustedHosts([]));

test('35: with CSRF enforcement active, a state-changing POST without a token is refused (419) and changes nothing', function () {
    $user = e26_user();
    $this->actingAs($user);
    $original = $this->app['env'];
    $this->app['env'] = 'local';        // VerifyCsrfToken يتخطى الفحص فقط حين تكون البيئة testing

    try {
        $this->post('/logout')->assertStatus(419);
        $this->assertAuthenticatedAs($user);
        $this->patch('/profile', ['name' => 'Hacked', 'email' => $user->email])->assertStatus(419);
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertStatus(419);
    } finally {
        $this->app['env'] = $original;
    }

    expect($user->fresh()->name)->not->toBe('Hacked');
});

test('35b: every state-changing web route runs inside the web middleware group (CSRF + session) and no route is excluded from CSRF', function () {
    $offenders = [];

    foreach (Route::getRoutes() as $route) {
        $mutates = array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) !== [];
        $middleware = $route->gatherMiddleware();

        // storage/{path}: مسار القرص المحلي من الإطار (PUT للروابط الموقّعة فقط، خارج مجموعة web بتصميمه).
        $framework = $route->uri() === 'storage/{path}';

        if ($mutates && ! $framework && str_starts_with($route->uri(), 'api/') === false && ! in_array('web', $middleware, true) && ! str_contains($route->uri(), 'livewire') && ! str_starts_with($route->uri(), 'admin')) {
            $offenders[] = implode('|', $route->methods()).' '.$route->uri();
        }
    }

    expect($offenders)->toBe([]);

    // لا استثناءات CSRF مضافة بالتطبيق: لا ملف VerifyCsrfToken مخصّص ولا validateCsrfTokens(except:) في bootstrap/app.php.
    expect(file_exists(base_path('app/Http/Middleware/VerifyCsrfToken.php')))->toBeFalse();
    expect(e26_php_code(base_path('bootstrap/app.php')))->not->toContain('validateCsrfTokens');
});

test('36: no GET/HEAD route is a state-changing action (verbs live on POST/PATCH/PUT/DELETE); the only GET exceptions are the signed verification link and the ad-click redirect', function () {
    $mutating = '/^(store|update|destroy|delete|accept|decline|cancel|join|leave|remove|transfer|purchase|equip|unequip|send|read|readAll|restore|hide|report|start|submit|reveal|attempt|hint|complete|reflect|register|logout|resend|requestJoin|requestToJoin|cancelRequest|invite|deactivate|changeRole|updateSettings|updateVisibility|updatePreferences|roster|block|unblock|removeFriend|open)$/i';
    $allowed = ['App\Http\Controllers\Auth\EmailVerificationController@verify', 'App\Http\Controllers\AdClickController@__invoke'];
    $offenders = [];

    foreach (Route::getRoutes() as $route) {
        if (! in_array('GET', $route->methods(), true)) {
            continue;
        }

        $action = $route->getActionName();
        if (! str_starts_with($action, 'App\\Http\\Controllers\\') || in_array($action, $allowed, true)) {
            continue;
        }

        $method = str_contains($action, '@') ? substr($action, strrpos($action, '@') + 1) : '__invoke';
        if (preg_match($mutating, $method)) {
            $offenders[] = $route->uri().' => '.$action;
        }
    }

    expect($offenders)->toBe([]);
});

test('36b: GET requests to every readable page leave the database untouched (no hidden writes besides tracking)', function () {
    $user = e26_user();
    $before = [\App\Models\Wallet::sum('pending_balance'), \App\Models\Wallet::sum('available_balance'), \App\Models\PuzzleAttempt::count(), \App\Models\RedemptionRequest::count(), \App\Models\Friendship::count()];

    foreach (['/', '/puzzles', '/leaderboard', '/store', '/challenges', '/competitions', '/teams', '/campaigns', '/seasons', '/wallet', '/redemption', '/inventory', '/friends', '/messages', '/notifications'] as $path) {
        $this->actingAs($user)->get($path);
    }

    expect([\App\Models\Wallet::sum('pending_balance'), \App\Models\Wallet::sum('available_balance'), \App\Models\PuzzleAttempt::count(), \App\Models\RedemptionRequest::count(), \App\Models\Friendship::count()])->toBe($before);
});

test('37: no request parameter or header steers a redirect off-site (login intended, ad click, post-login)', function () {
    $user = e26_user();

    $this->get('/login?redirect=https://evil.test&next=https://evil.test&intended=https://evil.test&return=//evil.test');
    $this->post('/login', ['email' => $user->email, 'password' => 'password', 'redirect' => 'https://evil.test', 'next' => '//evil.test', 'intended' => 'https://evil.test'])
        ->assertRedirect(route('home'));
    $this->post('/logout')->assertRedirect('/');

    // الوجهة المقصودة تُخزَّن من طلب GET محمي أصلًا لنفس المضيف فقط
    $this->get('/wallet')->assertRedirect(route('login'));
    expect(session('url.intended'))->toStartWith('http://localhost');

    $creative = SponsorCreative::factory()->create(['sponsor_campaign_id' => SponsorCampaign::factory()->create()->id, 'destination_url' => 'https://example.test/ok']);
    $this->get(route('ads.click', $creative).'?to=https://evil.test')->assertRedirect('https://example.test/ok');
});

test('38: security headers are present on public, guest-form and admin pages (including the CSP baseline)', function () {
    foreach (['/', '/login', '/puzzles', '/admin/login'] as $path) {
        $response = $this->get($path);
        $response->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')->assertHeader('Permissions-Policy');
        expect($response->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'self'")->toContain("base-uri 'self'")->toContain("object-src 'none'")
            ->not->toContain('script-src');       // CSP كامل مؤجَّل عمدًا (Alpine/Livewire تحتاج inline/eval)
    }

    config(['security.headers.csp_baseline' => false]);
    expect($this->get('/')->headers->has('Content-Security-Policy'))->toBeFalse();
});

test('39: HSTS is sent only for HTTPS in production - never on HTTP, never outside production', function () {
    $this->get('https://localhost/')->assertHeaderMissing('Strict-Transport-Security');       // https لكن ليس إنتاجًا
    e25_as_production();
    $this->get('http://localhost/')->assertHeaderMissing('Strict-Transport-Security');        // إنتاج لكن http
    expect($this->get('https://localhost/')->headers->get('Strict-Transport-Security'))->toStartWith('max-age=');
});

test('40: credential, account and money pages are never stored by caches; public pages keep the default and authenticated pages stay private', function () {
    $user = e26_user();

    foreach (['/login', '/register', '/forgot-password'] as $path) {
        expect($this->get($path)->headers->get('Cache-Control'))->toContain('no-store');
    }
    foreach (['/profile', '/wallet', '/redemption', '/confirm-password'] as $path) {
        expect($this->actingAs($user)->get($path)->headers->get('Cache-Control'))->toContain('no-store');
    }
    expect($this->get('/')->headers->get('Cache-Control'))->not->toContain('no-store')->and($this->actingAs($user)->get('/puzzles')->headers->get('Cache-Control'))->toContain('private');
});

test('E26-HOST: password-reset poisoning is blocked - a forged Host header is rejected by the trusted-host guard outside local/testing, and health probes stay reachable', function () {
    config(['app.url' => 'https://ahjiyat.example', 'security.hosts.extra' => ['www.ahjiyat.example']]);
    $this->app['env'] = 'production';
    $middleware = new TrustApplicationHosts($this->app);
    $pass = fn ($request) => response('ok');

    expect($middleware->hosts())->toBe(['^'.preg_quote('ahjiyat.example').'$', '^'.preg_quote('www.ahjiyat.example').'$']);

    $good = Request::create('https://ahjiyat.example/forgot-password', 'POST');
    expect($middleware->handle($good, $pass)->getContent())->toBe('ok');

    $www = Request::create('https://www.ahjiyat.example/forgot-password', 'POST');
    expect($middleware->handle($www, $pass)->getContent())->toBe('ok');

    $evil = Request::create('https://evil.test/forgot-password', 'POST');
    $middleware->handle($evil, $pass);       // يضبط المضيفات الموثوقة ثم يُمرِّر؛ قراءة المضيف تُفشل الطلب
    expect(fn () => $evil->getHost())->toThrow(\Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException::class);

    $probe = Request::create('http://10.0.0.7/ready', 'GET');
    expect($middleware->handle($probe, $pass)->getContent())->toBe('ok');

    config(['security.hosts.enforce' => false]);
    Request::setTrustedHosts([]);
    $off = Request::create('https://evil.test/x', 'GET');
    $middleware->handle($off, $pass);
    expect($off->getHost())->toBe('evil.test');                  // مفتاح الإيقاف الاضطراري
});

test('E26-HOST-2: the middleware is a no-op in local and testing (XAMPP/127.0.0.1 keep working)', function () {
    config(['app.url' => 'https://ahjiyat.example']);
    $this->get('http://127.0.0.1/')->assertOk();
    $this->get('http://localhost:8000/')->assertOk();
});
