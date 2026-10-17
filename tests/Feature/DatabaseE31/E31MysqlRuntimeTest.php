<?php

use App\Models\Currency;
use App\Models\StoreItem;
use App\Models\StoreItemPrice;
use App\Models\User;
use App\Services\Database\DatabaseFactsEvaluator;
use App\Services\Database\DatabaseRuntimeInspector;
use App\Services\Database\SqliteToMysqlTransfer;
use App\Services\Economy\CurrencyRegistry;
use App\Services\Economy\CurrencyWalletService;
use App\Services\Store\StorePurchaseService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/_support.php';
require_once __DIR__.'/../DiscoveryE27/_support.php';

/*
| E31 - اختبارات تشغيلية على خادم MySQL/MariaDB حقيقي. **تتخطى بوضوح** على SQLite (e31_requires_mysql) ولا تُحتسب دليلًا إلا إن شُغِّلت على MySQL فعلًا:
|   powershell -File tools/e31/run-mysql-matrix.ps1        (أو: php artisan test --configuration=phpunit.mysql.xml.dist tests/Feature/DatabaseE31)
| قاعدة الاختبار تُحرَس بـTestDatabaseGuard (يجب أن ينتهي اسمها بـ_test أو _testing أو _test_mysql). ولا يُلمس سواها إلا قاعدة نقل مؤقتة باسم <test>_xfer_test.
*/

afterAll(fn () => e31_cleanup());

/** اسم قاعدة النقل المؤقتة المشتقة من قاعدة الاختبار، أو null إن لم تكن آمنة. */
function e31_xfer_database(): string
{
    $main = (string) config('database.connections.'.config('database.default').'.database');
    $name = preg_replace('/(_mysql|_mariadb)$/i', '', $main).'_xfer_test';

    return preg_match('/^[A-Za-z0-9_]{1,50}_xfer_test$/', $name) === 1 && $name !== $main ? $name : '';
}

