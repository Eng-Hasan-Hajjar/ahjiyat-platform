<?php

require_once __DIR__.'/_support.php';

use App\Jobs\Concerns\BoundedRetries;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * E25 (20-22) - انحدار الأمان (رؤوس، HSTS، CORS، كوكيز، وكلاء موثوقون)، إعدادات الطابور، وإعدادات Reverb، + حراس البذور بالإنتاج.
 */
afterEach(function () {
    $this->app['env'] = 'testing';
    DB::prohibitDestructiveCommands(false);
});

// ───────────────────────────── 20) رؤوس الأمان وHSTS وCORS والكوكيز ─────────────────────────────

test('E25-20: security headers are present on public, authenticated-area and admin responses', function () {
    foreach (['/', '/login', '/puzzles', '/admin/login'] as $path) {
        $this->get($path)
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Permissions-Policy');
    }
});

test('E25-20: HSTS is sent only in production over real HTTPS, with a one-year max-age and no includeSubDomains/preload', function () {
    // لا HSTS خارج الإنتاج حتى على HTTPS
    $this->get('https://ahjiyat.example.com/')->assertHeaderMissing('Strict-Transport-Security');

    $this->app['env'] = 'production';

    // لا HSTS على HTTP حتى بالإنتاج
    $this->get('http://ahjiyat.example.com/')->assertHeaderMissing('Strict-Transport-Security');

    // HSTS على HTTPS بالإنتاج فقط
    $header = $this->get('https://ahjiyat.example.com/')->headers->get('Strict-Transport-Security');

    expect($header)->toBe('max-age=31536000')
        ->and($header)->not->toContain('includeSubDomains')
        ->and($header)->not->toContain('preload');
});

