<?php

require_once __DIR__.'/_support.php';

use App\Services\Operations\QueueSignals;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * E25 (J/S) - إشارات الطابور للقراءة فقط: العدد المنتظر، عمر أقدم مهمة، المهام الفاشلة؛ بلا حمولة ولا أسماء فئات.
 */
function e25_job(array $overrides = []): void
{
    DB::table('jobs')->insert(array_merge([
        'queue' => 'default',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\SecretJobName', 'data' => ['command' => 'serialized-secret-payload']]),
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => now()->getTimestamp(),
        'created_at' => now()->getTimestamp(),
    ], $overrides));
}

beforeEach(function () {
    config(['queue.default' => 'database']);
});

test('E25-J: pending counts only available, unreserved jobs and reports the oldest age', function () {
    $this->travelTo(now()->startOfMinute());
    e25_job(['available_at' => now()->subMinutes(30)->getTimestamp()]);   // قديمة ومنتظرة
    e25_job(['available_at' => now()->subMinutes(5)->getTimestamp()]);    // منتظرة
    e25_job(['available_at' => now()->subMinutes(10)->getTimestamp(), 'reserved_at' => now()->getTimestamp()]);   // قيد المعالجة: لا تُحسب
    e25_job(['available_at' => now()->addHour()->getTimestamp()]);        // مؤجَّلة للمستقبل: لا تُحسب

    $snapshot = app(QueueSignals::class)->snapshot();

    expect($snapshot['driver'])->toBe('database')
        ->and($snapshot['pending'])->toBe(2)
        ->and($snapshot['oldest_age_seconds'])->toBe(1800);
});

test('E25-J: an empty queue is healthy: zero pending, zero age, no warnings', function () {
    $snapshot = app(QueueSignals::class)->snapshot();

    expect($snapshot['pending'])->toBe(0)
        ->and($snapshot['oldest_age_seconds'])->toBe(0)
        ->and(app(QueueSignals::class)->warnings($snapshot))->toBe([]);
});

test('E25-J: a backlog older than the threshold and failed jobs produce warnings', function () {
    $this->travelTo(now()->startOfMinute());
    e25_job(['available_at' => now()->subMinutes(20)->getTimestamp()]);
    DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'boom', 'failed_at' => now()]);

    $signals = app(QueueSignals::class);
    $warnings = $signals->warnings($signals->snapshot());

    expect($warnings)->toHaveCount(2)
        ->and($warnings[0])->toContain('تراكم')->toContain('20 دقيقة')
        ->and($warnings[1])->toContain('1 مهمة فاشلة');
});

test('E25-J: thresholds are configurable and can be switched off with 0', function () {
    $this->travelTo(now()->startOfMinute());
    e25_job(['available_at' => now()->subMinutes(20)->getTimestamp()]);
    $signals = app(QueueSignals::class);
    $snapshot = $signals->snapshot();

    config(['operations.queue.backlog_warn_age_seconds' => 3600]);
    expect($signals->warnings($snapshot))->toBe([]);

    config(['operations.queue.backlog_warn_age_seconds' => 60]);
    expect($signals->warnings($snapshot))->toHaveCount(1);

    config(['operations.queue.backlog_warn_age_seconds' => 0]);
    expect($signals->warnings($snapshot))->toBe([]);
});

test('E25-J: the snapshot exposes numbers only, never payloads or job class names', function () {
    e25_job();

    $dump = json_encode(app(QueueSignals::class)->snapshot());

    expect($dump)->not->toContain('SecretJobName')->and($dump)->not->toContain('serialized-secret-payload');
});

test('E25-J: reading signals never writes (static and runtime)', function () {
    e25_job();
    $writes = [];
    DB::listen(function ($query) use (&$writes) {
        if (preg_match('/^\s*(insert|update|delete|create|alter|drop|truncate|replace)\b/i', $query->sql) === 1) {
            $writes[] = $query->sql;
        }
    });

    app(QueueSignals::class)->snapshot();

    expect($writes)->toBe([])
        ->and(preg_match('/->(insert|update|delete|truncate|pop|push|release|forget)\(|Queue::(push|pop|later)\b/', e25_repo_file('app/Services/Operations/QueueSignals.php')))->toBe(0);
});

test('E25-J: a failing read degrades to unknown (null) instead of throwing', function () {
    config(['queue.connections.database.table' => 'table_that_does_not_exist', 'queue.failed.table' => 'also_missing']);

    $snapshot = app(QueueSignals::class)->snapshot();

    expect($snapshot['pending'])->toBeNull()
        ->and($snapshot['oldest_age_seconds'])->toBeNull()
        ->and($snapshot['failed'])->toBeNull()
        ->and(app(QueueSignals::class)->warnings($snapshot))->toBe([]);
});

test('E25-J: the sync driver has no queue to measure (pending is unknown)', function () {
    config(['queue.default' => 'sync']);

    expect(app(QueueSignals::class)->snapshot()['pending'])->toBeNull();
});

test('E25-J: production:check surfaces queue warnings by name without leaking payloads', function () {
    $this->travelTo(now()->startOfMinute());
    e25_job(['available_at' => now()->subMinutes(45)->getTimestamp()]);
    config(['app.debug' => false]);

    [, $data, $raw] = e25_run('production:check');

    expect(collect($data['checks'])->where('setting', 'queue')->pluck('status')->all())->toContain('WARN')
        ->and($raw)->not->toContain('SecretJobName')->and($raw)->not->toContain('serialized-secret-payload');
});
