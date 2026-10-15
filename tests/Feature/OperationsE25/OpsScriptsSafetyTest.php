<?php

require_once __DIR__.'/_support.php';

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * E25 (13, 17) - سكربتات النشر/النسخ/الاستعادة: لا أوامر هدّامة، وترفض أهداف الإنتاج الخطرة، وتعمل بالـ dry-run دون تنفيذ شيء.
 * اختبارات bash الفعلية تعمل بأوامر وهمية (stubs) في PATH تسجّل استدعاءاتها فقط، فلا يلمس الاختبار قاعدة بيانات ولا شبكة ولا الملفات الحقيقية.
 * تُتخطّى على ويندوز/XAMPP (لا bash)؛ الفحوص الساكنة تعمل في كل مكان.
 */
function e25_script_paths(): array
{
    return array_merge(
        glob(base_path('tools/deploy/*.sh')) ?: [],
        glob(base_path('tools/backup/*.sh')) ?: [],
    );
}

/** أسطر الأوامر الفعلية فقط: بلا تعليقات ولا نصوص الشرح (step/echo) ولا الأسطر الفارغة. */
function e25_script_commands(string $path): array
{
    return collect(preg_split('/\r\n|\r|\n/', (string) file_get_contents($path)))
        ->map(fn (string $l) => trim($l))
        ->reject(fn (string $l) => $l === '' || str_starts_with($l, '#') || str_starts_with($l, 'step ') || str_starts_with($l, 'echo ') || str_starts_with($l, 'refuse ') || str_starts_with($l, 'die '))
        ->values()
        ->all();
}

/** يجهّز مجلد أوامر وهمية ويعيد [dir, logFile]. */
function e25_make_stubs(array $commands): array
{
    $dir = sys_get_temp_dir().'/e25-stubs-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($dir);
    $log = $dir.'/calls.log';
    touch($log);

    foreach ($commands as $name) {
        file_put_contents("{$dir}/{$name}", "#!/usr/bin/env bash\necho \"{$name} \$*\" >> \"{$dir}/calls.log\"\nexit 0\n");
        chmod("{$dir}/{$name}", 0755);
    }

    return [$dir, $log];
}

/** @return array{0: int, 1: string} */
function e25_bash(string $script, array $args = [], array $env = [], ?string $stubDir = null): array
{
    $base = [
        // نُفرغ متغيرات قد ترثها العملية من PHPUnit كي لا تؤثر على حُرّاس السكربتات
        'APP_ENV' => false, 'DB_DATABASE' => false, 'DB_HOST' => false, 'DB_CONNECTION' => false,
        'RESTORE_DB_ENGINE' => false, 'RESTORE_DB_DATABASE' => false, 'RESTORE_DB_HOST' => false, 'RESTORE_CONFIRM_DATABASE' => false,
        'RESTORE_FILES_TARGET' => false, 'RESTORE_CONFIRM_TARGET' => false, 'BACKUP_DIR' => false,
        'BACKUP_AGE_RECIPIENT' => false, 'BACKUP_GPG_RECIPIENT' => false, 'BACKUP_UPLOAD_CMD' => false, 'MAINTENANCE_SECRET' => false,
        'APP_RELEASE' => false, 'RELEASE_SHA' => false, 'DEPLOY_RELOAD_CMD' => false, 'DEPLOY_SMOKE_URL' => false, 'DEPLOY_BACKUP_CONFIRMED' => false,
        'APP_KEY' => 'base64:'.base64_encode(str_repeat('k', 32)),
    ];

    if ($stubDir !== null) {
        $base['PATH'] = $stubDir.':'.getenv('PATH');
    }

    $result = Process::path(base_path())->env(array_merge($base, $env))->timeout(60)->run(array_merge(['bash', $script], $args));

    return [$result->exitCode(), $result->output().$result->errorOutput()];
}

beforeEach(function () {
    $this->e25Cleanup = [];
});

afterEach(function () {
    foreach ($this->e25Cleanup as $dir) {
        File::deleteDirectory($dir);
    }
});

