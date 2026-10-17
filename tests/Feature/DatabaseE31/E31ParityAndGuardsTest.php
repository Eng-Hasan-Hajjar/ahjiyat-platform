<?php

use App\Services\Database\DatabaseFactsEvaluator;
use App\Services\Database\MysqlDdlLinter;
use App\Services\Database\SchemaParity;
use App\Services\Database\SchemaSnapshot;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/_support.php';
require_once __DIR__.'/../OperationsE25/_support.php';

afterAll(fn () => e31_cleanup());

/*
| E31 - تكافؤ المخطط (db:parity)، حواجز أمر النقل، تقييم حقائق الخادم، production:check على SQLite، و/ready.
| لا شيء هنا يحتاج خادم MySQL: الحواجز كلها تُرفض قبل أي اتصال بالهدف.
*/

function e31_run(string $command, array $options = []): array
{
    $code = Artisan::call($command, $options);

    return [$code, Artisan::output()];
}

// ───────────────────────────── تكافؤ المخطط ─────────────────────────────

test('AA-1: type classes map logically across engines (tinyint(1) is bool, int family is int, varchar vs text are distinct)', function () {
    expect(SchemaSnapshot::typeClass('tinyint(1)'))->toBe('bool')
        ->and(SchemaSnapshot::typeClass('boolean'))->toBe('bool')
        ->and(SchemaSnapshot::typeClass('bigint unsigned'))->toBe('int')
        ->and(SchemaSnapshot::typeClass('integer'))->toBe('int')
        ->and(SchemaSnapshot::typeClass('varchar(255)'))->toBe('string')
        ->and(SchemaSnapshot::typeClass('longtext'))->toBe('text')
        ->and(SchemaSnapshot::typeClass('json'))->toBe('text')
        ->and(SchemaSnapshot::typeClass('timestamp'))->toBe('datetime')
        ->and(SchemaSnapshot::typeClass('datetime'))->toBe('datetime')
        ->and(SchemaSnapshot::typeClass('date'))->toBe('date')
        ->and(SchemaSnapshot::typeClass('decimal(10,2)'))->toBe('decimal')
        ->and(SchemaSnapshot::typeClass('double'))->toBe('float')
        ->and(SchemaSnapshot::deleteRule('NO ACTION'))->toBe('restrict')
        ->and(SchemaSnapshot::deleteRule('CASCADE'))->toBe('cascade')
        ->and(SchemaSnapshot::deleteRule('SET NULL'))->toBe('set null');
});

function e31_snap(array $overrides = []): array
{
    $column = fn (string $class, bool $nullable = false, bool $unsigned = false, string $type = '') => ['class' => $class, 'nullable' => $nullable, 'unsigned' => $unsigned, 'type' => $type ?: $class];

    return ['wallets' => array_replace_recursive([
        'columns' => ['id' => $column('int', false, true), 'user_id' => $column('int', false, true), 'label' => $column('string', true)],
        'primary' => ['id'],
        'unique' => [['user_id']],
        'indexes' => [['label']],
        'fks' => [['columns' => ['user_id'], 'ref' => 'users', 'ref_columns' => ['id'], 'on_delete' => 'cascade']],
    ], $overrides)];
}

