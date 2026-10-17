# E31 — DELIVERY PACK (A–G)

**NO GIT COMMANDS.** أساس المقارنة: v30 = `0b8cc5f` (remote `main` بعد دفعك: `58d3882..0b8cc5f`). هذا الملف والأرشيف خارج المستودع عمدًا (لا تنسخهما إليه؛ ملاحظة: `E30-delivery-pack-A-E.md` موجود في جذر المستودع بعد commit الـv30، والقرار بإبقائه أو إزالته لك). لا حزم جديدة (0 Composer / 0 npm)، `composer.lock` و`composer.json` و`package*.json` لم تُمسّ، ولا أصول واجهة تغيّرت فلا `npm run build`.

الملفات: **26 جديدًا** + **2 ترحيلات جديدة** + **21 معدَّلًا** (كاملة). التحقق بعد النسخ: `manifest-sha256.txt` داخل الأرشيف `e31-files.zip` (سكربت التحقق في README.txt داخله وفي قسم G).

> **حالة الإغلاق:** E31 **غير مغلقة**. كل ما لا يحتاج خادم MySQL أُثبت واختُبر هنا. كل ما يحتاج MySQL فعليًا موسوم **PENDING USER-LOCAL MYSQL VALIDATION** وأوامره الدقيقة في الأقسام E وF وG. لم يُشغَّل أي اختبار على MySQL في هذه البيئة ولا يُدّعى ذلك.

## الفهرس

- 0) خريطة الملفات والبصمات
- A) NEW FILES (26 ملفًا، محتوى كامل، بلا الترحيلات)
- B) MODIFIED FILES (21 ملفًا كاملًا)
- C) MIGRATIONS (2 جديدة، محتوى كامل)
- D) ENV / CONFIG
- E) LOCAL MYSQL SETUP (PowerShell)
- F) SQLITE → MYSQL TRANSFER COMMANDS
- G) VALIDATION COMMANDS

---

## 0) خريطة الملفات والبصمات

| الحالة | المسار | بايت | SHA-256 |
|---|---|---:|---|
| جديد | `app/Console/Commands/DbParity.php` | 7501 | `bfa08c6a62e1a131…` |
| جديد | `app/Console/Commands/DbTransferSqliteToMysql.php` | 16456 | `a5596350e081255e…` |
| جديد | `app/Database/MysqlSafeBlueprint.php` | 2164 | `4ad6761ab1a364e5…` |
| جديد | `app/Services/Database/DatabaseFactsEvaluator.php` | 5318 | `e7ddaf449e1a12a6…` |
| جديد | `app/Services/Database/DatabaseRuntimeInspector.php` | 4800 | `25d4e6c85fcb7e0e…` |
| جديد | `app/Services/Database/FinancialHistoryGuard.php` | 2948 | `e25233009d99139e…` |
| جديد | `app/Services/Database/MysqlDdlCompiler.php` | 4785 | `e2d4c641defaf121…` |
| جديد | `app/Services/Database/MysqlDdlLinter.php` | 24289 | `4c33df04f1f2e38d…` |
| جديد | `app/Services/Database/SchemaParity.php` | 7370 | `2a80632506c10e36…` |
| جديد | `app/Services/Database/SchemaSnapshot.php` | 6439 | `9857b0e318d0e3de…` |
| جديد | `app/Services/Database/SqliteToMysqlTransfer.php` | 47439 | `197f0c972eeade24…` |
| جديد | `app/Services/Database/TransactionPolicy.php` | 2218 | `8da7c35e303b080a…` |
| جديد | `app/Services/Database/TransferException.php` | 456 | `4833214e01cd1874…` |
| جديد | `config/db_transfer.php` | 4772 | `ac5c826b234da252…` |
| جديد | `docs/mysql-production-e31.md` | 31521 | `8be44f0bedf5b413…` |
| جديد | `phpunit.mysql.xml.dist` | 2650 | `36ef9cbefda87130…` |
| جديد | `tests/Feature/DatabaseE31/E31DocsAndToolingTest.php` | 6465 | `2ee3428f5b6fc4d6…` |
| جديد | `tests/Feature/DatabaseE31/E31DomainIntegrityTest.php` | 15487 | `a4f460d7ba9e6e9b…` |
| جديد | `tests/Feature/DatabaseE31/E31MysqlRuntimeTest.php` | 16534 | `e015038aa6fe5940…` |
| جديد | `tests/Feature/DatabaseE31/E31ParityAndGuardsTest.php` | 19680 | `bf77b2d65fc1895c…` |
| جديد | `tests/Feature/DatabaseE31/E31RetryPolicyTest.php` | 3958 | `f32ccdcf3848a0b5…` |
| جديد | `tests/Feature/DatabaseE31/E31StaticSchemaTest.php` | 13734 | `841d95581f6b0cf4…` |
| جديد | `tests/Feature/DatabaseE31/E31TransferTest.php` | 13652 | `bda86f2b7fcc1495…` |
| جديد | `tests/Feature/DatabaseE31/_support.php` | 6581 | `d2600a96a51b4adc…` |
| جديد | `tools/e31/explain-audit.sql` | 5760 | `3608ee4c9be7a9a6…` |
| جديد | `tools/e31/run-mysql-matrix.ps1` | 8368 | `87e48166ee9d14e2…` |
| ترحيل جديد | `database/migrations/2026_09_23_000002_e31_guard_wallets_user_fk_index.php` | 2344 | `19ed64ba5f15e0a4…` |
| ترحيل جديد | `database/migrations/2026_10_23_000001_e31_drop_wallets_user_guard_index.php` | 2173 | `8674ee67a62387eb…` |
| معدَّل | `.env.example` | 17733 | `253d01b3d39f9629…` |
| معدَّل | `.github/workflows/ci.yml` | 6397 | `77573a82397925b0…` |
| معدَّل | `app/Policies/UserPolicy.php` | 2629 | `b5a58c4c31ad9b8f…` |
| معدَّل | `app/Providers/AppServiceProvider.php` | 20491 | `2dc419058e509cfe…` |
| معدَّل | `app/Services/Competitive/CompetitiveRunService.php` | 7661 | `353f9b4c43e25943…` |
| معدَّل | `app/Services/Creators/CreatorApplicationService.php` | 7664 | `fac63bed8f998e7e…` |
| معدَّل | `app/Services/Economy/CurrencyWalletService.php` | 12946 | `4e8117133152d9c9…` |
| معدَّل | `app/Services/GameSessionService.php` | 10025 | `9dac37991ffdce4f…` |
| معدَّل | `app/Services/Operations/ProductionChecker.php` | 43988 | `9c2bd87959a32913…` |
| معدَّل | `app/Services/PuzzleAttemptService.php` | 8550 | `4bd6911a10232117…` |
| معدَّل | `app/Services/Store/StorePurchaseService.php` | 10129 | `a4efa4811c92aa26…` |
| معدَّل | `app/Services/Teams/TeamCompetitiveRankingService.php` | 10052 | `b6df4af9ef47cead…` |
| معدَّل | `config/database.php` | 7350 | `f5f4088b9f7be712…` |
| معدَّل | `config/operations.php` | 4878 | `30a2846171e1dbce…` |
| معدَّل | `docs/environment-variables.md` | 11856 | `cadd51519b3b93b1…` |
| معدَّل | `docs/production-checklist.md` | 7847 | `d4a771a71a1f01b3…` |
| معدَّل | `tests/Feature/CreatorE28/E28OperationsTest.php` | 11219 | `5bb3eda2356b74bb…` |
| معدَّل | `tests/Feature/CreatorSeriesE30/E30SeriesPerformanceTest.php` | 13192 | `a9c94e87cecc00a9…` |
| معدَّل | `tests/Feature/DiscoveryE27/E27PerformanceTest.php` | 15651 | `fa2a3f1eb04ea2b2…` |
| معدَّل | `tests/Support/TestDatabaseGuard.php` | 2302 | `19432de6abd5b771…` |
| معدَّل | `tests/Unit/TestDatabaseGuardTest.php` | 2480 | `378085dfce5c2bed…` |

البصمات الكاملة بجانب كل ملف أدناه وفي `manifest-sha256.txt`.

---

## A) NEW FILES — المسار + المحتوى الكامل (26 ملفًا)

### `app/Console/Commands/DbParity.php`

`sha256: bfa08c6a62e1a1315ab840570a4e678fe45da4646868aadef4933545c0759b2e`

`````php
<?php

namespace App\Console\Commands;

use App\Services\Database\DatabaseFactsEvaluator;
use App\Services\Database\DatabaseRuntimeInspector;
use App\Services\Database\MysqlDdlCompiler;
use App\Services\Database\MysqlDdlLinter;
use App\Services\Database\SchemaParity;
use App\Services\Database\SchemaSnapshot;
use Illuminate\Console\Command;
use Throwable;

/**
 * E31: تكافؤ مخطط SQLite/MySQL (قراءة فقط تمامًا: لا ترحيل ولا كتابة ولا DDL على أي قاعدة).
 *
 * طريقتان:
 *  1) افتراضيًا: يترجم **كل الترحيلات** إلى جمل MySQL بلا خادم (وضع pretend)، يحلّلها (أسماء > 64، فهرس يحتاجه مفتاح أجنبي، أنواع المفاتيح، أعمدة مالية عائمة...)،
 *     ثم يقارنها بالمخطط **الحيّ** للاتصال المفحوص (--connection أو الافتراضي): هل القاعدة الفعلية هي ما تنتجه الترحيلات على MySQL؟
 *  2) --against=اتصال: يقارن مخططين حيّين (مثل sqlite مقابل mysql) بدل النموذج المحسوب.
 *  --static: الترجمة والتحليل فقط بلا أي اتصال بقاعدة حيّة (يعمل في CI بلا MySQL).
 *
 * المقارنة منطقية لا فيزيائية: لا يُشترط أن يتطابق تمثيل SQLite مع MySQL حرفيًا (unsigned، عرض الأنواع...).
 * Exit 0 = لا فشل جوهري (قد توجد تحذيرات/معلومات)، 1 = فشل جوهري، 2 = خطأ استعمال.
 */
class DbParity extends Command
{
    protected $signature = 'db:parity
        {--connection= : الاتصال الحيّ المفحوص (الافتراضي: الاتصال الافتراضي)}
        {--against= : اتصال حيّ كمرجع بدل النموذج المحسوب من الترحيلات (مثل mysql)}
        {--static : ترجمة الترحيلات إلى MySQL وتحليلها فقط بلا اتصال بقاعدة حيّة}
        {--runtime : اعرض حقائق خادم القاعدة (النسخة، الوضع الصارم، الترميز، المحرك) للاتصال المفحوص}
        {--details : اعرض كل الفروق بما فيها المعلوماتية}
        {--json : مخرجات JSON}';

    protected $description = 'تكافؤ مخطط SQLite/MySQL (قراءة فقط) - Exit 1 عند فشل جوهري فقط (E31)';

    public function handle(): int
    {
        $json = (bool) $this->option('json');
        $report = ['static' => null, 'parity' => null, 'runtime' => null];
        $material = 0;

        try {
            $lint = null;

            if (! $this->option('against')) {
                $compiled = (new MysqlDdlCompiler)->compile();
                $lint = (new MysqlDdlLinter)->analyze($compiled['statements']);
                $report['static'] = [
                    'migrations' => $compiled['migrations'], 'statements' => count($compiled['statements']),
                    'compile_errors' => $compiled['errors'], 'summary' => $lint['summary'], 'findings' => $lint['findings'],
                ];
                $material += $lint['summary']['fail'] + count($compiled['errors']);
            }

            if (! $this->option('static')) {
                $connection = (string) ($this->option('connection') ?: config('database.default'));
                $snapshot = new SchemaSnapshot;

                if ($this->option('against')) {
                    $expected = $snapshot->fromConnection((string) $this->option('against'));
                } else {
                    $expected = $snapshot->fromLinterModel($lint['tables']);
                }

                $parity = (new SchemaParity)->compare($expected, $snapshot->fromConnection($connection));
                $report['parity'] = ['connection' => $connection, 'reference' => $this->option('against') ? (string) $this->option('against') : 'mysql-from-migrations'] + $parity;
                $material += $parity['summary'][SchemaParity::MATERIAL];

                if ($this->option('runtime')) {
                    $facts = app(DatabaseRuntimeInspector::class)->inspect($connection);
                    $report['runtime'] = ['facts' => $facts, 'evaluation' => (new DatabaseFactsEvaluator)->evaluate($facts)];
                }
            }
        } catch (Throwable $e) {
            $this->error('تعذّر إكمال الفحص: '.class_basename($e).' - راجع سجلات التطبيق.');
            report($e);

            return 2;
        }

        $report['result'] = $material === 0 ? 'PASS' : 'FAIL';

        if ($json) {
            $this->line((string) json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

            return $material === 0 ? self::SUCCESS : self::FAILURE;
        }

        $this->render($report);

        return $material === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @param  array<string, mixed>  $report */
    private function render(array $report): void
    {
        $details = (bool) $this->option('details');
        $this->info('== تكافؤ المخطط (قراءة فقط) ==');

        if ($report['static'] !== null) {
            $s = $report['static'];
            $this->line("الترجمة الساكنة إلى MySQL: {$s['migrations']} ترحيلًا → {$s['statements']} جملة، أخطاء الترجمة: ".count($s['compile_errors']).'، جداول: '.$s['summary']['tables']);
            $this->line("تحليل MySQL: فشل {$s['summary']['fail']} · تحذير {$s['summary']['warn']} · معلومة {$s['summary']['info']}");

            foreach ($s['compile_errors'] as $migration => $error) {
                $this->error("  [ترجمة] {$migration}: {$error}");
            }

            foreach ($s['findings'] as $finding) {
                if ($finding['level'] === 'fail' || $details) {
                    $this->line("  [{$finding['level']}] {$finding['code']} · {$finding['table']} · {$finding['detail']}");
                }
            }
        }

        if ($report['parity'] !== null) {
            $p = $report['parity'];
            $this->newLine();
            $this->line("مقارنة المخطط الحيّ [{$p['connection']}] بالمرجع [{$p['reference']}]: جوهري {$p['summary']['material']} · تحذير {$p['summary']['warn']} · معلومة {$p['summary']['info']}");

            foreach ($p['findings'] as $finding) {
                if ($finding['level'] === 'material' || $details) {
                    $this->line("  [{$finding['level']}] {$finding['code']} · {$finding['table']} · {$finding['detail']}");
                }
            }
        }

        if ($report['runtime'] !== null) {
            $this->newLine();
            $f = $report['runtime']['facts'];
            $this->line('خادم القاعدة: '.($f['driver'] ?? '?').' '.($f['version_major_minor'] ?? '').' · charset='.($f['db_charset'] ?? '-').' · strict='.(($f['strict'] ?? false) ? 'yes' : 'no').' · isolation='.($f['isolation'] ?? '-'));

            foreach ($report['runtime']['evaluation'] as $item) {
                $this->line("  [{$item['level']}] {$item['key']} · {$item['message']}");
            }
        }

        $this->newLine();
        $report['result'] === 'PASS' ? $this->info('النتيجة: PASS') : $this->error('النتيجة: FAIL (فشل جوهري)');
    }
}
`````

### `app/Console/Commands/DbTransferSqliteToMysql.php`

`sha256: a5596350e081255e5ed1457f85c08a7ffa89849b926f6311c2f2236953e81cd7`

`````php
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
`````

### `app/Database/MysqlSafeBlueprint.php`

`sha256: 4ad6761ab1a364e5741f2cb9afaf5e7e0136dcb9ba6f304503d4421f273c5255`

`````php
<?php

namespace App\Database;

use Illuminate\Database\Schema\Blueprint;

/**
 * E31: MySQL وMariaDB يرفضان أي اسم فهرس أو مفتاح أجنبي أطول من 64 محرفًا (الخطأ 1059)، بينما SQLite لا يحدّ الطول.
 * بعض الأسماء التي يولّدها Laravel تلقائيًا من اسم الجدول والأعمدة تتجاوز 64 (مثل competitive_event_results_competitive_event_id_score_duration_ms_index = 70)،
 * فكان الترحيل من الصفر على MySQL سيفشل في منتصف الطريق.
 *
 * الحل هنا بلا لمس أي ترحيل قديم (الترحيلات القديمة غير قابلة للتعديل): هذا الصنف يُربط بدل Blueprint في الحاوية (انظر AppServiceProvider)،
 * ولا يغيّر شيئًا إلا حين يكون السائق mysql/mariadb والاسم المولَّد أطول من 64: عندها يُقتطع إلى 55 محرفًا ويُلحق به "_" وبصمة sha1 من 8 خانات
 * (الناتج 64 تمامًا). الاقتطاع حتمي، فيعطي dropIndex(['a','b']) نفس الاسم الذي أنشأه الترحيل.
 * على SQLite وبقية السائقين لا تغيير إطلاقًا (الأسماء كما كانت، وقواعد البيانات الحالية لا تتأثر).
 */
class MysqlSafeBlueprint extends Blueprint
{
    public const MAX_IDENTIFIER_LENGTH = 64;

    /** @var list<string> */
    private const LIMITED_DRIVERS = ['mysql', 'mariadb'];

    protected function createIndexName($type, array $columns)
    {
        return self::fit(parent::createIndexName($type, $columns), (string) $this->connection->getDriverName());
    }

    /** يعيد الاسم كما هو إن كان صالحًا للسائق، وإلا نسخته المقتطعة الحتمية. */
    public static function fit(string $name, string $driver): string
    {
        if (! in_array($driver, self::LIMITED_DRIVERS, true) || strlen($name) <= self::MAX_IDENTIFIER_LENGTH) {
            return $name;
        }

        return substr($name, 0, 55).'_'.substr(sha1($name), 0, 8);
    }
}
`````

### `app/Services/Database/DatabaseFactsEvaluator.php`

`sha256: e7ddaf449e1a12a66b976733cf662139219f808f9a4658fcd843e15970e58ef2`

`````php
<?php

namespace App\Services\Database;

/**
 * E31: يحوّل حقائق الخادم (DatabaseRuntimeInspector) إلى نتائج فحص. دالة نقية بلا I/O، لذا تُختبر بلا قاعدة MySQL.
 *
 * عقد النسخ المدعومة (docs/mysql-production-e31.md):
 *  - MySQL ≥ 8.0.16 (أول نسخة تفرض قيود CHECK؛ الاستهداف: 8.0 LTS و8.4 LTS). هذا هو المحرك المُتحقَّق منه.
 *  - MariaDB ≥ 10.6: «أفضل جهد»، لم يُتحقَّق منه في E31 (يلزم explicit_defaults_for_timestamp=ON قبل 10.10).
 *
 * المستوى: pass | warn | fail | prodfail (prodfail = FAIL بالإنتاج وWARN خارجه، مثل ProductionChecker::prodFail).
 */
final class DatabaseFactsEvaluator
{
    public const MYSQL_MIN = '8.0.16';

    public const MARIADB_MIN = '10.6.0';

    /**
     * @param  array<string, mixed>  $facts
     * @return list<array{level: string, key: string, message: string}>
     */
    public function evaluate(array $facts): array
    {
        $driver = (string) ($facts['driver'] ?? '');

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return [];
        }

        $out = [];
        $flavor = (string) ($facts['flavor'] ?? $driver);
        $number = $facts['version_number'] ?? null;
        $label = $flavor === 'mariadb' ? 'MariaDB' : 'MySQL';

        if ($number === null) {
            $out[] = $this->r('warn', 'DB_VERSION', 'تعذّر تحديد نسخة خادم القاعدة');
        } elseif ($flavor === 'mysql') {
            $out[] = version_compare((string) $number, self::MYSQL_MIN, '>=')
                ? $this->r('pass', 'DB_VERSION', "{$label} {$facts['version_major_minor']} ضمن النسخ المدعومة (الحد الأدنى ".self::MYSQL_MIN.')')
                : $this->r('prodfail', 'DB_VERSION', "نسخة {$label} أقدم من الحد الأدنى المدعوم ".self::MYSQL_MIN.' (قيود CHECK لا تُفرض قبلها)');
        } else {
            if (version_compare((string) $number, self::MARIADB_MIN, '<')) {
                $out[] = $this->r('prodfail', 'DB_VERSION', 'نسخة MariaDB أقدم من الحد الأدنى '.self::MARIADB_MIN);
            } else {
                $out[] = $this->r('warn', 'DB_VERSION', 'MariaDB مدعومة «بأفضل جهد» فقط ولم يُتحقَّق منها في E31 - الموصى به MySQL ≥ '.self::MYSQL_MIN);
            }
        }

        if (array_key_exists('strict', $facts)) {
            $out[] = $facts['strict']
                ? $this->r('pass', 'DB_STRICT', 'الوضع الصارم (STRICT_TRANS_TABLES) مفعّل: لا اقتطاع صامت للبيانات')
                : $this->r('prodfail', 'DB_STRICT', 'الوضع الصارم غير مفعّل في جلسة التطبيق (database.connections.*.strict=true مطلوب): خطر اقتطاع بيانات صامت');
        }

        $charset = (string) ($facts['db_charset'] ?? '');

        if ($charset !== '') {
            $out[] = str_starts_with($charset, 'utf8mb4')
                ? $this->r('pass', 'DB_CHARSET', 'ترميز القاعدة utf8mb4 (عربي + إيموجي سليم)')
                : $this->r('prodfail', 'DB_CHARSET', 'ترميز القاعدة ليس utf8mb4: النص العربي/الإيموجي قد يتلف - أعد إنشاء القاعدة بـCHARACTER SET utf8mb4');
        }

        if (isset($facts['non_innodb_tables'])) {
            $out[] = (int) $facts['non_innodb_tables'] === 0
                ? $this->r('pass', 'DB_ENGINE', 'كل الجداول InnoDB (معاملات + مفاتيح أجنبية)')
                : $this->r('prodfail', 'DB_ENGINE', (int) $facts['non_innodb_tables'].' جدول/جداول ليست InnoDB: لا معاملات ولا مفاتيح أجنبية فعلية عليها');
        }

        if (isset($facts['non_utf8mb4_tables']) && (int) $facts['non_utf8mb4_tables'] > 0) {
            $out[] = $this->r('warn', 'DB_COLLATION', (int) $facts['non_utf8mb4_tables'].' جدول/جداول بتجميع غير utf8mb4');
        }

        if (($facts['explicit_defaults_for_timestamp'] ?? true) === false) {
            $out[] = $this->r('warn', 'DB_TIMESTAMP', 'explicit_defaults_for_timestamp=OFF: أعمدة TIMESTAMP NOT NULL تتصرف بقيم افتراضية ضمنية (الافتراضي ON في MySQL 8)');
        }

        $tz = $facts['time_zone'] ?? null;

        if (is_string($tz) && ! in_array(strtoupper($tz), ['+00:00', 'UTC'], true)) {
            $out[] = $this->r('warn', 'DB_TIMEZONE', 'منطقة جلسة القاعدة ليست UTC (+00:00): اضبط DB_TIMEZONE=+00:00 كي تتطابق مع app.timezone');
        }

        $packet = $facts['max_allowed_packet'] ?? null;

        if (is_int($packet) && $packet < 4 * 1024 * 1024) {
            $out[] = $this->r('warn', 'DB_PACKET', 'max_allowed_packet أقل من 4MB: صفوف كبيرة (محتوى مبدعين/دردشة) قد تُرفض');
        }

        return $out;
    }

    /** @return array{level: string, key: string, message: string} */
    private function r(string $level, string $key, string $message): array
    {
        return ['level' => $level, 'key' => $key, 'message' => $message];
    }
}
`````

### `app/Services/Database/DatabaseRuntimeInspector.php`

`sha256: 25d4e6c85fcb7e0e22617e480349edacf0ad31ecd838f9da81392c8889f88572`

`````php
<?php

namespace App\Services\Database;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * E31: حقائق الخادم الفعلية للقاعدة المتصلة (قراءة فقط). تغذّي production:check وdb:parity --runtime وأمر النقل.
 *
 * لا تُرجع أبدًا مضيفًا أو منفذًا أو اسم مستخدم أو كلمة مرور أو اسم قاعدة: أرقام ونصوص إعدادات الخادم فقط (النسخة، sql_mode، الترميز...).
 * على SQLite (أو أي سائق آخر) تُرجع الحقائق الأساسية فقط بلا استعلامات خاصة بـMySQL.
 */
class DatabaseRuntimeInspector
{
    private const VARIABLES = [
        'version', 'sql_mode', 'character_set_database', 'collation_database', 'character_set_server', 'transaction_isolation', 'tx_isolation',
        'time_zone', 'system_time_zone', 'max_allowed_packet', 'explicit_defaults_for_timestamp', 'foreign_key_checks', 'innodb_lock_wait_timeout',
        'default_storage_engine', 'lower_case_table_names',
    ];

    /**
     * @return array<string, mixed>
     */
    public function inspect(?string $connection = null): array
    {
        $db = DB::connection($connection);
        $driver = $db->getDriverName();

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return ['driver' => $driver, 'reachable' => $this->reachable($db), 'flavor' => $driver];
        }

        $facts = ['driver' => $driver, 'reachable' => true];

        try {
            $placeholders = implode(',', array_fill(0, count(self::VARIABLES), '?'));
            $rows = $db->select("show variables where Variable_name in ({$placeholders})", self::VARIABLES);
            $vars = [];

            foreach ($rows as $row) {
                $row = (array) $row;
                $vars[strtolower((string) ($row['Variable_name'] ?? $row['variable_name'] ?? ''))] = (string) ($row['Value'] ?? $row['value'] ?? '');
            }
        } catch (Throwable) {
            return ['driver' => $driver, 'reachable' => false, 'flavor' => $driver];
        }

        $version = $vars['version'] ?? '';
        $flavor = stripos($version, 'mariadb') !== false ? 'mariadb' : 'mysql';
        preg_match('/(\d+)\.(\d+)\.(\d+)/', $version, $m);

        $facts += [
            'flavor' => $flavor,
            'version_number' => isset($m[0]) ? $m[0] : null,
            'version_major_minor' => isset($m[1]) ? $m[1].'.'.$m[2] : null,
            'sql_mode' => $vars['sql_mode'] ?? '',
            'strict' => (bool) preg_match('/STRICT_(TRANS|ALL)_TABLES/', $vars['sql_mode'] ?? ''),
            'only_full_group_by' => str_contains($vars['sql_mode'] ?? '', 'ONLY_FULL_GROUP_BY'),
            'db_charset' => $vars['character_set_database'] ?? null,
            'db_collation' => $vars['collation_database'] ?? null,
            'isolation' => $vars['transaction_isolation'] ?? $vars['tx_isolation'] ?? null,
            'time_zone' => $vars['time_zone'] ?? null,
            'max_allowed_packet' => isset($vars['max_allowed_packet']) ? (int) $vars['max_allowed_packet'] : null,
            'explicit_defaults_for_timestamp' => isset($vars['explicit_defaults_for_timestamp']) ? in_array(strtoupper($vars['explicit_defaults_for_timestamp']), ['ON', '1'], true) : null,
            'lock_wait_timeout' => isset($vars['innodb_lock_wait_timeout']) ? (int) $vars['innodb_lock_wait_timeout'] : null,
            'default_engine' => $vars['default_storage_engine'] ?? null,
            'lower_case_table_names' => isset($vars['lower_case_table_names']) ? (int) $vars['lower_case_table_names'] : null,
        ];

        try {
            $summary = $db->selectOne(
                "select count(*) as tables_total,
                        sum(case when engine <> 'InnoDB' then 1 else 0 end) as non_innodb,
                        sum(case when table_collation not like 'utf8mb4%' then 1 else 0 end) as non_utf8mb4
                   from information_schema.tables where table_schema = database() and table_type = 'BASE TABLE'"
            );
            $facts += [
                'tables_total' => (int) ($summary->tables_total ?? 0),
                'non_innodb_tables' => (int) ($summary->non_innodb ?? 0),
                'non_utf8mb4_tables' => (int) ($summary->non_utf8mb4 ?? 0),
            ];
        } catch (Throwable) {
            // information_schema غير متاح لهذا المستخدم: نترك الحقائق الجدولية غير معروفة (لا نخمّن).
        }

        return $facts;
    }

    private function reachable(\Illuminate\Database\Connection $db): bool
    {
        try {
            $db->getPdo();

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
`````

### `app/Services/Database/FinancialHistoryGuard.php`

`sha256: e25233009d99139ee437e9c363994cf6d8856aa35ee80e48b0a0838e3bf397c4`

`````php
<?php

namespace App\Services\Database;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * E31 (U2/U3): حارس "التاريخ المالي لا يُمحى عرَضًا".
 *
 * المشكلة (متطابقة على SQLite وMySQL): مفاتيح user_id على جداول السجلات المالية/المكافآت مُعرَّفة ON DELETE CASCADE، فحذف مستخدم من لوحة الإدارة
 * (مدير أعلى فقط) كان يمسح دفتر عملاته ومكافآته وطلبات استبداله بصمت. الترحيلات التاريخية لا تُعدَّل، فالحماية هنا على مستوى التطبيق:
 * لا يُعرض/لا يُسمح بحذف مستخدم له أي سجلّ مالي أو رصيد؛ البديل المقصود هو التجميد (users.freeze) الذي يحفظ التاريخ للتدقيق.
 *
 * يقرأ فقط (exists) بلا أي كتابة. الجداول ثابتة هنا عمدًا (قائمة بيضاء) ويثبّتها اختبار E31 مقابل DDL الفعلي، فإضافة جدول مالي جديد بـCASCADE على users تُظهر فشل الاختبار.
 */
final class FinancialHistoryGuard
{
    /**
     * جدول => [عمود المستخدم، وصف بالعربية]. كلها تُحذف بالتتالي عند حذف المستخدم (أو تمنعه بـRESTRICT كـstore_purchases).
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const HISTORY_TABLES = [
        'currency_transactions' => ['user_id', 'دفتر العملات'],
        'redemption_requests' => ['user_id', 'طلبات الاستبدال'],
        'store_purchases' => ['user_id', 'مشتريات المتجر'],
        'inventory_transactions' => ['user_id', 'حركات المخزون'],
        'xp_transactions' => ['user_id', 'حركات الخبرة'],
        'competitive_reward_grants' => ['user_id', 'جوائز الأحداث التنافسية'],
        'sponsorship_reward_bonuses' => ['user_id', 'مكافآت الرعاية'],
    ];

    /** @return list<string> أوصاف الأقسام التي فيها سجلّ لهذا المستخدم (فارغة = لا تاريخ مالي). */
    public function historyFor(User $user): array
    {
        $found = [];

        foreach (self::HISTORY_TABLES as $table => [$column, $label]) {
            if (DB::table($table)->where($column, $user->getKey())->exists()) {
                $found[] = $label;
            }
        }

        $hasBalance = DB::table('wallets')->where('user_id', $user->getKey())
            ->where(fn ($q) => $q->where('available_balance', '>', 0)->orWhere('pending_balance', '>', 0))->exists();

        if ($hasBalance) {
            $found[] = 'رصيد المحفظة';
        }

        return $found;
    }

    public function hasHistory(User $user): bool
    {
        return $this->historyFor($user) !== [];
    }
}
`````

### `app/Services/Database/MysqlDdlCompiler.php`

`sha256: e2d4c641defaf1216f6656b313f4e21b87a1fa228c1afe7cb62c41390d059855`

`````php
<?php

namespace App\Services\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use PDO;
use Throwable;

/**
 * E31: يولّد جمل DDL التي كانت ستُنفَّذ على MySQL/MariaDB لو شُغِّلت كل الترحيلات من الصفر، **بلا خادم** وبلا أي كتابة.
 *
 * الطريقة: اتصال وهمي بسائق mysql (أو mariadb) يُنفَّذ عليه up() لكل ترحيل داخل وضع pretend (Laravel يسجّل الجمل ولا ينفّذها).
 * لا يُفتح أي اتصال شبكي: يُحقَن PDO داخلي خامل (SQLite في الذاكرة) كي لا تطلبه أجزاء Laravel التي تسأل عن نسخة الخادم.
 * ما يعتمد على قراءة حالة القاعدة (hasTable/hasColumn/select) يرى قاعدة فارغة في هذا الوضع، وهو المطلوب لمحاكاة الترحيل من الصفر.
 *
 * النتيجة تُغذّي MysqlDdlLinter (فحص أسماء الفهارس، أنواع المفاتيح الأجنبية، حذف فهرس يحتاجه مفتاح...) وأمر db:parity --static.
 */
final class MysqlDdlCompiler
{
    private const CONNECTION = 'e31_ddl_static';

    /**
     * @return array{statements: list<array{migration: string, sql: string}>, errors: array<string, string>, migrations: int}
     */
    public function compile(?string $migrationsPath = null, string $driver = 'mysql'): array
    {
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            throw new \InvalidArgumentException('Only the mysql and mariadb drivers can be compiled.');
        }

        $path = $migrationsPath ?? database_path('migrations');
        $files = app('migrator')->getMigrationFiles($path);

        $base = (array) config("database.connections.{$driver}");
        config(['database.connections.'.self::CONNECTION => array_merge($base, [
            'host' => '127.0.0.1', 'port' => '3306', 'database' => 'e31_ddl_static', 'username' => 'static', 'password' => '',
            'unix_socket' => '', 'url' => null, 'options' => [],
        ])]);

        $previousDefault = DB::getDefaultConnection();
        $previousCache = [config('cache.default'), config('permission.cache.store')];
        // بعض الترحيلات (Spatie) تمسح مفتاحًا من الكاش في آخرها؛ مخزن الذاكرة لا يلمس أي قاعدة.
        config(['cache.default' => 'array', 'permission.cache.store' => 'array']);

        DB::purge(self::CONNECTION);
        $connection = DB::connection(self::CONNECTION);
        $inert = new PDO('sqlite::memory:');
        $connection->setPdo($inert);
        $connection->setReadPdo($inert);

        $statements = [];
        $errors = [];

        try {
            DB::setDefaultConnection(self::CONNECTION);
            Facade::clearResolvedInstance('db.schema');

            foreach ($files as $name => $file) {
                try {
                    $migration = $this->resolve($name, $file);

                    $queries = $connection->pretend(function () use ($migration) {
                        $migration->up();
                    });

                    foreach ($queries as $query) {
                        $statements[] = ['migration' => (string) $name, 'sql' => (string) $query['query']];
                    }
                } catch (Throwable $e) {
                    $errors[(string) $name] = class_basename($e).': '.mb_substr($e->getMessage(), 0, 200);
                }
            }
        } finally {
            DB::setDefaultConnection($previousDefault);
            Facade::clearResolvedInstance('db.schema');
            config(['cache.default' => $previousCache[0], 'permission.cache.store' => $previousCache[1]]);
            DB::purge(self::CONNECTION);
            config(['database.connections.'.self::CONNECTION => null]);
        }

        return ['statements' => $statements, 'errors' => $errors, 'migrations' => count($files)];
    }

    private function resolve(string $name, string $file): object
    {
        static $required = [];

        // getRequire هو ما يستعمله مُرحِّل لارافل نفسه لتحميل ملفات الترحيل (ملفات المشروع المحلية فقط، لا مدخلات طلب).
        $result = $required[$file] ??= app(\Illuminate\Filesystem\Filesystem::class)->getRequire($file);

        if (is_object($result)) {
            return clone $result;
        }

        // ترحيل بصنف مسمّى (النمط القديم): الاسم بعد البادئة الزمنية الأربعة.
        $class = \Illuminate\Support\Str::studly(implode('_', array_slice(explode('_', $name), 4)));

        return new $class;
    }
}
`````

### `app/Services/Database/MysqlDdlLinter.php`

`sha256: 4c33df04f1f2e38d26962aca2fe329153f3e30d439da0fb4a5266ee4dc36ec51`

`````php
<?php

namespace App\Services\Database;

/**
 * E31: يحلّل جمل DDL الخاصة بـMySQL/MariaDB (من MysqlDdlCompiler أو من نص جاهز) ويطبّق قواعد الأخطاء التي تظهر على MySQL فقط ولا تظهر على SQLite:
 *
 *  fail  identifier_too_long        اسم جدول/عمود/فهرس/مفتاح أجنبي/قيد > 64 محرفًا (الخطأ 1059)
 *  fail  index_needed_by_fk         حذف فهرس هو الوحيد الذي يخدم مفتاحًا أجنبيًا (الخطأ 1553)
 *  fail  fk_type_mismatch           نوع/إشارة عمود المفتاح الأجنبي لا يطابق العمود المُشار إليه (الخطأ 3780/150)
 *  fail  fk_target_missing          مفتاح أجنبي يشير إلى جدول/عمود غير موجود وقت إنشائه
 *  fail  index_too_long             مجموع بايتات الفهرس > 3072 (DYNAMIC) في utf8mb4
 *  fail  index_on_lob               فهرس على TEXT/BLOB/JSON بلا طول بادئة
 *  fail  lob_literal_default        قيمة افتراضية حرفية على TEXT/BLOB/JSON (مرفوضة؛ يلزم تعبير بين قوسين)
 *  fail  row_size_too_large         مجموع أعظم أحجام الأعمدة (غير LOB) > 65535 بايت
 *  fail  float_money_column         عمود مالي بنوع float/double (يجب أعداد صحيحة بوحدات صغرى أو DECIMAL)
 *  fail  fk_set_null_not_null       ON DELETE SET NULL على عمود NOT NULL
 *  warn  drop_missing_index         حذف فهرس غير مسجّل في النموذج
 *  info  redundant_index            فهرس غير فريد هو بادئة لفهرس آخر (تكلفة كتابة بلا فائدة قراءة؛ لا يُحذف بلا EXPLAIN)
 *  info  reserved_word_column       عمود باسم كلمة محجوزة في MySQL 8 (آمن مع منشئ الاستعلامات؛ يلزم تأهيله/اقتباسه في أي SQL خام)
 *  info  timestamp_summary          عدد أعمدة TIMESTAMP (نطاقها 1970..2038) وNOT NULL منها بلا قيمة افتراضية
 *  info  check_constraint           قيد CHECK (يُفرَض فعليًا من MySQL 8.0.16)
 *  info  unparsed_statement         جملة لم يفهمها المحلل (تُحصى ولا تُخفى)
 *
 * المحلل يبني نموذج المخطط بالترتيب نفسه الذي تُنفَّذ به الجمل، لذلك يلتقط أخطاء الترتيب (حذف فهرس قبل إضافة بديله) لا أخطاء الشكل فقط.
 * يحاكي أيضًا قاعدة InnoDB: إنشاء مفتاح أجنبي بلا فهرس يخلق فهرسًا ضمنيًا باسم القيد، ويُحذف ضمنيًا حين يظهر فهرس يغطي المفتاح.
 */
final class MysqlDdlLinter
{
    public const FAIL = 'fail';

    public const WARN = 'warn';

    public const INFO = 'info';

    /** كلمات MySQL 8.0 المحجوزة التي قد تكون أسماء أعمدة شائعة (المرجع الكامل: وثائق MySQL "Keywords and Reserved Words"). */
    private const RESERVED = [
        'rank', 'key', 'keys', 'group', 'groups', 'order', 'read', 'write', 'row', 'rows', 'range', 'system', 'usage', 'condition', 'lock',
        'index', 'primary', 'unique', 'check', 'default', 'desc', 'asc', 'limit', 'match', 'option', 'schema', 'table', 'trigger', 'window',
        'lead', 'lag', 'function', 'interval', 'release', 'repeat', 'replace', 'select', 'update', 'delete', 'insert', 'values', 'when', 'where',
        'dense_rank', 'cume_dist', 'ntile', 'first_value', 'last_value', 'nth_value', 'row_number', 'percent_rank', 'empty', 'except', 'lateral',
    ];

    /** أسماء أعمدة تعني مالًا أو رصيدًا؛ لا يجوز أن تكون float/double. */
    private const MONEY_PATTERN = '/(^|_)(amount|balance|price|total|minor|bps|reward|funding|gems|cost|fee)(_|$)/';

    private const MAX_INDEX_BYTES = 3072;

    private const MAX_ROW_BYTES = 65535;

    private const MAX_IDENTIFIER = 64;

    /** علامة الاقتباس (backtick) كثابت: تبقي سلاسل الشيفرة خالية من الباكتيك الحرفي فلا يلتبس بها تدقيق E26 الساكن لـ«أوامر الصدفة». */
    private const BT = "\x60";

    /** @var array<string, array{columns: array<string, array<string, mixed>>, indexes: array<string, array<string, mixed>>, fks: list<array<string, mixed>>, created_in: string}> */
    private array $tables = [];

    /** @var list<array{level: string, code: string, table: string, detail: string, migration: string}> */
    private array $findings = [];

    private int $unparsed = 0;

    /**
     * @param  list<array{migration: string, sql: string}>  $statements
     * @return array{tables: array<string, mixed>, findings: list<array<string, string>>, summary: array<string, int>}
     */
    public function analyze(array $statements): array
    {
        $this->tables = [];
        $this->findings = [];
        $this->unparsed = 0;

        foreach ($statements as $statement) {
            $this->apply($statement['migration'], trim((string) $statement['sql']));
        }

        $this->finalChecks();

        $counts = ['fail' => 0, 'warn' => 0, 'info' => 0];

        foreach ($this->findings as $finding) {
            $counts[$finding['level']]++;
        }

        return [
            'tables' => $this->tables,
            'findings' => $this->findings,
            'summary' => $counts + ['tables' => count($this->tables), 'statements' => count($statements), 'unparsed' => $this->unparsed],
        ];
    }

    // ───────────────────────────── تطبيق الجمل ─────────────────────────────

    private function apply(string $migration, string $sql): void
    {
        if ($sql === '' || preg_match('/^(select|insert|update|delete|set|show)\b/i', $sql)) {
            return;
        }

        if (preg_match('/^create table\s+`(\w+)`\s*\((.*)\)\s*(default character set.*|engine.*)?$/is', $sql, $m)) {
            $this->createTable($migration, $m[1], $m[2]);

            return;
        }

        if (preg_match('/^rename table\s+`(\w+)`\s+to\s+`(\w+)`/i', $sql, $m)) {
            if (isset($this->tables[$m[1]])) {
                $this->tables[$m[2]] = $this->tables[$m[1]];
                unset($this->tables[$m[1]]);
            }

            return;
        }

        if (preg_match('/^drop table(?: if exists)?\s+`(\w+)`/i', $sql, $m)) {
            unset($this->tables[$m[1]]);

            return;
        }

        if (preg_match('/^alter table\s+`?(\w+)`?\s+add constraint\s+`?(\w+)`?\s+check\b/i', $sql, $m)) {
            $this->checkIdentifier($m[2], $m[1], $migration, 'قيد CHECK');
            $this->add(self::INFO, 'check_constraint', $m[1], "قيد CHECK {$m[2]} (يُفرَض من MySQL 8.0.16 وMariaDB 10.2.1)", $migration);

            return;
        }

        if (preg_match('/^alter table\s+`(\w+)`\s+(.*)$/is', $sql, $m)) {
            $this->alterTable($migration, $m[1], $m[2]);

            return;
        }

        $this->unparsed++;
        $this->add(self::INFO, 'unparsed_statement', '-', mb_substr($sql, 0, 90), $migration);
    }

    private function createTable(string $migration, string $table, string $body): void
    {
        $this->tables[$table] = ['columns' => [], 'indexes' => [], 'fks' => [], 'created_in' => $migration];
        $this->checkIdentifier($table, $table, $migration, 'اسم جدول');

        foreach ($this->splitTop($body) as $definition) {
            if (preg_match('/^primary key\s*\((.*)\)$/is', $definition, $pk)) {
                $this->addIndex($table, 'PRIMARY', true, $pk[1], $migration);

                continue;
            }

            $this->addColumn($migration, $table, $definition);
        }
    }

    private function alterTable(string $migration, string $table, string $rest): void
    {
        if (! isset($this->tables[$table])) {
            $this->unparsed++;
            $this->add(self::INFO, 'unparsed_statement', $table, 'ALTER على جدول غير معروف للمحلل', $migration);

            return;
        }

        foreach ($this->splitTop($rest) as $part) {
            if (preg_match('/^add\s+`(\w+)`\s+(.*)$/is', $part, $m)) {
                $this->addColumn($migration, $table, self::BT.$m[1].self::BT.' '.$m[2]);
            } elseif (preg_match('/^add\s+(unique|index|fulltext|spatial)(?:\s+(?:index|key))?\s+`(\w+)`\s*\((.*)\)/is', $part, $m)) {
                $this->addIndex($table, $m[2], strtolower($m[1]) === 'unique', $m[3], $migration);
            } elseif (preg_match('/^add primary key\s*\((.*)\)/is', $part, $m)) {
                $this->addIndex($table, 'PRIMARY', true, $m[1], $migration);
            } elseif (preg_match('/^add constraint\s+`(\w+)`\s+foreign key\s*\((.*?)\)\s*references\s+`(\w+)`\s*\((.*?)\)(.*)$/is', $part, $m)) {
                $this->addForeignKey($table, $m[1], $m[2], $m[3], $m[4], $m[5], $migration);
            } elseif (preg_match('/^drop index\s+`(\w+)`/i', $part, $m)) {
                $this->dropIndex($table, $m[1], $migration);
            } elseif (preg_match('/^drop foreign key\s+`(\w+)`/i', $part, $m)) {
                $this->tables[$table]['fks'] = array_values(array_filter($this->tables[$table]['fks'], fn ($fk) => $fk['name'] !== $m[1]));
            } elseif (preg_match('/^drop(?: column)?\s+`(\w+)`/i', $part, $m)) {
                unset($this->tables[$table]['columns'][$m[1]]);
            } elseif (preg_match('/^(?:modify|change)(?: column)?\s+`(\w+)`(?:\s+`(\w+)`)?\s+(.*)$/is', $part, $m)) {
                $name = $m[2] !== '' ? $m[2] : $m[1];
                unset($this->tables[$table]['columns'][$m[1]]);
                $this->addColumn($migration, $table, self::BT.$name.self::BT.' '.$m[3]);
            } else {
                $this->unparsed++;
                $this->add(self::INFO, 'unparsed_statement', $table, mb_substr($part, 0, 90), $migration);
            }
        }
    }

    private function addColumn(string $migration, string $table, string $definition): void
    {
        if (! preg_match('/^`(\w+)`\s+([a-z]+(?:\s*\([^)]*\))?)(\s+unsigned)?(.*)$/is', $definition, $m)) {
            $this->unparsed++;
            $this->add(self::INFO, 'unparsed_statement', $table, 'تعريف عمود: '.mb_substr($definition, 0, 80), $migration);

            return;
        }

        [, $name, $type, $unsigned, $rest] = $m + [4 => ''];
        $type = strtolower(preg_replace('/\s+/', '', $type) ?? $type);
        $isNotNull = stripos($rest, 'not null') !== false;
        $default = preg_match('/\bdefault\s+(.+?)(?:\s+(?:on update|comment|after|first|auto_increment|primary|unique|collate|character)\b|$)/is', $rest, $d) === 1 ? trim($d[1]) : null;

        $this->tables[$table]['columns'][$name] = [
            'type' => $type.($unsigned !== '' ? ' unsigned' : ''),
            'base' => $this->baseType($type),
            'null' => ! $isNotNull,
            'default' => $default,
            'auto_increment' => stripos($rest, 'auto_increment') !== false,
            'migration' => $migration,
        ];

        $this->checkIdentifier($name, $table, $migration, 'اسم عمود');

        // Laravel (id()) يولّد المفتاح الأساسي في تعريف العمود نفسه: «... auto_increment primary key».
        if (preg_match('/\bprimary key\b/i', $rest) === 1) {
            $this->addIndex($table, 'PRIMARY', true, self::BT.$name.self::BT, $migration);
        }
    }

    private function addIndex(string $table, string $name, bool $unique, string $columnList, string $migration): void
    {
        preg_match_all('/`(\w+)`(?:\((\d+)\))?/', $columnList, $cols, PREG_SET_ORDER);
        $columns = [];
        $prefix = [];

        foreach ($cols as $c) {
            $columns[] = $c[1];

            if (isset($c[2]) && $c[2] !== '') {
                $prefix[$c[1]] = (int) $c[2];
            }
        }

        $this->tables[$table]['indexes'][$name] = ['unique' => $unique, 'columns' => $columns, 'prefix' => $prefix, 'implicit' => false, 'migration' => $migration];

        if ($name !== 'PRIMARY') {
            $this->checkIdentifier($name, $table, $migration, 'اسم فهرس');
        }

        $this->dropCoveredImplicitIndexes($table);
    }

    private function addForeignKey(string $table, string $name, string $cols, string $ref, string $refCols, string $tail, string $migration): void
    {
        preg_match_all('/`(\w+)`/', $cols, $a);
        preg_match_all('/`(\w+)`/', $refCols, $b);
        $columns = $a[1];

        $onDelete = preg_match('/on delete\s+(cascade|restrict|set null|no action|set default)/i', $tail, $d) === 1 ? strtolower($d[1]) : 'no action';
        $onUpdate = preg_match('/on update\s+(cascade|restrict|set null|no action|set default)/i', $tail, $u) === 1 ? strtolower($u[1]) : 'no action';

        $this->tables[$table]['fks'][] = ['name' => $name, 'columns' => $columns, 'ref' => $ref, 'ref_columns' => $b[1], 'on_delete' => $onDelete, 'on_update' => $onUpdate, 'migration' => $migration];
        $this->checkIdentifier($name, $table, $migration, 'اسم مفتاح أجنبي');

        // InnoDB: لا فهرس يخدم المفتاح ← فهرس ضمني باسم القيد.
        if (! $this->covered($table, $columns)) {
            $this->tables[$table]['indexes'][$name] = ['unique' => false, 'columns' => $columns, 'prefix' => [], 'implicit' => true, 'migration' => $migration];
        }

        // فحوص وقت الإنشاء: المرجع موجود، الأنواع متطابقة، SET NULL يستلزم عمودًا يقبل NULL.
        foreach ($columns as $i => $column) {
            $refColumn = $b[1][$i] ?? 'id';
            $local = $this->tables[$table]['columns'][$column] ?? null;
            $remote = $this->tables[$ref]['columns'][$refColumn] ?? null;

            if ($remote === null || $local === null) {
                $this->add(self::FAIL, 'fk_target_missing', $table, "{$name}: {$column} → {$ref}.{$refColumn} غير موجود", $migration);

                continue;
            }

            if ($local['type'] !== $remote['type']) {
                $this->add(self::FAIL, 'fk_type_mismatch', $table, "{$name}: {$column} ({$local['type']}) ≠ {$ref}.{$refColumn} ({$remote['type']})", $migration);
            }

            if ($onDelete === 'set null' && ! $local['null']) {
                $this->add(self::FAIL, 'fk_set_null_not_null', $table, "{$name}: ON DELETE SET NULL على عمود NOT NULL ({$column})", $migration);
            }
        }
    }

    private function dropIndex(string $table, string $name, string $migration): void
    {
        if (! isset($this->tables[$table]['indexes'][$name])) {
            $this->add(self::WARN, 'drop_missing_index', $table, "حذف فهرس غير معروف للنموذج: {$name}", $migration);

            return;
        }

        unset($this->tables[$table]['indexes'][$name]);

        foreach ($this->tables[$table]['fks'] as $fk) {
            if (! $this->covered($table, $fk['columns'])) {
                $this->add(self::FAIL, 'index_needed_by_fk', $table, "حذف الفهرس {$name} يترك المفتاح الأجنبي {$fk['name']} بلا فهرس (MySQL خطأ 1553)", $migration);
            }
        }
    }

    // ───────────────────────────── فحوص نهائية ─────────────────────────────

    private function finalChecks(): void
    {
        $timestamps = 0;
        $strictNoDefault = 0;

        foreach ($this->tables as $table => $model) {
            $rowBytes = 0;

            foreach ($model['columns'] as $name => $column) {
                $rowBytes += $this->columnBytes($column['type'], $column['base']);

                if ($this->isLob($column['base']) && $column['default'] !== null && ! str_starts_with($column['default'], '(')) {
                    $this->add(self::FAIL, 'lob_literal_default', $table, "{$name} ({$column['base']}) له قيمة افتراضية حرفية", $model['created_in']);
                }

                if (in_array($column['base'], ['float', 'double', 'real'], true) && preg_match(self::MONEY_PATTERN, $name) === 1) {
                    $this->add(self::FAIL, 'float_money_column', $table, "{$name} ({$column['type']}) عمود مالي بنوع عشري عائم", $column['migration']);
                }

                if (in_array($name, self::RESERVED, true)) {
                    $this->add(self::INFO, 'reserved_word_column', $table, "العمود {$name} كلمة محجوزة في MySQL 8: اقتبسه أو أهِّله باسم الجدول في أي SQL خام", $column['migration']);
                }

                if ($column['base'] === 'timestamp') {
                    $timestamps++;

                    if (! $column['null'] && $column['default'] === null) {
                        $strictNoDefault++;
                    }
                }
            }

            if ($rowBytes > self::MAX_ROW_BYTES) {
                $this->add(self::FAIL, 'row_size_too_large', $table, "مجموع أحجام الأعمدة المقدَّر {$rowBytes} بايت > ".self::MAX_ROW_BYTES, $model['created_in']);
            }

            foreach ($model['indexes'] as $indexName => $index) {
                $bytes = 0;

                foreach ($index['columns'] as $columnName) {
                    $column = $model['columns'][$columnName] ?? null;

                    if ($column === null) {
                        continue;
                    }

                    $prefix = $index['prefix'][$columnName] ?? null;

                    if ($this->isLob($column['base']) && $prefix === null) {
                        $this->add(self::FAIL, 'index_on_lob', $table, "الفهرس {$indexName} على {$columnName} ({$column['base']}) بلا طول بادئة", $index['migration']);

                        continue;
                    }

                    $bytes += $prefix !== null ? $prefix * 4 : $this->indexBytes($column['type'], $column['base']);
                }

                if ($bytes > self::MAX_INDEX_BYTES) {
                    $this->add(self::FAIL, 'index_too_long', $table, "الفهرس {$indexName} يبلغ {$bytes} بايت > ".self::MAX_INDEX_BYTES, $index['migration']);
                }
            }

            $this->redundantIndexes($table, $model['indexes']);
        }

        $this->add(self::INFO, 'timestamp_summary', '-', "{$timestamps} عمود TIMESTAMP (نطاق MySQL: 1970-01-01..2038-01-19)، منها {$strictNoDefault} NOT NULL بلا قيمة افتراضية (يتطلب explicit_defaults_for_timestamp=ON: افتراضي MySQL 8)", '-');
    }

    /** @param array<string, array<string, mixed>> $indexes */
    private function redundantIndexes(string $table, array $indexes): void
    {
        foreach ($indexes as $name => $index) {
            if ($index['unique'] || $index['implicit'] || $name === 'PRIMARY') {
                continue;
            }

            foreach ($indexes as $otherName => $other) {
                if ($otherName === $name) {
                    continue;
                }

                $same = $index['columns'] === $other['columns'];
                $prefixOf = ! $same && array_slice($other['columns'], 0, count($index['columns'])) === $index['columns'];

                if ($prefixOf || ($same && strcmp($name, (string) $otherName) > 0)) {
                    $this->add(self::INFO, 'redundant_index', $table, "{$name}(".implode(',', $index['columns']).") مغطّى بـ{$otherName}(".implode(',', $other['columns']).')', (string) $index['migration']);

                    break;
                }
            }
        }
    }

    // ───────────────────────────── أدوات ─────────────────────────────

    private function covered(string $table, array $columns): bool
    {
        foreach ($this->tables[$table]['indexes'] ?? [] as $index) {
            if (array_slice($index['columns'], 0, count($columns)) === $columns) {
                return true;
            }
        }

        return false;
    }

    private function dropCoveredImplicitIndexes(string $table): void
    {
        foreach ($this->tables[$table]['indexes'] as $name => $index) {
            if (! $index['implicit']) {
                continue;
            }

            foreach ($this->tables[$table]['indexes'] as $otherName => $other) {
                if ($otherName !== $name && ! $other['implicit'] && array_slice($other['columns'], 0, count($index['columns'])) === $index['columns']) {
                    unset($this->tables[$table]['indexes'][$name]);

                    break;
                }
            }
        }
    }

    private function checkIdentifier(string $identifier, string $table, string $migration, string $what): void
    {
        if (strlen($identifier) > self::MAX_IDENTIFIER) {
            $this->add(self::FAIL, 'identifier_too_long', $table, "{$what} يبلغ ".strlen($identifier).' محرفًا > '.self::MAX_IDENTIFIER.": {$identifier}", $migration);
        }
    }

    private function baseType(string $type): string
    {
        return preg_match('/^([a-z]+)/', $type, $m) === 1 ? $m[1] : $type;
    }

    private function isLob(string $base): bool
    {
        return in_array($base, ['text', 'tinytext', 'mediumtext', 'longtext', 'blob', 'tinyblob', 'mediumblob', 'longblob', 'json'], true);
    }

    private function indexBytes(string $type, string $base): int
    {
        if (preg_match('/^(?:var)?char\((\d+)\)/', $type, $m) === 1) {
            return (int) $m[1] * 4;
        }

        return $this->fixedBytes($base);
    }

    private function columnBytes(string $type, string $base): int
    {
        if (preg_match('/^varchar\((\d+)\)/', $type, $m) === 1) {
            return (int) $m[1] * 4 + ((int) $m[1] > 255 ? 2 : 1);
        }

        if (preg_match('/^char\((\d+)\)/', $type, $m) === 1) {
            return (int) $m[1] * 4;
        }

        return $this->isLob($base) ? 12 : $this->fixedBytes($base);
    }

    private function fixedBytes(string $base): int
    {
        return match ($base) {
            'tinyint' => 1, 'smallint' => 2, 'mediumint' => 3, 'int', 'integer', 'float' => 4, 'bigint', 'double', 'real', 'datetime' => 8,
            'timestamp' => 4, 'date' => 3, 'time' => 3, 'year' => 1, 'enum', 'set' => 2, 'decimal', 'numeric' => 16, default => 8,
        };
    }

    /** @return list<string> */
    private function splitTop(string $text): array
    {
        $parts = [];
        $depth = 0;
        $current = '';
        $quote = null;
        $length = strlen($text);

        for ($i = 0; $i < $length; $i++) {
            $ch = $text[$i];

            if ($quote !== null) {
                $current .= $ch;

                if ($ch === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $quote = $ch;
                $current .= $ch;

                continue;
            }

            if ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth--;
            } elseif ($ch === ',' && $depth === 0) {
                $parts[] = trim($current);
                $current = '';

                continue;
            }

            $current .= $ch;
        }

        if (trim($current) !== '') {
            $parts[] = trim($current);
        }

        return $parts;
    }

    private function add(string $level, string $code, string $table, string $detail, string $migration): void
    {
        $this->findings[] = ['level' => $level, 'code' => $code, 'table' => $table, 'detail' => $detail, 'migration' => $migration];
    }
}
`````

### `app/Services/Database/SchemaParity.php`

`sha256: 2a80632506c10e36b07fa92e92fc63724ab872a4feb485959a02e2a4d99946bb`

`````php
<?php

namespace App\Services\Database;

/**
 * E31: يقارن لقطتين منطقيتين (SchemaSnapshot) ويُرجع فروقًا مصنّفة. قراءة فقط ولا يلمس أي قاعدة.
 *
 * «expected» = ما يجب أن يكون (نموذج MySQL الناتج من الترحيلات، أو قاعدة المصدر)، و«actual» = ما هو موجود (القاعدة المفحوصة).
 * المستويات:
 *  material  فشل جوهري يوقف النقل/النشر (يجعل db:parity يخرج بـ1): جدول/عمود/قيد فريد/مفتاح أجنبي ناقص، اختلاف فئة نوع، عمود صار NOT NULL.
 *  warn      فرق يستحق نظرة ولا يمنع (فهرس غير فريد ناقص، عمود/جدول زائد، NOT NULL أصبح يقبل NULL، اختلاف string/text).
 *  info      فروق متوقعة بين المحركات (unsigned غير مفروض على SQLite، فهرس تغطيه فهارس أخرى...).
 */
final class SchemaParity
{
    public const MATERIAL = 'material';

    public const WARN = 'warn';

    public const INFO = 'info';

    /**
     * @param  array<string, array<string, mixed>>  $expected
     * @param  array<string, array<string, mixed>>  $actual
     * @param  list<string>  $ignoreTables
     * @return array{findings: list<array{level: string, code: string, table: string, detail: string}>, summary: array<string, int>}
     */
    public function compare(array $expected, array $actual, array $ignoreTables = ['migrations']): array
    {
        $findings = [];
        $add = function (string $level, string $code, string $table, string $detail) use (&$findings): void {
            $findings[] = ['level' => $level, 'code' => $code, 'table' => $table, 'detail' => $detail];
        };

        foreach ($ignoreTables as $ignored) {
            unset($expected[$ignored], $actual[$ignored]);
        }

        foreach ($expected as $table => $exp) {
            if (! isset($actual[$table])) {
                $add(self::MATERIAL, 'table_missing', $table, 'الجدول غير موجود في القاعدة المفحوصة');

                continue;
            }

            $this->compareTable($table, $exp, $actual[$table], $add);
        }

        foreach (array_keys($actual) as $table) {
            if (! isset($expected[$table])) {
                $add(self::WARN, 'table_extra', $table, 'جدول زائد غير موجود في المرجع');
            }
        }

        $counts = [self::MATERIAL => 0, self::WARN => 0, self::INFO => 0];

        foreach ($findings as $finding) {
            $counts[$finding['level']]++;
        }

        return ['findings' => $findings, 'summary' => $counts + ['tables_expected' => count($expected), 'tables_actual' => count($actual)]];
    }

    /**
     * @param  array<string, mixed>  $exp
     * @param  array<string, mixed>  $act
     */
    private function compareTable(string $table, array $exp, array $act, \Closure $add): void
    {
        $unsignedLost = 0;

        foreach ($exp['columns'] as $name => $col) {
            $a = $act['columns'][$name] ?? null;

            if ($a === null) {
                $add(self::MATERIAL, 'column_missing', $table, "العمود {$name} غير موجود");

                continue;
            }

            if ($col['class'] !== $a['class']) {
                $level = in_array([$col['class'], $a['class']], [['string', 'text'], ['text', 'string']], true) ? self::WARN : self::MATERIAL;
                $add($level, 'type_class_mismatch', $table, "العمود {$name}: المتوقع {$col['class']} ({$col['type']}) والموجود {$a['class']} ({$a['type']})");
            }

            if ($col['nullable'] === false && $a['nullable'] === true) {
                $add(self::WARN, 'nullable_relaxed', $table, "العمود {$name} يقبل NULL بينما المتوقع NOT NULL");
            } elseif ($col['nullable'] === true && $a['nullable'] === false) {
                $add(self::MATERIAL, 'nullable_tightened', $table, "العمود {$name} صار NOT NULL بينما المتوقع يقبل NULL (النقل/الكتابة قد يفشل)");
            }

            if ($col['unsigned'] && ! $a['unsigned']) {
                $unsignedLost++;
            }
        }

        foreach (array_keys($act['columns']) as $name) {
            if (! isset($exp['columns'][$name])) {
                $add(self::WARN, 'column_extra', $table, "عمود زائد غير موجود في المرجع: {$name}");
            }
        }

        if ($unsignedLost > 0) {
            $add(self::INFO, 'unsigned_not_enforced', $table, "{$unsignedLost} عمود/أعمدة UNSIGNED في المرجع لا يفرضها المحرك المفحوص (متوقع على SQLite)");
        }

        if ($exp['primary'] !== [] && $act['primary'] !== [] && $exp['primary'] !== $act['primary']) {
            $add(self::MATERIAL, 'primary_mismatch', $table, 'المفتاح الأساسي مختلف: ('.implode(',', $exp['primary']).') مقابل ('.implode(',', $act['primary']).')');
        } elseif ($exp['primary'] !== [] && $act['primary'] === []) {
            $add(self::WARN, 'primary_unreported', $table, 'المحرك المفحوص لم يُبلغ عن مفتاح أساسي');
        }

        $actualUnique = array_merge($act['unique'], $act['primary'] !== [] ? [$act['primary']] : []);

        foreach ($exp['unique'] as $columns) {
            if (! in_array($columns, $actualUnique, true)) {
                $add(self::MATERIAL, 'unique_missing', $table, 'قيد فريد ناقص على ('.implode(',', $columns).')');
            }
        }

        $allActual = array_merge($act['indexes'], $act['unique'], $act['primary'] !== [] ? [$act['primary']] : []);

        foreach ($exp['indexes'] as $columns) {
            if (! $this->covered($columns, $allActual)) {
                $add(self::WARN, 'index_missing', $table, 'فهرس ناقص على ('.implode(',', $columns).')');
            }
        }

        $actualFks = [];

        foreach ($act['fks'] as $fk) {
            $actualFks[implode(',', $fk['columns']).'>'.$fk['ref'].'('.implode(',', $fk['ref_columns']).')'] = $fk;
        }

        foreach ($exp['fks'] as $fk) {
            $key = implode(',', $fk['columns']).'>'.$fk['ref'].'('.implode(',', $fk['ref_columns']).')';

            if (! isset($actualFks[$key])) {
                $add(self::MATERIAL, 'fk_missing', $table, "مفتاح أجنبي ناقص: {$key}");
            } elseif ($actualFks[$key]['on_delete'] !== $fk['on_delete']) {
                $add(self::MATERIAL, 'fk_delete_rule_mismatch', $table, "قاعدة الحذف للمفتاح {$key}: المتوقع {$fk['on_delete']} والموجود {$actualFks[$key]['on_delete']}");
            }
        }
    }

    /**
     * فهرس «مغطّى» إن وُجد فهرس فعلي يبدأ بالأعمدة نفسها (بادئة أيسر).
     *
     * @param  list<string>  $columns
     * @param  list<list<string>>  $actualIndexes
     */
    private function covered(array $columns, array $actualIndexes): bool
    {
        foreach ($actualIndexes as $index) {
            if (array_slice($index, 0, count($columns)) === $columns) {
                return true;
            }
        }

        return false;
    }
}
`````

### `app/Services/Database/SchemaSnapshot.php`

`sha256: 9857b0e318d0e3de0c8deeb7af8b8fe1a2ef9256a8b63c064b2460b55bcdf906`

`````php
<?php

namespace App\Services\Database;

use Illuminate\Support\Facades\Schema;

/**
 * E31: لقطة «منطقية» للمخطط قابلة للمقارنة بين محركين مختلفين (SQLite وMySQL) دون اشتراط تطابق التمثيل الفيزيائي.
 *
 * الشكل الموحّد لكل جدول:
 *  columns: [اسم => [class, nullable, unsigned, type]]   (class: int|bool|decimal|float|string|text|datetime|date|time|binary|other)
 *  primary: list<string>
 *  unique:  list<list<string>>      (مجموعات أعمدة فريدة غير المفتاح الأساسي)
 *  indexes: list<list<string>>      (فهارس غير فريدة صريحة؛ الضمنية للمفاتيح الأجنبية لا تُعدّ)
 *  fks:     list<{columns, ref, ref_columns, on_delete}>
 *
 * مصدران: اتصال حيّ (Laravel Schema builder، يعمل على كل السائقين) أو نموذج MysqlDdlLinter (بلا خادم).
 */
final class SchemaSnapshot
{
    /** @return array<string, array<string, mixed>> */
    public function fromConnection(string $connection): array
    {
        $schema = Schema::connection($connection);
        $snapshot = [];

        foreach ($schema->getTables() as $table) {
            $name = (string) $table['name'];

            if (str_starts_with($name, 'sqlite_')) {
                continue;
            }

            $columns = [];

            foreach ($schema->getColumns($name) as $column) {
                $type = strtolower((string) ($column['type'] ?? $column['type_name'] ?? ''));
                $columns[(string) $column['name']] = [
                    'class' => self::typeClass($type !== '' ? $type : strtolower((string) $column['type_name'])),
                    'nullable' => (bool) $column['nullable'],
                    'unsigned' => str_contains($type, 'unsigned'),
                    'type' => $type,
                ];
            }

            $primary = [];
            $unique = [];
            $indexes = [];

            foreach ($schema->getIndexes($name) as $index) {
                $cols = array_values(array_map('strval', $index['columns']));

                if (! empty($index['primary'])) {
                    $primary = $cols;
                } elseif (! empty($index['unique'])) {
                    $unique[] = $cols;
                } else {
                    $indexes[] = $cols;
                }
            }

            $fks = [];

            foreach ($schema->getForeignKeys($name) as $fk) {
                $fks[] = [
                    'columns' => array_values(array_map('strval', $fk['columns'])),
                    'ref' => (string) $fk['foreign_table'],
                    'ref_columns' => array_values(array_map('strval', $fk['foreign_columns'])),
                    'on_delete' => self::deleteRule((string) ($fk['on_delete'] ?? '')),
                ];
            }

            $snapshot[$name] = compact('columns', 'primary', 'unique', 'indexes', 'fks');
        }

        ksort($snapshot);

        return $snapshot;
    }

    /**
     * @param  array<string, array<string, mixed>>  $tables  مخرج MysqlDdlLinter::analyze()['tables']
     * @return array<string, array<string, mixed>>
     */
    public function fromLinterModel(array $tables): array
    {
        $snapshot = [];

        foreach ($tables as $name => $model) {
            $columns = [];

            foreach ($model['columns'] as $column => $meta) {
                $columns[$column] = [
                    'class' => self::typeClass((string) $meta['type']),
                    'nullable' => (bool) $meta['null'],
                    'unsigned' => str_contains((string) $meta['type'], 'unsigned'),
                    'type' => (string) $meta['type'],
                ];
            }

            $primary = [];
            $unique = [];
            $indexes = [];

            foreach ($model['indexes'] as $indexName => $index) {
                if ($indexName === 'PRIMARY') {
                    $primary = $index['columns'];
                } elseif ($index['unique']) {
                    $unique[] = $index['columns'];
                } elseif (! $index['implicit']) {
                    $indexes[] = $index['columns'];
                }
            }

            $fks = [];

            foreach ($model['fks'] as $fk) {
                $fks[] = ['columns' => $fk['columns'], 'ref' => $fk['ref'], 'ref_columns' => $fk['ref_columns'], 'on_delete' => self::deleteRule($fk['on_delete'])];
            }

            $snapshot[$name] = compact('columns', 'primary', 'unique', 'indexes', 'fks');
        }

        ksort($snapshot);

        return $snapshot;
    }

    /** يحوّل نوع العمود (من أي سائق) إلى فئة منطقية. SQLite ديناميكية الأنواع: نقارن النية لا الشكل الفيزيائي. */
    public static function typeClass(string $type): string
    {
        $type = strtolower(trim($type));

        if ($type === 'tinyint(1)' || $type === 'boolean' || $type === 'bool') {
            return 'bool';
        }

        $base = preg_match('/^([a-z]+)/', $type, $m) === 1 ? $m[1] : $type;

        return match (true) {
            in_array($base, ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint', 'year'], true) => 'int',
            in_array($base, ['decimal', 'numeric'], true) => 'decimal',
            in_array($base, ['float', 'double', 'real'], true) => 'float',
            in_array($base, ['varchar', 'char', 'enum', 'set', 'string', 'nvarchar', 'nchar', 'uuid', 'ulid'], true) => 'string',
            in_array($base, ['text', 'tinytext', 'mediumtext', 'longtext', 'json', 'jsonb'], true) => 'text',
            in_array($base, ['datetime', 'timestamp', 'datetimetz', 'timestamptz'], true) => 'datetime',
            $base === 'date' => 'date',
            $base === 'time' => 'time',
            in_array($base, ['blob', 'tinyblob', 'mediumblob', 'longblob', 'binary', 'varbinary'], true) => 'binary',
            default => 'other:'.$base,
        };
    }

    /** NO ACTION وRESTRICT متكافئان في InnoDB؛ نوحّد الصيغة (حروف صغيرة). */
    public static function deleteRule(string $rule): string
    {
        $rule = strtolower(trim($rule));

        return match ($rule) {
            '', 'no action', 'restrict' => 'restrict',
            default => $rule,
        };
    }
}
`````

### `app/Services/Database/SqliteToMysqlTransfer.php`

`sha256: 197f0c972eeade24337ef01e7fec59bfa9fa1f79ff5349086850a87f5e1a7fd0`

`````php
<?php

namespace App\Services\Database;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * E31: ينقل **كل بيانات التطبيق** من قاعدة SQLite إلى قاعدة MySQL/MariaDB فارغة المخطط (بعد php artisan migrate عليها).
 *
 * المبادئ (تُثبَّت باختبارات DatabaseE31):
 *  - **قيم خام فقط**: قراءة/كتابة عبر منشئ الاستعلامات، لا Eloquent ولا أحداث نماذج ولا خدمات مكافآت/إشعارات/عدادات، لا تجزئة ولا فك تشفير:
 *    القيم المشفّرة (APP_KEY نفسه) والـJSON تُنسخ كما هي نصًّا.
 *  - **المعرّفات تُحفظ** (المفاتيح الأساسية تُدرج صراحةً) ثم يُضبط AUTO_INCREMENT فوق max(id) (وفوق sqlite_sequence إن كان أعلى).
 *  - **دفعات محدودة** (500..2000 صف، مع سقف للمتغيرات والبايتات)، كل دفعة معاملة مستقلة؛ لا معاملة عملاقة. المصدر يُقرأ بمفتاح rowid بلا OFFSET.
 *  - **المصدر للقراءة فقط**: PRAGMA query_only=ON. الأمر الخارجي يثبت بصمة الملف قبل/بعد.
 *  - **المفاتيح الأجنبية**: تُعطَّل على اتصال الهدف فقط داخل try/finally، تُنسخ الجداول بترتيب اعتماد الأمهات أولًا، ثم تُتحقَّق الأيتام لكل مفتاح أجنبي.
 *  - **لا دمج**: الهدف يجب أن يكون فارغ البيانات (عدا الصفوف المرجعية التي زرعتها الترحيلات نفسها، وتُستبدل بصفوف المصدر بشروط صارمة).
 *  - **لا أسرار في أي مخرج**: الأرقام والأسماء وبصمات مجزّأة فقط؛ رسائل الأخطاء تُنقّى (انظر TransferException/sanitize).
 */
final class SqliteToMysqlTransfer
{
    public const SOURCE = 'e31_transfer_source';

    public const TARGET = 'e31_transfer_target';

    private Connection $source;

    private Connection $target;

    /** @var array<string, mixed> */
    private array $config;

    /** @param  array<string, mixed>|null  $config */
    public function __construct(?array $config = null)
    {
        $this->config = $config ?? (array) config('db_transfer', []);
    }

    // ───────────────────────────── الاتصالات ─────────────────────────────

    /**
     * @param  array<string, mixed>  $targetOverrides
     */
    public function connect(string $sourcePath, string $targetConnection, array $targetOverrides = []): void
    {
        $sqlite = (array) config('database.connections.sqlite');
        $mysql = (array) config("database.connections.{$targetConnection}");

        config([
            'database.connections.'.self::SOURCE => array_merge($sqlite, [
                'driver' => 'sqlite', 'database' => $sourcePath, 'url' => null, 'foreign_key_constraints' => false,
                'busy_timeout' => null, 'journal_mode' => null, 'synchronous' => null, 'prefix' => '',
            ]),
            'database.connections.'.self::TARGET => array_merge($mysql, $targetOverrides, [
                'strict' => true, 'prefix' => '',
            ] + ($targetOverrides !== [] ? ['url' => null] : [])),
        ]);

        DB::purge(self::SOURCE);
        DB::purge(self::TARGET);

        $this->source = DB::connection(self::SOURCE);
        $this->target = DB::connection(self::TARGET);
        $this->source->statement('PRAGMA query_only = ON'); // قراءة فقط على مستوى SQLite نفسه
    }

    public function disconnect(): void
    {
        DB::purge(self::SOURCE);
        DB::purge(self::TARGET);
        config(['database.connections.'.self::SOURCE => null, 'database.connections.'.self::TARGET => null]);
    }

    public function source(): Connection
    {
        return $this->source;
    }

    public function target(): Connection
    {
        return $this->target;
    }

    // ───────────────────────────── التخطيط (قراءة فقط) ─────────────────────────────

    /**
     * @param  list<string>  $extraExclude
     * @return array{ok: bool, problems: list<string>, warnings: list<string>, tables: array<string, array<string, mixed>>, order: list<string>, target_facts: array<string, mixed>, source_rows: int, planned_rows: int}
     */
    public function plan(array $extraExclude = []): array
    {
        $problems = [];
        $warnings = [];
        $srcSchema = Schema::connection(self::SOURCE);
        $tgtSchema = Schema::connection(self::TARGET);

        $srcNames = $this->tableNames($srcSchema);
        $tgtNames = $this->tableNames($tgtSchema);
        $excluded = array_values(array_unique(array_merge((array) ($this->config['exclude'] ?? []), $extraExclude)));
        $reference = (array) ($this->config['reference_tables'] ?? []);

        $facts = app(DatabaseRuntimeInspector::class)->inspect(self::TARGET);

        if (! in_array($facts['driver'] ?? '', $this->allowedTargetDrivers(), true) || ($facts['reachable'] ?? false) !== true) {
            $problems[] = 'الهدف غير متاح أو ليس MySQL/MariaDB';
        } elseif ($this->isMysql()) {
            foreach ((new DatabaseFactsEvaluator)->evaluate($facts) as $result) {
                if (in_array($result['level'], ['fail', 'prodfail'], true) && in_array($result['key'], ['DB_CHARSET', 'DB_STRICT', 'DB_ENGINE', 'DB_VERSION'], true)) {
                    $problems[] = 'الهدف: '.$result['message'];
                }
            }
        }

        $this->checkMigrations($problems, $warnings);

        $tables = [];
        $planned = 0;
        $sourceRows = 0;

        foreach ($srcNames as $table) {
            if (in_array($table, $excluded, true)) {
                $tables[$table] = ['status' => 'excluded', 'source_rows' => $this->count($this->source, $table), 'target_rows' => 0];

                continue;
            }

            if (! in_array($table, $tgtNames, true)) {
                $problems[] = "الجدول {$table} موجود في المصدر وغير موجود في الهدف (شغّل php artisan migrate على الهدف أولًا)";

                continue;
            }

            $meta = $this->compareColumns($table, $srcSchema, $tgtSchema, $problems, $warnings);
            $srcCount = $this->count($this->source, $table);
            $tgtCount = $this->count($this->target, $table);
            $status = $srcCount === 0 ? 'empty' : 'copy';
            $seeded = 0;

            if ($tgtCount > 0) {
                if (isset($reference[$table])) {
                    $check = $this->referenceCheck($table, (string) $reference[$table]);

                    if ($check === null) {
                        $status = $srcCount === 0 ? 'reference-empty' : 'reference';
                        $seeded = $tgtCount;
                    } else {
                        $problems[] = "الجدول المرجعي {$table}: {$check}";
                    }
                } else {
                    $problems[] = "الجدول {$table} في الهدف غير فارغ ({$tgtCount} صف): لا دمج في E31 - استعمل --reset-target بتأكيد صريح أو قاعدة هدف جديدة";
                }
            }

            $this->preflightValues($table, $meta, $problems);
            $this->preflightUniqueCollisions($table, $meta, $problems);

            $tables[$table] = ['status' => $status, 'source_rows' => $srcCount, 'target_rows' => $tgtCount, 'seeded_rows' => $seeded] + $meta;
            $planned += $srcCount;
            $sourceRows += $srcCount;
        }

        foreach ($tgtNames as $table) {
            if (! in_array($table, $srcNames, true) && ! in_array($table, $excluded, true) && $table !== 'migrations') {
                $warnings[] = "الجدول {$table} موجود في الهدف فقط (مخطط أحدث من المصدر): يبقى فارغًا";
            }
        }

        return [
            'ok' => $problems === [],
            'problems' => $problems,
            'warnings' => $warnings,
            'tables' => $tables,
            'order' => $this->dependencyOrder(array_keys(array_filter($tables, static fn (array $t): bool => ! in_array($t['status'], ['excluded'], true))), $tgtSchema),
            'target_facts' => $facts,
            'source_rows' => $sourceRows,
            'planned_rows' => $planned,
        ];
    }

    // ───────────────────────────── التنفيذ ─────────────────────────────

    /**
     * يحذف (TRUNCATE) جداول التطبيق في الهدف. وضع تعافٍ صريح فقط (يقرّر الأمر الخارجي تأكيده)، ولا يلمس migrations.
     *
     * @param  array<string, mixed>  $plan
     * @return list<string> الجداول التي أُفرغت
     */
    public function resetTarget(array $plan): array
    {
        $cleared = [];

        try {
            $this->setForeignKeyChecks(false);

            foreach ($plan['tables'] as $table => $meta) {
                if ($meta['status'] === 'excluded' || $meta['target_rows'] === 0) {
                    continue;
                }

                $this->isMysql()
                    ? $this->target->statement('TRUNCATE TABLE '.$this->target->getQueryGrammar()->wrapTable($table))
                    : $this->target->table($table)->delete();
                $cleared[] = $table;
            }
        } finally {
            $this->setForeignKeyChecks(true);
        }

        return $cleared;
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  (callable(string, int, int): void)|null  $progress  (table, copied, total)
     * @return array{copied: array<string, int>, auto_increment: array<string, array<string, int>>}
     */
    public function execute(array $plan, ?callable $progress = null): array
    {
        if (! $plan['ok']) {
            throw new TransferException('الخطة غير سليمة: لا نسخ قبل معالجة المشكلات.');
        }

        $copied = [];
        $autoIncrement = [];

        try {
            foreach ($plan['order'] as $table) {
                $meta = $plan['tables'][$table];

                if (! in_array($meta['status'], ['copy', 'reference', 'reference-empty', 'empty'], true)) {
                    continue;
                }

                // الاتصال قد يُعاد فتحه بين الجداول الطويلة؛ إعادة الضبط idempotent.
                $this->setForeignKeyChecks(false);

                if (in_array($meta['status'], ['reference', 'reference-empty'], true)) {
                    $this->target->table($table)->delete(); // صفوف الترحيلات المرجعية فقط (تحقّق منها plan())، يحلّ محلها المصدر
                }

                $copied[$table] = $meta['source_rows'] === 0 ? 0 : $this->copyTable($table, $meta, $progress);

                if ($copied[$table] > 0 && ($fixed = $this->fixAutoIncrement($table, $meta)) !== null) {
                    $autoIncrement[$table] = $fixed;
                }
            }
        } finally {
            $this->setForeignKeyChecks(true);
        }

        if (! $this->foreignKeyChecksEnabled()) {
            throw new TransferException('تعذّر التأكد من إعادة تفعيل FOREIGN_KEY_CHECKS على الهدف - لا تستعمل هذه القاعدة قبل فحصها.');
        }

        return ['copied' => $copied, 'auto_increment' => $autoIncrement];
    }

    // ───────────────────────────── التحقق بعد النقل ─────────────────────────────

    /**
     * @param  array<string, mixed>  $plan
     * @param  (callable(string, string): void)|null  $progress  (stage, table)
     * @return array<string, mixed>
     */
    public function validate(array $plan, bool $fingerprints = true, ?callable $progress = null): array
    {
        $tables = [];
        $rowMismatches = 0;
        $fpMismatches = 0;
        $migrated = 0;
        $rows = 0;
        $skipped = 0;
        $tgtSchema = Schema::connection(self::TARGET);

        foreach ($plan['tables'] as $table => $meta) {
            if ($meta['status'] === 'excluded') {
                $skipped++;
                $tables[$table] = ['status' => 'excluded', 'source_rows' => $meta['source_rows'], 'target_rows' => null, 'row_match' => null, 'digest' => null, 'digest_match' => null];

                continue;
            }

            $progress && $progress('rows', $table);
            $tgtCount = $this->count($this->target, $table);
            $match = $tgtCount === $meta['source_rows'];
            $rowMismatches += $match ? 0 : 1;
            $row = ['status' => $meta['status'], 'source_rows' => $meta['source_rows'], 'target_rows' => $tgtCount, 'row_match' => $match, 'digest' => null, 'digest_match' => null];

            if ($fingerprints && $meta['source_rows'] > 0) {
                $progress && $progress('fingerprint', $table);
                $a = $this->digest($this->source, $table, $meta, true);
                $b = $this->digest($this->target, $table, $meta, false);
                $row['digest'] = $b['digest'];
                $row['digest_match'] = $a['digest'] === $b['digest'] && $a['rows'] === $b['rows'];
                $fpMismatches += $row['digest_match'] ? 0 : 1;
            }

            $migrated++;
            $rows += $tgtCount;
            $tables[$table] = $row;
        }

        $progress && $progress('orphans', '*');
        $orphans = $this->orphans($plan, $tgtSchema);
        $integrity = array_sum(array_column($orphans, 'count'));

        $progress && $progress('auto_increment', '*');
        $autoIncrement = $this->autoIncrementReport($plan);
        $aiIssues = count(array_filter($autoIncrement, static fn (array $r): bool => ! $r['ok']));

        $progress && $progress('financial', '*');
        $financial = $this->financial($plan);
        $financialMismatches = count(array_filter($financial, static fn (array $r): bool => ! $r['match']));

        $pass = $rowMismatches === 0 && $fpMismatches === 0 && $integrity === 0 && $aiIssues === 0 && $financialMismatches === 0;

        return [
            'tables' => $tables,
            'totals' => [
                'tables_migrated' => $migrated, 'rows_migrated' => $rows, 'skipped_ephemeral' => $skipped,
                'row_count_mismatches' => $rowMismatches, 'integrity_violations' => $integrity, 'fingerprint_mismatches' => $fpMismatches,
                'auto_increment_issues' => $aiIssues, 'financial_mismatches' => $financialMismatches,
            ],
            'orphans' => $orphans,
            'auto_increment' => $autoIncrement,
            'financial' => $financial,
            'fingerprints_checked' => $fingerprints,
            'result' => $pass ? 'PASS' : 'FAIL',
        ];
    }

    // ───────────────────────────── النسخ ─────────────────────────────

    /**
     * @param  array<string, mixed>  $meta
     * @param  (callable(string, int, int): void)|null  $progress
     */
    private function copyTable(string $table, array $meta, ?callable $progress): int
    {
        $columns = $meta['shared_columns'];
        $json = array_flip($meta['json_columns']);
        $maxRows = $this->batchRows(count($columns));
        $maxBytes = max(65536, (int) ($this->config['max_batch_bytes'] ?? 1_000_000));
        $last = 0;
        $total = 0;
        $select = array_merge([$this->source->raw('rowid as "__e31_rid"')], $columns);

        while (true) {
            $chunk = $this->source->table($table)->select($select)->whereRaw('rowid > ?', [$last])->orderByRaw('rowid')->limit($maxRows)->get();

            if ($chunk->isEmpty()) {
                break;
            }

            $batch = [];
            $rids = [];
            $bytes = 0;

            foreach ($chunk as $record) {
                $row = (array) $record;
                $rid = (int) $row['__e31_rid'];
                unset($row['__e31_rid']);
                $last = $rid;

                foreach ($json as $column => $_) {
                    if ($row[$column] !== null && ! $this->validJson((string) $row[$column])) {
                        throw new TransferException("الجدول {$table}: العمود {$column} يحوي JSON غير صالح في الصف rowid={$rid} (لن يقبله MySQL) - صحّح البيانات في المصدر.");
                    }
                }

                $batch[] = $row;
                $rids[] = $rid;
                $bytes += $this->approxBytes($row);

                if (count($batch) >= $maxRows || $bytes >= $maxBytes) {
                    $this->insertBatch($table, $batch, $rids);
                    $total += count($batch);
                    $batch = [];
                    $rids = [];
                    $bytes = 0;
                }
            }

            if ($batch !== []) {
                $this->insertBatch($table, $batch, $rids);
                $total += count($batch);
            }

            $progress && $progress($table, $total, (int) $meta['source_rows']);
        }

        return $total;
    }

    /**
     * @param  list<array<string, mixed>>  $batch
     * @param  list<int>  $rids
     */
    private function insertBatch(string $table, array $batch, array $rids): void
    {
        try {
            $this->target->transaction(fn () => $this->target->table($table)->insert($batch));
        } catch (Throwable $e) {
            throw $this->diagnose($table, $batch, $rids, $e);
        }
    }

    /**
     * عند فشل دفعة: يحدّد الصف المسبب بإدراج صف-صف داخل معاملة تُلغى دائمًا، ويُرجع خطأ بلا أي قيمة من البيانات.
     *
     * @param  list<array<string, mixed>>  $batch
     * @param  list<int>  $rids
     */
    private function diagnose(string $table, array $batch, array $rids, Throwable $original): TransferException
    {
        $info = self::sanitize($original);

        try {
            $this->target->beginTransaction();

            try {
                foreach ($batch as $i => $row) {
                    try {
                        $this->target->table($table)->insert([$row]);
                    } catch (Throwable $e) {
                        $info = self::sanitize($e);
                        $id = $row['id'] ?? $rids[$i];

                        return new TransferException("الجدول {$table}: رُفض الصف id={$id} - {$info['category']} (MySQL {$info['code']})".($info['column'] !== null ? "، العمود {$info['column']}" : '').($info['key'] !== null ? "، الفهرس {$info['key']}" : '').'.');
                    }
                }
            } finally {
                $this->target->rollBack();
            }
        } catch (Throwable) {
            // فشل التشخيص نفسه: نكتفي بالتصنيف الأول.
        }

        return new TransferException("الجدول {$table}: رُفضت دفعة من ".count($batch)." صفًّا - {$info['category']} (MySQL {$info['code']}).");
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, int>|null
     */
    private function fixAutoIncrement(string $table, array $meta): ?array
    {
        $column = $meta['auto_increment_column'] ?? null;

        if ($column === null) {
            return null;
        }

        $maxId = (int) $this->target->table($table)->max($column);
        $sequence = 0;

        try {
            $sequence = (int) ($this->source->table('sqlite_sequence')->where('name', $table)->value('seq') ?? 0);
        } catch (Throwable) {
            // لا جدول sqlite_sequence (لا AUTOINCREMENT): المعرّف التالي يكفيه max(id).
        }

        $wanted = max($maxId, $sequence) + 1;
        $current = $this->currentAutoIncrement($table);

        if ($current < $wanted && $this->isMysql()) {
            $this->target->statement('ALTER TABLE '.$this->target->getQueryGrammar()->wrapTable($table).' AUTO_INCREMENT = '.$wanted);
            $current = $wanted;
        }

        return ['max_id' => $maxId, 'sqlite_sequence' => $sequence, 'next' => $current];
    }

    // ───────────────────────────── مقارنة وفحوص ─────────────────────────────

    /**
     * @param  array<string, mixed>  $meta
     * @return array{rows: int, digest: string}
     */
    private function digest(Connection $conn, string $table, array $meta, bool $isSource): array
    {
        $columns = $meta['shared_columns'];
        sort($columns);
        $classes = $meta['classes'];
        $json = array_flip($meta['json_columns']);
        $lanes = [0, 0, 0, 0];
        $count = 0;
        $size = 2000;

        if ($isSource) {
            $last = 0;
            $select = array_merge([$conn->raw('rowid as "__e31_rid"')], $columns);

            while (true) {
                $chunk = $conn->table($table)->select($select)->whereRaw('rowid > ?', [$last])->orderByRaw('rowid')->limit($size)->get();

                if ($chunk->isEmpty()) {
                    break;
                }

                foreach ($chunk as $record) {
                    $row = (array) $record;
                    $last = (int) $row['__e31_rid'];
                    unset($row['__e31_rid']);
                    $this->accumulate($lanes, $columns, $row, $classes, $json);
                    $count++;
                }
            }
        } else {
            $primary = (array) ($meta['target_primary'] ?? []);
            $keyset = count($primary) === 1 && ($classes[$primary[0]] ?? '') === 'int' ? $primary[0] : null;
            $offset = 0;
            $lastKey = null;

            while (true) {
                $q = $conn->table($table)->select($columns);

                if ($keyset !== null) {
                    $q->select(array_values(array_unique(array_merge([$keyset], $columns))));
                    if ($lastKey !== null) {
                        $q->where($keyset, '>', $lastKey);
                    }

                    $q->orderBy($keyset)->limit($size);
                } else {
                    foreach (($primary !== [] ? $primary : $columns) as $orderColumn) {
                        $q->orderBy($orderColumn);
                    }
                    $q->offset($offset)->limit($size);
                }

                $chunk = $q->get();

                if ($chunk->isEmpty()) {
                    break;
                }

                foreach ($chunk as $record) {
                    $row = (array) $record;

                    if ($keyset !== null) {
                        $lastKey = $row[$keyset];
                    }

                    $this->accumulate($lanes, $columns, $row, $classes, $json);
                    $count++;
                }

                $offset += $size;
            }
        }

        return ['rows' => $count, 'digest' => substr(hash('sha256', implode('.', $lanes).'#'.$count), 0, 24)];
    }

    /**
     * @param  list<int>  $lanes
     * @param  list<string>  $columns
     * @param  array<string, mixed>  $row
     * @param  array<string, string>  $classes
     * @param  array<string, int>  $json
     */
    private function accumulate(array &$lanes, array $columns, array $row, array $classes, array $json): void
    {
        $payload = '';

        foreach ($columns as $column) {
            $value = self::canonical($row[$column] ?? null, $classes[$column] ?? 'string', isset($json[$column]));
            $payload .= $column.'='.strlen($value).':'.$value."\n";
        }

        $parts = unpack('N4', substr(hash('sha256', $payload, true), 0, 16));

        foreach ([0, 1, 2, 3] as $i) {
            $lanes[$i] += $parts[$i + 1];
        }
    }

    /** تمثيل قانوني موحّد للقيمة كي تتطابق البصمة رغم اختلاف تمثيل SQLite وMySQL (int مقابل string، ترتيب مفاتيح JSON...). */
    public static function canonical(mixed $value, string $class, bool $isJson = false): string
    {
        if ($value === null) {
            return "\0null";
        }

        if ($isJson) {
            $decoded = json_decode((string) $value, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                return json_encode(self::sortKeys($decoded), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) ?: '';
            }
        }

        return match ($class) {
            'int', 'bool', 'decimal', 'float' => self::number($value),
            'binary' => 'b64:'.base64_encode((string) $value),
            default => (string) $value,
        };
    }

    public static function number(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        $s = is_float($value) ? sprintf('%.10F', $value) : trim((string) $value);

        if (preg_match('/^([+-]?)(\d+)(?:\.(\d+))?$/', $s, $m) !== 1) {
            return $s;
        }

        $int = ltrim($m[2], '0');
        $int = $int === '' ? '0' : $int;
        $frac = isset($m[3]) ? rtrim($m[3], '0') : '';
        $out = $int.($frac !== '' ? '.'.$frac : '');

        return ($m[1] === '-' && $out !== '0' ? '-' : '').$out;
    }

    private static function sortKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map([self::class, 'sortKeys'], $value);

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return list<array{table: string, fk: string, count: int}>
     */
    private function orphans(array $plan, SchemaBuilder $tgtSchema): array
    {
        $result = [];

        foreach ($plan['tables'] as $table => $meta) {
            if ($meta['status'] === 'excluded') {
                continue;
            }

            foreach ($tgtSchema->getForeignKeys($table) as $fk) {
                $refTable = (string) $fk['foreign_table'];
                $q = $this->target->table("{$table} as c")->leftJoin("{$refTable} as p", function ($join) use ($fk) {
                    foreach ($fk['columns'] as $i => $column) {
                        $join->on("c.{$column}", '=', 'p.'.$fk['foreign_columns'][$i]);
                    }
                })->whereNull('p.'.$fk['foreign_columns'][0]);

                foreach ($fk['columns'] as $column) {
                    $q->whereNotNull("c.{$column}");
                }

                $count = (int) $q->count();

                if ($count > 0) {
                    $result[] = ['table' => $table, 'fk' => implode(',', $fk['columns']).'→'.$refTable, 'count' => $count];
                }
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, array{ok: bool, max_id: int, next: int|null}>
     */
    private function autoIncrementReport(array $plan): array
    {
        $report = [];

        foreach ($plan['tables'] as $table => $meta) {
            $column = $meta['auto_increment_column'] ?? null;

            if ($meta['status'] === 'excluded' || $column === null || $meta['source_rows'] === 0) {
                continue;
            }

            $maxId = (int) $this->target->table($table)->max($column);
            $next = $this->currentAutoIncrement($table);
            $report[$table] = ['ok' => $next > $maxId, 'max_id' => $maxId, 'next' => $next];
        }

        return $report;
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return list<array{table: string, column: string, source: string, target: string, match: bool}>
     */
    private function financial(array $plan): array
    {
        $out = [];

        foreach ((array) ($this->config['financial_sums'] ?? []) as $table => $columns) {
            if (! isset($plan['tables'][$table]) || $plan['tables'][$table]['status'] === 'excluded') {
                continue;
            }

            foreach ($columns as $column) {
                if (! in_array($column, $plan['tables'][$table]['shared_columns'], true)) {
                    continue;
                }

                $a = self::number($this->source->table($table)->sum($column) ?? 0);
                $b = self::number($this->target->table($table)->sum($column) ?? 0);
                $out[] = ['table' => $table, 'column' => $column, 'source' => $a, 'target' => $b, 'match' => $a === $b];
            }
        }

        return $out;
    }

    // ───────────────────────────── تحضير وفحوص ما قبل النقل ─────────────────────────────

    /**
     * @param  list<string>  $problems
     * @param  list<string>  $warnings
     * @return array<string, mixed>
     */
    private function compareColumns(string $table, SchemaBuilder $srcSchema, SchemaBuilder $tgtSchema, array &$problems, array &$warnings): array
    {
        $src = [];
        foreach ($srcSchema->getColumns($table) as $column) {
            $src[(string) $column['name']] = $column;
        }

        $tgt = [];
        foreach ($tgtSchema->getColumns($table) as $column) {
            $tgt[(string) $column['name']] = $column;
        }

        foreach (array_diff(array_keys($src), array_keys($tgt)) as $missing) {
            $problems[] = "الجدول {$table}: العمود {$missing} موجود في المصدر وغير موجود في الهدف (فقدان بيانات) - طابق مخطط الهدف مع المصدر";
        }

        foreach (array_diff(array_keys($tgt), array_keys($src)) as $extra) {
            $column = $tgt[$extra];

            if (! $column['nullable'] && $column['default'] === null && ! $column['auto_increment'] && empty($column['generation'])) {
                $problems[] = "الجدول {$table}: العمود {$extra} إلزامي في الهدف بلا قيمة افتراضية وغير موجود في المصدر";
            } else {
                $warnings[] = "الجدول {$table}: العمود {$extra} موجود في الهدف فقط ويأخذ قيمته الافتراضية";
            }
        }

        $shared = array_values(array_filter(array_keys($tgt), static fn (string $name): bool => isset($src[$name])));
        $classes = [];
        $json = [];
        $auto = null;

        foreach ($shared as $name) {
            $type = strtolower((string) $tgt[$name]['type']);
            $classes[$name] = SchemaSnapshot::typeClass($type);

            if (($tgt[$name]['type_name'] ?? '') === 'json' || $type === 'json') {
                $json[] = $name;
            }

            if (! empty($tgt[$name]['auto_increment'])) {
                $auto = $name;
            }
        }

        $primary = [];
        $unique = [];

        foreach ($tgtSchema->getIndexes($table) as $index) {
            if (! empty($index['primary'])) {
                $primary = array_values(array_map('strval', $index['columns']));
            } elseif (! empty($index['unique'])) {
                $unique[] = array_values(array_map('strval', $index['columns']));
            }
        }

        return [
            'shared_columns' => $shared, 'classes' => $classes, 'json_columns' => $json, 'auto_increment_column' => $auto,
            'target_primary' => $primary, 'target_unique' => $unique, 'target_types' => array_map(static fn (array $c): string => strtolower((string) $c['type']), $tgt),
        ];
    }

    /** تعبير SQLite لطول نص: المعرّف يأتي من مخطط الهدف (لا من طلب) ويُقتبس بقواعد الاتصال نفسه. */
    private function lengthExpression(string $column): string
    {
        return 'length('.$this->source->getQueryGrammar()->wrap($column).')';
    }

    private function jsonValidExpression(string $column): string
    {
        return 'json_valid('.$this->source->getQueryGrammar()->wrap($column).')';
    }

    /**
     * فحوص قراءة فقط على المصدر لقيم يقبلها SQLite ويرفضها MySQL الصارم (أعداد فقط في الرسائل - لا قيم).
     *
     * @param  array<string, mixed>  $meta
     * @param  list<string>  $problems
     */
    private function preflightValues(string $table, array $meta, array &$problems): void
    {
        foreach ($meta['shared_columns'] as $column) {
            $type = $meta['target_types'][$column] ?? '';

            try {
                if (str_contains($type, 'unsigned') && SchemaSnapshot::typeClass($type) === 'int') {
                    $n = $this->source->table($table)->where($column, '<', 0)->count();
                    $n > 0 && $problems[] = "الجدول {$table}: {$n} صف/صفوف بقيمة سالبة في العمود UNSIGNED {$column} (يرفضها MySQL)";
                }

                if (preg_match('/^(?:var)?char\((\d+)\)/', $type, $m) === 1) {
                    $n = $this->source->table($table)->whereRaw($this->lengthExpression($column).' > ?', [(int) $m[1]])->count();
                    $n > 0 && $problems[] = "الجدول {$table}: {$n} صف/صفوف في {$column} أطول من {$m[1]} محرفًا (يرفضها MySQL الصارم)";
                }

                if (in_array($column, $meta['json_columns'], true)) {
                    $n = $this->source->table($table)->whereNotNull($column)->whereRaw($this->jsonValidExpression($column).' = 0')->count();
                    $n > 0 && $problems[] = "الجدول {$table}: {$n} صف/صفوف في {$column} بـJSON غير صالح (يرفضه MySQL)";
                }

                if (in_array($type, ['timestamp', 'datetime', 'date'], true) || str_starts_with($type, 'timestamp(') || str_starts_with($type, 'datetime(')) {
                    $n = $this->source->table($table)->where($column, 'like', '0000-00-00%')->count();
                    $n > 0 && $problems[] = "الجدول {$table}: {$n} صف/صفوف في {$column} بتاريخ صفري (0000-00-00) يرفضه MySQL";

                    if (str_starts_with($type, 'timestamp')) {
                        $n = $this->source->table($table)->whereNotNull($column)->where(fn ($q) => $q->where($column, '<', '1970-01-01 00:00:01')->orWhere($column, '>', '2038-01-19 03:14:07'))->where($column, 'not like', '0000-00-00%')->count();
                        $n > 0 && $problems[] = "الجدول {$table}: {$n} صف/صفوف في {$column} خارج نطاق TIMESTAMP (1970..2038) - غيّر العمود إلى DATETIME أو صحّح البيانات";
                    }
                }
            } catch (Throwable) {
                // دالة غير متاحة في هذا البناء من SQLite (مثل json_valid): نتجاوز هذا الفحص ويلتقطه فحص النسخ نفسه.
            }
        }
    }

    /**
     * utf8mb4_unicode_ci غير حساس لحالة الأحرف (وللتشكيل/اللكنات) بينما SQLite حساس: صفّان مختلفان هناك (A@x.com وa@x.com) يتصادمان على فهرس فريد هنا.
     * نكشف تصادم **الحالة اللاتينية** مسبقًا (lower() في SQLite)؛ وبقية الحالات (لكنات/تشكيل) تُلتقط وقت النسخ برمز 1062 بلا قيم.
     *
     * @param  array<string, mixed>  $meta
     * @param  list<string>  $problems
     */
    private function preflightUniqueCollisions(string $table, array $meta, array &$problems): void
    {
        $grammar = $this->source->getQueryGrammar();

        foreach ($meta['target_unique'] ?? [] as $columns) {
            $strings = array_filter($columns, static fn (string $c): bool => ($meta['classes'][$c] ?? '') === 'string');

            if ($strings === [] || array_diff($columns, $meta['shared_columns']) !== []) {
                continue;
            }

            $group = implode(', ', array_map(static fn (string $c): string => in_array($c, $strings, true) ? 'lower('.$grammar->wrap($c).')' : $grammar->wrap($c), $columns));
            $notNull = implode(' and ', array_map(static fn (string $c): string => $grammar->wrap($c).' is not null', $columns));

            try {
                $row = $this->source->selectOne('select count(*) as n from (select 1 from '.$grammar->wrapTable($table)." where {$notNull} group by {$group} having count(*) > 1) as d");
            } catch (Throwable) {
                continue;
            }

            if ((int) ($row->n ?? 0) > 0) {
                $problems[] = "الجدول {$table}: {$row->n} مجموعة/مجموعات مكررة بتجاهل حالة الأحرف على الفهرس الفريد (".implode(',', $columns).') - ستتصادم في MySQL (تجميع غير حساس للحالة)؛ وحِّدها في المصدر أولًا';
            }
        }
    }

    /** @return string|null null = الصفوف المرجعية سليمة، وإلا سبب الرفض. */
    private function referenceCheck(string $table, string $naturalKey): ?string
    {
        if ($this->count($this->target, $table) > 500) {
            return 'عدد صفوف الهدف كبير جدًا لجدول مرجعي زرعته الترحيلات';
        }

        try {
            $targetRows = $this->target->table($table)->select(['id', $naturalKey])->get();
            $byKey = $this->source->table($table)->pluck('id', $naturalKey)->all();
            $byId = $this->source->table($table)->pluck($naturalKey, 'id')->all();
        } catch (Throwable) {
            return 'تعذّر مقارنة الصفوف المرجعية بين المصدر والهدف';
        }

        foreach ($targetRows as $row) {
            $key = (string) $row->{$naturalKey};
            $id = (int) $row->id;

            if (isset($byKey[$key])) {
                if ((int) $byKey[$key] !== $id) {
                    return "تعارض معرّف: المفتاح الطبيعي {$naturalKey} نفسه بمعرّف مختلف بين المصدر والهدف";
                }
            } elseif (isset($byId[$id])) {
                return "تعارض معرّف: المعرّف {$id} مستعمل بمفتاح طبيعي مختلف في المصدر";
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $problems
     * @param  list<string>  $warnings
     */
    private function checkMigrations(array &$problems, array &$warnings): void
    {
        try {
            $src = $this->source->table('migrations')->pluck('migration')->all();
        } catch (Throwable) {
            $warnings[] = 'جدول migrations غير موجود في المصدر: لا يمكن مقارنة نسخة المخطط';

            return;
        }

        try {
            $tgt = $this->target->table('migrations')->pluck('migration')->all();
        } catch (Throwable) {
            $problems[] = 'جدول migrations غير موجود في الهدف: شغّل php artisan migrate على قاعدة MySQL أولًا';

            return;
        }

        $missing = count(array_diff($src, $tgt));
        $newer = count(array_diff($tgt, $src));

        if ($missing > 0) {
            $problems[] = "الهدف ينقصه {$missing} ترحيل/ترحيلات منفَّذة في المصدر: شغّل php artisan migrate على الهدف بالكود الحالي";
        }

        if ($newer > 0) {
            $warnings[] = "الهدف عليه {$newer} ترحيل/ترحيلات إضافية لم تُنفَّذ على المصدر (مخطط أحدث): أعمدتها الجديدة تأخذ قيمها الافتراضية";
        }
    }

    // ───────────────────────────── أدوات ─────────────────────────────

    /**
     * ترتيب الجداول: الأمهات قبل الأبناء (من مفاتيح الهدف الأجنبية)، تُكسَر الدورات/الذاتية بالترتيب الأبجدي.
     *
     * @param  list<string>  $tables
     * @return list<string>
     */
    private function dependencyOrder(array $tables, SchemaBuilder $tgtSchema): array
    {
        sort($tables);
        $set = array_flip($tables);
        $deps = [];

        foreach ($tables as $table) {
            $deps[$table] = [];

            foreach ($tgtSchema->getForeignKeys($table) as $fk) {
                $parent = (string) $fk['foreign_table'];

                if ($parent !== $table && isset($set[$parent])) {
                    $deps[$table][$parent] = true;
                }
            }
        }

        $ordered = [];
        $remaining = $deps;

        while ($remaining !== []) {
            $ready = array_keys(array_filter($remaining, static fn (array $d): bool => array_diff_key($d, array_flip($ordered)) === []));

            if ($ready === []) {
                $ready = [array_key_first($remaining)]; // دورة: نكسرها (المفاتيح الأجنبية معطّلة أثناء النسخ على أي حال)
            }

            foreach ($ready as $table) {
                $ordered[] = $table;
                unset($remaining[$table]);
            }
        }

        return $ordered;
    }

    /** @return list<string> */
    private function allowedTargetDrivers(): array
    {
        return (array) ($this->config['target_drivers'] ?? ['mysql', 'mariadb']);
    }

    private function isMysql(): bool
    {
        return in_array($this->target->getDriverName(), ['mysql', 'mariadb'], true);
    }

    private function setForeignKeyChecks(bool $on): void
    {
        $this->isMysql()
            ? $this->target->statement('SET FOREIGN_KEY_CHECKS='.($on ? '1' : '0'))
            : $this->target->statement('PRAGMA foreign_keys = '.($on ? 'ON' : 'OFF'));
    }

    private function foreignKeyChecksEnabled(): bool
    {
        if ($this->isMysql()) {
            return (int) ($this->target->selectOne('select @@foreign_key_checks as fk')->fk ?? 0) === 1;
        }

        return (int) ((array) $this->target->selectOne('PRAGMA foreign_keys'))['foreign_keys'] === 1;
    }

    /** @return list<string> */
    private function tableNames(SchemaBuilder $schema): array
    {
        $names = [];

        foreach ($schema->getTables() as $table) {
            $name = (string) $table['name'];

            if (! str_starts_with($name, 'sqlite_')) {
                $names[] = $name;
            }
        }

        sort($names);

        return $names;
    }

    private function count(Connection $conn, string $table): int
    {
        return (int) $conn->table($table)->count();
    }

    private function currentAutoIncrement(string $table): int
    {
        if (! $this->isMysql()) {
            // SQLite (يُستعمل في الاختبارات فقط): sqlite_sequence يتبع أعلى معرّف أُدرج صراحةً.
            try {
                return (int) ($this->target->table('sqlite_sequence')->where('name', $table)->value('seq') ?? 0) + 1;
            } catch (Throwable) {
                return 0;
            }
        }

        try {
            $this->target->statement('SET SESSION information_schema_stats_expiry = 0'); // MySQL 8: بلا ذلك قد تُرجع information_schema قيمة قديمة مخزّنة
        } catch (Throwable) {
            // MariaDB لا تعرف هذا المتغير ولا تحتاجه.
        }

        $row = $this->target->selectOne('select auto_increment as ai from information_schema.tables where table_schema = database() and table_name = ?', [$table]);

        return (int) ($row->ai ?? 0);
    }

    private function batchRows(int $columnCount): int
    {
        $size = max(500, min(2000, (int) ($this->config['batch_size'] ?? 1000)));

        return max(1, min($size, intdiv(60000, max(1, $columnCount))));
    }

    /** @param  array<string, mixed>  $row */
    private function approxBytes(array $row): int
    {
        $bytes = 0;

        foreach ($row as $value) {
            $bytes += is_string($value) ? strlen($value) + 4 : 12;
        }

        return $bytes;
    }

    private function validJson(string $value): bool
    {
        if (function_exists('json_validate')) {
            return json_validate($value);
        }

        json_decode($value);

        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * يحوّل أي استثناء قاعدة إلى وصف آمن: رمز MySQL وتصنيف واسم العمود/الفهرس فقط. لا قيم ولا نص الخادم.
     *
     * @return array{code: int|string, category: string, column: string|null, key: string|null}
     */
    public static function sanitize(Throwable $e): array
    {
        $previous = $e->getPrevious() ?? $e;
        $info = $previous instanceof \PDOException ? $previous->errorInfo : null;
        $code = $info[1] ?? ($previous->getCode() ?: 0);
        $message = $previous->getMessage();

        $category = match ((int) $code) {
            1062 => 'تكرار في فهرس فريد (قد يكون تصادم تجميع utf8mb4_unicode_ci غير الحساس للحالة)',
            1048 => 'قيمة NULL في عمود NOT NULL',
            1406 => 'قيمة أطول من طول العمود',
            1366 => 'قيمة غير صالحة للعمود (ترميز أو نوع)',
            1292 => 'قيمة تاريخ/رقم غير صالحة',
            1264 => 'قيمة خارج نطاق العمود الرقمي (مثل سالب في UNSIGNED)',
            1265 => 'بيانات مقتطعة',
            3140 => 'JSON غير صالح',
            1452, 1451 => 'انتهاك مفتاح أجنبي',
            1153, 2006 => 'تجاوز max_allowed_packet أو انقطاع الاتصال',
            default => 'رفض من الخادم',
        };

        return [
            'code' => $code,
            'category' => $category,
            'column' => preg_match("/column '([A-Za-z0-9_]+)'/i", $message, $m) === 1 ? $m[1] : null,
            'key' => preg_match("/for key '([A-Za-z0-9_.]+)'/i", $message, $k) === 1 ? $k[1] : null,
        ];
    }
}
`````

### `app/Services/Database/TransactionPolicy.php`

`sha256: 8da7c35e303b080a4803e38da11882d3d88d922bca8e9218b28250a714edba4d`

`````php
<?php

namespace App\Services\Database;

/**
 * E31 (P): سياسة إعادة المحاولة عند الجمود (deadlock) في MySQL. مصدر الرقم الوحيد، كي لا تتناثر أرقام سحرية.
 *
 * القاعدة: **لا إعادة محاولة بالجملة.** تُستعمل فقط في مداخل المعاملات الخارجية التي تجمع الشروط الثلاثة:
 *  1) نمط «قفل نطاق فارغ ثم إدراج» (SELECT ... FOR UPDATE على صف غير موجود يأخذ gap-lock في REPEATABLE READ؛ طلبان متزامنان يتبادلان الانتظار على الإدراج ← الخطأ 1213 في MySQL، ولا يحدث هذا في SQLite لأنه يُسلسل الكتابة).
 *  2) المعاملة كلها كتابات قاعدة بيانات تُتراجع بالكامل عند الفشل: لا بريد/بث/كاش/ملفات/أحداث داخل الإغلاق (الأحداث والإشعارات بعد commit).
 *  3) إعادة التنفيذ آمنة دلاليًا: إما idempotent بطبيعتها (الجولة الثانية ترى نتيجة الأولى وتعيدها) أو مقيَّدة بقيد UNIQUE يحسم السباق.
 *
 * كيف تعمل: Laravel يعيد الإغلاق فقط عند أخطاء التزامن (deadlock/lock wait timeout) وفقط في المستوى الخارجي من المعاملات؛ أخطاء العمل (RuntimeException...)
 * تمرّ فورًا بلا تكرار. داخل معاملة قائمة (مثل اختبارات RefreshDatabase) لا تُعاد المحاولة (يترك Laravel القرار للمعاملة الخارجية).
 *
 * المواضع المعتمدة (يثبّتها اختبار E31: أي attempts خارج هذه القائمة يفشل):
 *  PuzzleAttemptService::attempt · GameSessionService::start · CompetitiveRunService::start · CreatorApplicationService::apply
 */
final class TransactionPolicy
{
    /** عدد المحاولات الكلي (الأولى + الإعادات). صغير عمدًا: جمود متكرر يعني خللًا في التصميم لا يعالجه التكرار. */
    public const DEADLOCK_ATTEMPTS = 3;
}
`````

### `app/Services/Database/TransferException.php`

`sha256: 4833214e01cd18743269b1155ee895666f1303ea905e98608f08aa38a8978303`

`````php
<?php

namespace App\Services\Database;

/**
 * E31: خطأ نقل بيانات برسالة آمنة بالتصميم (اسم جدول/عمود/معرّف صف/رمز خطأ فقط). لا تحمل أبدًا قيمًا من الصفوف ولا نص استثناء الخادم
 * (رسائل MySQL قد تتضمن قيمًا مثل «Duplicate entry '...'» أو «Incorrect string value»).
 */
final class TransferException extends \RuntimeException {}
`````

### `config/db_transfer.php`

`sha256: ac5c826b234da252c0126c84260acb4003c59e7ed79b5a3e4a1022690e8750ec`

`````php
<?php

/*
|--------------------------------------------------------------------------
| E31 - نقل البيانات من SQLite إلى MySQL (php artisan db:transfer-sqlite-to-mysql)
|--------------------------------------------------------------------------
| أداة محلية/تجريبية لمرة واحدة، تُرفض عند APP_ENV=production. كل القيم اختيارية؛ الأمر يعمل بلا أي منها (يستعمل اتصال mysql الحالي).
| لا أسرار هنا: كلمات المرور تأتي من البيئة فقط ولا تُطبع أبدًا.
|
| المصدر: ملف SQLite يُمرَّر بـ--source أو MIGRATION_SQLITE_DATABASE (مسار مطلق أو نسبي لجذر المشروع). الافتراضي: قاعدة اتصال sqlite الحالية.
|        تنبيه: إن بدّلت DB_CONNECTION=mysql وبقي DB_DATABASE باسم قاعدة MySQL فلن يجد الأمر الملف - مرِّره صراحةً.
| الهدف: اتصال mysql (DB_*) أو أي اتصال مسمّى mysql/mariadb عبر --target. تجاوزات اختيارية بلا تكرار الإعداد: MIGRATION_MYSQL_{HOST,PORT,DATABASE,USERNAME,PASSWORD}.
*/

return [

    'target_connection' => env('MIGRATION_TARGET_CONNECTION', 'mysql'),

    'sqlite_database' => env('MIGRATION_SQLITE_DATABASE'),

    /*
    | تجاوزات إعداد الهدف (تُهمَل القيمة الفارغة). تسمح ببقاء DB_* في .env على SQLite أثناء مرحلة النقل.
    */
    'target_overrides' => array_filter([
        'host' => env('MIGRATION_MYSQL_HOST'),
        'port' => env('MIGRATION_MYSQL_PORT'),
        'database' => env('MIGRATION_MYSQL_DATABASE'),
        'username' => env('MIGRATION_MYSQL_USERNAME'),
        'password' => env('MIGRATION_MYSQL_PASSWORD'),
    ], static fn ($value) => $value !== null && $value !== ''),

    /*
    | حجم الدفعة (صفوف لكل INSERT). يُقيَّد داخل [500, 2000]؛ ويُخفَّض تلقائيًا كي لا يتجاوز عدد المتغيرات 60000 (حد MySQL 65535)
    | ولا حجم الدفعة التقريبي max_batch_bytes (حماية من max_allowed_packet عند صفوف كبيرة).
    */
    'batch_size' => (int) env('MIGRATION_BATCH_SIZE', 1000),
    'max_batch_bytes' => (int) env('MIGRATION_MAX_BATCH_BYTES', 1_000_000),

    /*
    | الجداول المؤقتة التشغيلية: لا تُنقل (جلسات المستخدمين تُنهى عند التحويل؛ الكاش يُعاد بناؤه؛ طابور قاعدة البيانات الحيّ لا يُنقل
    | كي لا تُنفَّذ مهام قديمة مرتين؛ failed_jobs تُترك للنظافة؛ رموز إعادة تعيين كلمة المرور قصيرة العمر).
    | جدول migrations لا يُنقل أبدًا (هدفه يُبنى بـphp artisan migrate).
    */
    'exclude' => [
        'migrations', 'cache', 'cache_locks', 'sessions', 'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens',
    ],

    /*
    | جداول تُملأ بصفوف مرجعية بواسطة الترحيلات نفسها (currencies عبر seed_system_currencies، supporter_tiers عبر create_sponsorship_tables).
    | الهدف بعد migrate لا يكون «فارغًا» فيها. الأمر يقبل ذلك فقط إذا كان كل صف في الهدف موجودًا في المصدر بالمفتاح الطبيعي نفسه وبالمعرّف نفسه،
    | ثم يحذف تلك الصفوف المرجعية ويستبدلها بصفوف المصدر (المصدر هو الحقيقة). أي تعارض معرّف يوقف الأمر. الصيغة: جدول => عمود المفتاح الطبيعي.
    */
    'reference_tables' => [
        'currencies' => 'internal_key',
        'supporter_tiers' => 'slug',
    ],

    /*
    | فحوص المال التي تُقارَن مجاميعها بالأعداد الصحيحة بين المصدر والهدف (وحدات صغرى؛ لا float). جدول => أعمدة.
    */
    'financial_sums' => [
        'wallets' => ['pending_balance', 'available_balance', 'lifetime_earned', 'lifetime_redeemed'],
        'currency_transactions' => ['amount'],
        'redemption_requests' => ['gems_amount'],
        'store_purchases' => ['price_amount'],
        'currency_packs' => ['base_amount', 'bonus_amount', 'price_minor'],
        'sponsorship_contributions' => ['original_amount_minor', 'accounting_amount_minor'],
        'sponsorship_reward_bonuses' => ['base_amount', 'calculated_bonus_amount', 'bonus_amount', 'final_amount', 'funding_snapshot_minor'],
        'competitive_reward_grants' => ['amount'],
        'xp_transactions' => ['amount'],
    ],
];
`````

### `docs/mysql-production-e31.md`

`sha256: 8be44f0bedf5b413847bef0528cdb56221e7378297db78d7b54c52badaf5ad95`

`````markdown
# MySQL للإنتاج: التكافؤ، نقل البيانات من SQLite، تحصين القاعدة والتزامن (E31)

> **حالة الدليل التشغيلي:** كل ما يمكن إثباته بلا خادم MySQL أُثبت وأُختبر (ترجمة كل الترحيلات إلى MySQL وتحليلها، منطق النقل والتحقق على SQLite→SQLite، التزامن والسياسات).
> كل ما يتطلب خادم MySQL فعليًا موسوم هنا **«PENDING USER-LOCAL MYSQL VALIDATION»** إلى أن تشغّل [المصفوفة المحلية](#6-مصفوفة-اختبارات-mysql-المحلية) وتنجح. لا تُعدّ E31 مغلقة قبل ذلك.

## 1) الفكرة في سطرين

* **MySQL 8 هي قاعدة الإنتاج الأساسية**، وتبقى SQLite لسرعة الاختبارات اليومية وCI (مجموعة الاختبارات الكاملة تعمل عليها كما هي).
* أُضيفت أدوات للانتقال بأمان: `db:parity` (تكافؤ المخطط، قراءة فقط) و`db:transfer-sqlite-to-mysql` (نقل بيانات بحواجز صارمة وتحقق كامل) وإعداد اختبار MySQL (`phpunit.mysql.xml.dist`).

## 2) النسخ المدعومة

| المحرك | الحالة |
|---|---|
| **MySQL 8.0.16 فأعلى** (الاستهداف: 8.0 LTS و8.4 LTS) | المدعوم والمعتمَد. الحد الأدنى 8.0.16 لأن قيود `CHECK` لا تُفرض قبلها |
| MariaDB 10.6 فأعلى | «أفضل جهد» فقط، **لم يُتحقَّق منه** في E31 (يُظهر `production:check` تحذيرًا) |
| MySQL 5.7 وما دونه | غير مدعوم (يفشل `production:check` بالإنتاج) |

الإعدادات التي يتوقعها التطبيق (يفحصها `php artisan production:check` و`php artisan db:parity --runtime`):

* الترميز `utf8mb4` والتجميع `utf8mb4_unicode_ci`، المحرك **InnoDB** لكل الجداول.
* الوضع الصارم `STRICT_TRANS_TABLES` (يفرضه `config/database.php`: `strict => true`) كي لا تُقتطع البيانات بصمت.
* منطقة جلسة القاعدة UTC: `DB_TIMEZONE=+00:00` (الافتراضي).
* `max_allowed_packet` لا يقل عن 4MB (الافتراضي في MySQL 8 هو 64MB).

### قرار التجميع (collation)

نبقي `utf8mb4_unicode_ci` (افتراضي لارافل) ولا ننتقل إلى `utf8mb4_0900_ai_ci`:

* مدعوم في MySQL وMariaDB معًا (0900 غير موجود في MariaDB) فلا نقفل أنفسنا على محرك واحد.
* لا حاجة لتجميع عربي خاص: الترتيب الأبجدي العربي يكفيه UCA العام.
* **السلوك الذي يجب أن تعرفه:** المقارنة غير حساسة لحالة الأحرف **ولا للتشكيل/اللكنات**، أي `A@x.com` و`a@x.com` متساويان في الفهرس الفريد، و`أحجية` و`احجية` تتطابقان في `=`/`LIKE`. هذا هو الفرق الجوهري عن SQLite (حساسة للحالة)، وهو سبب فحص «التصادم بتجاهل الحالة» قبل النقل (القسم 8).

## 3) الإعداد المحلي (Windows / PowerShell)

### 3.1 خادم MySQL

اختر واحدًا (كلاهما بترميز utf8mb4):

**أ) Docker Desktop (الأسرع):**

```powershell
docker run --name ahjiyat-mysql -e MYSQL_ROOT_PASSWORD=<كلمة-مرور-محلية> -p 3306:3306 -d mysql:8.0 --character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_ci
docker exec -it ahjiyat-mysql mysql -uroot -p
```

**ب) MySQL Installer / XAMPP:** تأكد أن النسخة 8.0.16+ (`mysql --version`). في XAMPP (يأتي غالبًا بـMariaDB) النسخة **غير مُتحقَّق منها**؛ الأفضل MySQL 8.

> تأكد أن امتداد `pdo_mysql` مفعّل في `php.ini` (`extension=pdo_mysql`). الفحص: `php -m | Select-String pdo_mysql`.

### 3.2 القواعد والمستخدم (نفّذها داخل عميل mysql)

```sql
CREATE DATABASE ahjiyat_platform CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE ahjiyat_platform_test_mysql CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'ahjiyat_app'@'%' IDENTIFIED BY '<كلمة-مرور-قوية>';
GRANT ALL PRIVILEGES ON ahjiyat_platform.* TO 'ahjiyat_app'@'%';
GRANT ALL PRIVILEGES ON `ahjiyat\_platform\_test%`.* TO 'ahjiyat_app'@'%';
FLUSH PRIVILEGES;
```

* القاعدة الثانية **للاختبارات فقط** و`RefreshDatabase` يحذف جداولها كل مرة. اسمها يجب أن ينتهي بـ`_test` أو `_testing` أو `_test_mysql` وإلا يرفض `TestDatabaseGuard` التشغيل.
* الصلاحية بنمط `ahjiyat\_platform\_test%` تسمح للاختبار بإنشاء قاعدة نقل مؤقتة `ahjiyat_platform_test_xfer_test` وحذفها (اختبار النقل الحقيقي). لا تُعطِ المستخدم صلاحيات أوسع.
* في الإنتاج الحقيقي: مستخدم التطبيق بصلاحيات `SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES` على قاعدة واحدة، وليس root.

### 3.3 `.env` (للعمل بـMySQL)

```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=ahjiyat_platform
DB_USERNAME=ahjiyat_app
DB_PASSWORD=<من-ملف-.env-المحلي-فقط>
DB_TIMEZONE=+00:00
```

لا تضع كلمة المرور في أي ملف داخل المستودع. أبقِ `QUEUE_CONNECTION`/`SESSION_DRIVER`/`CACHE_STORE` على `database` مبدئيًا (جداولها ضمن الترحيلات) وانظر [القسم 14](#14-الطوابير-والجلسات-والكاش-على-mysql).

## 4) بناء المخطط من الصفر على MySQL

```powershell
# قاعدة جديدة فارغة فقط. لا تُشغّل هذا على قاعدة فيها بيانات.
$env:DB_CONNECTION='mysql'; $env:DB_HOST='127.0.0.1'; $env:DB_PORT='3306'
$env:DB_DATABASE='ahjiyat_platform'; $env:DB_USERNAME='ahjiyat_app'; $env:DB_PASSWORD='<كلمة-المرور>'
php artisan migrate --force
php artisan db:parity --runtime
```

* `db:parity` **قراءة فقط**: يترجم كل الترحيلات إلى جمل MySQL ويحلّلها (أسماء أطول من 64، فهارس المفاتيح الأجنبية، أنواع المفاتيح، أعمدة مالية عائمة، فهارس على TEXT...) ثم يقارن القاعدة الحيّة بالنموذج. يخرج بـ**1 عند فشل جوهري فقط** (جدول/عمود/قيد فريد/مفتاح أجنبي ناقص، اختلاف فئة نوع) وبـ0 مع تحذيرات/معلومات. `--static` يعمل بلا أي قاعدة، `--against=sqlite` يقارن قاعدتين حيّتين، `--details` يعرض الفروق المعلوماتية، `--json` للأتمتة.
* ما أُصلح في E31 لأن MySQL يرفضه ولا ترفضه SQLite (لا تعديل على ترحيل تاريخي):
  * **اسم فهرس أطول من 64 حرفًا** (خطأ 1059): `App\Database\MysqlSafeBlueprint` يقصّر الأسماء المولَّدة فقط على mysql/mariadb، بنمط ثابت يمكن إعادة اشتقاقه (`substr(name,0,55)_sha1(8)`). SQLite لا تتأثر.
  * **حذف فهرس يخدم مفتاحًا أجنبيًا** (خطأ 1553) في ترحيل المحافظ: أُضيف ترحيل حامٍ قبله (`2026_09_23_000002_e31_guard_wallets_user_fk_index`) وآخر يسقط الحامي لاحقًا حين يصير زائدًا (`2026_10_23_000001_e31_drop_wallets_user_guard_index`). كلاهما بلا أثر على SQLite.
  * **عمود `rank` كلمة محجوزة** في MySQL 8: استعلام ترتيب الفرق يؤهّلها باسم الجدول.
* **PENDING USER-LOCAL MYSQL VALIDATION:** نجاح `php artisan migrate` فعليًا على MySQL وخروج `db:parity` بـ0 هناك.

## 5) النسخ الاحتياطي لـSQLite (قبل أي نقل)

أوقف التطبيق والطابور أولًا (لا كتابات أثناء النسخ):

```powershell
php artisan down
# إن كان الملف بوضع WAL: ادمج الكتابات المعلقة في الملف الرئيسي
sqlite3 database\database.sqlite "PRAGMA wal_checkpoint(TRUNCATE);"   # أو تجاوزه إن لم يوجد ملف -wal
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
Copy-Item database\database.sqlite "database\backup-$stamp.sqlite"
(Get-FileHash database\database.sqlite -Algorithm SHA256).Hash
(Get-FileHash "database\backup-$stamp.sqlite" -Algorithm SHA256).Hash   # يجب أن تتطابق البصمتان
```

* **لا تستمر** إن اختلفت البصمتان. احتفظ بالنسخة خارج مجلد المشروع أيضًا (قرص آخر/سحابة خاصة).
* أمر النقل نفسه يرفض العمل بلا `--backup` مطابقة بايتًا لملف المصدر (يتحقق من SHA-256)، ويرفض إن وجد ملف `-wal` غير فارغ بجانب المصدر.

## 6) مصفوفة اختبارات MySQL المحلية

الأمر الواحد (مسارات صريحة لا اختبار كامل، يُنشئ قاعدة الاختبار إن لزم، ويختم بـ`db:parity`):

```powershell
powershell -ExecutionPolicy Bypass -File tools\e31\run-mysql-matrix.ps1 -Username ahjiyat_app -Password '<كلمة-المرور>'
```

أو مجموعة واحدة يدويًا (لاحظ: `vendor/bin/pest` وليس `php artisan test` لأن الأخير يضيف إعداده الخاص ويتعارض):

```powershell
$env:DB_USERNAME='ahjiyat_app'; $env:DB_PASSWORD='<كلمة-المرور>'
php vendor/bin/pest --configuration=phpunit.mysql.xml.dist tests/Feature/DatabaseE31
```

المجموعات: Auth, Wallet/Economy, Store, Competitive, Chat, Notifications, Search E27, Creator E28, Engagement E29, Sponsorship E30, Creator Series E30, Database E31.

* اختبارات `tests/Feature/DatabaseE31/E31MysqlRuntimeTest.php` **تتخطى بوضوح على SQLite** ولا تُحسب دليلًا إلا إن شُغِّلت على MySQL. تغطي: شروط الخادم، `db:parity` الحيّ، InnoDB/utf8mb4، عربي/إيموجي بايتًا ببايت، رفض النص الطويل، منع نزول UNSIGNED تحت الصفر، المفاتيح الأجنبية (1452/1451)، حساسية التجميع في الفرادة، JSON، مهلة القفل 1205 بين جلستين حقيقيتين، سباق مفتاح فريد (1205 ثم 1062)، بحث E27، **بناء مخطط من الصفر ثم نقل بيانات حقيقي والتحقق** و**الأمر end-to-end**.
* خمسة اختبارات قديمة تعتمد على `EXPLAIN QUERY PLAN` الخاص بـSQLite صارت **تتخطى بوضوح** على MySQL (E27 P5/P6، E28 X9، E30 AM15 ×2) كي لا تفشل زورًا؛ خطط MySQL تُفحص بـ[القسم 12](#12-تدقيق-explain-والفهارس).

## 7) سياسة التزامن والأقفال (تدقيق E31)

### 7.1 ما فُحص
كل مسار مالي/منافسة يستعمل `DB::transaction` مع `lockForUpdate` على صف أب ثابت (المحفظة، المنتج، الحدث، الجلسة)، ثم قيد فريد كخط دفاع أخير. على SQLite (كاتب واحد) لا يظهر الإيقاف المتبادل؛ على MySQL (REPEATABLE READ + أقفال الفجوات) قد يظهر عند نمط «افحص ثم أدرج» تحت حمل متزامن.

### 7.2 الإعادة عند الإيقاف المتبادل (محدودة عمدًا)
* الثابت `App\Services\Database\TransactionPolicy::DEADLOCK_ATTEMPTS = 3` (3 محاولات كحد أقصى).
* تُطبَّق على **أربع نقاط دخول فقط** (مجلّدة باختبار يفشل إن زادت): `PuzzleAttemptService`, `GameSessionService::start`, `CompetitiveRunService::start`, `CreatorApplicationService::apply`.
* لارافل تعيد المعاملة **الخارجية فقط** وعند أخطاء التزامن فقط (1213/40001)، ولا تعيد أخطاء الأعمال ولا المعاملات المتداخلة. لا إعادة بالجملة.
* **المتجر:** `StorePurchaseService::purchase` يعالج سباق النقر المزدوج بالمفتاح نفسه: إن اصطدم الثاني بقيد `request_key` الفريد بعد التزام الأول يُرجَع الشراء القائم (idempotent) بدل خطأ 500، بعد التأكد أنه الطلب نفسه. أي قيد فريد آخر يُعاد رميه.
* **تعليمة:** إن ظهر 1213 في السجلات خارج هذه النقاط فلا تُضف إعادة تلقائية؛ أرسل السجل (دون بيانات) لتحليله.

### 7.3 ما لم يتغير عمدًا
* مستوى العزل الافتراضي (REPEATABLE READ) والأقفال الحالية.
* `after_commit` للطوابير ما زال `false`: طابور قاعدة البيانات يشارك اتصال التطبيق نفسه فتُتراجع المهمة مع المعاملة. **عند الانتقال إلى طابور Redis** يجب ضبط `after_commit => true` (أو إرسال المهام بعد الالتزام) وإلا قد تعمل مهمة على بيانات لم تلتزم بعد.
* الأحداث تستعمل `DB::afterCommit` أصلًا حيث يهم.

### 7.4 كل تنفيذ تزامني فعلي = **PENDING USER-LOCAL MYSQL VALIDATION**
اختبارات M-9 وM-10 تثبت سلوك الأقفال بجلستين حقيقيتين. حمل متزامن حقيقي (عدة عمليات) لم يُنفَّذ هنا.

## 8) نقل البيانات من SQLite إلى MySQL

### 8.1 ما يفعله الأمر وما لا يفعله
* **أداة محلية/تجريبية لمرة واحدة، ترفض `APP_ENV=production` دائمًا** بلا أي تجاوز. انقل على جهازك/staging ثم انشر قاعدة MySQL الجاهزة.
* المصدر SQLite (قراءة فقط على مستوى المحرك: `PRAGMA query_only` + بصمة SHA-256 قبل وبعد)، الهدف mysql/mariadb **بمخطط مبني بـ`migrate` وبيانات فارغة**. لا دمج.
* لا ينقل: `migrations` (يبنيها `migrate`)، `sessions` (يعاد تسجيل الدخول)، `cache`/`cache_locks`، `jobs`/`job_batches`/`failed_jobs` (لا تُنفَّذ مهام قديمة مرتين)، `password_reset_tokens`.
* **الجداول المرجعية** (`currencies`, `supporter_tiers`) تزرعها الترحيلات نفسها فلا تكون «فارغة» بعد `migrate`: يقبل الأمر ذلك فقط إن كان كل صف في الهدف موجودًا في المصدر بالمفتاح الطبيعي (`internal_key`/`slug`) **والمعرّف نفسه**، ثم يستبدلها بصفوف المصدر. أي تعارض معرّف يوقف النقل.
* ينسخ بالمعرّفات الأصلية (حفظ العلاقات)، بدفعات 500–2000 صف داخل معاملات، بترتيب اعتماد الجداول (الآباء أولًا)، مع تعطيل فحص المفاتيح الأجنبية على الهدف **داخل try/finally** والتأكد من إعادة تفعيله. المحتوى المشفَّر ينتقل نصًّا مشفَّرًا كما هو (لا فك ولا إعادة تشفير): **يجب بقاء `APP_KEY` نفسه** وإلا لن يُفك.
* يضبط `AUTO_INCREMENT` بعد النسخ ويتحقق منه.

### 8.2 الفحوص قبل النقل (يوقف عند أي مشكلة، يطبع أعدادًا فقط لا قيمًا)
اختلاف `migrations`/الأعمدة، قيم سالبة في أعمدة UNSIGNED، نص أطول من `varchar`، JSON غير صالح، تواريخ `0000-00-00`، خارج نطاق `TIMESTAMP` (1970–2038)، **تصادم حالة الأحرف على فهرس فريد** (`A@x.com` مقابل `a@x.com`)، تعارض الجداول المرجعية، وحقائق الخادم (ترميز/صرامة/محرك/نسخة).

### 8.3 الأوامر
```powershell
# 1) مخطط فارغ على الهدف (القسم 4) ثم:
$src = 'database\database.sqlite'          # مرِّره دائمًا صراحة (DB_DATABASE صار اسم قاعدة MySQL في هذه الجلسة)

# 2) تجربة بلا أي كتابة: تطبع الخطة والمشكلات
php artisan db:transfer-sqlite-to-mysql --source=$src --dry-run

# 3) النقل الفعلي (يتطلب النسخة الاحتياطية المطابقة من القسم 5)
php artisan db:transfer-sqlite-to-mysql --source=$src --force --backup="database\backup-$stamp.sqlite" --report="$env:TEMP\e31-transfer-report.json"
```

* ينتهي بتقرير: المصدر/الهدف، عدد الجداول والصفوف المنقولة، الجداول المؤقتة المستثناة، فروق الصفوف، المخالفات (أيتام المفاتيح الأجنبية)، تطابق **بصمات المحتوى** لكل جدول، سلامة AUTO_INCREMENT، **تطابق مجاميع المال بالأعداد الصحيحة**، ثم `Result: PASS|FAIL`. الخروج 0 فقط عند PASS **ومصدر لم يتغير**.
* `--skip-fingerprint` أسرع وأضعف تحققًا (لا تستعمله للنقل النهائي). `--batch-size` بين 500 و2000. `--exclude=table1,table2` لجداول إضافية. `--json` و`--report`.
* **التعافي بعد فشل جزئي:** قاعدة الهدف جديدة بالتصميم، فالأسلم إسقاطها وإعادة إنشائها وتشغيل `migrate`. وإلا: `--reset-target --confirm-database=<اسم قاعدة الهدف نفسه> --force --backup=...` (يفرغ جداول التطبيق فقط ولا يلمس `migrations`).
* رمز الخروج: 0 نجاح/اكتمال dry-run، 1 فشل (حاجز/مشكلة/عدم تطابق)، 2 يلزم `--force`/`--backup`.
* لا يطبع أي سر أو قيمة صف: رسائل الأخطاء تحمل فئة الخطأ ورمز MySQL واسم الجدول/العمود فقط.

> **PENDING USER-LOCAL MYSQL VALIDATION:** تشغيل `--dry-run` ثم النقل الفعلي على نسخة من بياناتك الحقيقية، وإرسال التقرير. الاختبارات الآلية أثبتت المنطق على SQLite→SQLite، وإثبات MySQL الحقيقي في M-12/M-13.

## 9) الانتقال (Cutover) من SQLite إلى MySQL: 12 خطوة

1. **جاهزية:** المصفوفة المحلية (القسم 6) PASS، و`db:parity` خرج 0 على MySQL، ونجح `--dry-run` على نسخة بياناتك.
2. **أعلِم المستخدمين** بنافذة الصيانة (الجلسات ستُنهى: إعادة تسجيل دخول).
3. `php artisan down --secret=<رمز-صيانة>` لإيقاف الواجهة، وأوقف عمال الطابور و`schedule:run` وReverb.
4. **انتظر** تفريغ الطابور الحي (`php artisan queue:work --stop-when-empty` أو تأكد أن `jobs` فارغ).
5. **نسخ SQLite احتياطيًا** + بصمة SHA-256 (القسم 5) وحفظها خارج المشروع.
6. على قاعدة MySQL **جديدة**: `php artisan migrate --force` ثم `php artisan db:parity --runtime` (يجب 0).
7. `php artisan db:transfer-sqlite-to-mysql --source=<...> --dry-run` ثم النقل الفعلي بـ`--force --backup`.
8. **راجع التقرير:** `Result: PASS`، لا فروق صفوف، لا أيتام، لا فروق بصمات، لا فروق مالية، وSQLite لم تتغير.
9. **حقق يدويًا:** مجاميع الأرصدة (`wallets`) وعدد المستخدمين وآخر عملية في الدفتر وآخر طلب استبدال مقابل ما تعرفه.
10. غيّر `.env`: `DB_CONNECTION=mysql` + بيانات الاتصال، ثم `php artisan optimize` (يجمّد الإعدادات) و`php artisan queue:restart` و`reverb:restart`.
11. `php artisan production:check` (يجب ألا يفشل بشأن القاعدة) ثم `php artisan up`، وشغّل `release:smoke` وجرّب تسجيل الدخول واللعب وعرض الرصيد وطلب استبدال تجريبي.
12. **راقب 24 ساعة:** السجلات (1213/1205/1062 غير المتوقعة)، `/ready`، طوابير فاشلة. احتفظ بـSQLite والنسخة **للقراءة فقط** 30 يومًا.

### التراجع
أوقف الواجهة، أعد `.env` إلى `DB_CONNECTION=sqlite`، أعد ملف SQLite من النسخة المصدّقة (تحقق من البصمة)، `php artisan optimize`، شغّل. **ما كُتب في MySQL بعد الانتقال لا يعود تلقائيًا إلى SQLite**: قرار التراجع المتأخر يعني خسارة تلك الكتابات، فخذ القرار خلال دقائق الانتقال الأولى أو تقبّل الخسارة.

## 10) المفاتيح الأجنبية وحذف السجلات (U2/U3)

تدقيق 214 مفتاحًا أجنبيًا كشف أن جداول مالية/مكافآت مرتبطة بـ`users` بـ`ON DELETE CASCADE`، فحذف مستخدم (من لوحة الإدارة، مدير أعلى فقط) كان سيمحو دفتر عملاته وطلبات استبداله ومكافآته. الترحيلات التاريخية **لم تُعدَّل**؛ الحماية على مستوى التطبيق:

* `App\Services\Database\FinancialHistoryGuard`: يعتبر أي سجل في `currency_transactions, redemption_requests, store_purchases, inventory_transactions, xp_transactions, competitive_reward_grants, sponsorship_reward_bonuses` أو رصيدًا في المحفظة «تاريخًا ماليًا».
* `UserPolicy::delete` ترجع `false` لمن له تاريخ مالي (الواجهة تخفي الزر؛ لا 500). **البديل المقصود: تجميد الحساب** الذي يحفظ التاريخ للتدقيق.
* اختبار يثبّت القائمة مقابل DDL الفعلي: إضافة جدول مالي جديد بـCASCADE على `users` دون إدراجه في الحارس **تُفشل الاختبار**.
* العلاقات التي يجب أن تبقى `RESTRICT` (برامج الرعاية، الداعمون، العملات، عناصر المتجر، قواعد المكافآت) مثبتة باختبار كذلك. مرفوض: تغيير أي قاعدة حذف بلا اختبار.

## 11) أنواع المال

لا يوجد عمود `float`/`double`/`decimal` في المخطط كله: كل المبالغ **أعداد صحيحة بالوحدة الصغرى** (`bigint unsigned` للأرصدة والمبالغ، `bigint` الموقَّع لدفتر العملات لأن الإنفاق سالب). اختبار ساكن يفشل إن ظهر عمود عشري عائم باسم مالي. فرق مهم على MySQL: نقص `UNSIGNED` تحت الصفر يرمي خطأ (على SQLite يمرّ بصمت)؛ الخدمات تفحص الرصيد قبل الخصم أصلًا.

## 12) تدقيق EXPLAIN والفهارس

### 12.1 ما أُنجز بلا خادم
* لا فهرس مفقود يخدم مفتاحًا أجنبيًا (يفحصه المحلّل لكل المفاتيح).
* **أربعة فهارس زائدة** (مغطّاة ببادئة فهرس آخر) رُصدت ولم تُحذف عمدًا: `puzzle_attempts` ×2، `device_sightings`، `notifications`. حذفها يوفّر كتابة صغيرة لكنه يغيّر خطط التنفيذ بلا قياس؛ تُراجَع بعد EXPLAIN على بيانات حقيقية.
* لم يُضف أي فهرس جديد في E31.

### 12.2 الإجراء المحلي (**PENDING USER-LOCAL MYSQL VALIDATION**)
بعد النقل (أو `php artisan performance:seed` على قاعدة اختبار):

```powershell
$env:MYSQL_PWD = '<كلمة-المرور>'
Get-Content tools\e31\explain-audit.sql -Raw -Encoding UTF8 | mysql --default-character-set=utf8mb4 -u ahjiyat_app ahjiyat_platform
Remove-Item Env:\MYSQL_PWD
```

الملف يحوي 17 استعلام `EXPLAIN` للمسارات الساخنة (الدردشة، بحث الأحجيات واللاعبين، الرائج، قفل المحفظة، الدفتر، الإشعارات، صدارة المنافسة، أعمال المجتمع، السلاسل، التقييمات، المتابعات، الداعمون، الاستبدال، الجلسات) وبجانب كل منها الفهرس المتوقع. أرسل لي أي سطر ظهر فيه `type=ALL` أو `Using filesort/temporary` بعدد صفوف كبير. مراقبة معلنة: استعلام سجل العملات (07) قد يُظهر `filesort` لأن الفهرس `(user_id, type)`؛ لا فهرس قبل القياس.

## 13) فروق SQLite ↔ MySQL التي تهمك

| الموضوع | SQLite | MySQL |
|---|---|---|
| حالة الأحرف في `=`/`LIKE`/الفرادة | حساسة للحالة (`LIKE` لاتيني غير حساس) | **غير حساسة للحالة وللتشكيل** (`utf8mb4_unicode_ci`) |
| `UNSIGNED` | لا يُفرض | يُفرض (نقص تحت الصفر = خطأ) |
| أنواع التجميعات (`SUM`, `COUNT`) | أعداد | قد تعود **نصوصًا/DECIMAL**: اجعل التحويل `(int)` صريحًا في أي كود جديد |
| `TIMESTAMP` | نص | نطاق **1970–2038**؛ تاريخ خارجه يُرفض (فحص قبل النقل) |
| `0000-00-00` | يُقبل | مرفوض في الوضع الصارم |
| طول `varchar` | لا يُفرض | يُفرض (رفض لا اقتطاع) |
| القيد الفريد وNULL | NULL لا يتساوى بـNULL | كذلك (مثبت: `sponsorship_contributions(provider, external_reference)` يقبل صفوفًا بلا مرجع) |
| `CHECK` | يعمل | يُفرض من 8.0.16 (قيد `community_ratings_range_check` يُضاف على MySQL فقط؛ التحقق الخدمي هو الحارس على SQLite) |
| DDL داخل معاملة | قابل للتراجع | **يلتزم ضمنيًا** (`ALTER`/`CREATE`): لا ترحيل بمنطق بيانات داخل معاملة تتوقع تراجعه |
| كلمات محجوزة | — | `rank`, `key`, `group`... أهّلها باسم الجدول في أي SQL خام |
| قفل الصفوف | قفل قاعدة عند الكتابة | أقفال صفوف وفجوات: ممكن 1213/1205 تحت الحمل (القسم 7) |

## 14) الطوابير والجلسات والكاش على MySQL

* جداول `jobs`, `sessions`, `cache` تعمل على MySQL كما هي، لكنها **أكثر الجداول كتابة**: ستنافس الجداول المالية على الاتصال والأقفال. مقبول لمشروع صغير/متوسط.
* **توصية قبل النمو:** Redis للجلسات والكاش والطوابير (`SESSION_DRIVER=redis`, `CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`) مع `after_commit => true` للطابور (القسم 7.3). تغيير بنية تحتية، لا يُنفَّذ في E31.
* `DB_QUEUE_RETRY_AFTER` (افتراضي 90) يجب أن يظل أكبر من أقصى timeout لأي مهمة.

## 15) `production:check` و`/ready`

* `DB_CONNECTION=sqlite` **يفشل** `production:check` بالإنتاج برسالة صريحة. تجاوز واعٍ ومؤقت: `OPS_ALLOW_SQLITE_PRODUCTION=true` (يتحول إلى تحذير) — لا يُنصح به.
* على MySQL يفحص: النسخة (≥ 8.0.16)، الوضع الصارم، الترميز، المحرك، منطقة الجلسة، `max_allowed_packet`. لا يطبع مضيفًا ولا اسم قاعدة ولا مسارًا ولا كلمة مرور.
* `/ready` (فحص جاهزية) يعمل بـ`select 1` + الكاش على أي سائق ولا يكشف السائق ولا المضيف ولا اسم القاعدة ولا المخطط (مثبت باختبار على SQLite، **MySQL: PENDING USER-LOCAL MYSQL VALIDATION**).

## 16) CI

مهمة `mysql-parity` في `.github/workflows/ci.yml`: خدمة MySQL 8 (بيانات اعتماد اختبار فقط، لا أسرار)، تشغّل `tests/Feature/DatabaseE31` وأهم مجموعات السلامة على MySQL. مهمة SQLite الحالية باقية. لا نشر ولا migrate على بيئة حقيقية.

## 17) متغيرات البيئة الجديدة (كلها اختيارية)

| المتغير | الافتراضي | الوظيفة |
|---|---|---|
| `DB_ENGINE` | `InnoDB` | محرك الجداول على mysql/mariadb |
| `DB_TIMEZONE` | `+00:00` | منطقة جلسة القاعدة (يجب أن تطابق UTC) |
| `OPS_ALLOW_SQLITE_PRODUCTION` | `false` | يسمح بـSQLite بالإنتاج كتحذير بدل فشل (غير مستحسن) |
| `MIGRATION_TARGET_CONNECTION` | `mysql` | اتصال الهدف لأمر النقل |
| `MIGRATION_SQLITE_DATABASE` | — | مسار ملف SQLite المصدر |
| `MIGRATION_MYSQL_HOST/PORT/DATABASE/USERNAME/PASSWORD` | — | تجاوزات الهدف إن أردت إبقاء `DB_*` على SQLite أثناء النقل (كلمة المرور من البيئة فقط) |
| `MIGRATION_BATCH_SIZE` | `1000` | صفوف لكل دفعة (500–2000) |
| `MIGRATION_MAX_BATCH_BYTES` | `1000000` | سقف حجم الدفعة التقريبي |

## 18) أعطال شائعة على Windows

| العَرَض | السبب والحل |
|---|---|
| `could not find driver` | فعّل `extension=pdo_mysql` في `php.ini` ثم أعد تشغيل الطرفية |
| `SQLSTATE[HY000] [2002] Connection refused` | الخادم لا يعمل أو المنفذ/المضيف خطأ (`127.0.0.1` أفضل من `localhost` على ويندوز) |
| `[HY000] [2054] The server requested authentication method unknown` | مستخدم قديم/عميل قديم: أنشئ المستخدم بـ`mysql_native_password` أو حدّث PHP/`mysqlnd` |
| `Specified key was too long` / `1059 Identifier name is too long` | ضبطه `MysqlSafeBlueprint`؛ إن ظهر فأرسل اسم الفهرس |
| `1553 Cannot drop index needed in a foreign key constraint` | ترحيل المحافظ؛ يعالجه ترحيل E31 الحامي. تأكد من تشغيل كل الترحيلات بالترتيب |
| `1062 Duplicate entry` أثناء النقل | تصادم حالة أحرف؛ يكشفه الفحص المسبق (وحّد القيم في المصدر) |
| `1205 Lock wait timeout exceeded` | معاملة طويلة أو متزامنة: أرسل السجل؛ لا ترفع المهلة قبل التحليل |
| `1213 Deadlock found` | تعيدها نقاط الدخول الأربع تلقائيًا؛ إن ظهرت للمستخدم أرسل السجل |
| اختبارات MySQL تفشل بـ«Connection refused» | لم يعمل الخادم أو بيانات `$env:DB_*` خطأ (ليست مشكلة كود) |
| `Option --configuration cannot be used more than once` | استعملت `php artisan test`؛ استعمل `php vendor/bin/pest --configuration=...` |

## 19) خلاصة ما يلزم منك لإغلاق E31

1. تشغيل `tools\e31\run-mysql-matrix.ps1` وإرسال المخرجات (PASS لكل مجموعة + `db:parity`).
2. تشغيل `--dry-run` ثم النقل على **نسخة** من بياناتك الحقيقية وإرسال التقرير.
3. تشغيل `explain-audit.sql` على القاعدة المنقولة وإرسال أي سطر مريب.

بدون هذه الثلاثة تبقى بنود MySQL التشغيلية **PENDING USER-LOCAL MYSQL VALIDATION**.
`````

### `phpunit.mysql.xml.dist`

`sha256: 36ef9cbefda8713054ead1ec5614d53a88af829204e51cba055a2cd9b3eeda3c`

`````xml
<?xml version="1.0" encoding="UTF-8"?>
<!--
  E31 - إعداد اختبار على MySQL 8 الحقيقية (مصفوفة التكافؤ). بقية الاختبارات تبقى على SQLite عبر phpunit.xml.dist (سريعة).

  الاستعمال (PowerShell، من جذر المشروع):
      php vendor/bin/pest -c phpunit.mysql.xml.dist tests/Feature/DatabaseE31   (لا تستعمل php artisan test هنا: يضيف إعداده الخاص ويتعارض)
  أو المصفوفة كاملة:  tools/e31/run-mysql-matrix.ps1 (PowerShell)

  القيم أدناه افتراضات محلية فقط (غير مفروضة): أي متغير بيئة تضبطه في الجلسة قبل التشغيل (مثل $env:DB_USERNAME, $env:DB_PASSWORD) يتقدّم عليها،
  وهي بدورها تتقدّم على ملف .env. لا أسرار هنا. اسم القاعدة يجب أن ينتهي بـ_test أو _testing أو _test_mysql وإلا يرفض TestDatabaseGuard التشغيل
  (والاختبار يُنشئ ويحذف قاعدة نقل مؤقتة باسم <القاعدة>_xfer_test فقط، فيحتاج المستخدم صلاحية CREATE/DROP على ذلك النمط).
  ممنوع منعًا باتًا توجيه هذا الإعداد إلى قاعدة العمل (ahjiyat_platform): RefreshDatabase يحذف كل جداولها.
-->
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true"
>
    <testsuites>
        <testsuite name="Unit">
            <directory>tests/Unit</directory>
        </testsuite>
        <testsuite name="Feature">
            <directory>tests/Feature</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>app</directory>
        </include>
    </source>
    <php>
        <env name="APP_ENV" value="testing"/>
        <env name="BCRYPT_ROUNDS" value="4"/>
        <env name="CACHE_STORE" value="array"/>
        <env name="DB_CONNECTION" value="mysql" force="true"/>
        <env name="DB_HOST" value="127.0.0.1"/>
        <env name="DB_PORT" value="3306"/>
        <env name="DB_DATABASE" value="ahjiyat_platform_test_mysql"/>
        <env name="DB_USERNAME" value="root"/>
        <env name="DB_PASSWORD" value=""/>
        <env name="DB_TIMEZONE" value="+00:00"/>
        <env name="MAIL_MAILER" value="array"/>
        <env name="QUEUE_CONNECTION" value="sync"/>
        <env name="BROADCAST_CONNECTION" value="null"/>
        <env name="SESSION_DRIVER" value="array"/>
    </php>
</phpunit>
`````

### `tests/Feature/DatabaseE31/E31DocsAndToolingTest.php`

`sha256: 2ee3428f5b6fc4d6c1538696db8282e749c995653e621ab37bf1c0764decf50b`

`````php
<?php

require_once __DIR__.'/_support.php';

/*
| E31 - الوثائق والأدوات المرافقة: الدليل موجود بأقسامه الجوهرية ولا يدّعي نجاحًا لم يتحقق، .env.example يعرض MySQL بلا أسرار،
| إعداد اختبار MySQL يحمي قاعدة العمل، سكربت المصفوفة يرفض أسماء غير الاختبار، وCI فيه مهمة MySQL بلا أسرار.
*/

function e31_read(string $relative): string
{
    return (string) file_get_contents(base_path($relative));
}

test('Y-1: the MySQL production guide covers the required procedures and keeps unvalidated MySQL items marked as pending', function () {
    $doc = e31_read('docs/mysql-production-e31.md');

    foreach (['8.0.16', 'utf8mb4_unicode_ci', 'db:parity', 'db:transfer-sqlite-to-mysql', 'Get-FileHash', 'Copy-Item', 'PRAGMA wal_checkpoint', 'TransactionPolicy', 'FinancialHistoryGuard',
        'OPS_ALLOW_SQLITE_PRODUCTION', 'explain-audit.sql', 'run-mysql-matrix.ps1', 'APP_KEY', 'التراجع', 'Cutover'] as $needle) {
        expect($doc)->toContain($needle);
    }

    // 12 خطوة انتقال
    preg_match('/## 9\).*?### التراجع/su', $doc, $cutover);
    expect(preg_match_all('/^\d+\.\s/m', $cutover[0] ?? ''))->toBe(12);

    expect($doc)->toContain('PENDING USER-LOCAL MYSQL VALIDATION');
});

test('Y-2: documents embed no real secret - only placeholders for passwords', function () {
    $doc = e31_read('docs/mysql-production-e31.md');

    preg_match_all('/(?:PASSWORD|MYSQL_PWD|IDENTIFIED BY|-Password)\s*[=\']\s*[\'"]?([^\s\'";`)]+)/i', $doc, $matches);

    foreach ($matches[1] as $value) {
        expect(preg_match('/^(<[^>]+>|\$[A-Za-z_:]+|)$/u', $value))->toBe(1, "قيمة غير placeholder: {$value}");
    }
});

test('Y-3: .env.example shows MySQL as the primary database with an empty password and no real secret', function () {
    $env = e31_read('.env.example');

    expect($env)->toContain('DB_CONNECTION=mysql')->toContain('DB_DATABASE=ahjiyat_platform')->toContain('DB_PASSWORD=')->toContain('DB_TIMEZONE=+00:00')
        ->toContain('docs/mysql-production-e31.md');
    expect(preg_match('/^DB_PASSWORD=\s*$/m', $env))->toBe(1);
});

test('Y-4: the MySQL phpunit configuration forces the mysql driver, uses a guarded test database name and keeps SQLite as the default suite', function () {
    $mysql = e31_read('phpunit.mysql.xml.dist');
    $default = e31_read('phpunit.xml.dist');

    expect($mysql)->toContain('name="DB_CONNECTION" value="mysql" force="true"')
        ->and($mysql)->toContain('value="ahjiyat_platform_test_mysql"')
        ->and($mysql)->not->toContain('value="ahjiyat_platform"')
        ->and($default)->toContain('name="DB_CONNECTION" value="sqlite"');

    // الاسم الافتراضي في الإعداد يمرّ من حارس قاعدة الاختبار، واسم قاعدة العمل لا يمرّ
    expect(\Tests\Support\TestDatabaseGuard::isAllowed('mysql', 'ahjiyat_platform_test_mysql'))->toBeTrue()
        ->and(\Tests\Support\TestDatabaseGuard::isAllowed('mysql', 'ahjiyat_platform'))->toBeFalse();

    $xml = new DOMDocument;
    expect($xml->loadXML($mysql))->toBeTrue();
});

test('Y-5: the matrix script refuses any database name that is not an explicit test database, never installs anything and never runs an unscoped test command', function () {
    $script = e31_read('tools/e31/run-mysql-matrix.ps1');

    expect($script)->toContain("(^|_)(test|testing)(_(mysql|mariadb))?$")
        ->and($script)->not->toMatch('/^\s*&?\s*(composer|npm|git|npx)\b/mi')   // لا يستدعي أيًّا منها (الإشارة إليها في رسالة نصية مقبولة)
        ->and($script)->not->toMatch('/pest\s*$|pest\s+--configuration=\S+\s*$/m');   // كل استدعاء pest يمرّر مسارات

    foreach (['tests/Feature/Auth', 'tests/Feature/Store', 'tests/Feature/DiscoveryE27', 'tests/Feature/CreatorE28', 'tests/Feature/EngagementE29', 'tests/Feature/SponsorshipE30', 'tests/Feature/CreatorSeriesE30', 'tests/Feature/Chat', 'tests/Feature/Notifications', 'tests/Feature/Competitive', 'tests/Feature/Economy', 'tests/Feature/DatabaseE31'] as $path) {
        expect($script)->toContain($path);
    }

    // الملف يبدأ بـBOM (عربي في PowerShell 5.1)
    expect(substr(file_get_contents(base_path('tools/e31/run-mysql-matrix.ps1')), 0, 3))->toBe("\xEF\xBB\xBF");
});

test('Y-6: CI has a MySQL job with test-only credentials, no repository secrets, and still keeps the SQLite job', function () {
    $ci = e31_read('.github/workflows/ci.yml');
    $live = preg_replace('/^\s*#.*$/m', '', $ci);

    expect($ci)->toContain('mysql-parity:')->toContain('image: mysql:8.0')->toContain('validate:')
        ->and($live)->not->toContain('secrets.')
        ->and($live)->toContain('phpunit.mysql.xml.dist')
        ->and($live)->toContain('tests/Feature/DatabaseE31');
});

test('Y-7: the EXPLAIN audit file is read-only SQL (EXPLAIN statements only) and names the expected index for each query', function () {
    $sql = e31_read('tools/e31/explain-audit.sql');
    $statements = array_values(array_filter(array_map('trim', preg_split('/;\s*$/m', preg_replace('/^\s*--.*$/m', '', $sql)))));

    expect(count($statements))->toBeGreaterThanOrEqual(15);

    foreach ($statements as $statement) {
        expect(preg_match('/^(SET NAMES utf8mb4|EXPLAIN\s+SELECT)\b/i', $statement))->toBe(1, substr($statement, 0, 60));
        expect(preg_match('/\b(INSERT|UPDATE\s+\w+\s+SET|DELETE|DROP|ALTER|TRUNCATE|CREATE)\b/i', preg_replace('/FOR UPDATE/i', '', $statement)))->toBe(0);
    }

    // كل فهرس متوقَّع مذكور في التعليقات موجود فعلًا في المخطط المترجم (لا يشير الملف إلى فهرس غير موجود)
    $compiled = (new \App\Services\Database\MysqlDdlCompiler)->compile();
    $model = (new \App\Services\Database\MysqlDdlLinter)->analyze($compiled['statements'])['tables'];
    $existing = [];

    foreach ($model as $table) {
        foreach (array_keys($table['indexes']) as $index) {
            $existing[$index] = true;
        }
    }

    preg_match_all('/المتوقع:\s*([a-z0-9_]+)/', $sql, $named);

    foreach ($named[1] as $index) {
        expect(isset($existing[$index]))->toBeTrue("الفهرس {$index} المذكور في explain-audit.sql غير موجود في المخطط");
    }
});
`````

### `tests/Feature/DatabaseE31/E31DomainIntegrityTest.php`

`sha256: a4f460d7ba9e6e9b12306eabbe480272228500baffbb73773dd494ec12ca36b1`

`````php
<?php

use App\Models\CurrencyTransaction;
use App\Models\StoreItem;
use App\Models\StoreItemPrice;
use App\Models\StorePurchase;
use App\Models\User;
use App\Policies\UserPolicy;
use App\Services\Database\FinancialHistoryGuard;
use App\Services\Economy\CurrencyRegistry;
use App\Services\Economy\CurrencyWalletService;
use App\Services\Store\StorePurchaseService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/_support.php';

/*
| E31 - سلامة النطاق التي تعتمد عليها MySQL والإعادة بعد الإيقاف المتبادل (deadlock): قيود الفرادة هي خط الدفاع الأخير، والحارس المالي يمنع محو التاريخ.
| تعمل على أي سائق (SQLite افتراضيًا وMySQL في مصفوفة التكافؤ): أخطاء الفرادة تُرفع بالنوع نفسه UniqueConstraintViolationException.
*/

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
});

// ───────────────────────────── الحارس المالي وحذف المستخدم (U2/U3) ─────────────────────────────

test('W-1: a user with no financial history is deletable by a super admin; self-deletion and non-super-admins are refused', function () {
    $super = User::factory()->create();
    $super->assignRole('super-admin');
    $other = User::factory()->create();
    $second = User::factory()->create();
    $second->assignRole('super-admin');
    $plain = User::factory()->create();

    expect(app(UserPolicy::class)->delete($super, $other))->toBeTrue()
        ->and(app(UserPolicy::class)->delete($plain, $other))->toBeFalse()
        ->and(fn () => app(UserPolicy::class)->delete($super, $super))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

test('W-2: any ledger entry, wallet balance, redemption or reward history makes the policy refuse deletion (freeze instead)', function () {
    $super = User::factory()->create();
    $super->assignRole('super-admin');
    $backup = User::factory()->create();
    $backup->assignRole('super-admin');
    $currency = app(CurrencyRegistry::class)->defaultEarnedCurrency();
    $wallets = app(CurrencyWalletService::class);
    $guard = app(FinancialHistoryGuard::class);

    $withBalance = User::factory()->create();
    $wallets->creditAvailable($withBalance, $currency, 50, 'e31_history');

    $withLedgerOnly = User::factory()->create();
    $wallets->creditAvailable($withLedgerOnly, $currency, 10, 'e31_history');
    $wallets->debitAvailable($withLedgerOnly, $currency, 10, 'e31_history'); // الرصيد صفر لكن الدفتر ممتلئ

    $clean = User::factory()->create();

    expect($guard->historyFor($withBalance))->toContain('دفتر العملات')->and($guard->historyFor($withBalance))->toContain('رصيد المحفظة')
        ->and($guard->historyFor($withLedgerOnly))->toBe(['دفتر العملات'])
        ->and($guard->hasHistory($clean))->toBeFalse()
        ->and(app(UserPolicy::class)->delete($super, $withBalance))->toBeFalse()
        ->and(app(UserPolicy::class)->delete($super, $withLedgerOnly))->toBeFalse()
        ->and(app(UserPolicy::class)->delete($super, $clean))->toBeTrue();
});

test('W-3: the guard reads every history table it lists (a table that does not exist would fail loudly, not silently pass)', function () {
    foreach (array_keys(FinancialHistoryGuard::HISTORY_TABLES) as $table) {
        expect(DB::getSchemaBuilder()->hasColumn($table, 'user_id'))->toBeTrue($table);
    }
});

// ───────────────────────────── المحفظة والدفتر ─────────────────────────────

test('W-4: a failed wallet operation leaves no partial change - the balance and the ledger are untouched', function () {
    $user = User::factory()->create();
    $currency = app(CurrencyRegistry::class)->defaultEarnedCurrency();
    $wallets = app(CurrencyWalletService::class);
    $wallets->creditAvailable($user, $currency, 30, 'e31_seed');
    $ledgerBefore = CurrencyTransaction::where('user_id', $user->id)->count();

    expect(fn () => $wallets->debitAvailable($user, $currency, 31, 'e31_overdraw'))->toThrow(RuntimeException::class);

    expect($wallets->balanceFor($user, $currency)->available_balance)->toBe(30)
        ->and(CurrencyTransaction::where('user_id', $user->id)->count())->toBe($ledgerBefore);
});

test('W-5: replaying an idempotency key returns the first transaction and never credits twice; a key reused with another amount is refused', function () {
    $user = User::factory()->create();
    $currency = app(CurrencyRegistry::class)->defaultEarnedCurrency();
    $wallets = app(CurrencyWalletService::class);

    $first = $wallets->creditPending($user, $currency, 40, 'e31_replay', null, 'e31-key-'.$user->id);
    $replay = $wallets->creditPending($user, $currency, 40, 'e31_replay', null, 'e31-key-'.$user->id);

    expect($replay->id)->toBe($first->id)
        ->and($wallets->balanceFor($user, $currency)->pending_balance)->toBe(40)
        ->and(CurrencyTransaction::where('idempotency_key', 'e31-key-'.$user->id)->count())->toBe(1)
        ->and(fn () => $wallets->creditPending($user, $currency, 41, 'e31_replay', null, 'e31-key-'.$user->id))->toThrow(RuntimeException::class);
});

test('W-6: the database itself rejects a duplicate idempotency key and a second wallet row for the same user and currency', function () {
    $user = User::factory()->create();
    $currency = app(CurrencyRegistry::class)->defaultEarnedCurrency();
    $wallets = app(CurrencyWalletService::class);
    $wallets->creditPending($user, $currency, 5, 'e31_unique', null, 'e31-dup-key');

    $tx = (array) DB::table('currency_transactions')->where('idempotency_key', 'e31-dup-key')->first();
    unset($tx['id']);
    expect(fn () => DB::table('currency_transactions')->insert($tx))->toThrow(UniqueConstraintViolationException::class);

    $wallet = (array) DB::table('wallets')->where('user_id', $user->id)->where('currency_id', $currency->id)->first();
    unset($wallet['id']);
    expect(fn () => DB::table('wallets')->insert($wallet))->toThrow(UniqueConstraintViolationException::class);
});

// ───────────────────────────── المتجر: سباق نقر مزدوج ─────────────────────────────

test('W-7: a double-click race on the same request key resolves to the existing purchase instead of a 500 - and other unique violations still surface', function () {
    $user = User::factory()->create();
    $item = StoreItem::factory()->create(['fulfillment_type' => StoreItem::FULFILLMENT_INVENTORY, 'grant_quantity' => 1]);
    $price = StoreItemPrice::factory()->create(['store_item_id' => $item->id, 'amount' => 100]);
    app(CurrencyWalletService::class)->creditAvailable($user, $price->currency, 500, 'e31_store');
    $key = (string) Str::uuid();

    // الطلب «الفائز» يلتزم قبل أن يصل الخاسر إلى القيد الفريد؛ نحاكي ذلك: الخاسر يرمي خطأ الفرادة بعد أن صار الصف موجودًا.
    $racing = new class(app(CurrencyWalletService::class), app(\App\Services\Store\InventoryService::class), app(\App\Services\Store\EntitlementService::class)) extends StorePurchaseService
    {
        public ?StorePurchase $winner = null;

        public bool $conflictWithoutRow = false;

        protected function purchaseLocked(User $user, StoreItem $item, StoreItemPrice $price, string $requestKey): StorePurchase
        {
            if (! $this->conflictWithoutRow) {
                $this->winner = (new StorePurchaseService($this->wallets, $this->inventory, $this->entitlements))->purchase($user, $item, $price, $requestKey);
            }

            throw new UniqueConstraintViolationException('testing', 'insert into store_purchases', [], new PDOException('1062 duplicate'));
        }
    };

    // الفحص الأول لا يرى شيئًا (نحذف الصف الفائز لحظة الفحص عبر نسخة لا تتجاوز purchaseLocked) فنصل إلى الفرع المراد
    $result = $racing->purchase($user, $item, $price, $key);

    // ملاحظة: الفائز أُنشئ داخل purchaseLocked، فاستُعيد هنا عبر الفرع catch
    expect($result->id)->toBe($racing->winner->id)
        ->and(StorePurchase::where('request_key', $key)->count())->toBe(1)
        ->and(app(CurrencyWalletService::class)->balanceFor($user, $price->currency)->available_balance)->toBe(400);

    // خطأ فرادة آخر لا صلة له بـrequest_key (لا صف بالمفتاح) يُعاد رميه ولا يُبتلع
    $other = (string) Str::uuid();
    $racing->conflictWithoutRow = true;
    expect(fn () => $racing->purchase($user, $item, $price, $other))->toThrow(UniqueConstraintViolationException::class);
});

// ───────────────────────────── التفاعلات المجتمعية ─────────────────────────────

test('W-8: duplicate rating, favorite and follow rows are rejected by the database; anonymous (NULL user) ratings may repeat', function () {
    $creator = e28_creator();
    e28_category();
    $work = e28_published($creator, 'text');
    $fan = User::factory()->create();
    $profileId = (int) DB::table('creator_profiles')->where('user_id', $creator->id)->value('id');
    $now = now()->toDateTimeString();

    DB::table('community_puzzle_ratings')->insert(['user_id' => $fan->id, 'creator_puzzle_id' => $work->id, 'rating' => 4, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('community_puzzle_favorites')->insert(['user_id' => $fan->id, 'creator_puzzle_id' => $work->id, 'created_at' => $now]);
    DB::table('creator_follows')->insert(['user_id' => $fan->id, 'creator_profile_id' => $profileId, 'created_at' => $now]);

    expect(fn () => DB::table('community_puzzle_ratings')->insert(['user_id' => $fan->id, 'creator_puzzle_id' => $work->id, 'rating' => 5, 'created_at' => $now, 'updated_at' => $now]))->toThrow(UniqueConstraintViolationException::class)
        ->and(fn () => DB::table('community_puzzle_favorites')->insert(['user_id' => $fan->id, 'creator_puzzle_id' => $work->id, 'created_at' => $now]))->toThrow(UniqueConstraintViolationException::class)
        ->and(fn () => DB::table('creator_follows')->insert(['user_id' => $fan->id, 'creator_profile_id' => $profileId, 'created_at' => $now]))->toThrow(UniqueConstraintViolationException::class);

    // تقييمان مجهولان (user_id NULL بعد حذف مستخدمين) لا يتصادمان: NULL لا يتساوى بـNULL في القيد الفريد على SQLite وMySQL
    DB::table('community_puzzle_ratings')->insert([
        ['user_id' => null, 'creator_puzzle_id' => $work->id, 'rating' => 3, 'created_at' => $now, 'updated_at' => $now],
        ['user_id' => null, 'creator_puzzle_id' => $work->id, 'rating' => 2, 'created_at' => $now, 'updated_at' => $now],
    ]);

    expect(DB::table('community_puzzle_ratings')->where('creator_puzzle_id', $work->id)->whereNull('user_id')->count())->toBe(2);
});

test('W-9: on MySQL the rating range CHECK constraint is enforced by the engine (1..5 only); SQLite relies on the service validation', function () {
    e31_requires_mysql(); // الترحيل يضيف القيد على mysql/mariadb/pgsql فقط؛ SQLite لا يدعم ADD CONSTRAINT

    $creator = e28_creator();
    e28_category();
    $work = e28_published($creator, 'text');
    $fan = User::factory()->create();
    $now = now()->toDateTimeString();

    foreach ([0, 6] as $bad) {
        expect(fn () => DB::table('community_puzzle_ratings')->insert(['user_id' => $fan->id, 'creator_puzzle_id' => $work->id, 'rating' => $bad, 'created_at' => $now, 'updated_at' => $now]))->toThrow(\Illuminate\Database\QueryException::class);
    }
});

// ───────────────────────────── الرعاية ─────────────────────────────

test('W-10: sponsorship contributions - a repeated (provider, external_reference) is rejected, while many contributions without a reference are allowed (NULL-friendly unique)', function () {
    e30_seed();
    $supporter = e30_supporter();
    $program = e30_program();
    $first = e30_confirmed($supporter, $program, '5');
    $second = e30_confirmed($supporter, $program, '6');

    $row = fn (int $id) => (function () use ($id) {
        $r = (array) DB::table('sponsorship_contributions')->find($id);
        unset($r['id']);

        return $r;
    })();

    // بلا مرجع خارجي: صفّان NULL متجاوران مقبولان
    DB::table('sponsorship_contributions')->where('id', $first->id)->update(['external_reference' => null]);
    DB::table('sponsorship_contributions')->where('id', $second->id)->update(['external_reference' => null]);
    expect(DB::table('sponsorship_contributions')->whereNull('external_reference')->count())->toBeGreaterThanOrEqual(2);

    // مرجع خارجي مكرر لدى المزوّد نفسه: مرفوض
    DB::table('sponsorship_contributions')->where('id', $first->id)->update(['external_reference' => 'e31-ext-1']);
    $dup = $row($second->id);
    $dup['provider'] = DB::table('sponsorship_contributions')->where('id', $first->id)->value('provider');
    $dup['external_reference'] = 'e31-ext-1';

    expect(fn () => DB::table('sponsorship_contributions')->insert($dup))->toThrow(UniqueConstraintViolationException::class);
});

test('W-11: a sponsorship bonus source key is unique - the same source can never pay twice at the database level', function () {
    e30_seed();
    $supporter = e30_supporter();
    $program = e30_program();
    $user = User::factory()->create();
    $currency = app(CurrencyRegistry::class)->defaultEarnedCurrency();
    $now = now()->toDateTimeString();
    $bonus = [
        'user_id' => $user->id, 'sponsorship_program_id' => $program->id, 'currency_id' => $currency->id,
        'source_key' => 'e31-source-1',
    ];

    $columns = collect(DB::getSchemaBuilder()->getColumns('sponsorship_reward_bonuses'))->keyBy('name');
    foreach ($columns as $name => $column) {
        if (! array_key_exists($name, $bonus) && $name !== 'id' && ! $column['nullable'] && $column['default'] === null) {
            $bonus[$name] = match (true) {
                str_contains($column['type_name'], 'int') => 1,
                str_contains($column['type_name'], 'char') || str_contains($column['type_name'], 'text') => 'e31',
                str_contains($column['type_name'], 'json') => '{}',
                default => $now,
            };
        }
    }

    DB::table('sponsorship_reward_bonuses')->insert($bonus);

    expect(fn () => DB::table('sponsorship_reward_bonuses')->insert($bonus))->toThrow(UniqueConstraintViolationException::class);
    expect($supporter->id)->toBeInt();
});
`````

### `tests/Feature/DatabaseE31/E31MysqlRuntimeTest.php`

`sha256: e015038aa6fe594038df9daa2120c9e484d102040ae8ad464c34197596f4cee1`

`````php
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
`````

### `tests/Feature/DatabaseE31/E31ParityAndGuardsTest.php`

`sha256: bf77b2d65fc1895c05e9399c78e79e542a38c8abe5a5787fd5d4687c617c9b7e`

`````php
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
`````

### `tests/Feature/DatabaseE31/E31RetryPolicyTest.php`

`sha256: f32ccdcf3848a0b54f0daf7da4549619188b78e0a8aaa55e4262be962e3cf685`

`````php
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
`````

### `tests/Feature/DatabaseE31/E31StaticSchemaTest.php`

`sha256: 841d95581f6b0cf4287996a2935ca0bb6f832400733a6dcbaefcdf393d20cd7a`

`````php
<?php

use App\Database\MysqlSafeBlueprint;
use App\Services\Database\FinancialHistoryGuard;
use App\Services\Database\MysqlDdlCompiler;
use App\Services\Database\MysqlDdlLinter;
use Illuminate\Support\Facades\File;

require_once __DIR__.'/_support.php';

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
        fn (array $f) => "{$f['code']} · {$f['table']} · {$f['detail']} · {$f['migration']}",
        array_filter($lint['findings'], fn (array $f) => $f['level'] === 'fail'),
    ));
}

test('AR-1: every migration compiles to MySQL DDL with no errors (schema builds from zero, statically)', function () {
    $c = e31_compiled()['compiled'];

    expect($c['errors'])->toBe([])
        ->and($c['migrations'])->toBe(count(glob(database_path('migrations').'/*.php')))
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

    $tooLong = array_values(array_filter(array_unique($names), fn (string $n) => strlen($n) > 64));

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
    $dir = e31_tmp_dir().DIRECTORY_SEPARATOR.'migrations-noguard';
    File::deleteDirectory($dir);
    File::makeDirectory($dir, 0777, true);

    foreach (glob(database_path('migrations').'/*.php') as $file) {
        if (! str_contains($file, 'e31_')) {
            copy($file, $dir.DIRECTORY_SEPARATOR.basename($file));
        }
    }

    $without = (new MysqlDdlLinter)->analyze((new MysqlDdlCompiler)->compile($dir)['statements']);
    $codes = array_column(array_filter($without['findings'], fn (array $f) => $f['level'] === 'fail'), 'code');

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
    $exempt = ['wallets' /* يحرسه فحص الرصيد في الحارس */, 'challenge_participants' /* سجل مشاركة مشتق؛ الدفتر الفعلي في currency_transactions */];
    $unguarded = [];

    foreach (e31_compiled()['lint']['tables'] as $table => $model) {
        $cascadesOnUser = collect($model['fks'])->contains(fn (array $fk) => $fk['ref'] === 'users' && $fk['on_delete'] === 'cascade');

        if (! $cascadesOnUser) {
            continue;
        }

        $money = collect($model['columns'])->contains(fn (array $c, string $n) => in_array($c['base'], ['int', 'bigint', 'smallint', 'mediumint'], true)
            && preg_match('/(amount|balance|price|minor|bps|gems|earned|redeemed)/', $n) === 1);

        if ($money && ! in_array($table, array_merge($guarded, $exempt), true)) {
            $unguarded[] = $table;
        }
    }

    // جدول مالي جديد بـON DELETE CASCADE على users يجب أن يُضاف للحارس (FinancialHistoryGuard) أو يُبرَّر هنا.
    expect($unguarded)->toBe([]);
});

test('U-4: financial history that must survive parent deletion is RESTRICT (programs, supporters, currencies, store items)', function () {
    $tables = e31_compiled()['lint']['tables'];
    $rule = fn (string $table, string $column) => collect($tables[$table]['fks'])->first(fn (array $fk) => $fk['columns'] === [$column])['on_delete'] ?? 'missing';

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
    $unique = fn (string $table, array $columns) => collect($tables[$table]['indexes'])->contains(fn (array $i) => $i['unique'] && $i['columns'] === $columns);

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
    $redundant = collect(e31_compiled()['lint']['findings'])->where('code', 'redundant_index')->map(fn (array $f) => $f['table'])->sort()->values()->all();

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
        ->filter(fn ($f) => str_contains(File::get($f->getPathname()), 'TransactionPolicy::DEADLOCK_ATTEMPTS'))
        ->map(fn ($f) => str_replace(['\\', app_path().'/'], ['/', ''], $f->getPathname()))
        ->reject(fn ($p) => str_ends_with($p, 'Database/TransactionPolicy.php'))
        ->sort()->values()->all();

    expect($files)->toBe([
        'Services/Competitive/CompetitiveRunService.php',
        'Services/Creators/CreatorApplicationService.php',
        'Services/GameSessionService.php',
        'Services/PuzzleAttemptService.php',
    ])->and(\App\Services\Database\TransactionPolicy::DEADLOCK_ATTEMPTS)->toBe(3);

    // لا إعادة محاولة بالجملة: لا attempts: مسمّاة في أي مكان آخر.
    $named = collect(File::allFiles(app_path()))->filter(fn ($f) => preg_match('/DB::transaction\(.*attempts\s*:/s', File::get($f->getPathname())) === 1);
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
                    $offenders[] = str_replace(app_path().'/', '', $file->getPathname());
                }
            }
        }
    }

    expect(array_values(array_unique($offenders)))->toBe([]);
});
`````

### `tests/Feature/DatabaseE31/E31TransferTest.php`

`sha256: bda86f2b7fcc1495a480961b9affde6be030823cb8121eec8f32467615a0a3d2`

`````php
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
`````

### `tests/Feature/DatabaseE31/_support.php`

`sha256: d2600a96a51b4adc88eda6c49bf36ab243b489e286fa4e23051327cd60da48db`

`````php
<?php

use App\Services\Database\SqliteToMysqlTransfer;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
| E31 - مساعدات مشتركة. ليست ملف اختبار (لا تنتهي بـTest.php) فتُحمَّل بـrequire_once.
| الاختبارات «المحمولة» تعمل على أي سائق (SQLite سريع، وMySQL في مصفوفة التكافؤ)؛ اختبارات MySQL فقط تتخطى بوضوح على غيره (e31_requires_mysql).
| لا شبكة ولا أسرار. كل ملف SQLite مؤقت يُنشأ داخل مجلد النظام المؤقت ويُحذف في النهاية.
*/

require_once __DIR__.'/../SponsorshipE30/_support.php';

if (! function_exists('e31_driver')) {
    function e31_driver(): string
    {
        return DB::connection()->getDriverName();
    }

    function e31_is_mysql(): bool
    {
        return in_array(e31_driver(), ['mysql', 'mariadb'], true);
    }

    /** يتخطى الاختبار الجاري بوضوح إن لم يكن السائق MySQL/MariaDB (يُستدعى أول سطر في اختبار خاص بـMySQL). */
    function e31_requires_mysql(): void
    {
        if (! e31_is_mysql()) {
            test()->markTestSkipped('E31: يتطلب MySQL/MariaDB (السائق الحالي: '.e31_driver().'). شغّله بـphpunit.mysql.xml.dist - انظر docs/mysql-production-e31.md.');
        }
    }

    function e31_tmp_dir(): string
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'e31-'.getmypid();

        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        return $dir;
    }

    /** يحذف ملفات SQLite المؤقتة لهذه العملية (يُستدعى في afterAll/finally). */
    function e31_cleanup(): void
    {
        foreach ((array) glob(e31_tmp_dir().DIRECTORY_SEPARATOR.'*') as $file) {
            @unlink($file);
        }

        @rmdir(e31_tmp_dir());
    }

    /** ملف SQLite جديد مُرحَّل بالكامل (php artisan migrate) ومسجَّل كاتصال e31_{label}. يُرجع المسار. */
    function e31_new_sqlite(string $label): string
    {
        $path = e31_tmp_dir().DIRECTORY_SEPARATOR.$label.'-'.bin2hex(random_bytes(3)).'.sqlite';
        touch($path);
        $connection = 'e31_'.$label;
        config(['database.connections.'.$connection => array_merge((array) config('database.connections.sqlite'), [
            'driver' => 'sqlite', 'database' => $path, 'url' => null, 'foreign_key_constraints' => true,
        ])]);
        DB::purge($connection);
        Artisan::call('migrate', ['--database' => $connection, '--force' => true]);
        DB::purge($connection);

        return $path;
    }

    /** ينفّذ $fn والاتصال الافتراضي = $connection (لتعبئة ملف المصدر بالخدمات الرسمية)، ثم يعيد الافتراضي. */
    function e31_with_default(string $connection, Closure $fn): mixed
    {
        $previous = DB::getDefaultConnection();
        DB::setDefaultConnection($connection);
        \Illuminate\Support\Facades\Facade::clearResolvedInstance('db.schema');

        try {
            return $fn();
        } finally {
            DB::setDefaultConnection($previous);
            \Illuminate\Support\Facades\Facade::clearResolvedInstance('db.schema');
        }
    }

    /**
     * يعبّئ اتصال المصدر ببيانات واقعية عبر الخدمات الرسمية: مستخدمون وأدوار، أحجيات ومحاولات، محافظ ودفتر عملات، مبدع ومحتوى مشفَّر،
     * رعاية مالية (مساهمات مؤكدة + استرداد)، نص عربي وإيموجي، وصفوف في الجداول المؤقتة (يجب أن تُستثنى).
     *
     * @return array<string, mixed> أرقام مرجعية للتحقق
     */
    function e31_seed_world(string $connection): array
    {
        return e31_with_default($connection, function () {
            test()->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

            $admin = e30_admin();
            $player = e16User(['name' => 'لاعب عربي 🎮 ألِف']);
            $wallets = app(\App\Services\Economy\CurrencyWalletService::class);
            $currency = app(\App\Services\Economy\CurrencyRegistry::class)->defaultEarnedCurrency();
            $wallets->creditPending($player, $currency, 120, 'e31_seed', null, 'e31-seed-key-1');
            $wallets->releasePending($player, $currency, 70, 'e31_release');

            $supporter = e30_supporter(['display_name' => 'داعم الجواهر 💎']);
            $program = e30_program();
            e30_confirmed($supporter, $program, '12.50');
            $second = e30_confirmed($supporter, $program, '40');
            e30_refund($second);

            $creator = e28_creator();
            e28_category();
            e28_published($creator, 'text', ['title' => 'لغز مشفّر للنقل']);

            $puzzle = e27_puzzle('أحجية النقل');
            e27_attempt($player, $puzzle, true);

            // جداول مؤقتة يجب ألا تُنقل
            DB::table('sessions')->insert(['id' => 'e31-session-'.bin2hex(random_bytes(3)), 'user_id' => $player->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'x', 'payload' => 'e31', 'last_activity' => time()]);
            DB::table('cache')->insert(['key' => 'e31:cache', 'value' => 'x', 'expiration' => time() + 600]);
            DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => time(), 'created_at' => time()]);

            return ['player_id' => $player->id, 'admin_id' => $admin->id, 'supporter_id' => $supporter->id, 'program_id' => $program->id, 'puzzle_id' => $puzzle->id];
        });
    }

    /** خدمة نقل تقبل هدف SQLite (للاختبارات المحمولة فقط؛ الأمر الحقيقي يشترط mysql/mariadb). */
    function e31_service(array $over = []): SqliteToMysqlTransfer
    {
        return new SqliteToMysqlTransfer($over + ['target_drivers' => ['mysql', 'mariadb', 'sqlite']] + (array) config('db_transfer'));
    }

    /** يربط الخدمة بملف مصدر ومسمّى اتصال هدف SQLite مُسجَّل مسبقًا. */
    function e31_connected(string $sourcePath, string $targetConnection, array $over = []): SqliteToMysqlTransfer
    {
        $service = e31_service($over);
        $service->connect($sourcePath, $targetConnection);

        return $service;
    }
}
`````

### `tools/e31/explain-audit.sql`

`sha256: 3608ee4c9be7a9a6420f07e4559e239c6f46a72a22215eb57a670260e2445f16`

`````sql
-- E31 - تدقيق EXPLAIN على أهم الاستعلامات الساخنة (MySQL 8). قراءة فقط: كلها EXPLAIN ولا تعدّل أي بيانات.
-- شغّله على قاعدة فيها بيانات واقعية (بعد النقل أو بعد php artisan performance:seed) وليس على قاعدة فارغة:
-- على جدول فارغ يختار المخطِّط خططًا لا تمثل الحمل الحقيقي.
--
-- PowerShell (من جذر المشروع؛ لا يدعم PowerShell عامل < لتمرير الملف):
--   $env:MYSQL_PWD = '<كلمة-مرور-المستخدم>'
--   Get-Content tools\e31\explain-audit.sql -Raw -Encoding UTF8 | mysql --default-character-set=utf8mb4 -u ahjiyat_app ahjiyat_platform
--   Remove-Item Env:\MYSQL_PWD
--
-- كيف تقرأ النتيجة: لكل استعلام انظر العمودين key (الفهرس المستعمل) وrows (الصفوف المقدّرة)، وExtra:
--   * key = الفهرس المذكور في التعليق  →  سليم.
--   * type = ALL (مسح كامل) على جدول كبير، أو Extra فيه "Using filesort" مع rows كبير  →  أرسل لي السطر لأقرر فهرسًا مدروسًا.
--   * "Using temporary" على استعلام يظهر في صفحة عامة  →  أرسله أيضًا.
-- لا نضيف أي فهرس قبل رؤية خطة حقيقية بحجم بيانات حقيقي (كل فهرس يبطئ الكتابة).

SET NAMES utf8mb4;

-- 01 الدردشة: صفحة الرسائل بمؤشر id.            المتوقع: chat_messages_chat_thread_id_id_index (range/ref)
EXPLAIN SELECT * FROM chat_messages WHERE chat_thread_id = 1 AND id < 100 ORDER BY id DESC LIMIT 41;

-- 02 بحث الأحجيات (بادئة العنوان).               المتوقع: puzzles_active_title_search_index (range)
EXPLAIN SELECT id, title FROM puzzles WHERE is_active = 1 AND title LIKE 'لغز%' ORDER BY title LIMIT 9;

-- 03 بحث اللاعبين (بادئة الاسم).                 المتوقع: users_name_search_index (range)
EXPLAIN SELECT id, name FROM users WHERE name LIKE 'حسن%' LIMIT 9;

-- 04 الرائج: مدى على المفتاح الأساسي.            المتوقع: PRIMARY (type=range؛ لا ALL على puzzle_attempts)
EXPLAIN SELECT puzzle_id, COUNT(DISTINCT user_id) AS players FROM puzzle_attempts
 WHERE id >= 1000 AND created_at >= NOW() - INTERVAL 7 DAY AND context_type IS NULL GROUP BY puzzle_id;

-- 05 قفل المحفظة (المسار المالي الأهم).          المتوقع: wallets_user_id_currency_id_unique (const)
EXPLAIN SELECT * FROM wallets WHERE user_id = 1 AND currency_id = 1 FOR UPDATE;

-- 06 مفتاح idempotency في الدفتر.                المتوقع: currency_transactions_idempotency_key_unique (const)
EXPLAIN SELECT * FROM currency_transactions WHERE idempotency_key = 'e31-probe';

-- 07 سجل عملات مستخدم (الأحدث أولًا).            قد يظهر filesort لأن الفهرس (user_id,type): مراقبة فقط، لا فهرس قبل القياس
EXPLAIN SELECT * FROM currency_transactions WHERE user_id = 1 ORDER BY id DESC LIMIT 20;

-- 08 صندوق الإشعارات غير المقروءة.               المتوقع: notifications_inbox_index
EXPLAIN SELECT * FROM notifications WHERE notifiable_type = 'App\\Models\\User' AND notifiable_id = 1 AND read_at IS NULL ORDER BY created_at DESC LIMIT 20;

-- 09 لوحة صدارة حدث تنافسي.                      المتوقع: فهرس (competitive_event_id, score, duration_ms)؛ filesort طفيف مقبول (اتجاهان مختلفان)
EXPLAIN SELECT user_id, score, duration_ms FROM competitive_event_results WHERE competitive_event_id = 1 ORDER BY score DESC, duration_ms ASC LIMIT 50;

-- 10 قائمة أعمال المجتمع (الأحدث).               المتوقع: creator_puzzles_visible_new_index
EXPLAIN SELECT id FROM creator_puzzles WHERE is_visible = 1 ORDER BY published_at DESC LIMIT 12;

-- 11 بحث أعمال المجتمع (بادئة العنوان).          المتوقع: creator_puzzles_visible_title_index (range)
EXPLAIN SELECT id FROM creator_puzzles WHERE is_visible = 1 AND published_title LIKE 'لغز%' LIMIT 9;

-- 12 سلسلة بالمعرّف العام.                       المتوقع: creator_series_public_id_unique (const)
EXPLAIN SELECT id FROM creator_series WHERE public_id = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

-- 13 تجميع التقييمات لعمل.                       المتوقع: community_ratings_work_rating_index (Using index)
EXPLAIN SELECT COUNT(*), SUM(rating) FROM community_puzzle_ratings WHERE creator_puzzle_id = 1;

-- 14 توزيع إشعار النشر على المتابعين بدفعات.     المتوقع: creator_follows_fanout_index (range)
EXPLAIN SELECT user_id FROM creator_follows WHERE creator_profile_id = 1 AND id > 0 ORDER BY id LIMIT 500;

-- 15 لوحة الداعمين (تجميع مؤكَّد غير مجهول).     المتوقع: sponsorship_contrib_leaderboard_index
EXPLAIN SELECT supporter_profile_id, SUM(accounting_amount_minor) AS total FROM sponsorship_contributions
 WHERE status = 'confirmed' AND is_anonymous = 0 GROUP BY supporter_profile_id;

-- 16 قائمة طلبات الاستبدال قيد المراجعة.         المتوقع: redemption_requests_status_index
EXPLAIN SELECT * FROM redemption_requests WHERE status = 'pending' ORDER BY id LIMIT 25;

-- 17 جلسة لعب قائمة لمستخدم وأحجية.              المتوقع: game_sessions_user_id_puzzle_id_status_index
EXPLAIN SELECT * FROM game_sessions WHERE user_id = 1 AND puzzle_id = 1 AND status = 'in_progress' LIMIT 1;
`````

### `tools/e31/run-mysql-matrix.ps1`

`sha256: 87e48166ee9d14e2b55bf16000182d1e349be9a697a51a830be2e1392e3f773a`

`````powershell
﻿<#
.SYNOPSIS
  E31 - مصفوفة اختبارات MySQL المركّزة (Auth, Wallet/Economy, Store, Competitive, Chat, Notifications, Search E27, Creator E28, Engagement E29, Sponsorship E30, Creator Series E30, Database E31).

.DESCRIPTION
  يشغّل كل مجموعة بمسارات صريحة (لا اختبار كامل ولا أمر بلا مسار) على قاعدة MySQL اختبار مخصصة، ثم يطبع جدول نتائج، ثم يشغّل db:parity للقراءة فقط.
  لا يمسّ قاعدة العمل: يرفض أي اسم قاعدة لا ينتهي بـ _test أو _testing أو _test_mysql (يحمي RefreshDatabase من حذف جداول العمل).
  يحتاج PHP وملف vendor موجودين مسبقًا (لا يثبّت شيئًا ولا ينفّذ composer/npm). لا يطبع كلمة المرور.

.EXAMPLE
  powershell -ExecutionPolicy Bypass -File tools/e31/run-mysql-matrix.ps1 -Username root -Password ""

.EXAMPLE
  powershell -ExecutionPolicy Bypass -File tools/e31/run-mysql-matrix.ps1 -Only Auth,Store -SkipCreate
#>
[CmdletBinding()]
param(
    [string]$DbHost = '127.0.0.1',
    [int]$Port = 3306,
    [string]$Database = 'ahjiyat_platform_test_mysql',
    [string]$Username = 'root',
    [string]$Password = '',
    # لا تُنشئ القاعدة (إن كانت موجودة سلفًا أو لا تملك صلاحية CREATE DATABASE)
    [switch]$SkipCreate,
    # أسماء مجموعات محددة فقط (مثل Auth,Store). الافتراضي: كلها
    [string[]]$Only = @(),
    # توقف عند أول مجموعة فاشلة
    [switch]$StopOnFail
)

$ErrorActionPreference = 'Stop'

# ── حواجز السلامة ─────────────────────────────────────────────────────────────
if ($Database -notmatch '(^|_)(test|testing)(_(mysql|mariadb))?$') {
    Write-Host "مرفوض: اسم القاعدة '$Database' لا ينتهي بـ _test أو _testing أو _test_mysql. هذه الأداة تحذف كل جداول القاعدة المختارة (migrate:fresh) فلا تُوجَّه لقاعدة العمل." -ForegroundColor Red
    exit 2
}
if ($Database -notmatch '^[A-Za-z0-9_]{1,50}$') {
    Write-Host "مرفوض: اسم القاعدة يجب أن يتألف من حروف لاتينية وأرقام وشرطة سفلية فقط (حتى 50 خانة)." -ForegroundColor Red
    exit 2
}

$root = Resolve-Path (Join-Path $PSScriptRoot '..\..')
Set-Location $root

if (-not (Test-Path 'vendor\bin\pest')) {
    Write-Host 'لم أجد vendor\bin\pest: شغّل composer install مرة واحدة يدويًا في مشروعك أولًا (هذه الأداة لا تثبّت شيئًا).' -ForegroundColor Red
    exit 2
}
if (-not (Test-Path 'phpunit.mysql.xml.dist')) {
    Write-Host 'لم أجد phpunit.mysql.xml.dist في جذر المشروع.' -ForegroundColor Red
    exit 2
}

# بيئة هذه الجلسة فقط (تتقدّم على .env وعلى افتراضات phpunit.mysql.xml.dist)
$env:DB_CONNECTION = 'mysql'
$env:DB_HOST = $DbHost
$env:DB_PORT = "$Port"
$env:DB_DATABASE = $Database
$env:DB_USERNAME = $Username
$env:DB_PASSWORD = $Password
$env:DB_TIMEZONE = '+00:00'
$env:APP_ENV = 'testing'

# ── إنشاء قاعدة الاختبار (utf8mb4) ───────────────────────────────────────────
if (-not $SkipCreate) {
    $tmp = Join-Path ([System.IO.Path]::GetTempPath()) ("e31-create-db-" + [guid]::NewGuid().ToString('N') + '.php')
    $script = @'
<?php
$dsn = 'mysql:host=' . getenv('DB_HOST') . ';port=' . getenv('DB_PORT') . ';charset=utf8mb4';
try {
    $pdo = new PDO($dsn, getenv('DB_USERNAME'), getenv('DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $name = getenv('DB_DATABASE');
    if (!preg_match('/^[A-Za-z0-9_]{1,50}$/', $name)) { fwrite(STDERR, "bad name\n"); exit(2); }
    $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $v = $pdo->query('select version()')->fetchColumn();
    echo 'MySQL server version: ' . $v . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'تعذّر الاتصال/الإنشاء: ' . get_class($e) . ' (رمز ' . $e->getCode() . ')' . PHP_EOL);
    exit(1);
}
'@
    [System.IO.File]::WriteAllText($tmp, $script, (New-Object System.Text.UTF8Encoding($false)))   # UTF-8 بلا BOM كي لا يطبع PHP بايتات زائدة
    try {
        & php $tmp
        if ($LASTEXITCODE -ne 0) {
            Write-Host 'تعذّر الوصول إلى خادم MySQL. تأكد أنه يعمل وأن المضيف/المنفذ/المستخدم/كلمة المرور صحيحة (انظر docs/mysql-production-e31.md، قسم الإعداد المحلي).' -ForegroundColor Red
            exit 2
        }
    } finally {
        Remove-Item -Path $tmp -ErrorAction SilentlyContinue
    }
}

# ── المجموعات (مسارات صريحة فقط) ────────────────────────────────────────────
$groups = [ordered]@{
    'Auth'                = @('tests/Feature/Auth', 'tests/Feature/Rbac')
    'WalletEconomy'       = @('tests/Feature/Economy', 'tests/Feature/GemWalletServiceTest.php', 'tests/Feature/RedemptionServiceTest.php', 'tests/Feature/PuzzleAttemptServiceTest.php', 'tests/Feature/GameSessionTest.php')
    'Store'               = @('tests/Feature/Store')
    'Competitive'         = @('tests/Feature/Competitive', 'tests/Feature/CompetitiveRewards', 'tests/Feature/TeamCompetition')
    'Chat'                = @('tests/Feature/Chat')
    'Notifications'       = @('tests/Feature/Notifications')
    'SearchE27'           = @('tests/Feature/DiscoveryE27')
    'CreatorE28'          = @('tests/Feature/CreatorE28')
    'EngagementE29'       = @('tests/Feature/EngagementE29')
    'SponsorshipE30'      = @('tests/Feature/SponsorshipE30')
    'CreatorSeriesE30'    = @('tests/Feature/CreatorSeriesE30')
    'DatabaseE31'         = @('tests/Feature/DatabaseE31', 'tests/Unit/TestDatabaseGuardTest.php')
}

$selected = @($groups.Keys)
if ($Only.Count -gt 0) {
    $selected = @($groups.Keys | Where-Object { $Only -contains $_ })
    $unknown = @($Only | Where-Object { -not $groups.Contains($_) })
    if ($unknown.Count -gt 0) {
        Write-Host ("مجموعات غير معروفة: " + ($unknown -join ', ') + ". المتاح: " + (@($groups.Keys) -join ', ')) -ForegroundColor Red
        exit 2
    }
}

Write-Host ''
Write-Host "== E31 MySQL matrix : $DbHost`:$Port / $Database (user: $Username) ==" -ForegroundColor Cyan

$results = @()
$failed = $false

foreach ($name in $selected) {
    $paths = $groups[$name]
    Write-Host ''
    Write-Host ("-- {0}: {1}" -f $name, ($paths -join ' ')) -ForegroundColor Cyan
    $watch = [System.Diagnostics.Stopwatch]::StartNew()
    & php vendor/bin/pest --configuration=phpunit.mysql.xml.dist @paths
    $code = $LASTEXITCODE
    $watch.Stop()
    $results += [pscustomobject]@{ Group = $name; Result = $(if ($code -eq 0) { 'PASS' } else { 'FAIL' }); Seconds = [math]::Round($watch.Elapsed.TotalSeconds, 1) }
    if ($code -ne 0) {
        $failed = $true
        if ($StopOnFail) { break }
    }
}

Write-Host ''
Write-Host '== Summary ==' -ForegroundColor Cyan
$results | Format-Table -AutoSize

# ── db:parity (قراءة فقط) على القاعدة التي بنتها الاختبارات ────────────────────
Write-Host '-- db:parity (read-only) on the migrated test database' -ForegroundColor Cyan
& php artisan db:parity --connection=mysql --runtime
$parity = $LASTEXITCODE
if ($parity -ne 0) { $failed = $true }

Write-Host ''
if ($failed) {
    Write-Host 'RESULT: FAIL - أرسل لي مخرجات المجموعات الفاشلة (دون كلمات مرور).' -ForegroundColor Red
    exit 1
}
Write-Host 'RESULT: PASS - هذا هو الدليل التشغيلي على MySQL الذي يحتاجه إغلاق E31.' -ForegroundColor Green
exit 0
`````

---

## B) MODIFIED FILES — ملفات كاملة (21 ملفًا)

كلها نسخ نهائية كاملة تُستبدل بها ملفاتك (لا باتشات). مواضع التعديل موسومة بتعليق `E31` في معظم الملفات (ابحث عن `E31`).

### `.env.example`

`sha256: 253d01b3d39f9629e768d7be99d0aaca12ed5a222c5ab646bb0d7e603d3c775d`

`````dotenv
APP_NAME=Laravel
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_URL=http://localhost

APP_LOCALE=en
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=en_US

APP_MAINTENANCE_DRIVER=file
# APP_MAINTENANCE_STORE=database

PHP_CLI_SERVER_WORKERS=4

BCRYPT_ROUNDS=12

LOG_CHANNEL=stack
LOG_STACK=single
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=debug

# E31: MySQL 8.0.16+ هي قاعدة الإنتاج المعتمدة (docs/mysql-production-e31.md). SQLite تبقى لاختبارات سريعة وCI (phpunit.xml.dist) وللتطوير الخفيف.
# للتطوير الخفيف على SQLite بدل MySQL: DB_CONNECTION=sqlite واحذف الأسطر التالية حتى DB_TIMEZONE (يُنشأ database/database.sqlite بـphp artisan migrate).
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=ahjiyat_platform
DB_USERNAME=ahjiyat_app
DB_PASSWORD=
DB_TIMEZONE=+00:00
# DB_ENGINE=InnoDB

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=null

BROADCAST_CONNECTION=reverb

# E21: Laravel Reverb (خادم WebSocket من الطرف الأول). عبّئ القيم محليًا/بالإنتاج: لا أسرار حقيقية هنا.
REVERB_APP_ID=
REVERB_APP_KEY=
REVERB_APP_SECRET=
REVERB_HOST=localhost
REVERB_PORT=8080
REVERB_SCHEME=http
REVERB_SERVER_HOST=0.0.0.0
REVERB_SERVER_PORT=8080

VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"
FILESYSTEM_DISK=local
QUEUE_CONNECTION=database

CACHE_STORE=database
# CACHE_PREFIX=

MEMCACHED_HOST=127.0.0.1

REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

MAIL_MAILER=log
MAIL_SCHEME=null
MAIL_HOST=127.0.0.1
MAIL_PORT=2525
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_FROM_ADDRESS="hello@example.com"
MAIL_FROM_NAME="${APP_NAME}"

AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=
AWS_USE_PATH_STYLE_ENDPOINT=false

VITE_APP_NAME="${APP_NAME}"

# ==============================================================================
# E24: الأداء / التوسّع / الموثوقية / المراقبة  (كلها اختيارية؛ الافتراضات تحفظ سلوك المنتج كما هو)
# ==============================================================================
# CACHE_STORE=database يتطلب جدولَي cache وcache_locks (تنشئهما migration 2026_10_18_000001 بعد php artisan migrate).
# توصية الإنتاج متعدد الخوادم (توصية بنية تحتية، غير مفروضة محليًا): CACHE_STORE=redis و SESSION_DRIVER=redis و QUEUE_CONNECTION=redis
# مع REDIS_CLIENT=phpredis؛ أبقِ الجلسات/الكاش/الطوابير على قواعد Redis (DB) منفصلة. المجدول onOneServer يحتاج كاشًا مشتركًا بين الخوادم.
# DB_QUEUE_RETRY_AFTER=90          # يجب أن يظل أكبر من timeout أي Job (80ث) كي لا تُلتقط مهمة ما زالت تعمل
# REDIS_QUEUE_RETRY_AFTER=90

# خلف موازن حِمل/وكيل عكسي: بدونها يرى التطبيق IP الوكيل بدل IP العميل (يفسد حدود المعدّل وبصمات الاحتيال).
# فارغ/غير معرَّف = لا وكلاء موثوقة (آمن افتراضيًا)؛ "*" = كل الوكلاء (فقط إن كان التطبيق غير قابل للوصول إلا عبر الموازن)؛ أو قائمة IP/CIDR مفصولة بفواصل.
# TRUSTED_PROXIES=10.0.0.0/8,172.16.0.0/12

# المجدول: نفّذ كل مهمة على خادم واحد فقط عند تعدّد الخوادم (يحتاج كاشًا مشتركًا). على خادم واحد لا أثر له.
# PERF_SCHEDULER_ONE_SERVER=true

# تتبّع الجهاز (الاحتيال): مرة كل N ثانية لكل (مستخدم، IP، جهاز) بدل كل طلب. 0 = السلوك القديم (كل طلب).
# PERF_DEVICE_SIGHTING_THROTTLE=60

# مدد الكاش للعرض فقط (ثوانٍ، محدودة دائمًا). لا تُخزَّن بيانات صلاحيات أو أرصدة أو مكافآت.
# PERF_LEVEL_CATALOG_TTL=3600
# PERF_BRANDING_URLS_TTL=600
# PERF_LEADERBOARD_TTL=60

# المراقبة: معرّف الطلب X-Request-ID، وسجل الطلبات البطيئة (لا أجسام/كوكيز/رؤوس/سلسلة استعلام). 0 = تعطيل السجل البطيء.
# PERF_REQUEST_ID=true
# PERF_SLOW_REQUEST_MS=1500
# PERF_SLOW_REQUEST_SAMPLE=1.0
# فحص الجاهزية GET /ready (قاعدة البيانات + الكاش) ومهلته؛ الرمز اختياري (الرأس X-Health-Token). GET /up الأصلي للحياة فقط.
# PERF_READINESS_TIMEOUT_MS=1500
# PERF_READINESS_TOKEN=

# مهل اتصال PHP -> Reverb للبثّ المتزامن (ثوانٍ). قصيرة كي لا يحجز Reverb المعلّق عامل PHP-FPM ثلاثين ثانية.
# REVERB_HTTP_CONNECT_TIMEOUT=1
# REVERB_HTTP_TIMEOUT=3

# ==============================================================================
# E25: التشغيل بالإنتاج / الأمان / النشر / الاستعادة  (كلها اختيارية؛ الافتراضات تحفظ سلوك المنتج كما هو)
# القيم هنا placeholders فقط: لا أسرار حقيقية بهذا الملف ولا بأي ملف بالمستودع. أسرار الإنتاج من مدير أسرار المزوّد أو متغيرات بيئة محميّة (docs/environment-variables.md).
# ==============================================================================
# ملخص عقد الإنتاج (php artisan production:check يفحصه ويعطي PASS/WARN/FAIL بلا طباعة أي قيمة سرية):
#   APP_ENV=production  APP_DEBUG=false  APP_URL=https://<نطاقك>  SESSION_SECURE_COOKIE=true
#   DB_CONNECTION=mysql (أو pgsql)  QUEUE_CONNECTION=database|redis  MAIL_MAILER=smtp  LOG_STACK=daily
#   BROADCAST_CONNECTION=reverb مع REVERB_SCHEME=https و REVERB_HOST=<نطاق> و REVERB_ALLOWED_ORIGINS=<نطاقك>

# كوكي الجلسة: إلزامي true بالإنتاج على HTTPS (يمنع إرسالها عبر HTTP). محليًا اتركه غير مضبوط.
# SESSION_SECURE_COOKIE=true
# SESSION_HTTP_ONLY=true
# SESSION_SAME_SITE=lax

# هوية الإصدار (اختيارية): تُضبط عند كل نشر (SHA مختصر أو وسم). تظهر في /ready ومركز العمليات وكل سطر سجل (release). الصيغة [A-Za-z0-9._-] حتى 64 خانة.
# APP_RELEASE=
# RELEASE_SHA=

# السجلات: بالإنتاج استعمل daily مع احتفاظ محدود (القناة single بلا تدوير ولا حد حجم). LOG_LEVEL=info أو warning بدل debug.
# LOG_STACK=daily
# LOG_DAILY_DAYS=14

# Reverb: قيّد الأصول المسموحة لاتصالات WebSocket (أسماء مضيفين مفصولة بفواصل، بلا http://). الافتراضي * (يسمح للجميع).
# REVERB_ALLOWED_ORIGINS=ahjiyat.example.com

# أول مدير بالإنتاج فقط (AdminUserSeeder): لا كلمة مرور افتراضية بالإنتاج. ضعهما مؤقتًا بالبيئة لأول تشغيل ثم احذفهما فورًا. ≥ 12 خانة.
# ADMIN_BOOTSTRAP_EMAIL=
# ADMIN_BOOTSTRAP_PASSWORD=

# نبض المجدول وتنبيهات الطابور (ثوانٍ؛ كلها اختيارية).
# OPS_SCHEDULER_STALE_SECONDS=300
# OPS_SCHEDULER_DOWN_SECONDS=900
# OPS_READY_FAILS_ON_SCHEDULER=false     # true: /ready يفشل فقط عند حالة down (لا عند التأخر البسيط ولا بعد نشر جديد)
# OPS_QUEUE_BACKLOG_WARN_SECONDS=900
# OPS_FAILED_JOBS_WARN=1

# الأوامر الهدّامة (db:wipe, migrate:fresh|refresh|reset|rollback) ممنوعة بالإنتاج. استثناء واعٍ لعملية واحدة فقط (لا تضعه بملف .env الدائم):
# OPS_ALLOW_DESTRUCTIVE_COMMANDS=false

# E26 - أمان التطبيق (كلها اختيارية؛ الافتراضيات آمنة). انظر docs/security-e26.md.
# SECURITY_LOGIN_MAX_ATTEMPTS=5              # محاولات دخول فاشلة لكل (بريد+IP) قبل القفل المؤقت
# SECURITY_LOGIN_DECAY_SECONDS=300           # مدة القفل بالثواني
# SECURITY_LOGIN_IP_MAX_ATTEMPTS=30          # سقف الفشل لكل IP (رش كلمات المرور). أعلى بكثير كي لا يضرّ شبكة مشتركة
# SECURITY_API_EXPIRY_MINUTES=43200       # عمر رمز API (30 يومًا). 0 = بلا انتهاء (غير مستحسن)
# SECURITY_CSP_BASELINE=true                 # CSP أساسي (frame-ancestors/base-uri/object-src) لا يمسّ تنفيذ السكربتات
# REVERB_APP_ACCEPT_CLIENT_EVENTS_FROM=none  # أحداث العميل مغلقة (التطبيق لا يستعملها)
# SECURITY_TRUST_HOSTS=true                  # يقيّد Host بمضيف APP_URL بالإنتاج (يمنع تسميم روابط إعادة التعيين). تأكد أن APP_URL هو النطاق الفعلي
# SECURITY_TRUSTED_HOSTS=www.example.com     # مضيفات إضافية مسموحة (مفصولة بفواصل)

# E27 - البحث العام والاكتشاف والرائج والتوصيات (كلها اختيارية؛ الافتراضيات آمنة ولا تتطلب شيئًا). انظر docs/search-discovery-e27.md.
# SEARCH_DRIVER=database                     # السائق الوحيد حاليًا. قيمة غير معروفة تسقط إلى database (لا تعطّل الموقع). محرك خارجي = تنفيذ جديد لـSearchBackend
# SEARCH_MIN_LENGTH=2                        # أقل طول للمصطلح (حروف بعد التطبيع)
# SEARCH_MIN_LENGTH_GUEST_PLAYERS=3          # الضيف يحتاج 3 أحرف للبحث عن لاعبين (يحدّ التخمين)
# SEARCH_PARTIAL_MAX_ID=10000                # أكبر معرّف بجدول يُسمح عنده بالمطابقة الجزئية (وسط الكلمة/الحروف العربية المتقاربة). فوقه: بادئة العمود فقط. هذا مؤشر الانتقال لمحرك بحث/FULLTEXT
# SEARCH_TRENDING_WINDOW_DAYS=7              # نافذة «الرائج»
# SEARCH_TRENDING_TTL=300                    # عمر لقطة الرائج بالثواني (معرّفات ودرجات فقط)
# SEARCH_TRENDING_MIN_PLAYERS=2              # أقل عدد لاعبين مميَّزين كي تظهر أحجية بالرائج

# E28 - منظومة المبدعين وأحجيات المجتمع (كلها اختيارية؛ الافتراضيات آمنة ولا تتطلب شيئًا). انظر docs/creator-ecosystem-e28.md.
# أحجيات المجتمع بلا مكافآت اقتصادية بأي إعداد (جواهر/XP = 0 ثابت بالكود، لا مفتاح بيئة له).
# CREATOR_PUZZLE_MAX_ATTEMPTS=3              # عدد محاولات اللاعب لكل أحجية مجتمع (سيرفري، لا يحدده المبدع)
# CREATOR_MAX_WORKS=100                      # سقف أعمال المبدع الواحد
# CREATOR_MAX_OPEN_DRAFTS=20                 # سقف المسودات المفتوحة لكل مبدع
# CREATOR_MAX_PENDING=5                      # سقف الأعمال قيد المراجعة معًا لكل مبدع
# CREATOR_IMAGE_MAX_KB=4096                  # أقصى حجم صورة أحجية (كيلوبايت). JPEG/PNG/WEBP فقط، لا SVG
# CREATOR_ANALYTICS_TTL=120                  # عمر لقطة تحليلات المبدع بالثواني (أرقام مجمَّعة فقط)
# CREATOR_TRENDING_MIN_PLAYERS=2             # أقل عدد لاعبين مميَّزين كي يظهر عمل بقسم «رائج من المجتمع»

# E29 - التفاعل المجتمعي: التقييمات والمفضلة ومتابعة المبدعين والمجموعات الشخصية والأعلى تقييمًا (كلها اختيارية؛ الافتراضيات آمنة). انظر docs/community-engagement-e29.md.
# التفاعل بلا أي سلطة اقتصادية بأي إعداد (لا جواهر/XP/مهام/سلسلة/إنجازات/صدارة): لا مفتاح بيئة لذلك.
# ENGAGEMENT_RATING_DISPLAY_MIN=3            # أقل عدد تقييمات لإظهار «المتوسط» علنًا (تحته يُعرض العدد فقط)
# ENGAGEMENT_TOP_MIN_RATINGS=5               # أقل عدد تقييمات كي يدخل العمل ترتيب «الأعلى تقييمًا»
# ENGAGEMENT_TOP_PRIOR_MEAN=3.5              # المتوسط الافتراضي للانكماش البايزي: score = (sum + mean*weight) / (count + weight)
# ENGAGEMENT_TOP_PRIOR_WEIGHT=5              # وزن المتوسط الافتراضي (كم «تقييمًا وهميًا»)؛ كلما كبر احتاج الوصول للقمة تقييمات أكثر
# ENGAGEMENT_TOP_CACHE_TTL=300               # عمر لقطة الأعلى تقييمًا بالثواني (معرّفات ودرجات فقط؛ الظهور يُفحص حيًّا)
# ENGAGEMENT_CREATOR_MIN_RATINGS=5           # أقل مجموع تقييمات لإظهار ملخّص جودة المبدع بصفحته العامة
# ENGAGEMENT_MAX_FOLLOWS=500                 # سقف من يتابعهم المستخدم الواحد
# ENGAGEMENT_MAX_COLLECTIONS=20              # سقف مجموعات المستخدم الواحد
# ENGAGEMENT_MAX_COLLECTION_ITEMS=100        # سقف عناصر المجموعة الواحدة
# ENGAGEMENT_NOTIFY_ON_REVISION=true         # إشعار المتابعين أيضًا عند اعتماد نسخة جديدة لعمل منشور سابقًا

# E30 - الرعاة والداعمون وتمويل المكافآت + سلاسل المبدعين (كلها اختيارية؛ الافتراضيات آمنة). انظر docs/sponsorship-supporters-e30.md و docs/creator-series-e30.md.
# سلاسل المبدعين بلا أي سلطة اقتصادية بأي إعداد ولا يطالها تعزيز الرعاية: لا مفتاح بيئة لذلك.
# SPONSORSHIP_ENABLED=true                   # تعطيل شامل لواجهات الرعاية والتعزيز (التاريخ المالي يبقى كما هو)
# SPONSORSHIP_ACCOUNTING_CURRENCY=USD        # عملة المحاسبة الموحَّدة للمجاميع والمستويات والتعزيز
# SPONSORSHIP_MAX_AMOUNT_MINOR=1000000000    # سقف المبلغ الواحد بالوحدة الصغرى (حماية من أخطاء الإدخال)
# SPONSORSHIP_LOGO_DISK=public               # قرص شعارات الداعمين (JPEG/PNG/WEBP فقط)
# SPONSORSHIP_LOGO_MAX_KB=1024               # أقصى حجم شعار بالكيلوبايت
# SPONSORSHIP_CACHE_TTL=120                  # عمر لقطات اللوحات العامة بالثواني (معرّفات ومجاميع فقط؛ الظهور يُفحص حيًّا)
# SPONSORSHIP_DISPLAY_TTL=60                 # عمر لقطة شريط الرعاة بالثواني
# SPONSORSHIP_REWARD_BOOST_ENABLED=true      # تعزيز مكافآت المحتوى الرسمي فقط (false = لا تعزيز إطلاقًا)
# SPONSORSHIP_HARD_MAX_BOOST_BPS=5000        # السقف الصلب للتعزيز بالنقاط الأساسية (5000 = 50%) لا يتجاوزه أي برنامج
# SPONSORSHIP_EVENT_LOCK_LEAD_MINUTES=60     # قفل خطة تعزيز المنافسة قبل بدئها بهذه المدة (دقائق)
# SPONSORSHIP_NOTIFICATIONS_ENABLED=true     # إشعارات الداعمين (تأكيد الدعم/تغيّر المستوى/بدء البرنامج)
# CREATOR_SERIES_ENABLED=true                # تعطيل سلاسل المبدعين (404) دون مساس بأحجيات المجتمع
# CREATOR_SERIES_MIN_ITEMS=2                 # أقل عدد عناصر للسلسلة
# CREATOR_SERIES_MAX_ITEMS=25                # أقصى عدد عناصر للسلسلة
# CREATOR_SERIES_MAX_PER_CREATOR=50          # سقف سلاسل المبدع الواحد غير المؤرشفة
# CREATOR_SERIES_MAX_PENDING=5               # سقف السلاسل قيد المراجعة معًا لكل مبدع
# CREATOR_SERIES_DISCOVERY_TTL=120           # عمر كاش معرّفات «أحدث السلاسل» (الظهور يُفحص حيًّا بكل عرض)
# CREATOR_SERIES_ANALYTICS_TTL=600           # عمر لقطة تحليلات سلاسل المبدع (أرقام مجمَّعة بلا هويات)
# CREATOR_SERIES_NOTIFY_FOLLOWERS=true       # إشعار المتابعين عند نشر سلسلة جديدة (دفعات، idempotent)
# CREATOR_SERIES_NOTIFY_ON_REVISION=true     # إشعار المتابعين عند اعتماد نسخة جديدة لسلسلة منشورة سابقًا

# E31 - MySQL والنقل من SQLite (كلها اختيارية؛ الافتراضيات آمنة). انظر docs/mysql-production-e31.md.
# OPS_ALLOW_SQLITE_PRODUCTION=false          # true: يحوّل فشل production:check على SQLite إلى تحذير (غير مستحسن؛ MySQL هي المعتمدة)
# MIGRATION_TARGET_CONNECTION=mysql          # اتصال الهدف لأمر db:transfer-sqlite-to-mysql (محلي فقط، يرفض الإنتاج)
# MIGRATION_SQLITE_DATABASE=                 # مسار ملف SQLite المصدر (أو مرِّره بـ--source)
# MIGRATION_MYSQL_HOST=                      # تجاوزات الهدف إن أردت إبقاء DB_* على SQLite أثناء النقل
# MIGRATION_MYSQL_PORT=
# MIGRATION_MYSQL_DATABASE=
# MIGRATION_MYSQL_USERNAME=
# MIGRATION_MYSQL_PASSWORD=
# MIGRATION_BATCH_SIZE=1000                  # صفوف لكل دفعة (500..2000)
`````

### `.github/workflows/ci.yml`

`sha256: 77573a82397925b0f6c0d809da064feee9cfbf56f7412cdcddfcf108ef99214b`

`````yaml
# E25 - تحقّق CI صغير (اختياري). لا نشر، ولا أسرار، ولا بيانات اعتماد إنتاج. الغرض: كشف الكسر مبكرًا في كل PR/دفع.
# ما يفعله: تثبيت التبعيات من ملفات القفل، بناء الواجهة، فحص صياغة PHP، ثم مجموعات اختبار حرجة محددة (ليست المجموعة الكاملة كي يبقى الزمن معقولًا).
# ما لا يفعله عمدًا: لا deploy، لا migrate على بيئة حقيقية، لا composer/npm update، لا أسرار GitHub. تدقيق التبعيات (composer/npm audit) للإبلاغ فقط ولا يفشل البناء.
# توصية لاحقة (P2): تثبيت إجراءات GitHub على SHA كامل بدل الوسوم.
name: CI

on:
  pull_request:
  push:
    branches: [main]

permissions:
  contents: read

concurrency:
  group: ci-${{ github.ref }}
  cancel-in-progress: true

jobs:
  validate:
    runs-on: ubuntu-latest
    timeout-minutes: 25
    env:
      APP_ENV: testing
    steps:
      - uses: actions/checkout@v4

      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          extensions: mbstring, intl, bcmath, pdo_sqlite, sqlite3, gd, zip, fileinfo
          coverage: none
          tools: composer:v2

      - name: Composer cache
        uses: actions/cache@v4
        with:
          path: ~/.cache/composer
          key: composer-${{ hashFiles('composer.lock') }}
          restore-keys: composer-

      - name: Install PHP dependencies (من composer.lock)
        run: composer install --no-interaction --prefer-dist --no-progress

      - name: Prepare environment (بلا أسرار حقيقية)
        run: |
          cp .env.example .env
          php artisan key:generate --ansi
          touch database/testing.sqlite

      - uses: actions/setup-node@v4
        with:
          node-version: '20'
          cache: npm

      - name: Install & build frontend (من package-lock.json)
        run: |
          npm ci
          npm run build

      - name: PHP syntax check
        run: |
          errors=$(find app bootstrap config database routes tests -name '*.php' -print0 | xargs -0 -n1 -P4 php -l 2>&1 | grep -v '^No syntax errors detected' || true)
          if [ -n "$errors" ]; then echo "$errors"; exit 1; fi

      - name: Shell scripts syntax check
        run: |
          for f in tools/deploy/*.sh tools/backup/*.sh; do bash -n "$f"; done

      - name: Targeted regression suites (E25 + production + operations + E24 performance)
        run: |
          php artisan test --compact \
            tests/Feature/OperationsE25 \
            tests/Feature/Production \
            tests/Feature/Operations \
            tests/Feature/PerformanceE24

      - name: Composer audit (تقرير فقط)
        continue-on-error: true
        run: composer audit --no-interaction

      - name: npm audit (تقرير فقط)
        continue-on-error: true
        run: npm audit --audit-level=high

  # E31: تكافؤ MySQL. خدمة MySQL 8 مؤقتة بجانب المهمة (بيانات اعتماد اختبار فقط تعيش دقائق داخل الحاوية، ليست أسرارًا ولا تُستعمل خارجها).
  # يشغّل اختبارات E31 على MySQL الحقيقية + أهم مجموعات السلامة (محافظ/متجر/منافسة/بحث/مبدعون/رعاية). مهمة validate (SQLite) باقية كما هي.
  # لا نشر ولا migrate على بيئة حقيقية ولا composer/npm update.
  mysql-parity:
    runs-on: ubuntu-latest
    timeout-minutes: 45
    env:
      APP_ENV: testing
      DB_HOST: 127.0.0.1
      DB_PORT: 3306
      DB_DATABASE: ahjiyat_platform_test_mysql
      DB_USERNAME: root
      DB_PASSWORD: ci-only-test-password
    services:
      mysql:
        image: mysql:8.0
        env:
          MYSQL_ROOT_PASSWORD: ci-only-test-password
          MYSQL_DATABASE: ahjiyat_platform_test_mysql
        ports:
          - 3306:3306
        options: >-
          --health-cmd="mysqladmin ping -h 127.0.0.1 -uroot -pci-only-test-password"
          --health-interval=10s
          --health-timeout=5s
          --health-retries=12
    steps:
      - uses: actions/checkout@v4

      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          extensions: mbstring, intl, bcmath, pdo_sqlite, sqlite3, pdo_mysql, gd, zip, fileinfo
          coverage: none
          tools: composer:v2

      - name: Composer cache
        uses: actions/cache@v4
        with:
          path: ~/.cache/composer
          key: composer-${{ hashFiles('composer.lock') }}
          restore-keys: composer-

      - name: Install PHP dependencies (من composer.lock)
        run: composer install --no-interaction --prefer-dist --no-progress

      - name: Prepare environment (بلا أسرار حقيقية)
        run: |
          cp .env.example .env
          php artisan key:generate --ansi

      - uses: actions/setup-node@v4
        with:
          node-version: '20'
          cache: npm

      - name: Install & build frontend (من package-lock.json)
        run: |
          npm ci
          npm run build

      - name: Static MySQL compatibility (no live database needed)
        run: php artisan db:parity --static

      - name: E31 on MySQL (fresh migrate + parity + transfer + concurrency + domain integrity)
        run: php vendor/bin/pest --compact --configuration=phpunit.mysql.xml.dist tests/Feature/DatabaseE31 tests/Unit/TestDatabaseGuardTest.php

      - name: Critical integrity subsets on MySQL (wallet / store / competitive / search / creators / sponsorship)
        run: |
          php vendor/bin/pest --compact --configuration=phpunit.mysql.xml.dist \
            tests/Feature/Economy \
            tests/Feature/GemWalletServiceTest.php \
            tests/Feature/PuzzleAttemptServiceTest.php \
            tests/Feature/Store \
            tests/Feature/Competitive \
            tests/Feature/CompetitiveRewards \
            tests/Feature/DiscoveryE27 \
            tests/Feature/CreatorE28 \
            tests/Feature/SponsorshipE30 \
            tests/Feature/CreatorSeriesE30

      - name: Schema parity against the migrated MySQL database (read-only)
        run: php artisan db:parity --connection=mysql --runtime
`````

### `app/Policies/UserPolicy.php`

`sha256: b5a58c4c31ad9b8f79feea2ce6b5e327b34e3a03f8735f396d1476776e9d1015`

`````php
<?php

namespace App\Policies;

use App\Models\User;
use App\Services\AuthorizationSafetyService;
use App\Services\Database\FinancialHistoryGuard;

class UserPolicy
{
    public function __construct(protected AuthorizationSafetyService $safety) {}

    public function viewAny(User $user): bool
    {
        return $user->can('users.view');
    }

    public function view(User $user, User $model): bool
    {
        return $user->can('users.view');
    }

    public function create(User $user): bool
    {
        return $user->can('users.create');
    }

    public function update(User $user, User $model): bool
    {
        if (! $user->can('users.update')) {
            return false;
        }

        $this->safety->assertCanModifyUserAuthorization($user, $model);

        return true;
    }

    public function delete(User $user, User $model): bool
    {
        if (! app(AuthorizationSafetyService::class)->isSuperAdmin($user)) {
            return false;
        }

        if ($user->is($model)) {
            abort(403, 'لا يمكنك حذف حسابك الخاص أثناء تسجيل دخولك به.');
        }

        $this->safety->assertCanDeleteUser($model);

        // E31 (U3): ON DELETE CASCADE على جداول المال/المكافآت كان سيمسح تاريخ المستخدم المالي عرَضًا. من له تاريخ يُجمَّد ولا يُحذف.
        return ! app(FinancialHistoryGuard::class)->hasHistory($model);
    }

    public function freeze(User $user, User $model): bool
    {
        if (! $user->can('users.freeze')) {
            return false;
        }

        $this->safety->assertCanModifyUserAuthorization($user, $model);

        return ! $user->is($model);
    }

    public function unfreeze(User $user, User $model): bool
    {
        if (! $user->can('users.unfreeze')) {
            return false;
        }

        $this->safety->assertCanModifyUserAuthorization($user, $model);

        return true;
    }

    public function viewSecurity(User $user, User $model): bool
    {
        return $user->can('users.view_security');
    }

    public function viewWallet(User $user, User $model): bool
    {
        return $user->can('users.view_wallet');
    }

    public function viewActivity(User $user, User $model): bool
    {
        return $user->can('users.view_activity');
    }

    public function manageRoles(User $user, User $model): bool
    {
        if (! $user->can('users.manage_roles')) {
            return false;
        }

        $this->safety->assertCanModifyUserAuthorization($user, $model);

        return true;
    }
}
`````

### `app/Providers/AppServiceProvider.php`

`sha256: 2dc419058e509cfe330dbd8fa29e5f89c04cd91580cb63b2f6d665b2911b4106`

`````php
<?php

namespace App\Providers;

use App\Models\User;
use App\Services\AuthorizationSafetyService;
use App\Services\Teams\TeamService;
use App\Support\Security\Fingerprint;
use Illuminate\Cache\RateLimiting\Limit;
use App\Support\ReleaseInfo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // E27: محرك البحث. السائق غير المعروف يسقط إلى database (لا يعطّل الموقع). محرك خارجي مستقبلي = تنفيذ جديد لـSearchBackend وفرع هنا.
        $this->app->singleton(\App\Services\Search\Contracts\SearchBackend::class, fn ($app) => match ((string) config('search.driver', 'database')) {
            default => $app->make(\App\Services\Search\DatabaseSearchBackend::class),
        });

        // E31: أسماء الفهارس/المفاتيح المولَّدة تلقائيًا تُقتطع إلى 64 محرفًا على MySQL/MariaDB فقط (SQLite بلا تغيير). انظر MysqlSafeBlueprint.
        $this->app->bind(\Illuminate\Database\Schema\Blueprint::class, \App\Database\MysqlSafeBlueprint::class);
    }

    public function boot(): void
    {
        // E22: قائمة المحادثات الجانبية لصفحة الغرفة (قراءة فقط، بلا إنشاء غرف عند العرض).
        \Illuminate\Support\Facades\View::composer('chat.room', fn ($view) => $view->with('sidebar', app(\App\Services\Chat\ChatSidebar::class)->for(auth()->user())));

        // E19-E20: قبل حذف أي حساب يُعالَج ما يملكه من فرق (نقل الملكية لأقدم مشرف/عضو، وإلا أرشفة): لا فريق يتيم بلا مالك.
        // فشل المعالجة لا يمنع الحذف (owner_id يصير NULL بقيد القاعدة والفريق يبقى قابلًا لتدخل الإدارة).
        User::deleting(function (User $user) {
            try {
                app(TeamService::class)->releaseOwnershipFor($user);
            } catch (\Throwable $e) {
                report($e);
            }
        });

        // E29: قبل حذف أي حساب تُنقَص عدّادات ما تفاعل معه (مفضلة/متابعات) فتبقى المجمَّعات دقيقة؛ الصفوف نفسها تتتابع بقيد القاعدة، والتقييمات تبقى مجهولة الهوية.
        // فشل التنظيف لا يمنع الحذف؛ community:reconcile-engagement يصلح أي انحراف.
        User::deleting(function (User $user) {
            try {
                app(\App\Services\Engagement\EngagementCleanup::class)->purgeUser($user);
            } catch (\Throwable $e) {
                report($e);
            }
        });

        // Super Admin يتجاوز كل فحص can()/authorize() في التطبيق بالكامل -
        // Server-side حصراً. مصدر الفحص الوحيد AuthorizationSafetyService.
        Gate::before(function ($user, string $ability) {
            return app(AuthorizationSafetyService::class)->isSuperAdmin($user) ? true : null;
        });

        $this->registerRateLimiters();
        $this->registerOperationalGuards();
    }

    /**
     * E25: حواجز التشغيل بالإنتاج.
     *  - الأوامر الهدّامة (db:wipe, migrate:fresh|refresh|reset|rollback) ممنوعة بالإنتاج: الترحيلات بالإنتاج تتقدّم للأمام فقط (forward-fix) والتراجع عن القاعدة غير آمن بعد كتابات الإصدار الجديد.
     *    استثناء واعٍ ومؤقت بمتغير بيئة لتلك العملية وحدها: OPS_ALLOW_DESTRUCTIVE_COMMANDS=true (انظر docs/release-engineering.md). لا يُضاف إلى .env الدائم.
     *  - معرّف الإصدار (إن ضُبط) يُضاف لسياق كل سطر سجل (release) بجانب request_id من E24، لربط أي خطأ بالإصدار الذي أنتجه. لا أسرار فيه.
     */
    protected function registerOperationalGuards(): void
    {
        DB::prohibitDestructiveCommands($this->app->isProduction() && ! config('operations.allow_destructive_commands', false));

        if (($release = ReleaseInfo::short()) !== null) {
            Log::shareContext(['release' => $release]);
        }
    }

    /**
     * E8: بنية Rate Limiting مركزية واحدة - بدل أرقام مبعثرة (throttle:20,1)
     * بكل Route مباشرة. المفتاح: user ID للمصادَق عليهم (بند 26 - نفس سلوك
     * Laravel الافتراضي أصلاً بمجموعات auth/verified)، أو IP للضيوف حصراً
     * (تسجيل/استرجاع كلمة سر - لا يوجد مستخدم مصادَق عليه بعد أصلاً).
     */
    protected function registerRateLimiters(): void
    {
        // E8: لم تكن محمية إطلاقاً سابقاً (بند 21) - تسجيل حساب جديد.
        RateLimiter::for('registration', function (Request $request) {
            return Limit::perMinutes(10, 5)->by($request->ip());
        });

        // E8: لم تكن محمية إطلاقاً سابقاً (بند 22) - منع email flooding.
        // E26: حدّ ثانٍ **لكل مستلم** (بريد مُجزَّأ، لا بريد خام بمفتاح المحدِّد) كي لا يُغرِق مهاجمٌ بعناوين IP متعددة صندوق ضحية واحدة.
        RateLimiter::for('password-reset', function (Request $request) {
            return [
                Limit::perMinutes(10, 5)->by('pr-ip:'.$request->ip()),
                Limit::perHour(3)->by('pr-rcpt:'.Fingerprint::email($request->input('email'))),
            ];
        });

        // E26: تنفيذ إعادة التعيين نفسه (POST /reset-password) لم يكن محدودًا. الرمز عشوائي طويل، لكن لا سبب لقبول طرق غير محدودة.
        RateLimiter::for('password-reset-submit', function (Request $request) {
            return Limit::perMinutes(10, 10)->by('prs-ip:'.$request->ip());
        });

        // E26: تأكيد كلمة المرور (confirm-password) كان قابلًا للتخمين بلا حدّ داخل جلسة مسروقة. المفتاح المستخدم.
        RateLimiter::for('password-confirm', function (Request $request) {
            return Limit::perMinute(6)->by('pc:'.($request->user()?->id ?: $request->ip()));
        });

        // E26: تعديل بيانات الحساب الحسّاسة (الاسم/البريد/ظهور الملف) - أدنى بكثير من أي استخدام بشري.
        RateLimiter::for('account-sensitive', function (Request $request) {
            return Limit::perMinute(10)->by('acs:'.($request->user()?->id ?: $request->ip()));
        });

        // E26: أفعال لعب خفيفة كانت بلا حدّ (شراء تلميح، انضمام تحدٍّ، إكمال/تأمل خطوة حملة).
        RateLimiter::for('game-actions', function (Request $request) {
            return Limit::perMinute(30)->by('ga:'.($request->user()?->id ?: $request->ip()));
        });

        RateLimiter::for('puzzle-hint', function (Request $request) {
            return Limit::perMinute(10)->by('ph:'.($request->user()?->id ?: $request->ip()));
        });

        // E26: مصادقة قنوات البثّ الخاصة (/broadcasting/auth): اشتراك كل غرفة مرة واحدة عادةً؛ السقف يمنع استنزاف القاعدة باستعلامات التخويل.
        RateLimiter::for('broadcast-auth', function (Request $request) {
            return Limit::perMinute(90)->by('bca:'.($request->user()?->id ?: $request->ip()));
        });

        // E26: دخول API (إصدار رمز Sanctum) - حدّ لكل IP وحدّ لكل (بريد مُجزَّأ+IP). لا يتشارك مع محدّد دخول الويب.
        RateLimiter::for('api-login', function (Request $request) {
            return [
                Limit::perMinute(20)->by('apil-ip:'.$request->ip()),
                Limit::perMinutes(5, 5)->by('apil-acct:'.Fingerprint::email($request->input('email')).'|'.$request->ip()),
            ];
        });

        RateLimiter::for('email-verification', function (Request $request) {
            return Limit::perMinute(6)->by($request->user()?->id ?: $request->ip());
        });

        // أفعال اللعب (بند 24) - نفس الأرقام الحالية بالضبط، فقط مُسمّاة
        // ومركزية الآن. لا نخفّضها - قد تكسر لعبًا طبيعيًا سريعًا.
        RateLimiter::for('puzzle-attempt', function (Request $request) {
            return Limit::perMinute(20)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('game-session-start', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('game-session-reveal', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // E14 (بند 606): تحويل نقر الإعلان - عام (قد يشمل زائرًا غير مسجَّل)، معدَّل معقول يمنع إساءة الاستخدام بلا إزعاج تصفُّح عادي.
        RateLimiter::for('ad-click', function (Request $request) {
            return Limit::perMinute(30)->by($request->user()?->id ?: $request->ip());
        });

        // طلبات الاستبدال (بند 25) - نفس الحد الحالي: 5 كل ساعة.
        RateLimiter::for('redemption', function (Request $request) {
            return Limit::perMinutes(60, 5)->by($request->user()?->id ?: $request->ip());
        });

                // E10: مشتريات المتجر الافتراضي - مفتاح المستخدم (لا IP، دائماً
        // مُصادَق عليه بهذه المرحلة).
        RateLimiter::for('store-purchase', function (Request $request) {
            return Limit::perMinute(8)->by($request->user()?->id ?: $request->ip());
        });

        // E16: الأصدقاء. إرسال الطلبات بالدقيقة وبالساعة (مع cooldown إعادة الإرسال داخل FriendshipService)؛ بقية الإجراءات
        // والبحث أوسع. المفتاح المستخدم المصادَق (لا IP). لا يمنع الاستخدام الطبيعي.
        RateLimiter::for('friend-requests', function (Request $request) {
            $key = $request->user()?->id ?: $request->ip();

            return [Limit::perMinute(10)->by("friend-req-min:{$key}"), Limit::perHour(60)->by("friend-req-hour:{$key}")];
        });

        RateLimiter::for('friend-actions', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // E17: إنشاء تحدٍّ (حد ضيق: يولّد إشعارًا لشخص آخر)، تسجيل المنافسات، ولعب/إرسال نتيجة، وبقية إجراءات التحديات.
        RateLimiter::for('friend-challenges', function (Request $request) {
            $key = $request->user()?->id ?: $request->ip();

            return [Limit::perMinute(5)->by("fc-min:{$key}"), Limit::perHour(20)->by("fc-hour:{$key}")];
        });

        RateLimiter::for('competitive-register', function (Request $request) {
            return Limit::perMinute(20)->by($request->user()?->id ?: $request->ip());
        });

        // E19: الفرق. إنشاء نادر؛ الدعوات/الطلبات تحت حد يمنع الإزعاج؛ إجراءات الإدارة والقبول معتدلة.
        RateLimiter::for('team-create', fn (Request $request) => [Limit::perMinute(2)->by('tc:'.($request->user()?->id ?: $request->ip())), Limit::perHour(5)->by('tch:'.($request->user()?->id ?: $request->ip()))]);
        RateLimiter::for('team-invite', fn (Request $request) => [Limit::perMinute(10)->by('ti:'.($request->user()?->id ?: $request->ip())), Limit::perHour(40)->by('tih:'.($request->user()?->id ?: $request->ip()))]);
        RateLimiter::for('team-join', fn (Request $request) => [Limit::perMinute(8)->by('tj:'.($request->user()?->id ?: $request->ip())), Limit::perHour(30)->by('tjh:'.($request->user()?->id ?: $request->ip()))]);
        // E20: تحدّيات الفرق (الإنشاء نادر نسبيًا؛ القبول/الرفض/الإلغاء/الروستر معتدلة؛ اللعب بحد competitive-play القائم).
        RateLimiter::for('team-challenge-create', fn (Request $request) => [Limit::perMinute(4)->by('tcc:'.($request->user()?->id ?: $request->ip())), Limit::perHour(20)->by('tcch:'.($request->user()?->id ?: $request->ip()))]);
        RateLimiter::for('team-challenge-actions', fn (Request $request) => Limit::perMinute(30)->by('tca:'.($request->user()?->id ?: $request->ip())));
        // E21: دردشة. الإرسال حسب النوع (العامة أشد + حماية دفعات)، والبلاغ والتعديل/الحذف والقراءة بحدود منفصلة. المفتاح: المستخدم لا IP.
        RateLimiter::for('chat-dm-send', fn (Request $request) => Limit::perMinute(30)->by('cdm:'.($request->user()?->id ?: $request->ip())));
        RateLimiter::for('chat-team-send', fn (Request $request) => Limit::perMinute(30)->by('ctm:'.($request->user()?->id ?: $request->ip())));
        RateLimiter::for('chat-global-send', fn (Request $request) => [
            Limit::perMinute(10)->by('cgm:'.($request->user()?->id ?: $request->ip())),
            Limit::perSecond(3, 10)->by('cgb:'.($request->user()?->id ?: $request->ip())),     // دفعة: 3 رسائل/10 ثوانٍ
        ]);
        RateLimiter::for('chat-report', fn (Request $request) => [Limit::perMinute(5)->by('crm:'.($request->user()?->id ?: $request->ip())), Limit::perHour(20)->by('crh:'.($request->user()?->id ?: $request->ip()))]);
        RateLimiter::for('chat-edit', fn (Request $request) => Limit::perMinute(30)->by('ced:'.($request->user()?->id ?: $request->ip())));
        RateLimiter::for('chat-moderate', fn (Request $request) => Limit::perMinute(30)->by('cmo:'.($request->user()?->id ?: $request->ip())));
        RateLimiter::for('chat-read', fn (Request $request) => Limit::perMinute(120)->by('crd:'.($request->user()?->id ?: $request->ip())));
        RateLimiter::for('team-actions', fn (Request $request) => Limit::perMinute(40)->by('ta:'.($request->user()?->id ?: $request->ip())));

        RateLimiter::for('competitive-play', function (Request $request) {
            return Limit::perMinute(20)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('player-search', function (Request $request) {
            return Limit::perMinute(30)->by($request->user()?->id ?: $request->ip());
        });
                // E27: البحث العام. الاقتراحات الفورية أوسع (تُطلَق أثناء الكتابة بعد تأخير 220ms) لكن الضيف أضيق بكثير من العضو (مفتاح IP) كي لا يُستعمل مسحًا لأسماء اللاعبين.
        // صفحة البحث الكاملة أضيق من الاقتراحات. الاكتشاف (القراءة فقط) بحدّ واسع. المفتاح المستخدم المصادَق لا IP (يمنع التفاف مفتاح IP المشترك).
        RateLimiter::for('global-search', fn (Request $request) => $request->user()
            ? Limit::perMinute(60)->by('gs:u:'.$request->user()->id)
            : Limit::perMinute(20)->by('gs:ip:'.$request->ip()));
        RateLimiter::for('search-suggest', fn (Request $request) => $request->user()
            ? [Limit::perMinute(120)->by('ss:u:'.$request->user()->id), Limit::perSecond(16, 2)->by('ssb:u:'.$request->user()->id)]
            : [Limit::perMinute(40)->by('ss:ip:'.$request->ip()), Limit::perSecond(10, 2)->by('ssb:ip:'.$request->ip())]);
        RateLimiter::for('discover', fn (Request $request) => Limit::perMinute(90)->by('dc:'.($request->user()?->id ?: $request->ip())));

        // E28: المبدعون. كلها بمفتاح المستخدم لا IP. الطلب/الإنشاء/الإرسال (ويشمل السحب وإنشاء نسخة جديدة كي لا يُغرَق الإشراف بدورات إرسال/إلغاء) والبلاغات بحدّين دقيقة/ساعة.
        RateLimiter::for('creator-apply', fn (Request $request) => [Limit::perMinute(2)->by('cap:'.($request->user()?->id ?: $request->ip())), Limit::perHour(5)->by('caph:'.($request->user()?->id ?: $request->ip()))]);
        RateLimiter::for('creator-draft-create', fn (Request $request) => [Limit::perMinute(5)->by('cdc:'.($request->user()?->id ?: $request->ip())), Limit::perHour(30)->by('cdch:'.($request->user()?->id ?: $request->ip()))]);
        RateLimiter::for('creator-submit', fn (Request $request) => [Limit::perMinute(5)->by('csb:'.($request->user()?->id ?: $request->ip())), Limit::perHour(20)->by('csbh:'.($request->user()?->id ?: $request->ip()))]);
        RateLimiter::for('community-report', fn (Request $request) => [Limit::perMinute(5)->by('cpr:'.($request->user()?->id ?: $request->ip())), Limit::perHour(20)->by('cprh:'.($request->user()?->id ?: $request->ip()))]);

        // E29: التفاعل المجتمعي. بمفتاح المستخدم لا IP (كلها خلف auth). حدّان: دقيقة (سرعة بشرية) + ساعة (سقف إغراق/نمط آلي).
        // التقييم/الحفظ يسمحان بتغيير الرأي والتنقل بين أعمال كثيرة، فالحدّ أوسع من المتابعة والمجموعات (أثرهما أثقل على بيانات الآخرين).
        RateLimiter::for('community-rating', fn (Request $request) => [Limit::perMinute(20)->by('crt:'.($request->user()?->id ?: $request->ip())), Limit::perHour(200)->by('crth:'.($request->user()?->id ?: $request->ip()))]);
        RateLimiter::for('community-favorite', fn (Request $request) => [Limit::perMinute(30)->by('cfv:'.($request->user()?->id ?: $request->ip())), Limit::perHour(300)->by('cfvh:'.($request->user()?->id ?: $request->ip()))]);
        RateLimiter::for('creator-follow', fn (Request $request) => [Limit::perMinute(15)->by('cfl:'.($request->user()?->id ?: $request->ip())), Limit::perHour(100)->by('cflh:'.($request->user()?->id ?: $request->ip()))]);
        RateLimiter::for('collection-actions', fn (Request $request) => [Limit::perMinute(30)->by('cca:'.($request->user()?->id ?: $request->ip())), Limit::perHour(240)->by('ccah:'.($request->user()?->id ?: $request->ip()))]);

        // E30: قراءات الرعاة والسلاسل العامة (صفحات عرض فقط) بحدّ معتدل لكل مستخدم/عنوان؛ إجراءات استوديو السلاسل تُعيد استعمال محدّدات المبدعين (creator-draft-create / creator-submit).
        RateLimiter::for('supporters-read', fn (Request $request) => Limit::perMinute(120)->by('spr:'.($request->user()?->id ?: $request->ip())));
        RateLimiter::for('series-read', fn (Request $request) => Limit::perMinute(120)->by('ser:'.($request->user()?->id ?: $request->ip())));

        // E11: تجهيز/إزالة تجميليات - UX تتطلب تجربة سريعة (تبديل/معاينة)، لا حساسية مالية هنا.
        RateLimiter::for('cosmetic-equip', function (Request $request) {
            return Limit::perMinute(30)->by($request->user()?->id ?: $request->ip());
        });
        
        // E12: فجوة سابقة على هذه المرحلة - مجموعة Middleware الافتراضية 'api'
        // (المُطبَّقة تلقائيًا على كل مسار بـroutes/api.php) تتضمَّن throttle:api،
        // ولم يكن هذا الاسم مُعرَّفًا إطلاقًا (لم يُختَبر أي مسار API قبل الآن).
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

    }

}
`````

### `app/Services/Competitive/CompetitiveRunService.php`

`sha256: 353f9b4c43e259437bb32de82736889892578955843cf1aa300d543f578c9b87`

`````php
<?php

namespace App\Services\Competitive;

use App\GameEngine\GameTypeRegistry;
use App\GameEngine\Support\AttemptContext;
use App\GameEngine\Support\TimedAttemptToken;
use App\Models\GameSession;
use App\Models\Puzzle;
use App\Models\User;
use App\Services\Database\TransactionPolicy;
use Illuminate\Support\Facades\DB;

/**
 * اللعب التنافسي (تحدٍّ أو حدث) بمصدر حقيقة سيرفري كامل. جلسة = صف game_sessions بسياق تنافسي يربط (مستخدم، هدف، أحجية) بالسيرفر.
 *
 * عزل مطلق عن الاقتصاد: لا صفوف بـpuzzle_attempts ولا استدعاء لـPuzzleAttemptService (يمنح جواهر ويحدّث Challenge القديم ويستدعي التقدّم)
 * ولا لأي محفظة/XP/مهام/سلسلة. المتحقِّق نفسه (GameTypeRegistry) هو مصدر الصحة؛ والمدة بطوابع السيرفر (started_ms ثم الاستلام).
 *
 * محاولة واحدة: جلسة واحدة فقط لكل (مستخدم، سياق): الأقدم id هي الصالحة، وانتهاؤها نهائي. لا تُقبل قيمة من العميل غير الإجابة نفسها:
 * أي score/winner/rank/start_token/duration مُرسَل يُتجاهل (يُبنى payload المُتحقِّق من حالة السيرفر).
 */
class CompetitiveRunService
{
    public function __construct(
        protected GameTypeRegistry $games,
        protected CompetitiveScoringService $scoring,
        protected CompetitiveEligibility $eligibility,
    ) {}

    public function start(User $user, Puzzle $puzzle, AttemptContext $context, bool $requireActivePuzzle = true): GameSession
    {
        if (! $context->isCompetitive()) {
            throw new CompetitiveException('سياق غير تنافسي.');
        }

        if (($reason = $this->eligibility->reasonIfIneligible($puzzle, $requireActivePuzzle)) !== null) {
            throw new CompetitiveException($reason);
        }

        return DB::transaction(function () use ($user, $puzzle, $context) {
            $existing = $this->sessionsFor($user, $context)->lockForUpdate()->orderBy('id')->first();

            if ($existing !== null) {
                if ($existing->status === GameSession::STATUS_ACTIVE) {
                    return $existing; // استئناف نفس المحاولة (البدء الأصلي لا يتجدّد)
                }

                throw new CompetitiveException('أنهيت محاولتك الوحيدة هنا.');
            }

            $created = GameSession::create([
                'user_id' => $user->getKey(),
                'puzzle_id' => $puzzle->getKey(),
                ...$context->toAttributes(),
                'status' => GameSession::STATUS_ACTIVE,
                'started_at' => now(),
                'expires_at' => null,
                'server_state' => [
                    'competitive' => true,
                    'started_ms' => (int) now()->getPreciseTimestamp(3),
                    'start_token' => TimedAttemptToken::issue($puzzle, $user->getKey()),
                ],
            ]);

            // سباق بدءين متزامنين: يبقى الأقدم id وحده صالحًا.
            $first = $this->sessionsFor($user, $context)->orderBy('id')->first();

            if ($first->getKey() !== $created->getKey()) {
                $created->delete();

                return $first;
            }

            return $created;
        }, TransactionPolicy::DEADLOCK_ATTEMPTS); // E31 (P): على MySQL يتحوّل سباق البدء المتزامن إلى deadlock لا إلى إدراجين؛ الإعادة ترى الجلسة الأقدم فتستأنفها (idempotent).
    }

    /** الجلسة النشطة الصالحة لهذا المستخدم بهذا السياق (أو null). */
    public function activeSession(User $user, AttemptContext $context): ?GameSession
    {
        $first = $this->sessionsFor($user, $context)->orderBy('id')->first();

        return $first !== null && $first->status === GameSession::STATUS_ACTIVE ? $first : null;
    }

    /**
     * ينهي الجلسة ويحسب الناتج. يُستدعى داخل معاملة المستدعي (الذي يكتب النتيجة بقيد UNIQUE في المعاملة نفسها).
     *
     * @param  array<string, mixed>  $clientInput  الإجابة فقط: answer وsubmission (غيرهما يُتجاهل)
     */
    public function finish(User $user, GameSession $session, Puzzle $puzzle, AttemptContext $context, array $clientInput): CompetitiveOutcome
    {
        $locked = GameSession::query()->lockForUpdate()->find($session->getKey());
        $first = $locked === null ? null : $this->sessionsFor($user, $context)->orderBy('id')->first();

        // ملكية الجلسة مضمونة بالبناء: sessionsFor() مقيَّد بالمستخدم، فجلسة غيره لا يمكن أن تكون "الأقدم" لهذا المستخدم.
        if ($locked === null || $first?->getKey() !== $locked->getKey()
            || ! $locked->isCompetitive() || $locked->puzzle_id !== $puzzle->getKey()
            || $locked->context_type !== $context->type || $locked->context_id !== $context->id
            || $locked->status !== GameSession::STATUS_ACTIVE) {
            throw new CompetitiveException('لا توجد محاولة نشطة صالحة لك هنا.');
        }

        $state = (array) $locked->server_state;
        $nowMs = (int) now()->getPreciseTimestamp(3);
        $durationMs = max(0, $nowMs - (int) ($state['started_ms'] ?? $nowMs));
        $limit = $puzzle->time_limit_seconds;
        $timedOut = $limit !== null && $durationMs > $limit * 1000;

        $correct = ! $timedOut && $this->games->validatorFor($puzzle)->check($puzzle, $this->payload($user, $clientInput, $state))->correct;
        $score = $this->scoring->score($correct, $durationMs, $this->scoring->capMs($puzzle));

        $locked->update([
            'status' => GameSession::STATUS_COMPLETED,
            'completed_at' => now(),
            'server_state' => $state + ['finished_ms' => $nowMs, 'correct' => $correct, 'duration_ms' => $durationMs],
        ]);

        return new CompetitiveOutcome($correct, $durationMs, $score, $timedOut, $locked);
    }

    /** يُغلق جلسة نشطة بلا نتيجة (مهلة انتهت). */
    public function expire(GameSession $session): void
    {
        if ($session->isCompetitive() && $session->status === GameSession::STATUS_ACTIVE) {
            $session->update(['status' => GameSession::STATUS_EXPIRED, 'completed_at' => now()]);
        }
    }

    protected function sessionsFor(User $user, AttemptContext $context)
    {
        return GameSession::query()->where('user_id', $user->getKey())
            ->where('context_type', $context->type)->where('context_id', $context->id);
    }

    /** @return array<string, mixed> payload المُتحقِّق: إجابة العميل + مفاتيح السيرفر (الأخيرة تغلب دائمًا) */
    protected function payload(User $user, array $clientInput, array $state): array
    {
        $submission = $clientInput['submission'] ?? [];
        $submission = is_array($submission) ? $submission : [];
        unset($submission['start_token'], $submission['_context']);

        $base = $submission !== [] ? $submission : ['answer' => (string) ($clientInput['answer'] ?? '')];

        return $base + [
            'start_token' => (string) ($state['start_token'] ?? ''),
            '_context' => ['user_id' => $user->getKey()],
        ];
    }
}
`````

### `app/Services/Creators/CreatorApplicationService.php`

`sha256: fac63bed8f998e7e82376b32e6d38efa4e13a7aba3371a1812478017c08a0bd6`

`````php
<?php

namespace App\Services\Creators;

use App\Models\CreatorProfile;
use App\Models\User;
use App\Services\Database\TransactionPolicy;
use App\Services\OperationalAuditService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * E28 - دورة حياة ملف المبدع: تقديم → اعتماد/رفض، ثم تعليق/استعادة. كل انتقال: قفل الصف + فحص الحالة المتوقَّعة (لا انتقال مزدوج بتزامن) + تدقيق + مصالحة ظهور المنشورات.
 * الصلاحيات من RBAC الحالي (creators.review: اعتماد/رفض، creators.manage: تعليق/استعادة) وتُفحص هنا لا بالواجهة وحدها.
 * إشعار الاعتماد/الرفض بعد الـcommit ولا يؤثر فشله على الحالة.
 */
class CreatorApplicationService
{
    public function __construct(
        protected CreatorEligibility $eligibility,
        protected OperationalAuditService $audit,
        protected CreatorVisibilityService $visibility,
        protected CreatorNotifier $notifier,
    ) {}

    public function apply(User $user, string $bio): CreatorProfile
    {
        if (($denial = $this->eligibility->applyDenial($user)) !== null) {
            throw new CreatorException($denial);
        }

        $bio = $this->cleanBio($bio);

        try {
            $profile = DB::transaction(function () use ($user, $bio) {
                $existing = CreatorProfile::query()->where('user_id', $user->id)->lockForUpdate()->first();

                if ($existing !== null && ! $existing->isRejected()) {
                    throw new CreatorException('لديك طلب سابق لم يُغلق.');
                }

                $profile = $existing ?? new CreatorProfile;
                $profile->forceFill([
                    'user_id' => $user->id,
                    'slug' => $existing?->slug ?? $this->uniqueSlug($user),
                    'bio' => $bio,
                    'status' => CreatorProfile::STATUS_PENDING,
                    'submitted_at' => now(),
                    'reviewed_at' => null,
                    'reviewed_by' => null,
                    'review_note' => null,
                ])->save();

                return $profile;
            }, TransactionPolicy::DEADLOCK_ATTEMPTS); // E31 (P): طلبان متزامنان لنفس المستخدم: deadlock على MySQL؛ الإعادة ترى الطلب القائم فتُرفض بالرسالة الصحيحة.
        } catch (UniqueConstraintViolationException) {
            // طلبان متزامنان لنفس المستخدم: قيد user_id الفريد يحسمها؛ الخاسر يرى رسالة الطلب القائم.
            throw new CreatorException('لديك طلب قيد المراجعة بالفعل.');
        }

        $user->unsetRelation('creatorProfile');

        return $profile;
    }

    public function approve(CreatorProfile $profile, User $actor, ?string $note = null): CreatorProfile
    {
        $this->assertCan($actor, 'creators.review');

        $profile = $this->transition($profile, [CreatorProfile::STATUS_PENDING], CreatorProfile::STATUS_APPROVED, $actor, $note, 'creator_approved');
        $this->notifier->creatorApproved($profile);

        return $profile;
    }

    public function reject(CreatorProfile $profile, User $actor, string $note): CreatorProfile
    {
        $this->assertCan($actor, 'creators.review');
        $note = $this->requireNote($note);

        $profile = $this->transition($profile, [CreatorProfile::STATUS_PENDING], CreatorProfile::STATUS_REJECTED, $actor, $note, 'creator_rejected');
        $this->notifier->creatorRejected($profile);

        return $profile;
    }

    public function suspend(CreatorProfile $profile, User $actor, string $note): CreatorProfile
    {
        $this->assertCan($actor, 'creators.manage');
        $note = $this->requireNote($note);

        return $this->transition($profile, [CreatorProfile::STATUS_APPROVED], CreatorProfile::STATUS_SUSPENDED, $actor, $note, 'creator_suspended');
    }

    public function restore(CreatorProfile $profile, User $actor, ?string $note = null): CreatorProfile
    {
        $this->assertCan($actor, 'creators.manage');

        return $this->transition($profile, [CreatorProfile::STATUS_SUSPENDED], CreatorProfile::STATUS_APPROVED, $actor, $note, 'creator_restored');
    }

    /** @param list<string> $from */
    protected function transition(CreatorProfile $profile, array $from, string $to, User $actor, ?string $note, string $auditAction): CreatorProfile
    {
        $profile = DB::transaction(function () use ($profile, $from, $to, $actor, $note, $auditAction) {
            $locked = CreatorProfile::query()->lockForUpdate()->findOrFail($profile->id);

            if (! in_array($locked->status, $from, true)) {
                throw new CreatorException('لا يمكن تنفيذ هذا الإجراء على الحالة الحالية للمبدع.');
            }

            $previous = $locked->status;

            $locked->forceFill([
                'status' => $to,
                'reviewed_at' => now(),
                'reviewed_by' => $actor->id,
                'review_note' => filled($note) ? trim((string) $note) : null,
                'approved_at' => $to === CreatorProfile::STATUS_APPROVED ? ($locked->approved_at ?? now()) : $locked->approved_at,
            ])->save();

            $this->audit->log($auditAction, $locked, ['creator_user_id' => $locked->user_id, 'from' => $previous, 'to' => $to, 'note' => $locked->review_note], $actor);

            return $locked;
        });

        // المصالحة بعد الـcommit: تعليق/استعادة تُخفي/تُظهر منشورات المبدع. فشلها لا يُلغي الحالة (تعيدها أي مصالحة لاحقة).
        $this->visibility->reconcile($profile);

        return $profile->refresh();
    }

    protected function cleanBio(string $bio): string
    {
        $bio = trim(preg_replace('/\s+/u', ' ', $bio) ?? '');
        $min = (int) config('creators.limits.bio_min');
        $max = (int) config('creators.limits.bio_max');

        if (mb_strlen($bio) < $min || mb_strlen($bio) > $max) {
            throw new CreatorException("عرّف بنفسك وبسبب رغبتك بالانضمام ({$min}–{$max} حرفًا).");
        }

        return $bio;
    }

    protected function requireNote(string $note): string
    {
        $note = trim($note);
        $min = (int) config('creators.limits.note_min');
        $max = (int) config('creators.limits.note_max');

        if (mb_strlen($note) < $min || mb_strlen($note) > $max) {
            throw new CreatorException("اكتب سببًا واضحًا ({$min}–{$max} حرفًا).");
        }

        return $note;
    }

    protected function assertCan(User $actor, string $permission): void
    {
        abort_unless($actor->can($permission), 403);
    }

    /** معرّف رابط آمن ASCII فريد (الاسم العربي يُنقل حرفيًا لاتينيًا؛ الفارغ يصير creator). بلا بيانات خاصة: لا بريد ولا رقم داخلي. */
    protected function uniqueSlug(User $user): string
    {
        $base = Str::slug(Str::ascii((string) $user->name)) ?: 'creator';
        $base = Str::limit($base, 60, '');

        do {
            $slug = $base.'-'.Str::lower(Str::random(5));
        } while (CreatorProfile::query()->where('slug', $slug)->exists());

        return $slug;
    }
}
`````

### `app/Services/Economy/CurrencyWalletService.php`

`sha256: 4e8117133152d9c9265ca1879aa6e9ea26c6fe8ba5ee2e962a1745215fb761d3`

`````php
<?php

namespace App\Services\Economy;

use App\Models\Currency;
use App\Models\CurrencyTransaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * E9.1: خط الدفاع المركزي الوحيد لسلامة الاقتصاد - canEarn()/canSpend()
 * يُفرَضان هنا فقط، لا يُعتمَد على الطبقات الأعلى (PuzzleAttemptService...)
 * للتحقق. adjust()/refund() تبقيان Business Methods منفصلتين عمداً بلا هذا
 * الفحص - الإدارة يجب أن تقدر تُصحِّح رصيد عملة معطَّلة تشغيليًا.
 */
class CurrencyWalletService
{
    public function creditPending(
        User $user,
        Currency $currency,
        int $amount,
        string $reason,
        ?Model $reference = null,
        ?string $idempotencyKey = null,
    ): CurrencyTransaction {
        $this->assertPositiveAmount($amount);
        $this->assertCanEarn($currency);

        if ($idempotencyKey !== null && ($existing = $this->findByIdempotencyKey($idempotencyKey))) {
            $this->assertIdempotencyConsistent($existing, $user, $currency, $amount, CurrencyTransaction::TYPE_EARN_PENDING);

            return $existing;
        }

        return DB::transaction(function () use ($user, $currency, $amount, $reason, $reference, $idempotencyKey) {
            $wallet = $this->lockedWallet($user, $currency);

            $wallet->increment('pending_balance', $amount);
            $wallet->increment('lifetime_earned', $amount);

            return CurrencyTransaction::create([
                'user_id' => $user->id,
                'currency_id' => $currency->id,
                'amount' => $amount,
                'type' => CurrencyTransaction::TYPE_EARN_PENDING,
                'reason' => $reason,
                'reference_type' => $reference?->getMorphClass(),
                'reference_id' => $reference?->getKey(),
                'idempotency_key' => $idempotencyKey,
            ]);
        });
    }

    /**
     * E9.1 (بند 3): amount > 0 دائمًا (أُلزِمَ صراحة الآن). لا معاملة صفرية
     * إن لم يبقَ شيء معلَّق فعليًا - null بدل سجل وهمي بقيمة 0.
     */
    public function releasePending(User $user, Currency $currency, int $amount, string $reason): ?CurrencyTransaction
    {
        $this->assertPositiveAmount($amount);

        return DB::transaction(function () use ($user, $currency, $amount, $reason) {
            $wallet = $this->lockedWallet($user, $currency);

            $effectiveAmount = min($amount, $wallet->pending_balance);

            if ($effectiveAmount <= 0) {
                return null;
            }

            $wallet->decrement('pending_balance', $effectiveAmount);
            $wallet->increment('available_balance', $effectiveAmount);

            return CurrencyTransaction::create([
                'user_id' => $user->id,
                'currency_id' => $currency->id,
                'amount' => $effectiveAmount,
                'type' => CurrencyTransaction::TYPE_RELEASE_AVAILABLE,
                'reason' => $reason,
            ]);
        });
    }

    public function creditAvailable(
        User $user,
        Currency $currency,
        int $amount,
        string $reason,
        ?Model $reference = null,
        string $type = CurrencyTransaction::TYPE_ADMIN_ADJUSTMENT,
        ?string $idempotencyKey = null,
    ): CurrencyTransaction {
        $this->assertPositiveAmount($amount);

        if ($idempotencyKey !== null && ($existing = $this->findByIdempotencyKey($idempotencyKey))) {
            $this->assertIdempotencyConsistent($existing, $user, $currency, $amount, $type);

            return $existing;
        }

        return DB::transaction(function () use ($user, $currency, $amount, $reason, $reference, $type, $idempotencyKey) {
            $wallet = $this->lockedWallet($user, $currency);
            $wallet->increment('available_balance', $amount);
            $wallet->increment('lifetime_earned', $amount);

            return CurrencyTransaction::create([
                'user_id' => $user->id,
                'currency_id' => $currency->id,
                'amount' => $amount,
                'type' => $type,
                'reason' => $reason,
                'reference_type' => $reference?->getMorphClass(),
                'reference_id' => $reference?->getKey(),
                'idempotency_key' => $idempotencyKey,
            ]);
        });
    }

    public function debitAvailable(
        User $user,
        Currency $currency,
        int $amount,
        string $reason,
        ?Model $reference = null,
        string $type = CurrencyTransaction::TYPE_REDEEM,
    ): CurrencyTransaction {
        $this->assertPositiveAmount($amount);
        $this->assertCanSpend($currency);

        return DB::transaction(function () use ($user, $currency, $amount, $reason, $reference, $type) {
            $wallet = $this->lockedWallet($user, $currency);

            if ($wallet->available_balance < $amount) {
                throw new \RuntimeException('الرصيد المتاح غير كافٍ لإتمام هذه العملية.');
            }

            $wallet->decrement('available_balance', $amount);

            return CurrencyTransaction::create([
                'user_id' => $user->id,
                'currency_id' => $currency->id,
                'amount' => -$amount,
                'type' => $type,
                'reason' => $reason,
                'reference_type' => $reference?->getMorphClass(),
                'reference_id' => $reference?->getKey(),
            ]);
        });
    }

    /**
     * E9.1 (بند 1): عمداً بلا فحص canSpend/canEarn - إرجاع رصيد محجوز مسبقًا
     * (رفض طلب استبدال مثلاً) يجب أن ينجح دائمًا بغضّ النظر عن حالة العملة
     * الحالية، وإلا يُحتجَز رصيد المستخدم بلا رجعة إن عُطِّلت العملة بينهما.
     */
    public function refund(User $user, Currency $currency, int $amount, string $reason, ?Model $reference = null, string $type = CurrencyTransaction::TYPE_ADMIN_ADJUSTMENT): CurrencyTransaction
    {
        $this->assertPositiveAmount($amount);

        return DB::transaction(function () use ($user, $currency, $amount, $reason, $reference, $type) {
            $wallet = $this->lockedWallet($user, $currency);
            $wallet->increment('available_balance', $amount);

            return CurrencyTransaction::create([
                'user_id' => $user->id,
                'currency_id' => $currency->id,
                'amount' => $amount,
                'type' => $type,
                'reason' => $reason,
                'reference_type' => $reference?->getMorphClass(),
                'reference_id' => $reference?->getKey(),
            ]);
        });
    }

    /**
     * E9.1 (بند 1): تعديل الإدارة اليدوي - Business Method منفصلة تمامًا،
     * عمداً بلا فحص canEarn()/canSpend(). القرار موثَّق هنا صراحة: الإدارة
     * يجب أن تقدر تُصحِّح رصيد عملة معطَّلة/منتهية تشغيليًا (تعويض خطأ قبل
     * إعادة تفعيلها مثلاً) - هذا ليس Bypass ضمني بل تصميم مقصود.
     */
    public function adjust(User $user, Currency $currency, int $amount, string $reason): CurrencyTransaction
    {
        if ($amount === 0) {
            throw new \InvalidArgumentException('قيمة التعديل يجب ألا تساوي صفراً.');
        }

        return DB::transaction(function () use ($user, $currency, $amount, $reason) {
            $wallet = $this->lockedWallet($user, $currency);

            if ($amount < 0 && $wallet->available_balance + $amount < 0) {
                throw new \RuntimeException('لا يمكن إتمام هذا الخصم - سيجعل الرصيد المتاح سالباً.');
            }

            $wallet->increment('available_balance', $amount);

            return CurrencyTransaction::create([
                'user_id' => $user->id,
                'currency_id' => $currency->id,
                'amount' => $amount,
                'type' => CurrencyTransaction::TYPE_ADMIN_ADJUSTMENT,
                'reason' => $reason,
            ]);
        });
    }

    public function dailyEarnedToday(User $user, Currency $currency): int
    {
        return (int) CurrencyTransaction::where('user_id', $user->id)
            ->where('currency_id', $currency->id)
            ->where('type', CurrencyTransaction::TYPE_EARN_PENDING)
            // E31: نطاق نصف مفتوح بدل whereDate (DATE(created_at)=? على MySQL يمنع استعمال فهرس created_at ويفحص كل معاملات المستخدم عند كل حل). مكافئ دلاليًا (بتوقيت التطبيق).
            ->where('created_at', '>=', today())
            ->where('created_at', '<', today()->addDay())
            ->sum('amount');
    }

    public function balanceFor(User $user, Currency $currency): Wallet
    {
        return Wallet::query()->firstOrCreate(
            ['user_id' => $user->id, 'currency_id' => $currency->id],
            ['pending_balance' => 0, 'available_balance' => 0, 'lifetime_earned' => 0, 'lifetime_redeemed' => 0],
        );
    }

    protected function assertPositiveAmount(int $amount): void
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('قيمة العملية يجب أن تكون أكبر من صفر.');
        }
    }

    /** E9.1 (بند 1): خط الدفاع المركزي - لا كسب لعملة غير نشطة/غير قابلة للكسب/خارج نافذتها الزمنية/منتهية. */
    protected function assertCanEarn(Currency $currency): void
    {
        if (! $currency->canEarn()) {
            throw new \RuntimeException("لا يمكن كسب عملة \"{$currency->name}\" حاليًا (غير نشطة، أو غير قابلة للكسب، أو خارج نافذتها الزمنية، أو منتهية).");
        }
    }

    /** E9.1 (بند 1): خط الدفاع المركزي - لا صرف من عملة غير نشطة/غير قابلة للصرف/منتهية. */
    protected function assertCanSpend(Currency $currency): void
    {
        if (! $currency->canSpend()) {
            throw new \RuntimeException("لا يمكن صرف عملة \"{$currency->name}\" حاليًا (غير نشطة، أو غير قابلة للصرف، أو منتهية).");
        }
    }

    protected function findByIdempotencyKey(string $key): ?CurrencyTransaction
    {
        return CurrencyTransaction::where('idempotency_key', $key)->first();
    }

    /**
     * E9.1 (بند 5): لا نكتفي بإرجاع أي معاملة تحمل نفس المفتاح - نتحقق أنها
     * فعلاً نفس العملية (نفس مستخدم/عملة/قيمة مطلقة/نوع). إن اختلفت: هذا
     * تعارض حقيقي على مفتاح استُخدم لعمليتين مختلفتين تمامًا - Exception،
     * لا إرجاع صامت لنتيجة خاطئة.
     */
    protected function assertIdempotencyConsistent(CurrencyTransaction $existing, User $user, Currency $currency, int $amount, string $type): void
    {
        $matches = $existing->user_id === $user->id
            && $existing->currency_id === $currency->id
            && abs($existing->amount) === abs($amount)
            && $existing->type === $type;

        if (! $matches) {
            throw new \RuntimeException('مفتاح Idempotency هذا مُستخدَم مسبقًا لعملية مختلفة تمامًا - تعارض حقيقي.');
        }
    }

    /**
     * E26: قفل صف المحفظة داخل معاملة قائمة (SELECT ... FOR UPDATE). يستعمله المستدعي ليُسلسل «قراءة السقف اليومي ثم الإيداع»: بدونه تقرأ عدة طلبات متزامنة
     * (أحجيات مختلفة) المجموع نفسه قبل أي إيداع فتتجاوز كلها السقف اليومي معًا. يجب استدعاؤه داخل DB::transaction.
     */
    public function lockWallet(User $user, Currency $currency): Wallet
    {
        return $this->lockedWallet($user, $currency);
    }

    protected function lockedWallet(User $user, Currency $currency): Wallet
    {
        return Wallet::query()
            ->where('user_id', $user->id)
            ->where('currency_id', $currency->id)
            ->lockForUpdate()
            ->firstOrCreate([
                'user_id' => $user->id,
                'currency_id' => $currency->id,
            ]);
    }
}
`````

### `app/Services/GameSessionService.php`

`sha256: 9dac37991ffdce4f976dfa545123eb6d6780d6deb7ad47f632e1f653810de1ac`

`````php
<?php

namespace App\Services;

use App\GameEngine\Contracts\GameSessionHandler;
use App\GameEngine\GameTypeRegistry;
use App\GameEngine\Support\AttemptContext;
use App\Models\GameSession;
use App\Models\Puzzle;
use App\Models\PuzzleAttempt;
use App\Models\User;
use App\Services\Database\TransactionPolicy;
use Illuminate\Support\Facades\DB;

/**
 * الأورشستريتور الوحيد لدورة حياة أي جلسة لعبة Stateful - عام تمامًا، لا
 * يعرف شيئًا عن spot_difference بالاسم (يفوّض التفاعل الفعلي لِـ
 * GameSessionHandler الخاص بنوع اللعبة). كل عملية حسم (Finalize) تمر
 * بمعاملة واحدة مع قفل صف، وتُنتج محاولة PuzzleAttempt واحدة بالضبط عبر
 * PuzzleAttemptService الموجود فعلياً - لا تكرار لمنطق المكافأة هون إطلاقاً.
 */
class GameSessionService
{
    public function __construct(
        protected GameTypeRegistry $games,
        protected PuzzleAttemptService $attempts,
    ) {}

    /**
     * $context اختياري وسيرفري بحت - Standalone (الافتراضي) لا يتقاطع أبداً
     * مع أي سياق آخر (حملة/تحدٍّ راعٍ لاحقاً)، ولا العكس.
     */
    public function start(User $user, Puzzle $puzzle, ?AttemptContext $context = null): GameSession
    {
        $context ??= AttemptContext::none();

        // E28: جلسات اللعب مسار رسمي يمنح مكافآت وتأهيلًا؛ أحجيات المجتمع (غير المدعومة بالجلسات أصلًا) مرفوضة هنا كحاجز أخير.
        if ($puzzle->isCommunity()) {
            throw new \RuntimeException('هذه الأحجية غير متاحة عبر هذا المسار.');
        }

        $definition = $this->games->definitionFor($puzzle->game_type);

        if (! $definition instanceof GameSessionHandler) {
            throw new \RuntimeException('نوع هذه الأحجية لا يدعم الجلسات التفاعلية.');
        }

        if (! $puzzle->is_active) {
            throw new \RuntimeException('هذه الأحجية غير مُفعَّلة حالياً.');
        }

        return DB::transaction(function () use ($user, $puzzle, $definition, $context) {
            $existing = GameSession::where('user_id', $user->id)
                ->where('puzzle_id', $puzzle->id)
                ->where('context_type', $context->type)
                ->where('context_id', $context->id)
                ->where('status', GameSession::STATUS_ACTIVE)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if (! $existing->isExpired()) {
                    return $existing; // جلسة نشطة صالحة فعلاً بنفس السياق - نعيد استخدامها بدل التكرار
                }

                $this->finalize($existing);
            }

            if ($user->hasSolvedPuzzle($puzzle, $context)) {
                throw new \RuntimeException('سبق أن حللت هذه الأحجية.');
            }

            $attemptsUsed = PuzzleAttempt::where('user_id', $user->id)
                ->where('puzzle_id', $puzzle->id)
                ->where('context_type', $context->type)
                ->where('context_id', $context->id)
                ->count();

            if ($attemptsUsed >= $puzzle->max_attempts) {
                throw new \RuntimeException('استنفدت عدد المحاولات المسموح بها لهذه الأحجية.');
            }

            return GameSession::create([
                'user_id' => $user->id,
                'puzzle_id' => $puzzle->id,
                ...$context->toAttributes(),
                'status' => GameSession::STATUS_ACTIVE,
                'started_at' => now(),
                'expires_at' => $puzzle->time_limit_seconds
                    ? now()->addSeconds($puzzle->time_limit_seconds)
                    : null,
                'server_state' => $definition->initialServerState($puzzle),
            ]);
        }, TransactionPolicy::DEADLOCK_ATTEMPTS); // E31 (P): بدء متزامن (نقر مزدوج) = قفل نطاق فارغ ثم إدراج؛ الإعادة ترى الجلسة الأولى وتعيد استخدامها.
    }

    /**
     * @return array{hit: bool, found: int, required: int, completed: bool, correct: ?bool, gems_awarded: ?int, session_status: string}
     */
    public function reveal(GameSession $session, float $x, float $y): array
    {
        return DB::transaction(function () use ($session, $x, $y) {
            $locked = GameSession::whereKey($session->id)->lockForUpdate()->first();

            if ($locked->status !== GameSession::STATUS_ACTIVE) {
                return $this->staleResponse($locked);
            }

            if ($locked->isExpired()) {
                $this->finalize($locked);

                return $this->staleResponse($locked->fresh());
            }

            $puzzle = $locked->puzzle;
            $definition = $this->games->definitionFor($puzzle->game_type);

            if (! $definition instanceof GameSessionHandler) {
                throw new \RuntimeException('نوع هذه الأحجية لا يدعم الجلسات التفاعلية.');
            }

            $outcome = $definition->reveal($puzzle, $locked, $x, $y);

            $locked->update(['server_state' => ['found_indices' => $outcome['found_indices']]]);

            $response = [
                'hit' => $outcome['hit'],
                'found' => $outcome['found_count'],
                'required' => $outcome['required_count'],
                'completed' => false,
                'correct' => null,
                'gems_awarded' => null,
                'session_status' => $locked->status,
            ];

            if ($outcome['all_found']) {
                $finalized = $this->finalize($locked->fresh());
                $response['completed'] = true;
                $response['correct'] = $finalized['correct'];
                $response['gems_awarded'] = $finalized['gems_awarded'];
                $response['session_status'] = $locked->fresh()->status;
            }

            return $response;
        });
    }

    /**
     * الحسم النهائي - Idempotent بالكامل. يُستدعى إما تلقائياً من reveal()
     * عند اكتمال كل الفروق، أو عند اكتشاف انتهاء الوقت. لا يمنح مكافأة
     * مرتين مهما استُدعي، بفضل قفل الصف + فحص الحالة قبل أي تعديل.
     *
     * Invariant حقيقي: الجلسة لا تُختم "مكتملة" إلا إذا أكّد Game Type نفسه
     * (عبر isServerStateComplete) أن server_state تمثّل حلاً كاملاً صحيحاً -
     * بغض النظر عمّن استدعى finalize ولماذا.
     *
     * @return array{correct: bool, gems_awarded: int, already_finalized: bool}
     */
    public function finalize(GameSession $session): array
    {
        return DB::transaction(function () use ($session) {
            $locked = GameSession::whereKey($session->id)->lockForUpdate()->first();

            if ($locked->status !== GameSession::STATUS_ACTIVE) {
                $existingAttempt = PuzzleAttempt::where('game_session_id', $locked->id)->first();

                return [
                    'correct' => (bool) ($existingAttempt?->is_correct),
                    'gems_awarded' => 0,
                    'already_finalized' => true,
                ];
            }

            $puzzle = $locked->puzzle;
            $definition = $this->games->definitionFor($puzzle->game_type);

            $genuinelySolved = ! $locked->isExpired()
                && $definition instanceof GameSessionHandler
                && $definition->isServerStateComplete($puzzle, (array) $locked->server_state);

            $locked->update([
                'status' => $genuinelySolved ? GameSession::STATUS_COMPLETED : GameSession::STATUS_EXPIRED,
                'completed_at' => now(),
            ]);

            // Context يُستخرَج من الجلسة نفسها - أبداً لا يُفرَض none() قسراً
            // إذا كانت الجلسة تحمل سياقاً حقيقياً (حملة/تحدٍّ راعٍ لاحقاً).
            $context = $locked->context_type
                ? AttemptContext::for($locked->context_type, $locked->context_id)
                : AttemptContext::none();

            try {
                $result = $this->attempts->attempt(
                    $locked->user,
                    $puzzle,
                    '',
                    false,
                    ['server_state' => $locked->server_state],
                    $context,
                );
            } catch (\RuntimeException $e) {
                return ['correct' => false, 'gems_awarded' => 0, 'already_finalized' => false];
            }

            PuzzleAttempt::whereKey($result['attempt']->id)->update(['game_session_id' => $locked->id]);

            return [
                'correct' => $result['correct'],
                'gems_awarded' => $result['gems_awarded'],
                'already_finalized' => false,
            ];
        });
    }

    /** @return array{hit: bool, found: int, required: int, completed: bool, correct: ?bool, gems_awarded: ?int, session_status: string} */
    protected function staleResponse(GameSession $session): array
    {
        return [
            'hit' => false,
            'found' => count((array) ($session->server_state['found_indices'] ?? [])),
            'required' => 0,
            'completed' => in_array($session->status, [GameSession::STATUS_COMPLETED, GameSession::STATUS_EXPIRED], true),
            'correct' => null,
            'gems_awarded' => null,
            'session_status' => $session->status,
        ];
    }
}
`````

### `app/Services/Operations/ProductionChecker.php`

`sha256: 9c2bd87959a3291381edb9d72ab46a2d5ed030ab091be04acaa6c7736d3d46d8`

`````php
<?php

namespace App\Services\Operations;

use App\Services\Database\DatabaseFactsEvaluator;
use App\Services\Database\DatabaseRuntimeInspector;
use App\Support\ReleaseInfo;
use App\Support\SchedulerHeartbeat;
use Illuminate\Cache\NullStore;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * E25 - عقد بيئة الإنتاج. فحوصات قراءة فقط (لا كتابة قاعدة/كاش/تخزين، لا تنفيذ ترحيل، لا إعادة تشغيل) وسريعة.
 *
 * مبدأ الشدّة:
 *  - FAIL «حاجب» دائمًا (مهما كانت البيئة): APP_DEBUG مفعّل، APP_KEY مفقود/غير صالح، القاعدة لا تتصل، مجلدات الكتابة/الكاش/القرص غير قابلة للكتابة، أصول Vite غير مبنية، ملفات سجل الصلاحيات ناقصة، Redis مُعدّ ولا يتصل.
 *  - ما عدا ذلك يصير FAIL بالإنتاج فقط (APP_ENV=production) ويبقى WARN خارجه، كي لا تُكسَر بيئات التطوير والاختبار.
 *  - لا قيمة سرية تُطبع أبدًا: الرسائل تحمل أسماء الإعدادات فقط، ولا يدخل نص استثناء في أي رسالة. (والأوامر تمرّر الناتج على SecretRedactor كطبقة أمان ثانية.)
 */
final class ProductionChecker
{
    private CheckReport $report;

    private bool $production = false;

    /** يُحدَّد بخطوة القاعدة؛ تتجنّب الخطوات التالية تكرار FAIL حين تكون القاعدة نفسها معطّلة. */
    private bool $databaseReachable = true;

    public function __construct(
        private readonly SchedulerHeartbeat $heartbeat,
        private readonly MigrationState $migrations,
        private readonly QueueSignals $queue,
    ) {}

    public function run(?bool $production = null): CheckReport
    {
        $this->production = $production ?? (app()->environment('production') || config('app.env') === 'production');
        $this->report = new CheckReport($this->production);
        $this->databaseReachable = true;

        $steps = [
            'environment', 'appKey', 'release', 'database', 'migrations', 'cache', 'session', 'redis', 'queue', 'scheduler',
            'mail', 'broadcasting', 'security', 'logging', 'storage', 'build', 'optimization', 'permissions', 'accounts',
        ];

        foreach ($steps as $step) {
            try {
                $this->{$step}();
            } catch (Throwable) {
                // لا نكشف نص الاستثناء؛ فشل الفحص نفسه يعني أننا لا نستطيع الجزم بالسلامة.
                $this->fail('النظام', 'check:'.$step, 'تعذّر إكمال فحص ('.$step.') - راجع سجلات التطبيق');
            }
        }

        return $this->report;
    }

    // ───────────────────────────── البيئة ─────────────────────────────

    private function environment(): void
    {
        $g = 'البيئة';

        $this->production
            ? $this->pass($g, 'APP_ENV', 'APP_ENV = production')
            : $this->warn($g, 'APP_ENV', 'APP_ENV ليست production حالياً (بيئة: '.config('app.env').')');

        config('app.debug')
            ? $this->fail($g, 'APP_DEBUG', 'APP_DEBUG مفعَّلة - يجب أن تكون false بالإنتاج (تُظهر Stack Traces والإعدادات للعامة)')
            : $this->pass($g, 'APP_DEBUG', 'APP_DEBUG معطَّلة');

        $url = (string) config('app.url');
        $parts = parse_url($url) ?: [];
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));

        if ($host === '') {
            $this->prodFail($g, 'APP_URL', 'APP_URL غير صالح أو فارغ');
        } elseif ($scheme !== 'https') {
            $this->prodFail($g, 'APP_URL', 'APP_URL لا يبدأ بـhttps:// - بالإنتاج HTTPS فقط (روابط البريد والكوكيز تعتمد عليه)');
        } else {
            $this->pass($g, 'APP_URL', 'APP_URL يستخدم HTTPS');
        }

        if ($host !== '' && (in_array($host, ['localhost', '127.0.0.1', '::1', '0.0.0.0'], true) || str_ends_with($host, '.localhost') || str_ends_with($host, '.test') || str_ends_with($host, '.local'))) {
            $this->prodFail($g, 'APP_URL', 'APP_URL يشير إلى مضيف محلي - اضبطه على نطاق الإنتاج الفعلي');
        }
    }

    private function appKey(): void
    {
        $g = 'مفتاح التطبيق';
        $key = (string) config('app.key');

        if ($key === '') {
            $this->fail($g, 'APP_KEY', 'APP_KEY غير مضبوط - التطبيق لن يعمل بأمان (Session/Cookies/Encryption)');
        } elseif (! $this->keyIsValid($key, (string) config('app.cipher', 'AES-256-CBC'))) {
            $this->fail($g, 'APP_KEY', 'APP_KEY موجود لكن صيغته/طوله لا يطابق خوارزمية التشفير المضبوطة');
        } else {
            $this->pass($g, 'APP_KEY', 'APP_KEY موجود وصالح');
        }

        $previous = array_filter((array) config('app.previous_keys', []));
        if ($previous !== []) {
            $this->info($g, 'APP_PREVIOUS_KEYS', 'APP_PREVIOUS_KEYS مضبوط ('.count($previous).' مفتاح) - فترة تدوير المفتاح؛ أزله بعد انتهاء صلاحية الجلسات/البيانات المشفرة القديمة');
        }
    }

    private function keyIsValid(string $key, string $cipher): bool
    {
        $raw = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;

        return is_string($raw) && Encrypter::supported($raw, $cipher);
    }

    private function release(): void
    {
        $g = 'الإصدار';
        $configured = config('operations.release.id');

        if (ReleaseInfo::isConfigured()) {
            $this->pass($g, 'APP_RELEASE', 'معرّف الإصدار مضبوط ('.ReleaseInfo::short().')');
        } elseif (is_string($configured) && trim($configured) !== '') {
            $this->warn($g, 'APP_RELEASE', 'قيمة APP_RELEASE/RELEASE_SHA غير آمنة (المسموح [A-Za-z0-9._-] حتى 64 خانة) فتُتجاهل');
        } elseif ($this->production) {
            $this->warn($g, 'APP_RELEASE', 'APP_RELEASE غير مضبوط - اضبطه عند كل نشر (SHA أو وسم) كي يظهر بـ/ready والسجلات');
        } else {
            $this->info($g, 'APP_RELEASE', 'APP_RELEASE غير مضبوط (اختياري محليًا)');
        }
    }

    // ───────────────────────────── قاعدة البيانات والترحيلات ─────────────────────────────

    private function database(): void
    {
        $g = 'قاعدة البيانات';
        $name = (string) config('database.default');

        try {
            DB::connection()->getPdo();
            $this->pass($g, 'DB_CONNECTION', 'الاتصال بقاعدة البيانات ناجح ('.$name.')');
        } catch (Throwable) {
            $this->databaseReachable = false;
            $this->fail($g, 'DB_CONNECTION', 'تعذَّر الاتصال بقاعدة البيانات - راجع سجلات الخادم للتفاصيل (لن تُعرَض هنا)');

            return;
        }

        $driver = (string) config("database.connections.{$name}.driver");

        if ($driver === 'sqlite') {
            // E31: SQLite غير معتمدة للإنتاج في هذا الملف التشغيلي. التجاوز الواعي الوحيد: OPS_ALLOW_SQLITE_PRODUCTION=true (يبقى تحذيرًا ظاهرًا).
            if ((bool) config('operations.allow_sqlite_production', false)) {
                $this->warn($g, 'DB_CONNECTION', 'SQLite مسموحة صراحةً عبر OPS_ALLOW_SQLITE_PRODUCTION (تجاوز واعٍ) - غير موصى بها للإنتاج (كتابة متزامنة محدودة، لا نسخ نقطي)');
            } else {
                $this->prodFail($g, 'DB_CONNECTION', 'SQLite is not an approved production database for this deployment profile - SQLite غير معتمدة للإنتاج: استعمل MySQL 8.0.16+ (docs/mysql-production-e31.md)');
            }

            return;
        }

        // E31: حقائق الخادم الفعلية (نسخة/وضع صارم/ترميز/محرك) بلا كشف مضيف ولا بيانات اعتماد.
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            foreach (app(DatabaseFactsEvaluator::class)->evaluate(app(DatabaseRuntimeInspector::class)->inspect($name)) as $result) {
                match ($result['level']) {
                    'pass' => $this->pass($g, $result['key'], $result['message']),
                    'prodfail' => $this->prodFail($g, $result['key'], $result['message']),
                    'fail' => $this->fail($g, $result['key'], $result['message']),
                    default => $this->warn($g, $result['key'], $result['message']),
                };
            }
        }

        if ((string) config("database.connections.{$name}.password") === '') {
            $this->prodFail($g, 'DB_PASSWORD', 'DB_PASSWORD فارغة - لا تستعمل قاعدة بلا كلمة مرور بالإنتاج');
        }

        if (strtolower((string) config("database.connections.{$name}.username")) === 'root') {
            $this->warn($g, 'DB_USERNAME', 'DB_USERNAME=root - أنشئ مستخدمًا بصلاحيات التطبيق فقط (بلا DROP DATABASE/GRANT)');
        }
    }

    private function migrations(): void
    {
        $g = 'الترحيلات (فحص فقط - بلا تنفيذ)';

        if (! $this->databaseReachable) {
            $this->warn($g, 'migrations', 'تعذّر فحص الترحيلات لأن القاعدة غير متاحة (عطل القاعدة مُبلَّغ عنه أعلاه)');

            return;
        }

        $state = $this->migrations->inspect();

        if (! $state['repository']) {
            $this->fail($g, 'migrations', 'تعذَّر قراءة جدول migrations - لم تُهيَّأ القاعدة بعد؟ (php artisan migrate --force بعد نسخ احتياطي)');

            return;
        }

        $pending = count($state['pending']);

        if ($pending === 0) {
            $this->pass($g, 'migrations', "كل الترحيلات مُنفَّذة ({$state['ran']}/{$state['files']})");
        } else {
            $this->warn($g, 'migrations', "ترحيلات غير مُنفَّذة بعد ({$state['ran']}/{$state['files']}، المعلّق {$pending}) - شغّل php artisan migrate --force بعد نسخ احتياطي (انظر release:preflight)");
        }

        if ($state['orphans'] !== []) {
            $this->warn($g, 'migrations', 'القاعدة أحدث من الكود: '.count($state['orphans']).' ترحيل منفَّذ بلا ملف بالإصدار الحالي (كود أقدم فوق قاعدة أحدث) - لا تُجرِ rollback؛ اتبع «التراجع» في docs/release-engineering.md');
        }
    }

    // ───────────────────────────── الكاش والجلسات والـRedis ─────────────────────────────

    private function cache(): void
    {
        $g = 'الكاش';
        $driver = (string) config('cache.default');

        try {
            Cache::store()->get('ops:check:probe'); // قراءة فقط: يكشف عطل الاتصال دون أي كتابة
            $this->pass($g, 'CACHE_STORE', 'Cache Store ('.$driver.') يعمل فعلياً (قراءة)');
        } catch (Throwable) {
            $this->fail($g, 'CACHE_STORE', 'Cache Store لا يعمل - سيؤثر على التحليلات والإعدادات والصلاحيات والمجدول');

            return;
        }

        $store = Cache::getStore();

        if (! $store instanceof LockProvider) {
            $this->prodFail($g, 'CACHE_STORE', 'مخزن الكاش لا يدعم الأقفال - المجدول (withoutOverlapping/onOneServer) يحتاجها');
        } else {
            $this->pass($g, 'CACHE_STORE', 'مخزن الكاش يدعم الأقفال (يحتاجها المجدول)');
        }

        if ($driver === 'array' || $store instanceof NullStore) {
            $this->prodFail($g, 'CACHE_STORE', 'CACHE_STORE='.$driver.' لا يدوم بين الطلبات - استعمل redis أو database');
        } elseif ($driver === 'file' && $this->production) {
            $this->warn($g, 'CACHE_STORE', 'CACHE_STORE=file مقبول لخادم واحد فقط؛ تعدد الخوادم (onOneServer/الأقفال) يتطلب كاشًا مشتركًا: redis أو database');
        }
    }

    private function session(): void
    {
        $g = 'الجلسات والكوكيز';
        $driver = (string) config('session.driver');

        if (in_array($driver, ['array', 'null'], true)) {
            $this->prodFail($g, 'SESSION_DRIVER', 'SESSION_DRIVER='.$driver.' لا يحتفظ بالجلسات - استعمل database أو redis');
        } elseif ($driver === 'file' && $this->production) {
            $this->info($g, 'SESSION_DRIVER', 'SESSION_DRIVER=file مناسب لخادم واحد؛ تعدد الخوادم يتطلب database أو redis');
        } elseif ($driver === 'database') {
            match ($this->hasTable(config('session.connection'), (string) config('session.table', 'sessions'))) {
                true => $this->pass($g, 'SESSION_DRIVER', 'SESSION_DRIVER=database وجدول الجلسات موجود'),
                false => $this->fail($g, 'SESSION_DRIVER', 'SESSION_DRIVER=database لكن جدول الجلسات غير موجود - شغّل migrate'),
                null => $this->warn($g, 'SESSION_DRIVER', 'تعذّر التحقق من جدول الجلسات (القاعدة غير متاحة)'),
            };
        } else {
            $this->pass($g, 'SESSION_DRIVER', 'SESSION_DRIVER='.$driver);
        }

        if (config('session.secure') === true) {
            $this->pass($g, 'SESSION_SECURE_COOKIE', 'SESSION_SECURE_COOKIE=true');
        } elseif ($this->production) {
            $this->fail($g, 'SESSION_SECURE_COOKIE', 'SESSION_SECURE_COOKIE ليست true - كوكي الجلسة قد تُرسَل عبر HTTP بالإنتاج (اضبطها true)');
        } else {
            $this->info($g, 'SESSION_SECURE_COOKIE', 'SESSION_SECURE_COOKIE غير مفعّلة (مقبول محليًا؛ إلزامية بالإنتاج)');
        }

        config('session.http_only') === true
            ? $this->pass($g, 'SESSION_HTTP_ONLY', 'SESSION_HTTP_ONLY=true')
            : $this->prodFail($g, 'SESSION_HTTP_ONLY', 'SESSION_HTTP_ONLY ليست true - الكوكي مكشوفة لجافاسكربت (XSS)');

        $sameSite = strtolower((string) config('session.same_site'));
        in_array($sameSite, ['lax', 'strict'], true)
            ? $this->pass($g, 'SESSION_SAME_SITE', 'SESSION_SAME_SITE='.$sameSite)
            : $this->warn($g, 'SESSION_SAME_SITE', 'SESSION_SAME_SITE ليست lax/strict - تضعف الحماية من CSRF (اتركها lax ما لم يلزم غير ذلك)');
    }

    private function redis(): void
    {
        $g = 'Redis';
        $connections = $this->redisConnections();

        if ($connections === []) {
            $this->info($g, 'REDIS_HOST', 'Redis غير مستخدم حاليًا (الكاش/الجلسات/الطابور لا تعتمد عليه)');

            return;
        }

        foreach ($connections as $name) {
            try {
                Redis::connection($name)->ping();
                $this->pass($g, 'REDIS_HOST', "اتصال Redis ({$name}) ناجح");
            } catch (Throwable) {
                $this->fail($g, 'REDIS_HOST', "تعذَّر الاتصال بـRedis ({$name}) رغم أنه مُعدّ للاستعمال - راجع REDIS_HOST/REDIS_PORT/REDIS_PASSWORD (لن تُعرَض هنا)");
            }
        }

        $host = (string) config('database.redis.default.host');
        $local = in_array($host, ['127.0.0.1', 'localhost', '::1', ''], true);
        $password = config('database.redis.default.password');

        if (! $local && ($password === null || $password === '' || $password === 'null')) {
            $this->prodFail($g, 'REDIS_PASSWORD', 'Redis غير محلي ولا كلمة مرور له - اضبط REDIS_PASSWORD وقيّد الشبكة');
        }
    }

    /** @return list<string> */
    private function redisConnections(): array
    {
        $names = [];

        $store = (string) config('cache.default');
        if (config("cache.stores.{$store}.driver") === 'redis') {
            $names[] = (string) config("cache.stores.{$store}.connection", 'default');
        }

        if (config('session.driver') === 'redis') {
            $names[] = (string) (config('session.connection') ?: 'default');
        }

        $queue = (string) config('queue.default');
        if (config("queue.connections.{$queue}.driver") === 'redis') {
            $names[] = (string) config("queue.connections.{$queue}.connection", 'default');
        }

        if (config('reverb.servers.reverb.scaling.enabled')) {
            $names[] = 'default';
        }

        return array_values(array_unique($names));
    }

    // ───────────────────────────── الطوابير والمجدول ─────────────────────────────

    private function queue(): void
    {
        $g = 'الطوابير';
        $connection = (string) config('queue.default');
        $driver = (string) config("queue.connections.{$connection}.driver", $connection);

        $this->info($g, 'QUEUE_CONNECTION', "QUEUE_CONNECTION = {$connection}");

        if ($connection === 'sync') {
            $this->warn($g, 'QUEUE_CONNECTION', 'QUEUE_CONNECTION=sync - كل مهمة (بث/إشعارات) تُنفَّذ داخل الطلب نفسه بلا إعادة محاولة ولا حدود BoundedRetries؛ بالإنتاج استعمل database أو redis مع عامل queue:work');
        } elseif ($connection === 'null' || $driver === 'null') {
            $this->prodFail($g, 'QUEUE_CONNECTION', 'QUEUE_CONNECTION=null يُسقط المهام بصمت');
        } else {
            $this->pass($g, 'QUEUE_CONNECTION', 'مشغّل الطابور '.$driver.' مضبوط');
        }

        $failedDriver = (string) config('queue.failed.driver');
        if (in_array($failedDriver, ['database', 'database-uuids'], true)) {
            match ($this->hasTable(config('queue.failed.database'), (string) config('queue.failed.table', 'failed_jobs'))) {
                true => $this->pass($g, 'QUEUE_FAILED_DRIVER', 'جدول المهام الفاشلة موجود'),
                false => $this->fail($g, 'QUEUE_FAILED_DRIVER', 'جدول failed_jobs غير موجود - شغّل migrate'),
                null => $this->warn($g, 'QUEUE_FAILED_DRIVER', 'تعذّر التحقق من جدول المهام الفاشلة (القاعدة غير متاحة)'),
            };
        } else {
            $this->prodFail($g, 'QUEUE_FAILED_DRIVER', 'QUEUE_FAILED_DRIVER='.($failedDriver ?: 'null').' - المهام الفاشلة لن تُسجَّل ولن تُسترجَع');
        }

        if ($driver === 'database' || $driver === 'redis') {
            if ($driver === 'database') {
                match ($this->hasTable(config("queue.connections.{$connection}.connection"), (string) config("queue.connections.{$connection}.table", 'jobs'))) {
                    true => $this->pass($g, 'QUEUE_CONNECTION', 'جدول jobs موجود'),
                    false => $this->fail($g, 'QUEUE_CONNECTION', 'QUEUE_CONNECTION=database لكن جدول jobs غير موجود - شغّل migrate'),
                    null => $this->warn($g, 'QUEUE_CONNECTION', 'تعذّر التحقق من جدول jobs (القاعدة غير متاحة)'),
                };
            }

            $setting = $driver === 'database' ? 'DB_QUEUE_RETRY_AFTER' : 'REDIS_QUEUE_RETRY_AFTER';
            $retryAfter = (int) config("queue.connections.{$connection}.retry_after", 90);
            $timeout = (int) config('operations.queue.job_timeout_seconds', 80);

            $retryAfter > $timeout
                ? $this->pass($g, $setting, "retry_after ({$retryAfter}ث) أكبر من أقصى timeout للمهام ({$timeout}ث)")
                : $this->prodFail($g, $setting, "retry_after ({$retryAfter}ث) ليس أكبر من أقصى timeout للمهام ({$timeout}ث) - قد تُلتقَط مهمة ما زالت تعمل فتُنفَّذ مرتين");
        }

        $snapshot = $this->queue->snapshot();
        foreach ($this->queue->warnings($snapshot) as $warning) {
            $this->warn($g, 'queue', $warning);
        }
    }

    private function scheduler(): void
    {
        $g = 'المجدول';
        $status = $this->heartbeat->status();
        $age = $this->heartbeat->ageSeconds();

        match ($status) {
            SchedulerHeartbeat::OK => $this->pass($g, 'scheduler', "نبض المجدول حديث (منذ {$age}ث)"),
            SchedulerHeartbeat::STALE => $this->warn($g, 'scheduler', 'نبض المجدول متأخر (منذ '.intdiv((int) $age, 60).' د) - تحقق من cron: * * * * * php artisan schedule:run'),
            SchedulerHeartbeat::DOWN => $this->warn($g, 'scheduler', 'المجدول متوقف على الأرجح (آخر نبضة منذ '.intdiv((int) $age, 60).' د) - تحقق من cron/خدمة المجدول'),
            default => $this->production
                ? $this->warn($g, 'scheduler', 'لا نبضة مجدول مسجَّلة بعد - طبيعي مباشرة بعد أول نشر؛ تأكد من cron: * * * * * php artisan schedule:run ثم أعد الفحص بعد دقيقتين')
                : $this->info($g, 'scheduler', 'لا نبضة مجدول (المجدول لا يعمل محليًا عادةً)'),
        };

        if (config('performance.scheduler.one_server', true) && in_array(config('cache.default'), ['file', 'array'], true) && $this->production) {
            $this->warn($g, 'PERF_SCHEDULER_ONE_SERVER', 'onOneServer مفعّل لكن الكاش محلي لكل خادم - لن يمنع تشغيل المهمة على أكثر من خادم؛ استعمل كاشًا مشتركًا أو مجدولًا على خادم واحد');
        }
    }

    // ───────────────────────────── البريد والبث ─────────────────────────────

    private function mail(): void
    {
        $g = 'البريد الإلكتروني';
        $mailer = (string) config('mail.default');

        $this->info($g, 'MAIL_MAILER', "MAIL_MAILER = {$mailer}");

        if (in_array($mailer, ['log', 'array'], true)) {
            $this->prodFail($g, 'MAIL_MAILER', "MAIL_MAILER={$mailer} - لن يصل أي بريد فعلي (توثيق البريد/استرجاع كلمة السر مطلوبان لاستبدال الجواهر) - اضبط SMTP حقيقيًا");

            return;
        }

        $from = strtolower((string) config('mail.from.address'));

        if ($from === '') {
            $this->warn($g, 'MAIL_FROM_ADDRESS', 'MAIL_FROM_ADDRESS غير مضبوط');
        } elseif (str_ends_with($from, '@example.com')) {
            $this->prodFail($g, 'MAIL_FROM_ADDRESS', 'MAIL_FROM_ADDRESS ما زال القيمة الافتراضية (example.com) - اضبط عنوان نطاقك (SPF/DKIM)');
        } else {
            $this->pass($g, 'MAIL_FROM_ADDRESS', 'MAIL_FROM_ADDRESS مضبوط');
        }

        if ($mailer === 'smtp' && in_array(strtolower((string) config('mail.mailers.smtp.host')), ['127.0.0.1', 'localhost', ''], true)) {
            $this->warn($g, 'MAIL_HOST', 'MAIL_HOST محلي مع MAIL_MAILER=smtp - تأكد من وجود خادم بريد فعلي على الجهاز');
        }
    }

    private function broadcasting(): void
    {
        $g = 'البث الفوري (Reverb)';
        $default = (string) config('broadcasting.default');

        if ($default !== 'reverb') {
            $this->warn($g, 'BROADCAST_CONNECTION', "BROADCAST_CONNECTION={$default} - لا تحديث لحظي للدردشة والإشعارات (الحفظ يعمل بلا تغيير)");

            return;
        }

        $broadcast = (array) config('broadcasting.connections.reverb', []);
        $app = (array) config('reverb.apps.apps.0', []);
        $missing = array_filter(['REVERB_APP_ID' => $broadcast['app_id'] ?? null, 'REVERB_APP_KEY' => $broadcast['key'] ?? null, 'REVERB_APP_SECRET' => $broadcast['secret'] ?? null], fn ($v) => ! filled($v));

        if ($missing !== []) {
            $this->prodFail($g, implode('/', array_keys($missing)), 'بيانات Reverb ناقصة: '.implode(', ', array_keys($missing)));

            return;
        }

        $this->pass($g, 'REVERB_APP_ID', 'REVERB_APP_ID/KEY/SECRET مضبوطة');

        $secret = (string) ($broadcast['secret'] ?? '');
        if (strlen($secret) < 16 || in_array(strtolower($secret), ['secret', 'change-me', 'changeme', 'reverb-secret', 'password'], true)) {
            $this->prodFail($g, 'REVERB_APP_SECRET', 'REVERB_APP_SECRET قصير أو افتراضي - ولّد قيمة عشوائية (≥ 32 خانة)');
        }

        $scheme = (string) ($app['options']['scheme'] ?? config('reverb.apps.apps.0.options.scheme', ''));
        $scheme === 'https'
            ? $this->pass($g, 'REVERB_SCHEME', 'REVERB_SCHEME=https')
            : $this->prodFail($g, 'REVERB_SCHEME', 'REVERB_SCHEME ليست https - WebSocket غير مشفّر (wss مطلوب بالإنتاج)');

        $host = strtolower((string) config('reverb.servers.reverb.hostname'));
        if (in_array($host, ['', 'localhost', '127.0.0.1'], true)) {
            $this->prodFail($g, 'REVERB_HOST', 'REVERB_HOST محلي/فارغ - اضبط اسم النطاق العام لخادم Reverb');
        }

        $origins = (array) ($app['allowed_origins'] ?? ['*']);
        if (in_array('*', $origins, true)) {
            $this->warn($g, 'REVERB_ALLOWED_ORIGINS', 'REVERB_ALLOWED_ORIGINS تسمح بكل الأصول (*) - ضيّقها لنطاق موقعك (قنوات الدردشة الخاصة محمية بالمصادقة لكن التقييد أفضل)');
        } else {
            $this->pass($g, 'REVERB_ALLOWED_ORIGINS', 'REVERB_ALLOWED_ORIGINS مقيّدة ('.count($origins).' أصل)');
        }

        if (! config('reverb.servers.reverb.scaling.enabled') && $this->production) {
            $this->info($g, 'REVERB_SCALING_ENABLED', 'REVERB_SCALING_ENABLED=false - مناسب لخادم Reverb واحد؛ عدة خوادم تتطلب Redis وتفعيله (docs/scaling-architecture.md)');
        }
    }

    // ───────────────────────────── الأمان والسجلات ─────────────────────────────

    private function security(): void
    {
        $g = 'الأمان';
        $proxies = config('trustedproxy.proxies');
        $https = str_starts_with(strtolower((string) config('app.url')), 'https://');

        if ($proxies === '*') {
            $this->warn($g, 'TRUSTED_PROXIES', 'TRUSTED_PROXIES=* يثق بكل وكيل مباشر - آمن فقط إن كان التطبيق لا يُستقبَل إلا من الموازن (جدار ناري)؛ فضّل قائمة CIDR');
        } elseif (is_array($proxies) && $proxies !== []) {
            $this->pass($g, 'TRUSTED_PROXIES', 'TRUSTED_PROXIES مقيّدة ('.count($proxies).' عنوان/شبكة)');
        } elseif ($this->production && $https) {
            $this->warn($g, 'TRUSTED_PROXIES', 'TRUSTED_PROXIES غير مضبوط - خلف موازن/Cloudflare سيرى التطبيق عنوان الموازن بدل اللاعب (تتبع الجهاز، معدّلات IP، اكتشاف https)؛ اتركه فارغًا فقط إن استقبل الخادم المتصفح مباشرة');
        } else {
            $this->info($g, 'TRUSTED_PROXIES', 'TRUSTED_PROXIES غير مضبوط (سلوك افتراضي: لا وكلاء موثوقون)');
        }

        if (file_exists(public_path('.env')) || file_exists(public_path('.git'))) {
            $this->fail($g, 'public/', 'ملف .env أو مجلد .git داخل public/ - سيُخدَّم للعامة! أزله فورًا');
        }

        if (file_exists(public_path('hot'))) {
            $this->prodFail($g, 'VITE', 'ملف public/hot موجود (خادم Vite التطويري) - احذفه وابنِ الأصول: npm run build');
        }

        $this->checkEnvFilePermissions($g);

        if ($this->production) {
            foreach (['Laravel\\Telescope\\Telescope', 'Barryvdh\\Debugbar\\ServiceProvider', 'Spatie\\LaravelIgnition\\IgnitionServiceProvider'] as $class) {
                if (class_exists($class)) {
                    $this->warn($g, 'composer --no-dev', 'أداة تطوير/تشخيص مثبّتة ('.class_basename($class).') - ثبّت التبعيات بـcomposer install --no-dev وتأكد أنها معطلة بالإنتاج');
                }
            }

            if (class_exists('Pest\\TestSuite')) {
                $this->warn($g, 'composer --no-dev', 'تبعيات التطوير مثبّتة (Pest) - استعمل composer install --no-dev --prefer-dist --optimize-autoloader');
            }
        }

        $this->checkDiagnosticRoutes($g);
    }

    private function checkEnvFilePermissions(string $group): void
    {
        $env = base_path('.env');

        if (! $this->production || DIRECTORY_SEPARATOR !== '/' || ! is_file($env)) {
            return;
        }

        $perms = @fileperms($env);

        if ($perms !== false && ($perms & 0007) !== 0) {
            $this->warn($group, '.env', 'ملف .env قابل للقراءة من أي مستخدم على الخادم - قيّد الصلاحيات (chmod 640 ومالك التطبيق فقط)');
        } else {
            $this->pass($group, '.env', 'صلاحيات ملف .env مقيّدة (أو لا ملف: متغيرات بيئة العملية)');
        }
    }

    private function checkDiagnosticRoutes(string $group): void
    {
        $bad = [];

        foreach (Route::getRoutes() as $route) {
            if (preg_match('#^(_ignition|_debugbar|telescope|horizon|pulse|log-viewer|phpinfo|debug|tinker|adminer|phpmyadmin)(/|$)#i', ltrim($route->uri(), '/')) === 1) {
                $bad[] = ltrim($route->uri(), '/');
            }
        }

        $bad === []
            ? $this->pass($group, 'routes', 'لا مسارات تشخيص/إدارة عامة (ignition/telescope/horizon/debug…)')
            : $this->prodFail($group, 'routes', 'مسارات تشخيص عامة موجودة: '.implode(', ', array_slice(array_unique($bad), 0, 5)));
    }

    private function logging(): void
    {
        $g = 'السجلات';
        $default = (string) config('logging.default');
        $channels = $default === 'stack' ? (array) config('logging.channels.stack.channels', []) : [$default];

        if ($channels === []) {
            $this->warn($g, 'LOG_CHANNEL', 'لا قناة سجل فعّالة');

            return;
        }

        foreach ($channels as $channel) {
            $driver = (string) config("logging.channels.{$channel}.driver");
            $level = strtolower((string) config("logging.channels.{$channel}.level", 'debug'));

            if ($driver === 'single') {
                $this->production
                    ? $this->warn($g, 'LOG_STACK', 'قناة single تكتب ملفًا واحدًا بلا تدوير ولا حد حجم (قد يملأ القرص) - استعمل LOG_STACK=daily مع LOG_DAILY_DAYS أو logrotate')
                    : $this->info($g, 'LOG_STACK', 'قناة single (مقبول محليًا؛ بالإنتاج استعمل daily)');
            } elseif ($driver === 'daily') {
                $days = (int) config("logging.channels.{$channel}.days", 0);
                $days > 0
                    ? $this->pass($g, 'LOG_DAILY_DAYS', "قناة daily بتدوير يومي واحتفاظ {$days} يومًا")
                    : $this->warn($g, 'LOG_DAILY_DAYS', 'قناة daily بلا حد أيام الاحتفاظ - اضبط LOG_DAILY_DAYS');
            }

            if ($this->production && $level === 'debug' && in_array($driver, ['single', 'daily', 'stderr', 'errorlog', 'syslog'], true)) {
                $this->warn($g, 'LOG_LEVEL', 'LOG_LEVEL=debug بالإنتاج - ضجيج وحجم كبير وخطر تسجيل تفاصيل زائدة؛ استعمل info أو warning');
            }
        }
    }

    // ───────────────────────────── التخزين والأصول والتحسين ─────────────────────────────

    private function storage(): void
    {
        $g = 'التخزين';

        is_writable(storage_path())
            ? $this->pass($g, 'storage/', 'مجلد storage قابل للكتابة')
            : $this->fail($g, 'storage/', 'مجلد storage غير قابل للكتابة - راجع صلاحيات الملفات');

        is_writable(base_path('bootstrap/cache'))
            ? $this->pass($g, 'bootstrap/cache', 'مجلد bootstrap/cache قابل للكتابة')
            : $this->fail($g, 'bootstrap/cache', 'مجلد bootstrap/cache غير قابل للكتابة - راجع صلاحيات الملفات');

        if (File::exists(public_path('storage')) || is_link(public_path('storage'))) {
            $this->pass($g, 'public/storage', 'رابط public/storage موجود');
        } else {
            $this->warn($g, 'public/storage', 'رابط public/storage غير موجود - شغّل php artisan storage:link (لا تعتمد Windows Junction بالإنتاج)');
        }

        $disk = config('filesystems.disks.public', []);
        if (($disk['driver'] ?? 'local') === 'local') {
            $root = (string) ($disk['root'] ?? storage_path('app/public'));
            (is_dir($root) && is_writable($root))
                ? $this->pass($g, 'FILESYSTEM_DISK', 'قرص public قابل للكتابة (فحص صلاحيات فقط، بلا كتابة)')
                : $this->fail($g, 'FILESYSTEM_DISK', 'قرص public غير قابل للكتابة - سيفشل رفع أي ملف');
        } else {
            $this->info($g, 'FILESYSTEM_DISK', 'قرص public على مشغّل غير محلي - تحقق من بيانات الاعتماد خارج هذا الفحص');
        }

        $free = @disk_free_space(storage_path());
        $total = @disk_total_space(storage_path());

        if ($free !== false && $total !== false && $total > 0) {
            $pct = round($free / $total * 100, 1);
            $gb = round($free / 1024 ** 3, 1);

            if ($pct < 5 || $free < 500 * 1024 ** 2) {
                $this->prodFail($g, 'disk', "مساحة القرص حرجة: متبقٍ {$pct}% ({$gb} GB) - امتلاء القرص يوقف الكتابة والسجلات والجلسات");
            } elseif ($pct < 15) {
                $this->warn($g, 'disk', "مساحة القرص منخفضة: متبقٍ {$pct}% ({$gb} GB)");
            } else {
                $this->pass($g, 'disk', "مساحة القرص كافية: متبقٍ {$pct}% ({$gb} GB)");
            }
        }
    }

    private function build(): void
    {
        $g = 'أصول الواجهة (Vite)';

        File::exists(public_path('build/manifest.json'))
            ? $this->pass($g, 'public/build', 'public/build/manifest.json موجود - الأصول مبنية')
            : $this->fail($g, 'public/build', 'public/build/manifest.json غير موجود - شغّل npm ci && npm run build قبل النشر');
    }

    private function optimization(): void
    {
        if (! $this->production) {
            return;
        }

        $g = 'التحسين (optimize)';
        $app = app();

        $missing = array_keys(array_filter([
            'config' => ! $app->configurationIsCached(),
            'routes' => ! $app->routesAreCached(),
            'events' => ! $app->eventsAreCached(),
        ]));

        $missing === []
            ? $this->pass($g, 'optimize', 'كاش config/routes/events مبني')
            : $this->warn($g, 'optimize', 'كاش غير مبني: '.implode(', ', $missing).' - php artisan optimize (يحسّن زمن كل طلب)');
    }

    // ───────────────────────────── الصلاحيات والحسابات ─────────────────────────────

    private function permissions(): void
    {
        $g = 'سجل الصلاحيات';

        File::exists(config_path('permission.php'))
            ? $this->pass($g, 'config/permission.php', 'config/permission.php (حزمة Spatie) موجود')
            : $this->fail($g, 'config/permission.php', 'config/permission.php غير موجود - إعدادات Spatie الأساسية مفقودة');

        File::exists(config_path('permissions.php'))
            ? $this->pass($g, 'config/permissions.php', 'config/permissions.php (سجلّنا الخاص) موجود')
            : $this->fail($g, 'config/permissions.php', 'config/permissions.php غير موجود - permissions:sync لن يعمل');

        try {
            $inDb = DB::table('permissions')->count();
            $roles = DB::table('roles')->count();
        } catch (Throwable) {
            $this->prodFail($g, 'permissions', 'جداول الصلاحيات/الأدوار غير موجودة - شغّل migrate');

            return;
        }

        $registry = collect((array) config('permissions', []))->flatMap(fn ($module) => array_keys($module['permissions'] ?? []))->unique()->values();

        if ($inDb === 0 || $roles === 0) {
            $this->prodFail($g, 'permissions', 'لا صلاحيات/أدوار بالقاعدة - شغّل permissions:sync ثم db:seed --class="Database\\Seeders\\RolesAndPermissionsSeeder" --force');

            return;
        }

        $present = DB::table('permissions')->whereIn('name', $registry->all())->count();
        $present >= $registry->count()
            ? $this->pass($g, 'permissions', "كل صلاحيات السجل ({$registry->count()}) موجودة بالقاعدة")
            : $this->warn($g, 'permissions', ($registry->count() - $present).' صلاحية من السجل غير موجودة بالقاعدة - شغّل permissions:sync ثم RolesAndPermissionsSeeder');

        $superAdmins = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('roles.name', 'super-admin')
            ->count();

        $superAdmins > 0
            ? $this->pass($g, 'super-admin', 'يوجد مدير أعلى (super-admin) واحد على الأقل')
            : $this->warn($g, 'super-admin', 'لا مدير أعلى بعد - اتبع bootstrap الأول: docs/first-production-deploy.md (AdminUserSeeder ثم rbac:migrate-legacy-roles)');
    }

    private function accounts(): void
    {
        $g = 'حسابات الاختبار المعروفة';
        $suffix = (string) config('operations.known_test_accounts.email_suffix', '@ahjiyat.test');
        $defaultAdmin = (string) config('operations.known_test_accounts.default_admin_email', 'admin@ahjiyat.app');

        try {
            $testUsers = DB::table('users')->where('email', 'like', '%'.$suffix)->count();
            $admin = DB::table('users')->where('email', $defaultAdmin)->first(['password']);
        } catch (Throwable) {
            return; // لا جدول مستخدمين بعد: تغطيه فحوص الترحيلات
        }

        $testUsers === 0
            ? $this->pass($g, 'users', "لا حسابات {$suffix} (بيانات DemoQa/UserSeeder) بالقاعدة")
            : $this->prodFail($g, 'users', "{$testUsers} حساب تجريبي ({$suffix}) بكلمة مرور معروفة بالقاعدة - لا تشغّل db:seed الكامل بالإنتاج؛ احذف هذه الحسابات");

        if ($admin === null) {
            return;
        }

        foreach ((array) config('operations.known_test_accounts.default_admin_passwords', []) as $known) {
            try {
                if (Hash::check((string) $known, (string) $admin->password)) {
                    $this->prodFail($g, 'ADMIN_BOOTSTRAP_PASSWORD', 'حساب المدير الافتراضي ما زال بكلمة المرور الافتراضية المعروفة - غيّرها فورًا');

                    return;
                }
            } catch (Throwable) {
                // خوارزمية تجزئة مختلفة: ليست الافتراضية بالتأكيد
            }
        }

        $this->pass($g, 'ADMIN_BOOTSTRAP_PASSWORD', 'حساب المدير الافتراضي لا يستعمل كلمة المرور الافتراضية');
    }

    // ───────────────────────────── مساعدات ─────────────────────────────

    /** true/false وجود الجدول، أو null إن تعذّر الوصول للقاعدة (يُبلَّغ عنه بفحص القاعدة نفسه). */
    private function hasTable(?string $connection, string $table): ?bool
    {
        try {
            return Schema::connection($connection)->hasTable($table);
        } catch (Throwable) {
            return null;
        }
    }

    private function add(string $status, string $group, string $setting, string $message): void
    {
        $this->report->add(new CheckResult($status, $group, $setting, $message));
    }

    private function pass(string $group, string $setting, string $message): void
    {
        $this->add(CheckResult::PASS, $group, $setting, $message);
    }

    private function warn(string $group, string $setting, string $message): void
    {
        $this->add(CheckResult::WARN, $group, $setting, $message);
    }

    private function fail(string $group, string $setting, string $message): void
    {
        $this->add(CheckResult::FAIL, $group, $setting, $message);
    }

    private function info(string $group, string $setting, string $message): void
    {
        $this->add(CheckResult::INFO, $group, $setting, $message);
    }

    /** مخالفة تحجب بالإنتاج فقط وتبقى تحذيرًا خارجه. */
    private function prodFail(string $group, string $setting, string $message): void
    {
        $this->add($this->production ? CheckResult::FAIL : CheckResult::WARN, $group, $setting, $message);
    }
}
`````

### `app/Services/PuzzleAttemptService.php`

`sha256: 4bd6911a10232117689b52275899a77470056e0ba955a16071c301fb87e985ec`

`````php
<?php

namespace App\Services;

use App\GameEngine\GameResult;
use App\GameEngine\GameTypeRegistry;
use App\GameEngine\Support\AttemptContext;
use App\Models\Currency;
use App\Models\Puzzle;
use App\Models\PuzzleAttempt;
use App\Models\User;
use App\Services\Database\TransactionPolicy;
use App\Services\Economy\CurrencyRegistry;
use App\Services\Economy\CurrencyWalletService;
use App\Services\Progression\GameplayProgressionService;
use App\Services\Sponsorship\SponsorshipRewardService;
use Illuminate\Support\Facades\DB;

class PuzzleAttemptService
{
    public function __construct(
        protected CurrencyWalletService $wallets,
        protected CurrencyRegistry $currencies,
        protected FraudDetectionService $fraud,
        protected GameTypeRegistry $games,
        protected AttemptRewardResolver $rewards,
        protected GameplayProgressionService $progression,
    ) {}

    public function attempt(
        User $user,
        Puzzle $puzzle,
        string $submittedAnswer,
        bool $usedHint = false,
        array $submission = [],
        ?AttemptContext $context = null,
    ): array {
        $context ??= AttemptContext::none();

        // E28: أحجيات المجتمع بلا اقتصاد/تقدّم بالبناء: لعبها يمرّ حصرًا بـCommunityPlayService (جدول لعب منفصل). هذه الخدمة تمنح جواهر وXP وتغذّي المهام والسلسلة،
        // فنرفض أي أحجية مجتمع هنا مهما كان السياق (حملة/جلسة/تنافس) كحاجز أخير لا يعتمد على فلترة المنادي.
        if ($puzzle->isCommunity()) {
            throw new \RuntimeException('هذه الأحجية غير متاحة عبر هذا المسار.');
        }

        // E26: المسار العام المباشر (بلا سياق) كان يقبل محاولات ومكافآت على أحجية معطَّلة/مسودة/مسحوبة لمن يخمّن معرّفها. السياقات (حملة/جلسة/تنافسي) تفحص
        // التفعيل بخدماتها (CampaignPuzzleService/GameSessionService/CompetitiveEligibility) فلا نغيّر سلوك جلسة جارية بقرار إداري لاحق.
        if (! $context->isPresent() && ! $puzzle->is_active) {
            throw new \RuntimeException('هذه الأحجية غير متاحة حالياً.');
        }

        $result = DB::transaction(function () use ($user, $puzzle, $submittedAnswer, $usedHint, $submission, $context) {
            $previousAttempts = PuzzleAttempt::where('user_id', $user->id)
                ->where('puzzle_id', $puzzle->id)
                ->where('context_type', $context->type)
                ->where('context_id', $context->id)
                ->lockForUpdate()
                ->count();

            if ($previousAttempts >= $puzzle->max_attempts) {
                throw new \RuntimeException('استنفدت عدد المحاولات المسموح بها لهذه الأحجية.');
            }

            if ($user->hasSolvedPuzzle($puzzle, $context)) {
                throw new \RuntimeException('سبق أن حللت هذه الأحجية.');
            }

            $payload = $submission !== [] ? $submission : ['answer' => $submittedAnswer];

            $validatorPayload = $payload + ['_context' => ['user_id' => $user->id]];

            $gameResult = $this->games->validatorFor($puzzle)->check($puzzle, $validatorPayload);
            $isCorrect = $gameResult->correct;

            $puzzleAttempt = PuzzleAttempt::create([
                'user_id' => $user->id,
                'puzzle_id' => $puzzle->id,
                'attempt_number' => $previousAttempts + 1,
                'is_correct' => $isCorrect,
                'used_hint' => $usedHint,
                'submission_snapshot' => $payload,
                ...$context->toAttributes(),
            ]);

            $gemsAwarded = 0;
            $sponsorBonus = 0;

            if ($isCorrect) {
                ['base' => $gemsAwarded, 'bonus' => $sponsorBonus] = $this->awardGemsForSolve($user, $puzzle, $context, (int) $puzzleAttempt->id);
                $this->updateChallengeScoresIfAny($user, $puzzle);
            }

            return [
                'attempt' => $puzzleAttempt,
                'correct' => $isCorrect,
                'gems_awarded' => $gemsAwarded,
                // E30: مكافأة الرعاية الإضافية (معاملة منفصلة، غالبًا 0). المكافأة الأساسية أعلاه لا تتغير أبدًا.
                'sponsor_bonus' => $sponsorBonus,
                'attempts_left' => max(0, $puzzle->max_attempts - $puzzleAttempt->attempt_number),
            ];
        }, TransactionPolicy::DEADLOCK_ATTEMPTS); // E31 (P): قفل نطاق فارغ ثم إدراج ← deadlock محتمل على MySQL عند نقر مزدوج؛ المعاملة كلها كتابات DB فإعادتها آمنة.

        if ($result['correct']) {
            $this->progression->afterPuzzleSolved($user, $result['attempt'], $puzzle, $context);
        }

        return $result;
    }

    /** @return array{base: int, bonus: int} */
    protected function awardGemsForSolve(User $user, Puzzle $puzzle, AttemptContext $context, int $attemptId = 0): array
    {
        $directive = $this->rewards->resolve($puzzle, $context);

        $currency = $directive->currency
            ?? $puzzle->rewardCurrency
            ?? $this->currencies->defaultEarnedCurrency();

        $isDefaultCurrency = $currency->is($this->currencies->defaultEarnedCurrency());

        $rawReward = $directive->useDefault
            ? $this->games->scorerFor($puzzle)->calculate($puzzle, new GameResult(correct: true))
            : $directive->amount;

        if ($isDefaultCurrency) {
            // E26: تسلسل قراءة السقف + الإيداع بقفل صف المحفظة (نحن داخل معاملة attempt()): طلبات متزامنة لأحجيات مختلفة كانت تتجاوز السقف اليومي معًا.
            $this->wallets->lockWallet($user, $currency);

            $dailyCap = config('gems.daily_earn_cap');
            $alreadyEarnedToday = $this->wallets->dailyEarnedToday($user, $currency);
            $reward = min($rawReward, max(0, $dailyCap - $alreadyEarnedToday));
        } else {
            $reward = $rawReward;
        }

        if ($reward <= 0) {
            return ['base' => 0, 'bonus' => 0];
        }

        $this->wallets->creditPending($user, $currency, $reward, "solved_puzzle:{$puzzle->id}", $puzzle);

        // E30: مكافأة الرعاية (إن وُجد برنامج تعزيز نشط منطبق) عبر البوابة المركزية الوحيدة: معاملة منفصلة، رسمي فقط، ضمن سقف اليوم، وفشلها معزول (لا يمسّ الأساس).
        $bonus = app(SponsorshipRewardService::class)->applyToSolve($user, $puzzle, $context, $currency, $reward, $attemptId);

        $this->fraud->evaluateAfterEarn($user);

        return ['base' => $reward, 'bonus' => $bonus];
    }

    public function purchaseHint(User $user, Puzzle $puzzle): string
    {
        // E26: لا تلميحات (ولا خصم جواهر) لأحجية غير مفعّلة. E28: ولا لأحجية مجتمع (تلميحها مجاني عبر CommunityPlayService).
        if ($puzzle->isCommunity() || ! $puzzle->is_active) {
            throw new \RuntimeException('هذه الأحجية غير متاحة حالياً.');
        }

        if (blank($puzzle->hint)) {
            throw new \RuntimeException('لا يوجد تلميح متاح لهذه الأحجية.');
        }

        $this->wallets->debitAvailable(
            $user,
            $this->currencies->defaultEarnedCurrency(),
            (int) config('gems.hint_cost'),
            "hint:{$puzzle->id}",
            $puzzle,
        );

        return $puzzle->hint;
    }

    protected function updateChallengeScoresIfAny(User $user, Puzzle $puzzle): void
    {
        $openChallenges = $puzzle->challenges()
            ->where('is_active', true)
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>=', now())
            ->get();

        foreach ($openChallenges as $challenge) {
            $participant = $challenge->participants()->where('user_id', $user->id)->first();

            if ($participant) {
                $participant->increment('score');
            }
        }
    }
}
`````

### `app/Services/Store/StorePurchaseService.php`

`sha256: a4efa4811c92aa26de031c8ed9080576958788f350924ed158d7f957df886b6f`

`````php
<?php

namespace App\Services\Store;

use App\Models\CurrencyTransaction;
use App\Models\StoreItem;
use App\Models\StoreItemPrice;
use App\Models\StorePurchase;
use App\Models\User;
use App\Services\Economy\CurrencyWalletService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class StorePurchaseService
{
    public function __construct(
        protected CurrencyWalletService $wallets,
        protected InventoryService $inventory,
        protected EntitlementService $entitlements,
    ) {}

    public function purchase(User $user, StoreItem $item, StoreItemPrice $price, string $requestKey): StorePurchase
    {
        if ($existing = StorePurchase::where('request_key', $requestKey)->first()) {
            $this->assertRequestConsistent($existing, $user, $item, $price);

            return $existing;
        }

        try {
            return $this->purchaseLocked($user, $item, $price, $requestKey);
        } catch (UniqueConstraintViolationException $e) {
            // E31 (P3): طلبان متزامنان بالمفتاح نفسه (نقر مزدوج): يسلسلهما قفل صف المنتج، والثاني يصطدم بقيد request_key الفريد بعد التزام الأول.
            // الفحص الأول أعلاه سبق الالتزام فلم يره؛ نعيد الشراء القائم (idempotent) بدل خطأ 500، بعد تأكيد أنه الطلب نفسه. أي قيد فريد آخر يُعاد رميه.
            $existing = StorePurchase::where('request_key', $requestKey)->first();

            if ($existing === null) {
                throw $e;
            }

            $this->assertRequestConsistent($existing, $user, $item, $price);

            return $existing;
        }
    }

    protected function purchaseLocked(User $user, StoreItem $item, StoreItemPrice $price, string $requestKey): StorePurchase
    {
        return DB::transaction(function () use ($user, $item, $price, $requestKey) {
            $lockedItem = StoreItem::where('id', $item->id)->lockForUpdate()->first();

            $this->assertPurchasable($lockedItem, $price);
            $this->assertStockAvailable($lockedItem);
            $this->assertWithinPerUserLimit($lockedItem, $user);
            $this->assertNoDuplicateEntitlement($lockedItem, $user);

            $currency = $price->currency;

            $purchase = StorePurchase::create([
                'user_id' => $user->id,
                'store_item_id' => $lockedItem->id,
                'store_item_price_id' => $price->id,
                'currency_id' => $currency->id,
                'price_amount' => $price->amount,
                'status' => StorePurchase::STATUS_PENDING_FULFILLMENT,
                'request_key' => $requestKey,
                'item_snapshot' => [
                    'name' => $lockedItem->name,
                    'sku' => $lockedItem->sku,
                    'item_type' => $lockedItem->item_type,
                    'fulfillment_type' => $lockedItem->fulfillment_type,
                    'grant_quantity' => $lockedItem->grant_quantity,
                    'entitlement_key' => $lockedItem->entitlement_key,
                    'entitlement_duration_days' => $lockedItem->entitlement_duration_days,
                    'currency_code' => $currency->code,
                    'currency_name' => $currency->name,
                ],
                'fulfillment_type' => $lockedItem->fulfillment_type,
            ]);

            $this->wallets->debitAvailable(
                $user,
                $currency,
                $price->amount,
                "store_purchase:{$lockedItem->sku}",
                $purchase,
                CurrencyTransaction::TYPE_SPEND,
            );

            $this->fulfillAutomatically($purchase, $lockedItem, $user);

            return $purchase->fresh();
        });
    }

    protected function fulfillAutomatically(StorePurchase $purchase, StoreItem $item, User $user): void
    {
        match ($item->fulfillment_type) {
            StoreItem::FULFILLMENT_INVENTORY => $this->fulfillInventory($purchase, $item, $user),
            StoreItem::FULFILLMENT_ENTITLEMENT => $this->fulfillEntitlement($purchase, $item, $user),
            default => null,
        };
    }

    protected function fulfillInventory(StorePurchase $purchase, StoreItem $item, User $user): void
    {
        $this->inventory->grant($user, $item, $item->grant_quantity, "store_purchase:{$purchase->id}", $purchase);
        $purchase->update(['status' => StorePurchase::STATUS_FULFILLED, 'fulfilled_at' => now()]);
    }

    protected function fulfillEntitlement(StorePurchase $purchase, StoreItem $item, User $user): void
    {
        $this->entitlements->grant($user, $item, $purchase);
        $purchase->update(['status' => StorePurchase::STATUS_FULFILLED, 'fulfilled_at' => now()]);
    }

    public function fulfillManually(StorePurchase $purchase, User $admin, ?string $adminNote = null, ?string $userMessage = null): StorePurchase
    {
        return DB::transaction(function () use ($purchase, $admin, $adminNote, $userMessage) {
            $locked = StorePurchase::where('id', $purchase->id)->lockForUpdate()->first();

            if ($locked->status !== StorePurchase::STATUS_PENDING_FULFILLMENT) {
                throw new \RuntimeException('لا يمكن إنجاز هذا الطلب - حالته الحالية لا تسمح بذلك.');
            }

            $locked->update([
                'status' => StorePurchase::STATUS_FULFILLED,
                'fulfilled_by' => $admin->id,
                'fulfilled_at' => now(),
                'admin_note' => $adminNote,
                'user_message' => $userMessage,
            ]);

            return $locked->fresh();
        });
    }

    public function refund(StorePurchase $purchase, User $admin, string $reason): StorePurchase
    {
        return DB::transaction(function () use ($purchase, $admin, $reason) {
            $locked = StorePurchase::where('id', $purchase->id)->lockForUpdate()->first();

            if (! $locked->isRefundable()) {
                throw new \RuntimeException('لا يمكن استرجاع هذا الطلب - إما مُسترجَع مسبقاً، أو مُسلَّم يدوياً بالفعل ويتطلب حلاً إدارياً مباشراً.');
            }

            $this->wallets->refund(
                $locked->user,
                $locked->currency,
                $locked->price_amount,
                "store_refund:{$locked->id}",
                $locked,
                CurrencyTransaction::TYPE_REFUND,
            );

            $this->reverseFulfillment($locked, $admin, $reason);

            $locked->update([
                'status' => StorePurchase::STATUS_REFUNDED,
                'refunded_by' => $admin->id,
                'refunded_at' => now(),
                'admin_note' => $reason,
            ]);

            return $locked->fresh();
        });
    }

    protected function reverseFulfillment(StorePurchase $purchase, User $admin, string $reason): void
    {
        if ($purchase->fulfillment_type === StoreItem::FULFILLMENT_INVENTORY) {
            $grantQuantity = $purchase->item_snapshot['grant_quantity'] ?? 1;
            $this->inventory->revoke($purchase->user, $purchase->item, $grantQuantity, "store_refund:{$purchase->id}", $purchase);
        }

        if ($purchase->fulfillment_type === StoreItem::FULFILLMENT_ENTITLEMENT) {
            $entitlement = $purchase->entitlement;

            if ($entitlement) {
                $this->entitlements->revoke($entitlement, $admin, "استرجاع شراء #{$purchase->id}: {$reason}");
            }
        }
    }

    protected function assertPurchasable(StoreItem $item, StoreItemPrice $price): void
    {
        if (! $item->isPurchasable()) {
            throw new \RuntimeException('العنصر غير متاح للشراء حالياً.');
        }

        if ($price->store_item_id !== $item->id) {
            throw new \RuntimeException('خيار السعر هذا لا ينتمي لهذا العنصر.');
        }

        if (! $price->is_active) {
            throw new \RuntimeException('خيار السعر هذا لم يعد فعالاً.');
        }
    }

    protected function assertStockAvailable(StoreItem $item): void
    {
        if ($item->isSoldOut()) {
            throw new \RuntimeException('نفدت الكمية المتاحة من هذا العنصر.');
        }
    }

    protected function assertWithinPerUserLimit(StoreItem $item, User $user): void
    {
        if ($item->per_user_limit === null) {
            return;
        }

        $existingCount = StorePurchase::where('user_id', $user->id)
            ->where('store_item_id', $item->id)
            ->whereIn('status', [StorePurchase::STATUS_PENDING_FULFILLMENT, StorePurchase::STATUS_FULFILLED])
            ->count();

        if ($existingCount >= $item->per_user_limit) {
            throw new \RuntimeException('وصلت للحد المسموح به من عمليات الشراء لهذا العنصر.');
        }
    }

    protected function assertNoDuplicateEntitlement(StoreItem $item, User $user): void
    {
        if ($item->fulfillment_type !== StoreItem::FULFILLMENT_ENTITLEMENT || blank($item->entitlement_key)) {
            return;
        }

        if ($this->entitlements->hasActive($user, $item->entitlement_key)) {
            throw new \RuntimeException('تمتلك هذا الامتياز بالفعل.');
        }
    }

    protected function assertRequestConsistent(StorePurchase $existing, User $user, StoreItem $item, StoreItemPrice $price): void
    {
        $matches = $existing->user_id === $user->id
            && $existing->store_item_id === $item->id
            && $existing->store_item_price_id === $price->id;

        if (! $matches) {
            throw new \RuntimeException('مفتاح الطلب هذا مُستخدَم مسبقًا لعملية شراء مختلفة تمامًا - تعارض حقيقي.');
        }
    }
}
`````

### `app/Services/Teams/TeamCompetitiveRankingService.php`

`sha256: b6df4af9ef47ceadf8f9fa0aa719317219799c203b41c4c908872acc784096a6`

`````php
<?php

namespace App\Services\Teams;

use App\Models\CompetitiveEvent;
use App\Models\CompetitiveEventResult;
use App\Models\CompetitiveEventTeamResult;
use App\Models\Team;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ترتيب الفرق بالأحداث التنافسية (E19-D). **لا يغيّر شيئًا من منافسة الأفراد**: لا نقاط لاعب، ولا مراكزه، ولا جوائز E18. مصدر الحقيقة: نتائج **صحيحة** بأحداث **معتمَدة**.
 *
 * - **الانتماء = لقطة الفريق عند التسجيل** (competitive_event_participants.team_id_snapshot)، **لا العضوية الحالية** أبدًا: انتقال لاعب أو مغادرته لا ينقل نتيجة تاريخية.
 *   من سجّل قبل E19 أو بلا فريق: لقطته NULL فلا يدخل أي فريق (لا ربط تخميني).
 * - **الصيغة:** نقاط الفريق = مجموع **أفضل N نتيجة صحيحة** لأعضائه بالحدث (N = teams.ranking_top_n = 3). درجات E17 قابلة للجمع عدلًا (الأحجية والسقف الزمني واحد
 *   للجميع). السقف N يمنع أفضلية الحجم: فريق بعشرين عضوًا لا يتفوق بكثرة الأعضاء. (فريق بأقل من N مشاركين يُجمع له ما لديه: ميزته الحجم الأصغر أن يلعب أقل، لا أكثر.)
 * - **كسر التعادل الثابت:** نقاط أعلى ← مجموع مدد أقل (للنتائج المحتسَبة) ← أعضاء محتسَبون أكثر ← معرّف الفريق الأصغر. الحتمية: المدخلات نفسها ← الترتيب نفسه.
 * - **الثبات التاريخي:** يُخزَّن الترتيب عند الاعتماد مرة واحدة (UNIQUE) فلا يتغير بتعطيل فريق أو انتقال لاعبين لاحقًا. الفرق **المفعَّلة عند الاعتماد** فقط تدخل.
 * - بلا أي اقتصاد: لا عملة ولا XP ولا مكافأة للفرق.
 */
class TeamCompetitiveRankingService
{
    /**
     * يحسب الترتيب من النتائج الحالية (حتمي، بلا كتابة).
     *
     * @return list<array{team_id: int, score: int, counted: int, duration: int, rank: int}>
     */
    public function compute(CompetitiveEvent $event): array
    {
        // مُتدفَّق (cursor) مرتّب بالفريق ثم الأفضل: الذاكرة O(عدد الفرق) لا O(المشاركين).
        $rows = CompetitiveEventResult::query()
            ->join('competitive_event_participants as p', fn ($j) => $j->on('p.competitive_event_id', '=', 'competitive_event_results.competitive_event_id')->on('p.user_id', '=', 'competitive_event_results.user_id'))
            ->join('teams as t', 't.id', '=', 'p.team_id_snapshot')
            ->where('competitive_event_results.competitive_event_id', $event->getKey())
            ->where('competitive_event_results.is_correct', true)
            ->where('t.is_active', true)
            ->orderBy('p.team_id_snapshot')->orderByDesc('competitive_event_results.score')->orderBy('competitive_event_results.duration_ms')
            ->orderBy('competitive_event_results.completed_at')->orderBy('competitive_event_results.id')
            ->select('p.team_id_snapshot as team_id', 'competitive_event_results.score as score', 'competitive_event_results.duration_ms as duration')
            ->cursor();

        // الصيغة المشتركة (E19 + E20): TeamScoreCalculator هو المصدر الوحيد لمجموع أفضل N وترتيبه.
        $calc = app(TeamScoreCalculator::class);

        return $calc->ranked($calc->aggregate($rows));
    }

    /** يخزّن الترتيب النهائي لحدث معتمَد مرة واحدة (Idempotent، آمن للتوازي). @return int عدد الفرق المخزَّنة (0 إن سبق أو لا فرق) */
    public function finalize(CompetitiveEvent $event): int
    {
        if ($event->status !== CompetitiveEvent::STATUS_COMPLETED) {
            return 0;
        }

        return DB::transaction(function () use ($event) {
            $locked = CompetitiveEvent::query()->lockForUpdate()->find($event->getKey());

            if ($locked === null || $locked->team_rankings_finalized_at !== null) {
                return 0;
            }

            $rows = $this->compute($locked);

            foreach ($rows as $row) {
                DB::table('competitive_event_team_results')->insertOrIgnore([[
                    'competitive_event_id' => $locked->getKey(), 'team_id' => $row['team_id'], 'score' => $row['score'],
                    'counted_members' => $row['counted'], 'total_duration_ms' => $row['duration'], 'rank' => $row['rank'],
                    'created_at' => now(), 'updated_at' => now(),
                ]]);
            }

            DB::table('competitive_events')->where('id', $locked->getKey())->update(['team_rankings_finalized_at' => now()]);

            return count($rows);
        });
    }

    /** المعتمَدة بلا ترتيب فرق مخزَّن بعد (شبكة أمان للأمر الدوري). @return int */
    public function finalizePending(int $limit = 50): int
    {
        $done = 0;

        CompetitiveEvent::query()->where('status', CompetitiveEvent::STATUS_COMPLETED)->whereNull('team_rankings_finalized_at')->orderBy('id')->limit($limit)->get()
            ->each(function (CompetitiveEvent $event) use (&$done) {
                $this->finalize($event);
                $done++;
            });

        return $done;
    }

    /** هل لهذا الحدث بيانات فرق أصلًا (مشارك بلقطة)؟ لإظهار تبويب الفرق فقط حين يلزم. */
    public function hasTeamData(CompetitiveEvent $event): bool
    {
        return DB::table('competitive_event_participants')->where('competitive_event_id', $event->getKey())->whereNotNull('team_id_snapshot')->exists();
    }

    /**
     * ترتيب الفرق لعرضه: نهائي (مخزَّن) بعد الاعتماد، وإلا مؤقت (محسوب الآن، غير نهائي).
     *
     * @return array{final: bool, rows: Collection<int, object>}
     */
    public function standings(CompetitiveEvent $event): array
    {
        if ($event->team_rankings_finalized_at !== null) {
            $rows = CompetitiveEventTeamResult::query()->where('competitive_event_id', $event->getKey())->with('team:id,name,slug,is_active')->orderBy('rank')->limit(100)->get()
                ->map(fn ($r) => (object) ['rank' => $r->rank, 'team' => $r->team, 'score' => $r->score, 'counted' => $r->counted_members, 'duration' => $r->total_duration_ms]);

            return ['final' => true, 'rows' => $rows];
        }

        $computed = collect($this->compute($event))->take(100);
        $teams = Team::query()->whereIn('id', $computed->pluck('team_id'))->get(['id', 'name', 'slug', 'is_active'])->keyBy('id');

        return ['final' => false, 'rows' => $computed->map(fn ($r) => (object) ['rank' => $r['rank'], 'team' => $teams[$r['team_id']], 'score' => $r['score'], 'counted' => $r['counted'], 'duration' => $r['duration']])];
    }

    /** إحصاءات الفريق من ترتيباته المخزَّنة النهائية. @return array{events: int, wins: int, top3: int, best_rank: ?int, avg_rank: ?float} */
    public function stats(Team $team): array
    {
        $row = CompetitiveEventTeamResult::query()->where('team_id', $team->getKey())
            // E31: rank كلمة محجوزة في MySQL 8 (دالة نافذة) فيجب تأهيلها باسم الجدول في SQL الخام، وإلا فالاستعلام خطأ صياغة على MySQL.
            ->selectRaw('count(*) as events, sum(case when competitive_event_team_results.rank = 1 then 1 else 0 end) as wins, sum(case when competitive_event_team_results.rank <= 3 then 1 else 0 end) as top3, min(competitive_event_team_results.rank) as best, avg(competitive_event_team_results.rank) as avg_rank')->first();

        return [
            'events' => (int) $row->events, 'wins' => (int) $row->wins, 'top3' => (int) $row->top3,
            'best_rank' => $row->best === null ? null : (int) $row->best, 'avg_rank' => $row->avg_rank === null ? null : round((float) $row->avg_rank, 1),
        ];
    }

    /** آخر ترتيبات الفريق النهائية. */
    public function recent(Team $team, int $limit = 5): Collection
    {
        return CompetitiveEventTeamResult::query()->where('team_id', $team->getKey())->with('event:id,title,slug,ends_at')->orderByDesc('id')->limit($limit)->get();
    }

    /**
     * جدول الميداليات العام للفرق (E19-D14): لا نقاط مخترعة عبر الأحداث. الترتيب بأعداد شفافة: انتصارات ← مراكز أولى-ثلاثة ← أحداث ← معرّف أصغر.
     * الفرق المفعَّلة فقط. قرار منتجي لاحق قد يستبدله بنقاط مرجَّحة.
     */
    public function medalTable(int $perPage = 20): LengthAwarePaginator
    {
        $page = DB::table('competitive_event_team_results as r')->join('teams as t', 't.id', '=', 'r.team_id')->where('t.is_active', true)
            ->selectRaw('r.team_id, count(*) as events, sum(case when r.rank = 1 then 1 else 0 end) as wins, sum(case when r.rank <= 3 then 1 else 0 end) as top3')
            ->groupBy('r.team_id')->orderByDesc('wins')->orderByDesc('top3')->orderByDesc('events')->orderBy('r.team_id')->paginate($perPage);

        $teams = Team::query()->whereIn('id', $page->pluck('team_id'))->get(['id', 'name', 'slug', 'members_count'])->keyBy('id');
        $page->getCollection()->transform(fn ($r) => (object) ['team' => $teams[$r->team_id], 'events' => (int) $r->events, 'wins' => (int) $r->wins, 'top3' => (int) $r->top3]);

        return $page;
    }
}
`````

### `config/database.php`

`sha256: f5f4088b9f7be71205990f7a022d8345cdb2222f60fa52ca01ee3012e1e1791d`

`````php
<?php

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the database connections below you wish
    | to use as your default connection for database operations. This is
    | the connection which will be utilized unless another connection
    | is explicitly specified when you execute a query / statement.
    |
    */

    'default' => env('DB_CONNECTION', 'sqlite'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Below are all of the database connections defined for your application.
    | An example configuration is provided for each database system which
    | is supported by Laravel. You're free to add / remove connections.
    |
    */

    'connections' => [

        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
            'busy_timeout' => null,
            'journal_mode' => null,
            'synchronous' => null,
        ],

        'mysql' => [
            'driver' => 'mysql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            // E31: InnoDB صراحةً (المعاملات والمفاتيح الأجنبية والأقفال الصفية تعتمد عليه؛ لا نترك المحرك لإعداد الخادم الافتراضي).
            'engine' => env('DB_ENGINE', 'InnoDB'),
            // E31: جلسة الاتصال بتوقيت UTC (يطابق app.timezone). أعمدة TIMESTAMP تُحوَّل بتوقيت الجلسة، فتوحيده يمنع انزياح الأوقات وأخطاء التوقيت الصيفي.
            'timezone' => env('DB_TIMEZONE', '+00:00'),
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'mariadb' => [
            'driver' => 'mariadb',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            // E31: InnoDB صراحةً (المعاملات والمفاتيح الأجنبية والأقفال الصفية تعتمد عليه؛ لا نترك المحرك لإعداد الخادم الافتراضي).
            'engine' => env('DB_ENGINE', 'InnoDB'),
            // E31: جلسة الاتصال بتوقيت UTC (يطابق app.timezone). أعمدة TIMESTAMP تُحوَّل بتوقيت الجلسة، فتوحيده يمنع انزياح الأوقات وأخطاء التوقيت الصيفي.
            'timezone' => env('DB_TIMEZONE', '+00:00'),
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ],

        'sqlsrv' => [
            'driver' => 'sqlsrv',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', 'localhost'),
            'port' => env('DB_PORT', '1433'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            // 'encrypt' => env('DB_ENCRYPT', 'yes'),
            // 'trust_server_certificate' => env('DB_TRUST_SERVER_CERTIFICATE', 'false'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    |
    | This table keeps track of all the migrations that have already run for
    | your application. Using this information, we can determine which of
    | the migrations on disk haven't actually been run on the database.
    |
    */

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    |
    | Redis is an open source, fast, and advanced key-value store that also
    | provides a richer body of commands than a typical key-value system
    | such as Memcached. You may define your connection settings here.
    |
    */

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug(env('APP_NAME', 'laravel'), '_').'_database_'),
            'persistent' => env('REDIS_PERSISTENT', false),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
        ],

    ],

];
`````

### `config/operations.php`

`sha256: 30a2846171e1dbce185ff1ab2fd695ceef3c910dc5c3b51711988c0db9110c31`

`````php
<?php

/*
|--------------------------------------------------------------------------
| E25 - Production operations knobs
|--------------------------------------------------------------------------
| كل القيم اختيارية ولها افتراضات آمنة لا تغيّر سلوك المنتج. لا أسرار هنا؛ القيم الحساسة (إن وُجدت) تأتي من البيئة فقط ولا تُطبع أبدًا.
| الاستعمال: production:check، release:preflight، release:smoke، /ready، مركز العمليات.
*/

return [

    /*
    | هوية الإصدار (اختيارية). تُضبط عند النشر بقيمة SHA الالتزام أو وسم الإصدار، مثل: APP_RELEASE=b983867
    | تُعرض في /ready ومركز العمليات وتُضاف لسياق كل سجل. القيمة غير الآمنة (غير [A-Za-z0-9._-]{1,64}) تُتجاهل.
    | RELEASE_SHA بديل لنفس الغرض (توافقًا مع أنظمة CI التي تعرّفه تلقائيًا).
    */
    'release' => [
        'id' => env('APP_RELEASE', env('RELEASE_SHA')),
    ],

    /*
    | الأوامر الهدّامة بالإنتاج (db:wipe, migrate:fresh|refresh|reset|rollback) ممنوعة افتراضيًا عند APP_ENV=production (AppServiceProvider).
    | الاستثناء الواعي لعملية واحدة فقط: OPS_ALLOW_DESTRUCTIVE_COMMANDS=true في بيئة تلك العملية، ثم يُزال. لا تضعه في .env الدائم.
    */
    'allow_destructive_commands' => (bool) env('OPS_ALLOW_DESTRUCTIVE_COMMANDS', false),

    /*
    | E31: SQLite ليست قاعدة إنتاج معتمدة (production:check يفشل بها عند APP_ENV=production). الاستثناء الواعي: OPS_ALLOW_SQLITE_PRODUCTION=true
    | يحوّل الفشل إلى تحذير ظاهر. لا يُنصح به؛ وُجد للنشر التجريبي المعزول فقط. انظر docs/mysql-production-e31.md.
    */
    'allow_sqlite_production' => (bool) env('OPS_ALLOW_SQLITE_PRODUCTION', false),

    /*
    | نبض المجدول: مهمة صغيرة كل دقيقة (routes/console.php) تكتب الطابع الزمني في الكاش الافتراضي.
    | الحالة تُحسب من عمر آخر نبضة: ok < stale_after_seconds <= stale < down_after_seconds <= down. لا نبضة قط = unknown (نشر جديد).
    | /ready لا يفشل بسبب المجدول إلا إن فعّلت ready_fails_on_down صراحةً (افتراضيًا معلوماتي فقط).
    */
    'scheduler' => [
        'heartbeat_key' => 'ops:scheduler:heartbeat',
        'heartbeat_ttl_seconds' => 86400,
        'stale_after_seconds' => (int) env('OPS_SCHEDULER_STALE_SECONDS', 300),
        'down_after_seconds' => (int) env('OPS_SCHEDULER_DOWN_SECONDS', 900),
        'ready_fails_on_down' => (bool) env('OPS_READY_FAILS_ON_SCHEDULER', false),
    ],

    /*
    | الطابور: أقصى timeout لأي مهمة (يطابق App\Jobs\Concerns\BoundedRetries). retry_after لازم أن يتجاوزه.
    | تنبيه تراكم: عمر أقدم مهمة منتظرة (ثوانٍ) وعدد المهام الفاشلة، لمركز العمليات.
    */
    'queue' => [
        'job_timeout_seconds' => 80,
        'backlog_warn_age_seconds' => (int) env('OPS_QUEUE_BACKLOG_WARN_SECONDS', 900),
        'failed_jobs_warn' => (int) env('OPS_FAILED_JOBS_WARN', 1),
        'signals_cache_seconds' => 30,
    ],

    /*
    | فحص الدخان بعد النشر (php artisan release:smoke): قراءة فقط (GET) ومهل صغيرة. المسارات كلها عامة (200 للضيف).
    | {profile} يُستبدل بمعرّف لاعب عام عند تمريره بالخيار --profile (اختياري).
    */
    'smoke' => [
        'timeout_seconds' => 5,
        'connect_timeout_seconds' => 3,
        'slow_ms' => 2000,
        'routes' => ['/up', '/ready', '/', '/login', '/puzzles', '/competitions', '/store', '/players/{profile}'],
    ],

    /*
    | تهيئة أول مدير بالإنتاج (AdminUserSeeder): كلمة مرور إلزامية من البيئة بالإنتاج (لا كلمة افتراضية معروفة).
    | احذف المتغيرين من البيئة بعد أول تشغيل. لا تُطبع كلمة المرور أبدًا.
    */
    'admin_bootstrap' => [
        'email' => env('ADMIN_BOOTSTRAP_EMAIL'),
        'password' => env('ADMIN_BOOTSTRAP_PASSWORD'),
        'min_password_length' => 12,
    ],

    /*
    | حسابات اختبار معروفة يجب ألا توجد بقاعدة الإنتاج (production:check).
    */
    'known_test_accounts' => [
        'email_suffix' => '@ahjiyat.test',
        'default_admin_email' => 'admin@ahjiyat.app',
        'default_admin_passwords' => ['change-me-now'],
    ],
];
`````

### `docs/environment-variables.md`

`sha256: cadd51519b3b93b1a2fe55b2c7f8cb83b0bc8edc5ce992a2ec2721a2d80a3fdd`

`````markdown
# متغيرات البيئة: التصنيف وعقد الإنتاج (E25)

> المرجع الوحيد لكل متغير بيئة يقرؤه التطبيق، مصنَّفًا إلى **REQUIRED / SECRET / OPTIONAL / INFRASTRUCTURE-SPECIFIC**.
> يفحص هذا العقد الأمرُ `php artisan production:check` (قراءة فقط، يعطي `PASS/WARN/FAIL` + **اسم** الإعداد ولا يطبع أي قيمة سرية أبدًا).
> القيم الحقيقية لا توجد بأي ملف بالمستودع: `.env.example` يحوي placeholders فقط.

## قواعد عامة

1. **لا أسرار في Git ولا في الوثائق ولا في CI.** `.env` ملف خارج المستودع (مُستبعَد بـ`.gitignore`). أسرار الإنتاج: مدير أسرار المزوّد، أو متغيرات بيئة محميّة على الخادم، أو ملف `.env` بصلاحيات `640` ومالك مستخدم التطبيق فقط.
2. **`config:cache` يجمّد القيم.** الإنتاج يشغّل `php artisan optimize`، وبعدها لا يقرأ التطبيق `.env` إطلاقًا: أي تغيير بالبيئة **لا يسري** قبل إعادة `php artisan optimize` ثم `php artisan queue:restart` (العمال طويلو العمر) و`php artisan reverb:restart`.
3. **متغيرات `VITE_*` عامة بالتصميم**: تُدمَج داخل حزمة جافاسكربت وقت `npm run build` ويراها كل زائر. لا يوضع فيها أي سر إطلاقًا (`VITE_REVERB_APP_KEY` هو معرّف عام لا سر).
4. **التدوير**: كل سر له خطوات تدوير بـ[التحصين الأمني](security-hardening.md#تدوير-الأسرار-p4).

## 1) REQUIRED: لا يعمل الإنتاج الصحيح بدونها

| المتغير | القيمة بالإنتاج | ملاحظة |
|---|---|---|
| `APP_ENV` | `production` | تفعّل HSTS وتشدّد `production:check` (FAIL بدل WARN) وتمنع الأوامر الهدّامة |
| `APP_DEBUG` | `false` | **FAIL حاجب دائمًا** إن كانت true (تكشف Stack Traces والإعدادات) |
| `APP_URL` | `https://<نطاقك>` | يُفحص: https، ليس مضيفًا محليًا |
| `APP_KEY` | يولَّد مرة واحدة (`php artisan key:generate --show`) | **SECRET**. انظر [تحذير APP_KEY](security-hardening.md#app_key-تحذير-خاص) |
| `DB_CONNECTION` | `mysql` (أو `pgsql`) | **sqlite مرفوض بالإنتاج** (FAIL) |
| `DB_HOST` `DB_PORT` `DB_DATABASE` `DB_USERNAME` | بنية تحتية | مستخدم بصلاحيات التطبيق فقط (ليس root) |
| `SESSION_DRIVER` | `database` أو `redis` | `array`/`null` مرفوضان؛ `file` لخادم واحد فقط |
| `SESSION_SECURE_COOKIE` | `true` | **FAIL** بالإنتاج إن لم تكن true |
| `CACHE_STORE` | `redis` أو `database` | `array`/`null` مرفوضان؛ `file` لخادم واحد فقط؛ يلزم دعم الأقفال (المجدول) |
| `QUEUE_CONNECTION` | `database` أو `redis` | `sync` تحذير، `null` مرفوض. يلزم عامل `queue:work` دائم |
| `MAIL_MAILER` + `MAIL_HOST` `MAIL_PORT` `MAIL_USERNAME` `MAIL_FROM_ADDRESS` | `smtp` وبيانات مزوّدك | `log`/`array` مرفوضان بالإنتاج (توثيق البريد/استرجاع كلمة السر شرط الاستبدال) |
| `BROADCAST_CONNECTION` | `reverb` | وإلا لا تحديث لحظي للدردشة (الحفظ يعمل) |
| `REVERB_APP_ID` `REVERB_APP_KEY` `REVERB_HOST` `REVERB_PORT` `REVERB_SCHEME` | `https` للمخطط | `REVERB_HOST` النطاق العام لخادم Reverb |
| `LOG_STACK` | `daily` | `single` بلا تدوير يملأ القرص |
| `LOG_LEVEL` | `info` أو `warning` | `debug` بالإنتاج ضجيج وخطر تسجيل زائد |

## 2) SECRET: لا تُطبع ولا تُسجَّل ولا تُخزَّن بالمستودع

| المتغير | أين يُستعمل | تدويره |
|---|---|---|
| `APP_KEY` | التشفير، الجلسات المشفّرة، الكوكيز، الروابط الموقَّعة | **ليس تدويرًا عاديًا**: [تحذير خاص](security-hardening.md#app_key-تحذير-خاص) |
| `APP_PREVIOUS_KEYS` | قراءة بيانات مشفرة بمفتاح سابق أثناء التدوير | يُزال بعد انتهاء الفترة |
| `DB_PASSWORD` | اتصال القاعدة | [تدوير كلمة مرور القاعدة](security-hardening.md#تدوير-الأسرار-p4) |
| `REDIS_PASSWORD` | Redis | بنفس الطريقة |
| `REVERB_APP_SECRET` | توقيع البثّ والمصادقة | بنفس الطريقة (+ إعادة بناء الأصول لا يلزم: السر لا يصل المتصفح) |
| `MAIL_PASSWORD` (أو `POSTMARK_TOKEN`/`RESEND_KEY`) | إرسال البريد | عند المزوّد ثم تحديث البيئة |
| `AWS_SECRET_ACCESS_KEY` | قرص S3 (إن استُعمل) | عند المزوّد |
| `SLACK_BOT_USER_OAUTH_TOKEN` / `LOG_SLACK_WEBHOOK_URL` | تنبيهات (إن استُعملت) | عند Slack |
| `MEMCACHED_PASSWORD` | Memcached (إن استُعمل) | عند المزوّد |
| `PERF_READINESS_TOKEN` | تقييد `/ready` برأس `X-Health-Token` | ولّد قيمة عشوائية جديدة وحدّث الموازن |
| `ADMIN_BOOTSTRAP_PASSWORD` | أول مدير فقط | **مؤقت**: يُحذف بعد أول تشغيل (انظر [أول نشر](first-production-deploy.md)) |
| `MAINTENANCE_SECRET` | سر دخول وضع الصيانة (يقرؤه `tools/deploy/deploy.sh` فقط) | قيمة جديدة لكل مرة، لا تُحفظ ولا تُسجَّل |
| `BACKUP_*` مفاتيح التشفير / `RESTORE_AGE_IDENTITY` | نسخ احتياطي واستعادة | مفتاح الاستعادة الخاص **لا يبقى على الخادم** |

> **معرّف عام لا سر**: `REVERB_APP_KEY` (يصل المتصفح عبر `VITE_REVERB_APP_KEY`)، `ADSENSE_CLIENT_ID`. لا تعاملهما كسر لكن لا تضعهما بالتذاكر العامة بلا داعٍ.

## 3) OPTIONAL: لها افتراضات آمنة، لا تضبطها إلا لسبب

| المتغير | الافتراضي | الغرض |
|---|---|---|
| `APP_RELEASE` / `RELEASE_SHA` | غير مضبوط | معرّف الإصدار (يظهر في `/ready` والسجلات ومركز العمليات). صيغة `[A-Za-z0-9._-]` حتى 64 خانة وإلا يُتجاهَل |
| `TRUSTED_PROXIES` | فارغ (لا وكلاء) | عناوين/CIDR الموازن. `*` فقط إن لم يُستقبَل الطلب إلا من الموازن |
| `REVERB_ALLOWED_ORIGINS` | `*` | أسماء مضيفين مسموحة لاتصالات WebSocket (بلا `http://`) |
| `PERF_*` | انظر [performance-e24](performance-e24.md) | تحسينات الأداء والمراقبة (E24) |
| `OPS_SCHEDULER_STALE_SECONDS` / `OPS_SCHEDULER_DOWN_SECONDS` | 300 / 900 | عتبات نبض المجدول |
| `OPS_READY_FAILS_ON_SCHEDULER` | `false` | `true`: `/ready` يفشل فقط عند حالة down (لا عند التأخر البسيط ولا بعد نشر جديد) |
| `OPS_QUEUE_BACKLOG_WARN_SECONDS` / `OPS_FAILED_JOBS_WARN` | 900 / 1 | عتبات تنبيه الطابور (مركز العمليات وproduction:check) |
| `OPS_ALLOW_DESTRUCTIVE_COMMANDS` | `false` | استثناء واعٍ **لعملية واحدة** من حظر `migrate:rollback/fresh` وغيرها بالإنتاج. لا يوضع بـ`.env` الدائم |
| `LOG_DAILY_DAYS` | 14 | أيام الاحتفاظ بقناة `daily` |
| `GEMS_*`, `PROGRESSION_*` | انظر `config/gems.php` | منطق المنتج: **لا تغيّرها ضمن عملية تشغيل** (قرار منتج) |
| `DB_QUEUE_RETRY_AFTER` / `REDIS_QUEUE_RETRY_AFTER` | 90 | يجب أن يبقى **أكبر من 80** (أقصى timeout لأي Job) وإلا تُنفَّذ المهمة مرتين |
| `SESSION_SAME_SITE` / `SESSION_HTTP_ONLY` / `SESSION_LIFETIME` | `lax` / `true` / 120 | الكوكي: لا تعطّل HttpOnly |
| `DB_ENGINE` / `DB_TIMEZONE` | `InnoDB` / `+00:00` | محرك جداول MySQL ومنطقة جلسة القاعدة (E31؛ يجب أن تطابق UTC) |
| `OPS_ALLOW_SQLITE_PRODUCTION` | `false` | `true`: يحوّل فشل `production:check` على SQLite إلى تحذير. **غير مستحسن**: MySQL 8.0.16+ هي القاعدة المعتمدة ([E31](mysql-production-e31.md)) |
| `MIGRATION_TARGET_CONNECTION` / `MIGRATION_SQLITE_DATABASE` / `MIGRATION_BATCH_SIZE` / `MIGRATION_MAX_BATCH_BYTES` | `mysql` / — / 1000 / 1000000 | أداة نقل SQLite→MySQL لمرة واحدة (محلية فقط، ترفض الإنتاج) |
| `MIGRATION_MYSQL_HOST` / `_PORT` / `_DATABASE` / `_USERNAME` / `_PASSWORD` | — | تجاوزات هدف النقل (كلمة المرور **SECRET** من البيئة فقط ولا تُطبع) |

## 4) INFRASTRUCTURE-SPECIFIC: تعتمد على بنيتك/مزوّدك

| المتغيرات | تعتمد على |
|---|---|
| `DB_HOST` `DB_PORT` `DB_SOCKET` `DB_URL` `MYSQL_ATTR_SSL_CA` `DB_CHARSET` `DB_COLLATION` | خادم القاعدة وTLS |
| `REDIS_CLIENT` `REDIS_HOST` `REDIS_PORT` `REDIS_USERNAME` `REDIS_DB` `REDIS_CACHE_DB` `REDIS_URL` `REDIS_PREFIX` `REDIS_CLUSTER` | خادم Redis (انظر [دليل التشغيل](operations-runbook.md#redis)) |
| `FILESYSTEM_DISK` `AWS_*` | قرص الملفات (محلي أم S3-متوافق) |
| `TRUSTED_PROXIES` | الموازن/Cloudflare |
| `REVERB_SERVER_HOST` `REVERB_SERVER_PORT` `REVERB_SCALING_ENABLED` `REVERB_APP_MAX_CONNECTIONS` ... | طوبولوجيا خادم Reverb |
| `MAIL_SCHEME` `MAIL_EHLO_DOMAIN` `MAIL_SENDMAIL_PATH` | مزوّد البريد |
| `WKHTML_PDF_BINARY` | مسار wkhtmltopdf على الخادم (تصدير PDF) |
| `LOG_STDERR_FORMATTER` `LOG_SYSLOG_FACILITY` `PAPERTRAIL_*` | منصة السجلات المركزية (P2) |

## 5) متغيرات تشغيل السكربتات (ليست بيئة التطبيق)

لا تُوضع في `.env` التطبيق؛ تُمرَّر لحظة تشغيل السكربت: `BACKUP_DIR`، `BACKUP_AGE_RECIPIENT`/`BACKUP_GPG_RECIPIENT`، `BACKUP_UPLOAD_CMD`، `BACKUP_DB_DEFAULTS_FILE`، `RESTORE_DB_*`، `RESTORE_CONFIRM_DATABASE`، `DEPLOY_RELOAD_CMD`، `DEPLOY_SMOKE_URL`، `DEPLOY_BACKUP_CONFIRMED`. انظر [النسخ الاحتياطي](backup-disaster-recovery.md) و[هندسة الإصدارات](release-engineering.md).

## 6) الحد الأدنى النموذجي للإنتاج (placeholders فقط)

```dotenv
APP_NAME=Ahjiyat
APP_ENV=production
APP_KEY=                       # php artisan key:generate --show (مرة واحدة، يُحفظ بخزنة أسرار)
APP_DEBUG=false
APP_URL=https://ahjiyat.example.com
APP_RELEASE=                   # يُضبط عند كل نشر

LOG_STACK=daily
LOG_LEVEL=warning
LOG_DAILY_DAYS=14

DB_CONNECTION=mysql
DB_HOST=
DB_PORT=3306
DB_DATABASE=
DB_USERNAME=
DB_PASSWORD=                   # SECRET

SESSION_DRIVER=database        # أو redis
SESSION_SECURE_COOKIE=true
CACHE_STORE=database           # أو redis
QUEUE_CONNECTION=database      # أو redis

MAIL_MAILER=smtp
MAIL_HOST=
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=                 # SECRET
MAIL_FROM_ADDRESS=no-reply@ahjiyat.example.com

BROADCAST_CONNECTION=reverb
REVERB_APP_ID=
REVERB_APP_KEY=
REVERB_APP_SECRET=             # SECRET (≥ 32 خانة عشوائية)
REVERB_HOST=ws.ahjiyat.example.com
REVERB_PORT=443
REVERB_SCHEME=https
REVERB_ALLOWED_ORIGINS=ahjiyat.example.com
VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"

TRUSTED_PROXIES=               # CIDR الموازن إن وُجد
```

ثم: `php artisan production:check` (يجب Exit 0) قبل أي نشر.
`````

### `docs/production-checklist.md`

`sha256: d4a771a71a1f01b36339c4c90d2ab55c324a5d8c073c1650e6a6802d8f0be26e`

`````markdown
# قائمة جاهزية الإطلاق للإنتاج (E25)

> علّم كل بند بعد التحقق منه بنفسك (لا بالافتراض). تكمّل هذه القائمة [pre-launch-checklist](pre-launch-checklist.md) (القائمة الأقدم، تبقى مرجعًا للمحتوى والصفحات) وتتقدّم عليها في كل ما يخص التشغيل والأمان.
> معظم البنود التقنية يفحصها `php artisan production:check` (يجب Exit 0) و`php artisan release:preflight` قبل النشر.

## DNS و TLS

- [ ] DNS للنطاق (A/AAAA/CNAME) يشير للخادم/الموازن الصحيح، وللنطاق الفرعي لـReverb.
- [ ] شهادة TLS صالحة للنطاق والنطاق الفرعي، وتجديد تلقائي مُجرَّب.
- [ ] HTTP يُحوَّل إلى HTTPS على مستوى الوكيل.
- [ ] (اختياري، بوعي) HSTS: التطبيق يرسله فقط بالإنتاج على HTTPS بلا `includeSubDomains`/`preload`. لا تفعّلهما إلا بعد التأكد من ثبات HTTPS لكل النطاقات الفرعية.

## البيئة والإعدادات

- [ ] `APP_URL=https://<النطاق>` (ليس localhost).
- [ ] `APP_ENV=production`.
- [ ] `APP_DEBUG=false` (**FAIL حاجب** إن كان true).
- [ ] `APP_KEY` مضبوط وصالح، ونسخته محفوظة بخزنة أسرار بنسختين ([escrow](backup-disaster-recovery.md#2-الأسرار-وapp_key-ضمان-مفتاح-آمن-key-escrow)) وليس بالمستودع.
- [ ] `APP_RELEASE` مضبوط لهذا الإصدار.
- [ ] `SESSION_SECURE_COOKIE=true`، `SESSION_SAME_SITE=lax`، `SESSION_HTTP_ONLY=true`.
- [ ] `TRUSTED_PROXIES` مضبوط على عناوين الموازن (أو فارغ فقط إن لا موازن).
- [ ] `LOG_STACK=daily`، `LOG_LEVEL=warning|info`، `LOG_DAILY_DAYS` محدد.
- [ ] لا أسرار في الكود/الوثائق/CI؛ `.env` بصلاحيات `640` وخارج `public/`.
- [ ] `php artisan production:check` ⟵ Exit **0** ([المتغيرات](environment-variables.md)).

## قاعدة البيانات والترحيلات

- [ ] `DB_CONNECTION=mysql|pgsql` (ليس sqlite)، مستخدم بصلاحيات التطبيق فقط (ليس root)، كلمة مرور قوية.
- [ ] MySQL **8.0.16+** بترميز utf8mb4 والوضع الصارم وInnoDB؛ `php artisan db:parity --runtime` يخرج 0؛ إجراء النقل والانتقال والتراجع في [E31](mysql-production-e31.md).
- [ ] **نسخة احتياطية مُتحقَّق منها قبل أول ترحيل**.
- [ ] `php artisan release:preflight` بلا FAIL، ومراجعة كل ترحيل معلّق وخطره ([السياسة](release-engineering.md#4-سياسة-الترحيلات-الآمنة)).
- [ ] `php artisan migrate --force` نُفِّذ؛ `production:check` لا يعرض ترحيلات معلّقة.
- [ ] **لا `db:seed` الكامل بالإنتاج** (ممنوع آليًا)؛ لا حسابات `@ahjiyat.test` ولا كلمة المرور الافتراضية للمدير.

## Redis و الكاش

- [ ] قرار واعٍ: Redis أم database لكل من الكاش/الجلسات/الطابور ([Redis](operations-runbook.md#redis)).
- [ ] إن وُجد Redis: كلمة مرور، مربوط بواجهة خاصة، `noeviction` إن حمل الطابور/الجلسات، استمرارية AOF، وتنبيه ذاكرة.
- [ ] الكاش يدعم الأقفال (مطلوب للمجدول) ومشترك بين الخوادم إن تعددت.

## الطابور والمجدول و Reverb

- [ ] `QUEUE_CONNECTION=database|redis`، و`retry_after` (90) أكبر من timeout (80).
- [ ] عامل `queue:work` تحت Supervisor/systemd ويعمل (`--timeout=80 --max-time=3600`).
- [ ] `queue:restart` ضمن خطوات النشر.
- [ ] cron: `* * * * * php artisan schedule:run` لمستخدم التطبيق؛ نبضة المجدول `ok` بمركز العمليات بعد دقيقتين.
- [ ] قرار الطوبولوجيا: مجدول مخصص أم كل الخوادم + كاش مشترك.
- [ ] Reverb تحت المشرف، `REVERB_SCHEME=https`، `REVERB_HOST` عام، `REVERB_APP_SECRET` عشوائي ≥ 32 خانة، `REVERB_ALLOWED_ORIGINS` مقيّدة، الوكيل يمرّر `Upgrade`، وحد الملفات المفتوحة مرفوع.

## التخزين والبريد والصلاحيات

- [ ] `storage/` و`bootstrap/cache/` قابلان للكتابة؛ `php artisan storage:link` (رابط حقيقي).
- [ ] مساحة القرص كافية (> 15% متبقي) وتنبيه عند 80%.
- [ ] `MAIL_MAILER=smtp` فعلي، `MAIL_FROM_ADDRESS` على نطاقك مع SPF/DKIM/DMARC، ورسالة توثيق بريد وصلت فعلًا.
- [ ] `php artisan permissions:sync` ثم (عند أول نشر فقط) `RolesAndPermissionsSeeder`؛ مدير أعلى (super-admin) واحد على الأقل بكلمة مرور قوية؛ `ADMIN_BOOTSTRAP_*` حُذفت من البيئة.
- [ ] `/admin` يعمل بحساب مخوَّل فقط، لا مسارات تشخيص عامة (`production:check` يفحصها).

## البناء والصحة والدخان

- [ ] `composer install --no-dev --prefer-dist --optimize-autoloader` من `composer.lock`؛ `npm ci && npm run build` من `package-lock.json`؛ لا `composer update`/`npm update` في النشر.
- [ ] `php artisan optimize` (config/routes/views/events).
- [ ] `GET /up` = 200، `GET /ready` = 200 وفيه `release` الصحيح.
- [ ] `php artisan release:smoke --expect-release=<id>` ⟵ بلا FAIL.
- [ ] الفحص اليدوي بعد النشر ([smoke-tests](smoke-tests.md)) خلال 15–20 دقيقة.

## النسخ الاحتياطي والاستعادة

- [ ] نسخة يومية للقاعدة والملفات، **مشفّرة وخارج الخادم**، وتنبيه عند فشلها.
- [ ] PITR/binlog مفعّل إن أمكن؛ RPO/RTO متفق عليهما مع مالك المنتج.
- [ ] **تمرين استعادة ناجح واحد على الأقل** قبل الإطلاق، ومدوَّن بزمنه ([التمرين](backup-disaster-recovery.md#10-تمرين-الاستعادة-restore-drill-كل-ربع-سنة)).
- [ ] جدول تمارين ربع سنوية محدد.

## المراقبة والسجلات

- [ ] مراقب خارجي على `/ready` (وتنبيه إلى قناة حيّة).
- [ ] تنبيهات: القرص، الذاكرة، 5xx، عمر الطابور، المهام الفاشلة، نبضة المجدول، فشل النسخة.
- [ ] تدوير السجلات والاحتفاظ؛ قرار منصة سجلات مركزية (P2).
- [ ] لا بيانات حساسة بالسجلات (Authorization/Cookie/كلمات المرور/نص الدردشة/جسم الطلب).
- [ ] مركز العمليات يعرض الإصدار وحالة المجدول والطابور (لمن يملك `system.audit_view`).

## مسار التراجع والحوادث

- [ ] الإصدار السابق محفوظ وقابل للاستعادة (artifact/مجلد إصدار) ومعرَّف.
- [ ] مصفوفة التراجع مقروءة لمن سينفّذ ([التراجع](release-engineering.md#53-مصفوفة-التراجع)): الكود أولًا، لا rollback للقاعدة.
- [ ] [دليل الحوادث](incident-response.md) معروف، وجهات اتصال المناوبة محدّثة.
- [ ] سياسة الجوائز والاستبدال مراجعة لدى مختص قانوني بحسب الدولة المستهدفة (تنبيه الدراسة الأصلية).

## التوقيع

| | الاسم | التاريخ |
|---|---|---|
| منفّذ النشر | | |
| مراجع ثانٍ | | |
| مالك المنتج (الموافقة على الإطلاق) | | |
`````

### `tests/Feature/CreatorE28/E28OperationsTest.php`

`sha256: 5bb3eda2356b74bbdc2ff733adb9444fec5e0b5326300ed22d9bfd5f441612b6`

`````php
<?php

use App\Filament\Resources\CommunityPuzzleReportResource\Pages\ListCommunityPuzzleReports;
use App\Filament\Resources\CreatorProfileResource\Pages\ListCreatorProfiles;
use App\Filament\Resources\CreatorPuzzleRevisionResource\Pages\ListCreatorPuzzleRevisions;
use App\Filament\Resources\CreatorPuzzleRevisionResource\Pages\ViewCreatorPuzzleRevision;
use App\Models\CreatorProfile;
use App\Models\CreatorPuzzle;
use App\Models\CreatorPuzzleRevision;
use App\Models\Puzzle;
use App\Models\User;
use App\Services\UserAccountService;
use Database\Seeders\DemoCreatorSeeder;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/_support.php';

/*
| E28-X (إضافي على قائمة Y1–Y10) - الجزء التشغيلي: لوحة الإشراف (Filament) بصلاحياتها، تجميد الحساب → إخفاء، تنظيف الوسائط اليتيمة، بيانات Demo، قائمة التنقل.
*/

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Storage::fake('public');
});

test('X1: the moderation resources open for a moderator and stay closed to players and to roles without creators.view', function () {
    $creator = e28_creator();
    e28_pending($creator, 'text', ['title' => 'قيد الفحص']);
    $moderator = e28_reviewer('moderator');

    $this->actingAs($moderator);
    Livewire::test(ListCreatorProfiles::class)->assertSuccessful();
    Livewire::test(ListCreatorPuzzleRevisions::class)->assertSuccessful()->assertSee('قيد الفحص');
    Livewire::test(ListCommunityPuzzleReports::class)->assertSuccessful();
    auth()->logout();

    $this->actingAs(e16User());
    expect(\App\Filament\Resources\CreatorPuzzleRevisionResource::canViewAny())->toBeFalse()
        ->and(\App\Filament\Resources\CreatorProfileResource::canViewAny())->toBeFalse()
        ->and(\App\Filament\Resources\CommunityPuzzleReportResource::canViewAny())->toBeFalse();
    $this->get('/admin')->assertForbidden();   // لاعب عادي لا يدخل اللوحة أصلًا
});

test('X2: a moderator approves from the review queue; reasons are mandatory for changes/reject; a view-only role cannot act', function () {
    $creator = e28_creator();
    $revision = e28_pending($creator, 'text', ['title' => 'للاعتماد من اللوحة']);
    $second = e28_pending($creator, 'text', ['title' => 'للرفض من اللوحة']);

    $viewOnly = e16User();
    $viewOnly->givePermissionTo('creators.view');
    $this->actingAs($viewOnly);
    Livewire::test(ListCreatorPuzzleRevisions::class)->assertSuccessful()->assertTableActionHidden('approve', $revision)->assertTableActionHidden('reject', $revision);
    auth()->logout();

    $this->actingAs(e28_reviewer('moderator'));
    Livewire::test(ListCreatorPuzzleRevisions::class)->callTableAction('reject', $second, ['note' => ''])->assertHasTableActionErrors(['note' => 'required']);
    expect($second->refresh()->status)->toBe(CreatorPuzzleRevision::STATUS_PENDING_REVIEW);

    Livewire::test(ListCreatorPuzzleRevisions::class)->callTableAction('approve', $revision)->assertHasNoTableActionErrors();

    $revision->refresh();
    expect($revision->status)->toBe(CreatorPuzzleRevision::STATUS_APPROVED)
        ->and($revision->work->refresh()->is_visible)->toBeTrue()
        ->and($second->refresh()->status)->toBe(CreatorPuzzleRevision::STATUS_PENDING_REVIEW);

    Livewire::test(ListCreatorPuzzleRevisions::class)->callTableAction('reject', $second, ['note' => 'سبب الرفض مكتوب وواضح.'])->assertHasNoTableActionErrors();
    expect($second->refresh()->status)->toBe(CreatorPuzzleRevision::STATUS_REJECTED);
});

test('X3: the queue detail page never shows the answer, and the queue never loads the encrypted content column', function () {
    $creator = e28_creator();
    $revision = e28_pending($creator, 'text', ['title' => 'لغز الإشراف', 'answer' => 'جوابإشرافيسري']);

    $this->actingAs(e28_reviewer('moderator'));
    $html = Livewire::test(ViewCreatorPuzzleRevision::class, ['record' => $revision->getKey()])->assertSuccessful()->html();

    expect($html)->toContain('لغز الإشراف')->and(str_contains($html, 'جوابإشرافيسري'))->toBeFalse();
    e28_assert_no_secrets($html, ['جوابإشرافيسري']);
});

test('X4: freezing a creator account hides their works immediately, and unfreezing brings them back (no stale visibility)', function () {
    $creator = e28_creator();
    $work = e28_published($creator, 'text', ['title' => 'عمل يتأثر بالتجميد']);
    $admin = e28_reviewer('administrator');

    $this->get(route('community.puzzles.show', $work->public_id))->assertOk();
    expect(e27_titles(e27_suggest($this, 'يتأثر')))->toBe(['عمل يتأثر بالتجميد']);

    app(UserAccountService::class)->freeze($creator, $admin, 'سبب التجميد المؤقت للاختبار.');

    $this->get(route('community.puzzles.show', $work->public_id))->assertNotFound();
    expect($work->refresh()->is_visible)->toBeFalse()->and($work->hidden_reason)->toBe(CreatorPuzzle::HIDDEN_ACCOUNT_FROZEN)
        ->and(e27_titles(e27_suggest($this, 'يتأثر')))->toBe([]);

    $this->get(route('creators.show', $creator->creatorProfile()->value('slug')))->assertNotFound();

    app(UserAccountService::class)->unfreeze($creator->refresh(), $admin);

    $this->get(route('community.puzzles.show', $work->public_id))->assertOk();
    expect($work->refresh()->is_visible)->toBeTrue()->and($work->hidden_reason)->toBeNull();
});

test('X5: creators:prune-media removes only old orphaned files - never a referenced or a recent one - and --dry-run deletes nothing', function () {
    $creator = e28_creator();
    $this->actingAs($creator)->post(route('creator.puzzles.store'), e28_data('image', ['image' => UploadedFile::fake()->image('a.png', 200, 200)]))->assertSessionHasNoErrors();
    $referenced = CreatorPuzzleRevision::query()->firstOrFail()->image_path;
    $directory = dirname($referenced);

    $disk = Storage::disk('public');
    $disk->put($directory.'/orphan-old.png', 'x');
    $disk->put($directory.'/orphan-new.png', 'x');
    touch($disk->path($referenced), time() - 5 * 86400);                  // مرجعي قديم: يبقى لأنه مرتبط
    touch($disk->path($directory.'/orphan-old.png'), time() - 5 * 86400);  // يتيم قديم: يُحذف

    Artisan::call('creators:prune-media', ['--dry-run' => true]);
    $disk->assertExists($directory.'/orphan-old.png');

    Artisan::call('creators:prune-media');

    $disk->assertMissing($directory.'/orphan-old.png');
    $disk->assertExists($directory.'/orphan-new.png');
    $disk->assertExists($referenced);
});

test('X6: the demo seeder is idempotent, uses only the official services, and never touches wallets, XP or official attempts', function () {
    $this->seed(DemoCreatorSeeder::class);
    $first = [
        'users' => User::query()->count(),
        'profiles' => CreatorProfile::query()->count(),
        'works' => CreatorPuzzle::query()->count(),
        'revisions' => CreatorPuzzleRevision::query()->count(),
        'canonical' => Puzzle::query()->where('origin', 'community')->count(),
    ];

    $this->seed(DemoCreatorSeeder::class);

    expect([
        'users' => User::query()->count(),
        'profiles' => CreatorProfile::query()->count(),
        'works' => CreatorPuzzle::query()->count(),
        'revisions' => CreatorPuzzleRevision::query()->count(),
        'canonical' => Puzzle::query()->where('origin', 'community')->count(),
    ])->toBe($first)
        ->and($first['works'])->toBeGreaterThan(0)
        ->and(\App\Models\XpTransaction::query()->count())->toBe(0)
        ->and(\App\Models\GemTransaction::query()->count())->toBe(0)
        ->and(\App\Models\PuzzleAttempt::query()->count())->toBe(0)
        ->and(Puzzle::query()->where('origin', 'community')->where(fn ($q) => $q->where('gem_reward', '>', 0)->orWhere('xp_reward', '>', 0))->count())->toBe(0);
});

test('X7: the demo seeder refuses to run in production', function () {
    $original = $this->app['env'];
    $this->app['env'] = 'production';

    try {
        expect(fn () => app(DemoCreatorSeeder::class)->run())->toThrow(RuntimeException::class);
    } finally {
        $this->app['env'] = $original;
    }

    expect(CreatorProfile::query()->count())->toBe(0);
});

test('X8: navigation - community puzzles are listed for everyone; the creator studio entry only for verified players', function () {
    $guest = $this->get(route('community.puzzles.index'))->assertOk()->getContent();
    expect($guest)->toContain(route('community.puzzles.index'))->and(str_contains($guest, route('creator.apply')))->toBeFalse();

    $verified = $this->actingAs(e16User())->get(route('community.puzzles.index'))->assertOk()->getContent();
    expect($verified)->toContain(route('creator.apply'))->toContain('استوديو المبدع');
    auth()->logout();

    $unverified = $this->actingAs(e16User(['email_verified_at' => null]))->get(route('community.puzzles.index'))->assertOk()->getContent();
    expect(str_contains($unverified, route('creator.apply')))->toBeFalse();
});

test('X9: beyond the partial-match limit the community search filter is a bare-column prefix LIKE served by the visible-title index, and reads no encrypted content', function () {
    if (\Illuminate\Support\Facades\DB::getDriverName() !== 'sqlite') {
        test()->markTestSkipped('E31: يعتمد على EXPLAIN QUERY PLAN الخاص بـSQLite - خطط MySQL تُفحص بـEXPLAIN محليًا (docs/mysql-production-e31.md).');
    }

    config(['search.partial_match_max_id' => 0]);
    $creator = e28_creator();
    e28_published($creator, 'text', ['title' => 'نمط مجتمع واحد']);
    e27_cold();

    \Illuminate\Support\Facades\DB::flushQueryLog();
    \Illuminate\Support\Facades\DB::enableQueryLog();
    $titles = e27_titles(e27_suggest($this, 'نمط', e16User()));
    $log = \Illuminate\Support\Facades\DB::getQueryLog();
    \Illuminate\Support\Facades\DB::disableQueryLog();

    $query = collect($log)->first(fn ($q) => str_contains($q['query'], 'search_tier') && str_contains($q['query'], 'from "creator_puzzles"'));
    expect($titles)->toBe(['نمط مجتمع واحد'])->and($query)->not->toBeNull();

    $where = substr($query['query'], (int) strpos($query['query'], ' from "'));
    expect($where)->not->toContain('REPLACE(')->not->toContain('LOWER(')->toMatch('/like \? escape \'!\'/')
        ->and(str_contains($query['query'], 'content'))->toBeFalse();

    foreach ($query['bindings'] as $binding) {
        if (is_string($binding) && str_contains($binding, '%')) {
            expect($binding)->not->toStartWith('%');
        }
    }

    $plan = collect(\Illuminate\Support\Facades\DB::select('EXPLAIN QUERY PLAN '.$query['query'], $query['bindings']))->pluck('detail')->implode(' | ');
    expect($plan)->toContain('creator_puzzles_visible_title_index');
});
`````

### `tests/Feature/CreatorSeriesE30/E30SeriesPerformanceTest.php`

`sha256: a9c94e87cecc00a9133f66c78c9c0c2070a08af90cfcb9b6d2ae1ffb01da90b4`

`````php
<?php

use App\Filament\Resources\CreatorSeriesRevisionResource\Pages\ListCreatorSeriesRevisions;
use App\Models\CreatorSeries;
use App\Models\CreatorSeriesRevision;
use App\Services\CreatorSeries\CreatorSeriesAnalytics;
use App\Services\CreatorSeries\CreatorSeriesVisibilityService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

require_once __DIR__.'/_support.php';

/*
| E30 (B) - الأداء. المبدأ كـE27/E28: عدد الاستعلامات لا يتبع حجم البيانات (لا N+1) والسقوف مقيسة، والفهارس تُستعمل فعلًا. SQLite لا يقيس زمن MySQL؛ فالاختبارات بنيوية.
*/

beforeEach(function () {
    e30s_seed();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

test('AM15-140: the public series page runs a small bounded number of queries (guest and signed-in)', function () {
    $creator = e28_creator();
    $series = e30s_published($creator, e30s_works($creator, 5));
    $player = e16User();
    $this->actingAs($player)->get(route('series.show', $series->public_id))->assertOk();   // تسخين الصلاحيات/الإعدادات
    auth()->logout();
    $this->flushSession();

    $guest = e27_queries(fn () => $this->get(route('series.show', $series->public_id))->assertOk());
    $this->actingAs($player);
    $signedIn = e27_queries(fn () => $this->get(route('series.show', $series->public_id))->assertOk());

    expect($guest)->toBeLessThanOrEqual(10)->and($signedIn)->toBeLessThanOrEqual(14);
});

test('AM15-141: 5 vs 25 items do not explode the query count (series page, studio, creator profile, search, discovery)', function () {
    $small = e28_creator();
    $large = e28_creator();
    $smallSeries = e30s_published($small, e30s_works($small, 5), ['title' => 'سلسلة خمسة عناصر']);
    $largeSeries = e30s_published($large, e30s_works($large, 25), ['title' => 'سلسلة خمسة وعشرين']);
    $player = e16User();

    // تسخين كل المسارات مرة (كاش الصلاحيات والإعدادات) ثم القياس البارد للكاش المعني
    foreach ([$smallSeries, $largeSeries] as $s) {
        $this->actingAs($player)->get(route('series.show', $s->public_id))->assertOk();
    }
    auth()->logout();

    $measure = function (callable $request) use ($player) {
        Cache::flush();
        $this->actingAs($player);
        $count = e27_queries($request);
        auth()->logout();

        return $count;
    };

    $surfaces = [
        'series page' => fn ($s) => fn () => $this->get(route('series.show', $s->public_id))->assertOk(),
        'puzzle page with series context' => fn ($s) => fn () => $this->get(route('community.puzzles.show', ['work' => CreatorSeries::query()->find($s->id)->publishedRevision->items()->first()->work()->value('public_id'), 'series' => $s->public_id]))->assertOk(),
    ];

    foreach ($surfaces as $name => $factory) {
        expect($measure($factory($largeSeries)))->toBe($measure($factory($smallSeries)), $name.' query count depends on item count');
    }

    // استوديو المبدع (قائمة + تعديل) بعناصر 5 مقابل 25. كل مسار يُسخَّن مرة لكل مبدع ثم يُقاس القياس الثاني (كاش الصلاحيات/الإعدادات خارج المقارنة).
    $studio = function ($creator, $series) {
        $request = function () use ($creator, $series) {
            $this->actingAs($creator);
            $count = e27_queries(function () use ($series) {
                $this->get(route('creator.series.index'))->assertOk();
                $this->get(route('creator.series.edit', $series->public_id))->assertOk();
            });
            auth()->logout();

            return $count;
        };
        $request();

        return $request();
    };
    $smallStudio = $studio($small, $smallSeries);
    $largeStudio = $studio($large, $largeSeries);
    expect($largeStudio)->toBe($smallStudio, 'studio query count depends on item/work count');

    // البحث والاكتشاف وملف المبدع: لا يتبعون عدد العناصر
    foreach ([
        'search' => fn () => $this->get(route('search.index', ['q' => 'سلسلة', 'type' => 'creator_series']))->assertOk(),
        'discover' => fn () => $this->get(route('discover.index'))->assertOk(),
        'creator profile' => fn () => $this->get(route('creators.show', $large->creatorProfile()->value('slug')))->assertOk(),
    ] as $name => $request) {
        expect($measure($request))->toBeLessThanOrEqual(40, $name.' is not bounded');
    }
});

test('AM15-141b: progress is a single bounded query regardless of the number of items', function () {
    $creator = e28_creator();
    $works5 = e30s_works($creator, 5);
    $works25 = e30s_works($creator, 25);
    $player = e16User();

    $svc = app(\App\Services\CreatorSeries\CreatorSeriesProgressService::class);
    $items = fn (array $works) => collect($works)->map(fn ($w) => (object) ['work_id' => $w->id, 'public_id' => $w->public_id]);

    expect(e27_queries(fn () => $svc->forUser($player, $items($works5))))->toBe(1)
        ->and(e27_queries(fn () => $svc->forUser($player, $items($works25))))->toBe(1)
        ->and(e27_queries(fn () => $svc->forUser(null, $items($works25))))->toBe(0);
});

test('AM15-142: the moderation queue is paginated and its query count does not grow with the number of pending series', function () {
    config(['creator_series.moderation_page_size' => 10]);
    $creator = e28_creator();
    $works = e30s_works($creator, 3);
    config(['creator_series.limits.max_pending' => 100, 'creator_series.limits.max_per_creator' => 100]);

    for ($i = 0; $i < 12; $i++) {
        e30s_pending($creator, $works);
    }

    $admin = e28_reviewer('administrator');
    $this->actingAs($admin);

    $page = Livewire::test(ListCreatorSeriesRevisions::class);
    $records = $page->instance()->getTableRecords();

    expect($records->count())->toBe(10)->and($records->total())->toBe(12)->and($records->hasPages())->toBeTrue();
    $page->assertSuccessful();

    $queries12 = e27_queries(fn () => Livewire::test(ListCreatorSeriesRevisions::class)->assertSuccessful());

    for ($i = 0; $i < 12; $i++) {
        e30s_pending($creator, $works);
    }

    $queries24 = e27_queries(fn () => Livewire::test(ListCreatorSeriesRevisions::class)->assertSuccessful());
    expect($queries24)->toBeLessThanOrEqual($queries12 + 1);
});

test('AM15: hot series queries use their indexes (public id, creator listing, queue, items by revision)', function () {
    if (\Illuminate\Support\Facades\DB::getDriverName() !== 'sqlite') {
        test()->markTestSkipped('E31: يعتمد على EXPLAIN QUERY PLAN الخاص بـSQLite - خطط MySQL تُفحص بـEXPLAIN محليًا (docs/mysql-production-e31.md).');
    }

    $creator = e28_creator();
    $series = e30s_published($creator);

    $plan = fn (string $sql, array $bindings = []) => collect(DB::select('EXPLAIN QUERY PLAN '.$sql, $bindings))->pluck('detail')->implode(' | ');

    expect($plan('select id from creator_series where public_id = ?', [$series->public_id]))->toContain('creator_series_public_id_unique')
        ->and($plan('select id from creator_series where slug = ?', [$series->slug]))->toContain('creator_series_slug_unique')
        ->and($plan('select id from creator_series where creator_profile_id = ? order by id', [$series->creator_profile_id]))->toContain('creator_series_owner_index')
        ->and($plan('select id from creator_series_revisions where status = ? order by submitted_at', ['pending_review']))->toContain('creator_series_revisions_queue_index')
        ->and($plan('select creator_puzzle_id from creator_series_items where creator_series_revision_id = ? order by position', [1]))->toContain('creator_series_items_position_unique')
        ->and($plan('select id from creator_series_items where creator_puzzle_id = ?', [1]))->toContain('creator_series_items_work_index');
});

test('AM15: the visibility rule is one query with indexed lookups (no scan of items by series)', function () {
    if (\Illuminate\Support\Facades\DB::getDriverName() !== 'sqlite') {
        test()->markTestSkipped('E31: يعتمد على EXPLAIN QUERY PLAN الخاص بـSQLite - خطط MySQL تُفحص بـEXPLAIN محليًا (docs/mysql-production-e31.md).');
    }

    $creator = e28_creator();
    $series = e30s_published($creator);

    $query = app(CreatorSeriesVisibilityService::class)->visibleQuery()->where('creator_series.public_id', $series->public_id);
    $sql = $query->toSql();
    $plan = collect(DB::select('EXPLAIN QUERY PLAN '.$sql, $query->getBindings()))->pluck('detail')->implode(' | ');

    expect($plan)->toContain('creator_series_public_id_unique')
        ->and($plan)->toMatch('/SEARCH csi USING (COVERING )?INDEX creator_series_items_(work|position)_unique \(creator_series_revision_id=\?\)/')   // عناصر النسخة بفهرس لا بمسح كامل
        ->and($plan)->not->toContain('SCAN csi')
        ->and(substr_count(strtolower($sql), 'select'))->toBeGreaterThan(1);   // استعلام واحد بشروط فرعية، لا حلقة PHP
});

test('AM15: creator analytics are aggregated in SQL, cached with a TTL, carry no identities and never track views', function () {
    $creator = e28_creator();
    $works = e30s_works($creator, 3);
    $series = e30s_published($creator, $works);
    $profile = $creator->creatorProfile()->first();
    $p1 = e16User();
    $p2 = e16User();
    $p3 = e16User();

    foreach ($works as $w) {
        e30s_solve($p1, $w);          // أكمل
    }
    e30s_solve($p2, $works[0]);       // بدأ فقط
    e30s_wrong($p3, $works[1]);

    $stats = app(CreatorSeriesAnalytics::class)->forCreator($profile);
    expect($stats[$series->id])->toBe(['players' => 3, 'starts' => 5, 'completions' => 1, 'completion_rate' => 33]);
    expect(app(CreatorSeriesAnalytics::class)->totals($stats))->toBe(['players' => 3, 'starts' => 5, 'completions' => 1, 'completion_rate' => 33]);

    // مخزَّنة: لاعب جديد لا يظهر قبل انتهاء الـTTL، ثم يظهر بعد مسح الكاش
    e30s_solve(e16User(), $works[2]);
    expect(app(CreatorSeriesAnalytics::class)->forCreator($profile)[$series->id]['players'])->toBe(3);
    Cache::flush();
    expect(app(CreatorSeriesAnalytics::class)->forCreator($profile)[$series->id]['players'])->toBe(4);

    // لا هويات ولا مشاهدات: لا أعمدة/جداول views ولا أسماء لاعبين في المخرجات
    expect(json_encode($stats))->not->toContain($p1->email)->not->toContain($p1->name)
        ->and(\Illuminate\Support\Facades\Schema::hasColumn('creator_series', 'views_count'))->toBeFalse();
    $this->actingAs($creator)->get(route('creator.series.index'))->assertOk()->assertSee('نسبة الإكمال');
});

test('AM15: discovery caches ids only (never titles or names) and the cache is never an authority', function () {
    $creator = e28_creator();
    $series = e30s_published($creator, null, ['title' => 'عنوان لا يجوز تخزينه بالكاش']);
    $this->get(route('discover.index'))->assertOk()->assertSee('عنوان لا يجوز تخزينه بالكاش');

    $cached = Cache::get('discover.series.newest');
    expect($cached)->toBe([$series->id])->and(json_encode($cached))->not->toContain('عنوان');

    // معرّف مخزَّن لسلسلة لم تعد ظاهرة لا يعيد إظهارها
    app(\App\Services\CreatorSeries\CreatorSeriesService::class)->archive($creator, $series->public_id);
    $this->get(route('discover.index'))->assertOk()->assertDontSee('عنوان لا يجوز تخزينه بالكاش');
});

test('AM15: beyond the partial-match limit the series search filter is a bare-column prefix LIKE on the published title and reads nothing but the published snapshot', function () {
    config(['search.partial_match_max_id' => 0]);
    $creator = e28_creator();
    e30s_published($creator, null, ['title' => 'نمط سلسلة واحد']);
    e27_cold();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $titles = e27_titles(e27_suggest($this, 'نمط', e16User()));
    $log = DB::getQueryLog();
    DB::disableQueryLog();

    $query = collect($log)->first(fn ($q) => str_contains($q['query'], 'search_tier') && str_contains($q['query'], 'from "creator_series"'));
    expect($titles)->toContain('نمط سلسلة واحد')->and($query)->not->toBeNull();

    $where = substr($query['query'], (int) strpos($query['query'], ' from "'));
    expect($where)->not->toContain('REPLACE(')->not->toContain('LOWER(')->toMatch('/like \? escape \'!\'/')
        ->and(str_contains($query['query'], 'creator_series_revisions'))->toBeFalse()   // لا ضمّ للنسخ
        ->and(str_contains($query['query'], 'description'))->toBeFalse();

    foreach ($query['bindings'] as $binding) {
        if (is_string($binding) && str_contains($binding, '%')) {
            expect($binding)->not->toStartWith('%');
        }
    }
});
`````

### `tests/Feature/DiscoveryE27/E27PerformanceTest.php`

`sha256: fa2a3f1eb04ea2b2da873e6cf7e264148d12ea358327072b28d93dfd359927f2`

`````php
<?php

require_once __DIR__.'/_support.php';

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| E27-E - الأداء. المبدأ: عدد الاستعلامات لا يتبع حجم البيانات (لا N+1)، السقوف مقيسة (لا مخمَّنة)، الرائج مخزَّن ولا يمسح أسخن جدول،
| والبحث بالبادئة قابل لاستعمال الفهرس. SQLite لا يقيس زمن MySQL؛ لذا الاختبارات هنا بنيوية (عدد، خطة، شكل SQL) لا زمنية.
*/

beforeEach(fn () => $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class));

if (! function_exists('e27_grow')) {
    /** يضيف $count عنصرًا من كل نوع قابل للبحث، كلها تطابق «نمط». */
    function e27_grow(int $from, int $to): void
    {
        $category = \App\Models\PuzzleCategory::query()->first() ?? e27_category('نمط');

        for ($i = $from + 1; $i <= $to; $i++) {
            e27_puzzle('نمط أحجية '.$i, ['puzzle_category_id' => $category->id]);
            e27_campaign('نمط حملة '.$i);
            e27_season('نمط موسم '.$i);
            e27_user(['name' => 'نمط لاعب '.$i]);
            e19Team(null, ['name' => 'نمط فريق '.$i]);
            e17Event(['title' => 'نمط منافسة '.$i]);
        }
    }

    /** @return array<string, callable> نقاط الدخول المقيسة. */
    function e27_endpoints($test, $viewer): array
    {
        return [
            'suggest guest' => fn () => $test->getJson(route('search.suggest', ['q' => 'نمط']))->assertOk(),
            'suggest player' => fn () => $test->actingAs($viewer)->getJson(route('search.suggest', ['q' => 'نمط']))->assertOk(),
            'search all (player)' => fn () => $test->actingAs($viewer)->get(route('search.index', ['q' => 'نمط']))->assertOk(),
            'search all (guest)' => fn () => $test->get(route('search.index', ['q' => 'نمط']))->assertOk(),
            'search players page' => fn () => $test->actingAs($viewer)->get(route('search.index', ['q' => 'نمط', 'type' => 'players']))->assertOk(),
            'search puzzles page' => fn () => $test->actingAs($viewer)->get(route('search.index', ['q' => 'نمط', 'type' => 'puzzles']))->assertOk(),
            'discover guest' => fn () => $test->get(route('discover.index'))->assertOk(),
            'discover player' => fn () => $test->actingAs($viewer)->get(route('discover.index'))->assertOk(),
        ];
    }
}

test('P1: query counts do not grow with the data - every endpoint runs the same number of queries on 3 rows per table as on 40 (no N+1)', function () {
    $viewer = e27_user();
    e27_grow(0, 3);
    $endpoints = e27_endpoints($this, $viewer);
    foreach ($endpoints as $run) {
        $run();   // تسخين الكاش (الرائج، مجسّ الحجم)
    }
    $small = array_map(fn ($run) => e27_queries($run), $endpoints);

    e27_grow(3, 40);
    $large = array_map(fn ($run) => e27_queries($run), $endpoints);

    expect($large)->toBe($small);
});

test('P2: measured query ceilings hold on a warm cache - search and discovery add a bounded, small number of queries over a plain page', function () {
    $viewer = e27_user();
    e27_grow(0, 12);
    $endpoints = e27_endpoints($this, $viewer);
    foreach ($endpoints as $run) {
        $run();
    }
    $baseGuest = e27_queries(fn () => $this->get('/terms')->assertOk());
    $basePlayer = e27_queries(fn () => $this->actingAs($viewer)->get('/terms')->assertOk());
    $count = fn (string $name) => e27_queries($endpoints[$name]);

    // JSON بلا إطار صفحة: مصدر لكل نوع (9 منذ E30: +1 لسلاسل المجتمع بعد +1 لأحجيات المجتمع بـE28) + تحميل التصنيف + الحظر للمسجَّل. مقيس 12 (ضيف) و12 (لاعب).
    expect($count('suggest guest'))->toBeLessThanOrEqual(12)->and($count('suggest player'))->toBeLessThanOrEqual(13);
    // صفحات كاملة: فوق قاعدة الصفحة العادية بنفس المشاهد (مقيس: ضيف +7، لاعب +7 أو أقل).
    expect($count('search all (guest)') - $baseGuest)->toBeLessThanOrEqual(12)
        ->and($count('search all (player)') - $basePlayer)->toBeLessThanOrEqual(12)
        ->and($count('search players page') - $basePlayer)->toBeLessThanOrEqual(5)
        ->and($count('search puzzles page') - $basePlayer)->toBeLessThanOrEqual(5);
    // الاكتشاف: قسم بكل استعلام محدود (مقيس 22). +1 منذ E30: قسم «سلاسل جديدة ممّن تتابعهم» (استعلام واحد محدود للمسجَّل؛ المنفرد من الكاش) - ويُقاس هنا بجلسة اللاعب الباقية من النقطة السابقة.
    expect($count('discover guest'))->toBeLessThanOrEqual(27)->and($count('discover player'))->toBeLessThanOrEqual(29);
});

test('P3: a cold cache costs a few extra queries once - then nothing until it expires', function () {
    $viewer = e27_user();
    e27_grow(0, 5);
    $endpoints = e27_endpoints($this, $viewer);

    e27_cold();
    $cold = e27_queries($endpoints['suggest guest']);
    $warm = e27_queries($endpoints['suggest guest']);
    expect($cold)->toBeLessThanOrEqual(19)->and($warm)->toBeLessThan($cold)->and($cold - $warm)->toBeLessThanOrEqual(9);   // مجسّ الحجم لكل نوع مرة واحدة (9 أنواع منذ E30)

    e27_cold();
    expect(e27_queries($endpoints['discover guest']))->toBeLessThanOrEqual(30);
});

test('P4: the cold trending computation is logarithmic in the number of attempts and the warm one is free', function () {
    $puzzle = e27_puzzle('أحجية القياس');
    $users = array_map(fn () => e27_user(), range(1, 20));
    $rows = 400;
    foreach (range(1, $rows) as $n) {
        e27_attempt($users[$n % 20], $puzzle, $n % 7 === 0, $n <= 200 ? now()->subDays(12) : now()->subHours(3), $n);
    }
    e27_cold();

    $cold = e27_queries(fn () => e27_trending()->puzzleScores());
    $warm = e27_queries(fn () => e27_trending()->puzzleScores());

    // min + max + قيمة الأخير + الاستعلام الرئيسي + خطوات البحث الثنائي (≤ log2(صفوف)) + هامش خطوة.
    expect($cold)->toBeLessThanOrEqual(5 + (int) ceil(log($rows, 2)))->and($warm)->toBe(0);
    expect(e27_trending()->puzzleScores()[$puzzle->id]['players'])->toBe(20);   // النتيجة صحيحة: الصفوف القديمة (قبل 12 يومًا) لم تُحتسب
});

test('P5: the trending query is a bounded range on the primary key - never a full scan of the attempts table', function () {
    if (\Illuminate\Support\Facades\DB::getDriverName() !== 'sqlite') {
        test()->markTestSkipped('E31: يعتمد على EXPLAIN QUERY PLAN الخاص بـSQLite - خطط MySQL تُفحص بـEXPLAIN محليًا (docs/mysql-production-e31.md).');
    }

    $puzzle = e27_puzzle('أحجية الخطة');
    $users = array_map(fn () => e27_user(), range(1, 10));
    foreach (range(1, 120) as $n) {
        e27_attempt($users[$n % 10], $puzzle, false, $n <= 60 ? now()->subDays(12) : now()->subHours(3), $n);
    }
    e27_cold();

    DB::flushQueryLog();
    DB::enableQueryLog();
    e27_trending()->puzzleScores();
    $log = DB::getQueryLog();
    DB::disableQueryLog();

    $main = collect($log)->first(fn ($q) => str_contains($q['query'], 'COUNT(DISTINCT'));
    expect($main)->not->toBeNull();
    // شكل الاستعلام: مدى على المعرّف + حد زمني + محاولات مستقلة فقط.
    expect($main['query'])->toContain('"a"."id" >= ?')->toContain('"a"."created_at" >= ?')->toContain('"a"."context_type" is null');

    $plan = collect(DB::select('EXPLAIN QUERY PLAN '.$main['query'], $main['bindings']))->pluck('detail')->all();
    $attempts = array_values(array_filter($plan, fn ($line) => str_contains($line, ' a ') || str_starts_with($line, 'SCAN a') || str_starts_with($line, 'SEARCH a')));

    expect($attempts)->not->toBe([]);
    foreach ($attempts as $line) {
        expect($line)->toStartWith('SEARCH a')->not->toContain('SCAN');   // SEARCH بفهرس/مفتاح مع شرط rowid>? = مدى، لا مسح كامل
        expect($line)->toContain('rowid>?');
    }
});

test('P6: beyond the partial-match limit the filter is a bare-column prefix LIKE - nothing wraps the column, so a name/title index can serve it', function () {
    if (\Illuminate\Support\Facades\DB::getDriverName() !== 'sqlite') {
        test()->markTestSkipped('E31: يعتمد على EXPLAIN QUERY PLAN الخاص بـSQLite - خطط MySQL تُفحص بـEXPLAIN محليًا (docs/mysql-production-e31.md).');
    }

    config(['search.partial_match_max_id' => 0]);
    e27_grow(0, 1);   // صف في كل جدول مصدر: الجدول الفارغ رخيص المسح فيبقى بالوضع الكامل عمدًا، والمقصود هنا الوضع الكبير
    e27_cold();

    DB::flushQueryLog();
    DB::enableQueryLog();
    e27_suggest($this, 'نمط', e27_user());
    $log = DB::getQueryLog();
    DB::disableQueryLog();

    $tiered = collect($log)->filter(fn ($q) => str_contains($q['query'], 'search_tier') && ! str_contains($q['query'], 'from "team_championships"') && ! str_contains($q['query'], 'from "creator_puzzles"') && ! str_contains($q['query'], 'from "creator_series"'));   // لا بطولات ولا أحجيات/سلاسل مجتمع بالبيانات: جداول فارغة (وضعها الكبير مفحوص في CreatorE28 وCreatorSeriesE30)
    expect($tiered->count())->toBe(6);
    foreach ($tiered as $q) {
        $where = substr($q['query'], (int) strpos($q['query'], ' from "'));

        expect($where)->not->toContain('REPLACE(')->not->toContain('LOWER(')->not->toContain('like ?  ')->and($where)->toMatch('/like \? escape \'!\'/');
        foreach ($q['bindings'] as $binding) {
            if (is_string($binding) && str_contains($binding, '%')) {
                expect($binding)->not->toStartWith('%');   // لا «%x» في الوضع الكبير: كل نمط بادئة
            }
        }
    }

    $puzzles = $tiered->first(fn ($q) => str_contains($q['query'], 'from "puzzles"'));
    $plan = collect(DB::select('EXPLAIN QUERY PLAN '.$puzzles['query'], $puzzles['bindings']))->pluck('detail')->implode(' | ');
    expect($plan)->toContain('puzzles_active_title_search_index');   // SQLite لا يحوّل LIKE إلى مدى (غير حساس للحالة)؛ MySQL بترتيب utf8mb4 يفعل — يُتحقق منه بـEXPLAIN محليًا (docs)
});

test('P7: the two search indexes exist, are reversible, and nothing was added to the hot attempts table or the small tables', function () {
    expect(Schema::hasIndex('users', 'users_name_search_index'))->toBeTrue()->and(Schema::hasIndex('puzzles', 'puzzles_active_title_search_index'))->toBeTrue();

    foreach (Schema::getIndexes('puzzle_attempts') as $index) {
        expect($index['columns'])->not->toContain('created_at');   // قرار E24/E27: لا فهرس زمني على أسخن جدول
    }
    foreach (['campaigns', 'seasons', 'competitive_events', 'team_championships', 'teams'] as $table) {
        foreach (Schema::getIndexes($table) as $index) {
            expect($index['name'])->not->toContain('search');
        }
    }

    $migration = require base_path('database/migrations/2026_10_19_000001_add_e27_search_indexes.php');
    $migration->down();
    expect(Schema::hasIndex('users', 'users_name_search_index'))->toBeFalse()->and(Schema::hasIndex('puzzles', 'puzzles_active_title_search_index'))->toBeFalse();
    $migration->up();
    $migration->up();   // آمن لإعادة التشغيل
    expect(Schema::hasIndex('users', 'users_name_search_index'))->toBeTrue()->and(Schema::hasIndex('puzzles', 'puzzles_active_title_search_index'))->toBeTrue();

    $source = file_get_contents(base_path('database/migrations/2026_10_19_000001_add_e27_search_indexes.php'));
    expect(substr_count($source, '->index('))->toBe(2)->and($source)->not->toContain('Schema::create(')->not->toContain('DB::')->not->toContain('->fullText(');
});

test('P8: responses are bounded however many rows match - suggest nine items, a page twelve, depth five, groups five', function () {
    e27_grow(0, 40);
    $viewer = e27_user();

    $json = e27_suggest($this, 'نمط', $viewer);
    expect(collect($json['groups'])->sum(fn ($g) => count($g['items'])))->toBeLessThanOrEqual(9)
        ->and(collect($json['groups'])->every(fn ($g) => count($g['items']) <= 3))->toBeTrue()
        ->and(strlen(json_encode($json)))->toBeLessThan(6000);

    $all = uiXpath($this->actingAs($viewer)->get(route('search.index', ['q' => 'نمط']))->assertOk()->getContent());
    foreach ($all->query('//main//section[@aria-label]') as $section) {
        expect($all->query('.//ul/li', $section)->length)->toBeLessThanOrEqual(5);
    }

    $page = uiXpath($this->actingAs($viewer)->get(route('search.index', ['q' => 'نمط', 'type' => 'puzzles']))->assertOk()->getContent());
    expect($page->query('//main//ul/li')->length)->toBe(12)->and($page->query('//a[@rel="next"]')->length)->toBe(1);

    $last = uiXpath($this->actingAs($viewer)->get(route('search.index', ['q' => 'نمط', 'type' => 'puzzles', 'page' => 5]))->assertOk()->getContent());
    expect($last->query('//a[@rel="next"]')->length)->toBe(0)->and($last->query('//main//ul/li')->length)->toBeLessThanOrEqual(12);
});

test('P9: input size is bounded before it reaches the database - a ten-thousand-character term never produces a long binding', function () {
    $viewer = e27_user();
    e27_puzzle('أحجية');

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->actingAs($viewer)->get(route('search.index', ['q' => str_repeat('أحجية ', 2000)]))->assertOk();
    $this->actingAs($viewer)->getJson(route('search.suggest', ['q' => str_repeat('ب', 10000)]))->assertOk();
    $log = DB::getQueryLog();
    DB::disableQueryLog();

    $longest = 0;
    foreach ($log as $q) {
        if (! str_contains($q['query'], 'search_tier')) {
            continue;
        }
        foreach ($q['bindings'] as $binding) {
            if (is_string($binding)) {
                $longest = max($longest, mb_strlen($binding));
            }
        }
    }

    expect($longest)->toBeGreaterThan(0)->and($longest)->toBeLessThanOrEqual(70);   // 64 حرفًا كحد أقصى + محارف النمط
});

test('P10: recommendations cost the same queries for a short history as for a long one, and the history read is capped', function () {
    $category = e27_category('تاريخ طويل');
    $short = e27_user();
    $long = e27_user();
    e27_pool($category, 12, 'POOL');

    foreach (e27_pool($category, 3, 'S') as $i => $puzzle) {
        e27_attempt($short, $puzzle, $i === 0, now()->subDays(2));
    }
    foreach (e27_pool($category, 60, 'L') as $i => $puzzle) {
        e27_attempt($long, $puzzle, $i % 3 === 0, now()->subDays(2));
    }
    e27_trending()->puzzleScores();   // تسخين

    $countShort = e27_queries(fn () => e27_reco($short));
    $countLong = e27_queries(fn () => e27_reco($long));
    expect($countLong)->toBe($countShort)->and($countLong)->toBeLessThanOrEqual(14);

    config(['search.recommendations.history_cap' => 5]);
    DB::flushQueryLog();
    DB::enableQueryLog();
    e27_reco($long);
    $history = collect(DB::getQueryLog())->first(fn ($q) => str_contains($q['query'], 'group by "puzzle_id"'));
    DB::disableQueryLog();

    expect($history['query'])->toContain('limit 5');
});
`````

### `tests/Support/TestDatabaseGuard.php`

`sha256: 19432de6abd5b7718e5c3faa15eddc43e8f4d8ce72600aefef369b8f92ca5c35`

`````php
<?php

namespace Tests\Support;

use RuntimeException;

/**
 * حاجز أمان: اختبارات RefreshDatabase تبدأ بـmigrate:fresh فتمسح القاعدة التي
 * تعمل عليها. مع config:cache يتجاهل Laravel إعدادات phpunit.xml ويستخدم قاعدة
 * .env الفعلية، فتُمسَح قاعدة التطوير. هذا الصنف يرفض التشغيل قبل أي ترحيل
 * ما لم تكن القاعدة المحلولة قاعدة اختبار صريحة. لا يحذف شيئًا أبدًا.
 */
final class TestDatabaseGuard
{
    public static function isAllowed(string $driver, ?string $database): bool
    {
        if ($database === null || trim($database) === '') {
            return false;
        }

        if ($driver === 'sqlite') {
            if ($database === ':memory:') {
                return true;
            }

            return strtolower(basename(str_replace('\\', '/', $database))) === 'testing.sqlite';
        }

        // مخدّمات أخرى: اسم القاعدة نفسه يجب أن يدل صراحةً على الاختبار (xxx_test / xxx_testing / testing).
        // E31: يُقبل أيضًا اللاحق الصريح لمحرك الاختبار (xxx_test_mysql / xxx_test_mariadb) المقترح في وثيقة MySQL، ولا يُقبل غيره (لا contest_prod ولا xxx_test_prod).
        return (bool) preg_match('/(^|_)(test|testing)(_(mysql|mariadb))?$/i', $database);
    }

    public static function assertSafe(string $driver, ?string $database, bool $configCached): void
    {
        if ($configCached) {
            throw new RuntimeException(
                'إعدادات التطبيق مخزَّنة (config:cache): الاختبارات ستعمل على قاعدتك الفعلية وتمسحها. نفّذ: php artisan config:clear'
            );
        }

        if (! self::isAllowed($driver, $database)) {
            throw new RuntimeException(
                'قاعدة بيانات الاختبار غير آمنة ('.($database ?: 'فارغة').'): يُسمَح فقط بـdatabase/testing.sqlite أو :memory: '
                .'أو قاعدة اسمها ينتهي بـ_test/_testing. لن يُنفَّذ أي ترحيل.'
            );
        }
    }
}
`````

### `tests/Unit/TestDatabaseGuardTest.php`

`sha256: 378085dfce5c2bed890fbd24c8314e9c5518a4ee0aa5732d02c5f422c9ebeb25`

`````php
<?php

use Tests\Support\TestDatabaseGuard;

test('E14.1-R/41: only explicit test databases are allowed', function (string $driver, ?string $database, bool $allowed) {
    expect(TestDatabaseGuard::isAllowed($driver, $database))->toBe($allowed);
})->with([
    'sqlite memory' => ['sqlite', ':memory:', true],
    'project testing db' => ['sqlite', 'database/testing.sqlite', true],
    'absolute windows path' => ['sqlite', 'C:\\Users\\x\\proj\\database\\testing.sqlite', true],
    'dev db_puzzle' => ['sqlite', 'db_puzzle', false],
    'default laravel db' => ['sqlite', 'database/database.sqlite', false],
    'root artifact' => ['sqlite', 'laravel11_auth', false],
    'empty path' => ['sqlite', '', false],
    'null path' => ['sqlite', null, false],
    'mysql test db' => ['mysql', 'puzzles_test', true],
    'mysql testing db' => ['mysql', 'testing', true],
    'mysql production-looking db' => ['mysql', 'puzzles', false],
    'mysql db merely containing test' => ['mysql', 'contest_prod', false],
    'E31 mysql dedicated test db' => ['mysql', 'ahjiyat_platform_test_mysql', true],
    'E31 mariadb dedicated test db' => ['mariadb', 'ahjiyat_platform_test_mariadb', true],
    'E31 testing suffix with engine' => ['mysql', 'ahjiyat_testing_mysql', true],
    'E31 working db is never a test db' => ['mysql', 'ahjiyat_platform', false],
    'E31 engine suffix alone is not enough' => ['mysql', 'ahjiyat_platform_mysql', false],
    'E31 test in the middle is not enough' => ['mysql', 'ahjiyat_test_prod', false],
]);

test('a cached config is refused before any database check (it would silently target the real DB)', function () {
    expect(fn () => TestDatabaseGuard::assertSafe('sqlite', 'database/testing.sqlite', true))
        ->toThrow(RuntimeException::class, 'config:clear');
});

test('an unsafe database is refused with a clear message and nothing is migrated', function () {
    expect(fn () => TestDatabaseGuard::assertSafe('sqlite', 'db_puzzle', false))
        ->toThrow(RuntimeException::class, 'غير آمنة');
});

test('a safe test database passes the guard', function () {
    TestDatabaseGuard::assertSafe('sqlite', 'database/testing.sqlite', false);

    expect(true)->toBeTrue();
});

test('tests/TestCase.php actually wires the guard before RefreshDatabase runs', function () {
    $source = file_get_contents(__DIR__.'/../TestCase.php');

    expect($source)->toContain('refreshApplication')->toContain('TestDatabaseGuard::assertSafe');
});
`````

---

## C) MIGRATIONS — 2 جديدة، محتوى كامل

الترحيلات التاريخية **لم تُعدَّل** (ولا ترحيلات E28/E29). الترتيب: الأول يسبق ترحيل المحافظ الذي يحذف فهرسًا يخدم مفتاحًا أجنبيًا (خطأ MySQL 1553)، والثاني يسقط الفهرس الحامي بعد زوال الحاجة إليه. كلاهما **لا أثر له على SQLite** (يخرجان مبكرًا)، و idempotent (يفحصان وجود الفهرس قبل العمل)، و`down()` في كل منهما يعكس `up()`.

ثم `php artisan migrate` يشغّلهما بالترتيب الزمني تلقائيًا.

### `database/migrations/2026_09_23_000002_e31_guard_wallets_user_fk_index.php`

`sha256: 19ed64ba5f15e0a4a02482ee6c3b5b6ce70f6988135f6065ed4105a74a2a6e7a`

`````php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E31: ترحيل ما قبل الإصلاح لـMySQL/MariaDB (لا يفعل شيئًا على SQLite ولا على أي قاعدة تعمل حاليًا).
 *
 * المشكلة: الترحيل 2026_09_23_000003_add_currency_id_to_wallets_table يحذف الفهرس الفريد wallets_user_id_unique(user_id) ثم يضيف
 * unique(user_id, currency_id) في جملة لاحقة. على MySQL هذا الفهرس هو الوحيد الذي يخدم المفتاح الأجنبي wallets_user_id_foreign،
 * فيفشل DROP INDEX بالخطأ 1553 (Cannot drop index needed in a foreign key constraint) ويتوقف migrate من الصفر عند هذه النقطة.
 *
 * الحل بلا تعديل الترحيل القديم: فهرس عادي مؤقت على user_id يضمنه هذا الترحيل قبل ذلك الترحيل (اسمه يسبقه أبجديًا ضمن نفس اليوم)،
 * فيجد MySQL فهرسًا بديلًا يخدم المفتاح الأجنبي أثناء حذف الفريد. ثم يحذفه ترحيل لاحق (2026_10_23_000001) بعد أن يصير unique(user_id, currency_id) موجودًا.
 */
return new class extends Migration
{
    public const GUARD_INDEX = 'wallets_user_id_e31_fk_index';

    public function up(): void
    {
        $connection = Schema::getConnection();

        if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        if (! $connection->pretending() && (! Schema::hasTable('wallets') || Schema::hasIndex('wallets', self::GUARD_INDEX))) {
            return;
        }

        Schema::table('wallets', function (Blueprint $table) {
            $table->index('user_id', self::GUARD_INDEX);
        });
    }

    public function down(): void
    {
        $connection = Schema::getConnection();

        if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        if (! $connection->pretending() && ! Schema::hasIndex('wallets', self::GUARD_INDEX)) {
            return;
        }

        Schema::table('wallets', function (Blueprint $table) {
            $table->dropIndex(self::GUARD_INDEX);
        });
    }
};
`````

### `database/migrations/2026_10_23_000001_e31_drop_wallets_user_guard_index.php`

`sha256: 8674ee67a62387eb11e4faa09371f4fda8a21d03a500fefa3288425dd581b3a1`

`````php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E31: يزيل الفهرس المؤقت الذي أضافه 2026_09_23_000002_e31_guard_wallets_user_fk_index (MySQL/MariaDB فقط).
 * بعد الترحيل 2026_09_23_000003 صار unique(user_id, currency_id) يخدم المفتاح الأجنبي على user_id (user هو أول أعمدته)، فالفهرس المؤقت زائد
 * (يبطّئ الكتابة بلا فائدة). لا يُحذف إلا إذا وُجد فهرس آخر يبدأ بـuser_id، حتى لا يُترك المفتاح الأجنبي بلا فهرس (الخطأ 1553).
 * لا شيء على SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        $connection = Schema::getConnection();

        if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        $guard = 'wallets_user_id_e31_fk_index';

        if (! $connection->pretending()) {
            if (! Schema::hasTable('wallets')) {
                return;
            }

            $indexes = Schema::getIndexes('wallets');
            $hasGuard = collect($indexes)->contains(fn (array $index) => $index['name'] === $guard);
            $covered = collect($indexes)->contains(fn (array $index) => $index['name'] !== $guard && ($index['columns'][0] ?? null) === 'user_id');

            if (! $hasGuard || ! $covered) {
                return;
            }
        }

        Schema::table('wallets', function (Blueprint $table) use ($guard) {
            $table->dropIndex($guard);
        });
    }

    public function down(): void
    {
        $connection = Schema::getConnection();

        if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        if (! $connection->pretending() && Schema::hasIndex('wallets', 'wallets_user_id_e31_fk_index')) {
            return;
        }

        Schema::table('wallets', function (Blueprint $table) {
            $table->index('user_id', 'wallets_user_id_e31_fk_index');
        });
    }
};
`````

---

## D) ENV / CONFIG — الأسطر الدقيقة

`.env.example` (ملف معدَّل في القسم B) يعرض الآن MySQL قاعدةً أساسية بكلمة مرور فارغة ومستخدم تطبيق مخصص. **ملف `.env` الحقيقي عندك لا يُستبدل**؛ عدّله يدويًا بهذه الأسطر عند الانتقال لـMySQL:

```dotenv
# ── .env المحلي (ليس في المستودع) للعمل بـMySQL ──
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=ahjiyat_platform
DB_USERNAME=ahjiyat_app
DB_PASSWORD=                # ضع قيمتك الحقيقية في .env المحلي فقط؛ لا تدخل المستودع ولا أي مخرجات ترسلها لي
DB_TIMEZONE=+00:00
# DB_ENGINE=InnoDB          # اختياري؛ InnoDB هو الافتراضي في config/database.php

# ── أسطر E31 الاختيارية (كلها معلَّقة بـ# وافتراضياتها آمنة؛ كما في .env.example) ──
# OPS_ALLOW_SQLITE_PRODUCTION=false          # true: يحوّل فشل production:check على SQLite إلى تحذير (غير مستحسن؛ MySQL هي المعتمدة)
# MIGRATION_TARGET_CONNECTION=mysql          # اتصال الهدف لأمر db:transfer-sqlite-to-mysql (محلي فقط، يرفض الإنتاج)
# MIGRATION_SQLITE_DATABASE=                 # مسار ملف SQLite المصدر (أو مرِّره بـ--source)
# MIGRATION_MYSQL_HOST=                      # تجاوزات الهدف إن أردت إبقاء DB_* على SQLite أثناء النقل
# MIGRATION_MYSQL_PORT=
# MIGRATION_MYSQL_DATABASE=
# MIGRATION_MYSQL_USERNAME=
# MIGRATION_MYSQL_PASSWORD=
# MIGRATION_BATCH_SIZE=1000                  # صفوف لكل دفعة (500..2000)
```

* قيمة `default` في `config/database.php` بقيت `env('DB_CONNECTION', 'sqlite')` عمدًا (بيئة بلا `DB_CONNECTION` تسلك كما كانت)، و`phpunit.xml.dist` يثبّت `sqlite` للاختبارات اليومية. MySQL يُفعَّل بـ`.env` أو بـ`phpunit.mysql.xml.dist`.
* `config/database.php`: مفتاحا `engine` (=`InnoDB` افتراضيًا) و`timezone` (=`+00:00`) في اتصالَي mysql وmariadb. `config/operations.php`: مفتاح `allow_sqlite_production`. `config/db_transfer.php`: ملف جديد (إعدادات أمر النقل).
* لا مفتاح بيئة يضعف حاجزًا أمنيًا: أمر النقل يرفض `APP_ENV=production` دائمًا بلا تجاوز.

---

## E) LOCAL MYSQL SETUP COMMANDS (PowerShell)

> غيّر أي نص بين `<...>` بقيمتك. لا تضع كلمات المرور في ملفات المستودع. تجنّب علامة `'` داخل كلمات المرور (تدخل في جملة SQL).

### E.1 خادم MySQL 8 عبر Docker Desktop

```powershell
$rootPwd = Read-Host 'كلمة مرور root المحلية (جديدة)'
$appPwd  = Read-Host 'كلمة مرور ahjiyat_app (جديدة)'

docker run --name ahjiyat-mysql -e "MYSQL_ROOT_PASSWORD=$rootPwd" -p 3306:3306 -d mysql:8.0 --character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_ci

# انتظر حتى يرد (كرّر حتى يطبع mysqld is alive)
$env:MYSQL_PWD = $rootPwd
docker exec -e MYSQL_PWD ahjiyat-mysql mysqladmin ping -uroot --silent
```

بديل: MySQL Installer 8.0.16+ (تأكد `mysql --version`). XAMPP (MariaDB غالبًا) **غير مُتحقَّق منه** في E31.

### E.2 القاعدتان والمستخدم

```powershell
$sql = @'
CREATE DATABASE IF NOT EXISTS ahjiyat_platform CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS ahjiyat_platform_test_mysql CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'ahjiyat_app'@'%' IDENTIFIED BY '__APP_PWD__';
GRANT ALL PRIVILEGES ON ahjiyat_platform.* TO 'ahjiyat_app'@'%';
GRANT ALL PRIVILEGES ON `ahjiyat\_platform\_test%`.* TO 'ahjiyat_app'@'%';
FLUSH PRIVILEGES;
'@
$sql = $sql.Replace('__APP_PWD__', $appPwd)

$env:MYSQL_PWD = $rootPwd
$sql | docker exec -i -e MYSQL_PWD ahjiyat-mysql mysql -uroot --default-character-set=utf8mb4
Remove-Item Env:\MYSQL_PWD
```

(الصيغة الأحادية `@' ... '@` ضرورية: داخل `@" ... "@` تُفسَّر علامة `` ` `` كحرف هروب في PowerShell.) القاعدة الثانية **للاختبارات فقط**: `RefreshDatabase` يحذف جداولها كل مرة، واسمها يجب أن ينتهي بـ`_test` أو `_testing` أو `_test_mysql` وإلا يرفض `TestDatabaseGuard` التشغيل.

### E.3 تفعيل التعريف وتجهيز الجلسة (لا يمسّ `.env`)

```powershell
php -m | Select-String pdo_mysql          # يجب أن يظهر pdo_mysql، وإلا فعّل extension=pdo_mysql في php.ini
php -v

# متغيرات هذه الجلسة فقط (تتقدم على .env)
$env:DB_CONNECTION='mysql'; $env:DB_HOST='127.0.0.1'; $env:DB_PORT='3306'
$env:DB_DATABASE='ahjiyat_platform'; $env:DB_USERNAME='ahjiyat_app'; $env:DB_PASSWORD=$appPwd; $env:DB_TIMEZONE='+00:00'
```

### E.4 بناء المخطط على قاعدة MySQL **فارغة** ثم المقارنة

```powershell
php artisan migrate --force
php artisan db:parity --runtime          # قراءة فقط؛ المتوقع: Result: PASS (خروج 0)
```

**PENDING USER-LOCAL MYSQL VALIDATION:** نجاح `migrate` فعليًا على MySQL و`db:parity --runtime` بخروج 0. أرسل المخرجات إن فشل أي منهما.

---

## F) SQLITE → MYSQL TRANSFER COMMANDS

أداة محلية/تجريبية لمرة واحدة؛ **ترفض `APP_ENV=production` دائمًا**. جرّب أولًا على **نسخة** من بياناتك (بروفة)، ولا تلمس ملف SQLite الأصلي.

```powershell
# 1) بروفة على نسخة: يُفضَّل إيقاف التطبيق/الطابور قبل النسخ كي لا يكون الملف في منتصف كتابة
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$src   = "database\rehearsal-$stamp.sqlite"      # المصدر الذي ينقل منه الأمر (نسخة)
$bak   = "database\backup-$stamp.sqlite"         # النسخة الاحتياطية المطابقة بايتًا
Copy-Item database\database.sqlite $src
Copy-Item $src $bak
(Get-FileHash $src -Algorithm SHA256).Hash
(Get-FileHash $bak -Algorithm SHA256).Hash         # يجب أن تتطابق البصمتان قبل المتابعة

# 2) الهدف: قاعدة MySQL جديدة فارغة + migrate (القسم E.4) وجلستك تحمل $env:DB_* الخاصة بـMySQL

# 3) تجربة بلا أي كتابة: تطبع الخطة والمشكلات (أعدادًا فقط)
php artisan db:transfer-sqlite-to-mysql --source=$src --dry-run

# 4) النقل الفعلي + التحقق (بصمات المحتوى، أيتام FK، AUTO_INCREMENT، مجاميع المال)
php artisan db:transfer-sqlite-to-mysql --source=$src --force --backup=$bak --report="$env:TEMP\e31-transfer-report.json"
```

* ينتهي بـ`Result: PASS|FAIL` والخروج 0 فقط عند PASS **ومصدر لم يتغير**؛ 1 عند فشل؛ 2 يلزم `--force`/`--backup`.
* بعد فشل جزئي: الأسلم إسقاط قاعدة الهدف وإعادتها وتشغيل `migrate`؛ وإلا: `--reset-target --confirm-database=<اسم الهدف> --force --backup=$bak`.
* أرسل لي `Result` والتقرير (`$env:TEMP\e31-transfer-report.json`): فيه أسماء جداول وأعدادًا فقط، لا قيم صفوف ولا أسرار.
* الانتقال الحقيقي (12 خطوة + التراجع): `docs/mysql-production-e31.md` القسم 9.

**PENDING USER-LOCAL MYSQL VALIDATION:** تشغيل `--dry-run` ثم النقل على نسخة من بياناتك الحقيقية.

---

## G) VALIDATION COMMANDS

> مسارات صريحة فقط. لا `php artisan test` بلا مسار ولا تشغيل المجموعة الكاملة. (للمطابقة مع MySQL استعمل `php vendor/bin/pest --configuration=...`؛ `php artisan test --configuration` يتعارض.)

### G.1 على SQLite (سريع، نفذته هنا؛ كرّره عندك بعد النسخ)

```powershell
php artisan db:parity --static                                   # بلا قاعدة؛ المتوقع PASS: 118 ترحيلًا، 697 جملة، 0 أخطاء ترجمة، 0 FAIL
php vendor/bin/pest tests/Feature/DatabaseE31 tests/Unit/TestDatabaseGuardTest.php   # المتوقع: 88 ناجحًا + 14 تخطٍّ MySQL-only
php vendor/bin/pest tests/Feature/SecurityE26 tests/Feature/OperationsE25 tests/Feature/Production
```

### G.2 على MySQL — الدليل المطلوب لإغلاق E31 (**PENDING USER-LOCAL MYSQL VALIDATION**)

```powershell
# الأمر الواحد: كل المجموعات بمسارات صريحة ثم db:parity (قراءة فقط)
powershell -ExecutionPolicy Bypass -File tools\e31\run-mysql-matrix.ps1 -Username ahjiyat_app -Password $appPwd

# أو مجموعة واحدة:
php vendor/bin/pest --configuration=phpunit.mysql.xml.dist tests/Feature/DatabaseE31 tests/Unit/TestDatabaseGuardTest.php
php vendor/bin/pest --configuration=phpunit.mysql.xml.dist tests/Feature/Economy tests/Feature/GemWalletServiceTest.php
php vendor/bin/pest --configuration=phpunit.mysql.xml.dist tests/Feature/Store

# على القاعدة المبنية
php artisan db:parity --connection=mysql --runtime
php artisan production:check            # انظر أسطر القاعدة فقط (نسخة، صرامة، ترميز، محرك، منطقة)
```

المتوقع: كل مجموعة `PASS`، واختبارات `M-1..M-13` و`W-9` **تعمل** (لا تتخطى) على MySQL، وأن خمسة اختبارات `EXPLAIN QUERY PLAN` القديمة (E27 P5/P6، E28 X9، E30 AM15 ×2) تتخطى بوضوح.

### G.3 تدقيق EXPLAIN على بيانات حقيقية (**PENDING USER-LOCAL MYSQL VALIDATION**)

```powershell
$env:MYSQL_PWD = $appPwd
Get-Content tools\e31\explain-audit.sql -Raw -Encoding UTF8 | mysql --default-character-set=utf8mb4 -u ahjiyat_app ahjiyat_platform
Remove-Item Env:\MYSQL_PWD
```

### G.4 فحص `/ready` على MySQL (اختياري، **PENDING USER-LOCAL MYSQL VALIDATION**)

```powershell
# نافذة 1:  php artisan serve        نافذة 2:
Invoke-RestMethod http://127.0.0.1:8000/ready
```

### G.5 تحقق بصمات الملفات بعد النسخ

```powershell
Get-Content "$env:TEMP\e31\manifest-sha256.txt" | ForEach-Object {
    $h, $p = $_ -split '\s+', 2
    $a = (Get-FileHash -Algorithm SHA256 -LiteralPath $p).Hash.ToLower()
    if ($a -eq $h) { "OK   $p" } else { "DIFF $p" }
}
```

### G.6 ما أريد استلامه منك لإغلاق E31
1. مخرجات `run-mysql-matrix.ps1` (جدول PASS/FAIL + `db:parity`).
2. `Result` وتقرير `--dry-run` والنقل الفعلي على نسخة بياناتك.
3. أي سطر من `explain-audit.sql` فيه `type=ALL` أو `Using filesort/temporary` بصفوف كثيرة.
(لا ترسل كلمات مرور ولا قيم صفوف.)

---
**انتهى E31 — STOP. لم تبدأ E32.**