// ───────────────────────── فحوص ساكنة (تعمل في كل مكان) ─────────────────────────

test('E25-17: deployment and backup scripts contain no destructive or state-rewriting command', function () {
    $forbidden = '/(migrate:(fresh|refresh|reset|rollback)|db:wipe|db:seed|\bDROP\s+(DATABASE|SCHEMA|TABLE)\b|\bTRUNCATE\b|\bDELETE\s+FROM\b|\brm\s+-[a-z]*r|\brm\s+-rf|\bgit\s+\w+|composer\s+update|npm\s+update|npm\s+install\b|chmod\s+(-R\s+)?7[0-9][0-9]|mkfs|\bdd\s+if=|--force-recreate|key:generate|\bsudo\b|curl[^|]*\|\s*(ba)?sh)/i';

    foreach (e25_script_paths() as $path) {
        foreach (e25_script_commands($path) as $line) {
            expect(preg_match($forbidden, $line, $m))->toBe(0, basename($path).": forbidden command «{$line}»");
        }
    }

    expect(e25_script_paths())->not->toBeEmpty();
});

test('E25-17: the only artisan migration command a script may run is a forward-only "migrate --force"', function () {
    foreach (e25_script_paths() as $path) {
        foreach (e25_script_commands($path) as $line) {
            if (preg_match('/artisan\s+migrate\b/', $line) === 1) {
                expect($line)->toBe('run php artisan migrate --force');
            }
        }
    }

    expect(array_filter(e25_script_commands(base_path('tools/deploy/deploy.sh')), fn ($l) => str_contains($l, 'migrate')))->toHaveCount(1);
});

test('E25-17: scripts never use git, hold no inline credentials, and take secrets only from the environment', function () {
    foreach (e25_script_paths() as $path) {
        $source = (string) file_get_contents($path);
        $commands = implode("\n", e25_script_commands($path));

        expect(preg_match('/\bgit\s/', $commands))->toBe(0, basename($path).' must not call git')
            ->and(preg_match('/(password|passwd|secret|token)=[^\s"$\'<]+/i', $commands))->toBe(0, basename($path).' embeds a credential')
            ->and(preg_match('/--password[= ]|(?<![\w-])-p[A-Za-z0-9]{3,}\b/', $commands))->toBe(0, basename($path).' passes a password on the command line')
            ->and($source)->toContain('set -Eeuo pipefail');
    }
});

test('E25-17: every shell script parses (bash -n) and starts with a shebang', function () {
    foreach (e25_script_paths() as $path) {
        expect(str_starts_with((string) file_get_contents($path), '#!/usr/bin/env bash'))->toBeTrue(basename($path));

        if (e25_can_run_bash()) {
            $r = Process::run(['bash', '-n', $path]);
            expect($r->successful())->toBeTrue(basename($path).': '.$r->errorOutput());
        }
    }
});

test('E25-17: the repository wires a destructive-command ban for production (Laravel prohibitDestructiveCommands)', function () {
    $this->app['env'] = 'production';
    config(['operations.allow_destructive_commands' => false]);

    try {
        $provider = $this->app->getProvider(App\Providers\AppServiceProvider::class);
        (new ReflectionMethod($provider, 'registerOperationalGuards'))->invoke($provider);

        foreach ([Illuminate\Database\Console\WipeCommand::class, Illuminate\Database\Console\Migrations\FreshCommand::class, Illuminate\Database\Console\Migrations\RefreshCommand::class, Illuminate\Database\Console\Migrations\ResetCommand::class, Illuminate\Database\Console\Migrations\RollbackCommand::class] as $command) {
            $property = new ReflectionProperty($command, 'prohibitedFromRunning');
            expect($property->getValue())->toBeTrue("{$command} must be prohibited in production");
        }

        // الاستثناء الواعي لعملية واحدة يرفع الحظر
        config(['operations.allow_destructive_commands' => true]);
        (new ReflectionMethod($provider, 'registerOperationalGuards'))->invoke($provider);
        expect((new ReflectionProperty(Illuminate\Database\Console\WipeCommand::class, 'prohibitedFromRunning'))->getValue())->toBeFalse();
    } finally {
        $this->app['env'] = 'testing';
        config(['operations.allow_destructive_commands' => false]);
        Illuminate\Support\Facades\DB::prohibitDestructiveCommands(false);
    }
});

