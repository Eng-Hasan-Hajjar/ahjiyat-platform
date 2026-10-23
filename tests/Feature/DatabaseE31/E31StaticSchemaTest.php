<?php

use App\Database\MysqlSafeBlueprint;
use App\Services\Database\FinancialHistoryGuard;
use App\Services\Database\MysqlDdlCompiler;
use App\Services\Database\MysqlDdlLinter;
use Illuminate\Support\Facades\File;

require_once __DIR__ . '/_support.php';

/*
| E31 - ترجمة الترحيلات إلى جمل MySQL بلا خادم وتحليلها. تعمل على أي سائق لأن الترجمة تستعمل اتصالًا وهميًا بسائق mysql (وضع pretend).
| هذا هو الدليل المتاح بلا خادم على أن «migrate من الصفر» لن يصطدم بقيود MySQL المعروفة؛ التحقق الفعلي على خادم حقيقي في E31MysqlRuntimeTest.
*/

function e31_compiled(): array
{
    static $cache = null;

    if ($cache === null) {
        $compiled = (new MysqlDdlCompiler)->compile();
        $cache = ['compiled' => $compiled, 'lint' => (new MysqlDdlLinter)->analyze($compiled['statements'])];
    }

    return $cache;
}

function e31_fails(array $lint): array
{
    return array_values(array_map(
        fn(array $f) => "{$f['code']} · {$f['table']} · {$f['detail']} · {$f['migration']}",
        array_filter($lint['findings'], fn(array $f) => $f['level'] === 'fail'),
    ));
}

test('AR-1: every migration compiles to MySQL DDL with no errors (schema builds from zero, statically)', function () {
    $c = e31_compiled()['compiled'];

    expect($c['errors'])->toBe([])
        ->and($c['migrations'])->toBe(count(glob(database_path('migrations') . '/*.php')))
        ->and(count($c['statements']))->toBeGreaterThan(600);
});

test('AR-2: the compiled MySQL schema has zero blocking findings (name length, FK/index, FK types, LOB, money types)', function () {
    expect(e31_fails(e31_compiled()['lint']))->toBe([]);
});

test('C-1: no generated table, column, index or foreign-key name exceeds 64 characters (MySQL error 1059)', function () {
    $names = [];

    foreach (e31_compiled()['compiled']['statements'] as $statement) {
        preg_match_all('/`([A-Za-z0-9_]+)`/', $statement['sql'], $m);
        array_push($names, ...$m[1]);
    }

    $tooLong = array_values(array_filter(array_unique($names), fn(string $n) => strlen($n) > 64));

    expect($tooLong)->toBe([]);
});

test('C-2: the blueprint shim shortens only over-long names, only on mysql/mariadb, deterministically and without collisions', function () {
    $long = 'competitive_event_results_competitive_event_id_score_duration_ms_index';
    $other = 'competitive_event_results_competitive_event_id_score_duration_xx_index';

    $short = MysqlSafeBlueprint::fit($long, 'mysql');

    expect(strlen($long))->toBeGreaterThan(64)
        ->and(strlen($short))->toBe(64)
        ->and(MysqlSafeBlueprint::fit($long, 'mysql'))->toBe($short)
        ->and(MysqlSafeBlueprint::fit($long, 'mariadb'))->toBe($short)
        ->and(MysqlSafeBlueprint::fit($other, 'mysql'))->not->toBe($short)
        ->and(MysqlSafeBlueprint::fit($long, 'sqlite'))->toBe($long)
        ->and(MysqlSafeBlueprint::fit('users_email_unique', 'mysql'))->toBe('users_email_unique');
});

test('C-3: the shim is what the container resolves for Blueprint (so historical migrations are protected without edits)', function () {
    $connection = DB::connection();
    $connection->getSchemaBuilder(); // يهيّئ قواعد المخطط قبل بناء Blueprint

    expect(app(\Illuminate\Database\Schema\Blueprint::class, ['connection' => $connection, 'table' => 'x']))->toBeInstanceOf(MysqlSafeBlueprint::class);
});

