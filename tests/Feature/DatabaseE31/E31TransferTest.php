<?php

use App\Services\Database\SqliteToMysqlTransfer;
use App\Services\Database\TransferException;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/_support.php';

/*
| E31 - نقل بيانات SQLite → قاعدة هدف عبر خدمة النقل الحقيقية نفسها.
| الاختبارات هنا «محمولة»: الهدف ملف SQLite ثانٍ مُرحَّل كاملًا (الخدمة تقبل هدف SQLite في الاختبارات فقط)، فنثبت منطق النسخ والتحقق
| (ترتيب الاعتماد، حفظ المعرّفات، المحتوى المشفَّر، JSON، الأرقام المالية، الأيتام، البصمات) بلا خادم MySQL.
| النقل على MySQL فعليًا يغطّيه E31MysqlRuntimeTest (يتخطى بوضوح بلا MySQL).
*/

afterAll(fn () => e31_cleanup());

/** @return array{0: SqliteToMysqlTransfer, 1: string, 2: string, 3: array<string, mixed>} */
function e31_prepared_transfer(array $over = []): array
{
    $src = e31_new_sqlite('src');
    $tgt = e31_new_sqlite('tgt');
    $ids = e31_seed_world('e31_src');
    DB::purge('e31_src');

    return [e31_connected($src, 'e31_tgt', $over), $src, $tgt, $ids];
}

test('T-1: the plan is clean for a fresh migrated target, and ephemeral tables are excluded', function () {
    [$service, , , $ids] = e31_prepared_transfer();
    $plan = $service->plan();

    expect($plan['problems'])->toBe([])
        ->and($plan['ok'])->toBeTrue()
        ->and($plan['tables']['sessions']['status'])->toBe('excluded')
        ->and($plan['tables']['cache']['status'])->toBe('excluded')
        ->and($plan['tables']['jobs']['status'])->toBe('excluded')
        ->and($plan['tables']['migrations']['status'])->toBe('excluded')
        ->and($plan['tables']['users']['status'])->toBe('copy')
        ->and($plan['tables']['users']['source_rows'])->toBeGreaterThanOrEqual(2)
        ->and($plan['tables']['currencies']['status'])->toBe('reference')
        ->and($plan['planned_rows'])->toBeGreaterThan(20)
        ->and($ids['player_id'])->toBeInt();

    $service->disconnect();
});

test('T-2: execute + validate copies every row with identical ids, Arabic/emoji text, encrypted content and money - and PASSES', function () {
    [$service, $srcPath, , $ids] = e31_prepared_transfer();
    $before = hash_file('sha256', $srcPath);

    $plan = $service->plan();
    expect($plan['ok'])->toBeTrue();

    $run = $service->execute($plan);
    $report = $service->validate($plan);

    $t = $report['totals'];
    expect($report['result'])->toBe('PASS')
        ->and($t['row_count_mismatches'])->toBe(0)
        ->and($t['integrity_violations'])->toBe(0)
        ->and($t['fingerprint_mismatches'])->toBe(0)
        ->and($t['auto_increment_issues'])->toBe(0)
        ->and($t['financial_mismatches'])->toBe(0)
        ->and($t['skipped_ephemeral'])->toBeGreaterThanOrEqual(3)
        ->and($report['fingerprints_checked'])->toBeTrue()
        ->and($run['copied']['users'])->toBeGreaterThanOrEqual(2);

    // المعرّفات محفوظة، والنص العربي/الإيموجي كما هو بالبايت
    $src = $service->source();
    $tgt = $service->target();
    expect($tgt->table('users')->where('id', $ids['player_id'])->value('name'))->toBe('لاعب عربي 🎮 ألِف')
        ->and($tgt->table('users')->orderBy('id')->pluck('id')->all())->toBe($src->table('users')->orderBy('id')->pluck('id')->all())
        ->and($tgt->table('supporter_profiles')->where('id', $ids['supporter_id'])->value('display_name'))->toBe('داعم الجواهر 💎');

    // المحتوى المشفَّر ينتقل كما هو (النص المشفَّر نفسه، لا فك ولا إعادة تشفير) والمصدر لم يتغيّر
    $cipherSrc = $src->table('creator_puzzle_revisions')->orderBy('id')->pluck('content')->all();
    $cipherTgt = $tgt->table('creator_puzzle_revisions')->orderBy('id')->pluck('content')->all();
    expect($cipherSrc)->not->toBe([])->and($cipherTgt)->toBe($cipherSrc)
        ->and(hash_file('sha256', $srcPath))->toBe($before);

    // الأرصدة والدفتر مطابقة بالتمام
    foreach (['wallets' => ['available_balance', 'pending_balance'], 'currency_transactions' => ['amount'], 'sponsorship_contributions' => ['original_amount_minor', 'accounting_amount_minor']] as $table => $columns) {
        foreach ($columns as $column) {
            expect((int) $tgt->table($table)->sum($column))->toBe((int) $src->table($table)->sum($column), "{$table}.{$column}");
        }
    }

    $service->disconnect();
});