/** يجهّز قاعدة نقل مؤقتة فارغة (يُسقط القديمة) ويسجّل الاتصال e31_xfer. يُرجع الاسم. */
function e31_xfer_create(): string
{
    $database = e31_xfer_database();
    expect($database)->not->toBe('', 'تعذّر اشتقاق اسم آمن لقاعدة النقل المؤقتة');

    $base = (array) config('database.connections.'.config('database.default'));
    config([
        'database.connections.e31_xfer_admin' => array_merge($base, ['database' => null, 'url' => null]),
        'database.connections.e31_xfer' => array_merge($base, ['database' => $database, 'url' => null]),
    ]);
    DB::purge('e31_xfer_admin');
    DB::purge('e31_xfer');

    $admin = DB::connection('e31_xfer_admin');
    $admin->statement("DROP DATABASE IF EXISTS `{$database}`");
    $admin->statement("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    return $database;
}

function e31_xfer_drop(): void
{
    $database = e31_xfer_database();

    if ($database !== '') {
        try {
            DB::purge('e31_xfer');
            DB::connection('e31_xfer_admin')->statement("DROP DATABASE IF EXISTS `{$database}`");
        } catch (Throwable) {
            // best-effort cleanup
        }
    }

    DB::purge('e31_xfer_admin');
}

/** اتصال مستقل جديد (جلسة MySQL منفصلة) بنفس إعداد قاعدة الاختبار، خارج معاملة RefreshDatabase. */
function e31_session(string $name): \Illuminate\Database\Connection
{
    config(['database.connections.'.$name => (array) config('database.connections.'.config('database.default'))]);
    DB::purge($name);

    return DB::connection($name);
}

/** رمز خطأ MySQL من استثناء استعلام (مثل 1452 مفتاح أجنبي، 1205 مهلة قفل)، أو 0. */
function e31_mysql_code(Closure $fn): int
{
    try {
        $fn();
    } catch (\Illuminate\Database\QueryException $e) {
        return (int) ($e->errorInfo[1] ?? 0);
    }

    return 0;
}

// ───────────────────────────── الخادم والمخطط ─────────────────────────────

test('M-1: the server meets the production contract (version, strict mode, utf8mb4, InnoDB, UTC session)', function () {
    e31_requires_mysql();

    $facts = app(DatabaseRuntimeInspector::class)->inspect(null);
    $bad = collect((new DatabaseFactsEvaluator)->evaluate($facts))->whereIn('level', ['fail', 'prodfail'])->pluck('message')->all();

    expect($bad)->toBe([])
        ->and($facts['strict'])->toBeTrue()
        ->and($facts['non_innodb_tables'])->toBe(0)
        ->and(DB::selectOne('select @@session.time_zone as tz')->tz)->toBe('+00:00');
});

test('M-2: the schema built by the migrations on this server matches the model compiled from them (db:parity exits 0)', function () {
    e31_requires_mysql();

    $code = Artisan::call('db:parity', ['--runtime' => true, '--json' => true]);
    $data = json_decode(Artisan::output(), true);

    expect($code)->toBe(0)
        ->and($data['parity']['summary']['material'])->toBe(0)
        ->and($data['parity']['reference'])->toBe('mysql-from-migrations');
});

test('M-3: every table is InnoDB with a utf8mb4 collation', function () {
    e31_requires_mysql();

    $rows = DB::select('select table_name as t, engine as e, table_collation as c from information_schema.tables where table_schema = database() and table_type = ?', ['BASE TABLE']);

    expect(count($rows))->toBeGreaterThan(80)
        ->and(collect($rows)->reject(fn ($r) => strtolower((string) $r->e) === 'innodb')->pluck('t')->all())->toBe([])
        ->and(collect($rows)->reject(fn ($r) => str_starts_with((string) $r->c, 'utf8mb4'))->pluck('t')->all())->toBe([]);
});

// ───────────────────────────── النصوص والأنواع ─────────────────────────────

test('M-4: Arabic with diacritics, emoji (4-byte) and RTL marks round-trip byte-for-byte; over-long values are rejected, not truncated', function () {
    e31_requires_mysql();

    $text = "لُغْزُ الجَوَاهِرِ ‏🎮💎 مرحبا\u{200F} ٣٤٥ — ñ é";
    $user = User::factory()->create(['name' => $text]);

    $row = DB::selectOne('select name, hex(name) as hx from users where id = ?', [$user->id]);

    expect($row->name)->toBe($text)->and(strtolower($row->hx))->toBe(strtolower(bin2hex($text)));

    expect(fn () => User::factory()->create(['name' => str_repeat('م', 300)]))->toThrow(\Illuminate\Database\QueryException::class);
});

test('M-5: a decrement below zero on an UNSIGNED balance fails loudly on MySQL (it is silently allowed on SQLite)', function () {
    e31_requires_mysql();

    $user = User::factory()->create();
    $currency = app(CurrencyRegistry::class)->defaultEarnedCurrency();
    app(CurrencyWalletService::class)->creditAvailable($user, $currency, 5, 'e31_unsigned');

    expect(fn () => DB::table('wallets')->where('user_id', $user->id)->where('currency_id', $currency->id)->decrement('available_balance', 10))
        ->toThrow(\Illuminate\Database\QueryException::class);

    expect((int) DB::table('wallets')->where('user_id', $user->id)->value('available_balance'))->toBe(5);
});

test('M-6: foreign keys are enforced - an orphan insert fails (1452) and a RESTRICT parent cannot be deleted (1451)', function () {
    e31_requires_mysql();

    $currency = app(CurrencyRegistry::class)->defaultEarnedCurrency();
    $now = now()->toDateTimeString();

    expect(e31_mysql_code(fn () => DB::table('wallets')->insert(['user_id' => 987654321, 'currency_id' => $currency->id, 'created_at' => $now, 'updated_at' => $now])))->toBe(1452);

    $user = User::factory()->create();
    app(CurrencyWalletService::class)->creditAvailable($user, $currency, 5, 'e31_fk');

    expect(e31_mysql_code(fn () => DB::table('currencies')->where('id', $currency->id)->delete()))->toBe(1451);
});

test('M-7: unique indexes follow the server collation (utf8mb4_unicode_ci is case-insensitive) - the documented behaviour that the transfer pre-flight guards against', function () {
    e31_requires_mysql();

    User::factory()->create(['email' => 'Collation.Probe@example.test']);

    expect(fn () => User::factory()->create(['email' => 'collation.probe@example.test']))->toThrow(\Illuminate\Database\UniqueConstraintViolationException::class);
});

test('M-8: a store purchase snapshot (JSON column) keeps Arabic and emoji intact and balances come back as integers', function () {
    e31_requires_mysql();

    $user = User::factory()->create();
    $item = StoreItem::factory()->create(['name' => 'عنصر الجوهرة 💎', 'fulfillment_type' => StoreItem::FULFILLMENT_INVENTORY, 'grant_quantity' => 1]);
    $price = StoreItemPrice::factory()->create(['store_item_id' => $item->id, 'amount' => 25]);
    $wallets = app(CurrencyWalletService::class);
    $wallets->creditAvailable($user, $price->currency, 100, 'e31_json');

    $purchase = app(StorePurchaseService::class)->purchase($user, $item, $price, (string) Str::uuid());

    expect($purchase->fresh()->item_snapshot['name'])->toBe('عنصر الجوهرة 💎')
        ->and($wallets->balanceFor($user, $price->currency)->available_balance)->toBe(75)
        ->and($purchase->fresh()->price_amount)->toBeInt();
});

// ───────────────────────────── الأقفال والتزامن (اتصالان حقيقيان) ─────────────────────────────

test('M-9: a second connection holding a row lock makes a competing FOR UPDATE time out with error 1205 - not hang and not corrupt', function () {
    e31_requires_mysql();

    $id = (int) DB::table('currencies')->orderBy('id')->value('id');
    expect($id)->toBeGreaterThan(0);

    $holder = e31_session('e31_lock_holder');
    $waiter = e31_session('e31_lock_waiter');
    $holder->beginTransaction();

    try {
        $holder->select('select id from currencies where id = ? for update', [$id]);
        $waiter->statement('set session innodb_lock_wait_timeout = 1');

        $started = microtime(true);
        $code = e31_mysql_code(fn () => $waiter->select('select id from currencies where id = ? for update', [$id]));

        expect($code)->toBe(1205)->and(microtime(true) - $started)->toBeLessThan(10.0);
    } finally {
        $holder->rollBack();
        DB::purge('e31_lock_holder');
        DB::purge('e31_lock_waiter');
    }
});

test('M-10: two real sessions racing on the same unique key - the second waits for the first (1205), then reports a duplicate (1062) once the first commits; never two rows', function () {
    e31_requires_mysql();

    $table = 'e31_lock_probe_'.getmypid();
    $first = e31_session('e31_race_first');
    $second = e31_session('e31_race_second');
    $first->statement("create table `{$table}` (id int unsigned not null auto_increment primary key, k varchar(20) not null, unique key `{$table}_k` (k)) engine=InnoDB default charset=utf8mb4");

    try {
        $first->beginTransaction();
        $first->insert("insert into `{$table}` (k) values ('same')");
        $second->statement('set session innodb_lock_wait_timeout = 1');

        $whileOpen = e31_mysql_code(fn () => $second->insert("insert into `{$table}` (k) values ('same')"));
        $first->commit();
        $afterCommit = e31_mysql_code(fn () => $second->insert("insert into `{$table}` (k) values ('same')"));

        expect($whileOpen)->toBe(1205)->and($afterCommit)->toBe(1062)->and((int) $first->table($table)->count())->toBe(1);
    } finally {
        $first->statement("drop table if exists `{$table}`");
        DB::purge('e31_race_first');
        DB::purge('e31_race_second');
    }
});

// ───────────────────────────── البحث (E27) ─────────────────────────────

test('M-11: puzzle search on MySQL finds Latin text regardless of case and Arabic by prefix, and still hides inactive puzzles', function () {
    e31_requires_mysql();
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

    e27_puzzle('Gem Hunter Challenge');
    e27_puzzle('أحجية الجواهر الكبرى');
    e27_puzzle('Gem Hidden', ['is_active' => false]);

    expect(e27_titles(e27_suggest($this, 'GEM hunt')))->toContain('Gem Hunter Challenge')
        ->and(e27_titles(e27_suggest($this, 'gem')))->not->toContain('Gem Hidden')
        ->and(e27_titles(e27_suggest($this, 'أحجية')))->toContain('أحجية الجواهر الكبرى');
});

// ───────────────────────────── مخطط من الصفر + نقل حقيقي إلى MySQL ─────────────────────────────

test('M-12: a brand-new MySQL database builds from zero with `migrate`, matches the model, receives the SQLite data through the transfer service, and validates PASS', function () {
    e31_requires_mysql();

    $src = e31_new_sqlite('xsrc');
    e31_seed_world('e31_xsrc');
    DB::purge('e31_xsrc');
    $database = e31_xfer_create();

    try {
        // 1) ترحيل من الصفر على MySQL حقيقية
        expect(Artisan::call('migrate', ['--database' => 'e31_xfer', '--force' => true]))->toBe(0);

        // 2) المخطط الناتج = نموذج MySQL المحسوب من الترحيلات
        expect(Artisan::call('db:parity', ['--connection' => 'e31_xfer', '--json' => true]))->toBe(0);

        // 3) نقل البيانات والتحقق
        $service = new SqliteToMysqlTransfer((array) config('db_transfer'));
        $service->connect($src, 'e31_xfer');
        $plan = $service->plan();
        expect($plan['problems'])->toBe([])->and($plan['ok'])->toBeTrue();

        $service->execute($plan);
        $report = $service->validate($plan);
        $t = $report['totals'];

        expect($report['result'])->toBe('PASS')
            ->and([$t['row_count_mismatches'], $t['integrity_violations'], $t['fingerprint_mismatches'], $t['auto_increment_issues'], $t['financial_mismatches']])->toBe([0, 0, 0, 0, 0]);

        // 4) محتوى عربي/إيموجي سليم بعد النقل، والمعرّفات محفوظة
        $names = $service->target()->table('users')->orderBy('id')->pluck('name')->all();
        expect($names)->toContain('لاعب عربي 🎮 ألِف')
            ->and($service->target()->table('users')->orderBy('id')->pluck('id')->all())->toBe($service->source()->table('users')->orderBy('id')->pluck('id')->all());

        // 5) AUTO_INCREMENT صحيح: الإدراج التالي لا يصطدم بمعرّف منقول
        $next = $service->target()->table('users')->insertGetId(['name' => 'بعد النقل', 'email' => 'after-transfer@example.test', 'password' => 'x', 'created_at' => now(), 'updated_at' => now()]);
        expect($next)->toBeGreaterThan((int) $service->source()->table('users')->max('id'));

        $service->disconnect();
    } finally {
        e31_xfer_drop();
    }
});

test('M-13: the db:transfer-sqlite-to-mysql command runs end to end against a real MySQL (dry-run, refusal of a populated target, real run with a verified backup)', function () {
    e31_requires_mysql();

    $src = e31_new_sqlite('csrc');
    e31_seed_world('e31_csrc');
    DB::purge('e31_csrc');
    $backup = e31_tmp_dir().DIRECTORY_SEPARATOR.'backup-'.bin2hex(random_bytes(3)).'.sqlite';
    copy($src, $backup);
    $before = hash_file('sha256', $src);
    e31_xfer_create();

    try {
        expect(Artisan::call('migrate', ['--database' => 'e31_xfer', '--force' => true]))->toBe(0);

        $dry = Artisan::call('db:transfer-sqlite-to-mysql', ['--source' => $src, '--target' => 'e31_xfer', '--dry-run' => true]);
        expect($dry)->toBe(0)->and(DB::connection('e31_xfer')->table('users')->count())->toBe(0);

        $real = Artisan::call('db:transfer-sqlite-to-mysql', ['--source' => $src, '--target' => 'e31_xfer', '--force' => true, '--backup' => $backup]);
        $output = Artisan::output();
        expect($real)->toBe(0)->and($output)->toContain('PASS')->and(hash_file('sha256', $src))->toBe($before);

        // الهدف صار ممتلئًا: تشغيل ثانٍ بلا --reset-target يُرفض ولا يدمج
        $again = Artisan::call('db:transfer-sqlite-to-mysql', ['--source' => $src, '--target' => 'e31_xfer', '--force' => true, '--backup' => $backup]);
        expect($again)->toBe(1);
    } finally {
        e31_xfer_drop();
    }
});