test('AA-2: the parity comparison classifies differences - material blocks, warn informs, unsigned on SQLite is only info', function () {
    $parity = new SchemaParity;
    $expected = e31_snap();
    $codes = fn (array $actual) => collect($parity->compare($expected, $actual)['findings'])->pluck('level', 'code')->all();

    expect($parity->compare($expected, $expected)['summary'][SchemaParity::MATERIAL])->toBe(0);

    // جدول/عمود/فريد/مفتاح أجنبي ناقص = جوهري
    expect($parity->compare($expected, [])['findings'][0])->toMatchArray(['level' => 'material', 'code' => 'table_missing']);

    $noColumn = e31_snap();
    unset($noColumn['wallets']['columns']['label']);
    expect($codes($noColumn))->toMatchArray(['column_missing' => 'material']);

    expect($codes(e31_snap(['unique' => []]) + []))->toBeArray();
    $noUnique = e31_snap();
    $noUnique['wallets']['unique'] = [];
    expect($codes($noUnique))->toMatchArray(['unique_missing' => 'material']);

    $noFk = e31_snap();
    $noFk['wallets']['fks'] = [];
    expect($codes($noFk))->toMatchArray(['fk_missing' => 'material']);

    // قاعدة حذف مختلفة = جوهري
    $otherRule = e31_snap();
    $otherRule['wallets']['fks'][0]['on_delete'] = 'restrict';
    expect($codes($otherRule))->toMatchArray(['fk_delete_rule_mismatch' => 'material']);

    // فئة نوع مختلفة = جوهري، لكن string↔text = تحذير فقط
    $typeDiff = e31_snap();
    $typeDiff['wallets']['columns']['user_id']['class'] = 'string';
    expect($codes($typeDiff))->toMatchArray(['type_class_mismatch' => 'material']);
    $stringText = e31_snap();
    $stringText['wallets']['columns']['label']['class'] = 'text';
    expect($codes($stringText))->toMatchArray(['type_class_mismatch' => 'warn']);

    // NOT NULL صار يقبل NULL = تحذير؛ والعكس = جوهري
    $relaxed = e31_snap();
    $relaxed['wallets']['columns']['user_id']['nullable'] = true;
    expect($codes($relaxed))->toMatchArray(['nullable_relaxed' => 'warn']);
    $tightened = e31_snap();
    $tightened['wallets']['columns']['label']['nullable'] = false;
    expect($codes($tightened))->toMatchArray(['nullable_tightened' => 'material']);

    // UNSIGNED مفقود = معلومة فقط (SQLite لا يفرضه)
    $signed = e31_snap();
    $signed['wallets']['columns']['user_id']['unsigned'] = false;
    expect($codes($signed))->toMatchArray(['unsigned_not_enforced' => 'info']);

    // فهرس عادي ناقص = تحذير؛ جدول زائد = تحذير
    $noIndex = e31_snap();
    $noIndex['wallets']['indexes'] = [];
    expect($codes($noIndex))->toMatchArray(['index_missing' => 'warn'])
        ->and($codes($expected + ['extra_table' => $expected['wallets']]))->toMatchArray(['table_extra' => 'warn']);
});

test('AA-3: db:parity --static passes with exit 0 and prints a result - it needs no live database', function () {
    [$code, $out] = e31_run('db:parity', ['--static' => true]);

    expect($code)->toBe(0)->and($out)->toContain('PASS');
});

test('AA-4: db:parity on the live (SQLite test) database passes with exit 0 and its JSON carries no connection secrets', function () {
    config(['database.connections.sqlite.password' => 'LeakProbe-'.bin2hex(random_bytes(3))]);
    [$code, $out] = e31_run('db:parity', ['--json' => true]);
    $data = json_decode($out, true);

    expect($code)->toBe(0)
        ->and($data['result'])->toBe('PASS')
        ->and($data['parity']['summary']['material'])->toBe(0)
        ->and($data['static']['summary']['fail'])->toBe(0)
        ->and($out)->not->toContain('LeakProbe-')
        ->and($out)->not->toContain('password');
});

test('AA-5: db:parity is read-only and exits 1 only on material drift (a missing table), not on warnings', function () {
    e31_new_sqlite('drift');
    Schema::connection('e31_drift')->disableForeignKeyConstraints();
    Schema::connection('e31_drift')->dropIfExists('community_puzzle_favorites');
    DB::purge('e31_drift');

    [$code, $out] = e31_run('db:parity', ['--connection' => 'e31_drift', '--json' => true]);
    $data = json_decode($out, true);

    expect($code)->toBe(1)
        ->and($data['result'])->toBe('FAIL')
        ->and(collect($data['parity']['findings'])->where('code', 'table_missing')->pluck('table')->all())->toBe(['community_puzzle_favorites']);

    // غير ملوَّث: الاتصال المفحوص لم يُعدَّل (الجدول ما زال غائبًا ولم يُنشأ)
    expect(Schema::connection('e31_drift')->hasTable('community_puzzle_favorites'))->toBeFalse();
});

test('AA-6: comparing two live connections (--against) passes for identical schemas', function () {
    e31_new_sqlite('left');
    [$code, $out] = e31_run('db:parity', ['--connection' => 'e31_left', '--against' => 'sqlite', '--json' => true]);
    $data = json_decode($out, true);

    expect($code)->toBe(0)->and($data['parity']['summary']['material'])->toBe(0);
});

