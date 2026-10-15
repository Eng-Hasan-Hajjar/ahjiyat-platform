<?php

namespace App\Services\Operations;

use App\Support\ReleaseInfo;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use ParseError;
use Throwable;

/**
 * E25 - فحص ما قبل النشر (release:preflight): كل فحوص production:check + فحوص خاصة بقابلية نشر هذا الإصدار تحديدًا.
 * للقراءة فقط بصرامة: لا migrate ولا clear ولا restart ولا كتابة قاعدة/كاش/ملفات (ترجمة Blade تتم بالذاكرة عبر compileString دون كتابة ملفات مترجمة).
 */
final class PreflightChecker
{
    /** حد سقف لعدد أسماء الملفات المسرودة بالرسائل. */
    private const LIST_CAP = 10;

    public function __construct(
        private readonly ProductionChecker $production,
        private readonly MigrationState $migrations,
        private readonly MigrationRiskScanner $scanner,
    ) {}

    public function run(bool $skipViews = false, ?bool $production = null): CheckReport
    {
        $report = $this->production->run($production);
        $extra = new CheckReport($report->isProduction());
        $isProduction = $report->isProduction();

        $this->pendingMigrations($extra, $isProduction);
        $this->cacheFreshness($extra);
        $skipViews ? $extra->add(new CheckResult(CheckResult::INFO, 'قوالب Blade', 'views', 'تخطّي فحص ترجمة القوالب (--skip-views)')) : $this->views($extra);
        $this->maintenance($extra);
        $this->lockfiles($extra);
        $this->buildFreshness($extra);
        $this->attestations($extra);

        $report->merge($extra);

        return $report;
    }

    private function pendingMigrations(CheckReport $report, bool $production): void
    {
        $g = 'الترحيلات المعلّقة (سياسة docs/release-engineering.md)';
        $state = $this->migrations->inspect();

        if (! $state['repository'] || $state['pending'] === []) {
            return; // الحالة العامة تغطيها production:check
        }

        $names = $state['pending'];
        $report->add(new CheckResult(CheckResult::INFO, $g, 'migrations', count($names).' ترحيل سيُنفَّذ بـmigrate --force: '.implode(', ', array_slice($names, 0, self::LIST_CAP)).(count($names) > self::LIST_CAP ? ' …' : '')));

        foreach ($names as $name) {
            $path = $state['paths'][$name] ?? null;
            $source = is_string($path) && is_file($path) ? (string) file_get_contents($path) : '';

            foreach ($this->scanner->scan($source) as $code) {
                $breaking = $this->scanner->isBreaking($code);
                $report->add(new CheckResult(
                    $breaking ? CheckResult::WARN : CheckResult::INFO,
                    $g,
                    $name,
                    $name.': '.$this->scanner->describe($code).($breaking ? ' - يلزم مراجعة بشرية وخطة توافق (additive-first) قبل النشر' : ''),
                ));
            }
        }

        $report->add(new CheckResult(CheckResult::WARN, $g, 'backup', 'خذ نسخة احتياطية مُتحقَّقًا منها قبل migrate --force (tools/backup/db-backup.sh)'));
    }

    private function cacheFreshness(CheckReport $report): void
    {
        $g = 'كاش التهيئة';
        $app = app();

        $pairs = [
            'config' => [$app->getCachedConfigPath(), array_merge(glob(config_path('*.php')) ?: [], array_filter([base_path('.env')], 'is_file'))],
            'routes' => [$app->getCachedRoutesPath(), glob(base_path('routes/*.php')) ?: []],
        ];

        foreach ($pairs as $name => [$cached, $sources]) {
            if (! is_file($cached)) {
                $report->add(new CheckResult(CheckResult::INFO, $g, $name, "كاش {$name} غير مبني - سيُبنى بـphp artisan optimize بعد النشر"));

                continue;
            }

            $newest = max(array_map(fn (string $f) => (int) @filemtime($f), $sources) ?: [0]);

            (int) filemtime($cached) >= $newest
                ? $report->add(new CheckResult(CheckResult::PASS, $g, $name, "كاش {$name} أحدث من مصادره"))
                : $report->add(new CheckResult(CheckResult::WARN, $g, $name, "كاش {$name} أقدم من مصدره (.env/config أو routes) - أعد بناءه: php artisan optimize (وإلا يخدم النظام إعدادًا قديمًا)"));
        }
    }

