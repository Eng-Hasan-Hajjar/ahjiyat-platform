<?php

namespace App\Console\Commands;

use App\Services\Database\SqliteToMysqlTransfer;
use App\Services\Database\TransferException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * E31: نقل بيانات التطبيق من ملف SQLite إلى MySQL/MariaDB. أداة محلية/تجريبية خطرة بطبيعتها فلها حواجز صارمة (انظر guard()):
 *  - ترفض APP_ENV=production دائمًا (لا تجاوز). المصدر SQLite، الهدف mysql/mariadb، ولا يتطابقان.
 *  - الهدف يجب أن يحوي المخطط (php artisan migrate) وبيانات تطبيق فارغة؛ لا وضع دمج. وضع التعافي --reset-target صريح ومؤكَّد باسم القاعدة.
 *  - --dry-run لا يكتب شيئًا. النسخ الفعلي يتطلب --force و--backup (نسخة مطابقة بايتًا لملف المصدر، يتحقق الأمر من بصمتها).
 *  - المصدر لا يُعدَّل أبدًا (PRAGMA query_only + بصمة قبل/بعد). لا كتابة مزدوجة. لا أسرار في أي مخرج (أرقام وأسماء جداول وبصمات فقط).
 * Exit: 0 نجاح/اكتمال dry-run، 1 فشل (حواجز/مشكلات/عدم تطابق)، 2 يلزم --force/--backup.
 */
class DbTransferSqliteToMysql extends Command
{
    protected $signature = 'db:transfer-sqlite-to-mysql
        {--source= : مسار ملف SQLite (الافتراضي: MIGRATION_SQLITE_DATABASE ثم اتصال sqlite)}
        {--target= : اسم اتصال mysql/mariadb (الافتراضي: MIGRATION_TARGET_CONNECTION=mysql)}
        {--dry-run : خطة وفحوص ما قبل النقل بلا أي كتابة}
        {--force : مطلوب للنسخ الفعلي}
        {--backup= : مسار نسخة احتياطية مطابقة لملف المصدر (مطلوب للنسخ الفعلي)}
        {--batch-size= : صفوف لكل دفعة (500..2000)}
        {--exclude= : جداول إضافية تُستثنى، مفصولة بفواصل}
        {--skip-fingerprint : تخطَّ بصمات المحتوى (أسرع وأضعف تحققًا)}
        {--reset-target : وضع تعافٍ: يفرغ جداول التطبيق في الهدف قبل النسخ (يتطلب --force و--confirm-database)}
        {--confirm-database= : اسم قاعدة الهدف تأكيدًا لـ--reset-target}
        {--report= : اكتب تقرير JSON (بلا أسرار) إلى هذا المسار}
        {--json : مخرجات JSON}';

    protected $description = 'نقل بيانات التطبيق من SQLite إلى MySQL بأمان (محلي/تجريبي فقط، يرفض الإنتاج) - E31';