test('E25-20: no CORS headers are emitted for a foreign Origin, including on a preflight request', function () {
    expect(file_exists(config_path('cors.php')))->toBeFalse('إضافة config/cors.php قرار واعٍ: حدّث docs/security-hardening.md واختبار CORS معًا');

    $get = $this->withHeaders(['Origin' => 'https://evil.example'])->get('/puzzles');
    $preflight = $this->call('OPTIONS', '/puzzles', [], [], [], ['HTTP_ORIGIN' => 'https://evil.example', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET']);

    foreach ([$get, $preflight] as $response) {
        expect($response->headers->has('Access-Control-Allow-Origin'))->toBeFalse()
            ->and($response->headers->has('Access-Control-Allow-Credentials'))->toBeFalse();
    }
});

test('E25-20: the session cookie is HttpOnly and SameSite=Lax, and Secure when configured', function () {
    config(['session.secure' => true]);

    $cookie = collect($this->get('/login')->headers->getCookies())->first(fn ($c) => $c->getName() === config('session.cookie'));

    expect($cookie)->not->toBeNull()
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->isSecure())->toBeTrue()
        ->and(strtolower((string) $cookie->getSameSite()))->toBe('lax')
        ->and($cookie->getDomain())->toBeNull();   // مرتبطة بالمضيف فقط
});

test('E25-20: cookie hardening defaults hold in config (no accidental weakening)', function () {
    expect(config('session.http_only'))->toBeTrue()
        ->and(strtolower((string) config('session.same_site')))->toBe('lax')
        ->and(config('session.domain'))->toBeNull();
});

test('E25-20: trusted proxies are audited by name: wildcard warns, a CIDR list passes, unset behind https warns', function () {
    config(['app.debug' => false, 'app.url' => 'https://ahjiyat.example.com']);

    config(['trustedproxy.proxies' => '*']);
    expect(e25_statuses(e25_run('production:check')[1], 'TRUSTED_PROXIES'))->toBe(['WARN']);

    config(['trustedproxy.proxies' => ['10.0.0.0/8', '172.16.0.0/12']]);
    [, $data, $raw] = e25_run('production:check');
    expect(e25_statuses($data, 'TRUSTED_PROXIES'))->toBe(['PASS'])
        ->and($raw)->not->toContain('10.0.0.0/8');   // العدد فقط، لا قيم الشبكة

    e25_as_production();
    config(['trustedproxy.proxies' => null]);
    expect(e25_statuses(e25_run('production:check')[1], 'TRUSTED_PROXIES'))->toBe(['WARN']);
});

test('E25-20: publicly reachable diagnostic routes fail the production contract, and the real route table has none', function () {
    [, $data] = e25_check(['app.debug' => false]);
    expect(e25_statuses($data, 'routes'))->toBe(['PASS']);

    Illuminate\Support\Facades\Route::get('/telescope/requests', fn () => 'x');   // مسار تشخيص عام مفترض
    e25_as_production();
    [$code, $data] = e25_check(['app.url' => 'https://ahjiyat.example.com']);

    expect(e25_statuses($data, 'routes'))->toBe(['FAIL'])->and($code)->toBe(1);
});

test('E25-20: .env or .git inside public/ is a blocking failure', function () {
    $marker = public_path('.env');
    file_put_contents($marker, 'X=1');

    try {
        [$code, $data] = e25_check(['app.debug' => false]);
    } finally {
        @unlink($marker);
    }

    expect($code)->toBe(1)->and(e25_statuses($data, 'public/'))->toBe(['FAIL']);
});

// ───────────────────────────── 21) الطابور ─────────────────────────────

test('E25-21: queue retry_after exceeds the job timeout on every connection, and the ops config agrees with BoundedRetries', function () {
    $jobTimeout = (new class
    {
        use BoundedRetries;
    })->timeout;

    expect((int) config('operations.queue.job_timeout_seconds'))->toBe($jobTimeout);

    foreach (['database', 'redis', 'beanstalkd'] as $connection) {
        expect((int) config("queue.connections.{$connection}.retry_after"))->toBeGreaterThan($jobTimeout, "{$connection}.retry_after must exceed the job timeout");
    }
});

test('E25-21: production:check flags retry_after <= job timeout (warning locally, failure in production) and warns on the sync driver', function () {
    config(['app.debug' => false, 'app.url' => 'https://ahjiyat.example.com', 'queue.default' => 'database']);

    [, $data] = e25_run('production:check');
    expect(e25_statuses($data, 'DB_QUEUE_RETRY_AFTER'))->toBe(['PASS']);

    config(['queue.connections.database.retry_after' => 60]);
    [$code, $data] = e25_run('production:check');
    expect(e25_statuses($data, 'DB_QUEUE_RETRY_AFTER'))->toBe(['WARN'])->and($code)->toBe(0);

    e25_as_production();
    [$code, $data] = e25_run('production:check');
    expect(e25_statuses($data, 'DB_QUEUE_RETRY_AFTER'))->toBe(['FAIL'])->and($code)->toBe(1);

    config(['queue.connections.database.retry_after' => 90, 'queue.default' => 'sync']);
    [, $data] = e25_run('production:check');
    expect(e25_statuses($data, 'QUEUE_CONNECTION'))->toContain('WARN');
});

test('E25-21: the operations runbook documents the worker timeout that matches the code', function () {
    $runbook = e25_repo_file('docs/operations-runbook.md');

    expect($runbook)->toContain('--timeout=80')->and($runbook)->toContain('retry_after');
});

// ───────────────────────────── 22) Reverb ─────────────────────────────

test('E25-22: Reverb allowed origins default to "*" (E21 behaviour) and follow REVERB_ALLOWED_ORIGINS when set', function () {
    expect(config('reverb.apps.apps.0.allowed_origins'))->toBe(['*']);

    putenv('REVERB_ALLOWED_ORIGINS= ahjiyat.example.com , www.ahjiyat.example.com ,');
    try {
        $config = require config_path('reverb.php');
    } finally {
        putenv('REVERB_ALLOWED_ORIGINS');
    }

    expect($config['apps']['apps'][0]['allowed_origins'])->toBe(['ahjiyat.example.com', 'www.ahjiyat.example.com']);

    putenv('REVERB_ALLOWED_ORIGINS= , ');
    try {
        $blank = require config_path('reverb.php');
    } finally {
        putenv('REVERB_ALLOWED_ORIGINS');
    }

    expect($blank['apps']['apps'][0]['allowed_origins'])->toBe(['*']);   // قيمة فارغة لا تغلق الاتصال بالكامل
});

test('E25-22: production:check audits Reverb: weak secret, non-https scheme, local host and wildcard origins', function () {
    config([
        'app.debug' => false,
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.app_id' => '1001',
        'broadcasting.connections.reverb.key' => 'public-key-value',
        'broadcasting.connections.reverb.secret' => 'secret',
        'reverb.apps.apps.0.options.scheme' => 'http',
        'reverb.servers.reverb.hostname' => 'localhost',
        'reverb.apps.apps.0.allowed_origins' => ['*'],
    ]);
    e25_as_production();

    [$code, $data, $raw] = e25_run('production:check');

    expect(e25_statuses($data, 'REVERB_APP_SECRET'))->toBe(['FAIL'])
        ->and(e25_statuses($data, 'REVERB_SCHEME'))->toBe(['FAIL'])
        ->and(e25_statuses($data, 'REVERB_HOST'))->toBe(['FAIL'])
        ->and(e25_statuses($data, 'REVERB_ALLOWED_ORIGINS'))->toBe(['WARN'])
        ->and($code)->toBe(1);

    config([
        'broadcasting.connections.reverb.secret' => 'a-long-random-reverb-secret-0123456789abcdef',
        'reverb.apps.apps.0.options.scheme' => 'https',
        'reverb.servers.reverb.hostname' => 'ws.ahjiyat.example.com',
        'reverb.apps.apps.0.allowed_origins' => ['ahjiyat.example.com'],
    ]);

    [, $data, $raw] = e25_run('production:check');

    expect(e25_statuses($data, 'REVERB_APP_SECRET'))->not->toContain('FAIL')
        ->and(e25_statuses($data, 'REVERB_SCHEME'))->toBe(['PASS'])
        ->and(e25_statuses($data, 'REVERB_HOST'))->not->toContain('FAIL')
        ->and(e25_statuses($data, 'REVERB_ALLOWED_ORIGINS'))->toBe(['PASS'])
        ->and($raw)->not->toContain('a-long-random-reverb-secret-0123456789abcdef');
});

test('E25-22: with a non-reverb broadcaster the audit only warns (chat/notifications stay functional without realtime)', function () {
    config(['app.debug' => false, 'broadcasting.default' => 'log']);

    [$code, $data] = e25_run('production:check');

    expect(e25_statuses($data, 'BROADCAST_CONNECTION'))->toBe(['WARN'])->and($code)->toBe(0);
});

// ───────────────────────────── حراس البذور والحسابات بالإنتاج ─────────────────────────────

test('E25: the full DatabaseSeeder and UserSeeder refuse to run in production', function () {
    $this->app['env'] = 'production';

    expect(fn () => app(DatabaseSeeder::class)->run())->toThrow(RuntimeException::class, 'ممنوع بالإنتاج')
        ->and(fn () => app(UserSeeder::class)->run())->toThrow(RuntimeException::class, 'ممنوع بالإنتاج');
});

test('E25: AdminUserSeeder in production needs a valid email and a strong, non-default password from the environment', function (?string $email, ?string $password) {
    $this->app['env'] = 'production';
    config(['operations.admin_bootstrap.email' => $email, 'operations.admin_bootstrap.password' => $password]);

    expect(fn () => app(AdminUserSeeder::class)->run())->toThrow(RuntimeException::class);
    expect(User::where('email', AdminUserSeeder::DEFAULT_EMAIL)->exists())->toBeFalse();
})->with([
    'nothing set' => [null, null],
    'no password' => ['owner@ahjiyat.example.com', null],
    'short password' => ['owner@ahjiyat.example.com', 'short-pass'],
    'the known default password' => ['owner@ahjiyat.example.com', 'change-me-now'],
    'invalid email' => ['not-an-email', 'a-very-long-password-123'],
]);

test('E25: AdminUserSeeder in production creates the operator-chosen admin, never the default account, and never prints the password', function () {
    $this->app['env'] = 'production';
    $password = 'Str0ng-Operator-Pass-2026';
    config(['operations.admin_bootstrap.email' => 'owner@ahjiyat.example.com', 'operations.admin_bootstrap.password' => $password]);

    Artisan::call('db:seed', ['--class' => AdminUserSeeder::class, '--force' => true]);
    $output = Artisan::output();

    $admin = User::where('email', 'owner@ahjiyat.example.com')->first();

    expect($admin)->not->toBeNull()
        ->and(Hash::check($password, $admin->password))->toBeTrue()
        ->and(Hash::check(AdminUserSeeder::DEFAULT_PASSWORD, $admin->password))->toBeFalse()
        ->and(User::where('email', AdminUserSeeder::DEFAULT_EMAIL)->exists())->toBeFalse()
        ->and($output)->not->toContain($password)
        ->and($output)->not->toContain(AdminUserSeeder::DEFAULT_PASSWORD);
});

test('E25: outside production AdminUserSeeder keeps its documented local-development behaviour', function () {
    app(AdminUserSeeder::class)->run();

    $admin = User::where('email', AdminUserSeeder::DEFAULT_EMAIL)->first();

    expect($admin)->not->toBeNull()->and(Hash::check(AdminUserSeeder::DEFAULT_PASSWORD, $admin->password))->toBeTrue();
});

test('E25: production:check flags leftover demo accounts and the default admin password, and passes when they are absent', function () {
    config(['app.debug' => false, 'app.url' => 'https://ahjiyat.example.com']);
    e25_as_production();

    [, $data] = e25_run('production:check');
    expect(e25_statuses($data, 'users'))->toBe(['PASS']);

    User::factory()->create(['email' => 'demo.player@ahjiyat.test']);
    [$code, $data] = e25_run('production:check');
    expect(e25_statuses($data, 'users'))->toBe(['FAIL'])->and($code)->toBe(1);

    User::where('email', 'like', '%@ahjiyat.test')->delete();
    User::factory()->create(['email' => AdminUserSeeder::DEFAULT_EMAIL, 'password' => Hash::make(AdminUserSeeder::DEFAULT_PASSWORD)]);
    [$code, $data] = e25_run('production:check');
    expect(e25_statuses($data, 'ADMIN_BOOTSTRAP_PASSWORD'))->toBe(['FAIL'])->and($code)->toBe(1);

    User::where('email', AdminUserSeeder::DEFAULT_EMAIL)->update(['password' => Hash::make('a-completely-different-passphrase')]);
    [, $data] = e25_run('production:check');
    expect(e25_statuses($data, 'ADMIN_BOOTSTRAP_PASSWORD'))->toBe(['PASS']);
});