test('C-4: without the E31 wallets guard migrations the static check reports MySQL error 1553 - and with them it is clean', function () {
    $dir = e31_tmp_dir() . DIRECTORY_SEPARATOR . 'migrations-noguard';
    File::deleteDirectory($dir);
    File::makeDirectory($dir, 0777, true);

    foreach (glob(database_path('migrations') . '/*.php') as $file) {
        if (!str_contains($file, 'e31_')) {
            copy($file, $dir . DIRECTORY_SEPARATOR . basename($file));
        }
    }

    $without = (new MysqlDdlLinter)->analyze((new MysqlDdlCompiler)->compile($dir)['statements']);
    $codes = array_column(array_filter($without['findings'], fn(array $f) => $f['level'] === 'fail'), 'code');

    File::deleteDirectory($dir);

    expect($codes)->toContain('index_needed_by_fk')
        ->and(e31_fails(e31_compiled()['lint']))->toBe([]);
});

test('V-1: money is integer minor units only - the schema has no float, double or decimal column at all', function () {
    $offenders = [];

    foreach (e31_compiled()['lint']['tables'] as $table => $model) {
        foreach ($model['columns'] as $name => $column) {
            if (in_array($column['base'], ['float', 'double', 'real', 'decimal', 'numeric'], true)) {
                $offenders[] = "{$table}.{$name} ({$column['type']})";
            }
        }
    }

    expect($offenders)->toBe([]);
});

test('V-2: monetary columns are integers (never negative-capable where they are balances)', function () {
    $tables = e31_compiled()['lint']['tables'];

    foreach ([['wallets', 'available_balance'], ['wallets', 'pending_balance'], ['sponsorship_contributions', 'original_amount_minor'], ['sponsorship_contributions', 'accounting_amount_minor'], ['sponsorship_reward_bonuses', 'final_amount'], ['store_purchases', 'price_amount'], ['currency_packs', 'price_minor']] as [$table, $column]) {
        expect($tables[$table]['columns'][$column]['type'])->toContain('int')->and($tables[$table]['columns'][$column]['type'])->toContain('unsigned');
    }

    // دفتر العملات موقَّع عمدًا (الإنفاق سالب)
    expect($tables['currency_transactions']['columns']['amount']['type'])->toBe('bigint');
});

test('U-1: every foreign key has a locally indexed column set and a type-compatible parent (checked by the linter) - and none is orphaned in the model', function () {
    foreach (e31_compiled()['lint']['tables'] as $table => $model) {
        foreach ($model['fks'] as $fk) {
            $covered = false;

            foreach ($model['indexes'] as $index) {
                if (array_slice($index['columns'], 0, count($fk['columns'])) === $fk['columns']) {
                    $covered = true;
                }
            }

            expect($covered)->toBeTrue("{$table}.{$fk['name']} has no covering index");
        }
    }
});

