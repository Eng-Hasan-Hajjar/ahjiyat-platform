<?php

require_once __DIR__.'/_support.php';

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * E25 (16) - release:smoke: طلبات GET فقط على مسارات عامة، بمهل، يحدد PASS/WARN/FAIL، ولا يكشف نصوص الاستثناءات أو بيانات الاعتماد.
 */
function e25_fake_site(array $overrides = []): void
{
    // الأنماط تُطابَق بالترتيب والأول يفوز: التجاوزات أولًا ثم الافتراضيات.
    Http::fake($overrides + [
        '*/ready' => Http::response(['status' => 'ok', 'release' => 'abc1234', 'checks' => []], 200),
        '*' => Http::response('<html>ok</html>', 200),
    ]);
}

test('E25-16: smoke sends only GET requests, one per configured public route, and passes on a healthy site', function () {
    e25_fake_site();

    [$code, $data] = e25_run('release:smoke', ['--url' => 'https://smoke.example.com']);

    expect($code)->toBe(0)->and($data['ready'])->toBeTrue();

    Http::assertSentCount(7); // 8 مسارات مهيأة - /players/{profile} يُتخطى بلا --profile
    Http::assertSent(fn (Request $request) => $request->method() === 'GET' && str_starts_with($request->url(), 'https://smoke.example.com'));
    Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');
    Http::assertNotSent(fn (Request $request) => $request->hasHeader('Authorization') || $request->hasHeader('Cookie'));
    Http::assertNotSent(fn (Request $request) => $request->body() !== '');
});

test('E25-16: every route result shows the path, the status and the latency', function () {
    e25_fake_site();

    [, $data] = e25_run('release:smoke', ['--url' => 'https://smoke.example.com']);

    foreach ($data['checks'] as $check) {
        if ($check['status'] === 'PASS') {
            expect($check['message'])->toMatch('/^GET \S+ → 200 \(\d+ms\)$/');
        }
    }

    expect(collect($data['checks'])->pluck('setting')->all())->toContain('/up', '/ready', '/', '/login', '/puzzles', '/competitions', '/store');
});

test('E25-16: a non-200 route fails the smoke run', function () {
    e25_fake_site(['*/puzzles' => Http::response('boom', 500)]);

    [$code, $data] = e25_run('release:smoke', ['--url' => 'https://smoke.example.com']);

    expect($code)->toBe(1)
        ->and(collect($data['checks'])->firstWhere('setting', '/puzzles')['status'])->toBe('FAIL')
        ->and(collect($data['checks'])->firstWhere('setting', '/puzzles')['message'])->toContain('500');
});

test('E25-16: a redirect is not a pass and is not followed', function () {
    e25_fake_site(['*/store' => Http::response('', 302, ['Location' => 'https://elsewhere.example.com/'])]);

    [$code, $data] = e25_run('release:smoke', ['--url' => 'https://smoke.example.com']);

    expect($code)->toBe(1)->and(collect($data['checks'])->firstWhere('setting', '/store')['status'])->toBe('FAIL');
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'elsewhere.example.com'));
});

test('E25-16: a connection failure is a FAIL that never echoes the exception text', function () {
    Http::fake(['*' => fn () => throw new ConnectionException('cURL error 7: Failed to connect to internal-db-host.corp:3306 as secret-user')]);

    [$code, $data, $raw] = e25_run('release:smoke', ['--url' => 'https://smoke.example.com']);

    expect($code)->toBe(1)
        ->and($data['summary']['fail'])->toBeGreaterThan(0)
        ->and($raw)->not->toContain('internal-db-host')
        ->and($raw)->not->toContain('secret-user');
});

test('E25-16: /ready must confirm readiness in its body, not only answer 200', function () {
    e25_fake_site(['*/ready' => Http::response(['status' => 'unavailable'], 200)]);

    [$code, $data] = e25_run('release:smoke', ['--url' => 'https://smoke.example.com']);

    expect($code)->toBe(1)->and(collect($data['checks'])->firstWhere('setting', '/ready')['status'])->toBe('FAIL');
});

