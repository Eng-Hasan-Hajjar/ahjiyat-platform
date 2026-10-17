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