test('T-3: a populated target is refused (no merge) - and reset allows an idempotent retry', function () {
    [$service, , , ] = e31_prepared_transfer();
    $plan = $service->plan();
    $service->execute($plan);

    $again = $service->plan();
    expect($again['ok'])->toBeFalse()
        ->and(implode(' ', $again['problems']))->toContain('users')
        ->and(fn () => $service->execute($again))->toThrow(TransferException::class);

    $cleared = $service->resetTarget($again);
    expect($cleared)->toContain('users');

    $fresh = $service->plan();
    expect($fresh['ok'])->toBeTrue();
    $service->execute($fresh);
    expect($service->validate($fresh)['result'])->toBe('PASS');

    $service->disconnect();
});

test('T-4: tampering with the target after copy is caught (changed value, missing row, orphan) - validation never silently passes', function () {
    foreach (['value', 'row', 'orphan'] as $mode) {
        [$service] = e31_prepared_transfer();
        $plan = $service->plan();
        $service->execute($plan);
        $tgt = $service->target();

        match ($mode) {
            'value' => $tgt->table('users')->orderBy('id')->limit(1)->update(['name' => 'تم العبث']),
            'row' => $tgt->table('currency_transactions')->orderByDesc('id')->limit(1)->delete(),
            'orphan' => (function () use ($tgt) {
                $tgt->statement('PRAGMA foreign_keys = OFF');
                $tgt->table('wallets')->orderBy('id')->limit(1)->update(['user_id' => 987654]);
                $tgt->statement('PRAGMA foreign_keys = ON');
            })(),
        };

        $report = $service->validate($plan);
        expect($report['result'])->toBe('FAIL', $mode);

        if ($mode === 'value') {
            expect($report['totals']['fingerprint_mismatches'])->toBeGreaterThan(0);
        }

        if ($mode === 'row') {
            expect($report['totals']['row_count_mismatches'])->toBeGreaterThan(0);
        }

        if ($mode === 'orphan') {
            expect($report['totals']['integrity_violations'])->toBeGreaterThan(0);
        }

        $service->disconnect();
    }
});

test('T-5: the copy is correct across multiple batches (keyset paging) with no row lost or duplicated', function () {
    [$service, , , ] = e31_prepared_transfer(['batch_size' => 500]);
    $src = $service->source();
    $max = (int) $src->table('users')->max('id');

    // نضيف مستخدمين بالجملة مباشرة في ملف المصدر (قراءة فقط عبر الخدمة، لذا نفتح اتصالًا عاديًا آخر)
    $service->disconnect();
    $path = config('database.connections.e31_src.database');
    config(['database.connections.e31_bulk' => array_merge((array) config('database.connections.sqlite'), ['database' => $path, 'foreign_key_constraints' => true])]);
    $bulk = DB::connection('e31_bulk');
    $now = now()->toDateTimeString();

    for ($chunk = 0; $chunk < 3; $chunk++) {
        $rows = [];

        for ($i = 0; $i < 450; $i++) {
            $n = $chunk * 450 + $i;
            $rows[] = ['name' => "مستخدم {$n} 🎲", 'email' => "bulk{$n}@example.test", 'password' => 'x', 'created_at' => $now, 'updated_at' => $now];
        }

        $bulk->table('users')->insert($rows);
    }

    DB::purge('e31_bulk');

    $service = e31_connected($path, 'e31_tgt', ['batch_size' => 500]);
    $plan = $service->plan();
    $service->execute($plan);
    $report = $service->validate($plan);

    expect($plan['tables']['users']['source_rows'])->toBeGreaterThan(1300)
        ->and($report['result'])->toBe('PASS')
        ->and($service->target()->table('users')->count())->toBe($service->source()->table('users')->count())
        ->and((int) $service->target()->table('users')->max('id'))->toBeGreaterThan($max);

    $service->disconnect();
});

test('T-6: sanitized errors expose only a category and a code - never a row value, SQL text or credential', function () {
    $secretValue = 'سري-'.bin2hex(random_bytes(4));

    $leaky = new PDOException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '{$secretValue}' for key 'users.users_email_unique'");
    $query = new \Illuminate\Database\QueryException('mysql', 'insert into `users` (`email`) values (?)', [$secretValue], $leaky);

    $out = SqliteToMysqlTransfer::sanitize($query);
    $flat = json_encode($out, JSON_UNESCAPED_UNICODE);

    expect($flat)->not->toContain($secretValue)->and($flat)->not->toContain('insert into')->and($flat)->not->toContain('SQLSTATE');
});

