<?php

require_once __DIR__.'/_support.php';

use App\Services\Operations\ProductionChecker;
use App\Support\SecretRedactor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * E25 (1-5) - production:check: ينجح بإعداد سليم، يفشل عند غياب APP_KEY، لا يطبع قيمًا سرية، يرفع APP_DEBUG، ويحذّر/يفشل عند URL/كوكي غير آمنَين.
 */
test('E25-1: production:check succeeds (exit 0) with a safe configuration', function () {
    [$code, $data] = e25_check(['app.debug' => false, 'app.url' => 'https://ahjiyat.example.com']);

    expect($code)->toBe(0)
        ->and($data['ready'])->toBeTrue()
        ->and($data['summary']['fail'])->toBe(0)
        ->and(e25_statuses($data, 'APP_KEY'))->toBe(['PASS'])
        ->and(e25_statuses($data, 'APP_DEBUG'))->toBe(['PASS']);
});

test('E25-1: the human-readable output groups checks, uses PASS/WARN/FAIL and ends with a summary line', function () {
    config(['app.debug' => false, 'app.url' => 'https://ahjiyat.example.com']);

    $this->artisan('production:check')
        ->expectsOutputToContain('PASS')
        ->expectsOutputToContain('النتيجة:')
        ->assertExitCode(0);
});

test('E25-2: a missing APP_KEY fails with a blocking error', function () {
    [$code, $data] = e25_check(['app.key' => '', 'app.debug' => false]);

    expect($code)->toBe(1)
        ->and($data['ready'])->toBeFalse()
        ->and(e25_statuses($data, 'APP_KEY'))->toBe(['FAIL']);
});

test('E25-2: an APP_KEY with the wrong length for the cipher fails', function () {
    [$code, $data] = e25_check(['app.key' => 'base64:'.base64_encode('too-short'), 'app.debug' => false]);

    expect($code)->toBe(1)->and(e25_statuses($data, 'APP_KEY'))->toBe(['FAIL']);
});

test('E25-2: an unreachable database fails without repeating the failure for the migrations step', function () {
    $original = config('database.default');
    config(['database.connections.e25_broken' => ['driver' => 'sqlite', 'database' => '/nonexistent/dir/none.sqlite', 'prefix' => '', 'foreign_key_constraints' => true]]);
    config(['database.default' => 'e25_broken', 'app.debug' => false]);

    try {
        [$code, $data] = e25_check();
    } finally {
        config(['database.default' => $original]); // كي لا ينهار تفكيك RefreshDatabase على اتصال معطوب
    }

    expect($code)->toBe(1)
        ->and(e25_statuses($data, 'DB_CONNECTION'))->toContain('FAIL')
        ->and(e25_statuses($data, 'migrations'))->not->toContain('FAIL');
});

test('E25-3: no secret value is ever printed (text or JSON output)', function () {
    $secrets = [
        'dbpw' => 'DbPassw0rd-VALUE-a1b2c3',
        'mail' => 'MailPassw0rd-VALUE-d4e5f6',
        'reverb' => 'ReverbSecret-VALUE-g7h8i9j0k1l2m3',
        'redis' => 'RedisPassw0rd-VALUE-n4o5p6',
        'token' => 'ApiToken-VALUE-q7r8s9',
    ];

    config([
        'database.connections.mysql.password' => $secrets['dbpw'],
        'mail.mailers.smtp.password' => $secrets['mail'],
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.app_id' => '12345',
        'broadcasting.connections.reverb.key' => 'public-key',
        'broadcasting.connections.reverb.secret' => $secrets['reverb'],
        'database.redis.default.password' => $secrets['redis'],
        'services.thirdparty.api_token' => $secrets['token'],
        'app.debug' => false,
    ]);
    $appKey = (string) config('app.key');

    [, , $json] = e25_check();
    Artisan::call('production:check');
    $text = Artisan::output();

    foreach ([$json, $text] as $output) {
        expect($output)->not->toContain($appKey)
            ->and($output)->not->toContain(substr($appKey, 7)); // الجزء base64 من المفتاح

        foreach ($secrets as $secret) {
            expect($output)->not->toContain($secret);
        }
    }
});

test('E25-3: the SecretRedactor scrubs known secret values from arbitrary text as a second safety layer', function () {
    config(['database.connections.mysql.password' => 'Sup3rSecretValue-9981', 'services.thirdparty.webhook_url' => 'https://hooks.example/abc-very-secret-123456']);

    $secrets = SecretRedactor::secrets();
    $clean = SecretRedactor::scrub('connect failed using Sup3rSecretValue-9981 at https://hooks.example/abc-very-secret-123456 for '.config('app.key'), $secrets);

    expect($clean)->not->toContain('Sup3rSecretValue-9981')
        ->and($clean)->not->toContain('abc-very-secret-123456')
        ->and($clean)->not->toContain((string) config('app.key'))
        ->and($clean)->toContain('[redacted]');
});