    public function handle(): int
    {
        $config = (array) config('db_transfer', []);
        $targetName = (string) ($this->option('target') ?: ($config['target_connection'] ?? 'mysql'));
        $dry = (bool) $this->option('dry-run');
        $json = (bool) $this->option('json');

        if (($error = $this->guard($targetName, $config)) !== null) {
            $this->error($error);

            return self::FAILURE;
        }

        $sourcePath = $this->resolveSource($config);
        $overrides = (array) ($config['target_overrides'] ?? []);

        if ($this->option('batch-size') !== null) {
            $config['batch_size'] = (int) $this->option('batch-size');
        }

        $extra = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('exclude')))));
        $service = new SqliteToMysqlTransfer($config);
        $hashBefore = hash_file('sha256', $sourcePath) ?: '';

        try {
            $service->connect($sourcePath, $targetName, $overrides);

            if (! $dry && ($error = $this->guardRealRun($sourcePath, $hashBefore, $service)) !== null) {
                $this->error($error);

                return $this->option('force') ? self::FAILURE : 2;
            }

            $plan = $service->plan($extra);
            $this->renderPlan($plan, $json);

            if (! $plan['ok'] && ! ($this->option('reset-target') && $this->onlyTargetNotEmpty($plan))) {
                $this->error('توجد مشكلات تمنع النقل (أعلاه). لم يُكتب شيء.');

                return self::FAILURE;
            }

            if ($dry) {
                $this->info('dry-run: لم يُكتب أي شيء. المصدر والهدف بلا تغيير.');

                return self::SUCCESS;
            }

            if ($this->option('reset-target')) {
                $cleared = $service->resetTarget($plan);
                $this->warn('وضع التعافي: أُفرغ '.count($cleared).' جدول/جداول في الهدف.');
                $plan = $service->plan($extra);

                if (! $plan['ok']) {
                    $this->renderPlan($plan, $json);
                    $this->error('الخطة غير سليمة بعد التفريغ. لم يُنسخ شيء.');

                    return self::FAILURE;
                }
            }

            $started = microtime(true);
            $service->execute($plan, function (string $table, int $done, int $total): void {
                if ($done >= $total) {
                    $this->line("  ✓ {$table}: {$done} صف");
                }
            });
            $validation = $service->validate($plan, ! $this->option('skip-fingerprint'), function (string $stage, string $table): void {
                if ($stage === 'fingerprint') {
                    $this->line("  … بصمة {$table}");
                }
            });
        } catch (TransferException $e) {
            $this->error('فشل النقل: '.$e->getMessage());
            $this->line('المصدر سليم (قراءة فقط). امسح/أعد إنشاء قاعدة الهدف أو استعمل --reset-target ثم أعد المحاولة.');

            return self::FAILURE;
        } catch (Throwable $e) {
            $info = SqliteToMysqlTransfer::sanitize($e);
            $this->error('فشل النقل غير متوقَّع: '.class_basename($e)." - {$info['category']} (رمز {$info['code']}). التفاصيل في سجلات التطبيق.");
            report($e);

            return self::FAILURE;
        } finally {
            $service->disconnect();
        }

        $hashAfter = hash_file('sha256', $sourcePath) ?: '';
        $unchanged = hash_equals($hashBefore, $hashAfter);
        $totals = $validation['totals'];
        $pass = $validation['result'] === 'PASS' && $unchanged;

        $report = [
            'source' => 'SQLite', 'target' => 'MySQL', 'result' => $pass ? 'PASS' : 'FAIL', 'source_unchanged' => $unchanged,
            'seconds' => round(microtime(true) - $started, 1), 'totals' => $totals, 'tables' => $validation['tables'],
            'orphans' => $validation['orphans'], 'auto_increment' => $validation['auto_increment'], 'financial' => $validation['financial'],
            'fingerprints_checked' => $validation['fingerprints_checked'],
        ];

        if ($this->option('report')) {
            File::put((string) $this->option('report'), (string) json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        }

        if ($json) {
            $this->line((string) json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        } else {
            $this->renderReport($report);
        }

        return $pass ? self::SUCCESS : self::FAILURE;
    }

    // ───────────────────────────── الحواجز ─────────────────────────────

    /** @param  array<string, mixed>  $config */
    private function guard(string $targetName, array $config): ?string
    {
        if (app()->environment('production')) {
            return 'مرفوض: هذا الأمر أداة محلية/تجريبية ولا يعمل عند APP_ENV=production (لا يوجد تجاوز). نفّذ النقل على نسخة محلية/staging ثم انشر قاعدة MySQL الجاهزة.';
        }

        $target = config("database.connections.{$targetName}");

        if (! is_array($target)) {
            return "مرفوض: اتصال الهدف '{$targetName}' غير معرَّف في config/database.php.";
        }

        if (! in_array($target['driver'] ?? '', ['mysql', 'mariadb'], true)) {
            return 'مرفوض: الهدف يجب أن يكون اتصال mysql أو mariadb (الحالي: '.($target['driver'] ?? '?').').';
        }

        if ($targetName === 'sqlite') {
            return 'مرفوض: المصدر والهدف لا يجوز أن يكونا الاتصال نفسه.';
        }

        $path = $this->resolveSource($config);

        if (! is_file($path) || ! is_readable($path)) {
            return 'مرفوض: ملف SQLite المصدر غير موجود أو غير مقروء. مرِّر --source=<المسار> أو اضبط MIGRATION_SQLITE_DATABASE. (تنبيه: إن بدّلت DB_CONNECTION=mysql فقد صار DB_DATABASE اسم قاعدة MySQL لا مسار SQLite.)';
        }

        try {
            $magic = (string) (new \SplFileObject($path, 'rb'))->fread(16);   // ترويسة الملف فقط (16 بايت)، من مسار أدخله المشغّل في سطر الأوامر
        } catch (Throwable) {
            $magic = '';
        }

        if ($magic !== "SQLite format 3\0") {
            return 'مرفوض: الملف المحدد ليس قاعدة SQLite صالحة.';
        }

        return null;
    }

    private function guardRealRun(string $sourcePath, string $hash, SqliteToMysqlTransfer $service): ?string
    {
        if (! $this->option('force')) {
            return 'النسخ الفعلي يتطلب --force بعد مراجعة --dry-run (لم يُكتب شيء).';
        }

        if (is_file($sourcePath.'-wal') && filesize($sourcePath.'-wal') > 0) {
            return 'مرفوض: بجانب ملف SQLite ملف -wal غير فارغ (كتابات لم تُدمج). أوقف التطبيق ونفّذ PRAGMA wal_checkpoint(TRUNCATE) ثم أعد المحاولة.';
        }

        $backup = (string) $this->option('backup');

        if ($backup === '') {
            return 'مرفوض: النسخ الفعلي يتطلب --backup=<مسار> لنسخة احتياطية مطابقة لملف SQLite (انظر docs/mysql-production-e31.md، خطوة النسخ الاحتياطي).';
        }

        if (! is_file($backup)) {
            return 'مرفوض: ملف النسخة الاحتياطية غير موجود.';
        }

        if (realpath($backup) === realpath($sourcePath)) {
            return 'مرفوض: النسخة الاحتياطية هي ملف المصدر نفسه؛ أنشئ نسخة منفصلة.';
        }

        if (! hash_equals($hash, (string) hash_file('sha256', $backup))) {
            return 'مرفوض: النسخة الاحتياطية لا تطابق ملف المصدر بايتًا ببايت (بصمة SHA-256 مختلفة). أعد النسخ بعد إيقاف التطبيق.';
        }

        if ($this->option('reset-target')) {
            $database = (string) ($service->target()->getDatabaseName());

            if ((string) $this->option('confirm-database') !== $database) {
                return 'مرفوض: --reset-target يتطلب --confirm-database=<اسم قاعدة الهدف نفسه> ('.$database.').';
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $config */
    private function resolveSource(array $config): string
    {
        $path = (string) ($this->option('source') ?: ($config['sqlite_database'] ?? '') ?: config('database.connections.sqlite.database'));

        if ($path === '' || $path === ':memory:') {
            return '';
        }

        $absolute = str_starts_with($path, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1;

        return $absolute ? $path : base_path($path);
    }

    /** هل كل مشكلات الخطة هي «جدول الهدف غير فارغ» فقط (أي ما يعالجه --reset-target)؟ @param  array<string, mixed>  $plan */
    private function onlyTargetNotEmpty(array $plan): bool
    {
        foreach ($plan['problems'] as $problem) {
            if (! str_contains($problem, 'في الهدف غير فارغ')) {
                return false;
            }
        }

        return true;
    }

    // ───────────────────────────── العرض ─────────────────────────────

    /** @param  array<string, mixed>  $plan */
    private function renderPlan(array $plan, bool $json): void
    {
        if ($json) {
            return;
        }

        $this->info('== خطة النقل (SQLite → MySQL) ==');
        $rows = [];

        foreach ($plan['tables'] as $table => $meta) {
            $rows[] = [$table, $meta['source_rows'], $meta['target_rows'], $meta['status'] === 'excluded' ? 0 : $meta['source_rows'], $this->statusLabel($meta['status'])];
        }

        $this->table(['الجدول', 'صفوف المصدر', 'صفوف الهدف', 'مخطَّط نسخها', 'الحالة'], $rows);
        $this->line("إجمالي الصفوف المخطَّط نسخها: {$plan['planned_rows']}");

        foreach ($plan['warnings'] as $warning) {
            $this->warn('  ! '.$warning);
        }

        foreach ($plan['problems'] as $problem) {
            $this->error('  ✗ '.$problem);
        }
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'excluded' => 'مستثنى (مؤقت)',
            'reference' => 'مرجعي (يُستبدل بالمصدر)',
            'reference-empty' => 'مرجعي (المصدر فارغ)',
            'empty' => 'فارغ',
            default => 'نسخ',
        };
    }

    /** @param  array<string, mixed>  $report */
    private function renderReport(array $report): void
    {
        $t = $report['totals'];
        $this->newLine();

        foreach ($report['tables'] as $table => $row) {
            if ($row['status'] === 'excluded') {
                continue;
            }

            $flag = ($row['row_match'] ? 'OK' : 'ROWS-MISMATCH').($row['digest_match'] === false ? ' DIGEST-MISMATCH' : '');
            $this->line(sprintf('  %-38s %8d → %-8d %s %s', $table, $row['source_rows'], $row['target_rows'], $flag, $row['digest'] ?? ''));
        }

        foreach ($report['orphans'] as $orphan) {
            $this->error("  يتيم: {$orphan['table']} ({$orphan['fk']}) = {$orphan['count']}");
        }

        foreach ($report['financial'] as $row) {
            $row['match'] || $this->error("  مالي لا يطابق: {$row['table']}.{$row['column']}");
        }

        $this->newLine();
        $this->line('SOURCE: SQLite');
        $this->line('TARGET: MySQL');
        $this->line('Tables migrated: '.$t['tables_migrated']);
        $this->line('Rows migrated: '.$t['rows_migrated']);
        $this->line('Skipped ephemeral: '.$t['skipped_ephemeral']);
        $this->line('Row-count mismatches: '.$t['row_count_mismatches']);
        $this->line('Integrity violations: '.$t['integrity_violations']);
        $this->line('Fingerprint mismatches: '.$t['fingerprint_mismatches'].($report['fingerprints_checked'] ? '' : ' (not checked)'));
        $this->line('Auto-increment issues: '.$t['auto_increment_issues']);
        $this->line('Financial mismatches: '.$t['financial_mismatches']);
        $this->line('Source SQLite unchanged: '.($report['source_unchanged'] ? 'yes' : 'NO'));
        $this->newLine();
        $report['result'] === 'PASS' ? $this->info('Result: PASS') : $this->error('Result: FAIL');

        if ($report['result'] === 'PASS') {
            $this->line('التالي: اضبط DB_CONNECTION=mysql في .env ثم php artisan optimize:clear واختبر الدخول/الاقتصاد/البحث. احتفظ بنسخة SQLite الاحتياطية كما هي.');
        } else {
            $this->line('لا تحوّل التطبيق إلى MySQL. المصدر سليم؛ عالج المشكلة ثم أعد المحاولة (--reset-target بتأكيد صريح).');
        }
    }
}