    private function views(CheckReport $report): void
    {
        $g = 'قوالب Blade';
        $dir = resource_path('views');

        if (! is_dir($dir)) {
            return;
        }

        $bad = [];
        $count = 0;

        foreach (File::allFiles($dir) as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $count++;

            try {
                $php = Blade::compileString((string) file_get_contents($file->getPathname()));
                token_get_all($php, TOKEN_PARSE);
            } catch (ParseError|Throwable) {
                $bad[] = str_replace('\\', '/', $file->getRelativePathname());
            }
        }

        $bad === []
            ? $report->add(new CheckResult(CheckResult::PASS, $g, 'views', "{$count} قالب Blade يُترجَم بلا أخطاء نحوية (فحص بالذاكرة)"))
            : $report->add(new CheckResult(CheckResult::FAIL, $g, 'views', count($bad).' قالب لا يُترجَم: '.implode(', ', array_slice($bad, 0, self::LIST_CAP))));
    }

    private function maintenance(CheckReport $report): void
    {
        app()->isDownForMaintenance()
            ? $report->add(new CheckResult(CheckResult::WARN, 'وضع الصيانة', 'maintenance', 'التطبيق في وضع الصيانة الآن (php artisan down) - تأكد أنه مقصود؛ العمال لا يسحبون مهامًا والمجدول يتخطى المهام أثناء الصيانة'))
            : $report->add(new CheckResult(CheckResult::PASS, 'وضع الصيانة', 'maintenance', 'التطبيق ليس في وضع الصيانة'));
    }

    private function lockfiles(CheckReport $report): void
    {
        $g = 'ملفات القفل (مصدر الإصدارات)';

        foreach (['composer.lock', 'package-lock.json'] as $file) {
            is_file(base_path($file))
                ? $report->add(new CheckResult(CheckResult::PASS, $g, $file, "{$file} موجود - النشر يعتمد عليه (لا composer update ولا npm update بالإنتاج)"))
                : $report->add(new CheckResult(CheckResult::FAIL, $g, $file, "{$file} غير موجود - لا نشر بإصدارات غير مثبّتة"));
        }
    }

    private function buildFreshness(CheckReport $report): void
    {
        $g = 'أصول الواجهة (Vite)';
        $manifest = public_path('build/manifest.json');

        if (! is_file($manifest)) {
            return; // تغطيه production:check
        }

        $newest = 0;

        foreach ([resource_path('js'), resource_path('css')] as $dir) {
            if (! is_dir($dir)) {
                continue;
            }

            foreach (File::allFiles($dir) as $file) {
                $newest = max($newest, (int) $file->getMTime());
            }
        }

        foreach (['vite.config.js', 'package.json', 'tailwind.config.js'] as $file) {
            if (is_file(base_path($file))) {
                $newest = max($newest, (int) filemtime(base_path($file)));
            }
        }

        (int) filemtime($manifest) >= $newest
            ? $report->add(new CheckResult(CheckResult::PASS, $g, 'public/build', 'الأصول المبنية أحدث من مصدرها'))
            : $report->add(new CheckResult(CheckResult::WARN, $g, 'public/build', 'الأصول المبنية أقدم من resources/js|css - أعد البناء: npm ci && npm run build'));
    }

    private function attestations(CheckReport $report): void
    {
        $g = 'تأكيدات يدوية (لا يمكن فحصها آليًا)';

        foreach ([
            ['backup', 'نسخة احتياطية حديثة للقاعدة والملفات، ومُجرَّبة الاستعادة (docs/backup-disaster-recovery.md)'],
            ['rollback', 'الإصدار السابق محفوظ وقابل للرجوع إليه، ومعرَّف: '.(ReleaseInfo::short() ?? 'APP_RELEASE غير مضبوط')],
            ['maintenance', 'قرار وضع الصيانة: فقط إن كان الترحيل غير متوافق مع الكود السابق (docs/operations-runbook.md)'],
        ] as [$setting, $text]) {
            $report->add(new CheckResult(CheckResult::INFO, $g, $setting, $text));
        }
    }
}