test('E25-3: a step that throws is reported generically without leaking the exception message', function () {
    $secretMessage = 'boom-LEAKY-exception-text-5551';
    Cache::shouldReceive('store')->andThrow(new RuntimeException($secretMessage));

    [, $data, $raw] = e25_check(['app.debug' => false]);

    expect($raw)->not->toContain($secretMessage);
});

test('E25-4: APP_DEBUG=true is a blocking failure (production)', function () {
    e25_as_production();
    [$code, $data] = e25_check(['app.debug' => true, 'app.url' => 'https://ahjiyat.example.com']);

    expect($code)->toBe(1)
        ->and($data['production'])->toBeTrue()
        ->and(e25_statuses($data, 'APP_DEBUG'))->toBe(['FAIL']);
});

test('E25-4: APP_DEBUG=true blocks even outside production', function () {
    [$code, $data] = e25_check(['app.debug' => true]);

    expect($code)->toBe(1)->and(e25_statuses($data, 'APP_DEBUG'))->toBe(['FAIL']);
});

test('E25-5: an insecure APP_URL and cookie are warnings outside production but failures in production', function () {
    config(['app.debug' => false, 'app.url' => 'http://ahjiyat.example.com', 'session.secure' => null]);

    [$code, $data] = e25_run('production:check');
    expect(e25_statuses($data, 'APP_URL'))->toBe(['WARN'])
        ->and(e25_statuses($data, 'SESSION_SECURE_COOKIE'))->toBe(['INFO'])
        ->and($code)->toBe(0);

    e25_as_production();
    [$code, $data] = e25_run('production:check');
    expect(e25_statuses($data, 'APP_URL'))->toBe(['FAIL'])
        ->and(e25_statuses($data, 'SESSION_SECURE_COOKIE'))->toBe(['FAIL'])
        ->and($code)->toBe(1);
});

test('E25-5: a localhost APP_URL is flagged and a secure cookie passes', function () {
    e25_as_production();
    [, $data] = e25_check(['app.debug' => false, 'app.url' => 'https://localhost', 'session.secure' => true]);

    expect(e25_statuses($data, 'APP_URL'))->toContain('FAIL')
        ->and(e25_statuses($data, 'SESSION_SECURE_COOKIE'))->toBe(['PASS']);
});

test('E25-5: sqlite, the file session driver and permissive trusted-proxies get the right severity in production', function () {
    e25_as_production();
    config(['app.debug' => false, 'app.url' => 'https://ahjiyat.example.com', 'trustedproxy.proxies' => '*']);

    [, $data] = e25_run('production:check');

    expect(e25_statuses($data, 'DB_CONNECTION'))->toContain('FAIL')   // sqlite لا يصلح للإنتاج
        ->and(e25_statuses($data, 'TRUSTED_PROXIES'))->toBe(['WARN']);
});

test('E25: --json output is machine readable and every check carries only status, group, setting and message', function () {
    [, $data] = e25_check(['app.debug' => false]);

    expect($data)->toHaveKeys(['production', 'ready', 'summary', 'checks'])
        ->and($data['summary'])->toHaveKeys(['pass', 'warn', 'fail', 'info']);

    foreach ($data['checks'] as $check) {
        expect(array_keys($check))->toBe(['status', 'group', 'setting', 'message'])
            ->and($check['status'])->toBeIn(['PASS', 'WARN', 'FAIL', 'INFO']);
    }
});

test('E25: production:check is read-only (no write queries, no cache mutation)', function () {
    config(['app.debug' => false]);
    $store = Cache::getStore();
    $property = new ReflectionProperty($store, 'storage');
    $before = $property->getValue($store);
    $writes = [];

    DB::listen(function ($query) use (&$writes) {
        if (preg_match('/^\s*(insert|update|delete|create|alter|drop|truncate|replace)\b/i', $query->sql) === 1) {
            $writes[] = $query->sql;
        }
    });

    Artisan::call('production:check');

    expect($writes)->toBe([])
        ->and($property->getValue($store))->toBe($before);
});

test('E25: ProductionChecker source contains no mutating calls (static guard)', function () {
    $source = e25_repo_file('app/Services/Operations/ProductionChecker.php');

    foreach (['Cache::put', 'Cache::forever', 'Cache::forget', 'Cache::flush', 'Cache::add', 'file_put_contents', 'touch(', 'unlink(', 'Artisan::call', 'DB::statement', 'DB::insert', 'DB::update', 'DB::delete', '->insert(', '->update(', '->delete(', 'Storage::put', 'mkdir('] as $needle) {
        expect(str_contains($source, $needle))->toBeFalse("ProductionChecker must stay read-only but contains {$needle}");
    }

    expect(app(ProductionChecker::class))->toBeInstanceOf(ProductionChecker::class);
});
