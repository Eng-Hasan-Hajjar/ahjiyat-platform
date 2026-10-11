<?php

use App\Jobs\Concerns\BoundedRetries;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * E24 - حدود الموثوقية كإعدادات ثابتة (لا أزمنة): تفشل فورًا إن عاد أحد الأخطار الذي أصلحناه.
 */
function e24_job_classes(): array
{
    $classes = [];

    foreach (glob(app_path('Jobs/*.php')) as $file) {
        $class = 'App\\Jobs\\'.basename($file, '.php');
        if (class_exists($class) && is_subclass_of($class, ShouldQueue::class)) {
            $classes[] = $class;
        }
    }

    return $classes;
}

test('E24: there are queued jobs and every one of them uses the bounded-retry policy', function () {
    $classes = e24_job_classes();

    expect($classes)->not->toBeEmpty();

    foreach ($classes as $class) {
        expect(in_array(BoundedRetries::class, class_uses($class), true))->toBeTrue("{$class} must use BoundedRetries");
    }
});

test('E24: a job timeout is always below the queue retry_after (no duplicate pickup of a still-running job)', function () {
    $retryAfters = collect(['database', 'redis', 'beanstalkd'])
        ->map(fn ($name) => config("queue.connections.{$name}.retry_after"))
        ->filter()
        ->all();

    expect($retryAfters)->not->toBeEmpty();

    foreach (e24_job_classes() as $class) {
        $job = (new ReflectionClass($class))->newInstanceWithoutConstructor();

        expect($job->failOnTimeout)->toBeTrue("{$class} must fail on timeout");

        foreach ($retryAfters as $retryAfter) {
            expect($job->timeout)->toBeLessThan((int) $retryAfter, "{$class} timeout must be below retry_after");
        }
    }
});

test('E24: job tries are finite and the backoff is non-empty and non-decreasing', function () {
    foreach (e24_job_classes() as $class) {
        $job = (new ReflectionClass($class))->newInstanceWithoutConstructor();
        $backoff = $job->backoff();

        expect($job->tries)->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(10)
            ->and($backoff)->not->toBeEmpty()
            ->and($backoff)->toBe(collect($backoff)->sort()->values()->all());
    }
});

test('E24: the realtime HTTP client to Reverb is bounded so a hung broadcaster cannot hold a PHP worker for 30 seconds', function () {
    $options = config('broadcasting.connections.reverb.client_options');

    expect((float) $options['connect_timeout'])->toBeGreaterThan(0)->toBeLessThanOrEqual(5)
        ->and((float) $options['timeout'])->toBeGreaterThan(0)->toBeLessThanOrEqual(10)
        ->and((float) config('broadcasting.connections.reverb.options.timeout'))->toBeLessThanOrEqual(10);
});

test('E24: every scheduled task has a bounded overlap lock (never the 24h default) and is single-server by default', function () {
    $events = collect(app(Schedule::class)->events());

    expect($events)->not->toBeEmpty();

    foreach ($events as $event) {
        $name = $event->description ?: $event->command;

        expect($event->withoutOverlapping)->toBeTrue("{$name} must use withoutOverlapping")
            ->and($event->expiresAt)->toBeLessThanOrEqual(720, "{$name} lock must expire within 12h")
            ->and($event->onOneServer)->toBeTrue("{$name} must be onOneServer()");
    }
});

test('E24: performance defaults keep product behavior and never cache forever', function () {
    expect(config('performance.device_sighting.throttle_seconds'))->toBeGreaterThan(0)
        ->and(config('performance.scheduler.one_server'))->toBeTrue()
        ->and(config('performance.observability.request_id'))->toBeTrue()
        ->and(config('performance.observability.slow_request_ms'))->toBeGreaterThan(0);

    foreach (config('performance.cache') as $key => $ttl) {
        expect($ttl)->toBeGreaterThan(0, "{$key} must be positive")->toBeLessThanOrEqual(86400, "{$key} must not be effectively forever");
    }
});