test('T-7: canonical value encoding is order-independent, type-stable and byte-exact for text, JSON, booleans and integers', function () {
    $c = fn ($v, $class, $json = false) => SqliteToMysqlTransfer::canonical($v, $class, $json);

    expect($c(1, 'bool'))->toBe($c('1', 'bool'))->and($c(true, 'bool'))->toBe($c(1, 'bool'))
        ->and($c(null, 'string'))->not->toBe($c('', 'string'))
        ->and($c(12, 'int'))->toBe($c('12', 'int'))
        ->and($c('لغز 🎮', 'string'))->toBe($c('لغز 🎮', 'string'))
        ->and($c('{"a":1,"b":[1,2]}', 'text', true))->toBe($c('{"b":[1,2],"a":1}', 'text', true))
        ->and($c('{"a":1}', 'text', true))->not->toBe($c('{"a":2}', 'text', true))
        ->and(SqliteToMysqlTransfer::number('007'))->toBe(SqliteToMysqlTransfer::number(7));
});

/** يفتح ملف المصدر باتصال عادي لتعديله (الخدمة نفسها تفتحه للقراءة فقط). */
function e31_mutate_source(string $path, Closure $fn): void
{
    config(['database.connections.e31_mut' => array_merge((array) config('database.connections.sqlite'), ['database' => $path, 'url' => null, 'foreign_key_constraints' => false])]);
    DB::purge('e31_mut');
    $fn(DB::connection('e31_mut'));
    DB::purge('e31_mut');
}

test('T-8: pre-flight blocks data MySQL would reject or silently merge (case-insensitive unique collision, zero dates, reference identity, missing table)', function () {
    $cases = [
        'unique' => fn ($db) => $db->table('users')->insert([
            ['name' => 'أ', 'email' => 'Case.Collision@example.test', 'password' => 'x'],
            ['name' => 'ب', 'email' => 'case.collision@example.test', 'password' => 'x'],
        ]),
        'zero_date' => fn ($db) => $db->table('users')->where('id', 1)->update(['email_verified_at' => '0000-00-00 00:00:00']),
        'reference' => fn ($db) => $db->table('currencies')->orderBy('id')->limit(1)->update(['id' => 9001]),
        'missing_table' => fn ($db) => $db->statement('create table e31_only_in_source (id integer primary key)'),
    ];
    $expect = ['unique' => 'users', 'zero_date' => 'users', 'reference' => 'currencies', 'missing_table' => 'e31_only_in_source'];

    foreach ($cases as $name => $mutation) {
        [$service, $srcPath] = e31_prepared_transfer();
        $service->disconnect();
        e31_mutate_source($srcPath, $mutation);

        $service = e31_connected($srcPath, 'e31_tgt');
        $plan = $service->plan();

        expect($plan['ok'])->toBeFalse($name)
            ->and(implode("\n", $plan['problems']))->toContain($expect[$name])
            ->and(fn () => $service->execute($plan))->toThrow(TransferException::class);

        // لا قيمة صف في أي رسالة مشكلة
        expect(implode("\n", $plan['problems']))->not->toContain('example.test')->and(implode("\n", $plan['problems']))->not->toContain('0000-00-00 00');

        // وبعد الرفض لا شيء نُسخ إلى الهدف
        expect($service->target()->table('users')->count())->toBe(0);
        $service->disconnect();
    }
});

test('T-9: a failure in the middle of the copy re-enables foreign-key checks and reports no row value', function () {
    [$service, , , ] = e31_prepared_transfer();
    $plan = $service->plan();
    $target = $service->target();
    $target->statement("create trigger e31_boom before insert on wallets begin select raise(abort, 'e31 boom سري'); end");

    $caught = null;

    try {
        $service->execute($plan);
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(Throwable::class)
        ->and($caught->getMessage())->not->toContain('سري')
        ->and($caught->getMessage())->not->toContain('insert into')
        ->and((int) $target->selectOne('PRAGMA foreign_keys')->foreign_keys)->toBe(1);

    $service->disconnect();
});

test('T-10: the source connection is read-only at the engine level (any write is refused)', function () {
    [$service, $srcPath] = e31_prepared_transfer();
    $before = hash_file('sha256', $srcPath);

    expect(fn () => $service->source()->table('users')->insert(['name' => 'x', 'email' => 'w@example.test', 'password' => 'x']))->toThrow(\Illuminate\Database\QueryException::class)
        ->and(fn () => $service->source()->statement('delete from users'))->toThrow(\Illuminate\Database\QueryException::class);

    $service->disconnect();
    expect(hash_file('sha256', $srcPath))->toBe($before);
});