test('U-2/U-3: financial and reward history never cascades accidentally - cascades onto users are guarded or explicitly exempt', function () {
    $guarded = array_keys(FinancialHistoryGuard::HISTORY_TABLES);
    $exempt = [
        'wallets' /* يحرسه فحص الرصيد في الحارس */ ,
        'challenge_participants' /* سجل مشاركة مشتق؛ الدفتر الفعلي في currency_transactions */ ,
        // E33: صف الإحالة لقطة مشتقة (snap_*)؛ الدفتر الفعلي لمكافآتها في currency_transactions المحروس أعلاه، فأي مستخدم نالت محفظته مكافأة إحالة يُمنع حذفه أصلًا.
        // المُحال بلا مكافأة مسلَّمة لا تاريخ مالي له، وحذفه (حق المحو) يزيل صفّ إحالته فقط؛ المحيل المحذوف يُجعل NULL فيبقى تاريخ المُحال.
        'user_referrals',
    ];
    $unguarded = [];

    foreach (e31_compiled()['lint']['tables'] as $table => $model) {
        $cascadesOnUser = collect($model['fks'])->contains(fn(array $fk) => $fk['ref'] === 'users' && $fk['on_delete'] === 'cascade');

        if (!$cascadesOnUser) {
            continue;
        }

        $money = collect($model['columns'])->contains(fn(array $c, string $n) => in_array($c['base'], ['int', 'bigint', 'smallint', 'mediumint'], true)
            && preg_match('/(amount|balance|price|minor|bps|gems|earned|redeemed)/', $n) === 1);

        if ($money && !in_array($table, array_merge($guarded, $exempt), true)) {
            $unguarded[] = $table;
        }
    }

    // جدول مالي جديد بـON DELETE CASCADE على users يجب أن يُضاف للحارس (FinancialHistoryGuard) أو يُبرَّر هنا.
    expect($unguarded)->toBe([]);
});

test('U-4: financial history that must survive parent deletion is RESTRICT (programs, supporters, currencies, store items)', function () {
    $tables = e31_compiled()['lint']['tables'];
    $rule = fn(string $table, string $column) => collect($tables[$table]['fks'])->first(fn(array $fk) => $fk['columns'] === [$column])['on_delete'] ?? 'missing';

    expect($rule('sponsorship_contributions', 'supporter_profile_id'))->toBe('restrict')
        ->and($rule('sponsorship_contributions', 'sponsorship_program_id'))->toBe('restrict')
        ->and($rule('sponsorship_reward_bonuses', 'sponsorship_program_id'))->toBe('restrict')
        ->and($rule('sponsorship_reward_bonuses', 'currency_id'))->toBe('restrict')
        ->and($rule('currency_transactions', 'currency_id'))->toBe('restrict')
        ->and($rule('wallets', 'currency_id'))->toBe('restrict')
        ->and($rule('store_purchases', 'user_id'))->toBe('restrict')
        ->and($rule('store_purchases', 'store_item_id'))->toBe('restrict')
        ->and($rule('competitive_reward_grants', 'competitive_reward_rule_id'))->toBe('restrict')
        ->and($rule('sponsorship_contributions', 'created_by'))->toBe('set null');
});

test('O-1: idempotency and duplicate-protection unique indexes exist (the backstop that retries and races rely on)', function () {
    $tables = e31_compiled()['lint']['tables'];
    $unique = fn(string $table, array $columns) => collect($tables[$table]['indexes'])->contains(fn(array $i) => $i['unique'] && $i['columns'] === $columns);

    expect($unique('wallets', ['user_id', 'currency_id']))->toBeTrue()
        ->and($unique('currency_transactions', ['idempotency_key']))->toBeTrue()
        ->and($unique('store_purchases', ['request_key']))->toBeTrue()
        ->and($unique('sponsorship_reward_bonuses', ['source_key']))->toBeTrue()
        ->and($unique('sponsorship_contributions', ['provider', 'external_reference']))->toBeTrue()
        ->and($unique('community_puzzle_ratings', ['user_id', 'creator_puzzle_id']))->toBeTrue()
        ->and($unique('community_puzzle_favorites', ['user_id', 'creator_puzzle_id']))->toBeTrue()
        ->and($unique('creator_follows', ['user_id', 'creator_profile_id']))->toBeTrue()
        ->and($unique('competitive_reward_grants', ['competitive_event_id', 'user_id']))->toBeTrue()
        ->and($unique('competitive_event_results', ['competitive_event_id', 'user_id']))->toBeTrue()
        ->and($unique('creator_puzzle_revisions', ['creator_puzzle_id', 'revision_number']))->toBeTrue()
        ->and($unique('creator_series_revisions', ['creator_series_id', 'revision_number']))->toBeTrue()
        ->and($unique('creator_profiles', ['user_id']))->toBeTrue();
});

