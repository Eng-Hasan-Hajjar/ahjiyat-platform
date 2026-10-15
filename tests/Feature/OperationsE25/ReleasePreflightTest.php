<?php

require_once __DIR__.'/_support.php';

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * E25 (14-15) - release:preflight يبلّغ عن الترحيلات المعلّقة (وخطرها) دون تنفيذ أي شيء، وهو للقراءة فقط بالكامل.
 */
beforeEach(function () {
    $this->e25Dir = sys_get_temp_dir().'/e25-pre-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->e25Dir);
});

afterEach(function () {
    File::deleteDirectory($this->e25Dir);
});

function e25_write_pending_migration(string $dir, string $name, string $upBody): void
{
    file_put_contents($dir.'/'.$name.'.php', <<<PHP
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        {$upBody}
    }

    public function down(): void {}
};
PHP);
}

test('E25-14: preflight lists pending migrations and flags breaking operations for human review', function () {
    e25_write_pending_migration($this->e25Dir, '2099_01_01_000000_e25_probe_pending', <<<'BODY'
        Schema::create('e25_probe_table', function (Blueprint $t) { $t->id(); });
        Schema::table('users', function (Blueprint $t) { $t->dropColumn('e25_nonexistent'); });
BODY);
    $this->app['migrator']->path($this->e25Dir);
    config(['app.debug' => false]);

    [, $data] = e25_run('release:preflight', ['--skip-views' => true]);
    $messages = collect($data['checks'])->where('setting', '2099_01_01_000000_e25_probe_pending');
    $summary = collect($data['checks'])->where('setting', 'migrations')->pluck('message')->implode(' | ');

    expect($summary)->toContain('2099_01_01_000000_e25_probe_pending')
        ->and($messages)->not->toBeEmpty()
        ->and($messages->pluck('status')->all())->toContain('WARN')                 // dropColumn = تغيير هدّام
        ->and(collect($data['checks'])->where('setting', 'backup')->pluck('status')->all())->toContain('WARN');
});

test('E25-14: preflight also reports a pending additive migration without calling it breaking', function () {
    e25_write_pending_migration($this->e25Dir, '2099_01_02_000000_e25_probe_additive', <<<'BODY'
        Schema::create('e25_probe_additive', function (Blueprint $t) { $t->id(); $t->string('name')->nullable(); });
BODY);
    $this->app['migrator']->path($this->e25Dir);
    config(['app.debug' => false]);

    [, $data] = e25_run('release:preflight', ['--skip-views' => true]);

    expect(collect($data['checks'])->where('setting', 'migrations')->pluck('message')->implode(' | '))->toContain('2099_01_02_000000_e25_probe_additive')
        ->and(collect($data['checks'])->where('setting', '2099_01_02_000000_e25_probe_additive')->pluck('status')->all())->not->toContain('WARN');
});

test('E25-14: a database ahead of the code (ran migration without a file) is reported as an orphan', function () {
    DB::table('migrations')->insert(['migration' => '2098_12_31_000000_e25_future_migration', 'batch' => 9999]);
    config(['app.debug' => false]);

    [, $data] = e25_run('production:check');
    $migrations = collect($data['checks'])->where('setting', 'migrations');

    expect($migrations->pluck('message')->implode(' '))->toContain('القاعدة أحدث من الكود')
        ->and($migrations->pluck('status')->all())->toContain('WARN');
});

test('E25-14: a clean, healthy state has no pending migrations and preflight passes (exit 0)', function () {
    config(['app.debug' => false, 'app.url' => 'https://ahjiyat.example.com']);

    [$code, $data] = e25_run('release:preflight', ['--skip-views' => true]);

    expect($code)->toBe(0)
        ->and(collect($data['checks'])->firstWhere('setting', 'migrations')['status'])->toBe('PASS')
        ->and(collect($data['checks'])->where('group', 'ملفات القفل (مصدر الإصدارات)')->pluck('status')->unique()->all())->toBe(['PASS']);
});

test('E25-14: preflight fails (exit 1) when the production contract is broken', function () {
    config(['app.debug' => true]);

    [$code, $data] = e25_run('release:preflight', ['--skip-views' => true]);

    expect($code)->toBe(1)->and($data['ready'])->toBeFalse();
});

test('E25-14: the view compilation check runs in memory and reports the number of templates', function () {
    config(['app.debug' => false, 'app.url' => 'https://ahjiyat.example.com']);

    [$code, $data] = e25_run('release:preflight');

    $views = collect($data['checks'])->firstWhere('setting', 'views');

    expect($views['status'])->toBe('PASS')
        ->and($views['message'])->toMatch('/\d+ قالب Blade/')
        ->and($code)->toBe(0);
});

test('E25-15: preflight is read-only: it executes no migration, writes no row and creates no compiled file', function () {
    e25_write_pending_migration($this->e25Dir, '2099_01_03_000000_e25_probe_readonly', <<<'BODY'
        Schema::create('e25_probe_readonly', function (Blueprint $t) { $t->id(); });
BODY);
    $this->app['migrator']->path($this->e25Dir);
    config(['app.debug' => false]);

    $snapshot = function (): array {
        $out = [];
        foreach ([storage_path('framework/views'), base_path('bootstrap/cache')] as $dir) {
            foreach (is_dir($dir) ? scandir($dir) : [] as $file) {
                if ($file !== '.' && $file !== '..' && $file !== '.gitignore') {
                    $out[$dir.'/'.$file] = filemtime($dir.'/'.$file);
                }
            }
        }
        ksort($out);

        return $out;
    };

    $ranBefore = DB::table('migrations')->count();
    $filesBefore = $snapshot();
    $writes = [];
    DB::listen(function ($query) use (&$writes) {
        if (preg_match('/^\s*(insert|update|delete|create|alter|drop|truncate|replace)\b/i', $query->sql) === 1) {
            $writes[] = $query->sql;
        }
    });

    Artisan::call('release:preflight');

    expect($writes)->toBe([])
        ->and(DB::table('migrations')->count())->toBe($ranBefore)
        ->and(Schema::hasTable('e25_probe_readonly'))->toBeFalse()
        ->and($snapshot())->toBe($filesBefore);
});

test('E25-15: preflight sources contain no mutating or state-changing calls (static guard)', function () {
    $forbidden = '/Artisan::|->call\(|->callSilent|\$this->call\(|migrator->run\(|->rollback\(|->reset\(|DB::(statement|insert|update|delete|unprepared)\b|Cache::(put|forget|flush|forever|add)\b|file_put_contents|unlink\(|touch\(|\bexec\(|shell_exec|proc_open|passthru|\bsystem\(|->truncate\(|rmdir\(|rename\(|copy\(/';

    foreach (['app/Services/Operations/PreflightChecker.php', 'app/Services/Operations/MigrationState.php', 'app/Services/Operations/MigrationRiskScanner.php', 'app/Console/Commands/ReleasePreflight.php'] as $file) {
        expect(preg_match($forbidden, e25_repo_file($file), $m))->toBe(0, "{$file} must stay read-only but matches ".($m[0] ?? ''));
    }
});