// ───────────────────────────── حواجز أمر النقل ─────────────────────────────

function e31_good_source(): string
{
    static $path = null;

    return $path ??= e31_new_sqlite('guardsrc');
}

test('AB-1: the transfer command refuses to run when APP_ENV=production (no override)', function () {
    $previous = app()->environment();
    e25_as_production();

    try {
        [$code, $out] = e31_run('db:transfer-sqlite-to-mysql', ['--source' => e31_good_source(), '--dry-run' => true]);
    } finally {
        config(['app.env' => $previous]);
        app()['env'] = $previous;
    }

    expect($code)->toBe(1)->and($out)->toContain('production');
});

test('AB-2: it refuses an unknown target, a non-MySQL target and the sqlite connection itself', function () {
    foreach (['nope', 'sqlite', 'e31_guardsrc'] as $target) {
        [$code, $out] = e31_run('db:transfer-sqlite-to-mysql', ['--source' => e31_good_source(), '--target' => $target, '--dry-run' => true]);

        expect($code)->toBe(1, $target)->and($out)->toContain('مرفوض');
    }
});

test('AB-3: it refuses a missing source and a file that is not an SQLite database', function () {
    $notSqlite = e31_tmp_dir().DIRECTORY_SEPARATOR.'not-sqlite.txt';
    file_put_contents($notSqlite, 'this is not a database');

    foreach ([e31_tmp_dir().DIRECTORY_SEPARATOR.'missing.sqlite', $notSqlite] as $source) {
        [$code, $out] = e31_run('db:transfer-sqlite-to-mysql', ['--source' => $source, '--dry-run' => true]);

        expect($code)->toBe(1)->and($out)->toContain('مرفوض');
    }
});

test('AB-4: a real run needs --force (exit 2), then --backup, a distinct file, and a byte-identical copy - nothing touches the target before that', function () {
    $source = e31_good_source();
    $backup = e31_tmp_dir().DIRECTORY_SEPARATOR.'backup-'.bin2hex(random_bytes(3)).'.sqlite';
    $wrong = e31_tmp_dir().DIRECTORY_SEPARATOR.'wrong-'.bin2hex(random_bytes(3)).'.sqlite';
    copy($source, $backup);
    file_put_contents($wrong, "SQLite format 3\0 but different");

    [$noForce] = e31_run('db:transfer-sqlite-to-mysql', ['--source' => $source]);
    [$noBackup, $noBackupOut] = e31_run('db:transfer-sqlite-to-mysql', ['--source' => $source, '--force' => true]);
    [$sameFile, $sameOut] = e31_run('db:transfer-sqlite-to-mysql', ['--source' => $source, '--force' => true, '--backup' => $source]);
    [$mismatch, $mismatchOut] = e31_run('db:transfer-sqlite-to-mysql', ['--source' => $source, '--force' => true, '--backup' => $wrong]);
    [$noConfirm, $noConfirmOut] = e31_run('db:transfer-sqlite-to-mysql', ['--source' => $source, '--force' => true, '--backup' => $backup, '--reset-target' => true]);

    expect($noForce)->toBe(2)
        ->and($noBackup)->toBe(1)->and($noBackupOut)->toContain('--backup')
        ->and($sameFile)->toBe(1)->and($sameOut)->toContain('نفسه')
        ->and($mismatch)->toBe(1)->and($mismatchOut)->toContain('SHA-256')
        ->and($noConfirm)->toBe(1)->and($noConfirmOut)->toContain('--confirm-database');
});

test('AB-5: the guard messages never print the configured database password or host', function () {
    $password = 'MysqlPw-'.bin2hex(random_bytes(4));
    config(['database.connections.mysql.password' => $password, 'database.connections.mysql.host' => 'db.internal.example']);
    $outputs = '';

    foreach ([['--dry-run' => true], []] as $options) {
        [, $out] = e31_run('db:transfer-sqlite-to-mysql', ['--source' => e31_good_source()] + $options);
        $outputs .= $out;
    }

    expect($outputs)->not->toContain($password)->and($outputs)->not->toContain('db.internal.example');
});

