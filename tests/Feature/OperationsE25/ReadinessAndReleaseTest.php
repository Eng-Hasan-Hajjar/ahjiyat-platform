<?php

require_once __DIR__.'/_support.php';

use App\Providers\AppServiceProvider;
use App\Support\ReleaseInfo;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;

/**
 * E25 (6-8) - /ready يبقى خاليًا من الأسرار، معرّف الإصدار يظهر فقط بصيغة آمنة، والسجلات تحمل release وrequest_id.
 */
afterEach(function () {
    // اللقطة المشتركة لسياق السجل تبقى بين الاختبارات داخل العملية؛ نصفّرها كي لا يتأثر غيرنا.
    Log::flushSharedContext();
    Context::flush();

    if ($this->app->isDownForMaintenance()) {
        $this->app->maintenanceMode()->deactivate();
    }
});

test('E25-6: /ready exposes only status and per-check ok/ms (no secret, host, version or path)', function () {
    config(['operations.release.id' => null]);

    $response = $this->getJson('/ready')->assertOk();
    $body = $response->getContent();

    expect(array_keys($response->json()))->toBe(['status', 'checks'])
        ->and(array_keys($response->json('checks')))->toBe(['database', 'cache'])
        ->and($body)->not->toContain((string) config('app.key'))
        ->and($body)->not->toContain(substr((string) config('app.key'), 7))
        ->and($body)->not->toContain(PHP_VERSION)
        ->and($body)->not->toContain(app()->version())
        ->and($body)->not->toContain(base_path())
        ->and($body)->not->toContain((string) gethostname())
        ->and($body)->not->toContain('sqlite')
        ->and($body)->not->toContain('localhost')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');
});

test('E25-6: a configured readiness token is enforced and the denial leaks nothing', function () {
    config(['performance.observability.readiness_token' => 'e25-health-token-value', 'operations.release.id' => 'rel-1']);

    $denied = $this->getJson('/ready')->assertForbidden();
    expect($denied->getContent())->not->toContain('rel-1')->and($denied->json())->toBe(['status' => 'forbidden']);

    $this->withHeaders(['X-Health-Token' => 'e25-health-token-value'])->getJson('/ready')->assertOk();
});

test('E25-6: /ready answers 503 (still without details) when the database is not reachable', function () {
    $original = config('database.default');
    config(['database.connections.e25_broken' => ['driver' => 'sqlite', 'database' => '/nonexistent/dir/none.sqlite', 'prefix' => '', 'foreign_key_constraints' => true]]);
    config(['database.default' => 'e25_broken']);

    try {
        $response = $this->getJson('/ready');
    } finally {
        config(['database.default' => $original]);
    }

    $response->assertStatus(503);

    expect($response->json('status'))->toBe('unavailable')
        ->and($response->json('checks.database.ok'))->toBeFalse()
        ->and($response->getContent())->not->toContain('nonexistent')
        ->and($response->getContent())->not->toContain((string) config('app.key'));
});

test('E25-7: the release id is shown in /ready only when set and safe, shortened if it is a full SHA', function () {
    config(['operations.release.id' => 'b983867a5c214ee19c8edfc99319f04b8d3427c5']);
    expect($this->getJson('/ready')->json('release'))->toBe('b983867');

    config(['operations.release.id' => 'v25.1-rc_2']);
    expect($this->getJson('/ready')->json('release'))->toBe('v25.1-rc_2');

    config(['operations.release.id' => null]);
    expect($this->getJson('/ready')->json())->not->toHaveKey('release');
});

test('E25-7: unsafe release identifiers are ignored everywhere', function (string $unsafe) {
    config(['operations.release.id' => $unsafe]);

    expect(ReleaseInfo::id())->toBeNull()
        ->and(ReleaseInfo::short())->toBeNull()
        ->and(ReleaseInfo::isConfigured())->toBeFalse()
        ->and($this->getJson('/ready')->json())->not->toHaveKey('release');
})->with([
    'spaces' => 'a b',
    'newline injection' => "rel\nInjected: 1",
    'html' => '<script>alert(1)</script>',
    'assignment-looking secret' => 'password=hunter2',
    'too long' => str_repeat('a', 65),
    'empty' => '',
    'unicode' => 'إصدار-1',
]);

test('E25-7: /up (liveness) never carries the release or any detail', function () {
    config(['operations.release.id' => 'rel-visible']);

    expect($this->get('/up')->assertOk()->getContent())->not->toContain('rel-visible');
});

test('E25-8: log lines carry the release (shared context) when a release is configured', function () {
    config(['operations.release.id' => 'b983867a5c214ee19c8edfc99319f04b8d3427c5']);

    $provider = $this->app->getProvider(AppServiceProvider::class);
    (new ReflectionMethod($provider, 'registerOperationalGuards'))->invoke($provider);

    $handler = e25_memory_log_handler();
    Log::channel('e25mem')->warning('release correlation probe');

    $record = $handler->getRecords()[0];
    $all = json_encode([$record->context, $record->extra], JSON_UNESCAPED_UNICODE);

    expect($all)->toContain('"release":"b983867"');
});

test('E25-8: log lines carry the request id of the current request (E24 context)', function () {
    $this->withHeaders(['X-Request-ID' => 'e25-req-id-000001'])->get('/up')->assertOk();

    $handler = e25_memory_log_handler();
    Log::channel('e25mem')->warning('request correlation probe');

    $record = $handler->getRecords()[0];
    $all = json_encode([$record->context, $record->extra], JSON_UNESCAPED_UNICODE);

    expect($all)->toContain('e25-req-id-000001');
});

test('E25-8: without a configured release no release key is added to log lines', function () {
    config(['operations.release.id' => null]);

    $provider = $this->app->getProvider(AppServiceProvider::class);
    (new ReflectionMethod($provider, 'registerOperationalGuards'))->invoke($provider);

    $handler = e25_memory_log_handler();
    Log::channel('e25mem')->warning('no release probe');

    $record = $handler->getRecords()[0];

    expect(json_encode([$record->context, $record->extra]))->not->toContain('"release"');
});

test('E25: during maintenance /up (liveness) stays 200 while /ready and public pages answer 503, without leaking anything', function () {
    $this->app->maintenanceMode()->activate(['retry' => 60, 'status' => 503]);

    $up = $this->get('/up');
    $ready = $this->getJson('/ready');
    $page = $this->get('/puzzles');

    expect($up->status())->toBe(200)
        ->and($ready->status())->toBe(503)
        ->and($page->status())->toBe(503)
        ->and($ready->getContent())->not->toContain((string) config('app.key'))
        ->and($page->getContent())->not->toContain((string) config('app.key'));
});

if (! function_exists('e25_memory_log_handler')) {
    /** قناة سجل في الذاكرة (Monolog TestHandler) لقراءة السجلات المنتجة فعليًا مع سياقها الحقيقي. */
    function e25_memory_log_handler(): TestHandler
    {
        config(['logging.channels.e25mem' => ['driver' => 'monolog', 'handler' => TestHandler::class, 'level' => 'debug']]);
        Log::forgetChannel('e25mem');

        /** @var TestHandler $handler */
        $handler = Log::channel('e25mem')->getLogger()->getHandlers()[0];

        return $handler;
    }
}