test('E25-16: --expect-release detects a deploy that did not take effect', function () {
    e25_fake_site(); // /ready يعرض abc1234

    [$ok] = e25_run('release:smoke', ['--url' => 'https://smoke.example.com', '--expect-release' => 'abc1234']);
    [$stale, $data] = e25_run('release:smoke', ['--url' => 'https://smoke.example.com', '--expect-release' => 'def5678']);

    expect($ok)->toBe(0)
        ->and($stale)->toBe(1)
        ->and(collect($data['checks'])->firstWhere('setting', '/ready')['status'])->toBe('FAIL');
});

test('E25-16: a URL that embeds credentials is rejected before any request is made', function () {
    Http::fake();

    [$code, $data, $raw] = e25_run('release:smoke', ['--url' => 'https://admin:hunter2@smoke.example.com']);

    expect($code)->toBe(1)
        ->and($data['summary']['fail'])->toBe(1)
        ->and($raw)->not->toContain('hunter2');
    Http::assertNothingSent();
});

test('E25-16: only http(s) schemes are accepted', function (string $url) {
    Http::fake();

    [$code] = e25_run('release:smoke', ['--url' => $url]);

    expect($code)->toBe(1);
    Http::assertNothingSent();
})->with(['file:///etc/passwd', 'ftp://example.com', 'javascript:alert(1)', 'not a url']);

test('E25-16: --profile adds the public profile route, and unsafe profile ids are skipped', function () {
    e25_fake_site();

    [, $data] = e25_run('release:smoke', ['--url' => 'https://smoke.example.com', '--profile' => 'player_123']);
    expect(collect($data['checks'])->pluck('setting')->all())->toContain('/players/player_123');
    Http::assertSent(fn (Request $request) => $request->url() === 'https://smoke.example.com/players/player_123');

    Http::fake();
    e25_fake_site();
    [, $data] = e25_run('release:smoke', ['--url' => 'https://smoke.example.com', '--profile' => '../../etc/passwd']);
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'passwd'));
});

test('E25-16: a slow route is a warning, not a failure', function () {
    config(['operations.smoke.slow_ms' => 1]);
    e25_fake_site(['*/login' => function () {
        usleep(20_000);

        return Http::response('ok', 200);
    }]);

    [$code, $data] = e25_run('release:smoke', ['--url' => 'https://smoke.example.com']);

    expect(collect($data['checks'])->firstWhere('setting', '/login')['status'])->toBe('WARN')
        ->and($code)->toBe(0);
});

test('E25-16: the checker is read-only by construction: GET only, with timeouts, no session, no body (static guard)', function () {
    $source = e25_repo_file('app/Services/Operations/SmokeChecker.php');

    foreach (['->post(', '->put(', '->patch(', '->delete(', 'Http::post', 'Http::put', 'Http::delete', 'asForm', 'asJson', 'withBody', 'withCookies', 'withToken', 'withBasicAuth', 'attach('] as $needle) {
        expect(str_contains($source, $needle))->toBeFalse("SmokeChecker must be GET-only but contains {$needle}");
    }

    expect($source)->toContain('->get(')->toContain('->timeout(')->toContain('->connectTimeout(')->toContain('->withoutRedirecting()');
    expect((int) config('operations.smoke.timeout_seconds'))->toBeBetween(1, 10)
        ->and((int) config('operations.smoke.connect_timeout_seconds'))->toBeBetween(1, 10);
});

test('E25-16: every default smoke route is public (guest-accessible) and idempotent', function () {
    foreach ((array) config('operations.smoke.routes') as $path) {
        $uri = str_replace('{profile}', 'x', $path);
        $route = collect(app('router')->getRoutes()->getRoutes())->first(fn ($r) => '/'.ltrim($r->uri(), '/') === $path || '/'.ltrim($r->uri(), '/') === $uri || preg_replace('#\{[^}]+\}#', '{profile}', '/'.ltrim($r->uri(), '/')) === $path);

        expect($route)->not->toBeNull("{$path} is not a registered route")
            ->and($route->methods())->toContain('GET')
            ->and(array_intersect($route->gatherMiddleware(), ['auth', 'verified', 'auth:sanctum']))->toBe([], "{$path} must not require authentication");
    }
});