// ───────────────────────── تشغيل bash فعلي بأوامر وهمية ─────────────────────────

test('E25-13: db-restore refuses an APP_ENV=production environment before touching any database client', function () {
    if (! e25_can_run_bash()) {
        $this->markTestSkipped('bash غير متاح (ويندوز)');
    }

    [$stubs, $log] = e25_make_stubs(['mysql', 'psql', 'mysqldump']);
    $this->e25Cleanup[] = $stubs;
    $backup = $stubs.'/db.sql.gz';
    touch($backup);

    [$code, $out] = e25_bash('tools/backup/db-restore.sh', [$backup], ['APP_ENV' => 'production', 'RESTORE_DB_ENGINE' => 'mysql', 'RESTORE_DB_DATABASE' => 'drill_restore_target'], $stubs);

    expect($code)->toBe(3)->and($out)->toContain('رُفضت الاستعادة')
        ->and(file_get_contents($log))->toBe('');
});

test('E25-13: db-restore refuses targets that look like production or equal the live database', function (array $env) {
    if (! e25_can_run_bash()) {
        $this->markTestSkipped('bash غير متاح (ويندوز)');
    }

    [$stubs, $log] = e25_make_stubs(['mysql', 'psql']);
    $this->e25Cleanup[] = $stubs;
    $backup = $stubs.'/db.sql.gz';
    touch($backup);

    [$code, $out] = e25_bash('tools/backup/db-restore.sh', [$backup], array_merge(['RESTORE_DB_ENGINE' => 'mysql'], $env), $stubs);

    expect($code)->toBe(3)->and(file_get_contents($log))->toBe('');
})->with([
    'name says prod' => [['RESTORE_DB_DATABASE' => 'ahjiyat_prod']],
    'name says production' => [['RESTORE_DB_DATABASE' => 'production']],
    'name says live' => [['RESTORE_DB_DATABASE' => 'live_db']],
    'same as live database on same host' => [['RESTORE_DB_DATABASE' => 'ahjiyat', 'RESTORE_DB_HOST' => 'db1.internal', 'DB_DATABASE' => 'ahjiyat', 'DB_HOST' => 'db1.internal']],
    'same as live database, both local' => [['RESTORE_DB_DATABASE' => 'ahjiyat', 'RESTORE_DB_HOST' => '127.0.0.1', 'DB_DATABASE' => 'ahjiyat', 'DB_HOST' => 'localhost']],
]);

test('E25-13: overriding the production guard needs --allow-production AND an exact database-name confirmation', function () {
    if (! e25_can_run_bash()) {
        $this->markTestSkipped('bash غير متاح (ويندوز)');
    }

    [$stubs, $log] = e25_make_stubs(['mysql', 'psql']);
    $this->e25Cleanup[] = $stubs;
    $backup = $stubs.'/db.sql.gz';
    touch($backup);
    $env = ['RESTORE_DB_ENGINE' => 'mysql', 'RESTORE_DB_DATABASE' => 'ahjiyat_prod'];

    // بلا --allow-production
    [$code] = e25_bash('tools/backup/db-restore.sh', ['--dry-run', $backup], $env, $stubs);
    expect($code)->toBe(3);

    // --allow-production بلا تأكيد
    [$code, $out] = e25_bash('tools/backup/db-restore.sh', ['--dry-run', '--allow-production', $backup], $env, $stubs);
    expect($code)->toBe(3)->and($out)->toContain('RESTORE_CONFIRM_DATABASE');

    // تأكيد غير مطابق
    [$code] = e25_bash('tools/backup/db-restore.sh', ['--dry-run', '--allow-production', $backup], $env + ['RESTORE_CONFIRM_DATABASE' => 'ahjiyat'], $stubs);
    expect($code)->toBe(3);

    // تأكيد مطابق تمامًا فقط يعبر الحارس (dry-run: لا تنفيذ)
    [$code, $out] = e25_bash('tools/backup/db-restore.sh', ['--dry-run', '--allow-production', $backup], $env + ['RESTORE_CONFIRM_DATABASE' => 'ahjiyat_prod'], $stubs);
    expect($code)->toBe(0)->and($out)->toContain('[dry-run]')->and(file_get_contents($log))->toBe('');
});