// ───────────────────────────── حقائق الخادم ─────────────────────────────

function e31_facts(array $over = []): array
{
    return $over + [
        'driver' => 'mysql', 'flavor' => 'mysql', 'version_number' => '8.0.36', 'version_major_minor' => '8.0', 'strict' => true,
        'db_charset' => 'utf8mb4', 'non_innodb_tables' => 0, 'non_utf8mb4_tables' => 0, 'explicit_defaults_for_timestamp' => true,
        'time_zone' => '+00:00', 'max_allowed_packet' => 67108864,
    ];
}

function e31_levels(array $facts): array
{
    return collect((new DatabaseFactsEvaluator)->evaluate($facts))->mapWithKeys(fn (array $r) => [$r['key'] => $r['level']])->all();
}

test('AC-1: server facts are evaluated - a healthy MySQL 8 passes everything', function () {
    expect(array_values(array_unique(e31_levels(e31_facts()))))->toBe(['pass'])
        ->and(e31_levels(['driver' => 'sqlite']))->toBe([]);
});

test('AC-2: old MySQL, non-strict mode, a non-utf8mb4 charset and non-InnoDB tables are production failures', function () {
    expect(e31_levels(e31_facts(['version_number' => '8.0.15', 'version_major_minor' => '8.0']))['DB_VERSION'])->toBe('prodfail')
        ->and(e31_levels(e31_facts(['version_number' => '5.7.44']))['DB_VERSION'])->toBe('prodfail')
        ->and(e31_levels(e31_facts(['version_number' => '8.0.16']))['DB_VERSION'])->toBe('pass')
        ->and(e31_levels(e31_facts(['version_number' => '8.4.2']))['DB_VERSION'])->toBe('pass')
        ->and(e31_levels(e31_facts(['strict' => false]))['DB_STRICT'])->toBe('prodfail')
        ->and(e31_levels(e31_facts(['db_charset' => 'latin1']))['DB_CHARSET'])->toBe('prodfail')
        ->and(e31_levels(e31_facts(['non_innodb_tables' => 2]))['DB_ENGINE'])->toBe('prodfail');
});

test('AC-3: MariaDB is best-effort (warning, never a pass); timezone, timestamp defaults and packet size warn', function () {
    expect(e31_levels(e31_facts(['driver' => 'mariadb', 'flavor' => 'mariadb', 'version_number' => '10.11.6']))['DB_VERSION'])->toBe('warn')
        ->and(e31_levels(e31_facts(['driver' => 'mariadb', 'flavor' => 'mariadb', 'version_number' => '10.4.0']))['DB_VERSION'])->toBe('prodfail')
        ->and(e31_levels(e31_facts(['time_zone' => '+03:00']))['DB_TIMEZONE'])->toBe('warn')
        ->and(e31_levels(e31_facts(['explicit_defaults_for_timestamp' => false]))['DB_TIMESTAMP'])->toBe('warn')
        ->and(e31_levels(e31_facts(['max_allowed_packet' => 1048576]))['DB_PACKET'])->toBe('warn')
        ->and(e31_levels(e31_facts(['non_utf8mb4_tables' => 3]))['DB_COLLATION'])->toBe('warn');
});

// ───────────────────────────── production:check و/ready ─────────────────────────────

test('AD-1: SQLite fails production:check in production with an explicit message - and the override downgrades it to a warning', function () {
    e25_as_production();
    config(['app.debug' => false, 'app.url' => 'https://ahjiyat.example.com', 'operations.allow_sqlite_production' => false]);
    [$code, $data] = e25_run('production:check');
    $db = collect($data['checks'])->firstWhere(fn (array $c) => $c['setting'] === 'DB_CONNECTION' && $c['status'] === 'FAIL');

    expect($code)->toBe(1)
        ->and($db)->not->toBeNull()
        ->and($db['message'])->toContain('SQLite')->and($db['message'])->toContain('MySQL');

    config(['operations.allow_sqlite_production' => true]);
    [, $data] = e25_run('production:check');

    expect(e25_statuses($data, 'DB_CONNECTION'))->not->toContain('FAIL')->and(e25_statuses($data, 'DB_CONNECTION'))->toContain('WARN');
});

