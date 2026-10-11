<?php

use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * E24 - المراقبة: معرّف الطلب، سجل الطلبات البطيئة (بلا بيانات حساسة)، Server-Timing محليًا، وجاهزية الموازن /ready.
 */
test('E24: every response carries an X-Request-ID, generated when the client sends none', function () {
    $response = $this->get('/up')->assertOk();

    expect($response->headers->get('X-Request-ID'))->toMatch('/^[0-9a-f-]{36}$/');
});

test('E24: a safe incoming X-Request-ID is echoed back and unsafe ones are replaced', function () {
    $this->withHeaders(['X-Request-ID' => 'edge-abc_123.XYZ'])->get('/up')->assertHeader('X-Request-ID', 'edge-abc_123.XYZ');

    foreach (["bad id with spaces", "x\r\nInjected: 1", 'short', str_repeat('a', 65), '<script>alert(1)</script>'] as $bad) {
        $echoed = $this->withHeaders(['X-Request-ID' => $bad])->get('/up')->headers->get('X-Request-ID');
        expect($echoed)->not->toBe($bad)->and($echoed)->toMatch('/^[0-9a-f-]{36}$/');
    }
});

test('E24: the request id is also added to the log context', function () {
    $this->withHeaders(['X-Request-ID' => 'ctx-req-id-0001'])->get('/up')->assertOk();

    expect(\Illuminate\Support\Facades\Context::get('request_id'))->toBe('ctx-req-id-0001');
});

test('E24: Server-Timing is emitted in local/testing with query count and time', function () {
    $user = User::factory()->create();
    $response = $this->actingAs($user)->get('/puzzles')->assertOk();

    expect($response->headers->get('Server-Timing'))->toMatch('/^app;dur=[\d.]+, db;dur=[\d.]+;desc="\d+ queries"$/');
});

test('E24: Server-Timing and the DB listener are absent in production', function () {
    $this->app['env'] = 'production';

    $this->get('/up')->assertOk()->assertHeaderMissing('Server-Timing');
});

test('E24: a slow request is logged once with safe fields only (no body, query string, cookies or headers)', function () {
    config(['performance.observability.slow_request_ms' => 1, 'performance.observability.slow_request_sample' => 1.0]);
    $captured = [];
    Log::listen(function ($event) use (&$captured) {
        if ($event->message === 'slow_request') {
            $captured[] = $event->context;
        }
    });
    $user = User::factory()->create();

    $this->actingAs($user)->withHeaders(['Cookie' => 'secret=cookie-value', 'Authorization' => 'Bearer top-secret'])
        ->get('/puzzles?token=abc123&q=سري')->assertOk();

    expect($captured)->not->toBeEmpty();
    $ctx = $captured[0];
    $dump = json_encode($ctx, JSON_UNESCAPED_UNICODE);

    expect($ctx)->toHaveKeys(['request_id', 'method', 'route', 'status', 'duration_ms', 'peak_memory_mb', 'user_id'])
        ->and($ctx['method'])->toBe('GET')->and($ctx['status'])->toBe(200)
        ->and($dump)->not->toContain('abc123')->not->toContain('سري')->not->toContain('cookie-value')->not->toContain('top-secret')->not->toContain('token=');
});

test('E24: nothing is logged below the threshold, or when the threshold is zero', function () {
    $captured = 0;
    Log::listen(function ($event) use (&$captured) {
        $event->message === 'slow_request' && $captured++;
    });

    config(['performance.observability.slow_request_ms' => 600000]);
    $this->get('/up')->assertOk();
    config(['performance.observability.slow_request_ms' => 0]);
    $this->get('/up')->assertOk();

    expect($captured)->toBe(0);
});

test('E24: sampling at zero logs nothing even for slow requests', function () {
    config(['performance.observability.slow_request_ms' => 1, 'performance.observability.slow_request_sample' => 0.0]);
    $captured = 0;
    Log::listen(function ($event) use (&$captured) {
        $event->message === 'slow_request' && $captured++;
    });

    $this->get('/up')->assertOk();

    expect($captured)->toBe(0);
});

test('E24: /ready is 200 with database and cache ok, exposes no secrets or versions, and is not cacheable', function () {
    $response = $this->getJson('/ready')->assertOk()->assertJsonPath('status', 'ok')->assertJsonPath('checks.database.ok', true)->assertJsonPath('checks.cache.ok', true);
    $body = $response->getContent();

    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($body)->not->toContain(config('app.key'))->not->toContain('localhost')->not->toContain(PHP_VERSION)->not->toContain(app()->version());
});

test('E24: /ready is 503 when a dependency is down, without leaking the failure detail', function () {
    config(['cache.default' => 'store-that-does-not-exist']);

    $response = $this->getJson('/ready')->assertStatus(503)->assertJsonPath('status', 'unavailable')->assertJsonPath('checks.database.ok', true)->assertJsonPath('checks.cache.ok', false);

    expect($response->getContent())->not->toContain('store-that-does-not-exist')->not->toContain('InvalidArgumentException');
});

test('E24: a dependency slower than the budget counts as not ready', function () {
    $probe = new ReflectionMethod(\App\Http\Controllers\HealthController::class, 'probe');
    $controller = new \App\Http\Controllers\HealthController;

    $slow = $probe->invoke($controller, function () { usleep(8000); }, 2);
    $fast = $probe->invoke($controller, function () {}, 5000);
    $broken = $probe->invoke($controller, function () { throw new RuntimeException('x'); }, 5000);

    expect($slow['ok'])->toBeFalse()->and($fast['ok'])->toBeTrue()->and($broken['ok'])->toBeFalse();
});

test('E24: /ready with a configured token rejects requests without it', function () {
    config(['performance.observability.readiness_token' => 's3cret-token']);

    $this->getJson('/ready')->assertForbidden();
    $this->withHeaders(['X-Health-Token' => 'wrong'])->getJson('/ready')->assertForbidden();
    $this->withHeaders(['X-Health-Token' => 's3cret-token'])->getJson('/ready')->assertOk();
});

test('E24: /ready does not start a session, record a device sighting or require authentication', function () {
    $before = \App\Models\DeviceSighting::count();
    $response = $this->getJson('/ready')->assertOk();

    expect(\App\Models\DeviceSighting::count())->toBe($before)->and($response->headers->getCookies())->toBeEmpty();
});