test('E25-13: db-restore into a clean non-production target passes the guards in dry-run and runs nothing', function () {
    if (! e25_can_run_bash()) {
        $this->markTestSkipped('bash غير متاح (ويندوز)');
    }

    [$stubs, $log] = e25_make_stubs(['mysql', 'psql']);
    $this->e25Cleanup[] = $stubs;
    $backup = $stubs.'/db.sql.gz';
    touch($backup);

    [$code, $out] = e25_bash('tools/backup/db-restore.sh', ['--dry-run', $backup], ['RESTORE_DB_ENGINE' => 'mysql', 'RESTORE_DB_DATABASE' => 'restore_drill_2026q4', 'DB_DATABASE' => 'ahjiyat', 'DB_HOST' => 'db1.internal'], $stubs);

    expect($code)->toBe(0)->and($out)->toContain('[dry-run]')->and(file_get_contents($log))->toBe('');
});

test('E25-13: db-restore rejects bad input (no file, unknown engine, unsafe database name, unknown flag)', function () {
    if (! e25_can_run_bash()) {
        $this->markTestSkipped('bash غير متاح (ويندوز)');
    }

    [$stubs] = e25_make_stubs(['mysql']);
    $this->e25Cleanup[] = $stubs;
    $backup = $stubs.'/db.sql.gz';
    touch($backup);

    expect(e25_bash('tools/backup/db-restore.sh', [], [], $stubs)[0])->toBe(2)
        ->and(e25_bash('tools/backup/db-restore.sh', [$backup], ['RESTORE_DB_ENGINE' => 'oracle', 'RESTORE_DB_DATABASE' => 'x'], $stubs)[0])->toBe(2)
        ->and(e25_bash('tools/backup/db-restore.sh', [$backup], ['RESTORE_DB_ENGINE' => 'mysql', 'RESTORE_DB_DATABASE' => 'x; DROP DATABASE y'], $stubs)[0])->toBe(2)
        ->and(e25_bash('tools/backup/db-restore.sh', ['--drop-everything', $backup], ['RESTORE_DB_ENGINE' => 'mysql', 'RESTORE_DB_DATABASE' => 'x'], $stubs)[0])->toBe(2);
});

test('E25-13: files-restore refuses production, the live storage folder and non-empty targets', function () {
    if (! e25_can_run_bash()) {
        $this->markTestSkipped('bash غير متاح (ويندوز)');
    }

    [$stubs] = e25_make_stubs([]);
    $this->e25Cleanup[] = $stubs;
    $backup = $stubs.'/files.tar.gz';
    touch($backup);
    $nonEmpty = $stubs.'/occupied';
    File::ensureDirectoryExists($nonEmpty);
    file_put_contents($nonEmpty.'/existing.txt', 'x');
    $empty = $stubs.'/empty-target';

    expect(e25_bash('tools/backup/files-restore.sh', ['--dry-run', $backup], ['RESTORE_FILES_TARGET' => $empty, 'APP_ENV' => 'production'])[0])->toBe(3)
        ->and(e25_bash('tools/backup/files-restore.sh', ['--dry-run', $backup], ['RESTORE_FILES_TARGET' => base_path('storage/app/public')])[0])->toBe(3)
        ->and(e25_bash('tools/backup/files-restore.sh', ['--dry-run', $backup], ['RESTORE_FILES_TARGET' => base_path('storage/app/public/uploads')])[0])->toBe(3)
        ->and(e25_bash('tools/backup/files-restore.sh', ['--dry-run', $backup], ['RESTORE_FILES_TARGET' => $nonEmpty])[0])->toBe(3);

    [$code, $out] = e25_bash('tools/backup/files-restore.sh', ['--dry-run', $backup], ['RESTORE_FILES_TARGET' => $empty]);
    expect($code)->toBe(0)->and($out)->toContain('[dry-run]')->and(is_dir($empty))->toBeFalse();
});

