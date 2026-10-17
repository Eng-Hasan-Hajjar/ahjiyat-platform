<?php

use App\Services\Database\TransactionPolicy;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/_support.php';

afterAll(fn () => e31_cleanup());

/*
| E31 - سياسة إعادة المحاولة عند الإيقاف المتبادل (MySQL 1213) / فشل التسلسل (40001): تُعاد المعاملة الخارجية فقط، بحد صغير، ولا شيء يُعاد داخل معاملة أخرى.
| تُختبر على اتصال مستقل (خارج معاملة RefreshDatabase التي تجعل كل معاملة تطبيقية «متداخلة» ولا تُعاد).
*/

function e31_deadlock(): QueryException
{
    return new QueryException('e31', 'update wallets set x = ?', [1], new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'));
}

test('X-1: an outermost transaction that deadlocks is retried and succeeds on a later attempt - with the data committed exactly once', function () {
    e31_new_sqlite('retry');
    $db = DB::connection('e31_retry');
    $attempts = 0;

    $result = $db->transaction(function () use (&$attempts, $db) {
        $attempts++;
        $db->table('migrations')->insert(['migration' => 'e31_retry_probe_'.$attempts, 'batch' => 9999]);

        if ($attempts < TransactionPolicy::DEADLOCK_ATTEMPTS) {
            throw e31_deadlock();
        }

        return 'done';
    }, TransactionPolicy::DEADLOCK_ATTEMPTS);

    // المحاولات الفاشلة تراجعت كاملة؛ لا بقايا منها
    expect($result)->toBe('done')
        ->and($attempts)->toBe(TransactionPolicy::DEADLOCK_ATTEMPTS)
        ->and($db->table('migrations')->where('batch', 9999)->pluck('migration')->all())->toBe(['e31_retry_probe_'.TransactionPolicy::DEADLOCK_ATTEMPTS]);
});

test('X-2: retries stop at the limit and the original error surfaces (no endless loop, nothing committed)', function () {
    e31_new_sqlite('retry2');
    $db = DB::connection('e31_retry2');
    $attempts = 0;

    $run = function () use (&$attempts, $db) {
        return $db->transaction(function () use (&$attempts, $db) {
            $attempts++;
            $db->table('migrations')->insert(['migration' => 'e31_never_'.$attempts, 'batch' => 9998]);

            throw e31_deadlock();
        }, TransactionPolicy::DEADLOCK_ATTEMPTS);
    };

    expect($run)->toThrow(QueryException::class);

    expect($attempts)->toBe(TransactionPolicy::DEADLOCK_ATTEMPTS)->and($db->table('migrations')->where('batch', 9998)->count())->toBe(0);
});

test('X-3: a non-concurrency error is never retried (business and validation errors surface immediately)', function () {
    e31_new_sqlite('retry3');
    $db = DB::connection('e31_retry3');
    $attempts = 0;

    $run = function () use (&$attempts, $db) {
        return $db->transaction(function () use (&$attempts) {
            $attempts++;

            throw new RuntimeException('الرصيد المتاح غير كافٍ لإتمام هذه العملية.');
        }, TransactionPolicy::DEADLOCK_ATTEMPTS);
    };

    expect($run)->toThrow(RuntimeException::class);

    expect($attempts)->toBe(1);
});

test('X-4: inside an already-open transaction a deadlock is not retried locally (the outermost owner decides) - nothing is silently re-run', function () {
    e31_new_sqlite('retry4');
    $db = DB::connection('e31_retry4');
    $attempts = 0;

    $db->beginTransaction();

    try {
        $run = function () use (&$attempts, $db) {
            return $db->transaction(function () use (&$attempts) {
                $attempts++;

                throw e31_deadlock();
            }, TransactionPolicy::DEADLOCK_ATTEMPTS);
        };

        expect($run)->toThrow(Exception::class); // Laravel يحوّله إلى DeadlockException عند التداخل
    } finally {
        $db->rollBack();
    }

    expect($attempts)->toBe(1);
});