test('Q-4: redundant (prefix-covered) indexes are reported, never silently dropped - the audit list is pinned', function () {
    $redundant = collect(e31_compiled()['lint']['findings'])->where('code', 'redundant_index')->map(fn(array $f) => $f['table'])->sort()->values()->all();

    // تدقيق E31: هذه الفهارس الأربعة مغطّاة ببادئات فهارس أخرى. لم تُحذف عمدًا (تكلفة كتابة صغيرة مقابل خطر تغيير خطط التنفيذ بلا EXPLAIN على بيانات حقيقية).
    // إن زادت القائمة فيجب مراجعة الفهرس الجديد؛ وإن نقصت فهذا مقبول (يحدّث الاختبار).
    expect($redundant)->toBe(['device_sightings', 'notifications', 'puzzle_attempts', 'puzzle_attempts']);
});

test('S-2: the schema never depends on MySQL zero-dates, and CURRENT_TIMESTAMP defaults only appear on timestamp columns', function () {
    foreach (e31_compiled()['compiled']['statements'] as $statement) {
        $sql = strtolower($statement['sql']);

        expect($sql)->not->toContain('0000-00-00');

        if (preg_match_all('/`[a-z0-9_]+` (\w+)(?:\(\d+\))? [^,]*?default current_timestamp/', $sql, $m)) {
            foreach ($m[1] as $type) {
                expect(in_array($type, ['timestamp', 'datetime'], true))->toBeTrue("CURRENT_TIMESTAMP default on {$type}");
            }
        }
    }
});

test('P-2: only the four audited entry points use the deadlock retry policy, and the attempt count is small', function () {
    $files = collect(File::allFiles(app_path()))
        ->filter(fn($f) => str_contains(File::get($f->getPathname()), 'TransactionPolicy::DEADLOCK_ATTEMPTS'))
        ->map(fn($f) => str_replace(str_replace('\\', '/', app_path()) . '/', '', str_replace('\\', '/', $f->getPathname())))
        ->reject(fn($p) => str_ends_with($p, 'Database/TransactionPolicy.php'))
        ->sort()->values()->all();

    expect($files)->toBe([
        'Services/Competitive/CompetitiveRunService.php',
        'Services/Creators/CreatorApplicationService.php',
        'Services/GameSessionService.php',
        'Services/PuzzleAttemptService.php',
    ])->and(\App\Services\Database\TransactionPolicy::DEADLOCK_ATTEMPTS)->toBe(3);

    // لا إعادة محاولة بالجملة: لا attempts: مسمّاة في أي مكان آخر.
    $named = collect(File::allFiles(app_path()))->filter(fn($f) => preg_match('/DB::transaction\(.*attempts\s*:/s', File::get($f->getPathname())) === 1);
    expect($named->count())->toBe(0);
});

test('R-1: raw SQL never uses a reserved MySQL 8 word unqualified (rank)', function () {
    $offenders = [];

    foreach (File::allFiles(app_path()) as $file) {
        $source = File::get($file->getPathname());

        // selectRaw/whereRaw/orderByRaw/DB::raw/havingRaw أو DB::select(...) يحوي rank غير مسبوقة بنقطة أو بـ`.
        if (preg_match_all('/(selectRaw|whereRaw|orderByRaw|groupByRaw|havingRaw|DB::raw|DB::select|DB::statement)\(\s*([\'"`])(.*?)\2/s', $source, $matches)) {
            foreach ($matches[3] as $sql) {
                if (preg_match('/(?<![\w.`])rank(?![\w(])/i', $sql) === 1) {
                    $offenders[] = str_replace(str_replace('\\', '/', app_path()) . '/', '', str_replace('\\', '/', $file->getPathname()));
                }
            }
        }
    }

    expect(array_values(array_unique($offenders)))->toBe([]);
});
