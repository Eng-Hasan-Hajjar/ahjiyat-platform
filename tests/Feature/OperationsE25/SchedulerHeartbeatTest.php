<?php

require_once __DIR__.'/_support.php';

use App\Support\SchedulerHeartbeat;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

/**
 * E25 (9-10) - نبض المجدول: يُحدَّث، وتُحسب حالته من عمره (ok/stale/down/unknown)، ولا يُسقط /ready إلا بخيار صريح.
 */
beforeEach(function () {
    Cache::forget(app(SchedulerHeartbeat::class)->key());
});

test('E25-9: the heartbeat task is registered last, every minute, and running it updates the heartbeat', function () {
    Artisan::call('schedule:list');
    $list = Artisan::output();

    expect($list)->toContain('scheduler-heartbeat')
        ->and(strpos($list, 'scheduler-heartbeat'))->toBeGreaterThan(strpos($list, 'campaigns-sync-availability'));

    $schedule = app(Illuminate\Console\Scheduling\Schedule::class);
    $event = collect($schedule->events())->first(fn ($e) => $e->description === 'scheduler-heartbeat');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *')
        // قاعدة E24 تسري عليه أيضًا: قفل تداخل محدود (دقائق لا 24 ساعة) + تنفيذ على خادم واحد.
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBeLessThanOrEqual(720)
        ->and($event->onOneServer)->toBeTrue()
        ->and(app(SchedulerHeartbeat::class)->lastBeatAt())->toBeNull();

    $event->run($this->app);

    expect(app(SchedulerHeartbeat::class)->lastBeatAt())->not->toBeNull()
        ->and(app(SchedulerHeartbeat::class)->status())->toBe(SchedulerHeartbeat::OK);
});

test('E25-9: beat() records the current timestamp and refreshes it on each call', function () {
    $heartbeat = app(SchedulerHeartbeat::class);

    $this->travelTo(now()->startOfMinute());
    $heartbeat->beat();
    $first = $heartbeat->lastBeatAt();

    $this->travel(61)->seconds();
    $heartbeat->beat();

    expect($first)->toBe(now()->subSeconds(61)->getTimestamp())
        ->and($heartbeat->lastBeatAt())->toBe(now()->getTimestamp())
        ->and($heartbeat->ageSeconds())->toBe(0);
});

test('E25-10: status is derived from the heartbeat age', function (?int $ageSeconds, string $expected) {
    $heartbeat = app(SchedulerHeartbeat::class);

    if ($ageSeconds !== null) {
        $this->travelTo(now()->startOfMinute());
        $heartbeat->beat();
        $this->travel($ageSeconds)->seconds();
    }

    expect($heartbeat->status())->toBe($expected);
})->with([
    'never beat (fresh deploy)' => [null, SchedulerHeartbeat::UNKNOWN],
    'just now' => [0, SchedulerHeartbeat::OK],
    'one minute' => [60, SchedulerHeartbeat::OK],
    'just below stale threshold' => [299, SchedulerHeartbeat::OK],
    'stale threshold' => [300, SchedulerHeartbeat::STALE],
    'just below down threshold' => [899, SchedulerHeartbeat::STALE],
    'down threshold' => [900, SchedulerHeartbeat::DOWN],
    'a day old' => [86_000, SchedulerHeartbeat::DOWN],
]);

test('E25-10: thresholds are configurable', function () {
    config(['operations.scheduler.stale_after_seconds' => 30, 'operations.scheduler.down_after_seconds' => 60]);
    $heartbeat = app(SchedulerHeartbeat::class);

    $this->travelTo(now()->startOfMinute());
    $heartbeat->beat();

    $this->travel(31)->seconds();
    expect($heartbeat->status())->toBe(SchedulerHeartbeat::STALE);

    $this->travel(30)->seconds();
    expect($heartbeat->status())->toBe(SchedulerHeartbeat::DOWN);
});

test('E25-10: a failing cache never throws from the heartbeat (fail-open: unknown / silent)', function () {
    Cache::shouldReceive('put')->andThrow(new RuntimeException('cache down'));
    Cache::shouldReceive('get')->andThrow(new RuntimeException('cache down'));

    $heartbeat = new SchedulerHeartbeat;
    $heartbeat->beat();

    expect($heartbeat->lastBeatAt())->toBeNull()
        ->and($heartbeat->status())->toBe(SchedulerHeartbeat::UNKNOWN);
});

test('E25-10: a garbage cache value is treated as unknown, not as a crash', function () {
    Cache::put(app(SchedulerHeartbeat::class)->key(), 'not-a-number', 60);

    expect(app(SchedulerHeartbeat::class)->status())->toBe(SchedulerHeartbeat::UNKNOWN);
});

test('E25-10: /ready ignores the scheduler by default, even when it is down', function () {
    $this->travelTo(now()->startOfMinute());
    app(SchedulerHeartbeat::class)->beat();
    $this->travel(2000)->seconds();

    $response = $this->getJson('/ready')->assertOk();

    expect($response->json('checks'))->not->toHaveKey('scheduler');
});

test('E25-10: with OPS_READY_FAILS_ON_SCHEDULER, /ready fails only when the scheduler is down (not unknown or stale)', function () {
    config(['operations.scheduler.ready_fails_on_down' => true]);
    $heartbeat = app(SchedulerHeartbeat::class);

    // نشر جديد: لا نبضة => unknown => لا تُسقط الجاهزية
    $this->getJson('/ready')->assertOk()->assertJsonPath('checks.scheduler.status', 'unknown');

    $this->travelTo(now()->startOfMinute());
    $heartbeat->beat();

    $this->travel(400)->seconds();
    $this->getJson('/ready')->assertOk()->assertJsonPath('checks.scheduler.status', 'stale');

    $this->travel(600)->seconds();
    $down = $this->getJson('/ready')->assertStatus(503);
    expect($down->json('checks.scheduler.status'))->toBe('down')->and($down->json('status'))->toBe('unavailable');
});

test('E25-10: production:check reports the scheduler state by name without failing the release on a stale heartbeat', function () {
    config(['app.debug' => false]);
    $heartbeat = app(SchedulerHeartbeat::class);

    [, $data] = e25_run('production:check');
    expect(e25_statuses($data, 'scheduler'))->toBe(['INFO']);

    $this->travelTo(now()->startOfMinute());
    $heartbeat->beat();
    [, $data] = e25_run('production:check');
    expect(e25_statuses($data, 'scheduler'))->toBe(['PASS']);

    $this->travel(1000)->seconds();
    [, $data] = e25_run('production:check');
    expect(e25_statuses($data, 'scheduler'))->toBe(['WARN']);
});