test('E25-13: backup scripts refuse unencrypted output and any output inside public/', function () {
    if (! e25_can_run_bash()) {
        $this->markTestSkipped('bash غير متاح (ويندوز)');
    }

    [$stubs, $log] = e25_make_stubs(['mysqldump', 'pg_dump', 'tar', 'gzip']);
    $this->e25Cleanup[] = $stubs;
    $out = $stubs.'/out';
    File::ensureDirectoryExists($out);
    $env = ['BACKUP_DIR' => $out, 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'ahjiyat'];

    // بلا تشفير => رفض (exit 3) قبل أي dump
    expect(e25_bash('tools/backup/db-backup.sh', ['--dry-run'], $env, $stubs)[0])->toBe(3)
        ->and(e25_bash('tools/backup/files-backup.sh', ['--dry-run'], ['BACKUP_DIR' => $out], $stubs)[0])->toBe(3);

    // داخل public/ => رفض حتى مع التشفير
    $inPublic = ['BACKUP_DIR' => base_path('public/backups'), 'BACKUP_AGE_RECIPIENT' => 'age1examplepublicrecipient'] + $env;
    expect(e25_bash('tools/backup/db-backup.sh', ['--dry-run'], $inPublic, $stubs)[0])->not->toBe(0)
        ->and(e25_bash('tools/backup/files-backup.sh', ['--dry-run'], ['BACKUP_DIR' => base_path('public/backups'), 'BACKUP_AGE_RECIPIENT' => 'age1examplepublicrecipient'], $stubs)[0])->not->toBe(0);

    // اسم قاعدة غير آمن
    expect(e25_bash('tools/backup/db-backup.sh', ['--dry-run'], ['DB_DATABASE' => 'x;rm -rf /', 'BACKUP_AGE_RECIPIENT' => 'age1x'] + $env, $stubs)[0])->not->toBe(0);

    expect(file_get_contents($log))->toBe('');           // لم يُستدعَ dump ولا tar
    expect(File::files($out))->toBe([]);                  // ولم يُكتب شيء
});

test('E25-13: a dry-run backup with encryption configured only prints the plan and writes nothing', function () {
    if (! e25_can_run_bash()) {
        $this->markTestSkipped('bash غير متاح (ويندوز)');
    }

    [$stubs, $log] = e25_make_stubs(['mysqldump', 'pg_dump', 'tar', 'gzip', 'age']);
    $this->e25Cleanup[] = $stubs;
    $out = $stubs.'/out';
    File::ensureDirectoryExists($out);

    [$code, $text] = e25_bash('tools/backup/db-backup.sh', ['--dry-run'], ['BACKUP_DIR' => $out, 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'ahjiyat', 'BACKUP_AGE_RECIPIENT' => 'age1examplepublicrecipient'], $stubs);

    expect($code)->toBe(0)->and($text)->toContain('dry-run')
        ->and(File::files($out))->toBe([])
        ->and(file_get_contents($log))->toBe('');
});