test('AD-2: the production check output carries no database name, host or file path', function () {
    e25_as_production();
    config(['app.debug' => false, 'database.connections.sqlite.database' => '/srv/secret-place/ahjiyat-private.sqlite']);
    [, , $raw] = e25_run('production:check');

    expect($raw)->not->toContain('secret-place')->and($raw)->not->toContain('ahjiyat-private');
});

test('AD-3: /ready answers on the current driver without exposing the driver, host, database or schema', function () {
    $response = $this->getJson('/ready');
    $body = $response->getContent();

    $response->assertOk();
    expect($body)->not->toContain('sqlite')->and($body)->not->toContain('mysql')->and($body)->not->toContain('information_schema')
        ->and($body)->not->toContain(database_path())
        ->and($body)->not->toContain((string) config('database.connections.'.config('database.default').'.database'));
});

// ───────────────────────────── قواعد المحلّل (تركيب صناعي) ─────────────────────────────

function e31_lint(array $sql): array
{
    $statements = array_map(fn (string $s, int $i) => ['migration' => "synthetic_{$i}", 'sql' => $s], $sql, array_keys($sql));
    $out = (new MysqlDdlLinter)->analyze($statements);

    return collect($out['findings'])->where('level', 'fail')->pluck('code')->unique()->values()->all();
}

test('AE-1: the linter detects MySQL-hostile DDL (long identifiers, float money, LOB defaults, LOB indexes, FK type mismatch, missing parent)', function () {
    $long = str_repeat('a', 65);
    $parent = 'create table `parents` (`id` bigint unsigned not null auto_increment primary key) default character set utf8mb4 collate utf8mb4_unicode_ci engine = InnoDB';
    $fk = fn (string $parentTable = 'parents', string $tail = '') => "alter table `kids` add constraint `kids_p` foreign key (`parent_id`) references `{$parentTable}` (`id`){$tail}";

    expect(e31_lint(["create table `{$long}` (`id` int)"]))->toContain('identifier_too_long')
        ->and(e31_lint([$parent, "alter table `parents` add index `{$long}`(`id`)"]))->toContain('identifier_too_long')
        ->and(e31_lint(['create table `payments` (`id` int, `price` double not null)']))->toContain('float_money_column')
        ->and(e31_lint(["create table `blobs` (`id` int, `body` text not null default 'x')"]))->toContain('lob_literal_default')
        ->and(e31_lint(['create table `blobs` (`id` int, `body` text)', 'alter table `blobs` add index `blobs_body_index`(`body`)']))->toContain('index_on_lob')
        ->and(e31_lint([$parent, 'create table `kids` (`id` int, `parent_id` int not null)', $fk()]))->toContain('fk_type_mismatch')
        ->and(e31_lint(['create table `kids` (`id` int, `parent_id` bigint unsigned not null)', $fk('ghosts')]))->toContain('fk_target_missing')
        ->and(e31_lint([$parent, 'create table `kids` (`id` int, `parent_id` bigint unsigned not null)', $fk('parents', ' on delete set null')]))->toContain('fk_set_null_not_null');
});

test('AE-2: the linter detects error 1553 (dropping the only index a foreign key relies on) and passes clean DDL', function () {
    $parent = 'create table `parents` (`id` bigint unsigned not null auto_increment primary key) default character set utf8mb4 engine = InnoDB';
    $child = 'create table `kids` (`id` bigint unsigned not null auto_increment primary key, `parent_id` bigint unsigned not null, `slot` int not null) default character set utf8mb4 engine = InnoDB';
    $fk = 'alter table `kids` add constraint `kids_p` foreign key (`parent_id`) references `parents` (`id`)';
    $unique = 'alter table `kids` add unique `kids_parent_slot_unique`(`parent_id`, `slot`)';

    expect(e31_lint([$parent, $child, $fk]))->toBe([])
        ->and(e31_lint([$parent, $child, $fk, $unique]))->toBe([])
        ->and(e31_lint([$parent, $child, $fk, $unique, 'alter table `kids` drop index `kids_parent_slot_unique`']))->toContain('index_needed_by_fk')
        ->and(e31_lint([$parent, $child, $unique, $fk, 'alter table `kids` drop index `kids_parent_slot_unique`']))->toContain('index_needed_by_fk');
});