test('E25-17: deploy.sh --dry-run executes nothing, prints the whole sequence and never prints the maintenance secret', function () {
    if (! e25_can_run_bash()) {
        $this->markTestSkipped('bash غير متاح (ويندوز)');
    }

    [$stubs, $log] = e25_make_stubs(['php', 'composer', 'npm', 'git', 'curl', 'mysql']);
    $this->e25Cleanup[] = $stubs;

    [$code, $out] = e25_bash('tools/deploy/deploy.sh', ['--dry-run', '--maintenance', '--release=b983867'], ['MAINTENANCE_SECRET' => 'e25-never-print-me-VALUE', 'DEPLOY_RELOAD_CMD' => 'echo reload-secret-cmd-VALUE'], $stubs);

    expect($code)->toBe(0)
        ->and(file_get_contents($log))->toBe('')                      // dry-run: لا أمر نُفِّذ
        ->and($out)->toContain('release:preflight')->toContain('migrate --force')->toContain('queue:restart')->toContain('release:smoke')->toContain('production:check')
        ->and($out)->not->toContain('e25-never-print-me-VALUE');
});

test('E25-17: deploy.sh refuses to migrate without the backup confirmation and never leaves maintenance mode on because of it', function () {
    if (! e25_can_run_bash()) {
        $this->markTestSkipped('bash غير متاح (ويندوز)');
    }

    [$stubs, $log] = e25_make_stubs(['php', 'composer', 'npm', 'git']);
    $this->e25Cleanup[] = $stubs;

    [$code, $out] = e25_bash('tools/deploy/deploy.sh', ['--skip-build', '--maintenance', '--release=b983867'], [], $stubs);
    $calls = file_get_contents($log);

    expect($code)->toBe(3)
        ->and($out)->toContain('--backup-confirmed')
        ->and($calls)->toContain('artisan release:preflight')     // الفحص للقراءة فقط يجري
        ->and($calls)->not->toContain('artisan down')             // لم ندخل الصيانة
        ->and($calls)->not->toContain('migrate')                  // ولم نرحّل
        ->and($calls)->not->toContain('git');
});

test('E25-17: deploy.sh with a confirmed backup runs the documented order, forward-only, with no git and no destructive command', function () {
    if (! e25_can_run_bash()) {
        $this->markTestSkipped('bash غير متاح (ويندوز)');
    }

    [$stubs, $log] = e25_make_stubs(['php', 'composer', 'npm', 'git']);
    $this->e25Cleanup[] = $stubs;

    [$code, $out] = e25_bash('tools/deploy/deploy.sh', ['--skip-build', '--maintenance', '--backup-confirmed', '--release=b983867'], ['MAINTENANCE_SECRET' => 'e25-never-print-me-VALUE'], $stubs);
    $lines = array_values(array_filter(explode("\n", (string) file_get_contents($log))));
    $position = fn (string $needle) => collect($lines)->search(fn ($l) => str_contains($l, $needle));

    expect($code)->toBe(0)->and($out)->not->toContain('e25-never-print-me-VALUE');

    $order = ['release:preflight', 'artisan down', 'migrate --force', 'permissions:sync', 'optimize', 'queue:restart', 'reverb:restart', 'artisan up', 'release:smoke', 'production:check'];
    $positions = array_map($position, $order);

    expect(in_array(false, $positions, true))->toBeFalse('missing step: '.json_encode(array_combine($order, $positions)))
        ->and($positions)->toBe(collect($positions)->sort()->values()->all());   // الترتيب كما هو موثّق

    foreach ($lines as $line) {
        expect($line)->not->toMatch('/migrate:(fresh|refresh|reset|rollback)|db:wipe|db:seed|^git |composer update|npm update/');
    }

    expect($lines)->toContain('php artisan release:smoke --expect-release=b983867');
});

test('E25-17: deploy.sh rejects an unsafe release id and unknown flags', function () {
    if (! e25_can_run_bash()) {
        $this->markTestSkipped('bash غير متاح (ويندوز)');
    }

    [$stubs, $log] = e25_make_stubs(['php', 'composer', 'npm']);
    $this->e25Cleanup[] = $stubs;

    expect(e25_bash('tools/deploy/deploy.sh', ['--dry-run', '--release=a b;rm -rf /'], [], $stubs)[0])->toBe(2)
        ->and(e25_bash('tools/deploy/deploy.sh', ['--dry-run', '--wipe-everything'], [], $stubs)[0])->toBe(2)
        ->and(file_get_contents($log))->toBe('');
});
