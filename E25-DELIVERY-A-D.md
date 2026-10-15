# حزمة تسليم E25 — التشغيل بالإنتاج، التعافي من الكوارث، هندسة الإصدارات، تصليب الأمان

> للنسخ اليدوي إلى مشروعك المحلي. **لا أوامر git هنا (NO GIT COMMANDS).** لم يُلمس `PlatformSettingsPage.php` ولا `AdminPanelProvider.php`.
> الأسهل: فكّ `e25-operations.zip` فوق جذر المشروع (يحتفظ بالمسارات)، ثم نفّذ القسم E. الأقسام أدناه هي نفس المحتوى للمراجعة أو النسخ الفردي.

ملخص: 45 ملف جديد، 16 ملف معدَّل (+ `.env.example`)، 0 ترحيلات، 0 حزم جديدة.

## A) ملفات جديدة (محتوى كامل)

### `.github/workflows/ci.yml`

```yaml
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
```

### `app/Console/Commands/Concerns/RendersCheckReport.php`

```php
<?php

namespace App\Console\Commands\Concerns;

use App\Services\Operations\CheckReport;
use App\Services\Operations\CheckResult;
use App\Support\SecretRedactor;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * E25 - عرض موحّد لتقارير الفحص: PASS/WARN/FAIL/INFO + اسم الإعداد، بلا قيم سرية.
 * كل نص يمرّ على SecretRedactor قبل الطباعة (طبقة أمان ثانية فوق كون الرسائل خالية من القيم أصلًا).
 */
trait RendersCheckReport
{
    protected function renderReport(CheckReport $report, bool $json): void
    {
        $secrets = SecretRedactor::secrets();

        if ($json) {
            $data = $report->toArray();
            $data['checks'] = array_map(fn (array $check) => array_map(fn ($v) => SecretRedactor::scrub((string) $v, $secrets), $check), $data['checks']);

            $this->line((string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

            return;
        }

        $colors = [CheckResult::PASS => 'green', CheckResult::WARN => 'yellow', CheckResult::FAIL => 'red', CheckResult::INFO => 'cyan'];

        foreach ($report->grouped() as $group => $results) {
            $this->comment(SecretRedactor::scrub((string) $group, $secrets));

            foreach ($results as $result) {
                $message = OutputFormatter::escape(SecretRedactor::scrub($result->message, $secrets));
                $this->line("  <fg={$colors[$result->status]}>{$result->status}</> {$message}");
            }
        }
    }

    protected function renderSummary(CheckReport $report): void
    {
        $this->newLine();
        $this->info("النتيجة: {$report->failures()} فشل حرج، {$report->warnings()} تحذير.");
    }
}
```

### `app/Console/Commands/ReleasePreflight.php`

```php
<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RendersCheckReport;
use App\Services\Operations\PreflightChecker;
use Illuminate\Console\Command;

/**
 * E25: فحص ما قبل النشر. production:check + الترحيلات المعلّقة (مع مسح مخاطر ساكن)، ترجمة Blade، حداثة كاش config/routes والأصول، وضع الصيانة، ملفات القفل.
 * لا يُغيّر شيئًا إطلاقًا (لا migrate، لا clear، لا restart). Exit 0 = قابل للنشر، 1 = FAIL حاجب.
 */
class ReleasePreflight extends Command
{
    use RendersCheckReport;

    protected $signature = 'release:preflight {--json : مخرجات JSON} {--skip-views : تخطّي فحص ترجمة قوالب Blade (أسرع)}';

    protected $description = 'فحص ما قبل النشر (Read-Only): هل هذا الإصدار قابل للنشر؟ لا ينفّذ migrate ولا يمسح ولا يعيد تشغيل شيء';

    public function handle(PreflightChecker $checker): int
    {
        $json = (bool) $this->option('json');

        if (! $json) {
            $this->info('== فحص ما قبل النشر (Read-Only، لا يغيّر شيئًا) ==');
            $this->newLine();
        }

        $report = $checker->run((bool) $this->option('skip-views'));
        $this->renderReport($report, $json);

        if (! $json) {
            $this->renderSummary($report);
            $report->hasFailures()
                ? $this->error('غير جاهز للنشر: عالج حالات FAIL أعلاه ثم أعد الفحص.')
                : $this->info('جاهز للنشر (راجع التحذيرات والتأكيدات اليدوية أعلاه).');
        }

        return $report->hasFailures() ? self::FAILURE : self::SUCCESS;
    }
}
```

### `app/Console/Commands/ReleaseSmoke.php`

```php
<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RendersCheckReport;
use App\Services\Operations\SmokeChecker;
use Illuminate\Console\Command;

/**
 * E25: فحص الدخان بعد النشر - طلبات GET فقط على المسارات العامة (انظر config/operations.php smoke.routes) بمهلة صغيرة.
 * لا كتابة: لا صداقة/محاولة أحجية/رسالة/محفظة. Exit 0 = كل المسارات 200 (بلا FAIL)، 1 = فشل.
 */
class ReleaseSmoke extends Command
{
    use RendersCheckReport;

    protected $signature = 'release:smoke
        {--url= : العنوان الأساسي (الافتراضي APP_URL)}
        {--profile= : public_id لاعب عام لفحص /players/{profile} (اختياري)}
        {--expect-release= : يتحقق أن /ready يعرض هذا المعرّف (يكشف نشرًا لم يُفعَّل بعد)}
        {--json : مخرجات JSON}';

    protected $description = 'فحص دخان بعد النشر (GET فقط، بلا كتابة): /up و/ready والرئيسية وتسجيل الدخول والأحجيات والمنافسات والمتجر';

    public function handle(SmokeChecker $checker): int
    {
        $json = (bool) $this->option('json');
        $url = rtrim((string) ($this->option('url') ?: config('app.url')), '/');
        $profile = $this->option('profile') ? (string) $this->option('profile') : null;
        $expect = $this->option('expect-release') ? (string) $this->option('expect-release') : null;

        if (! $json) {
            $this->info('== فحص الدخان بعد النشر (GET فقط) ==');
            $this->newLine();
        }

        $report = $checker->run($url, $profile, $expect);
        $this->renderReport($report, $json);

        if (! $json) {
            $this->renderSummary($report);
        }

        return $report->hasFailures() ? self::FAILURE : self::SUCCESS;
    }
}
```

### `app/Services/Operations/CheckReport.php`

```php
<?php

namespace App\Services\Operations;

/**
 * E25 - مجموعة نتائج الفحص. تُحوَّل لنص/JSON بعد تمريرها على SecretRedactor في الأوامر.
 * القاعدة الوحيدة للخروج: أي FAIL => الإصدار/البيئة غير جاهز (exit 1)؛ WARN وINFO لا يمنعان.
 */
final class CheckReport
{
    /** @var list<CheckResult> */
    private array $results = [];

    public function __construct(private readonly bool $production = false) {}

    public function add(CheckResult $result): void
    {
        $this->results[] = $result;
    }

    public function merge(self $other): void
    {
        foreach ($other->results() as $result) {
            $this->results[] = $result;
        }
    }

    /** @return list<CheckResult> */
    public function results(): array
    {
        return $this->results;
    }

    public function isProduction(): bool
    {
        return $this->production;
    }

    public function count(string $status): int
    {
        return count(array_filter($this->results, fn (CheckResult $r) => $r->status === $status));
    }

    public function failures(): int
    {
        return $this->count(CheckResult::FAIL);
    }

    public function warnings(): int
    {
        return $this->count(CheckResult::WARN);
    }

    public function hasFailures(): bool
    {
        return $this->failures() > 0;
    }

    /** @return array<string, list<CheckResult>> */
    public function grouped(): array
    {
        $groups = [];

        foreach ($this->results as $result) {
            $groups[$result->group][] = $result;
        }

        return $groups;
    }

    /** @return array{production: bool, ready: bool, summary: array<string, int>, checks: list<array<string, string>>} */
    public function toArray(): array
    {
        return [
            'production' => $this->production,
            'ready' => ! $this->hasFailures(),
            'summary' => [
                'pass' => $this->count(CheckResult::PASS),
                'warn' => $this->warnings(),
                'fail' => $this->failures(),
                'info' => $this->count(CheckResult::INFO),
            ],
            'checks' => array_map(fn (CheckResult $r) => $r->toArray(), $this->results),
        ];
    }
}
```

### `app/Services/Operations/CheckResult.php`

```php
<?php

namespace App\Services\Operations;

/**
 * E25 - نتيجة فحص واحدة. الرسالة تحمل اسم الإعداد (APP_KEY…) ولا تحمل قيمة سرية أبدًا.
 */
final class CheckResult
{
    public const PASS = 'PASS';

    public const WARN = 'WARN';

    public const FAIL = 'FAIL';

    public const INFO = 'INFO';

    public function __construct(
        public readonly string $status,
        public readonly string $group,
        public readonly string $setting,
        public readonly string $message,
    ) {}

    /** @return array{status: string, group: string, setting: string, message: string} */
    public function toArray(): array
    {
        return ['status' => $this->status, 'group' => $this->group, 'setting' => $this->setting, 'message' => $this->message];
    }
}
```

### `app/Services/Operations/MigrationRiskScanner.php`

```php
<?php

namespace App\Services\Operations;

/**
 * E25 - ماسح مخاطر ترحيل (heuristic ساكن، للقراءة فقط) لتطبيق سياسة docs/release-engineering.md على الترحيلات المعلّقة قبل النشر.
 * يفحص جسم up() فقط (down() يحتوي حذفًا بطبيعته). نتيجته تحذير للمراجعة البشرية وليس حكمًا: ترحيل «خطر» قد يكون صحيحًا بعد مراجعة وقياس على staging.
 */
final class MigrationRiskScanner
{
    /** رمز الخطر => [نمط، شرح عربي قصير] */
    private const RULES = [
        'drop_column' => ['/->(dropColumn|dropColumns)\s*\(/', 'حذف عمود (غير متوافق مع الإصدار السابق للكود)'],
        'rename_column' => ['/->(renameColumn|renameIndex)\s*\(|Schema::rename\s*\(/', 'إعادة تسمية عمود/جدول (يكسر الإصدار السابق فورًا)'],
        'change_type' => ['/->change\s*\(\s*\)/', 'تغيير نوع/خصائص عمود (قد يعيد بناء الجدول ويقفله)'],
        'drop_table' => ['/Schema::(dropIfExists|drop|dropAllTables|dropAllViews)\s*\(/', 'حذف جدول'],
        'drop_constraint' => ['/->(dropIndex|dropUnique|dropPrimary|dropForeign|dropFullText|dropSpatialIndex)\s*\(/', 'حذف فهرس/قيد'],
        'add_index' => ['/->(index|unique|fullText|spatialIndex)\s*\(/', 'إضافة فهرس (قِس زمنه على نسخة staging بحجم الإنتاج؛ قد يقفل الجدول)'],
        'bulk_data' => ['/DB::table\([^;]*->(update|delete|truncate)\s*\(|DB::(statement|unprepared)\s*\(\s*[\'"]\s*(UPDATE|DELETE|TRUNCATE)\b/is', 'تعديل/حذف بيانات داخل الترحيل (استعمل أمر Artisan مع chunkById بدلًا منه)'],
        'raw_ddl' => ['/DB::(statement|unprepared)\s*\(\s*[\'"]\s*(ALTER|DROP|CREATE\s+(UNIQUE\s+)?INDEX)\b/i', 'تعليمة DDL خام (تحقق من القفل والتوافق مع الإصدار السابق)'],
        'eloquent_in_migration' => ['/\b(?!Schema\b|DB\b)[A-Z][A-Za-z]+::(query|where|create|update|delete|truncate|chunk|each|all)\b/', 'استعمال نموذج Eloquent داخل الترحيل (يتغير مع الكود؛ فضّل DB::table أو أمرًا منفصلًا)'],
    ];

    /** @return list<string> رموز المخاطر الموجودة بجسم up() */
    public function scan(string $source): array
    {
        $up = $this->upBody($source);
        $found = [];

        foreach (self::RULES as $code => [$pattern]) {
            if (preg_match($pattern, $up) === 1) {
                $found[] = $code;
            }
        }

        return $found;
    }

    public function describe(string $code): string
    {
        return self::RULES[$code][1] ?? $code;
    }

    /** رموز تعني تغييرًا قد يكسر الكود الحالي أو يخسر بيانات (أشدّ من مجرد «قِس الزمن»). */
    public function isBreaking(string $code): bool
    {
        return in_array($code, ['drop_column', 'rename_column', 'change_type', 'drop_table', 'bulk_data'], true);
    }

    private function upBody(string $source): string
    {
        $start = preg_match('/function\s+up\s*\(/', $source, $m, PREG_OFFSET_CAPTURE) === 1 ? $m[0][1] : null;

        if ($start === null) {
            return '';
        }

        $end = preg_match('/function\s+down\s*\(/', $source, $d, PREG_OFFSET_CAPTURE, $start) === 1 ? $d[0][1] : strlen($source);

        return substr($source, $start, $end - $start);
    }
}
```

### `app/Services/Operations/MigrationState.php`

```php
<?php

namespace App\Services\Operations;

use Illuminate\Database\Migrations\Migrator;
use Throwable;

/**
 * E25 - حالة الترحيلات للقراءة فقط: ما هو معلّق (ملف موجود لم يُنفَّذ) وما هو «يتيم» (نُفِّذ بالقاعدة ولا ملف له بالكود الحالي).
 * اليتيم هو العَرَض الكلاسيكي لـ«نشر إصدار أقدم فوق قاعدة أحدث» - أي تراجع عن الكود بعد ترحيل (انظر docs/release-engineering.md).
 * لا يستدعي migrate ولا يكتب شيئًا: الـRepository يقرأ جدول migrations فقط.
 */
final class MigrationState
{
    public function __construct(private readonly Migrator $migrator) {}

    /**
     * @return array{repository: bool, files: int, ran: int, pending: list<string>, orphans: list<string>, paths: array<string, string>}
     */
    public function inspect(): array
    {
        $paths = array_values(array_unique(array_merge([database_path('migrations')], $this->migrator->paths())));
        $files = $this->migrator->getMigrationFiles($paths);

        $state = ['repository' => false, 'files' => count($files), 'ran' => 0, 'pending' => array_keys($files), 'orphans' => [], 'paths' => $files];

        try {
            $repository = $this->migrator->getRepository();

            if (! $repository->repositoryExists()) {
                return $state;
            }

            $ran = $repository->getRan();
        } catch (Throwable) {
            return $state;
        }

        $state['repository'] = true;
        $state['ran'] = count($ran);
        $state['pending'] = array_values(array_diff(array_keys($files), $ran));
        $state['orphans'] = array_values(array_diff($ran, array_keys($files)));

        return $state;
    }
}
```

### `app/Services/Operations/OperationalSignals.php`

```php
<?php

namespace App\Services\Operations;

use App\Support\ReleaseInfo;
use App\Support\SchedulerHeartbeat;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * E25 - ملخص إشارات تشغيلية للقراءة فقط لمركز العمليات (Filament): الإصدار، البيئة، أسماء المشغّلات (driver) فقط، نبض المجدول، الطابور، وجاهزية القاعدة/الكاش.
 *  - لا أسرار ولا مضيفون ولا بيانات اعتماد: أسماء مشغّلات وأرقام وحالات.
 *  - ثقيلة بالقدر الأدنى: استعلام select 1 + قراءة مفتاح كاش + إشارات الطابور، والناتج كله يُخزَّن 30ث في الكاش الافتراضي (يُحسب مباشرة إن تعطل الكاش).
 *  - لا تكتب في الكاش غير مفتاح اللقطة نفسه (لا مفاتيح اختبار): القراءة وحدها تكفي لقياس صحة الكاش هنا.
 */
final class OperationalSignals
{
    public const CACHE_KEY = 'ops:signals:v1';

    public function __construct(
        private readonly SchedulerHeartbeat $heartbeat,
        private readonly QueueSignals $queue,
    ) {}

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        $ttl = (int) config('operations.queue.signals_cache_seconds', 30);

        if ($ttl <= 0) {
            return $this->compute();
        }

        try {
            return Cache::remember(self::CACHE_KEY, $ttl, fn () => $this->compute());
        } catch (Throwable) {
            return $this->compute();
        }
    }

    /** @return array<string, mixed> */
    public function compute(): array
    {
        $queue = $this->queue->snapshot();

        return [
            'release' => ReleaseInfo::short(),
            'environment' => (string) config('app.env'),
            'debug' => (bool) config('app.debug'),
            'maintenance' => app()->isDownForMaintenance(),
            'drivers' => [
                'cache' => (string) config('cache.default'),
                'session' => (string) config('session.driver'),
                'queue' => (string) config('queue.default'),
                'broadcast' => (string) config('broadcasting.default'),
                'mail' => (string) config('mail.default'),
            ],
            'scheduler' => [
                'status' => $this->heartbeat->status(),
                'age_seconds' => $this->heartbeat->ageSeconds(),
            ],
            'queue' => $queue,
            'queue_warnings' => $this->queue->warnings($queue),
            'readiness' => [
                'database' => $this->timed(fn () => DB::select('select 1')),
                'cache' => $this->timed(fn () => Cache::get($this->heartbeat->key())),
            ],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /** @return array{ok: bool, ms: float} */
    private function timed(\Closure $fn): array
    {
        $start = hrtime(true);

        try {
            $fn();
            $ok = true;
        } catch (Throwable) {
            $ok = false;
        }

        return ['ok' => $ok, 'ms' => round((hrtime(true) - $start) / 1e6, 1)];
    }
}
```

### `app/Services/Operations/PreflightChecker.php`

```php
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
```

### `app/Services/Operations/ProductionChecker.php`

```php
<?php

namespace App\Services\Operations;

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
            $this->prodFail($g, 'DB_CONNECTION', 'DB_CONNECTION=sqlite غير مناسب للإنتاج (كتابة متزامنة محدودة، لا نسخ نقطي) - استعمل MySQL/MariaDB/PostgreSQL');

            return;
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
```

### `app/Services/Operations/QueueSignals.php`

```php
<?php

namespace App\Services\Operations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * E25 - إشارات الطابور للقراءة فقط (لمركز العمليات وproduction:check): العدد المنتظر الآن، عمر أقدم مهمة منتظرة، وعدد المهام الفاشلة.
 * لا تعدّل شيئًا ولا تكشف حمولة المهام أو أسماء الفئات: أرقام فقط. أي فشل بالقراءة يعطي null (غير معروف) ولا يرمي.
 * مع طابور database: استعلامان على جدول jobs (count + min) ويُخزَّن ناتج مركز العمليات 30ث، فلا تتحول الصفحة إلى عنق زجاجة (E24/S3).
 */
final class QueueSignals
{
    /** @return array{driver: string, pending: ?int, oldest_age_seconds: ?int, failed: ?int} */
    public function snapshot(): array
    {
        $driver = (string) config('queue.default');
        $out = ['driver' => $driver, 'pending' => null, 'oldest_age_seconds' => null, 'failed' => $this->failedCount()];

        $driverName = (string) config("queue.connections.{$driver}.driver", $driver);

        if ($driverName === 'database') {
            try {
                $query = DB::connection(config("queue.connections.{$driver}.connection"))
                    ->table((string) config("queue.connections.{$driver}.table", 'jobs'))
                    ->whereNull('reserved_at')
                    ->where('available_at', '<=', now()->getTimestamp());

                $out['pending'] = (clone $query)->count();
                $oldest = (clone $query)->min('available_at');
                $out['oldest_age_seconds'] = $oldest === null ? 0 : max(0, now()->getTimestamp() - (int) $oldest);
            } catch (Throwable) {
                // يبقى null: غير معروف
            }
        } elseif ($driverName === 'redis') {
            try {
                $out['pending'] = (int) Queue::connection($driver)->size();
            } catch (Throwable) {
            }
        }

        return $out;
    }

    /**
     * @param  array{driver: string, pending: ?int, oldest_age_seconds: ?int, failed: ?int}  $snapshot
     * @return list<string> رسائل تنبيه عربية (فارغة = سليم)
     */
    public function warnings(array $snapshot): array
    {
        $warnings = [];

        $maxAge = (int) config('operations.queue.backlog_warn_age_seconds', 900);
        if ($maxAge > 0 && ($snapshot['oldest_age_seconds'] ?? 0) >= $maxAge) {
            $warnings[] = 'تراكم بالطابور: أقدم مهمة منتظرة منذ '.intdiv((int) $snapshot['oldest_age_seconds'], 60).' دقيقة (تحقق من عمل queue:work)';
        }

        $failedWarn = (int) config('operations.queue.failed_jobs_warn', 1);
        if ($failedWarn > 0 && ($snapshot['failed'] ?? 0) >= $failedWarn) {
            $warnings[] = 'يوجد '.$snapshot['failed'].' مهمة فاشلة (راجع queue:failed قبل أي إعادة محاولة، خصوصًا المهام الاقتصادية)';
        }

        return $warnings;
    }

    private function failedCount(): ?int
    {
        if (! in_array(config('queue.failed.driver'), ['database', 'database-uuids'], true)) {
            return null;
        }

        try {
            return DB::connection(config('queue.failed.database'))->table((string) config('queue.failed.table', 'failed_jobs'))->count();
        } catch (Throwable) {
            return null;
        }
    }
}
```

### `app/Services/Operations/SmokeChecker.php`

```php
<?php

namespace App\Services\Operations;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * E25 - فحص الدخان بعد النشر. قراءة فقط بصرامة: طلبات GET فقط على مسارات عامة، بلا جلسة ولا بيانات اعتماد ولا كتابة (لا صداقة/محاولة أحجية/رسالة/محفظة).
 * مهلة صغيرة لكل طلب. المخرجات: PASS/WARN/FAIL + المسار + الحالة + الزمن، بلا ترويسات ولا أجسام ولا نصوص استثناءات.
 * الفحص الموثَّق (بحساب مصادَق) يتم بـFeature tests على نسخة staging لا بالإنتاج (docs/smoke-tests.md).
 */
final class SmokeChecker
{
    public function run(string $baseUrl, ?string $profile = null, ?string $expectRelease = null): CheckReport
    {
        $report = new CheckReport(false);
        $group = 'فحص الدخان (GET فقط)';
        $parts = parse_url($baseUrl) ?: [];
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        if (! in_array($scheme, ['http', 'https'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            $report->add(new CheckResult(CheckResult::FAIL, $group, 'url', 'العنوان غير صالح (يلزم http(s)://host بلا بيانات اعتماد داخل الرابط)'));

            return $report;
        }

        $origin = $scheme.'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        $timeout = max(1, (int) config('operations.smoke.timeout_seconds', 5));
        $connect = max(1, (int) config('operations.smoke.connect_timeout_seconds', 3));
        $slow = (int) config('operations.smoke.slow_ms', 2000);

        foreach ((array) config('operations.smoke.routes', []) as $route) {
            $path = (string) $route;

            if (str_contains($path, '{profile}')) {
                if ($profile === null || preg_match('/^[A-Za-z0-9_-]{1,64}$/', $profile) !== 1) {
                    $report->add(new CheckResult(CheckResult::INFO, $group, $path, "تخطّي {$path} (مرّر --profile=<public_id لاعب عام> لفحصه)"));

                    continue;
                }

                $path = str_replace('{profile}', $profile, $path);
            }

            $start = hrtime(true);

            try {
                $response = Http::withHeaders(['Accept' => 'text/html,application/json', 'User-Agent' => 'ahjiyat-smoke/1.0', 'X-Request-ID' => 'smoke-'.bin2hex(random_bytes(6))])
                    ->timeout($timeout)
                    ->connectTimeout($connect)
                    ->withoutRedirecting()
                    ->get($origin.$path);
            } catch (Throwable) {
                $report->add(new CheckResult(CheckResult::FAIL, $group, $path, "GET {$path} → تعذّر الاتصال أو انتهت المهلة ({$timeout}ث)"));

                continue;
            }

            $ms = (int) round((hrtime(true) - $start) / 1e6);
            $status = $response->status();

            if ($status !== 200) {
                $report->add(new CheckResult(CheckResult::FAIL, $group, $path, "GET {$path} → {$status} ({$ms}ms) - المتوقع 200"));

                continue;
            }

            if ($path === '/ready' && ! $this->readyBodyOk($response->json(), $expectRelease, $report, $group, $ms)) {
                continue;
            }

            $report->add($slow > 0 && $ms > $slow
                ? new CheckResult(CheckResult::WARN, $group, $path, "GET {$path} → 200 ({$ms}ms) بطيء (> {$slow}ms)")
                : new CheckResult(CheckResult::PASS, $group, $path, "GET {$path} → 200 ({$ms}ms)"));
        }

        return $report;
    }

    /** يتحقق من جسم /ready (حالة + الإصدار المتوقع). يضيف نتيجة الفشل ويعيد false إن فشل. */
    private function readyBodyOk(mixed $json, ?string $expectRelease, CheckReport $report, string $group, int $ms): bool
    {
        if (! is_array($json) || ($json['status'] ?? null) !== 'ok') {
            $report->add(new CheckResult(CheckResult::FAIL, $group, '/ready', "GET /ready → 200 لكن الجسم لا يؤكد الجاهزية ({$ms}ms)"));

            return false;
        }

        if ($expectRelease !== null && $expectRelease !== '' && ($json['release'] ?? null) !== $expectRelease) {
            $report->add(new CheckResult(CheckResult::FAIL, $group, '/ready', 'GET /ready → الإصدار المنشور لا يطابق المتوقع (--expect-release) - هل أُعيد تحميل php-fpm/الكاش بعد النشر؟'));

            return false;
        }

        return true;
    }
}
```

### `app/Support/ReleaseInfo.php`

```php
<?php

namespace App\Support;

/**
 * E25 - هوية الإصدار. مصدرها الوحيد البيئة (APP_RELEASE أو RELEASE_SHA) عبر config('operations.release.id').
 * القيمة تُعرض علنًا في /ready ويُضاف لسياق السجل، لذلك لا تُقبل إلا بصيغة آمنة قصيرة (لا مسافات ولا أسطر ولا رموز غريبة).
 * لا تقرأ git ولا الملفات ولا أي سر.
 */
final class ReleaseInfo
{
    public const PATTERN = '/^[A-Za-z0-9._-]{1,64}$/';

    /** المعرّف كما ضُبط (آمن) أو null. */
    public static function id(): ?string
    {
        $value = config('operations.release.id');

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return preg_match(self::PATTERN, $value) === 1 ? $value : null;
    }

    /** للعرض: SHA كامل (40 خانة سداسية) يُختصر إلى 7، وغيره يُعرض كما هو. */
    public static function short(): ?string
    {
        $id = self::id();

        if ($id === null) {
            return null;
        }

        return preg_match('/^[0-9a-f]{40}$/i', $id) === 1 ? strtolower(substr($id, 0, 7)) : $id;
    }

    public static function isConfigured(): bool
    {
        return self::id() !== null;
    }
}
```

### `app/Support/SchedulerHeartbeat.php`

```php
<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * E25 - نبض المجدول. مهمة مجدولة صغيرة (routes/console.php، كل دقيقة) تستدعي beat() فتكتب الطابع الزمني في الكاش الافتراضي.
 * اكتشاف توقف المجدول = عمر آخر نبضة. لا جدول جديد ولا ترحيل: الكاش كافٍ لأن النبضة قيمة تُجدَّد كل دقيقة وفقدانها يعني «غير معروف» لا أكثر.
 *
 * ليس مصدر حقيقة لأي منطق لعب أو اقتصاد: للعرض والتنبيه فقط. أي عطل بالكاش يعطي unknown ولا يرمي استثناءً (fail-open).
 */
final class SchedulerHeartbeat
{
    public const OK = 'ok';

    public const STALE = 'stale';

    public const DOWN = 'down';

    public const UNKNOWN = 'unknown';

    public function key(): string
    {
        return (string) config('operations.scheduler.heartbeat_key', 'ops:scheduler:heartbeat');
    }

    public function beat(): void
    {
        try {
            Cache::put($this->key(), now()->getTimestamp(), (int) config('operations.scheduler.heartbeat_ttl_seconds', 86400));
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** طابع آخر نبضة (ثوانٍ يونكس) أو null. */
    public function lastBeatAt(): ?int
    {
        try {
            $value = Cache::get($this->key());
        } catch (Throwable) {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    public function ageSeconds(): ?int
    {
        $at = $this->lastBeatAt();

        return $at === null ? null : max(0, now()->getTimestamp() - $at);
    }

    public function status(): string
    {
        $age = $this->ageSeconds();

        if ($age === null) {
            return self::UNKNOWN;
        }

        if ($age >= (int) config('operations.scheduler.down_after_seconds', 900)) {
            return self::DOWN;
        }

        if ($age >= (int) config('operations.scheduler.stale_after_seconds', 300)) {
            return self::STALE;
        }

        return self::OK;
    }
}
```

### `app/Support/SecretRedactor.php`

```php
<?php

namespace App\Support;

use Illuminate\Support\Arr;

/**
 * E25 - شبكة أمان أخيرة لمخرجات أدوات التشغيل (production:check, release:preflight, release:smoke).
 * الأدوات لا تطبع قيمًا سرية أصلًا (رسائلها تحمل أسماء الإعدادات فقط)؛ هذه الطبقة تضمن ألا يتسرّب سر لو دخل نص خطأ أو قيمة إعداد في رسالة بالخطأ.
 *
 * المصدر: كل قيمة نصية بإعدادات التطبيق الحالية يحوي آخر مقطع من مفتاحها (password|secret|token|api_key|private_key|webhook|dsn|credential) + قيم url/dsn داخل إعدادات القاعدة/السجلات/البريد/الطابور/الكاش (قد تحمل كلمة مرور مضمَّنة) + APP_KEY (وجزؤه base64) + مفاتيح التدوير السابقة.
 * الحد الأدنى للطول 6 كي لا تُحجب كلمات قصيرة شائعة (null/true/false/users…).
 */
final class SecretRedactor
{
    public const MASK = '[redacted]';

    /** الكلمة المفتاحية داخل آخر مقطع من المسار (يشمل webhook_url وapi_token وclient_secret…). */
    private const KEY_PATTERN = '/(password|passwd|secret|token|api_?key|private_?key|webhook|dsn|credential)[a-z0-9_]*$/i';

    /** روابط اتصال قد تُضمِّن كلمة المرور (DATABASE_URL، REDIS_URL، LOG_SLACK_WEBHOOK_URL، MAIL_URL…). */
    private const URL_PATTERN = '/^(database|logging|mail|queue|cache|session|broadcasting)\..*\.(url|uri|dsn)$/i';

    /** @return list<string> */
    public static function secrets(): array
    {
        $values = [];

        $appKey = config('app.key');
        if (is_string($appKey) && $appKey !== '') {
            $values[] = $appKey;
            if (str_starts_with($appKey, 'base64:')) {
                $values[] = substr($appKey, 7);
            }
        }

        foreach ((array) config('app.previous_keys', []) as $previous) {
            if (is_string($previous) && $previous !== '') {
                $values[] = $previous;
                if (str_starts_with($previous, 'base64:')) {
                    $values[] = substr($previous, 7);
                }
            }
        }

        foreach (Arr::dot(config()->all()) as $path => $value) {
            if (is_string($value) && (preg_match(self::KEY_PATTERN, (string) $path) === 1 || preg_match(self::URL_PATTERN, (string) $path) === 1)) {
                $values[] = $value;
            }
        }

        // أسماء الأصناف (Facades\Password، Middleware\ValidateCsrfToken…) ليست أسرارًا وتأتي من مفاتيح تحمل كلمة password/token بالصدفة.
        return array_values(array_unique(array_filter($values, fn ($v) => is_string($v) && strlen($v) >= 6 && preg_match('/^[A-Za-z0-9_]+(\\\\[A-Za-z0-9_]+)+$/', $v) !== 1)));
    }

    /** @param  list<string>|null  $secrets */
    public static function scrub(string $text, ?array $secrets = null): string
    {
        $secrets ??= self::secrets();

        if ($secrets === []) {
            return $text;
        }

        // الأطول أولًا كي لا يترك استبدال الجزء القصير بقايا من القيمة الأطول.
        usort($secrets, fn (string $a, string $b) => strlen($b) <=> strlen($a));

        return str_replace($secrets, self::MASK, $text);
    }
}
```

### `config/operations.php`

```php
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
```

### `database/seeders/Concerns/RefusesProduction.php`

```php
<?php

namespace Database\Seeders\Concerns;

use RuntimeException;

/**
 * E25 - يمنع seeders البيانات التجريبية من العمل بالإنتاج (APP_ENV=production): تزرع حسابات بكلمات مرور معروفة (password / change-me-now) وبيانات وهمية.
 * الإنتاج يستعمل seeders محددة فقط (RolesAndPermissionsSeeder، AdminUserSeeder مع ADMIN_BOOTSTRAP_*) - انظر docs/first-production-deploy.md.
 */
trait RefusesProduction
{
    protected function refuseInProduction(string $what): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException("{$what} ممنوع بالإنتاج: يزرع حسابات/بيانات تجريبية بكلمات مرور معروفة. شغّل بدلًا منه seeders المحددة فقط: RolesAndPermissionsSeeder ثم AdminUserSeeder (مع ADMIN_BOOTSTRAP_EMAIL/PASSWORD) - docs/first-production-deploy.md");
        }
    }
}
```

### `docs/backup-disaster-recovery.md`

````markdown
# النسخ الاحتياطي والتعافي من الكوارث (E25)

> محايد للمزوّد. الأرقام توصيات قابلة للتعديل وليست وعودًا؛ كل رقم يجب أن تثبته أنت بتمرين استعادة حقيقي.
> **النسخة التي لم تُجرَّب استعادتها ليست نسخة احتياطية.** هذه الوثيقة تحلّ محل التفاصيل القديمة في [backup-restore](backup-restore.md) (الذي يبقى كمرجع سريع).

## 1) ما الذي يُنسَخ

| المكوّن | يُنسَخ؟ | ملاحظات |
|---|---|---|
| **قاعدة البيانات** | نعم، الأهم | كل الحقيقة: مستخدمون، محافظ وجواهر، معاملات، محاولات، رسائل، طلبات استبدال، سجلات تدقيق. جداول `jobs`/`sessions`/`cache` داخلها أيضًا |
| **الملفات الدائمة** `storage/app/public` | نعم | الهوية البصرية، وسائط التجميليات، أي رفع دائم. (إن كانت على S3-متوافق: استعمل إصدارات/نسخًا متماثلًا لدى المزوّد) |
| **الأسرار** (`APP_KEY` وغيره) | نعم، **منفصلة** | مفتاح التطبيق مهم جدًا (القسم 2). **لا تدخل أبدًا ضمن نسخة القاعدة/الملفات ولا بالمستودع** |
| الكاش (`storage/framework/cache`/Redis cache) | لا | قابل لإعادة البناء؛ فقدانه يعني بطئًا مؤقتًا فقط |
| الجلسات (Redis/جدول `sessions`) | لا تُستعاد عادةً | فقدانها = تسجيل دخول جديد للمستخدمين (أثر أكبر من الكاش بقليل، مقبول) |
| الطابور (Redis queue) | بحسب التصميم | طابور `database` داخل نسخة القاعدة. طابور Redis يحتاج `appendonly` (انظر [Redis](operations-runbook.md#redis)) |
| السجلات `storage/logs` | يُفضّل مركزيًا | لا تُستعاد؛ تُرحَّل لمنصة سجلات (P2) |
| الكود | لا | هو بالمستودع/artifact. يُعاد من هناك |

## 2) الأسرار و`APP_KEY`: ضمان مفتاح آمن (key escrow)

- فقدان `APP_KEY` يجعل **كل ما شُفِّر به غير قابل للقراءة**: الكوكيز والجلسات المشفّرة، الروابط الموقّعة، وأي حقل مشفّر بالنماذج. لا يمكن استعادته من النسخ.
- احفظ `APP_KEY` (وبقية الأسرار) بـ**خزنة أسرار** المزوّد/مدير أسرار، مع نسخة مشفّرة **غير متصلة** (offline) بيد شخصين مخوَّلين على الأقل (انقسام المعرفة)، وسجّل **من** و**أين** (بلا القيمة) بسجل الوصول.
- **لا تضعه في نسخة القاعدة/الملفات ولا في المستودع ولا في الوثائق ولا في CI.** نسخة الأسرار منفصلة عن نسخ البيانات (كي لا يكشف تسرّبُ نسخةٍ كلَّ شيء).
- عند الاستعادة تحتاج `APP_KEY` **الأصلي** نفسه. تدويره ليس عاديًا: [تحذير APP_KEY](security-hardening.md#app_key-تحذير-خاص).

## 3) التكرار (توصية)

- **لقطة كاملة يوميًا** للقاعدة والملفات (خارج ساعات الذروة).
- **استعادة لنقطة زمنية (PITR/binlog)** إن كان مزوّد القاعدة يدعمها: فعّلها؛ تخفّض الفاقد من يوم إلى دقائق. MySQL: `binlog` مع احتفاظ ≥ أسبوع، أو خدمة مُدارة بـPITR.
- لقطة إضافية **قبل كل ترحيل** (`tools/backup/db-backup.sh`، ويرفض `deploy.sh` تنفيذ `migrate` بلا إقرارها).

## 4) الاحتفاظ (Retention): توصية قابلة للتعديل

| الطبقة | الاحتفاظ المقترح |
|---|---|
| يومية | 7 إلى 14 يومًا |
| أسبوعية | 4 إلى 8 أسابيع |
| شهرية | 6 إلى 12 شهرًا |
| لقطة ما قبل ترحيل | حتى تثبت سلامة الإصدار + أسبوع |

نفّذ الاحتفاظ بسياسة دورة حياة **عند وجهة النسخ** (lifecycle rules)، لا بسكربت حذف على الخادم. عدّل الأرقام بحسب التزامات الخصوصية/القانون (حذف بيانات مستخدم يجب أن ينعكس بحدود زمنية على النسخ القديمة) وكلفة التخزين.

## 5) خارج الخادم (Offsite)

نسخة على نفس الخادم/القرص/المزوّد **ليست** نسخة احتياطية. المطلوب نسخة واحدة على الأقل في **نطاق فشل منفصل**: حساب/منطقة/مزوّد مختلف، بصلاحيات كتابة فقط للمُنفِّذ (لا حذف) أو بقفل غير قابل للتعديل (object lock/immutable) ضد الفدية. السكربتات تدعم ذلك بخطّاف محايد `BACKUP_UPLOAD_CMD` (rclone/aws/scp… بحسب اختيارك).

## 6) التشفير

- **في الراحة (at rest)** و**أثناء النقل (in transit)**: النسخ مشفّرة **قبل** مغادرة الخادم (age أو gpg بمفتاح عام)، والنقل عبر TLS/SSH.
- السكربتات **ترفض** إنتاج نسخة غير مشفّرة إلا بـ`--allow-unencrypted` (للتجارب المحلية فقط).
- **المفتاح الخاص للفك لا يبقى على الخادم** (يُحفظ بخزنة منفصلة، ويُجرَّب فكّه بتمرين الاستعادة).

## 7) أدوات النسخ (محايدة، بلا أسرار مضمَّنة)

| السكربت | وظيفته |
|---|---|
| `tools/backup/db-backup.sh` | نسخة منطقية MySQL/MariaDB (`mysqldump --single-transaction`) أو PostgreSQL (`pg_dump`) → gzip → تشفير → checksum → (اختياري) رفع خارجي. قراءة فقط على القاعدة |
| `tools/backup/files-backup.sh` | `storage/app/public` → tar.gz → تشفير → checksum → (اختياري) رفع |
| `tools/backup/db-restore.sh` | استعادة إلى بيئة **نظيفة** مع حُرّاس الرفض (القسم 9) |
| `tools/backup/files-restore.sh` | فك نسخة الملفات إلى مجلد **فارغ** منفصل |

```bash
# مثال (MySQL). كلمة المرور تأتي من MYSQL_PWD أو ملف my.cnf بصلاحيات 600، لا كوسيط أبدًا.
BACKUP_DIR=/var/backups/ahjiyat \
BACKUP_AGE_RECIPIENT=<المفتاح-العام-age> \
BACKUP_UPLOAD_CMD='rclone copyto "$1" remote:ahjiyat-backups/' \
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=<db> DB_USERNAME=<backup-user> \
bash tools/backup/db-backup.sh --dry-run     # راجع الخطة ثم احذف --dry-run
```

- مستخدم النسخ بصلاحيات `SELECT, LOCK TABLES, SHOW VIEW, EVENT, TRIGGER` فقط (لا كتابة).
- `mysqldump` مثال وليس افتراضًا وحيدًا: لقطات المزوّد المُدارة، أو Percona XtraBackup، أو `pg_basebackup` بدائل مقبولة؛ المهم: مشفّرة، خارج الخادم، ومُجرَّبة الاستعادة.
- جدولها (cron/مجدول المزوّد) خارج مجدول Laravel عمدًا: يجب أن تعمل ولو تعطّل التطبيق.

## 8) دليل الاستعادة (Restore Runbook)

> الهدف دائمًا **بيئة جديدة نظيفة** (للتعافي الحقيقي أو للتمرين)، لا الكتابة فوق إنتاج حي.

1. **جهّز بيئة نظيفة**: خادم/حاوية جديدة بنفس مواصفات [أول نشر](first-production-deploy.md)، وقاعدة بيانات **فارغة** جديدة.
2. **اضبط الأسرار** من الخزنة: `APP_KEY` **الأصلي** (ضروري)، وبيانات القاعدة الجديدة. **أمان بيئة الاستعادة**: `MAIL_MAILER=log` و`BROADCAST_CONNECTION=null` ولا مجدول ولا عمال طابور (لئلا تُرسَل رسائل/إشعارات حقيقية أو تُنفَّذ مهام قديمة على نسخة).
3. **استعد القاعدة**:
   ```bash
   RESTORE_DB_ENGINE=mysql RESTORE_DB_DATABASE=<قاعدة-فارغة-جديدة> RESTORE_DB_HOST=<مضيف> RESTORE_DB_USERNAME=<مستخدم> \
   RESTORE_AGE_IDENTITY=<مسار-المفتاح-الخاص> \
   bash tools/backup/db-restore.sh --dry-run <ملف-النسخة>.sql.gz.age     # ثم نفّذ بلا --dry-run
   ```
4. **استعد الملفات** إلى مجلد فارغ: `RESTORE_FILES_TARGET=<مجلد> bash tools/backup/files-restore.sh <ملف>.tar.gz.age` ثم انقلها إلى `storage/app/public` يدويًا بعد مراجعتها، و`php artisan storage:link`.
5. **ثبّت الكود** للإصدار المطلوب (artifact/مجلد إصدار) و`composer install --no-dev`، والواجهة، ثم `php artisan optimize`.
6. **فحوص توافق**: `php artisan production:check` و`php artisan release:preflight`. انتبه: إن كانت النسخة أقدم من الكود يظهر «ترحيلات معلّقة»، وإن كانت أحدث يظهر «القاعدة أحدث من الكود».
7. **الطابور**: قبل تشغيل أي عامل افحص جدول `jobs`: مهام أقدم من نقطة النسخة قد تعيد تنفيذ إشعارات/مكافآت. القرار بشري: احذف الزائد عمدًا أو دعها بعد التأكد من أن المهام idempotent.
8. **شغّل التطبيق** (على النطاق/الوجهة الجديدة)، ثم **فحص الدخان**: `php artisan release:smoke --url=<عنوان-البيئة>` ثم فحص يدوي: دخول Super Admin، مستخدم حقيقي، رصيد محفظة، آخر الطلبات.
9. **التحويل (cutover)** إن كان تعافيًا حقيقيًا: وجّه DNS/الموازن للبيئة الجديدة، اضبط `MAIL_MAILER` و`BROADCAST_CONNECTION` الحقيقيين، شغّل العمال والمجدول والـReverb، وراقب.
10. **سجّل**: زمن كل خطوة (لقياس RTO)، عمر آخر بيانات (لقياس RPO)، وأي عائق.

## 9) حارس الاستعادة: لا استعادة فوق الإنتاج الحي بالخطأ

`db-restore.sh` و`files-restore.sh` **يرفضان (exit 3) قبل أي اتصال بالقاعدة** إن:

1. `APP_ENV=production` في البيئة الحالية.
2. اسم القاعدة الهدف يشبه `prod|production|live`.
3. الهدف هو نفسه قاعدة التطبيق الحية (`DB_DATABASE` + `DB_HOST`).
4. القاعدة الهدف **غير فارغة** (ما لم تمرّر `--allow-non-empty` عن وعي).

التجاوز الوحيد: `--allow-production` **و** `RESTORE_CONFIRM_DATABASE=<اسم القاعدة بالضبط>` **و** كتابة اسم القاعدة يدويًا عند الطلب (إلا مع `--yes`). النسخة تُتحقَّق بـchecksum قبل الاستعادة إن وُجد `.sha256`. لا كلمات مرور بسطر الأوامر ولا بالسكربتات.

## 10) تمرين الاستعادة (Restore Drill): كل ربع سنة

1. اختر نسخة **عشوائية** من الأسبوع الأخير من وجهة النسخ (وليس آخر نسخة دائمًا).
2. نفّذ القسم 8 كاملًا على بيئة معزولة، **بتوقيت** كل خطوة، وبشخص غير الذي كتب الدليل إن أمكن.
3. تحقق: `production:check` و`release:smoke` ينجحان، وعدّ صفوف جداول رئيسية (`users`، `wallets`، `gem_transactions`…) قريب من المتوقع، والملفات تفتح.
4. دوّن: التاريخ، النسخة، زمن الاستعادة الكلي (**RTO الفعلي**)، عمر البيانات (**RPO الفعلي**)، الأعطال، والإصلاحات. حدّث هذه الوثيقة بما تعلّمته.
5. تمرين فاشل = **حادثة** (SEV-2): أصلح سلسلة النسخ قبل أي شيء آخر.

## 11) RPO / RTO (توصيات، وليست وعودًا)

| | بدون PITR | مع PITR/binlog |
|---|---|---|
| **RPO** (أقصى بيانات يمكن خسارتها) | **≤ 24 ساعة** (لقطة يومية) | **دقائق** (بحسب فاصل تفريغ binlog/WAL) |
| **RTO** (زمن العودة) | يعتمد على البنية: بيئة جاهزة + قاعدة صغيرة/متوسطة: **ساعة إلى 4 ساعات** كهدف أولي | أقصر إن توفرت بيئة احتياطية دافئة |

ما يحدد الأرقام الفعلية: حجم القاعدة، سرعة الشبكة للوجهة، جاهزية بيئة نظيفة (IaC/صورة)، وتمرين الاستعادة. **لا تعتمد رقمًا لم تقِسه.** قبل الإطلاق العام: اتفق مع مالك المنتج على RPO/RTO المقبولين لأن بيانات المحفظة/الاستبدال لها أثر مالي.

## 12) سيناريوهات كوارث القاعدة

لكل سيناريو: **الاكتشاف → الإجراء الفوري → التعافي**. (الإجراء العام للحوادث: [incident-response](incident-response.md).)

### تلف القاعدة (Corruption)
- **اكتشاف**: أخطاء تكامل/فهارس في سجل MySQL (InnoDB)، أخطاء استعلام غير مفسّرة، فشل `CHECK TABLE`، فرق أرصدة مع سجل المعاملات.
- **فوري**: أوقف الكتابة (وضع صيانة + أوقف عمال الطابور والمجدول) كي لا تتفاقم؛ **لا تشغّل أدوات إصلاح** على القاعدة الحية قبل لقطة للوضع الحالي.
- **تعافٍ**: انسخ الحالة الحالية للتحليل؛ استعد آخر نسخة سليمة إلى بيئة نظيفة (القسم 8)؛ وإن توفر PITR فأعد لنقطة قبل التلف؛ قارن المحفظة بسجل المعاملات (مصدر الحقيقة المالي) قبل فتح الخدمة؛ خطّط لإعادة تطبيق ما بعد النسخة إن أمكن.

### حذف عرضي (Accidental deletion)
- **اكتشاف**: بلاغات مستخدمين/إدارة، أعداد صفوف منخفضة فجأة، سجل التدقيق التشغيلي.
- **فوري**: أوقف الكتابة على الجداول المتأثرة إن أمكن؛ سجّل وقت الحدث بدقة.
- **تعافٍ**: استعد النسخة/PITR **إلى بيئة منفصلة** (لا فوق الحية)، استخرج الصفوف المحذوفة فقط، وأعد إدخالها بترحيل/أمر معتمد بمراجعة مزدوجة (انتبه للاعتماديات والأرصدة). الاستعادة الكاملة فوق الحية = خسارة كل ما بعد النسخة؛ اخترها فقط للأضرار الواسعة.

### ترحيل سيئ (Bad migration)
- **اكتشاف**: فشل الترحيل في النشر، أو أخطاء 500 بعده، أو بيانات خاطئة.
- **فوري**: وقف النشر، **لا `migrate:rollback`** (محظور بالإنتاج عمدًا). افحص جدول `migrations` والمخطط الفعلي (DDL في MySQL غير معاملاتي).
- **تعافٍ**: Forward-Fix بترحيل تصحيحي إن أمكن؛ إن أفسد البيانات: استعادة من لقطة ما قبل الترحيل إلى بيئة منفصلة، ثم قرار (استعادة كاملة وخسارة الكتابات ↔ ترقيع انتقائي). راجع [release-engineering](release-engineering.md#5-التراجع-rollback).

### امتلاء التخزين (Storage full)
- **اكتشاف**: `production:check` يعطي FAIL «مساحة القرص حرجة»، أخطاء كتابة، فشل جلسات/سجلات/رفع، فشل `mysqld`.
- **فوري**: حرّر مساحة **آمنة**: سجلات قديمة مُدوَّرة، نسخ محلية مرفوعة سلفًا، كاش قابل لإعادة البناء. لا تحذف ملفات القاعدة/الملفات الدائمة/النسخ الوحيدة.
- **تعافٍ**: وسّع القرص أو انقل الملفات لقرص كائنات، ضبط تدوير السجلات (`LOG_STACK=daily`+`LOG_DAILY_DAYS`)، تنبيه عند 80% كي لا تتكرر الحادثة. بعد الامتلاء قد تحتاج `CHECK TABLE` للجداول المكتوبة وقتها.

### القاعدة غير متاحة (DB unavailable)
- **اكتشاف**: `/ready` يعطي 503 (`checks.database.ok=false`)، `production:check` FAIL، موجة 5xx.
- **فوري**: تحقق من الخدمة/الشبكة/بيانات الاعتماد/امتلاء القرص/حد الاتصالات؛ أعِد تشغيل الخدمة إن تعطلت؛ فعّل صفحة صيانة إن طال الأمر.
- **تعافٍ**: عند العودة افحص `jobs`/`failed_jobs` وأعد تشغيل العمال. إن كان العطل فقدان الخادم: استعادة (القسم 8) أو التحويل لنسخة مُدارة احتياطية.

### تأخّر النسخة المتماثلة (Replica lag): مستقبلًا
- **غير مفعّل اليوم** (لا replicas). عند إضافتها: وجّه القراءات الحساسة (المحفظة، الاستبدال) للأساسية دائمًا، وراقب التأخر؛ لا تستعمل نسخة متأخرة للتعافي دون التحقق من فجوتها.
- **اكتشاف**: `Seconds_Behind_Source`/مقياس المزوّد. **فوري**: إيقاف توجيه القراءات الحرجة للنسخة. **تعافٍ**: تقليل الحمل/إعادة بناء النسخة.

## 13) قائمة تحقق سريعة

- [ ] نسخة يومية للقاعدة والملفات تعمل ومُشفّرة ومنسوخة خارج الخادم.
- [ ] PITR مفعّل إن أمكن، وRPO/RTO متفق عليهما مع مالك المنتج.
- [ ] `APP_KEY` وبقية الأسرار بخزنة منفصلة بنسختين ومسؤولَين على الأقل.
- [ ] آخر تمرين استعادة خلال 90 يومًا، موثّق، بنجاح.
- [ ] سياسة الاحتفاظ مطبّقة عند وجهة النسخ (دورة حياة).
- [ ] تنبيه عند فشل النسخة اليومية أو تأخرها (> 26 ساعة).
````

### `docs/environment-variables.md`

````markdown
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
````

### `docs/first-production-deploy.md`

````markdown
# أول نشر للإنتاج (E25)

> خطوات مرتّبة ومحايدة للمزوّد. للنشرات **اللاحقة** استعمل [`tools/deploy/deploy.sh`](release-engineering.md#3-تسلسل-النشر)؛ هذا الدليل لأول مرة فقط (قاعدة فارغة، لا مدير بعد، لا خدمات). لا شيء هنا يُشغَّل تلقائيًا.
> قبل البدء اقرأ: [قائمة الإطلاق](production-checklist.md)، [متغيرات البيئة](environment-variables.md)، [النسخ والتعافي](backup-disaster-recovery.md).

## U1) التحضير

1. **الخادم**: Linux (LTS) بمستخدم نشر **غير root**، جدار ناري (80/443 للعامة، SSH مقيّد)، مزامنة وقت (NTP)، منطقة زمنية UTC.
2. **PHP 8.2+** (8.3 مُوصى به؛ هو ما يختبره CI) مع PHP-FPM والامتدادات: `bcmath ctype curl dom fileinfo filter hash iconv intl json libxml mbstring openssl pcre pdo_mysql session tokenizer xml xmlreader zip` (+ `redis` إن استعملت Redis). **`intl` و`zip` مطلوبتان** (Filament وتصدير الجداول).
   - OPcache مفعّل؛ بالإنتاج غالبًا `opcache.validate_timestamps=0` ⟵ **يجب إعادة تحميل PHP-FPM بعد كل نشر**.
3. **قاعدة البيانات**: MySQL 8+/MariaDB 10.6+ بترميز `utf8mb4`. أنشئ قاعدة **فارغة** جديدة ومستخدمًا مخصصًا لها (ليس root) وآخر للنسخ الاحتياطي بصلاحيات قراءة. التطبيق يحتاج DDL للترحيلات (`CREATE/ALTER/INDEX/DROP/REFERENCES` + DML) على قاعدته فقط.
4. **Redis** (اختياري الآن، موصى به عند التوسع): كلمة مرور، ربط بواجهة خاصة، قرار الفصل والاستمرارية ([Redis](operations-runbook.md#redis)). بدونه: `CACHE_STORE=database`/`SESSION_DRIVER=database`/`QUEUE_CONNECTION=database`.
5. **خادم الويب** (مثال Nginx في [production-deployment](production-deployment.md#ملحق-مثال-nginx)): جذر الموقع **`/public` فقط**، TLS، `client_max_body_size` مناسب، وكتل WebSocket لـReverb ([الوكيل](operations-runbook.md#الوكيل-العكسي-nginx-websocket)).
6. **Composer 2** و**Node 20+** (للبناء فقط، أو ابنِ بـCI وانقل الناتج كـartifact).
7. **نسخة احتياطية**: جهّز وجهة النسخ الخارجية ومفتاح التشفير (age/gpg) **قبل** أن توجد بيانات.

## U2) التطبيق

1. **ضع الكود** لإصدار محدد (وسم/SHA) بمجلد إصدار جديد، مثل `/var/www/ahjiyat/releases/<id>` مع رابط `current` ⟵ مجلد الإصدار (يسهّل التراجع). عبر artifact/rsync/آلية مزوّدك. لا `git pull` على الخادم، ولا `.git` داخل `public/`.
2. **البيئة**: أنشئ `.env` للإنتاج من مدير الأسرار (القالب: [environment-variables](environment-variables.md#6-الحد-الأدنى-النموذجي-للإنتاج-placeholders-فقط))، بصلاحيات `640` ومالك مستخدم التطبيق.
   - **`APP_KEY`**: ولّده مرة واحدة على جهاز آمن `php artisan key:generate --show` وخزّنه فورًا بالخزنة (نسختان، مسؤولان) قبل وضعه بالبيئة. لا تلصقه بمحادثة أو تذكرة. [تحذير APP_KEY](security-hardening.md#app_key-تحذير-خاص).
   - `APP_ENV=production`، `APP_DEBUG=false`، `APP_URL=https://…`، `SESSION_SECURE_COOKIE=true`، `APP_RELEASE=<id>`.
3. **التبعيات والواجهة**:
   ```bash
   composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
   npm ci && npm run build        # أو انقل public/build من CI
   ```
4. **الصلاحيات**: مالك الملفات مستخدم النشر، مجموعة الويب `www-data`، `chmod -R 775 storage bootstrap/cache` (لا `777` إطلاقًا) ([التفاصيل](production-deployment.md#أذونات-الملفات)).
5. **رابط التخزين**: `php artisan storage:link` (رابط حقيقي، ليس Windows Junction).

## U3) قاعدة البيانات والمدير الأول

> القاعدة جديدة وفارغة؛ لا شيء تخسره، لكن خذ لقطة فارغة للتمرين. على قاعدة فارغة سيعطي `release:preflight` FAIL لغياب الجداول: **متوقع** قبل الترحيل الأول، ولهذا ترحَّل أولًا هنا (وتُستعمل `deploy.sh` بعد هذه المرحلة فقط).

1. `php artisan migrate --force`
2. `php artisan permissions:sync`
3. `php artisan db:seed --class="Database\Seeders\RolesAndPermissionsSeeder" --force`
4. **المدير الأول** (بلا كلمة مرور افتراضية: بالإنتاج يشترط `AdminUserSeeder` بريدًا وكلمة مرور ≥ 12 خانة من البيئة). أدخل القيمتين في **بيئة العملية فقط** (ليس `.env`) وبلا ظهورها بسجل الأوامر:
   ```bash
   read -r  -p "بريد المدير: " ADMIN_BOOTSTRAP_EMAIL
   read -rs -p "كلمة المرور (≥ 12 خانة): " ADMIN_BOOTSTRAP_PASSWORD; echo
   export ADMIN_BOOTSTRAP_EMAIL ADMIN_BOOTSTRAP_PASSWORD
   php artisan config:clear      # كي تقرأ الإعدادات البيئة الحالية (لا كاش مجمّد)
   php artisan db:seed --class="Database\Seeders\AdminUserSeeder" --force
   unset ADMIN_BOOTSTRAP_EMAIL ADMIN_BOOTSTRAP_PASSWORD
   php artisan rbac:migrate-legacy-roles     # يرقّي أقدم مدير قديم إلى super-admin (Bootstrap)
   ```
   سجّل الدخول فورًا من `/admin` وغيّر كلمة المرور إلى قيمة جديدة قوية. **لا `php artisan db:seed` الكامل** (ممنوع آليًا بالإنتاج: يزرع حسابات تجريبية بكلمات معروفة).
5. **المحتوى الأولي** (تصنيفات/أحجيات): قرار منتج. شغّل seeders المحتوى المحددة فقط بعد مراجعة ما تزرعه، أو أدخله من لوحة الإدارة. لا بيانات تجريبية بالإنتاج.
6. **حسّن** (آخر خطوة قبل الخدمات): `APP_RELEASE=<id> php artisan optimize` (يبني كاش config/routes/views/events ويخبز معرّف الإصدار).

## U4) الخدمات

1. **المجدول**: cron لمستخدم التطبيق: `* * * * * cd /var/www/ahjiyat/current && php artisan schedule:run >> /dev/null 2>&1` ([قرار الطوبولوجيا](operations-runbook.md#خادم-مجدول-مخصص-أم-كل-الخوادم)).
2. **عامل الطابور** تحت Supervisor/systemd ([الإعداد](operations-runbook.md#تشغيل-العامل-تحت-مشرف-supervisor)).
3. **Reverb** تحت المشرف + كتل الوكيل ([الإعداد](operations-runbook.md#reverb-البث-الفوري)).
4. **PHP-FPM**: أعد تحميله (reload) كي يحمّل OPcache الكود.
5. **النسخ الاحتياطي**: جدولة `tools/backup/db-backup.sh` و`files-backup.sh` (يوميًا) + تنبيه على الفشل. نفّذ نسخة يدوية الآن وتحقق من وصولها خارج الخادم.

## U5) التحقق

1. `curl -sS -o /dev/null -w "%{http_code}\n" https://<النطاق>/up` ⟵ `200`.
2. `curl -sS https://<النطاق>/ready` ⟵ `{"status":"ok", "release":"<id>", "checks":{…}}` (200). لا أسرار ولا إصدارات PHP/Laravel ولا أسماء مضيفين.
3. `php artisan production:check` ⟵ Exit **0**. بعد دقيقتين: نبضة المجدول `ok` (قبل ذلك `unknown` طبيعية).
4. `php artisan release:preflight` ⟵ بلا FAIL.
5. `php artisan release:smoke --url=https://<النطاق> --expect-release=<id>` ⟵ كل المسارات PASS.
6. الفحص اليدوي: [smoke-tests](smoke-tests.md) (دخول المدير، تسجيل حساب جديد + وصول بريد التوثيق، أحجية، محفظة، `/admin/operations`).
7. مركز العمليات: الإصدار، المجدول `ok`، الطابور بلا تراكم، لا `APP_DEBUG`.
8. `curl -sI https://<النطاق>/` ⟵ رؤوس الأمان موجودة وHSTS (بالإنتاج HTTPS).

## U6) بعد الإطلاق

- [ ] `ADMIN_BOOTSTRAP_*` غير موجودة بأي مكان (البيئة، `.env`، سجل الأوامر).
- [ ] **تمرين استعادة** على بيئة معزولة خلال أسبوعين من الإطلاق، ثم ربع سنوي ([التمرين](backup-disaster-recovery.md#10-تمرين-الاستعادة-restore-drill-كل-ربع-سنة)).
- [ ] المراقبة الخارجية على `/ready` تعمل والتنبيه يصل.
- [ ] سجّل: معرّف الإصدار الأول، تاريخ الإطلاق، مَن يملك أي سر، مكان نسخ الأسرار.
- [ ] راقب 24 ساعة: السجلات، الطابور، المجدول، القرص.

## ما لا تفعله أبدًا

| لا تفعل | لماذا |
|---|---|
| `php artisan db:seed` (كامل) | حسابات تجريبية بكلمات معروفة (ممنوع آليًا بالإنتاج) |
| `migrate:fresh` / `db:wipe` / `migrate:rollback` | يمحو/يهدم بيانات (محظور آليًا بالإنتاج) |
| `APP_DEBUG=true` | يكشف Stack Traces والإعدادات (FAIL حاجب) |
| `.env` أو أسرار في Git أو الوثائق أو CI | تسرّب دائم عبر التاريخ |
| `php artisan serve` / `npm run dev` على الخادم | ليسا خادمَي إنتاج، و`public/hot` يكسر الأصول |
| `composer update` / `npm update` عند النشر | إصدارات غير مُختبَرة؛ مصدر الإصدارات هو ملفات القفل |
| `chmod -R 777` | يفتح الملفات لأي عملية على الخادم |
````

### `docs/incident-response.md`

````markdown
# الاستجابة للحوادث (E25)

> بسيطة عمدًا: ثلاث درجات، ست مراحل، أمثلة جاهزة. الهدف: **استعادة الخدمة أولًا، ثم فهم السبب.** الأدوات: مركز العمليات، `GET /ready`، `php artisan production:check`، `release:smoke`، وسجلات التطبيق (`request_id` + `release`).

## 1) الدرجات (Severity)

| الدرجة | التعريف | أمثلة | الاستجابة |
|---|---|---|---|
| **SEV-1** | الخدمة معطّلة أو خطر على **المال/البيانات/الأمان** | الموقع لا يفتح؛ القاعدة معطلة؛ أرصدة/جواهر خاطئة أو مضاعفة؛ تسرّب أسرار/بيانات؛ حساب إدارة مخترق | فورًا، كل المتاحين، تحديثات كل 15–30 د، قرار صيانة/إيقاف الكتابة مسموح |
| **SEV-2** | تدهور كبير أو ميزة أساسية معطّلة بلا فقدان بيانات | تأخر الطابور الكبير؛ المجدول متوقف؛ 5xx متكررة؛ بطء شديد؛ الدردشة اللحظية متوقفة؛ فشل نسخة يومية | خلال ساعة، شخص رئيسي + مساعد، تحديثات كل ساعة |
| **SEV-3** | عيب محدود أو تدهور طفيف له حل بديل | مهمة فاشلة واحدة؛ صفحة بطيئة؛ تحذير `production:check` جديد | خلال يوم عمل، يُسجَّل ويُجدول |

عند الشك: **ارفع الدرجة** ثم اخفضها بعد الفهم.

## 2) العملية: Detect → Acknowledge → Contain → Recover → Verify → Postmortem

1. **Detect (اكتشف)**: تنبيه مراقبة، `/ready` ≠ 200، مركز العمليات، بلاغ مستخدم (اطلب `X-Request-ID` من الرد/لقطة الشاشة).
2. **Acknowledge (أقرّ)**: شخص واحد **قائد الحادثة** يتولى التنسيق ويعلن الدرجة والوقت بقناة واحدة. بقية الفريق ينفّذ بتوجيه.
3. **Contain (احتوِ)**: أوقف الضرر الآن قبل الفهم الكامل: تراجع الكود الأخير إن كان السبب إصدارًا، تعطيل ميزة/مصدر، وضع صيانة لوقف الكتابات، تجميد حساب/مفتاح مسرّب، إيقاف العمال إن كانت المهام تفسد بيانات.
4. **Recover (استعد)**: الإجراء الأسرع الآمن: تراجع كود، إعادة تشغيل خدمة، Forward-Fix، أو استعادة من نسخة ([التعافي](backup-disaster-recovery.md)). **لا تُجرِ تجارب** على الإنتاج الحي بلا لقطة/نسخة للوضع الحالي.
5. **Verify (تحقق)**: `/ready` = 200، `release:smoke` ينجح، `production:check` لا FAIL جديد، نبضة المجدول ok، الطابور يتصرّف، وفحص يدوي للمسار المتأثر. راقب 30 دقيقة.
6. **Postmortem (مراجعة بعد الحادثة)**: لـSEV-1/2 خلال 5 أيام عمل (القسم 4).

سجل زمني أثناء الحادثة (من/متى/ماذا) في مكان واحد؛ تنفع كلمة واحدة لكل إجراء. **لا أسرار** في هذا السجل ولا في القنوات.

## 3) أمثلة جاهزة (أعراض → فحص → إجراء)

### قاعدة البيانات غير متاحة
- **أعراض**: `/ready` 503 (`checks.database.ok=false`)، موجة 5xx، `production:check` FAIL «تعذَّر الاتصال».
- **فحص**: خدمة القاعدة تعمل؟ القرص ممتلئ؟ حد الاتصالات؟ بيانات الاعتماد/الشبكة تغيّرت؟
- **إجراء**: أعد تشغيل الخدمة/حرّر المساحة؛ صفحة صيانة إن طال؛ عند العودة أعد تشغيل العمال وراجع `failed_jobs`. فقدان الخادم ⟵ [الاستعادة](backup-disaster-recovery.md#8-دليل-الاستعادة-restore-runbook).

### Redis غير متاح
- **أعراض**: `/ready` 503 (`cache`)، أخطاء جلسات/طابور، تسجيلات خروج جماعية.
- **فحص**: العملية/الذاكرة/`maxmemory`/الشبكة؛ هل طُردت مفاتيح (`noeviction` غير مضبوط)؟
- **إجراء**: أعد تشغيل Redis (الاستمرارية AOF تحفظ الطابور/الجلسات)؛ إن لم يعد سريعًا فبدّل مؤقتًا إلى `database` للكاش/الجلسات/الطابور (`.env` ثم `optimize` + `queue:restart`) كخطة طوارئ. راجع [Redis](operations-runbook.md#redis).

### تراكم الطابور (Queue backlog)
- **أعراض**: عمر أقدم مهمة منتظرة مرتفع، إشعارات/بثّ متأخرة، `queue_warnings` بمركز العمليات.
- **فحص**: العمال يعملون؟ (`supervisorctl status`)، **أثناء صيانة؟** (العمال لا يسحبون)، مهمة سامة؟ تدفق وارد غير عادي؟
- **إجراء**: أعد تشغيل/زِد العمال، عالج المهمة السامة، **لا `queue:retry all`** للاقتصادية. [التفاصيل](operations-runbook.md#الطابور-queue).

### Reverb متوقف
- **أعراض**: دردشة/إشعارات بلا تحديث لحظي؛ الحفظ يعمل.
- **إجراء**: `supervisorctl restart ahjiyat-reverb` ثم `reverb:restart` للتأكد؛ تحقق من الوكيل (Upgrade/مهلة) وحد الملفات المفتوحة. **SEV-3** غالبًا (لا فقدان بيانات). [التفاصيل](operations-runbook.md#reverb-البث-الفوري).

### أخطاء 5xx مرتفعة
- **فحص**: `release` بالسجلات: هل بدأت مع إصدار جديد؟ `request_id` عيّنة ⟵ الاستثناء. القاعدة/Redis/القرص؟
- **إجراء**: إن بدأت مع نشر ⟵ **تراجع الكود فورًا** ([المصفوفة](release-engineering.md#53-مصفوفة-التراجع)) ثم حلّل.

### طلبات بطيئة
- **فحص**: سجل `slow_request` (route، duration، peak_memory)، `Server-Timing` محليًا، حمل القاعدة، الاستعلامات الثقيلة، ارتفاع اتصالات.
- **إجراء**: تخفيف الحمل (تعطيل مسار ثقيل مؤقتًا)، رفع موارد، فهرس مفقود (ترحيل متوافق)، توسّع. أعد قياس بـ`tools/load` على staging.

### امتلاء القرص
- **أعراض**: `production:check` FAIL «مساحة القرص حرجة»، أخطاء كتابة.
- **إجراء**: [امتلاء التخزين](backup-disaster-recovery.md#امتلاء-التخزين-storage-full). عادةً السجلات: `LOG_STACK=daily` والتدوير.

### نشر سيئ (Bad deployment)
- **أعراض**: فشل `release:smoke` أو 5xx بعد النشر مباشرة.
- **إجراء**: تراجع الكود (5.5) خلال 15 دقيقة؛ **لا rollback للقاعدة**؛ لو الترحيل سبب ⟵ Forward-Fix أو إجراء استعادة. [مصفوفة التراجع](release-engineering.md#53-مصفوفة-التراجع).

### حادثة أمنية
- **أمثلة**: سر مسرّب (في commit/سجل/لقطة)، دخول إدارة غير مصرّح به، نشاط جواهر مشبوه واسع، حساب مخترق.
- **احتواء فوري**: **دوّر السر المسرّب** ([الخطوات](security-hardening.md#تدوير-الأسرار-p4)) وأبطل جلسات المتأثرين (إنهاء الجلسات من لوحة الإدارة)، جمّد الحسابات المشبوهة، وإن لزم وضع صيانة لوقف الكتابات.
- **حفظ الأدلة**: لا تحذف السجلات؛ انسخ نسخة منها. سجّل `request_id`/`release`/الأوقات.
- **تقييم**: ما الذي كُشف؟ منذ متى؟ هل أُسيء استعماله (سجلات التدقيق: `OperationalAuditLog`, `AuthorizationAuditLog`, المعاملات)؟
- **إبلاغ**: بحسب القانون المحلي والتزاماتكم (إخطار المستخدمين/الجهات إن لزم): قرار مالك المنتج/المستشار القانوني.
- **APP_KEY**: لا تدوّره عفويًا؛ [التحذير](security-hardening.md#app_key-تحذير-خاص).

## 4) المراجعة بعد الحادثة (Postmortem): تقنية ووقائعية

القالب (صفحة واحدة، **بلا لوم**: نصف الأنظمة لا الأشخاص):

```
الحادثة: <عنوان قصير>            الدرجة: SEV-?        التاريخ: <…>
الأثر: <من/ما تأثر، ولكم من الوقت، بيانات/مال؟>
الخط الزمني (UTC): <الوقت — حدث — إجراء>
الإصدار (release) ومعرّفات الطلب ذات الصلة: <…>
السبب الجذري: <تقني ووقائعي>
ما نجح: <كشف/استجابة سريعة…>
ما لم ينجح: <فجوات المراقبة/الوثائق/الأدوات>
الإجراءات التصحيحية: <ما، من، متى>  ← تتحول إلى مهام بمالك وتاريخ
```

**قواعد اللغة**: صِف ما **حدث** وما **فعله النظام**، لا من أخطأ. «الترحيل لم يكن متوافقًا مع الكود السابق ولم يكتشفه الفحص» لا «فلان نشر ترحيلًا خاطئًا». ركّز على: لماذا لم تمنع الضمانات ذلك؟ وما الضمان الذي نضيفه (اختبار، فحص preflight، تنبيه، وثيقة)؟

## 5) جاهزية مسبقة (لتكون الحادثة أقصر)

- جهات اتصال المناوبة ومن يملك صلاحيات الخادم/القاعدة/المزوّد معروفة ومحدّثة.
- الوصول لخزنة الأسرار مجرَّب (بما فيه `APP_KEY` من نسختين).
- آخر نسخة احتياطية وآخر **تمرين استعادة** معروفان وناجحان ([التمرين](backup-disaster-recovery.md#10-تمرين-الاستعادة-restore-drill-كل-ربع-سنة)).
- المراقبة الخارجية على `/ready` تعمل وتصل لقناة تنبيه حيّة.
- أوامر التراجع (5.5) وإيقاف الخدمات معروفة لمن سينفّذها.
````

### `docs/operations-runbook.md`

````markdown
# دليل التشغيل: الطابور، المجدول، Reverb، Redis، الصيانة، السجلات (E25)

> للتشغيل اليومي على VPS Linux قياسي (محايد للمزوّد). الإجراءات هنا قراءة/تشغيل آمن؛ الأوامر المؤثرة بالبيانات منبَّهة صراحة.
> أدوات الرؤية: مركز العمليات (`/admin/operations`، قسم «صحة النظام» لمن يملك `system.audit_view`)، `php artisan production:check`، `GET /ready`.

## الطابور (Queue)

### تشغيل العامل تحت مشرف (Supervisor)

```ini
[program:ahjiyat-worker]
command=php /var/www/ahjiyat/artisan queue:work --sleep=3 --tries=3 --timeout=80 --max-time=3600 --max-jobs=1000
process_name=%(program_name)s_%(process_num)02d
numprocs=2
autostart=true
autorestart=true
user=www-data
stopwaitsecs=90
stdout_logfile=/var/log/ahjiyat/worker.log
redirect_stderr=true
```

أو بـ systemd: وحدة بـ`ExecStart=/usr/bin/php /var/www/ahjiyat/artisan queue:work --sleep=3 --tries=3 --timeout=80 --max-time=3600`، `Restart=always`، `TimeoutStopSec=90`، `User=www-data`.

القيم لها أسباب:

- **`--timeout=80`** يطابق حدّ المهام (`BoundedRetries`: timeout 80 ثانية، backoff 15/60/180) ويجب أن يبقى **أقل من `retry_after` (90)**؛ وإلا تُلتقط مهمة ما زالت تعمل فتُنفَّذ مرتين (`production:check` يفشل عند ذلك).
- **`stopwaitsecs=90`** > timeout: المشرف ينتظر انتهاء المهمة الحالية قبل القتل (لا قتل منتصف مهمة اقتصادية).
- **`--max-time`/`--max-jobs`**: إعادة تدوير العامل دوريًا لتفادي تسرّب الذاكرة.
- عدد العمال: ابدأ بـ2 وزِد بحسب عمر أقدم مهمة منتظرة (لا بحسب الحدس).

### إعادة تشغيل آمنة بعد النشر

```bash
php artisan queue:restart
```

يُشير للعمال **أن ينتهوا من مهمتهم الحالية ثم يخرجوا**، فيعيد المشرف تشغيلهم بالكود الجديد (لا انقطاع ولا قتل). يتطلب كاشًا مشتركًا غير `array`. عمّال بكود قديم بعد نشر = مصدر أخطاء غامضة؛ لا تنسَ هذه الخطوة.

### المهام الفاشلة

```bash
php artisan queue:failed                 # قائمة (UUID، الاتصال، الطابور، الوقت)
php artisan queue:retry <uuid>           # إعادة محاولة مهمة واحدة بعد فهم السبب
php artisan queue:forget <uuid>          # حذف سجل مهمة فاشلة (بعد التقرير عنها)
```

**قواعد صارمة**:

1. **لا `queue:retry all` أعمى** للمهام الاقتصادية/المكافآت (تحرير جواهر، منح جوائز، مشتريات): قد تُضاعف المكافأة أو تتعارض مع حالة تغيّرت. افحص كل مهمة، وتأكد من **idempotency** (هل نُفِّذت جزئيًا؟) قبل إعادتها.
2. اعرف السبب قبل الإعادة (`exception` بجدول `failed_jobs`)، وأصلح الجذر؛ وإلا ستفشل ثانية.
3. المهام غير الحرجة (إشعارات، بثّ) يمكن إعادتها أو نسيانها بقرار موثّق.
4. بعد استعادة نسخة: راجع `jobs`/`failed_jobs` **قبل** تشغيل العمال ([النسخ الاحتياطي](backup-disaster-recovery.md#8-دليل-الاستعادة-restore-runbook)).

### تنبيه التراكم (Backlog)

ثلاث إشارات يعرضها مركز العمليات ويفحصها `production:check` (العتبات من `config/operations.php`):

| الإشارة | العتبة الافتراضية | الدلالة |
|---|---|---|
| **عمق الطابور** (منتظرة الآن) | تراقب الاتجاه، لا عتبة ثابتة | ارتفاع مستمر = العمال أبطأ من الوارد |
| **عمر أقدم مهمة منتظرة** | ≥ 900 ث (`OPS_QUEUE_BACKLOG_WARN_SECONDS`) | العمال متوقفون أو غارقون |
| **المهام الفاشلة** | ≥ 1 (`OPS_FAILED_JOBS_WARN`) | ثمة جذر فشل يحتاج فهمًا |

اربط هذه بتنبيه خارجي (صفحة العمليات للقراءة اليدوية؛ للمراقبة الآلية استعمل `production:check --json` أو أداة مراقبة المزوّد).

### المهمة السامة (Poison job)

مهمة تفشل دائمًا لا يجب أن تعطّل البقية. النظام محميّ بنيويًا: `BoundedRetries` (محاولات محدودة بتأخير متصاعد، ثم `failed_jobs`)، وعمّال متعددون. إن رأيت العمال عالقين على مهمة:

1. حدّد الـUUID من `queue:failed` (أو من سجل العامل).
2. **لا تعد المحاولة قبل الإصلاح.** أصلح الكود/البيانات أو `queue:forget` بعد توثيقها.
3. إن كانت تتكرر بعد `retry_after` فتحقق أن `timeout (80) < retry_after (90)`.

## المجدول (Scheduler)

### Cron

```cron
* * * * * cd /var/www/ahjiyat && php artisan schedule:run >> /dev/null 2>&1
```

مدخل واحد كل دقيقة، لمستخدم التطبيق نفسه. كل مهمة بـ`routes/console.php` محمية بـ`withoutOverlapping(N)` (تعافٍ تلقائي بعد N دقيقة لا 24 ساعة) و`onOneServer()` (تنفيذ على خادم واحد حين تتعدد الخوادم؛ يحتاج كاشًا مشتركًا).

### خادم مجدول مخصص أم كل الخوادم؟

| الخيار | متى | ملاحظات |
|---|---|---|
| **A) خادم مجدول مخصص (الموصى به)** | أكثر من خادم تطبيق | cron على خادم واحد فقط. الأبسط والأوضح؛ نقطة فشل واحدة تُغطّيها نبضة المجدول وتنبيهها |
| B) كل الخوادم + `onOneServer` | تريد بلا نقطة فشل مفردة | cron على كل الخوادم، والقفل بكاش **مشترك** (Redis/DB): ينفّذ خادم واحد. بدون كاش مشترك (file/array) يُنفَّذ على الجميع! (`production:check` يحذّر) |
| خادم واحد | البداية | cron واحد. `onOneServer` بلا أثر. كل ما سبق يبقى صالحًا |

**التوصية**: خادم واحد الآن؛ عند التوسع الأفقي استعمل A إلى أن تحتاج B. بدّل `PERF_SCHEDULER_ONE_SERVER=false` فقط إن لم يتوفر كاش مشترك بعد.

### نبضة المجدول (Heartbeat)

- مهمة صغيرة `scheduler-heartbeat` (كل دقيقة) تكتب الطابع الزمني بالكاش الافتراضي، وتخضع لنفس حارس بقية المهام (`withoutOverlapping(5)` + `onOneServer()`). **لا جدول ولا ترحيل.**
- الحالة من عمر آخر نبضة: `ok` (< 300ث)، `stale` (300–899ث)، `down` (≥ 900ث)، `unknown` (لا نبضة قط: طبيعي بعد أول نشر أو بعد مسح الكاش). العتبات `OPS_SCHEDULER_STALE_SECONDS`/`OPS_SCHEDULER_DOWN_SECONDS`.
- تظهر في **مركز العمليات** وفي `production:check`. **`/ready` لا يفشل بسببها** افتراضيًا؛ فعّل `OPS_READY_FAILS_ON_SCHEDULER=true` ليفشل **فقط عند `down`** (لا عند التأخر البسيط ولا `unknown`).
- لا يغيّر منطق أي مهمة؛ فقدان الكاش يعني `unknown` لا أكثر.

### المجدول متوقف: ماذا أفعل؟

1. تحقق من cron (`crontab -l` لمستخدم التطبيق) ومن سجلاته، وأن المسار والمستخدم صحيحان.
2. `php artisan schedule:list` ثم `php artisan schedule:run` يدويًا مرة؛ إن عملت فالمشكلة cron.
3. **قفل عالق** (`withoutOverlapping` بعد انهيار عملية): ينتهي وحده بعد N دقيقة (≤ 360 للمهام اليومية). لتخطيه فورًا بعد التأكد أن المهمة غير جارية: امسح مفتاح القفل من الكاش (`schedule-…`)؛ لا تمسح الكاش كله إن كان يحمل أقفالًا حية.
4. المهام لا تكسر النظام عند غيابها (تحرير الجواهر المعلّقة يتأخر، التذكيرات لا تصل)؛ لكن غيابًا طويلًا يؤخّر الجواهر: عالجه خلال ساعات لا أيام.

## Reverb (البث الفوري)

### العملية

خدمة **دائمة** (persistent) مستقلة عن PHP-FPM:

```ini
[program:ahjiyat-reverb]
command=php /var/www/ahjiyat/artisan reverb:start --host=0.0.0.0 --port=8080
autostart=true
autorestart=true
user=www-data
stopwaitsecs=30
stdout_logfile=/var/log/ahjiyat/reverb.log
redirect_stderr=true
```

- **حد الملفات المفتوحة** (`ulimit -n`، أو `minfds` في قسم `[supervisord]`، أو `LimitNOFILE=` في وحدة systemd): كل اتصال WebSocket ملف مفتوح؛ ارفعه (≥ 10000) قبل الذروة. فوق ~1000 اتصال يُنصح بامتداد حلقة أحداث أسرع (`ext-uv`) وفق توثيق Reverb.
- `php artisan reverb:start` **لا يُشغَّل بالواجهة الأمامية** على خادم الإنتاج؛ دائمًا تحت المشرف.

### إعادة التشغيل بعد النشر

```bash
php artisan reverb:restart
```

إعادة تشغيل graceful: يُنهي الاتصالات بلطف ويعيده المشرف بالكود الجديد؛ العملاء يعيدون الاتصال تلقائيًا (Echo). يتطلب كاشًا مشتركًا غير `array`.

### الوكيل العكسي (Nginx): WebSocket

```nginx
# اتصال WebSocket العام (wss) إلى Reverb
location /app {
    proxy_http_version 1.1;
    proxy_set_header Host $http_host;
    proxy_set_header Scheme $scheme;
    proxy_set_header SERVER_PORT $server_port;
    proxy_set_header REMOTE_ADDR $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "Upgrade";
    proxy_read_timeout 120s;
    proxy_send_timeout 120s;
    proxy_pass http://127.0.0.1:8080;
}
# واجهة HTTP الداخلية لنشر الأحداث (إن كان REVERB_HOST عامًا وليس 127.0.0.1)
location /apps {
    proxy_http_version 1.1;
    proxy_set_header Host $http_host;
    proxy_pass http://127.0.0.1:8080;
}
```

النقاط الحاسمة: ترويستا **`Upgrade`** و**`Connection: Upgrade`**، **مهلة قراءة** أطول من `REVERB_APP_PING_INTERVAL` (60ث) كي لا يقطع الوكيل الاتصال الخامل، و`proxy_http_version 1.1`. إن كان موازنك طبقة سابعة (ALB/Cloudflare) فاضبط مهلة الخمول ≥ 120ث وفعّل دعم WebSocket. `REVERB_SCHEME=https` ويعني `wss` للمتصفح.

### المراقبة

| المقياس | كيف | تنبيه |
|---|---|---|
| عدد الاتصالات الحية | سجل Reverb/`reverb:start --debug` مؤقتًا، أو Pulse إن فُعِّل | وصول 80% من `REVERB_APP_MAX_CONNECTIONS` أو ملفات مفتوحة |
| الأخطاء | سجل خدمة Reverb + سجل التطبيق (`broadcast failed`) | ارتفاع مفاجئ |
| معدّل إعادة الاتصال | سجل الوكيل (طلبات `/app` الجديدة/الدقيقة) | ارتفاع = تقطّع (مهلة وكيل/إعادة تشغيل متكررة) |
| الخدمة نفسها | `supervisorctl status` | غير RUNNING |

### نمط الفشل (سلوك E21: لا يتغير)

**إذا سقط Reverb**: الرسائل تُحفَظ كما هي (الحفظ مستقل عن البث)، ويُنشر البث بمهلة قصيرة (`REVERB_HTTP_CONNECT_TIMEOUT=1`، `REVERB_HTTP_TIMEOUT=3`) كي لا يحجز عامل PHP-FPM. الأثر: لا تحديث لحظي؛ المستخدم يرى الرسائل عند تحديث الصفحة/الاستطلاع. **لا تعدّل هذا السلوك أثناء حادثة**؛ أعد تشغيل الخدمة فقط.

### الحماية

`REVERB_ALLOWED_ORIGINS` قيّدها لنطاق موقعك. القنوات الخاصة محمية بمصادقة `/broadcasting/auth` (جلسة المستخدم) بصرف النظر عن ذلك.

## Redis

### ما الذي قد يحمله (حسب اختيارك)

| الاستعمال | `ENV` | أثر الفقدان |
|---|---|---|
| **الكاش** | `CACHE_STORE=redis` | بطء مؤقت فقط؛ **قابل للفقدان بلا ضرر** |
| **الجلسات** | `SESSION_DRIVER=redis` | تسجيل دخول جديد للمستخدمين (أثر معتدل) |
| **الطابور** | `QUEUE_CONNECTION=redis` | **فقدان مهام** (إشعارات، بثّ، مكافآت معلّقة): أثر كبير |
| أقفال المعدّل/المجدول | عبر الكاش | تنفيذ مزدوج مؤقت أو سماح بحدود |
| توسّع Reverb | `REVERB_SCALING_ENABLED=true` | اتصالات متعددة الخوادم لا تتبادل الأحداث |

### الاستمرارية (Persistence)

الكاش وحده يمكن أن يعمل بلا استمرارية. **الجلسات والطابور لها أثر أكبر**: فعّل `appendonly yes` (AOF، `appendfsync everysec`) أو لقطات RDB متقاربة إن حملتهما. ولا تخلطهما بنفس القاعدة مع الكاش إن كان الكاش سيُمسح.

### سياسة الذاكرة (Eviction)

- **لا تستعمل سياسة طرد خطرة مع الطابور/الجلسات**: `allkeys-lru`/`volatile-*` قد **تحذف مفاتيح الطابور أو الجلسات** عند امتلاء الذاكرة بصمت.
- إن حمل Redis الطابور/الجلسات: `maxmemory-policy noeviction` (يفشل الكتابة بدل فقدان صامت) مع **تنبيه عند 70–80%** من `maxmemory`.
- `allkeys-lru` مقبول **فقط** لنسخة Redis مخصصة للكاش وحده.

### الفصل (Separation)

الحد الأدنى: قواعد منطقية منفصلة (`REDIS_DB` للافتراضي، `REDIS_CACHE_DB` للكاش) وبادئة (`REDIS_PREFIX`). عندما يبرّره الحجم: **نسخة Redis منفصلة** للكاش (طرد مسموح) وأخرى للطابور/الجلسات (`noeviction` + استمرارية). **لا تفرض Redis Cluster الآن**؛ يكفي نسخة واحدة مع نسخ احتياطي/Sentinel عند الحاجة.

### الأمان

`requirepass`/ACL (`REDIS_PASSWORD`)، ربط بواجهة خاصة فقط (`bind`)، لا منفذ 6379 مفتوح للعامة، TLS عند الشبكة غير الموثوقة. `production:check` يفشل إن كان Redis غير محلي بلا كلمة مرور، وأيضًا إن كان مُعدًّا ولا يتصل.

### الأعطال

Redis معطّل ⇒ الكاش والجلسات والطابور (إن كانت عليه) تتعطل ⇒ `/ready` يعطي 503. راجع [الحوادث](incident-response.md#redis-غير-متاح).

## وضع الصيانة

```bash
php artisan down --retry=60 --refresh=15      # بلا سر
php artisan up
```

**ليس لكل نشر.** استعمله فقط عند: (أ) ترحيل غير متوافق مع الكود السابق، (ب) حادثة تتطلب وقف الكتابات.

- **سر الدخول** (`--secret=…` ليتمكن المشغّل من فحص الموقع أثناء الصيانة): قيمة عشوائية جديدة لكل مرة، تأتي من البيئة (`MAINTENANCE_SECRET` في `deploy.sh`)، **لا تُسجَّل ولا تُوثَّق ولا تُحفظ بالمستودع/التذاكر**. وبعد الانتهاء `up` يلغيه.
- **صفحة الصيانة ليست إيقافًا للعمال** (والفرق مهم):
  - **عمليات العمال تبقى قائمة** (`supervisorctl status` يعرضها RUNNING)، لكن `queue:work` **يتوقف عن سحب المهام** طالما التطبيق في `down` (ما لم يُشغَّل بـ`--force`). المهام تتراكم بالطابور ويظهر عمر أقدم مهمة منتظرة بمركز العمليات؛ وتستأنف المعالجة تلقائيًا عند `php artisan up`.
  - إن أردت **تصريف الطابور أثناء الصيانة** شغّل عامل واحد مؤقتًا بـ`--force`؛ وإن أردت **إيقاف المعالجة تمامًا** (مثلًا أثناء ترحيل بيانات) فأوقف العمال صراحة (`supervisorctl stop ahjiyat-worker:*`) ثم أعدهم.
  - **المجدول يتخطى المهام** أثناء الصيانة (لا `evenInMaintenanceMode`)، ولذلك **نبضة المجدول تتوقف أيضًا** فتتدهور حالتها إلى `stale/down` في صيانة طويلة: متوقَّع، وتظهر علامة «وضع الصيانة مفعَّل» بمركز العمليات. لا تتفاعل معها كحادثة مجدول.
  - أثناء الصيانة: `/up` (الحيوية) يبقى **200** (Laravel يستثنيه من الصيانة)، بينما `/ready` يعطي **503** (يمرّ عبر خدمة الصيانة) وكذلك الصفحات العامة (تحقّقه اختبار آلي)، فاستثنِ نافذة الصيانة المعلنة من تنبيهات المراقبة على `/ready`.
- حافظ على مدة الصيانة قصيرة، وأخطر المستخدمين إن طالت.

## السجلات (Logging)

### التدوير والاحتفاظ

- `LOG_STACK=daily` مع `LOG_DAILY_DAYS=14` (قابل للتعديل)، و`LOG_LEVEL=warning` أو `info` بالإنتاج. القناة `single` الافتراضية **بلا تدوير ولا حد حجم**: قد تملأ القرص (`production:check` يحذّر).
- بديل/إضافة: `logrotate` على `storage/logs/*.log` يوميًا، ضغط، احتفاظ محدود.
- **سجلات المراجعة/الأمن** (OperationalAuditLog، AuthorizationAuditLog) بالقاعدة وتُنسَخ مع النسخ الاحتياطية؛ سجلات الملفات للتشخيص فقط.
- **مركزيًا (P2)**: ارسل السجلات لمنصة مركزية (ELK/Loki/Papertrail/خدمة المزوّد) عبر `LOG_STACK=daily,stderr` أو قناة المزوّد؛ تجعل البحث بـ`request_id` و`release` عبر كل الخوادم ممكنًا.

### الربط (Correlation)

كل سطر سجل يحمل:

- `request_id` (E24): من `X-Request-ID` الآمن الذي يرسله الموازن أو UUID مولَّد، ويُعاد برأس الرد، ويصل مهام الطابور المرسلة من الطلب.
- `release` (E25): معرّف الإصدار المختصر إن ضُبط `APP_RELEASE`/`RELEASE_SHA`.

من أي خطأ: خذ `request_id` ⟵ كل أحداث الطلب؛ و`release` ⟵ أي إصدار أنتجه.

### البيانات الحساسة

**ممنوع تسجيلها**: `Authorization`، الكوكيز/معرّف الجلسة، كلمات المرور، `APP_KEY`/أسرار الإعدادات، **نص رسائل الدردشة**، جسم الطلب كاملًا. سجل الطلبات البطيئة (E24) يحتوي حقولًا آمنة فقط (`request_id`, `method`, `route`, `status`, `duration_ms`, `peak_memory_mb`, `user_id`)، بلا سلسلة استعلام ولا رؤوس. يحمي ذلك اختبار ساكن (`tests/Feature/OperationsE25`) يفشل لو ظهر `Log::…` يمرّر طلبًا كاملًا أو رأسًا أو نص رسالة. عند كتابة سجل جديد: سجّل **المعرّفات** (user_id، message_id) لا المحتوى.

## ما الذي أراقبه؟ (ملخص)

| الإشارة | أين | العتبة المقترحة |
|---|---|---|
| `/ready` | الموازن/مراقب خارجي | غير 200 لدقيقتين = SEV-2 |
| نبضة المجدول | مركز العمليات | `down` (≥ 15 د) |
| عمر أقدم مهمة منتظرة | مركز العمليات | ≥ 15 د |
| المهام الفاشلة | مركز العمليات | ≥ 1 جديدة (فحص يدوي) |
| 5xx | سجل الوكيل/المنصة | > 1% من الطلبات لـ5 دقائق |
| الطلبات البطيئة | سجل `slow_request` | ارتفاع p95 |
| القرص | المزوّد | > 80% |
| Redis ذاكرة | `INFO memory` | > 70–80% من `maxmemory` |
| اتصالات Reverb | سجل/Pulse | > 80% من الحد |
| نسخة القاعدة اليومية | مراقب خارجي | لم تنجح خلال 26 ساعة |
````

### `docs/production-checklist.md`

```markdown
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
```

### `docs/release-engineering.md`

````markdown
# هندسة الإصدارات: النشر، الترحيلات الآمنة، التراجع (E25)

> محايد للمزوّد. لا شيء هنا يُنفَّذ تلقائيًا؛ كل خطوة قرار واعٍ. أدوات هذه الوثيقة: `release:preflight`، `release:smoke`، `production:check`، `tools/deploy/deploy.sh`.

## 1) هوية الإصدار

- اضبط عند **كل** نشر: `APP_RELEASE=<sha-مختصر-أو-وسم>` (أو `RELEASE_SHA` إن كان نظام CI يعرّفه). الصيغة `[A-Za-z0-9._-]` حتى 64 خانة؛ غير ذلك يُتجاهل.
- SHA كامل (40 خانة) يُعرض مختصرًا بسبع خانات. لا يقرأ التطبيق git ولا ملفات لمعرفة الإصدار: مصدره البيئة فقط.
- **يظهر في**: `GET /ready` (`"release": "b983867"` فقط إن ضُبط)، مركز العمليات، `production:check`، وفي **سياق كل سطر سجل** (`release`) بجانب `request_id` من E24.
- **كاش الإعداد يخبز القيمة**: لأن الإنتاج يشغّل `config:cache`، مرّر المعرّف لحظة البناء: `APP_RELEASE=b983867 php artisan optimize` (هذا ما يفعله `tools/deploy/deploy.sh --release=b983867`). ثم `queue:restart` كي تلتقط العمال القيمة الجديدة.
- لماذا: عند خطأ بالسجل تعرف فورًا **أي إصدار** أنتجه، وبعد النشر تتحقق أن ما يخدمه الموازن هو الإصدار الجديد فعلًا (`release:smoke --expect-release=<id>`).

## 2) فحص ما قبل النشر `release:preflight`

```bash
php artisan release:preflight            # كل الفحوص
php artisan release:preflight --skip-views   # أسرع: بلا ترجمة قوالب Blade
php artisan release:preflight --json     # لأدوات المراقبة/CI
```

**قراءة فقط بصرامة**: لا `migrate`، لا مسح، لا إعادة تشغيل، لا كتابة قاعدة/كاش/ملفات. Exit `0` = قابل للنشر، `1` = FAIL حاجب.

| الفحص | ما يفعله |
|---|---|
| كل فحوص `production:check` | البيئة، APP_KEY، القاعدة، الكاش (يدعم الأقفال)، الجلسات والكوكيز، Redis، الطابور (`retry_after > 80`)، المجدول، البريد، Reverb، الوكلاء الموثوقون، السجلات، التخزين والقرص، الأصول، الصلاحيات، حسابات الاختبار المعروفة |
| **الترحيلات المعلّقة** | يسرد المعلّق، ويمسح (heuristic ساكن لجسم `up()`) المخاطر: حذف/إعادة تسمية عمود، تغيير نوع، حذف جدول، فهارس جديدة، تعديل بيانات داخل الترحيل، DDL خام، Eloquent داخل الترحيل |
| **ترجمة Blade** | يترجم كل قالب بالذاكرة ويفحص صحة PHP الناتج (يكشف 500 قبل أن تصل للمستخدم) |
| **حداثة كاش config/routes** | يحذّر إن كان كاش الإعداد أقدم من `.env`/`config/*` أو كاش المسارات أقدم من `routes/*` |
| **حداثة الأصول** | يحذّر إن كان `public/build` أقدم من `resources/js|css` |
| وضع الصيانة | يحذّر إن كان التطبيق في `down` |
| ملفات القفل | `composer.lock` و`package-lock.json` موجودان (مصدر الإصدارات) |
| تأكيدات يدوية | نسخة احتياطية مُتحقَّق منها، هدف التراجع محدد، قرار وضع الصيانة |

> ما يحجب (FAIL) ما يجعل النشر **خطرًا فعليًا** (APP_DEBUG، APP_KEY، قاعدة لا تتصل، `retry_after` أقل من timeout، SESSION_SECURE_COOKIE ليست true، sqlite بالإنتاج…). التحذيرات (WARN) تحتاج قرارًا بشريًا لا حجبًا آليًا.

## 3) تسلسل النشر

`tools/deploy/deploy.sh` ينفّذ هذا التسلسل. **ابدأ دائمًا بـ`--dry-run`** (يطبع الخطوات فقط). السكربت لا يستعمل git، ولا أوامر هدّامة، ولا يحذف ملفات، ولا يطبع أسرارًا، ولا يُشغَّل من CI.

```bash
bash tools/deploy/deploy.sh --dry-run
bash tools/deploy/deploy.sh --release=b983867 --backup-confirmed
```

| # | الخطوة | الأمر | نقطة الفشل → الإجراء |
|---|---|---|---|
| 0 | نسخة احتياطية مُتحقَّق منها | `tools/backup/db-backup.sh` | **لا migrate بدونها**: السكربت يرفض (exit 3) فورًا بعد preflight وقبل أي تغيير بالحالة، فلا يُترك الموقع في الصيانة بسبب هذا الرفض |
| 1 | التبعيات | `composer install --no-dev --prefer-dist --optimize-autoloader` | يفشل → لا شيء تغيّر بعد؛ أصلح وأعد |
| 2 | الواجهة | `npm ci && npm run build` | يفشل → لا شيء تغيّر بعد |
| 3 | **preflight** | `php artisan release:preflight` | FAIL → **يتوقف النشر** |
| 4 | وضع الصيانة (اختياري) | `php artisan down --retry=60 --refresh=15` | فقط لترحيل غير متوافق (انظر 5.4) |
| 5 | الترحيلات | `php artisan migrate --force` | يفشل → **لا rollback**؛ انظر 6.3 |
| 6 | الصلاحيات | `php artisan permissions:sync` | آمن ومتكرر (ينشئ الناقص فقط) |
| 7 | الرابط | `php artisan storage:link` | موجود غالبًا |
| 8 | **optimize** | `APP_RELEASE=<id> php artisan optimize` | يبني كاش config/routes/views/events |
| 9 | إعادة تحميل PHP-FPM/OPcache | `DEPLOY_RELOAD_CMD` (بحسب بنيتك) | وإلا يخدم FPM الكود القديم |
| 10 | إعادة تشغيل آمنة | `php artisan queue:restart` ثم `php artisan reverb:restart` | graceful: كل عامل يُكمل مهمته ثم يخرج ويعيده المشرف |
| 11 | إخراج من الصيانة | `php artisan up` | |
| 12 | **فحص دخان** | `php artisan release:smoke --expect-release=<id>` | FAIL → قرار التراجع (القسم 6) |
| 13 | فحص نهائي | `php artisan production:check` | |

ملاحظات:

- `queue:restart` و`reverb:restart` يعتمدان على الكاش للإشارة: يلزم كاش **غير** `array` مشترك بين CLI والعمال (database/redis/file على نفس الجهاز).
- **منح صلاحيات جديدة للأدوار** (`RolesAndPermissionsSeeder`) ليس جزءًا من النشر الآلي: يعيد `grantIfMissing` منح صلاحيات قد يكون المدير سحبها عمدًا من دور. شغّله يدويًا فقط حين تقول ملاحظات الإصدار إن صلاحيات جديدة يجب أن تُمنح.
- **لا `git pull` على الخادم**: الكود يوضع بآلية مزوّدك (artifact/rsync/مجلد إصدار جديد) ثم يبدأ التسلسل.
- بنية `releases/<id>` + رابط `current` (اختيارية لكنها الأسرع للتراجع): ابنِ الإصدار الجديد في مجلد جديد، شغّل خطوات 1–3 فيه، ثم بدّل الرابط عند الخطوة 8، وأبقِ آخر 3–5 إصدارات.

## 4) سياسة الترحيلات الآمنة

### 4.1 القاعدة الذهبية: additive-first (توافق N-1)

كل إصدار يجب أن يعمل **كوده السابق** فوق **مخططه الجديد** (هذا ما يجعل تراجع الكود وحده كافيًا):

1. **أضِف** (عمود/جدول/فهرس nullable أو بقيمة افتراضية)، لا تغيّر ولا تحذف.
2. **انشر كودًا متوافقًا** مع المخطط القديم والجديد (يقرأ القديم، يكتب القديم والجديد إن لزم).
3. **Backfill** للبيانات القديمة بأمر Artisan منفصل (4.4)، لا داخل الترحيل.
4. **بدّل القراءة/الكتابة** إلى الجديد في إصدار لاحق.
5. **نظّف لاحقًا**: احذف القديم في إصدار ثالث بعد التأكد (وبعد نسخة احتياطية).

### 4.2 عمليات خطرة بالإنتاج عالي الحجم (تجنّبها بالإصدار العادي)

| العملية | الخطر | البديل |
|---|---|---|
| حذف/إعادة تسمية عمود كبير | يكسر الكود السابق فورًا؛ قد يعيد بناء الجدول | additive-first ثم حذف بإصدار لاحق |
| تغيير نوع عمود (`->change()`) | يعيد بناء الجدول ويقفله؛ قد يقتطع بيانات | عمود جديد + backfill + تبديل |
| بناء فهرس على جدول كبير | قد يقفل الجدول | انظر 4.3 |
| تحديث/حذف جماعي داخل الترحيل | يقفل صفوفًا طويلًا تحت قفل النشر | أمر Artisan مع `chunkById` |
| `Schema::drop*` / `dropColumn` | فقدان بيانات غير قابل للعكس | نسخة احتياطية + قرار صريح |

### 4.3 الفهارس

الفهارس على جداول كبيرة **تُقاس على staging بحجم قريب من الإنتاج** قبل النشر. على MySQL 8 / MariaDB استعمل Online DDL (`ALGORITHM=INPLACE, LOCK=NONE`) حيث يُدعَم لعمليتك بالذات (إضافة فهرس ثانوي عادةً)، وتحقق من الدعم في توثيق إصدارك قبل الاعتماد عليه. إن لم يتوفر فالتزم نافذة هادئة أو أداة تغيير مخطط online يختارها فريقك.

### 4.4 ترحيل البيانات الكبيرة: أمر Artisan لا ترحيل

```php
// app/Console/Commands/Backfill….php  (يُشغَّل يدويًا، يمكن إيقافه واستئنافه، idempotent)
Model::query()->whereNull('new_col')->chunkById(1000, function ($rows) {
    foreach ($rows as $row) {
        // احسب القيمة من الرسمي (خدمة)، لا تعدّل أرصدة/مكافآت مباشرة
    }
});
```

لا ترحيل طويلًا تحت قفل النشر. البدء بإحصاء (`--dry-run`) ثم التنفيذ على دفعات، وسجّل التقدّم.

### 4.5 حواجز آلية

- **`release:preflight`** يمسح الترحيلات المعلّقة ويعرض كل خطر (القسم 2). يحتاج الخطر الكاسر (`drop/rename/change/bulk`) مراجعة وقرارًا موثّقًا.
- **حظر الأوامر الهدّامة بالإنتاج**: `db:wipe`، `migrate:fresh`، `migrate:refresh`، `migrate:reset`، `migrate:rollback` تُرفض عند `APP_ENV=production` (`DB::prohibitDestructiveCommands`). الاستثناء الواعي لعملية واحدة: `OPS_ALLOW_DESTRUCTIVE_COMMANDS=true php artisan migrate:rollback --step=1 --force` ثم يزول بانتهاء العملية. **لا يوضع بـ`.env` الدائم.**
- **اختبار ساكن** (`tests/Feature/OperationsE25`) يفشل إن ظهر أي من هذه الأوامر داخل سكربتات النشر أو CI.
- `php artisan db:seed` الكامل ممنوع بالإنتاج (يزرع حسابات بكلمات مرور معروفة).

## 5) التراجع (Rollback)

### 5.1 المبدأ

1. **تراجع الكود** عملية رخيصة وآمنة: أعد الإصدار السابق (رابط `current` أو artifact سابق)، `optimize`، `queue:restart`، `reverb:restart`، أعد تحميل FPM.
2. **تراجع القاعدة ليس آمنًا افتراضيًا.** `migrate:rollback` ليس حلًا تلقائيًا: بعد أن كتب الإصدار الجديد بيانات (جواهر، محاولات، رسائل، طلبات استبدال) قد يخسر التراجع تلك البيانات أو يفسدها، وقد يتعذّر عكس ترحيل بيانات أصلًا.
3. **قاعدة Forward-Fix**: عند وجود خطر بيانات، **صحّح للأمام** (إصدار إصلاح + ترحيل تصحيحي)، ولا تتراجع بالمخطط. التراجع بالمخطط فقط حين: لم يُكتب شيء بالجديد (أو ترحيل بنيوي بحت أُضيف للتو وبدون كتابات عليه)، وبعد نسخة احتياطية جديدة، وبقرار موثّق.
4. تضمن additive-first أن **كود N-1 يعمل فوق مخطط N**: لذلك يكفي غالبًا تراجع الكود.

### 5.2 قاعدة بيانات أحدث من الكود (بعد تراجع الكود)

`production:check` يكشفها: «القاعدة أحدث من الكود: N ترحيل منفَّذ بلا ملف». هذا **متوقع ومقبول** بعد تراجع كود لإصدار additive. لا تُجرِ `rollback`. إن كان الإصدار السابق لا يعمل فوق المخطط الجديد فهذه حادثة (5.3): Forward-Fix أو استعادة.

### 5.3 مصفوفة التراجع

| الفشل | الإجراء |
|---|---|
| حزمة واجهة سيئة (JS/CSS مكسور) | تراجع للإصدار السابق (الأصول مبنية داخله) أو أعد `npm run build` للإصدار السليم؛ لا مساس بالقاعدة |
| تراجع أداء/خطأ 5xx بعد النشر بلا ترحيل | تراجع الكود للإصدار السابق ثم `optimize` + `queue:restart` + `reverb:restart` + إعادة تحميل FPM |
| تراجع في عامل الطابور | أوقف العمال الجدد (`supervisorctl stop`/`systemctl stop`)، أعد كود الإصدار السابق، `queue:restart`؛ **لا retry جماعي** للمهام الفاشلة (راجعها يدويًا: [دليل التشغيل](operations-runbook.md#المهام-الفاشلة)) |
| تراجع في Reverb | أعد كود الإصدار السابق ثم `reverb:restart`. الدردشة تستمر بالحفظ بدون التحديث اللحظي (سلوك E21) |
| تراجع في المجدول | أعد الكود؛ المجدول يقرأ من جديد كل دقيقة. راقب نبض المجدول |
| ترحيل غير متوافق (الكود السابق لا يعمل) | **إجراء حادثة**: Forward-Fix إن أمكن بسرعة؛ وإلا وضع صيانة + استعادة من نسخة سابقة للنشر (تخسر كتابات ما بعد النسخة: RPO) — قرار مالك المنتج |
| ترحيل فشل في منتصفه | لا تعيد التشغيل بلا فهم: افحص `migrations` والجداول (MySQL DDL غير معاملاتي)؛ أكمل بترحيل تصحيحي أو استعد من النسخة |
| إعداد بيئة خاطئ | أعد `.env` السابق (احتفظ بنسخة قبل أي تعديل يدوي) ثم `optimize` + `queue:restart` + `reverb:restart` |
| ملف/اعتماد سر مسرّب | **حادثة أمنية**: [دليل الحوادث](incident-response.md#حادثة-أمنية) وتدوير الأسرار |

### 5.4 متى نستعمل وضع الصيانة

ليس لكل نشر. فقط: (أ) ترحيل لا يتوافق مع الكود السابق ولا يمكن تجزئته، (ب) حادثة تتطلب إيقاف الكتابات. سر الدخول (إن استُعمل) يأتي من `MAINTENANCE_SECRET` بالبيئة ولا يُسجَّل ولا يُوثَّق ولا يُحفظ. وضع الصيانة يعطي 503 لطلبات HTTP، **لكن عمليات العمال تبقى قائمة** (المشرف يعرضها RUNNING) وهي **تتوقف عن سحب المهام** ما لم تُشغَّل بـ`--force`، والمجدول **يتخطى** مهامه؛ لا تخلط بين «الصفحة تعرض صيانة» و«العمال أُوقفوا» ([التفاصيل](operations-runbook.md#وضع-الصيانة)).

### 5.5 تسلسل تراجع الكود (مثال)

```bash
# بعد تبديل رابط current إلى الإصدار السابق (أو إعادة artifact سابق):
APP_RELEASE=<id-السابق> php artisan optimize
php artisan queue:restart
php artisan reverb:restart
# إعادة تحميل PHP-FPM/OPcache بحسب بنيتك
php artisan release:smoke --expect-release=<id-السابق>
```

## 6) فحص الدخان وقرار التراجع

`php artisan release:smoke` (GET فقط، مهلة 5ث، بلا كتابة): `/up`، `/ready`، `/`، `/login`، `/puzzles`، `/competitions`، `/store`، و`/players/{profile}` إن مرّرت `--profile`. المخرجات `PASS/WARN/FAIL` + المسار + الحالة + الزمن. تفاصيل: `tools/smoke/README.md`.

**معايير التراجع الفوري** (خلال 15 دقيقة من النشر): أي FAIL في الدخان، أو `/ready` غير 200، أو ارتفاع 5xx، أو فشل مهام جديدة متكرر. القرار للأقدم على الوردية: تراجع الكود أولًا (رخيص) ثم تحليل السبب.

## 7) تتبّع حادثة بإصدار ومعرّف طلب

كل سطر سجل يحمل `release` (من السياق المشترك) و`request_id` (من `X-Request-ID` الذي يعيده كل رد). من شكوى مستخدم: خذ `X-Request-ID` من الرد/الموازن → ابحث عنه بالسجلات → اقرأ `release` → اعرف الإصدار الذي أنتج الخطأ، وهل هو الإصدار الحالي أم السابق.
````

### `tests/Feature/OperationsE25/DocumentationIntegrityTest.php`

````php
<?php

require_once __DIR__.'/_support.php';

/**
 * E25 - سلامة الوثائق التشغيلية: الملفات المطلوبة موجودة، الأقسام الجوهرية موجودة، والروابط الداخلية (ملفات + عناوين) صحيحة،
 * ولا قيمة سرية حقيقية داخلها. الوثائق جزء من الضمان: دليل استعادة بروابط مكسورة لا ينفع وقت الحادثة.
 */
const E25_DOCS = [
    'docs/environment-variables.md',
    'docs/release-engineering.md',
    'docs/backup-disaster-recovery.md',
    'docs/operations-runbook.md',
    'docs/incident-response.md',
    'docs/production-checklist.md',
    'docs/first-production-deploy.md',
    'docs/security-hardening.md',
];

/** نفس قاعدة GitHub لاشتقاق معرّف العنوان: أحرف صغيرة، حذف الترقيم عدا _ و-، مسافات ⟵ شرطات. */
function e25_slug(string $heading): string
{
    $heading = mb_strtolower(trim(str_replace('`', '', $heading)));
    $out = '';

    foreach (mb_str_split($heading) as $char) {
        if (preg_match('/[\p{L}\p{N}\p{M}_\- ]/u', $char) === 1) {
            $out .= $char;
        }
    }

    return str_replace(' ', '-', $out);
}

function e25_anchors(string $markdown): array
{
    $seen = [];
    $anchors = [];
    $inFence = false;

    foreach (preg_split('/\r\n|\r|\n/', $markdown) as $line) {
        if (str_starts_with(ltrim($line), '```')) {
            $inFence = ! $inFence;

            continue;
        }

        if (! $inFence && preg_match('/^#{1,6}\s+(.*)$/u', $line, $m) === 1) {
            $slug = e25_slug($m[1]);
            $n = $seen[$slug] ?? 0;
            $seen[$slug] = $n + 1;
            $anchors[] = $n === 0 ? $slug : "{$slug}-{$n}";
        }
    }

    return $anchors;
}

/** @return list<array{from: string, target: string}> روابط markdown الداخلية (غير http) داخل نص. */
function e25_links(string $markdown, string $from): array
{
    preg_match_all('/\]\(([^)\s]+)\)/u', $markdown, $m);

    return collect($m[1])->reject(fn ($t) => str_starts_with($t, 'http') || str_starts_with($t, 'mailto:'))->map(fn ($t) => ['from' => $from, 'target' => $t])->all();
}

test('E25-docs: every required operations document exists and is non-trivial', function () {
    foreach (E25_DOCS as $doc) {
        expect(is_file(base_path($doc)))->toBeTrue($doc)
            ->and(strlen(e25_repo_file($doc)))->toBeGreaterThan(1500, "{$doc} looks empty");
    }

    expect(is_file(base_path('tools/smoke/README.md')))->toBeTrue();
});

test('E25-docs: internal links and anchors in the E25 documents all resolve', function () {
    $broken = [];
    $links = [];

    foreach (array_merge(E25_DOCS, ['tools/smoke/README.md']) as $doc) {
        $links = array_merge($links, e25_links(e25_repo_file($doc), $doc));
    }

    // ملاحظات E25 أعلى الوثائق القديمة (أسطر تبدأ بـ«>»)
    foreach (['docs/production-deployment.md', 'docs/backup-restore.md', 'docs/rollback.md', 'docs/pre-launch-checklist.md', 'docs/smoke-tests.md'] as $doc) {
        $notes = collect(preg_split('/\r\n|\r|\n/', e25_repo_file($doc)))->filter(fn ($l) => str_starts_with($l, '>'))->implode("\n");
        $links = array_merge($links, e25_links($notes, $doc));
    }

    foreach ($links as ['from' => $from, 'target' => $target]) {
        [$path, $fragment] = array_pad(explode('#', $target, 2), 2, null);
        $file = $path === '' ? $from : str_replace('\\', '/', (string) realpath(dirname(base_path($from)).'/'.$path));

        if ($path !== '' && ($file === '' || ! is_file($file))) {
            $broken[] = "{$from} → {$target} (ملف غير موجود)";

            continue;
        }

        if ($fragment !== null && str_ends_with($file, '.md')) {
            $content = $path === '' ? e25_repo_file($from) : (string) file_get_contents($file);

            if (! in_array(rawurldecode($fragment), e25_anchors($content), true)) {
                $broken[] = "{$from} → {$target} (عنوان غير موجود)";
            }
        }
    }

    expect($broken)->toBe([]);
});

test('E25-docs: the backup document defines RPO/RTO, retention, offsite, encryption, restore runbook, guard, drill and DB disaster scenarios', function () {
    $doc = e25_repo_file('docs/backup-disaster-recovery.md');

    foreach (['RPO', 'RTO', 'الاحتفاظ', 'Offsite', 'التشفير', 'دليل الاستعادة', 'حارس الاستعادة', 'تمرين الاستعادة', 'تلف القاعدة', 'حذف عرضي', 'ترحيل سيئ', 'امتلاء التخزين', 'القاعدة غير متاحة', 'APP_KEY'] as $needle) {
        expect($doc)->toContain($needle);
    }
});

test('E25-docs: the incident-response document defines three severities, the six phases and a blameless postmortem', function () {
    $doc = e25_repo_file('docs/incident-response.md');

    foreach (['SEV-1', 'SEV-2', 'SEV-3', 'Detect', 'Acknowledge', 'Contain', 'Recover', 'Verify', 'Postmortem', 'بلا لوم'] as $needle) {
        expect($doc)->toContain($needle);
    }
});

test('E25-docs: the checklist and first-deploy runbook cover DNS, TLS, env, DB, Redis, queue, scheduler, Reverb, backups and rollback', function () {
    $checklist = e25_repo_file('docs/production-checklist.md');
    $first = e25_repo_file('docs/first-production-deploy.md');

    foreach (['DNS', 'TLS', 'APP_DEBUG=false', 'APP_KEY', 'Redis', 'queue:work', 'schedule:run', 'Reverb', 'تمرين استعادة', 'التراجع', 'SESSION_SECURE_COOKIE=true'] as $needle) {
        expect($checklist)->toContain($needle);
    }

    foreach (['rbac:migrate-legacy-roles', 'AdminUserSeeder', 'ADMIN_BOOTSTRAP_PASSWORD', 'release:smoke', 'production:check', 'php artisan optimize'] as $needle) {
        expect($first)->toContain($needle);
    }
});

test('E25-docs: the environment-variable contract names every variable the E25 code reads, classified', function () {
    $doc = e25_repo_file('docs/environment-variables.md');

    foreach (['REQUIRED', 'SECRET', 'OPTIONAL', 'INFRASTRUCTURE-SPECIFIC', 'APP_RELEASE', 'OPS_SCHEDULER_STALE_SECONDS', 'OPS_READY_FAILS_ON_SCHEDULER', 'OPS_ALLOW_DESTRUCTIVE_COMMANDS', 'REVERB_ALLOWED_ORIGINS', 'ADMIN_BOOTSTRAP_PASSWORD', 'TRUSTED_PROXIES', 'PERF_READINESS_TOKEN', 'MAINTENANCE_SECRET'] as $needle) {
        expect($doc)->toContain($needle);
    }
});

test('E25-docs: documents embed no real secret and show only placeholders for sensitive variables', function () {
    foreach (E25_DOCS as $doc) {
        $content = e25_repo_file($doc);

        // أسطر تعيين بقيمة حقيقية الشكل لمفاتيح حساسة (غير placeholder)
        preg_match_all('/^\s*(?:export\s+)?([A-Z][A-Z0-9_]*(?:PASSWORD|SECRET|TOKEN|KEY))=([^\s#`]+)/m', $content, $matches, PREG_SET_ORDER);

        foreach ($matches as [$all, $name, $value]) {
            expect(preg_match('/^(<[^>]+>|\$\{?[A-Z_]+\}?|\$\(.*\)|"?\$[A-Z_]+"?|age1[a-z0-9]*|\.{3}|…|null|"")$/i', trim($value, '"\'')))->toBe(1, "{$doc}: {$name} has a non-placeholder value");
        }
    }
});

test('E25-docs: the anchors the other documents rely on exist in the security hardening document', function () {
    $anchors = e25_anchors(e25_repo_file('docs/security-hardening.md'));

    expect($anchors)->toContain('تدوير-الأسرار-p4')->toContain('app_key-تحذير-خاص');
});
````

### `tests/Feature/OperationsE25/MigrationRiskScannerTest.php`

```php
<?php

use App\Services\Operations\MigrationRiskScanner;

/**
 * E25 (C5) - ماسح مخاطر الترحيلات: يرصد العمليات الهدّامة/المقفلة في up() فقط، ولا يعاقب ما هو additive.
 */
function e25_migration(string $up, string $down = ''): string
{
    return "<?php\nreturn new class extends Migration {\n    public function up(): void\n    {\n        {$up}\n    }\n\n    public function down(): void\n    {\n        {$down}\n    }\n};";
}

test('E25-C5: additive migrations are not flagged', function () {
    $source = e25_migration("Schema::create('badges', function (Blueprint \$t) { \$t->id(); \$t->string('name')->nullable(); \$t->timestamps(); });\nSchema::table('users', fn (\$t) => \$t->unsignedInteger('score')->default(0));");

    expect((new MigrationRiskScanner)->scan($source))->toBe([]);
});

test('E25-C5: each dangerous operation maps to its own risk code', function (string $up, string $code, bool $breaking) {
    $scanner = new MigrationRiskScanner;

    expect($scanner->scan(e25_migration($up)))->toContain($code)
        ->and($scanner->isBreaking($code))->toBe($breaking)
        ->and($scanner->describe($code))->not->toBe($code);   // وصف عربي مقروء لا الرمز الخام
})->with([
    'drop column' => ["Schema::table('users', fn (\$t) => \$t->dropColumn('legacy'));", 'drop_column', true],
    'rename column' => ["Schema::table('users', fn (\$t) => \$t->renameColumn('a', 'b'));", 'rename_column', true],
    'rename table' => ["Schema::rename('old_t', 'new_t');", 'rename_column', true],
    'change type' => ["Schema::table('users', fn (\$t) => \$t->string('name', 50)->change());", 'change_type', true],
    'drop table' => ["Schema::dropIfExists('legacy');", 'drop_table', true],
    'drop index' => ["Schema::table('users', fn (\$t) => \$t->dropIndex('users_x_index'));", 'drop_constraint', false],
    'add index' => ["Schema::table('puzzle_attempts', fn (\$t) => \$t->index(['user_id', 'created_at']));", 'add_index', false],
    'bulk update' => ["DB::table('users')->where('x', 1)->update(['y' => 2]);", 'bulk_data', true],
    'bulk delete' => ["DB::table('users')->delete();", 'bulk_data', true],
    'raw alter' => ["DB::statement('ALTER TABLE users ADD COLUMN z INT');", 'raw_ddl', false],
    'eloquent in migration' => ["User::where('x', 1)->update(['y' => 2]);", 'eloquent_in_migration', false],
]);

test('E25-C5: only up() is scanned: destructive down() methods are normal', function () {
    $source = e25_migration("Schema::create('t', fn (\$b) => \$b->id());", "Schema::dropIfExists('t');\n        Schema::table('users', fn (\$t) => \$t->dropColumn('x'));");

    expect((new MigrationRiskScanner)->scan($source))->toBe([]);
});

test('E25-C5: a file without up() yields nothing and unknown codes degrade gracefully', function () {
    $scanner = new MigrationRiskScanner;

    expect($scanner->scan('<?php return [];'))->toBe([])
        ->and($scanner->isBreaking('nope'))->toBeFalse()
        ->and($scanner->describe('nope'))->toBe('nope');
});

test('E25-C5: a mixed migration reports every risk it contains', function () {
    $source = e25_migration("Schema::table('users', function (\$t) { \$t->dropColumn('a'); \$t->index('b'); });\nDB::table('users')->update(['b' => 1]);");

    expect((new MigrationRiskScanner)->scan($source))->toEqualCanonicalizing(['drop_column', 'add_index', 'bulk_data']);
});
```

### `tests/Feature/OperationsE25/OperationsCenterSignalsTest.php`

```php
<?php

use App\Filament\Pages\OperationsCenter;
use App\Models\User;
use App\Support\SchedulerHeartbeat;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

/**
 * E25 (11-12) - مركز العمليات: إشارات النظام تُعرض لمن يملك system.audit_view فقط، وبلا أي سر.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Cache::forget('ops:signals:v1');
    Cache::forget(app(SchedulerHeartbeat::class)->key());
});

function e25_super_admin(): User
{
    $user = User::factory()->create();
    $user->assignRole('super-admin');

    return $user;
}

test('E25-11: the system signals render for a user with system.audit_view (release, scheduler, queue, readiness)', function () {
    config(['operations.release.id' => 'b983867a5c214ee19c8edfc99319f04b8d3427c5']);
    app(SchedulerHeartbeat::class)->beat();

    $this->actingAs(e25_super_admin());

    Livewire::test(OperationsCenter::class)
        ->assertSee('صحة النظام')
        ->assertSee('b983867')
        ->assertSee('سليم')               // نبض المجدول ok
        ->assertSee('منتظرة')             // الطابور
        ->assertSee('DB OK')
        ->assertSee('Cache OK');
});

test('E25-11: the scheduler tile reflects an unknown, stale and down heartbeat', function () {
    $this->actingAs(e25_super_admin());

    Livewire::test(OperationsCenter::class)->assertSee('غير معروف');

    Cache::forget('ops:signals:v1');
    $this->travelTo(now()->startOfMinute());
    app(SchedulerHeartbeat::class)->beat();
    $this->travel(400)->seconds();
    Cache::forget('ops:signals:v1');

    Livewire::test(OperationsCenter::class)->assertSee('متأخر');

    $this->travel(700)->seconds();
    Cache::forget('ops:signals:v1');

    Livewire::test(OperationsCenter::class)->assertSee('متوقف');
});

test('E25-11: a debug-enabled app is flagged prominently', function () {
    config(['app.debug' => true]);
    $this->actingAs(e25_super_admin());

    Livewire::test(OperationsCenter::class)->assertSee('APP_DEBUG مفعَّلة');
});

test('E25-12: the signals never expose secrets, hostnames, credentials or the app key', function () {
    config([
        'database.connections.mysql.password' => 'OpsCenterDbPassword-VALUE-123',
        'mail.mailers.smtp.password' => 'OpsCenterMailPassword-VALUE-456',
        'broadcasting.connections.reverb.secret' => 'OpsCenterReverbSecret-VALUE-789',
        'database.redis.default.password' => 'OpsCenterRedisPassword-VALUE-012',
        'mail.mailers.smtp.host' => 'smtp.private-host.example',
    ]);
    $this->actingAs(e25_super_admin());

    $html = Livewire::test(OperationsCenter::class)->html();

    foreach (['OpsCenterDbPassword-VALUE-123', 'OpsCenterMailPassword-VALUE-456', 'OpsCenterReverbSecret-VALUE-789', 'OpsCenterRedisPassword-VALUE-012', 'smtp.private-host.example', (string) config('app.key'), substr((string) config('app.key'), 7)] as $secret) {
        expect($html)->not->toContain($secret);
    }

    // أسماء المشغّلات فقط (driver names)
    expect($html)->toContain('cache=')->toContain('queue=');
});

test('E25-12: the signal snapshot itself contains only whitelisted keys and no config values', function () {
    config(['database.connections.mysql.password' => 'SnapshotPassword-VALUE-345']);

    $snapshot = app(App\Services\Operations\OperationalSignals::class)->compute();
    $dump = json_encode($snapshot, JSON_UNESCAPED_UNICODE);

    expect(array_keys($snapshot))->toBe(['release', 'environment', 'debug', 'maintenance', 'drivers', 'scheduler', 'queue', 'queue_warnings', 'readiness', 'generated_at'])
        ->and($dump)->not->toContain('SnapshotPassword-VALUE-345')
        ->and($dump)->not->toContain((string) config('app.key'));
});

test('E25-12: a user without system.audit_view does not get the system signals at all', function () {
    $moderator = User::factory()->create();
    $moderator->assignRole('moderator');
    config(['operations.release.id' => 'hidden-release-1']);

    expect($moderator->can('system.audit_view'))->toBeFalse();

    $this->actingAs($moderator);
    $page = Livewire::test(OperationsCenter::class);

    expect($page->instance()->getSystemSignals())->toBeNull();
    $page->assertDontSee('صحة النظام')->assertDontSee('hidden-release-1');
});

test('E25: the signals snapshot is cached for 30 seconds (the page does not recompute on every refresh)', function () {
    $this->actingAs(e25_super_admin());
    $queries = 0;
    $signals = app(App\Services\Operations\OperationalSignals::class);

    $first = $signals->snapshot();

    Illuminate\Support\Facades\DB::listen(function () use (&$queries) {
        $queries++;
    });
    $second = $signals->snapshot();

    expect($second)->toBe($first)->and($queries)->toBe(0);
});
```

### `tests/Feature/OperationsE25/OpsScriptsSafetyTest.php`

```php
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
```

### `tests/Feature/OperationsE25/ProductionCheckCommandTest.php`

```php
<?php

require_once __DIR__.'/_support.php';

use App\Services\Operations\ProductionChecker;
use App\Support\SecretRedactor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * E25 (1-5) - production:check: ينجح بإعداد سليم، يفشل عند غياب APP_KEY، لا يطبع قيمًا سرية، يرفع APP_DEBUG، ويحذّر/يفشل عند URL/كوكي غير آمنَين.
 */
test('E25-1: production:check succeeds (exit 0) with a safe configuration', function () {
    [$code, $data] = e25_check(['app.debug' => false, 'app.url' => 'https://ahjiyat.example.com']);

    expect($code)->toBe(0)
        ->and($data['ready'])->toBeTrue()
        ->and($data['summary']['fail'])->toBe(0)
        ->and(e25_statuses($data, 'APP_KEY'))->toBe(['PASS'])
        ->and(e25_statuses($data, 'APP_DEBUG'))->toBe(['PASS']);
});

test('E25-1: the human-readable output groups checks, uses PASS/WARN/FAIL and ends with a summary line', function () {
    config(['app.debug' => false, 'app.url' => 'https://ahjiyat.example.com']);

    $this->artisan('production:check')
        ->expectsOutputToContain('PASS')
        ->expectsOutputToContain('النتيجة:')
        ->assertExitCode(0);
});

test('E25-2: a missing APP_KEY fails with a blocking error', function () {
    [$code, $data] = e25_check(['app.key' => '', 'app.debug' => false]);

    expect($code)->toBe(1)
        ->and($data['ready'])->toBeFalse()
        ->and(e25_statuses($data, 'APP_KEY'))->toBe(['FAIL']);
});

test('E25-2: an APP_KEY with the wrong length for the cipher fails', function () {
    [$code, $data] = e25_check(['app.key' => 'base64:'.base64_encode('too-short'), 'app.debug' => false]);

    expect($code)->toBe(1)->and(e25_statuses($data, 'APP_KEY'))->toBe(['FAIL']);
});

test('E25-2: an unreachable database fails without repeating the failure for the migrations step', function () {
    $original = config('database.default');
    config(['database.connections.e25_broken' => ['driver' => 'sqlite', 'database' => '/nonexistent/dir/none.sqlite', 'prefix' => '', 'foreign_key_constraints' => true]]);
    config(['database.default' => 'e25_broken', 'app.debug' => false]);

    try {
        [$code, $data] = e25_check();
    } finally {
        config(['database.default' => $original]); // كي لا ينهار تفكيك RefreshDatabase على اتصال معطوب
    }

    expect($code)->toBe(1)
        ->and(e25_statuses($data, 'DB_CONNECTION'))->toContain('FAIL')
        ->and(e25_statuses($data, 'migrations'))->not->toContain('FAIL');
});

test('E25-3: no secret value is ever printed (text or JSON output)', function () {
    $secrets = [
        'dbpw' => 'DbPassw0rd-VALUE-a1b2c3',
        'mail' => 'MailPassw0rd-VALUE-d4e5f6',
        'reverb' => 'ReverbSecret-VALUE-g7h8i9j0k1l2m3',
        'redis' => 'RedisPassw0rd-VALUE-n4o5p6',
        'token' => 'ApiToken-VALUE-q7r8s9',
    ];

    config([
        'database.connections.mysql.password' => $secrets['dbpw'],
        'mail.mailers.smtp.password' => $secrets['mail'],
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.app_id' => '12345',
        'broadcasting.connections.reverb.key' => 'public-key',
        'broadcasting.connections.reverb.secret' => $secrets['reverb'],
        'database.redis.default.password' => $secrets['redis'],
        'services.thirdparty.api_token' => $secrets['token'],
        'app.debug' => false,
    ]);
    $appKey = (string) config('app.key');

    [, , $json] = e25_check();
    Artisan::call('production:check');
    $text = Artisan::output();

    foreach ([$json, $text] as $output) {
        expect($output)->not->toContain($appKey)
            ->and($output)->not->toContain(substr($appKey, 7)); // الجزء base64 من المفتاح

        foreach ($secrets as $secret) {
            expect($output)->not->toContain($secret);
        }
    }
});

test('E25-3: the SecretRedactor scrubs known secret values from arbitrary text as a second safety layer', function () {
    config(['database.connections.mysql.password' => 'Sup3rSecretValue-9981', 'services.thirdparty.webhook_url' => 'https://hooks.example/abc-very-secret-123456']);

    $secrets = SecretRedactor::secrets();
    $clean = SecretRedactor::scrub('connect failed using Sup3rSecretValue-9981 at https://hooks.example/abc-very-secret-123456 for '.config('app.key'), $secrets);

    expect($clean)->not->toContain('Sup3rSecretValue-9981')
        ->and($clean)->not->toContain('abc-very-secret-123456')
        ->and($clean)->not->toContain((string) config('app.key'))
        ->and($clean)->toContain('[redacted]');
});

test('E25-3: a step that throws is reported generically without leaking the exception message', function () {
    $secretMessage = 'boom-LEAKY-exception-text-5551';
    Cache::shouldReceive('store')->andThrow(new RuntimeException($secretMessage));

    [, $data, $raw] = e25_check(['app.debug' => false]);

    expect($raw)->not->toContain($secretMessage);
});

test('E25-4: APP_DEBUG=true is a blocking failure (production)', function () {
    e25_as_production();
    [$code, $data] = e25_check(['app.debug' => true, 'app.url' => 'https://ahjiyat.example.com']);

    expect($code)->toBe(1)
        ->and($data['production'])->toBeTrue()
        ->and(e25_statuses($data, 'APP_DEBUG'))->toBe(['FAIL']);
});

test('E25-4: APP_DEBUG=true blocks even outside production', function () {
    [$code, $data] = e25_check(['app.debug' => true]);

    expect($code)->toBe(1)->and(e25_statuses($data, 'APP_DEBUG'))->toBe(['FAIL']);
});

test('E25-5: an insecure APP_URL and cookie are warnings outside production but failures in production', function () {
    config(['app.debug' => false, 'app.url' => 'http://ahjiyat.example.com', 'session.secure' => null]);

    [$code, $data] = e25_run('production:check');
    expect(e25_statuses($data, 'APP_URL'))->toBe(['WARN'])
        ->and(e25_statuses($data, 'SESSION_SECURE_COOKIE'))->toBe(['INFO'])
        ->and($code)->toBe(0);

    e25_as_production();
    [$code, $data] = e25_run('production:check');
    expect(e25_statuses($data, 'APP_URL'))->toBe(['FAIL'])
        ->and(e25_statuses($data, 'SESSION_SECURE_COOKIE'))->toBe(['FAIL'])
        ->and($code)->toBe(1);
});

test('E25-5: a localhost APP_URL is flagged and a secure cookie passes', function () {
    e25_as_production();
    [, $data] = e25_check(['app.debug' => false, 'app.url' => 'https://localhost', 'session.secure' => true]);

    expect(e25_statuses($data, 'APP_URL'))->toContain('FAIL')
        ->and(e25_statuses($data, 'SESSION_SECURE_COOKIE'))->toBe(['PASS']);
});

test('E25-5: sqlite, the file session driver and permissive trusted-proxies get the right severity in production', function () {
    e25_as_production();
    config(['app.debug' => false, 'app.url' => 'https://ahjiyat.example.com', 'trustedproxy.proxies' => '*']);

    [, $data] = e25_run('production:check');

    expect(e25_statuses($data, 'DB_CONNECTION'))->toContain('FAIL')   // sqlite لا يصلح للإنتاج
        ->and(e25_statuses($data, 'TRUSTED_PROXIES'))->toBe(['WARN']);
});

test('E25: --json output is machine readable and every check carries only status, group, setting and message', function () {
    [, $data] = e25_check(['app.debug' => false]);

    expect($data)->toHaveKeys(['production', 'ready', 'summary', 'checks'])
        ->and($data['summary'])->toHaveKeys(['pass', 'warn', 'fail', 'info']);

    foreach ($data['checks'] as $check) {
        expect(array_keys($check))->toBe(['status', 'group', 'setting', 'message'])
            ->and($check['status'])->toBeIn(['PASS', 'WARN', 'FAIL', 'INFO']);
    }
});

test('E25: production:check is read-only (no write queries, no cache mutation)', function () {
    config(['app.debug' => false]);
    $store = Cache::getStore();
    $property = new ReflectionProperty($store, 'storage');
    $before = $property->getValue($store);
    $writes = [];

    DB::listen(function ($query) use (&$writes) {
        if (preg_match('/^\s*(insert|update|delete|create|alter|drop|truncate|replace)\b/i', $query->sql) === 1) {
            $writes[] = $query->sql;
        }
    });

    Artisan::call('production:check');

    expect($writes)->toBe([])
        ->and($property->getValue($store))->toBe($before);
});

test('E25: ProductionChecker source contains no mutating calls (static guard)', function () {
    $source = e25_repo_file('app/Services/Operations/ProductionChecker.php');

    foreach (['Cache::put', 'Cache::forever', 'Cache::forget', 'Cache::flush', 'Cache::add', 'file_put_contents', 'touch(', 'unlink(', 'Artisan::call', 'DB::statement', 'DB::insert', 'DB::update', 'DB::delete', '->insert(', '->update(', '->delete(', 'Storage::put', 'mkdir('] as $needle) {
        expect(str_contains($source, $needle))->toBeFalse("ProductionChecker must stay read-only but contains {$needle}");
    }

    expect(app(ProductionChecker::class))->toBeInstanceOf(ProductionChecker::class);
});
```

### `tests/Feature/OperationsE25/QueueSignalsTest.php`

```php
<?php

require_once __DIR__.'/_support.php';

use App\Services\Operations\QueueSignals;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * E25 (J/S) - إشارات الطابور للقراءة فقط: العدد المنتظر، عمر أقدم مهمة، المهام الفاشلة؛ بلا حمولة ولا أسماء فئات.
 */
function e25_job(array $overrides = []): void
{
    DB::table('jobs')->insert(array_merge([
        'queue' => 'default',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\SecretJobName', 'data' => ['command' => 'serialized-secret-payload']]),
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => now()->getTimestamp(),
        'created_at' => now()->getTimestamp(),
    ], $overrides));
}

beforeEach(function () {
    config(['queue.default' => 'database']);
});

test('E25-J: pending counts only available, unreserved jobs and reports the oldest age', function () {
    $this->travelTo(now()->startOfMinute());
    e25_job(['available_at' => now()->subMinutes(30)->getTimestamp()]);   // قديمة ومنتظرة
    e25_job(['available_at' => now()->subMinutes(5)->getTimestamp()]);    // منتظرة
    e25_job(['available_at' => now()->subMinutes(10)->getTimestamp(), 'reserved_at' => now()->getTimestamp()]);   // قيد المعالجة: لا تُحسب
    e25_job(['available_at' => now()->addHour()->getTimestamp()]);        // مؤجَّلة للمستقبل: لا تُحسب

    $snapshot = app(QueueSignals::class)->snapshot();

    expect($snapshot['driver'])->toBe('database')
        ->and($snapshot['pending'])->toBe(2)
        ->and($snapshot['oldest_age_seconds'])->toBe(1800);
});

test('E25-J: an empty queue is healthy: zero pending, zero age, no warnings', function () {
    $snapshot = app(QueueSignals::class)->snapshot();

    expect($snapshot['pending'])->toBe(0)
        ->and($snapshot['oldest_age_seconds'])->toBe(0)
        ->and(app(QueueSignals::class)->warnings($snapshot))->toBe([]);
});

test('E25-J: a backlog older than the threshold and failed jobs produce warnings', function () {
    $this->travelTo(now()->startOfMinute());
    e25_job(['available_at' => now()->subMinutes(20)->getTimestamp()]);
    DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'boom', 'failed_at' => now()]);

    $signals = app(QueueSignals::class);
    $warnings = $signals->warnings($signals->snapshot());

    expect($warnings)->toHaveCount(2)
        ->and($warnings[0])->toContain('تراكم')->toContain('20 دقيقة')
        ->and($warnings[1])->toContain('1 مهمة فاشلة');
});

test('E25-J: thresholds are configurable and can be switched off with 0', function () {
    $this->travelTo(now()->startOfMinute());
    e25_job(['available_at' => now()->subMinutes(20)->getTimestamp()]);
    $signals = app(QueueSignals::class);
    $snapshot = $signals->snapshot();

    config(['operations.queue.backlog_warn_age_seconds' => 3600]);
    expect($signals->warnings($snapshot))->toBe([]);

    config(['operations.queue.backlog_warn_age_seconds' => 60]);
    expect($signals->warnings($snapshot))->toHaveCount(1);

    config(['operations.queue.backlog_warn_age_seconds' => 0]);
    expect($signals->warnings($snapshot))->toBe([]);
});

test('E25-J: the snapshot exposes numbers only, never payloads or job class names', function () {
    e25_job();

    $dump = json_encode(app(QueueSignals::class)->snapshot());

    expect($dump)->not->toContain('SecretJobName')->and($dump)->not->toContain('serialized-secret-payload');
});

test('E25-J: reading signals never writes (static and runtime)', function () {
    e25_job();
    $writes = [];
    DB::listen(function ($query) use (&$writes) {
        if (preg_match('/^\s*(insert|update|delete|create|alter|drop|truncate|replace)\b/i', $query->sql) === 1) {
            $writes[] = $query->sql;
        }
    });

    app(QueueSignals::class)->snapshot();

    expect($writes)->toBe([])
        ->and(preg_match('/->(insert|update|delete|truncate|pop|push|release|forget)\(|Queue::(push|pop|later)\b/', e25_repo_file('app/Services/Operations/QueueSignals.php')))->toBe(0);
});

test('E25-J: a failing read degrades to unknown (null) instead of throwing', function () {
    config(['queue.connections.database.table' => 'table_that_does_not_exist', 'queue.failed.table' => 'also_missing']);

    $snapshot = app(QueueSignals::class)->snapshot();

    expect($snapshot['pending'])->toBeNull()
        ->and($snapshot['oldest_age_seconds'])->toBeNull()
        ->and($snapshot['failed'])->toBeNull()
        ->and(app(QueueSignals::class)->warnings($snapshot))->toBe([]);
});

test('E25-J: the sync driver has no queue to measure (pending is unknown)', function () {
    config(['queue.default' => 'sync']);

    expect(app(QueueSignals::class)->snapshot()['pending'])->toBeNull();
});

test('E25-J: production:check surfaces queue warnings by name without leaking payloads', function () {
    $this->travelTo(now()->startOfMinute());
    e25_job(['available_at' => now()->subMinutes(45)->getTimestamp()]);
    config(['app.debug' => false]);

    [, $data, $raw] = e25_run('production:check');

    expect(collect($data['checks'])->where('setting', 'queue')->pluck('status')->all())->toContain('WARN')
        ->and($raw)->not->toContain('SecretJobName')->and($raw)->not->toContain('serialized-secret-payload');
});
```

### `tests/Feature/OperationsE25/ReadinessAndReleaseTest.php`

```php
<?php

require_once __DIR__.'/_support.php';

use App\Providers\AppServiceProvider;
use App\Support\ReleaseInfo;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;

/**
 * E25 (6-8) - /ready يبقى خاليًا من الأسرار، معرّف الإصدار يظهر فقط بصيغة آمنة، والسجلات تحمل release وrequest_id.
 */
afterEach(function () {
    // اللقطة المشتركة لسياق السجل تبقى بين الاختبارات داخل العملية؛ نصفّرها كي لا يتأثر غيرنا.
    Log::flushSharedContext();
    Context::flush();

    if ($this->app->isDownForMaintenance()) {
        $this->app->maintenanceMode()->deactivate();
    }
});

test('E25-6: /ready exposes only status and per-check ok/ms (no secret, host, version or path)', function () {
    config(['operations.release.id' => null]);

    $response = $this->getJson('/ready')->assertOk();
    $body = $response->getContent();

    expect(array_keys($response->json()))->toBe(['status', 'checks'])
        ->and(array_keys($response->json('checks')))->toBe(['database', 'cache'])
        ->and($body)->not->toContain((string) config('app.key'))
        ->and($body)->not->toContain(substr((string) config('app.key'), 7))
        ->and($body)->not->toContain(PHP_VERSION)
        ->and($body)->not->toContain(app()->version())
        ->and($body)->not->toContain(base_path())
        ->and($body)->not->toContain((string) gethostname())
        ->and($body)->not->toContain('sqlite')
        ->and($body)->not->toContain('localhost')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');
});

test('E25-6: a configured readiness token is enforced and the denial leaks nothing', function () {
    config(['performance.observability.readiness_token' => 'e25-health-token-value', 'operations.release.id' => 'rel-1']);

    $denied = $this->getJson('/ready')->assertForbidden();
    expect($denied->getContent())->not->toContain('rel-1')->and($denied->json())->toBe(['status' => 'forbidden']);

    $this->withHeaders(['X-Health-Token' => 'e25-health-token-value'])->getJson('/ready')->assertOk();
});

test('E25-6: /ready answers 503 (still without details) when the database is not reachable', function () {
    $original = config('database.default');
    config(['database.connections.e25_broken' => ['driver' => 'sqlite', 'database' => '/nonexistent/dir/none.sqlite', 'prefix' => '', 'foreign_key_constraints' => true]]);
    config(['database.default' => 'e25_broken']);

    try {
        $response = $this->getJson('/ready');
    } finally {
        config(['database.default' => $original]);
    }

    $response->assertStatus(503);

    expect($response->json('status'))->toBe('unavailable')
        ->and($response->json('checks.database.ok'))->toBeFalse()
        ->and($response->getContent())->not->toContain('nonexistent')
        ->and($response->getContent())->not->toContain((string) config('app.key'));
});

test('E25-7: the release id is shown in /ready only when set and safe, shortened if it is a full SHA', function () {
    config(['operations.release.id' => 'b983867a5c214ee19c8edfc99319f04b8d3427c5']);
    expect($this->getJson('/ready')->json('release'))->toBe('b983867');

    config(['operations.release.id' => 'v25.1-rc_2']);
    expect($this->getJson('/ready')->json('release'))->toBe('v25.1-rc_2');

    config(['operations.release.id' => null]);
    expect($this->getJson('/ready')->json())->not->toHaveKey('release');
});

test('E25-7: unsafe release identifiers are ignored everywhere', function (string $unsafe) {
    config(['operations.release.id' => $unsafe]);

    expect(ReleaseInfo::id())->toBeNull()
        ->and(ReleaseInfo::short())->toBeNull()
        ->and(ReleaseInfo::isConfigured())->toBeFalse()
        ->and($this->getJson('/ready')->json())->not->toHaveKey('release');
})->with([
    'spaces' => 'a b',
    'newline injection' => "rel\nInjected: 1",
    'html' => '<script>alert(1)</script>',
    'assignment-looking secret' => 'password=hunter2',
    'too long' => str_repeat('a', 65),
    'empty' => '',
    'unicode' => 'إصدار-1',
]);

test('E25-7: /up (liveness) never carries the release or any detail', function () {
    config(['operations.release.id' => 'rel-visible']);

    expect($this->get('/up')->assertOk()->getContent())->not->toContain('rel-visible');
});

test('E25-8: log lines carry the release (shared context) when a release is configured', function () {
    config(['operations.release.id' => 'b983867a5c214ee19c8edfc99319f04b8d3427c5']);

    $provider = $this->app->getProvider(AppServiceProvider::class);
    (new ReflectionMethod($provider, 'registerOperationalGuards'))->invoke($provider);

    $handler = e25_memory_log_handler();
    Log::channel('e25mem')->warning('release correlation probe');

    $record = $handler->getRecords()[0];
    $all = json_encode([$record->context, $record->extra], JSON_UNESCAPED_UNICODE);

    expect($all)->toContain('"release":"b983867"');
});

test('E25-8: log lines carry the request id of the current request (E24 context)', function () {
    $this->withHeaders(['X-Request-ID' => 'e25-req-id-000001'])->get('/up')->assertOk();

    $handler = e25_memory_log_handler();
    Log::channel('e25mem')->warning('request correlation probe');

    $record = $handler->getRecords()[0];
    $all = json_encode([$record->context, $record->extra], JSON_UNESCAPED_UNICODE);

    expect($all)->toContain('e25-req-id-000001');
});

test('E25-8: without a configured release no release key is added to log lines', function () {
    config(['operations.release.id' => null]);

    $provider = $this->app->getProvider(AppServiceProvider::class);
    (new ReflectionMethod($provider, 'registerOperationalGuards'))->invoke($provider);

    $handler = e25_memory_log_handler();
    Log::channel('e25mem')->warning('no release probe');

    $record = $handler->getRecords()[0];

    expect(json_encode([$record->context, $record->extra]))->not->toContain('"release"');
});

test('E25: during maintenance /up (liveness) stays 200 while /ready and public pages answer 503, without leaking anything', function () {
    $this->app->maintenanceMode()->activate(['retry' => 60, 'status' => 503]);

    $up = $this->get('/up');
    $ready = $this->getJson('/ready');
    $page = $this->get('/puzzles');

    expect($up->status())->toBe(200)
        ->and($ready->status())->toBe(503)
        ->and($page->status())->toBe(503)
        ->and($ready->getContent())->not->toContain((string) config('app.key'))
        ->and($page->getContent())->not->toContain((string) config('app.key'));
});

if (! function_exists('e25_memory_log_handler')) {
    /** قناة سجل في الذاكرة (Monolog TestHandler) لقراءة السجلات المنتجة فعليًا مع سياقها الحقيقي. */
    function e25_memory_log_handler(): TestHandler
    {
        config(['logging.channels.e25mem' => ['driver' => 'monolog', 'handler' => TestHandler::class, 'level' => 'debug']]);
        Log::forgetChannel('e25mem');

        /** @var TestHandler $handler */
        $handler = Log::channel('e25mem')->getLogger()->getHandlers()[0];

        return $handler;
    }
}
```

### `tests/Feature/OperationsE25/ReleasePreflightTest.php`

```php
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
```

### `tests/Feature/OperationsE25/SchedulerHeartbeatTest.php`

```php
<?php

require_once __DIR__.'/_support.php';

use App\Support\SchedulerHeartbeat;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

/**
 * E25 (9-10) - نبض المجدول: يُحدَّث، وتُحسب حالته من عمره (ok/stale/down/unknown)، ولا يُسقط /ready إلا بخيار صريح.
 */
beforeEach(function () {
    Cache::forget(app(SchedulerHeartbeat::class)->key());
});

test('E25-9: the heartbeat task is registered last, every minute, and running it updates the heartbeat', function () {
    Artisan::call('schedule:list');
    $list = Artisan::output();

    expect($list)->toContain('scheduler-heartbeat')
        ->and(strpos($list, 'scheduler-heartbeat'))->toBeGreaterThan(strpos($list, 'campaigns-sync-availability'));

    $schedule = app(Illuminate\Console\Scheduling\Schedule::class);
    $event = collect($schedule->events())->first(fn ($e) => $e->description === 'scheduler-heartbeat');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *')
        // قاعدة E24 تسري عليه أيضًا: قفل تداخل محدود (دقائق لا 24 ساعة) + تنفيذ على خادم واحد.
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBeLessThanOrEqual(720)
        ->and($event->onOneServer)->toBeTrue()
        ->and(app(SchedulerHeartbeat::class)->lastBeatAt())->toBeNull();

    $event->run($this->app);

    expect(app(SchedulerHeartbeat::class)->lastBeatAt())->not->toBeNull()
        ->and(app(SchedulerHeartbeat::class)->status())->toBe(SchedulerHeartbeat::OK);
});

test('E25-9: beat() records the current timestamp and refreshes it on each call', function () {
    $heartbeat = app(SchedulerHeartbeat::class);

    $this->travelTo(now()->startOfMinute());
    $heartbeat->beat();
    $first = $heartbeat->lastBeatAt();

    $this->travel(61)->seconds();
    $heartbeat->beat();

    expect($first)->toBe(now()->subSeconds(61)->getTimestamp())
        ->and($heartbeat->lastBeatAt())->toBe(now()->getTimestamp())
        ->and($heartbeat->ageSeconds())->toBe(0);
});

test('E25-10: status is derived from the heartbeat age', function (?int $ageSeconds, string $expected) {
    $heartbeat = app(SchedulerHeartbeat::class);

    if ($ageSeconds !== null) {
        $this->travelTo(now()->startOfMinute());
        $heartbeat->beat();
        $this->travel($ageSeconds)->seconds();
    }

    expect($heartbeat->status())->toBe($expected);
})->with([
    'never beat (fresh deploy)' => [null, SchedulerHeartbeat::UNKNOWN],
    'just now' => [0, SchedulerHeartbeat::OK],
    'one minute' => [60, SchedulerHeartbeat::OK],
    'just below stale threshold' => [299, SchedulerHeartbeat::OK],
    'stale threshold' => [300, SchedulerHeartbeat::STALE],
    'just below down threshold' => [899, SchedulerHeartbeat::STALE],
    'down threshold' => [900, SchedulerHeartbeat::DOWN],
    'a day old' => [86_000, SchedulerHeartbeat::DOWN],
]);

test('E25-10: thresholds are configurable', function () {
    config(['operations.scheduler.stale_after_seconds' => 30, 'operations.scheduler.down_after_seconds' => 60]);
    $heartbeat = app(SchedulerHeartbeat::class);

    $this->travelTo(now()->startOfMinute());
    $heartbeat->beat();

    $this->travel(31)->seconds();
    expect($heartbeat->status())->toBe(SchedulerHeartbeat::STALE);

    $this->travel(30)->seconds();
    expect($heartbeat->status())->toBe(SchedulerHeartbeat::DOWN);
});

test('E25-10: a failing cache never throws from the heartbeat (fail-open: unknown / silent)', function () {
    Cache::shouldReceive('put')->andThrow(new RuntimeException('cache down'));
    Cache::shouldReceive('get')->andThrow(new RuntimeException('cache down'));

    $heartbeat = new SchedulerHeartbeat;
    $heartbeat->beat();

    expect($heartbeat->lastBeatAt())->toBeNull()
        ->and($heartbeat->status())->toBe(SchedulerHeartbeat::UNKNOWN);
});

test('E25-10: a garbage cache value is treated as unknown, not as a crash', function () {
    Cache::put(app(SchedulerHeartbeat::class)->key(), 'not-a-number', 60);

    expect(app(SchedulerHeartbeat::class)->status())->toBe(SchedulerHeartbeat::UNKNOWN);
});

test('E25-10: /ready ignores the scheduler by default, even when it is down', function () {
    $this->travelTo(now()->startOfMinute());
    app(SchedulerHeartbeat::class)->beat();
    $this->travel(2000)->seconds();

    $response = $this->getJson('/ready')->assertOk();

    expect($response->json('checks'))->not->toHaveKey('scheduler');
});

test('E25-10: with OPS_READY_FAILS_ON_SCHEDULER, /ready fails only when the scheduler is down (not unknown or stale)', function () {
    config(['operations.scheduler.ready_fails_on_down' => true]);
    $heartbeat = app(SchedulerHeartbeat::class);

    // نشر جديد: لا نبضة => unknown => لا تُسقط الجاهزية
    $this->getJson('/ready')->assertOk()->assertJsonPath('checks.scheduler.status', 'unknown');

    $this->travelTo(now()->startOfMinute());
    $heartbeat->beat();

    $this->travel(400)->seconds();
    $this->getJson('/ready')->assertOk()->assertJsonPath('checks.scheduler.status', 'stale');

    $this->travel(600)->seconds();
    $down = $this->getJson('/ready')->assertStatus(503);
    expect($down->json('checks.scheduler.status'))->toBe('down')->and($down->json('status'))->toBe('unavailable');
});

test('E25-10: production:check reports the scheduler state by name without failing the release on a stale heartbeat', function () {
    config(['app.debug' => false]);
    $heartbeat = app(SchedulerHeartbeat::class);

    [, $data] = e25_run('production:check');
    expect(e25_statuses($data, 'scheduler'))->toBe(['INFO']);

    $this->travelTo(now()->startOfMinute());
    $heartbeat->beat();
    [, $data] = e25_run('production:check');
    expect(e25_statuses($data, 'scheduler'))->toBe(['PASS']);

    $this->travel(1000)->seconds();
    [, $data] = e25_run('production:check');
    expect(e25_statuses($data, 'scheduler'))->toBe(['WARN']);
});
```

### `tests/Feature/OperationsE25/SecretHygieneTest.php`

```php
<?php

require_once __DIR__.'/_support.php';

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * E25 (18-19) - .env.example placeholders فقط، لا .env متتبَّع، ولا أسرار مضمَّنة بالمستودع.
 */
function e25_env_example_entries(): array
{
    $entries = [];

    foreach (explode("\n", e25_repo_file('.env.example')) as $number => $line) {
        // يشمل الأسطر المعلَّقة (# KEY=value) لأنها تُنسخ وتُفعَّل عادةً
        if (preg_match('/^\s*#?\s*([A-Z][A-Z0-9_]*)=(.*)$/', $line, $m) === 1) {
            $entries[] = ['line' => $number + 1, 'key' => $m[1], 'value' => trim(preg_replace('/\s+#.*$/', '', $m[2]), " \t\"'")];
        }
    }

    return $entries;
}

test('E25-18: .env.example has no real secret values: secret-like keys are empty or placeholders', function () {
    $secretKey = '/(PASSWORD|SECRET|TOKEN|PRIVATE|CREDENTIAL|WEBHOOK|DSN|API_KEY|APP_KEY|ACCESS_KEY|AUTH_KEY|ENCRYPTION|SIGNING)/';
    $placeholder = '/^(|null|none|false|true|changeme|change-me|change-me-now|your[-_a-z0-9]*|example[-_a-z0-9.]*|xxx+|<[^>]+>|\$\{[A-Z0-9_]+\}|\.\.\.)$/i';

    $offenders = collect(e25_env_example_entries())
        ->filter(fn (array $e) => preg_match($secretKey, $e['key']) === 1)
        ->reject(fn (array $e) => preg_match($placeholder, $e['value']) === 1)
        ->map(fn (array $e) => ".env.example:{$e['line']} {$e['key']}");  // اسم المفتاح فقط، لا القيمة

    expect($offenders->values()->all())->toBe([]);
});

test('E25-18: .env.example leaves APP_KEY, ADMIN_BOOTSTRAP_* and Reverb credentials empty', function () {
    $values = collect(e25_env_example_entries())->mapWithKeys(fn (array $e) => [$e['key'] => $e['value']]);

    foreach (['APP_KEY', 'ADMIN_BOOTSTRAP_EMAIL', 'ADMIN_BOOTSTRAP_PASSWORD', 'REVERB_APP_ID', 'REVERB_APP_KEY', 'REVERB_APP_SECRET', 'MAINTENANCE_SECRET'] as $key) {
        if ($values->has($key)) {
            expect($values[$key])->toBe('', "{$key} must be empty in .env.example");
        }
    }

    expect($values['APP_ENV'] ?? 'local')->not->toBe('production')
        ->and($values['APP_DEBUG'] ?? 'true')->not->toBe('production');
});

test('E25-18: .env.example contains no high-entropy secrets or provider tokens', function () {
    $content = e25_repo_file('.env.example');

    foreach ([
        'base64 key' => '/base64:[A-Za-z0-9+\/]{40,}={0,2}/',
        'aws access key' => '/AKIA[0-9A-Z]{16}/',
        'github token' => '/gh[pousr]_[A-Za-z0-9]{30,}/',
        'slack token' => '/xox[baprs]-[A-Za-z0-9-]{10,}/',
        'stripe live key' => '/sk_live_[A-Za-z0-9]{10,}/',
        'private key block' => '/-----BEGIN [A-Z ]*PRIVATE KEY-----/',
        'long hex/base64 blob' => '/=[A-Fa-f0-9]{40,}\s*$/m',
    ] as $label => $pattern) {
        expect(preg_match($pattern, $content))->toBe(0, ".env.example matches pattern: {$label}");
    }
});

test('E25-18: every env var the E25 code reads is documented in .env.example (commented placeholders)', function () {
    $keys = collect(e25_env_example_entries())->pluck('key');

    foreach (['APP_RELEASE', 'RELEASE_SHA', 'REVERB_ALLOWED_ORIGINS', 'ADMIN_BOOTSTRAP_EMAIL', 'ADMIN_BOOTSTRAP_PASSWORD', 'OPS_SCHEDULER_STALE_SECONDS', 'OPS_SCHEDULER_DOWN_SECONDS', 'OPS_READY_FAILS_ON_SCHEDULER', 'OPS_QUEUE_BACKLOG_WARN_SECONDS', 'OPS_FAILED_JOBS_WARN', 'OPS_ALLOW_DESTRUCTIVE_COMMANDS'] as $key) {
        expect($keys->contains($key))->toBeTrue("{$key} is missing from .env.example");
    }

    // الخيار الهدّام معطّل افتراضيًا وغير مفعّل في الملف
    $destructive = collect(e25_env_example_entries())->firstWhere('key', 'OPS_ALLOW_DESTRUCTIVE_COMMANDS');
    expect($destructive['value'])->toBe('false')
        ->and(preg_match('/^OPS_ALLOW_DESTRUCTIVE_COMMANDS=/m', e25_repo_file('.env.example')))->toBe(0);
});

test('E25-19: .gitignore excludes every real environment file and no env file sits in a deployable directory', function () {
    $gitignore = collect(explode("\n", e25_repo_file('.gitignore')))->map(fn ($l) => trim($l))->filter();

    foreach (['.env', '.env.backup', '.env.production'] as $rule) {
        expect($gitignore->contains($rule))->toBeTrue("{$rule} must be git-ignored");
    }

    $found = [];

    foreach (['app', 'bootstrap', 'config', 'database', 'docs', 'public', 'resources', 'routes', 'tests', 'tools', '.github'] as $dir) {
        if (! is_dir(base_path($dir))) {
            continue;
        }

        foreach (File::allFiles(base_path($dir), true) as $file) {
            if (preg_match('/^\.env(\..+)?$/', $file->getFilename()) === 1 || str_ends_with($file->getFilename(), '.env')) {
                $found[] = str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1));
            }
        }
    }

    expect($found)->toBe([]);
});

test('E25-19: the repository index tracks no .env file (when a git work tree is present)', function () {
    if (! is_dir(base_path('.git'))) {
        $this->markTestSkipped('ليست نسخة git (مثل تصدير ZIP)');
    }

    $which = PHP_OS_FAMILY === 'Windows' ? 'where git 2>NUL' : 'command -v git 2>/dev/null';
    if (trim((string) @shell_exec($which)) === '') {
        $this->markTestSkipped('أداة git غير متاحة');
    }

    // قراءة فقط: يسرد الملفات المتتبَّعة. GIT_OPTIONAL_LOCKS=0 كي لا يلمس فهرس المستودع.
    $result = Process::path(base_path())->env(['GIT_OPTIONAL_LOCKS' => '0'])->run(['git', 'ls-files', '--cached', '--', '.env', '.env.production', '.env.backup', '.env.local', '*.env']);

    expect($result->successful())->toBeTrue()->and(trim($result->output()))->toBe('');
});

test('E25-19: no real secret, key or token pattern is embedded in code, config, docs, tools or CI', function () {
    $patterns = [
        'private key block' => '/-----BEGIN [A-Z ]*PRIVATE KEY-----/',
        'aws access key id' => '/\bAKIA[0-9A-Z]{16}\b/',
        'github token' => '/\bgh[pousr]_[A-Za-z0-9]{30,}\b/',
        'slack token' => '/\bxox[baprs]-[A-Za-z0-9-]{10,}/',
        'stripe live key' => '/\bsk_live_[A-Za-z0-9]{10,}/',
        'laravel app key literal' => '/\bbase64:[A-Za-z0-9+\/]{43}=/',
        'basic-auth url' => '#https?://[^\s/:@]+:[^\s/@]{3,}@[a-z0-9.-]+#i',
    ];
    $hits = [];

    foreach (['app', 'bootstrap', 'config', 'database', 'docs', 'resources', 'routes', 'tools', '.github', 'tests/Feature/OperationsE25'] as $dir) {
        if (! is_dir(base_path($dir))) {
            continue;
        }

        foreach (File::allFiles(base_path($dir), true) as $file) {
            if ($file->getSize() > 1_500_000 || ! in_array(strtolower($file->getExtension()), ['php', 'md', 'sh', 'yml', 'yaml', 'json', 'js', 'txt', 'env', 'example', 'blade', 'css'], true)) {
                continue;
            }

            $content = (string) file_get_contents($file->getPathname());

            foreach ($patterns as $label => $pattern) {
                preg_match_all($pattern, $content, $found);

                // نطاقات example.* محجوزة (RFC 2606) وتُستعمل بيانات اختبار صريحة (مثل رفض روابط تحمل بيانات اعتماد)
                $real = array_filter($found[0], fn (string $match) => ! ($label === 'basic-auth url' && preg_match('/\bexample\.(com|org|net)\b/i', $match) === 1));

                if ($real !== []) {
                    $hits[] = str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1))." ({$label})";
                }
            }
        }
    }

    expect($hits)->toBe([]);
});

test('E25-19: the CI workflow uses no repository secrets and performs no deployment', function () {
    $raw = e25_repo_file('.github/workflows/ci.yml');
    $ci = preg_replace('/^\s*#.*$/m', '', $raw); // الأوامر الفعلية فقط، لا التعليقات الشارحة

    expect($ci)->not->toContain('secrets.')
        ->and(preg_match('/^\s*permissions:\s*\n\s*contents:\s*read\s*$/m', $ci))->toBe(1)
        ->and(preg_match('/\b(ssh|scp|rsync|kubectl|helm|terraform|ansible|aws |gcloud |az |docker push|deploy|publish)\b/i', str_replace('tools/deploy/', 'tools/', $ci)))->toBe(0)
        ->and(preg_match('/composer (update|require)|npm (update|install -g)|migrate:(fresh|refresh|reset|rollback)|db:wipe|git (push|commit|tag)/', $ci))->toBe(0)
        ->and($ci)->toContain('tests/Feature/OperationsE25');

    // كل أمر "php artisan test" يجب أن يحدّد مسارات اختبار (لا المجموعة الكاملة)
    preg_match_all("/php artisan test\\b[^\n\\\\]*(?:\\\\\n[^\n\\\\]*)*/", $ci, $commands);
    expect($commands[0])->not->toBeEmpty();

    foreach ($commands[0] as $command) {
        expect($command)->toContain('tests/');
    }
});
```

### `tests/Feature/OperationsE25/SecurityAndConfigRegressionTest.php`

```php
<?php

require_once __DIR__.'/_support.php';

use App\Jobs\Concerns\BoundedRetries;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * E25 (20-22) - انحدار الأمان (رؤوس، HSTS، CORS، كوكيز، وكلاء موثوقون)، إعدادات الطابور، وإعدادات Reverb، + حراس البذور بالإنتاج.
 */
afterEach(function () {
    $this->app['env'] = 'testing';
    DB::prohibitDestructiveCommands(false);
});

// ───────────────────────────── 20) رؤوس الأمان وHSTS وCORS والكوكيز ─────────────────────────────

test('E25-20: security headers are present on public, authenticated-area and admin responses', function () {
    foreach (['/', '/login', '/puzzles', '/admin/login'] as $path) {
        $this->get($path)
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Permissions-Policy');
    }
});

test('E25-20: HSTS is sent only in production over real HTTPS, with a one-year max-age and no includeSubDomains/preload', function () {
    // لا HSTS خارج الإنتاج حتى على HTTPS
    $this->get('https://ahjiyat.example.com/')->assertHeaderMissing('Strict-Transport-Security');

    $this->app['env'] = 'production';

    // لا HSTS على HTTP حتى بالإنتاج
    $this->get('http://ahjiyat.example.com/')->assertHeaderMissing('Strict-Transport-Security');

    // HSTS على HTTPS بالإنتاج فقط
    $header = $this->get('https://ahjiyat.example.com/')->headers->get('Strict-Transport-Security');

    expect($header)->toBe('max-age=31536000')
        ->and($header)->not->toContain('includeSubDomains')
        ->and($header)->not->toContain('preload');
});

test('E25-20: no CORS headers are emitted for a foreign Origin, including on a preflight request', function () {
    expect(file_exists(config_path('cors.php')))->toBeFalse('إضافة config/cors.php قرار واعٍ: حدّث docs/security-hardening.md واختبار CORS معًا');

    $get = $this->withHeaders(['Origin' => 'https://evil.example'])->get('/puzzles');
    $preflight = $this->call('OPTIONS', '/puzzles', [], [], [], ['HTTP_ORIGIN' => 'https://evil.example', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET']);

    foreach ([$get, $preflight] as $response) {
        expect($response->headers->has('Access-Control-Allow-Origin'))->toBeFalse()
            ->and($response->headers->has('Access-Control-Allow-Credentials'))->toBeFalse();
    }
});

test('E25-20: the session cookie is HttpOnly and SameSite=Lax, and Secure when configured', function () {
    config(['session.secure' => true]);

    $cookie = collect($this->get('/login')->headers->getCookies())->first(fn ($c) => $c->getName() === config('session.cookie'));

    expect($cookie)->not->toBeNull()
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->isSecure())->toBeTrue()
        ->and(strtolower((string) $cookie->getSameSite()))->toBe('lax')
        ->and($cookie->getDomain())->toBeNull();   // مرتبطة بالمضيف فقط
});

test('E25-20: cookie hardening defaults hold in config (no accidental weakening)', function () {
    expect(config('session.http_only'))->toBeTrue()
        ->and(strtolower((string) config('session.same_site')))->toBe('lax')
        ->and(config('session.domain'))->toBeNull();
});

test('E25-20: trusted proxies are audited by name: wildcard warns, a CIDR list passes, unset behind https warns', function () {
    config(['app.debug' => false, 'app.url' => 'https://ahjiyat.example.com']);

    config(['trustedproxy.proxies' => '*']);
    expect(e25_statuses(e25_run('production:check')[1], 'TRUSTED_PROXIES'))->toBe(['WARN']);

    config(['trustedproxy.proxies' => ['10.0.0.0/8', '172.16.0.0/12']]);
    [, $data, $raw] = e25_run('production:check');
    expect(e25_statuses($data, 'TRUSTED_PROXIES'))->toBe(['PASS'])
        ->and($raw)->not->toContain('10.0.0.0/8');   // العدد فقط، لا قيم الشبكة

    e25_as_production();
    config(['trustedproxy.proxies' => null]);
    expect(e25_statuses(e25_run('production:check')[1], 'TRUSTED_PROXIES'))->toBe(['WARN']);
});

test('E25-20: publicly reachable diagnostic routes fail the production contract, and the real route table has none', function () {
    [, $data] = e25_check(['app.debug' => false]);
    expect(e25_statuses($data, 'routes'))->toBe(['PASS']);

    Illuminate\Support\Facades\Route::get('/telescope/requests', fn () => 'x');   // مسار تشخيص عام مفترض
    e25_as_production();
    [$code, $data] = e25_check(['app.url' => 'https://ahjiyat.example.com']);

    expect(e25_statuses($data, 'routes'))->toBe(['FAIL'])->and($code)->toBe(1);
});

test('E25-20: .env or .git inside public/ is a blocking failure', function () {
    $marker = public_path('.env');
    file_put_contents($marker, 'X=1');

    try {
        [$code, $data] = e25_check(['app.debug' => false]);
    } finally {
        @unlink($marker);
    }

    expect($code)->toBe(1)->and(e25_statuses($data, 'public/'))->toBe(['FAIL']);
});

// ───────────────────────────── 21) الطابور ─────────────────────────────

test('E25-21: queue retry_after exceeds the job timeout on every connection, and the ops config agrees with BoundedRetries', function () {
    $jobTimeout = (new class
    {
        use BoundedRetries;
    })->timeout;

    expect((int) config('operations.queue.job_timeout_seconds'))->toBe($jobTimeout);

    foreach (['database', 'redis', 'beanstalkd'] as $connection) {
        expect((int) config("queue.connections.{$connection}.retry_after"))->toBeGreaterThan($jobTimeout, "{$connection}.retry_after must exceed the job timeout");
    }
});

test('E25-21: production:check flags retry_after <= job timeout (warning locally, failure in production) and warns on the sync driver', function () {
    config(['app.debug' => false, 'app.url' => 'https://ahjiyat.example.com', 'queue.default' => 'database']);

    [, $data] = e25_run('production:check');
    expect(e25_statuses($data, 'DB_QUEUE_RETRY_AFTER'))->toBe(['PASS']);

    config(['queue.connections.database.retry_after' => 60]);
    [$code, $data] = e25_run('production:check');
    expect(e25_statuses($data, 'DB_QUEUE_RETRY_AFTER'))->toBe(['WARN'])->and($code)->toBe(0);

    e25_as_production();
    [$code, $data] = e25_run('production:check');
    expect(e25_statuses($data, 'DB_QUEUE_RETRY_AFTER'))->toBe(['FAIL'])->and($code)->toBe(1);

    config(['queue.connections.database.retry_after' => 90, 'queue.default' => 'sync']);
    [, $data] = e25_run('production:check');
    expect(e25_statuses($data, 'QUEUE_CONNECTION'))->toContain('WARN');
});

test('E25-21: the operations runbook documents the worker timeout that matches the code', function () {
    $runbook = e25_repo_file('docs/operations-runbook.md');

    expect($runbook)->toContain('--timeout=80')->and($runbook)->toContain('retry_after');
});

// ───────────────────────────── 22) Reverb ─────────────────────────────

test('E25-22: Reverb allowed origins default to "*" (E21 behaviour) and follow REVERB_ALLOWED_ORIGINS when set', function () {
    expect(config('reverb.apps.apps.0.allowed_origins'))->toBe(['*']);

    putenv('REVERB_ALLOWED_ORIGINS= ahjiyat.example.com , www.ahjiyat.example.com ,');
    try {
        $config = require config_path('reverb.php');
    } finally {
        putenv('REVERB_ALLOWED_ORIGINS');
    }

    expect($config['apps']['apps'][0]['allowed_origins'])->toBe(['ahjiyat.example.com', 'www.ahjiyat.example.com']);

    putenv('REVERB_ALLOWED_ORIGINS= , ');
    try {
        $blank = require config_path('reverb.php');
    } finally {
        putenv('REVERB_ALLOWED_ORIGINS');
    }

    expect($blank['apps']['apps'][0]['allowed_origins'])->toBe(['*']);   // قيمة فارغة لا تغلق الاتصال بالكامل
});

test('E25-22: production:check audits Reverb: weak secret, non-https scheme, local host and wildcard origins', function () {
    config([
        'app.debug' => false,
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.app_id' => '1001',
        'broadcasting.connections.reverb.key' => 'public-key-value',
        'broadcasting.connections.reverb.secret' => 'secret',
        'reverb.apps.apps.0.options.scheme' => 'http',
        'reverb.servers.reverb.hostname' => 'localhost',
        'reverb.apps.apps.0.allowed_origins' => ['*'],
    ]);
    e25_as_production();

    [$code, $data, $raw] = e25_run('production:check');

    expect(e25_statuses($data, 'REVERB_APP_SECRET'))->toBe(['FAIL'])
        ->and(e25_statuses($data, 'REVERB_SCHEME'))->toBe(['FAIL'])
        ->and(e25_statuses($data, 'REVERB_HOST'))->toBe(['FAIL'])
        ->and(e25_statuses($data, 'REVERB_ALLOWED_ORIGINS'))->toBe(['WARN'])
        ->and($code)->toBe(1);

    config([
        'broadcasting.connections.reverb.secret' => 'a-long-random-reverb-secret-0123456789abcdef',
        'reverb.apps.apps.0.options.scheme' => 'https',
        'reverb.servers.reverb.hostname' => 'ws.ahjiyat.example.com',
        'reverb.apps.apps.0.allowed_origins' => ['ahjiyat.example.com'],
    ]);

    [, $data, $raw] = e25_run('production:check');

    expect(e25_statuses($data, 'REVERB_APP_SECRET'))->not->toContain('FAIL')
        ->and(e25_statuses($data, 'REVERB_SCHEME'))->toBe(['PASS'])
        ->and(e25_statuses($data, 'REVERB_HOST'))->not->toContain('FAIL')
        ->and(e25_statuses($data, 'REVERB_ALLOWED_ORIGINS'))->toBe(['PASS'])
        ->and($raw)->not->toContain('a-long-random-reverb-secret-0123456789abcdef');
});

test('E25-22: with a non-reverb broadcaster the audit only warns (chat/notifications stay functional without realtime)', function () {
    config(['app.debug' => false, 'broadcasting.default' => 'log']);

    [$code, $data] = e25_run('production:check');

    expect(e25_statuses($data, 'BROADCAST_CONNECTION'))->toBe(['WARN'])->and($code)->toBe(0);
});

// ───────────────────────────── حراس البذور والحسابات بالإنتاج ─────────────────────────────

test('E25: the full DatabaseSeeder and UserSeeder refuse to run in production', function () {
    $this->app['env'] = 'production';

    expect(fn () => app(DatabaseSeeder::class)->run())->toThrow(RuntimeException::class, 'ممنوع بالإنتاج')
        ->and(fn () => app(UserSeeder::class)->run())->toThrow(RuntimeException::class, 'ممنوع بالإنتاج');
});

test('E25: AdminUserSeeder in production needs a valid email and a strong, non-default password from the environment', function (?string $email, ?string $password) {
    $this->app['env'] = 'production';
    config(['operations.admin_bootstrap.email' => $email, 'operations.admin_bootstrap.password' => $password]);

    expect(fn () => app(AdminUserSeeder::class)->run())->toThrow(RuntimeException::class);
    expect(User::where('email', AdminUserSeeder::DEFAULT_EMAIL)->exists())->toBeFalse();
})->with([
    'nothing set' => [null, null],
    'no password' => ['owner@ahjiyat.example.com', null],
    'short password' => ['owner@ahjiyat.example.com', 'short-pass'],
    'the known default password' => ['owner@ahjiyat.example.com', 'change-me-now'],
    'invalid email' => ['not-an-email', 'a-very-long-password-123'],
]);

test('E25: AdminUserSeeder in production creates the operator-chosen admin, never the default account, and never prints the password', function () {
    $this->app['env'] = 'production';
    $password = 'Str0ng-Operator-Pass-2026';
    config(['operations.admin_bootstrap.email' => 'owner@ahjiyat.example.com', 'operations.admin_bootstrap.password' => $password]);

    Artisan::call('db:seed', ['--class' => AdminUserSeeder::class, '--force' => true]);
    $output = Artisan::output();

    $admin = User::where('email', 'owner@ahjiyat.example.com')->first();

    expect($admin)->not->toBeNull()
        ->and(Hash::check($password, $admin->password))->toBeTrue()
        ->and(Hash::check(AdminUserSeeder::DEFAULT_PASSWORD, $admin->password))->toBeFalse()
        ->and(User::where('email', AdminUserSeeder::DEFAULT_EMAIL)->exists())->toBeFalse()
        ->and($output)->not->toContain($password)
        ->and($output)->not->toContain(AdminUserSeeder::DEFAULT_PASSWORD);
});

test('E25: outside production AdminUserSeeder keeps its documented local-development behaviour', function () {
    app(AdminUserSeeder::class)->run();

    $admin = User::where('email', AdminUserSeeder::DEFAULT_EMAIL)->first();

    expect($admin)->not->toBeNull()->and(Hash::check(AdminUserSeeder::DEFAULT_PASSWORD, $admin->password))->toBeTrue();
});

test('E25: production:check flags leftover demo accounts and the default admin password, and passes when they are absent', function () {
    config(['app.debug' => false, 'app.url' => 'https://ahjiyat.example.com']);
    e25_as_production();

    [, $data] = e25_run('production:check');
    expect(e25_statuses($data, 'users'))->toBe(['PASS']);

    User::factory()->create(['email' => 'demo.player@ahjiyat.test']);
    [$code, $data] = e25_run('production:check');
    expect(e25_statuses($data, 'users'))->toBe(['FAIL'])->and($code)->toBe(1);

    User::where('email', 'like', '%@ahjiyat.test')->delete();
    User::factory()->create(['email' => AdminUserSeeder::DEFAULT_EMAIL, 'password' => Hash::make(AdminUserSeeder::DEFAULT_PASSWORD)]);
    [$code, $data] = e25_run('production:check');
    expect(e25_statuses($data, 'ADMIN_BOOTSTRAP_PASSWORD'))->toBe(['FAIL'])->and($code)->toBe(1);

    User::where('email', AdminUserSeeder::DEFAULT_EMAIL)->update(['password' => Hash::make('a-completely-different-passphrase')]);
    [, $data] = e25_run('production:check');
    expect(e25_statuses($data, 'ADMIN_BOOTSTRAP_PASSWORD'))->toBe(['PASS']);
});
```

### `tests/Feature/OperationsE25/SensitiveLoggingTest.php`

```php
<?php

require_once __DIR__.'/_support.php';

use App\Support\SecretRedactor;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * E25 (سياسة السجلات الحساسة) - لا يمرّر أي استدعاء سجل مفاتيح/كائنات حساسة: كلمات مرور، رموز، Authorization/Cookie، أجسام الطلبات الكاملة.
 * مسح ساكن لكل استدعاءات Log:: وlogger() وreport() بالمستودع + اختبارات سلوكية لمسار الطلبات البطيئة وسجل الأخطاء التشغيلي.
 */
/** استدعاءات السجل داخل نص مصدر واحد: [file, line, args]. */
function e25_log_calls_in_source(string $source, string $file): array
{
    $calls = [];
    $pattern = '/(?:\\\\?Log::(?:emergency|alert|critical|error|warning|notice|info|debug|log|withContext|shareContext)\s*\(|\\\\?Log::channel\([^)]*\)->\w+\s*\(|\blogger\(\)->\w+\s*\(|\blogger\s*\()/';

    if (! preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE)) {
        return [];
    }

    foreach ($matches[0] as [$text, $offset]) {
        $start = $offset + strlen($text);
        $depth = 1;
        $i = $start;
        $len = strlen($source);

        while ($i < $len && $depth > 0) {
            $ch = $source[$i];
            $depth += $ch === '(' ? 1 : ($ch === ')' ? -1 : 0);
            $i++;
        }

        $calls[] = ['file' => $file, 'line' => substr_count(substr($source, 0, $offset), "\n") + 1, 'args' => substr($source, $start, $i - $start - 1)];
    }

    return $calls;
}

function e25_log_calls(): array
{
    $calls = [];

    foreach (['app', 'routes', 'bootstrap', 'database/seeders'] as $dir) {
        if (! is_dir(base_path($dir))) {
            continue;
        }

        foreach (File::allFiles(base_path($dir)) as $file) {
            if ($file->getExtension() === 'php') {
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1));
                $calls = array_merge($calls, e25_log_calls_in_source((string) file_get_contents($file->getPathname()), $relative));
            }
        }
    }

    return $calls;
}

function e25_sensitive_log_args(string $args): bool
{
    $keys = '/[\'"](?:password|passwd|password_confirmation|current_password|new_password|token|access_token|refresh_token|remember_token|authorization|cookie|set-cookie|secret|client_secret|api_key|apikey|app_key|private_key|x-health-token|x-xsrf-token|x-csrf-token)[\'"]\s*=>/i';
    $objects = '/(?:request\(\)->all\(\)|\$request->all\(\)|\$request->input\(\)|\$request->header\(|->headers->all\(\)|\$request->cookies|\$request->getContent\(\)|\$request->only\(|\$request->post\(\)|\$_COOKIE|\$_SERVER|\$_POST|getenv\(|\benv\(|config\(\)->all\(\)|\$user->password|->makeVisible)/i';

    return preg_match($keys, $args) === 1 || preg_match($objects, $args) === 1;
}

test('E25-log: the static scan really finds the application log calls (guards the guard)', function () {
    $calls = e25_log_calls();

    expect(count($calls))->toBeGreaterThan(5)
        ->and(collect($calls)->pluck('file')->all())->toContain('app/Services/Chat/ChatBroadcaster.php');
});

test('E25-log: the sensitive-argument detector flags bad calls (guards the guard)', function () {
    $bad = [
        "Log::info('login', ['password' => \$p]);",
        "Log::warning('x', ['Authorization' => \$h]);",
        "logger()->error('req', request()->all());",
        "Log::error('dump', [\$request->header('Cookie')]);",
    ];
    $good = ["Log::warning('chat.broadcast_failed', ['message_id' => \$id, 'error' => get_class(\$e)]);"];

    foreach ($bad as $source) {
        $calls = e25_log_calls_in_source($source, 'synthetic.php');
        expect($calls)->toHaveCount(1)->and(e25_sensitive_log_args($calls[0]['args']))->toBeTrue($source);
    }

    foreach ($good as $source) {
        expect(e25_sensitive_log_args(e25_log_calls_in_source($source, 'synthetic.php')[0]['args']))->toBeFalse($source);
    }
});

test('E25-log: no log call passes sensitive keys or whole request/header/body objects', function () {
    $offenders = collect(e25_log_calls())->filter(fn (array $call) => e25_sensitive_log_args($call['args']));

    expect($offenders->map(fn ($c) => $c['file'].':'.$c['line'])->all())->toBe([]);
});

test('E25-log: exceptions are logged by class or message only, never with the request (Context/Request objects)', function () {
    $offenders = collect(e25_log_calls())->filter(fn (array $call) => preg_match('/\$request(?!->(?:ip|user|route|method|path|fullUrl)\b)\s*[,)\]]/', $call['args']) === 1);

    expect($offenders->map(fn ($c) => $c['file'].':'.$c['line'])->all())->toBe([]);
});

test('E25-log: the slow-request log carries only whitelisted fields (no query string, headers, cookies, body)', function () {
    config(['performance.observability.slow_request_ms' => 1, 'performance.observability.slow_request_sample' => 1.0]);

    $captured = [];
    Log::listen(function ($event) use (&$captured) {
        if ($event->message === 'slow_request') {
            $captured[] = $event->context;
        }
    });

    $this->withHeaders(['Authorization' => 'Bearer e25-secret-bearer', 'Cookie' => 'session=e25-secret-cookie'])
        ->get('/puzzles?token=e25-secret-query&q=%D8%B3%D8%B1')
        ->assertOk();

    expect($captured)->not->toBeEmpty();
    $dump = json_encode($captured, JSON_UNESCAPED_UNICODE);

    foreach (['e25-secret-bearer', 'e25-secret-cookie', 'e25-secret-query', 'Authorization', 'token='] as $needle) {
        expect($dump)->not->toContain($needle);
    }
});

test('E25-log: SecretRedactor keeps operational command output free of configured secrets even if an error message carries them', function () {
    config(['database.connections.mysql.password' => 'LogLeakPassword-VALUE-777']);

    $clean = SecretRedactor::scrub('SQLSTATE[HY000] [1045] Access denied (using password: LogLeakPassword-VALUE-777)');

    expect($clean)->not->toContain('LogLeakPassword-VALUE-777')->and($clean)->toContain('[redacted]');
});

test('E25-log: daily rotation with bounded retention is the documented production channel and its retention is configurable', function () {
    expect(config('logging.channels.daily.driver'))->toBe('daily')
        ->and((int) config('logging.channels.daily.days'))->toBeGreaterThan(0)
        ->and(e25_repo_file('.env.example'))->toContain('LOG_STACK=daily')->toContain('LOG_DAILY_DAYS=14');
});
```

### `tests/Feature/OperationsE25/SmokeCheckerTest.php`

```php
<?php

require_once __DIR__.'/_support.php';

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * E25 (16) - release:smoke: طلبات GET فقط على مسارات عامة، بمهل، يحدد PASS/WARN/FAIL، ولا يكشف نصوص الاستثناءات أو بيانات الاعتماد.
 */
function e25_fake_site(array $overrides = []): void
{
    // الأنماط تُطابَق بالترتيب والأول يفوز: التجاوزات أولًا ثم الافتراضيات.
    Http::fake($overrides + [
        '*/ready' => Http::response(['status' => 'ok', 'release' => 'abc1234', 'checks' => []], 200),
        '*' => Http::response('<html>ok</html>', 200),
    ]);
}

test('E25-16: smoke sends only GET requests, one per configured public route, and passes on a healthy site', function () {
    e25_fake_site();

    [$code, $data] = e25_run('release:smoke', ['--url' => 'https://smoke.example.com']);

    expect($code)->toBe(0)->and($data['ready'])->toBeTrue();

    Http::assertSentCount(7); // 8 مسارات مهيأة - /players/{profile} يُتخطى بلا --profile
    Http::assertSent(fn (Request $request) => $request->method() === 'GET' && str_starts_with($request->url(), 'https://smoke.example.com'));
    Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');
    Http::assertNotSent(fn (Request $request) => $request->hasHeader('Authorization') || $request->hasHeader('Cookie'));
    Http::assertNotSent(fn (Request $request) => $request->body() !== '');
});

test('E25-16: every route result shows the path, the status and the latency', function () {
    e25_fake_site();

    [, $data] = e25_run('release:smoke', ['--url' => 'https://smoke.example.com']);

    foreach ($data['checks'] as $check) {
        if ($check['status'] === 'PASS') {
            expect($check['message'])->toMatch('/^GET \S+ → 200 \(\d+ms\)$/');
        }
    }

    expect(collect($data['checks'])->pluck('setting')->all())->toContain('/up', '/ready', '/', '/login', '/puzzles', '/competitions', '/store');
});

test('E25-16: a non-200 route fails the smoke run', function () {
    e25_fake_site(['*/puzzles' => Http::response('boom', 500)]);

    [$code, $data] = e25_run('release:smoke', ['--url' => 'https://smoke.example.com']);

    expect($code)->toBe(1)
        ->and(collect($data['checks'])->firstWhere('setting', '/puzzles')['status'])->toBe('FAIL')
        ->and(collect($data['checks'])->firstWhere('setting', '/puzzles')['message'])->toContain('500');
});

test('E25-16: a redirect is not a pass and is not followed', function () {
    e25_fake_site(['*/store' => Http::response('', 302, ['Location' => 'https://elsewhere.example.com/'])]);

    [$code, $data] = e25_run('release:smoke', ['--url' => 'https://smoke.example.com']);

    expect($code)->toBe(1)->and(collect($data['checks'])->firstWhere('setting', '/store')['status'])->toBe('FAIL');
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'elsewhere.example.com'));
});

test('E25-16: a connection failure is a FAIL that never echoes the exception text', function () {
    Http::fake(['*' => fn () => throw new ConnectionException('cURL error 7: Failed to connect to internal-db-host.corp:3306 as secret-user')]);

    [$code, $data, $raw] = e25_run('release:smoke', ['--url' => 'https://smoke.example.com']);

    expect($code)->toBe(1)
        ->and($data['summary']['fail'])->toBeGreaterThan(0)
        ->and($raw)->not->toContain('internal-db-host')
        ->and($raw)->not->toContain('secret-user');
});

test('E25-16: /ready must confirm readiness in its body, not only answer 200', function () {
    e25_fake_site(['*/ready' => Http::response(['status' => 'unavailable'], 200)]);

    [$code, $data] = e25_run('release:smoke', ['--url' => 'https://smoke.example.com']);

    expect($code)->toBe(1)->and(collect($data['checks'])->firstWhere('setting', '/ready')['status'])->toBe('FAIL');
});

test('E25-16: --expect-release detects a deploy that did not take effect', function () {
    e25_fake_site(); // /ready يعرض abc1234

    [$ok] = e25_run('release:smoke', ['--url' => 'https://smoke.example.com', '--expect-release' => 'abc1234']);
    [$stale, $data] = e25_run('release:smoke', ['--url' => 'https://smoke.example.com', '--expect-release' => 'def5678']);

    expect($ok)->toBe(0)
        ->and($stale)->toBe(1)
        ->and(collect($data['checks'])->firstWhere('setting', '/ready')['status'])->toBe('FAIL');
});

test('E25-16: a URL that embeds credentials is rejected before any request is made', function () {
    Http::fake();

    [$code, $data, $raw] = e25_run('release:smoke', ['--url' => 'https://admin:hunter2@smoke.example.com']);

    expect($code)->toBe(1)
        ->and($data['summary']['fail'])->toBe(1)
        ->and($raw)->not->toContain('hunter2');
    Http::assertNothingSent();
});

test('E25-16: only http(s) schemes are accepted', function (string $url) {
    Http::fake();

    [$code] = e25_run('release:smoke', ['--url' => $url]);

    expect($code)->toBe(1);
    Http::assertNothingSent();
})->with(['file:///etc/passwd', 'ftp://example.com', 'javascript:alert(1)', 'not a url']);

test('E25-16: --profile adds the public profile route, and unsafe profile ids are skipped', function () {
    e25_fake_site();

    [, $data] = e25_run('release:smoke', ['--url' => 'https://smoke.example.com', '--profile' => 'player_123']);
    expect(collect($data['checks'])->pluck('setting')->all())->toContain('/players/player_123');
    Http::assertSent(fn (Request $request) => $request->url() === 'https://smoke.example.com/players/player_123');

    Http::fake();
    e25_fake_site();
    [, $data] = e25_run('release:smoke', ['--url' => 'https://smoke.example.com', '--profile' => '../../etc/passwd']);
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'passwd'));
});

test('E25-16: a slow route is a warning, not a failure', function () {
    config(['operations.smoke.slow_ms' => 1]);
    e25_fake_site(['*/login' => function () {
        usleep(20_000);

        return Http::response('ok', 200);
    }]);

    [$code, $data] = e25_run('release:smoke', ['--url' => 'https://smoke.example.com']);

    expect(collect($data['checks'])->firstWhere('setting', '/login')['status'])->toBe('WARN')
        ->and($code)->toBe(0);
});

test('E25-16: the checker is read-only by construction: GET only, with timeouts, no session, no body (static guard)', function () {
    $source = e25_repo_file('app/Services/Operations/SmokeChecker.php');

    foreach (['->post(', '->put(', '->patch(', '->delete(', 'Http::post', 'Http::put', 'Http::delete', 'asForm', 'asJson', 'withBody', 'withCookies', 'withToken', 'withBasicAuth', 'attach('] as $needle) {
        expect(str_contains($source, $needle))->toBeFalse("SmokeChecker must be GET-only but contains {$needle}");
    }

    expect($source)->toContain('->get(')->toContain('->timeout(')->toContain('->connectTimeout(')->toContain('->withoutRedirecting()');
    expect((int) config('operations.smoke.timeout_seconds'))->toBeBetween(1, 10)
        ->and((int) config('operations.smoke.connect_timeout_seconds'))->toBeBetween(1, 10);
});

test('E25-16: every default smoke route is public (guest-accessible) and idempotent', function () {
    foreach ((array) config('operations.smoke.routes') as $path) {
        $uri = str_replace('{profile}', 'x', $path);
        $route = collect(app('router')->getRoutes()->getRoutes())->first(fn ($r) => '/'.ltrim($r->uri(), '/') === $path || '/'.ltrim($r->uri(), '/') === $uri || preg_replace('#\{[^}]+\}#', '{profile}', '/'.ltrim($r->uri(), '/')) === $path);

        expect($route)->not->toBeNull("{$path} is not a registered route")
            ->and($route->methods())->toContain('GET')
            ->and(array_intersect($route->gatherMiddleware(), ['auth', 'verified', 'auth:sanctum']))->toBe([], "{$path} must not require authentication");
    }
});
```

### `tests/Feature/OperationsE25/_support.php`

```php
<?php

use Illuminate\Support\Facades\Artisan;

/*
| E25 - مساعدات مشتركة لاختبارات العمليات. ليست ملف اختبار (لا تنتهي بـTest.php) فلا يشغّلها Pest وحدها؛ تُحمَّل بـrequire_once.
*/

if (! function_exists('e25_check')) {
    /**
     * يشغّل أمرًا تشغيليًا بصيغة JSON ويعيد [exitCode, data, rawOutput].
     *
     * @return array{0: int, 1: array<string, mixed>, 2: string}
     */
    function e25_run(string $command, array $options = []): array
    {
        $code = Artisan::call($command, array_merge(['--json' => true], $options));
        $raw = Artisan::output();
        $data = json_decode($raw, true);

        return [$code, is_array($data) ? $data : [], $raw];
    }

    /** نتائج فحص production:check كاملة. */
    function e25_check(array $config = []): array
    {
        if ($config !== []) {
            config($config);
        }

        return e25_run('production:check');
    }

    /** كل حالات إعداد معيّن (قد يملك الإعداد الواحد أكثر من نتيجة). @return list<string> */
    function e25_statuses(array $data, string $setting): array
    {
        return array_values(array_map(
            fn (array $c) => $c['status'],
            array_filter($data['checks'] ?? [], fn (array $c) => $c['setting'] === $setting),
        ));
    }

    /** يحوّل الإنتاج: بيئة التطبيق + config('app.env'). */
    function e25_as_production(): void
    {
        config(['app.env' => 'production']);
        app()['env'] = 'production';
    }

    function e25_repo_file(string $relative): string
    {
        return (string) file_get_contents(base_path($relative));
    }

    /** هل يمكن تشغيل bash حقيقي (سكربتات tools/*) هنا؟ ويندوز/XAMPP لا. */
    function e25_can_run_bash(): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return false;
        }

        $out = @shell_exec('command -v bash 2>/dev/null');

        return is_string($out) && trim($out) !== '';
    }
}
```

### `tools/backup/db-backup.sh`

```bash
#!/usr/bin/env bash
# =============================================================================
# E25 - نسخة احتياطية منطقية لقاعدة البيانات (MySQL/MariaDB أو PostgreSQL). محايدة للمزوّد، بلا أسرار مضمَّنة، لا تعدّل القاعدة (قراءة فقط).
# docs/backup-disaster-recovery.md
#
# المدخلات من البيئة (لا من سطر الأوامر، كي لا تظهر في سجل العمليات):
#   BACKUP_DIR              (إلزامي) مجلد الإخراج؛ يجب أن يكون خارج public/ وخارج مجلد التطبيق المخدَّم
#   DB_CONNECTION           mysql|mariadb|pgsql  (أو BACKUP_DB_ENGINE)
#   DB_HOST DB_PORT DB_DATABASE DB_USERNAME
#   كلمة المرور: MYSQL_PWD أو PGPASSWORD بالبيئة، أو BACKUP_DB_DEFAULTS_FILE (ملف my.cnf بصلاحيات 600) - لا تُمرَّر كوسيط أبدًا
#   التشفير (إلزامي افتراضيًا): BACKUP_AGE_RECIPIENT (age -r) أو BACKUP_GPG_RECIPIENT (gpg -r). بدونهما يُرفض التشغيل إلا مع --allow-unencrypted
#   BACKUP_UPLOAD_CMD       (اختياري) أمر نسخ خارج الخادم (rclone/aws/scp… بحسب مزوّدك) يُنفَّذ عبر bash -c ويرى مسار الملف كـ "$1" (مثل: rclone copyto "$1" remote:bucket/)
#
# الخيارات: --dry-run (يطبع الخطة فقط)، --allow-unencrypted (غير مُوصى به)
# مثال: BACKUP_DIR=/var/backups/ahjiyat BACKUP_AGE_RECIPIENT=age1... DB_CONNECTION=mysql DB_DATABASE=ahjiyat DB_USERNAME=backup bash tools/backup/db-backup.sh
# =============================================================================
set -Eeuo pipefail
umask 077

DRY_RUN=0
ALLOW_UNENCRYPTED=0
for arg in "$@"; do
  case "$arg" in
    --dry-run) DRY_RUN=1 ;;
    --allow-unencrypted) ALLOW_UNENCRYPTED=1 ;;
    -h|--help) sed -n '2,19p' "$0"; exit 0 ;;
    *) echo "خيار غير معروف: $arg" >&2; exit 2 ;;
  esac
done

die() { echo "خطأ: $1" >&2; exit "${2:-2}"; }

APP_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
ENGINE="${BACKUP_DB_ENGINE:-${DB_CONNECTION:-}}"
case "$ENGINE" in
  mysql|mariadb) ENGINE="mysql" ;;
  pgsql|postgres|postgresql) ENGINE="pgsql" ;;
  *) die "مشغّل القاعدة غير مدعوم أو غير مضبوط (DB_CONNECTION/BACKUP_DB_ENGINE = mysql|mariadb|pgsql)" ;;
esac

: "${BACKUP_DIR:?BACKUP_DIR إلزامي}"
: "${DB_DATABASE:?DB_DATABASE إلزامي}"
[[ "$DB_DATABASE" =~ ^[A-Za-z0-9_.-]+$ ]] || die "اسم القاعدة يحتوي أحرفًا غير مسموحة"

# لا نسخ داخل مجلدات تُخدَّم للعامة
case "$(cd "$BACKUP_DIR" 2>/dev/null && pwd || echo "$BACKUP_DIR")" in
  "$APP_DIR"/public|"$APP_DIR"/public/*) die "BACKUP_DIR داخل public/ - ستُخدَّم النسخة للعامة!" ;;
esac

ENCRYPT=none
if [ -n "${BACKUP_AGE_RECIPIENT:-}" ]; then ENCRYPT=age
elif [ -n "${BACKUP_GPG_RECIPIENT:-}" ]; then ENCRYPT=gpg
elif [ "$ALLOW_UNENCRYPTED" -ne 1 ]; then
  die "لا تشفير مضبوط (BACKUP_AGE_RECIPIENT أو BACKUP_GPG_RECIPIENT). النسخ غير المشفّرة مرفوضة افتراضيًا؛ استعمل --allow-unencrypted لتجربة محلية فقط" 3
fi

STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
OUT="${BACKUP_DIR%/}/db-${DB_DATABASE}-${STAMP}.sql.gz"
[ "$ENCRYPT" = age ] && OUT="${OUT}.age"
[ "$ENCRYPT" = gpg ] && OUT="${OUT}.gpg"

echo "المشغّل: $ENGINE | القاعدة: $DB_DATABASE | التشفير: $ENCRYPT"
echo "الإخراج: $OUT (+ .sha256)"
[ -n "${BACKUP_UPLOAD_CMD:-}" ] && echo "نسخ خارجي: سيُنفَّذ BACKUP_UPLOAD_CMD بعد النجاح" || echo "تنبيه: لا BACKUP_UPLOAD_CMD - نسخة على نفس الخادم فقط ليست نسخة احتياطية حقيقية (انسخها خارج نطاق الفشل)"

if [ "$DRY_RUN" -eq 1 ]; then
  echo "[dry-run] لم يُنفَّذ شيء."
  exit 0
fi

[ -d "$BACKUP_DIR" ] && [ -w "$BACKUP_DIR" ] || die "BACKUP_DIR غير موجود أو غير قابل للكتابة"
command -v gzip >/dev/null 2>&1 || die "gzip غير موجود"
case "$ENGINE" in
  mysql) command -v mysqldump >/dev/null 2>&1 || die "mysqldump غير موجود" ;;
  pgsql) command -v pg_dump >/dev/null 2>&1 || die "pg_dump غير موجود" ;;
esac
[ "$ENCRYPT" = age ] && { command -v age >/dev/null 2>&1 || die "age غير موجود"; }
[ "$ENCRYPT" = gpg ] && { command -v gpg >/dev/null 2>&1 || die "gpg غير موجود"; }

PARTIAL="${OUT}.partial"
trap 'rm -f "$PARTIAL"' ERR

dump() {
  case "$ENGINE" in
    mysql)
      local args=(--single-transaction --quick --routines --triggers --events --no-tablespaces --hex-blob --default-character-set=utf8mb4)
      [ -n "${BACKUP_DB_DEFAULTS_FILE:-}" ] && args=("--defaults-extra-file=${BACKUP_DB_DEFAULTS_FILE}" "${args[@]}")
      [ -n "${DB_HOST:-}" ] && args+=("--host=${DB_HOST}")
      [ -n "${DB_PORT:-}" ] && args+=("--port=${DB_PORT}")
      [ -n "${DB_USERNAME:-}" ] && args+=("--user=${DB_USERNAME}")
      mysqldump "${args[@]}" "$DB_DATABASE"
      ;;
    pgsql)
      local args=(--no-owner --no-privileges --format=plain)
      [ -n "${DB_HOST:-}" ] && args+=("--host=${DB_HOST}")
      [ -n "${DB_PORT:-}" ] && args+=("--port=${DB_PORT}")
      [ -n "${DB_USERNAME:-}" ] && args+=("--username=${DB_USERNAME}")
      pg_dump "${args[@]}" "$DB_DATABASE"
      ;;
  esac
}

case "$ENCRYPT" in
  age) dump | gzip -9 | age -r "$BACKUP_AGE_RECIPIENT" > "$PARTIAL" ;;
  gpg) dump | gzip -9 | gpg --batch --yes --encrypt --recipient "$BACKUP_GPG_RECIPIENT" --output "$PARTIAL" ;;
  none) dump | gzip -9 > "$PARTIAL" ;;
esac

[ -s "$PARTIAL" ] || die "الملف الناتج فارغ - فشلت النسخة" 4
[ "$ENCRYPT" = none ] && gzip -t "$PARTIAL"
mv "$PARTIAL" "$OUT"
( cd "$(dirname "$OUT")" && sha256sum "$(basename "$OUT")" > "$(basename "$OUT").sha256" )

echo "تمت النسخة: $(basename "$OUT") ($(wc -c < "$OUT") بايت)"

if [ -n "${BACKUP_UPLOAD_CMD:-}" ]; then
  bash -c "$BACKUP_UPLOAD_CMD" _ "$OUT"
  bash -c "$BACKUP_UPLOAD_CMD" _ "$OUT.sha256"
  echo "تم النسخ الخارجي."
fi

echo "تذكير: النسخة غير مفيدة حتى تُجرَّب استعادتها (tools/backup/db-restore.sh على بيئة نظيفة) - docs/backup-disaster-recovery.md"
```

### `tools/backup/db-restore.sh`

```bash
#!/usr/bin/env bash
# =============================================================================
# E25 - استعادة نسخة قاعدة بيانات إلى بيئة *جديدة/نظيفة* للاختبار أو التعافي. تُرفض افتراضيًا أي استعادة تبدو موجَّهة للإنتاج الحي.
# docs/backup-disaster-recovery.md (دليل الاستعادة + تمرين الاستعادة ربع السنوي)
#
# المدخلات من البيئة (لا من سطر الأوامر):
#   RESTORE_DB_ENGINE     mysql|mariadb|pgsql                (إلزامي)
#   RESTORE_DB_DATABASE   اسم القاعدة الهدف (فارغة وموجودة)  (إلزامي)
#   RESTORE_DB_HOST RESTORE_DB_PORT RESTORE_DB_USERNAME
#   كلمة المرور: MYSQL_PWD أو PGPASSWORD أو RESTORE_DB_DEFAULTS_FILE (my.cnf 600) - لا كوسيط أبدًا
#   فك التشفير: RESTORE_AGE_IDENTITY (ملف مفتاح age) للملفات .age؛ gpg يستعمل حلقة المفاتيح المحلية للملفات .gpg
#   (للمقارنة بالقاعدة الحية، إن ضُبطت بنفس بيئة التطبيق: APP_ENV, DB_HOST, DB_DATABASE)
#
# الاستعمال: bash tools/backup/db-restore.sh [--dry-run] [--yes] [--allow-non-empty] [--allow-production] <ملف-النسخة>
#
# حُرّاس الرفض (exit 3) قبل أي اتصال بالقاعدة:
#   1) APP_ENV=production        2) اسم الهدف يشبه prod|production|live      3) الهدف هو نفسه قاعدة التطبيق الحية (DB_DATABASE + DB_HOST)
#   تجاوز أي منها يتطلب --allow-production + RESTORE_CONFIRM_DATABASE=<اسم القاعدة الهدف بالضبط>، ثم تأكيدًا بكتابة اسم القاعدة (إلا مع --yes).
#   4) القاعدة الهدف غير فارغة     (تجاوزه: --allow-non-empty) - الاستعادة لا تُجرى فوق بيانات موجودة افتراضيًا.
# =============================================================================
set -Eeuo pipefail
umask 077

DRY_RUN=0
ASSUME_YES=0
ALLOW_NON_EMPTY=0
ALLOW_PRODUCTION=0
FILE=""

for arg in "$@"; do
  case "$arg" in
    --dry-run) DRY_RUN=1 ;;
    --yes) ASSUME_YES=1 ;;
    --allow-non-empty) ALLOW_NON_EMPTY=1 ;;
    --allow-production) ALLOW_PRODUCTION=1 ;;
    -h|--help) sed -n '2,22p' "$0"; exit 0 ;;
    -*) echo "خيار غير معروف: $arg" >&2; exit 2 ;;
    *) [ -z "$FILE" ] && FILE="$arg" || { echo "وسيط زائد: $arg" >&2; exit 2; } ;;
  esac
done

die() { echo "خطأ: $1" >&2; exit "${2:-2}"; }
refuse() { echo "رُفضت الاستعادة: $*" >&2; exit 3; }

[ -n "$FILE" ] || die "مرّر ملف النسخة كوسيط (انظر --help)"
[ -f "$FILE" ] || die "ملف النسخة غير موجود"

ENGINE="${RESTORE_DB_ENGINE:-}"
case "$ENGINE" in
  mysql|mariadb) ENGINE="mysql" ;;
  pgsql|postgres|postgresql) ENGINE="pgsql" ;;
  *) die "RESTORE_DB_ENGINE غير مضبوط/غير مدعوم (mysql|mariadb|pgsql)" ;;
esac
TARGET_DB="${RESTORE_DB_DATABASE:-}"
[ -n "$TARGET_DB" ] || die "RESTORE_DB_DATABASE إلزامي"
[[ "$TARGET_DB" =~ ^[A-Za-z0-9_.-]+$ ]] || die "اسم القاعدة الهدف يحتوي أحرفًا غير مسموحة"
TARGET_HOST="${RESTORE_DB_HOST:-}"

norm_host() {
  case "${1:-}" in ""|localhost|127.0.0.1|::1) echo "local" ;; *) echo "$1" | tr '[:upper:]' '[:lower:]' ;; esac
}

# ───── الحُرّاس (كلها قبل أي أمر قاعدة بيانات) ─────
REASONS=()
[ "${APP_ENV:-}" = "production" ] && REASONS+=("APP_ENV=production بهذه البيئة")
if [[ "$(echo "$TARGET_DB" | tr '[:upper:]' '[:lower:]')" =~ (prod|production|live) ]]; then
  REASONS+=("اسم القاعدة الهدف يشبه قاعدة إنتاج")
fi
if [ -n "${DB_DATABASE:-}" ] && [ "$TARGET_DB" = "$DB_DATABASE" ] && [ "$(norm_host "$TARGET_HOST")" = "$(norm_host "${DB_HOST:-}")" ]; then
  REASONS+=("الهدف هو نفسه قاعدة التطبيق الحية (DB_DATABASE على DB_HOST)")
fi

if [ "${#REASONS[@]}" -gt 0 ]; then
  if [ "$ALLOW_PRODUCTION" -ne 1 ]; then
    refuse "${REASONS[*]}. لن تُستعاد نسخة فوق إنتاج حي افتراضيًا. استعدها إلى بيئة نظيفة منفصلة (docs/backup-disaster-recovery.md)."
  fi
  if [ "${RESTORE_CONFIRM_DATABASE:-}" != "$TARGET_DB" ]; then
    refuse "تجاوز الإنتاج يتطلب RESTORE_CONFIRM_DATABASE مطابقًا تمامًا لاسم القاعدة الهدف."
  fi
fi

# ───── نوع الملف ─────
CAT=(cat "$FILE")
case "$FILE" in
  *.age)
    command -v age >/dev/null 2>&1 || die "age غير موجود"
    [ -n "${RESTORE_AGE_IDENTITY:-}" ] && [ -f "${RESTORE_AGE_IDENTITY}" ] || die "RESTORE_AGE_IDENTITY (ملف مفتاح age) مطلوب لفك التشفير"
    CAT=(age -d -i "$RESTORE_AGE_IDENTITY" "$FILE") ;;
  *.gpg)
    command -v gpg >/dev/null 2>&1 || die "gpg غير موجود"
    CAT=(gpg --batch --quiet --decrypt "$FILE") ;;
esac

echo "المشغّل: $ENGINE | الهدف: $TARGET_DB | الملف: $(basename "$FILE")"
echo "الخطة: [تحقق من الـchecksum إن وُجد] → [فحص أن الهدف فارغ] → [فك التشفير/الضغط] → [استعادة] → التالي: production:check ثم release:smoke على البيئة المستعادة"

if [ "$DRY_RUN" -eq 1 ]; then
  echo "[dry-run] اجتازت كل حُرّاس الرفض. لم يُنفَّذ شيء."
  exit 0
fi

if [ -f "${FILE}.sha256" ]; then
  ( cd "$(dirname "$FILE")" && sha256sum -c "$(basename "$FILE").sha256" >/dev/null ) || die "فشل التحقق من checksum - النسخة تالفة أو معدّلة" 4
  echo "checksum سليم."
else
  echo "تنبيه: لا ملف .sha256 بجوار النسخة - لم يُتحقَّق من سلامتها."
fi

case "$ENGINE" in
  mysql) command -v mysql >/dev/null 2>&1 || die "mysql (العميل) غير موجود" ;;
  pgsql) command -v psql >/dev/null 2>&1 || die "psql (العميل) غير موجود" ;;
esac

client_args() {
  case "$ENGINE" in
    mysql)
      local a=()
      [ -n "${RESTORE_DB_DEFAULTS_FILE:-}" ] && a+=("--defaults-extra-file=${RESTORE_DB_DEFAULTS_FILE}")
      [ -n "$TARGET_HOST" ] && a+=("--host=${TARGET_HOST}")
      [ -n "${RESTORE_DB_PORT:-}" ] && a+=("--port=${RESTORE_DB_PORT}")
      [ -n "${RESTORE_DB_USERNAME:-}" ] && a+=("--user=${RESTORE_DB_USERNAME}")
      printf '%s\0' "${a[@]+"${a[@]}"}" ;;
    pgsql)
      local a=()
      [ -n "$TARGET_HOST" ] && a+=("--host=${TARGET_HOST}")
      [ -n "${RESTORE_DB_PORT:-}" ] && a+=("--port=${RESTORE_DB_PORT}")
      [ -n "${RESTORE_DB_USERNAME:-}" ] && a+=("--username=${RESTORE_DB_USERNAME}")
      printf '%s\0' "${a[@]+"${a[@]}"}" ;;
  esac
}
ARGS=()
while IFS= read -r -d '' item; do ARGS+=("$item"); done < <(client_args)

# 4) الهدف يجب أن يكون فارغًا
if [ "$ENGINE" = mysql ]; then
  TABLES="$(mysql "${ARGS[@]+"${ARGS[@]}"}" -N -B -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()" "$TARGET_DB")"
else
  TABLES="$(psql "${ARGS[@]+"${ARGS[@]}"}" -d "$TARGET_DB" -At -c "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema NOT IN ('pg_catalog','information_schema')")"
fi
if [ "${TABLES:-0}" != "0" ] && [ "$ALLOW_NON_EMPTY" -ne 1 ]; then
  refuse "القاعدة الهدف غير فارغة (${TABLES} جدول). استعمل قاعدة جديدة فارغة (أو --allow-non-empty عن وعي)."
fi

if [ "$ASSUME_YES" -ne 1 ]; then
  [ -t 0 ] || die "لا طرفية للتأكيد: مرّر --yes للتشغيل غير التفاعلي" 2
  read -r -p "ستُستعاد النسخة إلى القاعدة '${TARGET_DB}'. اكتب اسمها للمتابعة: " CONFIRM
  [ "$CONFIRM" = "$TARGET_DB" ] || refuse "التأكيد لا يطابق اسم القاعدة."
fi

echo "جارٍ الاستعادة …"
if [ "$ENGINE" = mysql ]; then
  "${CAT[@]}" | gunzip | mysql "${ARGS[@]+"${ARGS[@]}"}" "$TARGET_DB"
else
  "${CAT[@]}" | gunzip | psql "${ARGS[@]+"${ARGS[@]}"}" -d "$TARGET_DB" -v ON_ERROR_STOP=1 -q
fi

echo "تمت الاستعادة. التالي على البيئة المستعادة: ضبط الأسرار (بما فيها APP_KEY الأصلي)، php artisan production:check، php artisan release:preflight، ثم release:smoke."
```

### `tools/backup/files-backup.sh`

```bash
#!/usr/bin/env bash
# =============================================================================
# E25 - نسخة احتياطية للملفات الدائمة (الهوية البصرية، وسائط التجميليات، أي رفع دائم): storage/app/public افتراضيًا.
# لا تشمل storage/logs ولا storage/framework (كاش/جلسات/مترجمات: قابلة لإعادة البناء). محايدة للمزوّد، بلا أسرار.
# إن كانت الملفات على قرص سحابي (S3 وغيره) فاستعمل أداة المزوّد (versioning/replication) بدل هذا السكربت.
#
# المدخلات: BACKUP_DIR (إلزامي)، BACKUP_FILES_SOURCE (الافتراضي storage/app/public نسبةً لمجلد التطبيق)،
#           BACKUP_AGE_RECIPIENT | BACKUP_GPG_RECIPIENT (التشفير إلزامي إلا مع --allow-unencrypted)، BACKUP_UPLOAD_CMD (اختياري)
# الخيارات: --dry-run، --allow-unencrypted
# =============================================================================
set -Eeuo pipefail
umask 077

DRY_RUN=0
ALLOW_UNENCRYPTED=0
for arg in "$@"; do
  case "$arg" in
    --dry-run) DRY_RUN=1 ;;
    --allow-unencrypted) ALLOW_UNENCRYPTED=1 ;;
    -h|--help) sed -n '2,10p' "$0"; exit 0 ;;
    *) echo "خيار غير معروف: $arg" >&2; exit 2 ;;
  esac
done

die() { echo "خطأ: $1" >&2; exit "${2:-2}"; }

APP_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
: "${BACKUP_DIR:?BACKUP_DIR إلزامي}"
SOURCE="${BACKUP_FILES_SOURCE:-storage/app/public}"
case "$SOURCE" in /*) ;; *) SOURCE="$APP_DIR/$SOURCE" ;; esac

case "$(cd "$BACKUP_DIR" 2>/dev/null && pwd || echo "$BACKUP_DIR")" in
  "$APP_DIR"/public|"$APP_DIR"/public/*|"$SOURCE"|"$SOURCE"/*) die "BACKUP_DIR داخل public/ أو داخل مصدر النسخ نفسه" ;;
esac

ENCRYPT=none
if [ -n "${BACKUP_AGE_RECIPIENT:-}" ]; then ENCRYPT=age
elif [ -n "${BACKUP_GPG_RECIPIENT:-}" ]; then ENCRYPT=gpg
elif [ "$ALLOW_UNENCRYPTED" -ne 1 ]; then
  die "لا تشفير مضبوط (BACKUP_AGE_RECIPIENT أو BACKUP_GPG_RECIPIENT). النسخ غير المشفّرة مرفوضة افتراضيًا" 3
fi

STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
OUT="${BACKUP_DIR%/}/files-${STAMP}.tar.gz"
[ "$ENCRYPT" = age ] && OUT="${OUT}.age"
[ "$ENCRYPT" = gpg ] && OUT="${OUT}.gpg"

echo "المصدر: $SOURCE | التشفير: $ENCRYPT"
echo "الإخراج: $OUT (+ .sha256)"
[ -n "${BACKUP_UPLOAD_CMD:-}" ] || echo "تنبيه: لا BACKUP_UPLOAD_CMD - نسخة على نفس الخادم فقط ليست نسخة احتياطية حقيقية"

if [ "$DRY_RUN" -eq 1 ]; then
  echo "[dry-run] لم يُنفَّذ شيء."
  exit 0
fi

[ -d "$SOURCE" ] || die "مجلد المصدر غير موجود: $SOURCE"
[ -d "$BACKUP_DIR" ] && [ -w "$BACKUP_DIR" ] || die "BACKUP_DIR غير موجود أو غير قابل للكتابة"

PARTIAL="${OUT}.partial"
trap 'rm -f "$PARTIAL"' ERR

pack() { tar -C "$SOURCE" -czf - .; }

case "$ENCRYPT" in
  age) command -v age >/dev/null 2>&1 || die "age غير موجود"; pack | age -r "$BACKUP_AGE_RECIPIENT" > "$PARTIAL" ;;
  gpg) command -v gpg >/dev/null 2>&1 || die "gpg غير موجود"; pack | gpg --batch --yes --encrypt --recipient "$BACKUP_GPG_RECIPIENT" --output "$PARTIAL" ;;
  none) pack > "$PARTIAL" ;;
esac

[ -s "$PARTIAL" ] || die "الملف الناتج فارغ - فشلت النسخة" 4
mv "$PARTIAL" "$OUT"
( cd "$(dirname "$OUT")" && sha256sum "$(basename "$OUT")" > "$(basename "$OUT").sha256" )

echo "تمت النسخة: $(basename "$OUT") ($(wc -c < "$OUT") بايت)"

if [ -n "${BACKUP_UPLOAD_CMD:-}" ]; then
  bash -c "$BACKUP_UPLOAD_CMD" _ "$OUT"
  bash -c "$BACKUP_UPLOAD_CMD" _ "$OUT.sha256"
  echo "تم النسخ الخارجي."
fi
```

### `tools/backup/files-restore.sh`

```bash
#!/usr/bin/env bash
# =============================================================================
# E25 - استعادة نسخة الملفات (files-backup.sh) إلى مجلد *فارغ* منفصل. لا تستبدل مجلد الإنتاج الحي افتراضيًا.
#
# المدخلات: RESTORE_FILES_TARGET (إلزامي: مجلد هدف فارغ أو غير موجود)، RESTORE_AGE_IDENTITY (للملفات .age)
# الاستعمال: bash tools/backup/files-restore.sh [--dry-run] [--allow-production] <ملف-النسخة>
# الرفض (exit 3): APP_ENV=production، أو الهدف داخل storage/app/public الحي/مطابق له، أو الهدف غير فارغ.
# تجاوز الإنتاج: --allow-production + RESTORE_CONFIRM_TARGET=<مسار الهدف بالضبط>.
# =============================================================================
set -Eeuo pipefail
umask 077

DRY_RUN=0
ALLOW_PRODUCTION=0
FILE=""
for arg in "$@"; do
  case "$arg" in
    --dry-run) DRY_RUN=1 ;;
    --allow-production) ALLOW_PRODUCTION=1 ;;
    -h|--help) sed -n '2,9p' "$0"; exit 0 ;;
    -*) echo "خيار غير معروف: $arg" >&2; exit 2 ;;
    *) [ -z "$FILE" ] && FILE="$arg" || { echo "وسيط زائد: $arg" >&2; exit 2; } ;;
  esac
done

die() { echo "خطأ: $1" >&2; exit "${2:-2}"; }
refuse() { echo "رُفضت الاستعادة: $*" >&2; exit 3; }

[ -n "$FILE" ] || die "مرّر ملف النسخة كوسيط"
[ -f "$FILE" ] || die "ملف النسخة غير موجود"
: "${RESTORE_FILES_TARGET:?RESTORE_FILES_TARGET إلزامي}"

APP_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
LIVE="$APP_DIR/storage/app/public"
TARGET="${RESTORE_FILES_TARGET%/}"
TARGET_ABS="$(cd "$TARGET" 2>/dev/null && pwd || echo "$TARGET")"

REASONS=()
[ "${APP_ENV:-}" = "production" ] && REASONS+=("APP_ENV=production بهذه البيئة")
case "$TARGET_ABS" in "$LIVE"|"$LIVE"/*) REASONS+=("الهدف هو مجلد الملفات الحي أو داخله") ;; esac

if [ "${#REASONS[@]}" -gt 0 ]; then
  [ "$ALLOW_PRODUCTION" -eq 1 ] || refuse "${REASONS[*]}. استعد إلى مجلد منفصل ثم انسخ بوعي."
  [ "${RESTORE_CONFIRM_TARGET:-}" = "$TARGET_ABS" ] || refuse "التجاوز يتطلب RESTORE_CONFIRM_TARGET مطابقًا تمامًا لمسار الهدف."
fi

if [ -d "$TARGET" ] && [ -n "$(ls -A "$TARGET" 2>/dev/null)" ]; then
  refuse "المجلد الهدف غير فارغ."
fi

CAT=(cat "$FILE")
case "$FILE" in
  *.age) command -v age >/dev/null 2>&1 || die "age غير موجود"; [ -f "${RESTORE_AGE_IDENTITY:-}" ] || die "RESTORE_AGE_IDENTITY مطلوب"; CAT=(age -d -i "$RESTORE_AGE_IDENTITY" "$FILE") ;;
  *.gpg) command -v gpg >/dev/null 2>&1 || die "gpg غير موجود"; CAT=(gpg --batch --quiet --decrypt "$FILE") ;;
esac

echo "الملف: $(basename "$FILE") → الهدف: $TARGET_ABS"

if [ "$DRY_RUN" -eq 1 ]; then
  echo "[dry-run] اجتازت كل حُرّاس الرفض. لم يُنفَّذ شيء."
  exit 0
fi

if [ -f "${FILE}.sha256" ]; then
  ( cd "$(dirname "$FILE")" && sha256sum -c "$(basename "$FILE").sha256" >/dev/null ) || die "فشل التحقق من checksum" 4
fi

mkdir -p "$TARGET"
"${CAT[@]}" | tar -xzf - -C "$TARGET"
echo "تمت الاستعادة إلى $TARGET_ABS. راجع المحتوى ثم انسخه إلى مكانه يدويًا إن لزم."
```

### `tools/deploy/deploy.sh`

```bash
#!/usr/bin/env bash
# =============================================================================
# E25 - تسلسل نشر الإنتاج (نموذج محايد للمزوّد). لا يُشغَّل تلقائيًا من أي مكان: راجعه وكيّفه لبنيتك أولًا.
# docs/first-production-deploy.md  ·  docs/release-engineering.md
#
# يفترض أن الكود الجديد موضوع مسبقًا في مجلد التطبيق (artifact/rsync/آلية مزوّدك) - لا يستعمل git ولا يسحب شيئًا.
# لا يحتوي أسرارًا: كل شيء من البيئة. لا يطبع أي سر. لا يستعمل أوامر هدّامة (لا migrate:fresh/db:wipe/rollback) ولا يحذف ملفات.
#
# الاستعمال:
#   bash tools/deploy/deploy.sh --dry-run                       # يطبع الخطوات فقط ولا ينفّذ شيئًا (ابدأ بهذا دائمًا)
#   bash tools/deploy/deploy.sh --release=b983867 --backup-confirmed
#
# الخيارات:
#   --release=ID         معرّف الإصدار (SHA مختصر/وسم) - يُخبَز في كاش الإعداد عبر optimize ويظهر في /ready والسجلات (أو RELEASE_SHA/APP_RELEASE من البيئة)
#   --backup-confirmed   إقرار صريح بأن نسخة احتياطية حديثة مُتحقَّق منها موجودة (إلزامي لتنفيذ migrate؛ أو DEPLOY_BACKUP_CONFIRMED=yes)
#   --maintenance        php artisan down أثناء الترحيل (فقط لترحيل غير متوافق مع الكود السابق، أو حادثة). سرّ الدخول (إن وُجد) من MAINTENANCE_SECRET بالبيئة ولا يُطبع.
#   --skip-build         لا composer/npm (الأصول والتبعيات مبنية سلفًا بـCI/artifact)
#   --dry-run            لا ينفّذ شيئًا
#
# متغيرات اختيارية بالبيئة:
#   DEPLOY_RELOAD_CMD    أمر إعادة تحميل PHP-FPM/OPcache الخاص ببنيتك (مثل: "sudo systemctl reload php8.3-fpm") - يُنفَّذ بعد optimize
#   DEPLOY_SMOKE_URL     عنوان فحص الدخان (الافتراضي APP_URL من .env الحالي عبر artisan)
# =============================================================================
set -Eeuo pipefail

DRY_RUN=0
SKIP_BUILD=0
MAINTENANCE=0
BACKUP_CONFIRMED=0
RELEASE="${APP_RELEASE:-${RELEASE_SHA:-}}"
MAINT_ACTIVE=0

for arg in "$@"; do
  case "$arg" in
    --dry-run) DRY_RUN=1 ;;
    --skip-build) SKIP_BUILD=1 ;;
    --maintenance) MAINTENANCE=1 ;;
    --backup-confirmed) BACKUP_CONFIRMED=1 ;;
    --release=*) RELEASE="${arg#--release=}" ;;
    -h|--help) sed -n '2,28p' "$0"; exit 0 ;;
    *) echo "خيار غير معروف: $arg" >&2; exit 2 ;;
  esac
done

[ "${DEPLOY_BACKUP_CONFIRMED:-}" = "yes" ] && BACKUP_CONFIRMED=1

if [ -n "$RELEASE" ] && ! [[ "$RELEASE" =~ ^[A-Za-z0-9._-]{1,64}$ ]]; then
  echo "معرّف الإصدار غير آمن (المسموح [A-Za-z0-9._-] حتى 64 خانة)" >&2
  exit 2
fi
export APP_RELEASE="$RELEASE"

step() { echo; echo "==> $*"; }

# ينفّذ الأمر أو يطبعه (dry-run). لا يستعمل eval، ولا يطبع قيم المتغيرات السرية.
run() {
  if [ "$DRY_RUN" -eq 1 ]; then
    echo "    [dry-run] $*"
  else
    "$@"
  fi
}

on_error() {
  local code=$?
  echo >&2
  echo "فشل النشر عند الخطوة أعلاه (exit $code). لم يُنفَّذ أي تراجع تلقائي عن القاعدة (التراجع عن القاعدة غير آمن بعد الكتابات)." >&2
  if [ "$MAINT_ACTIVE" -eq 1 ]; then
    echo "التطبيق ما زال في وضع الصيانة عمدًا - لا تُخرجه قبل فهم السبب (docs/release-engineering.md: جدول التراجع)." >&2
  fi
  echo "راجع: docs/release-engineering.md (التراجع) و docs/incident-response.md" >&2
  exit "$code"
}
trap on_error ERR

cd "$(dirname "$0")/../.."
echo "مجلد التطبيق: $(pwd)"
echo "الإصدار: ${RELEASE:-<غير مضبوط - يُنصح بـ --release=>}"
[ "$DRY_RUN" -eq 1 ] && echo "(وضع dry-run: لن يُنفَّذ شيء)"

step "0) متطلبات أساسية"
# بالتشغيل الفعلي أي نقص يوقف السكربت؛ بـ dry-run يُبلَّغ عنه فقط (كي يمكن مراجعة التسلسل على أي جهاز).
need() {
  if [ "$DRY_RUN" -eq 1 ]; then echo "    [dry-run] ينقص: $1"; else echo "$1" >&2; exit 1; fi
}
for bin in php composer; do
  command -v "$bin" >/dev/null 2>&1 || need "الأداة غير موجودة: $bin"
done
if [ "$SKIP_BUILD" -eq 0 ]; then
  command -v npm >/dev/null 2>&1 || need "الأداة غير موجودة: npm (أو استعمل --skip-build)"
fi
[ -f artisan ] || need "artisan غير موجود: شغّل السكربت من مستودع التطبيق"
[ -f .env ] || [ -n "${APP_KEY:-}" ] || need "لا .env ولا متغيرات بيئة (APP_KEY) - جهّز البيئة أولًا (docs/environment-variables.md)"

if [ "$SKIP_BUILD" -eq 0 ]; then
  step "1) التبعيات (composer.lock هو مصدر الإصدارات - لا composer update)"
  run composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction

  step "2) بناء الواجهة (package-lock.json هو مصدر الإصدارات - لا npm update)"
  run npm ci
  run npm run build
else
  step "1-2) تخطّي بناء التبعيات والواجهة (--skip-build)"
fi

step "3) فحص ما قبل النشر (قراءة فقط) - يوقف النشر عند أي FAIL"
run php artisan release:preflight

step "3b) إقرار النسخة الاحتياطية - قبل أي تغيير بالحالة (بما فيه وضع الصيانة، كي لا يبقى الموقع في صيانة بسبب رفض لاحق)"
if [ "$BACKUP_CONFIRMED" -ne 1 ] && [ "$DRY_RUN" -ne 1 ]; then
  echo "ممنوع تنفيذ migrate بلا إقرار النسخة الاحتياطية. خذ نسخة (tools/backup/db-backup.sh) ثم أعد التشغيل بـ --backup-confirmed" >&2
  exit 3
fi
[ "$BACKUP_CONFIRMED" -eq 1 ] && echo "    نسخة احتياطية مُقرّة." || echo "    [dry-run] سيُطلب --backup-confirmed عند التنفيذ الفعلي."

if [ "$MAINTENANCE" -eq 1 ]; then
  step "4) وضع الصيانة (لترحيل غير متوافق فقط) - أثناءه: HTTP يعطي 503، العمال يبقون عمليات قائمة لكن لا يسحبون مهامًا، والمجدول يتخطى المهام"
  if [ -n "${MAINTENANCE_SECRET:-}" ]; then
    # السرّ لا يُطبع أبدًا (ولا بـ dry-run). استعمل قيمة جديدة لكل مرة ولا تحفظها بالمستودع/الوثائق/السجلات.
    if [ "$DRY_RUN" -eq 1 ]; then
      echo "    [dry-run] php artisan down --retry=60 --refresh=15 --secret=<من MAINTENANCE_SECRET>"
    else
      php artisan down --retry=60 --refresh=15 --secret="$MAINTENANCE_SECRET"
    fi
  else
    run php artisan down --retry=60 --refresh=15
  fi
  MAINT_ACTIVE=1
else
  step "4) بلا وضع الصيانة (الترحيلات المتوافقة additive-first لا تحتاجه)"
fi

step "5) الترحيلات (للأمام فقط؛ الإقرار بالنسخة الاحتياطية تحقّقنا منه قبل الصيانة)"
run php artisan migrate --force

step "6) مزامنة سجلّ الصلاحيات (ينشئ الناقص فقط ولا يمنح أدوارًا؛ منح صلاحيات جديدة للأدوار = RolesAndPermissionsSeeder خطوة يدوية واعية عند الحاجة)"
run php artisan permissions:sync

step "7) رابط التخزين العام (آمن إن وُجد)"
if [ "$DRY_RUN" -eq 1 ]; then echo "    [dry-run] php artisan storage:link"; else php artisan storage:link || true; fi

step "8) optimize: يبني كاش config/routes/views/events ويخبز APP_RELEASE بالكاش"
run php artisan optimize

if [ -n "${DEPLOY_RELOAD_CMD:-}" ]; then
  step "9) إعادة تحميل PHP-FPM/OPcache (الأمر من DEPLOY_RELOAD_CMD)"
  if [ "$DRY_RUN" -eq 1 ]; then echo "    [dry-run] \$DEPLOY_RELOAD_CMD"; else bash -c "$DEPLOY_RELOAD_CMD"; fi
else
  step "9) لا DEPLOY_RELOAD_CMD: أعد تحميل PHP-FPM/OPcache يدويًا بحسب بنيتك كي يُحمَّل الكود الجديد"
fi

step "10) إعادة تشغيل آمنة (graceful) لعمال الطابور وReverb - يُكمل كل عامل مهمته الحالية ثم يخرج ويعيده المشرف (supervisor/systemd)"
run php artisan queue:restart
run php artisan reverb:restart

if [ "$MAINT_ACTIVE" -eq 1 ]; then
  step "11) إخراج التطبيق من وضع الصيانة"
  run php artisan up
  MAINT_ACTIVE=0
fi

step "12) فحص دخان بعد النشر (GET فقط، بلا كتابة)"
SMOKE_ARGS=()
[ -n "${DEPLOY_SMOKE_URL:-}" ] && SMOKE_ARGS+=("--url=${DEPLOY_SMOKE_URL}")
[ -n "$RELEASE" ] && SMOKE_ARGS+=("--expect-release=${RELEASE}")
run php artisan release:smoke ${SMOKE_ARGS[@]+"${SMOKE_ARGS[@]}"}

step "13) فحص الإنتاج النهائي"
run php artisan production:check

echo
echo "اكتمل تسلسل النشر. راقب السجلات ولوحة العمليات 15-30 دقيقة (docs/operations-runbook.md)."
```

### `tools/smoke/README.md`

````markdown
# فحص الدخان بعد النشر (E25)

الأداة الرسمية: **أمر Artisan** `release:smoke` (لا سكربت منفصل، كي يعمل بنفس بيئة التطبيق ولا يحتاج أدوات إضافية).

```bash
php artisan release:smoke                                  # على APP_URL
php artisan release:smoke --url=https://ahjiyat.example.com
php artisan release:smoke --expect-release=b983867         # يتحقق أن /ready يعرض هذا الإصدار (يكشف نشرًا لم يُفعَّل)
php artisan release:smoke --profile=<public_id لاعب عام>   # يضيف /players/{profile}
php artisan release:smoke --json                           # لأدوات المراقبة
```

- **قراءة فقط**: طلبات `GET` فقط على مسارات عامة (`/up`، `/ready`، `/`، `/login`، `/puzzles`، `/competitions`، `/store`)، بلا جلسة ولا بيانات اعتماد ولا كتابة (لا صداقة ولا محاولة أحجية ولا رسالة ولا محفظة).
- **مهلة صغيرة** لكل طلب (`config/operations.php`: 5ث، اتصال 3ث) ويُعلَّم البطيء (> 2000ms) بتحذير.
- **المخرجات**: `PASS/WARN/FAIL` + المسار + الحالة + الزمن. Exit `0` = لا FAIL، `1` = فشل.
- الفحص المصادَق (حساب لاعب) لا يُجرى بالإنتاج: يتم بـFeature tests على بيئة staging (docs/smoke-tests.md).

بديل بلا PHP (فحص يدوي سريع بـcurl، GET فقط):

```bash
for p in /up /ready / /login /puzzles /competitions /store; do
  curl -sS -o /dev/null -m 5 -w "%{http_code} %{time_total}s $p\n" "https://ahjiyat.example.com$p"
done
```
````

## B) ملفات معدَّلة (patch دقيق لكل ملف؛ النسخة الكاملة داخل الـzip)

### `app/Console/Commands/ProductionCheck.php`

```diff
--- a/app/Console/Commands/ProductionCheck.php
+++ b/app/Console/Commands/ProductionCheck.php
@@ -2,234 +2,39 @@
 
 namespace App\Console\Commands;
 
+use App\Console\Commands\Concerns\RendersCheckReport;
+use App\Services\Operations\ProductionChecker;
 use Illuminate\Console\Command;
-use Illuminate\Support\Facades\DB;
-use Illuminate\Support\Facades\File;
-use Illuminate\Support\Facades\Cache;
-use Illuminate\Support\Facades\Storage;
-use Throwable;
 
+/**
+ * E25: عقد بيئة الإنتاج. المنطق كله في App\Services\Operations\ProductionChecker (للقراءة فقط: لا كتابة قاعدة/كاش/تخزين، لا ترحيل، لا إعادة تشغيل).
+ * Exit 0 = جاهز (قد توجد تحذيرات)، 1 = مشكلة حاجبة. لا تُطبع أي قيمة سرية: PASS/WARN/FAIL + اسم الإعداد فقط.
+ * الشدّة: بالإنتاج (APP_ENV=production) تصير معظم المخالفات FAIL؛ خارجه تبقى تحذيرات، إلا الحاجبة دائمًا (APP_DEBUG، APP_KEY، القاعدة…).
+ */
 class ProductionCheck extends Command
 {
-    protected $signature = 'production:check';
+    use RendersCheckReport;
 
-    protected $description = 'فحوصات جاهزية الإنتاج (Read-Only بالكامل) - Exit code صفر إذا لا مشاكل حرجة، غير صفر خلاف ذلك';
-
-    protected int $criticalFailures = 0;
-
-    protected int $warnings = 0;
-
-    public function handle(): int
-    {
-        $this->info('== فحص جاهزية الإنتاج (Read-Only) ==');
-        $this->newLine();
-
-        $this->checkEnvironment();
-        $this->checkAppKey();
-        $this->checkDatabase();
-        $this->checkStorage();
-        $this->checkCache();
-        $this->checkQueue();
-        $this->checkMail();
-        $this->checkPermissionRegistry();
-        $this->checkBuildManifest();
-        $this->checkMigrationStatus();
-
-        $this->newLine();
-        $this->info("النتيجة: {$this->criticalFailures} فشل حرج، {$this->warnings} تحذير.");
-
-        return $this->criticalFailures > 0 ? self::FAILURE : self::SUCCESS;
-    }
-
-    protected function pass(string $label): void
-    {
-        $this->line("  <fg=green>PASS</> {$label}");
-    }
-
-    protected function warn2(string $label): void
-    {
-        $this->warnings++;
-        $this->line("  <fg=yellow>WARN</> {$label}");
-    }
-
-    protected function failCheck(string $label): void
-    {
-        $this->criticalFailures++;
-        $this->line("  <fg=red>FAIL</> {$label}");
-    }
-
-    protected function checkEnvironment(): void
-    {
-        $this->comment('البيئة');
-
-        if (app()->environment('production')) {
-            $this->pass('APP_ENV = production');
-        } else {
-            $this->warn2('APP_ENV ليست production حالياً (بيئة: '.app()->environment().')');
-        }
-
-        if (config('app.debug')) {
-            $this->failCheck('APP_DEBUG مفعَّلة - يجب أن تكون false بالإنتاج (تُظهر Stack Traces للعامة)');
-        } else {
-            $this->pass('APP_DEBUG معطَّلة');
-        }
-
-        $url = (string) config('app.url');
-        if (str_starts_with($url, 'https://')) {
-            $this->pass('APP_URL يستخدم HTTPS');
-        } else {
-            $this->warn2('APP_URL لا يبدأ بـhttps:// - تأكد من ضبطه بالإنتاج الفعلي');
-        }
-    }
-
-    protected function checkAppKey(): void
-    {
-        $this->comment('مفتاح التطبيق');
+    protected $signature = 'production:check {--json : مخرجات JSON لأدوات المراقبة وCI (بلا قيم سرية)}';
 
-        if (filled(config('app.key'))) {
-            $this->pass('APP_KEY موجود');
-        } else {
-            $this->failCheck('APP_KEY غير مضبوط - التطبيق لن يعمل بأمان (Session/Cookies/Encryption)');
-        }
-    }
-
-    protected function checkDatabase(): void
-    {
-        $this->comment('قاعدة البيانات');
-
-        try {
-            DB::connection()->getPdo();
-            $this->pass('الاتصال بقاعدة البيانات ناجح ('.config('database.default').')');
-        } catch (Throwable $e) {
-            $this->failCheck('تعذَّر الاتصال بقاعدة البيانات - راجع سجلات الخادم للتفاصيل (لن تُعرَض هنا)');
-        }
-    }
-
-    protected function checkStorage(): void
-    {
-        $this->comment('التخزين');
-
-        if (is_writable(storage_path())) {
-            $this->pass('مجلد storage قابل للكتابة');
-        } else {
-            $this->failCheck('مجلد storage غير قابل للكتابة - راجع صلاحيات الملفات');
-        }
-
-        if (is_writable(base_path('bootstrap/cache'))) {
-            $this->pass('مجلد bootstrap/cache قابل للكتابة');
-        } else {
-            $this->failCheck('مجلد bootstrap/cache غير قابل للكتابة - راجع صلاحيات الملفات');
-        }
-
-        if (File::exists(public_path('storage')) || is_link(public_path('storage'))) {
-            $this->pass('رابط public/storage موجود');
-        } else {
-            $this->warn2('رابط public/storage غير موجود - شغّل php artisan storage:link (لا تعتمد Windows Junction بالإنتاج)');
-        }
-
-        try {
-            Storage::disk('public')->put('__production_check_tmp.txt', 'ok');
-            Storage::disk('public')->delete('__production_check_tmp.txt');
-            $this->pass('قرص public قابل للكتابة فعلياً');
-        } catch (Throwable $e) {
-            $this->failCheck('قرص public غير قابل للكتابة فعلياً - سيفشل رفع أي ملف');
-        }
-    }
-
-    protected function checkCache(): void
-    {
-        $this->comment('الكاش');
-
-        try {
-            Cache::put('__production_check_tmp', true, 5);
-            Cache::forget('__production_check_tmp');
-            $this->pass('Cache Store ('.config('cache.default').') يعمل فعلياً');
-        } catch (Throwable $e) {
-            $this->failCheck('Cache Store لا يعمل - سيؤثر على التحليلات والإعدادات والصلاحيات');
-        }
-    }
-
-    protected function checkQueue(): void
-    {
-        $this->comment('الطوابير');
-
-        $connection = config('queue.default');
-        $this->line("  <fg=cyan>INFO</> QUEUE_CONNECTION = {$connection}");
-
-        if ($connection === 'database') {
-            if (DB::getSchemaBuilder()->hasTable('jobs') && DB::getSchemaBuilder()->hasTable('failed_jobs')) {
-                $this->pass('جدولا jobs/failed_jobs موجودان');
-            } else {
-                $this->failCheck('QUEUE_CONNECTION=database لكن جدولي jobs/failed_jobs غير موجودين - شغّل migrate');
-            }
-        }
-
-        if ($connection === 'sync') {
-            $this->warn2('QUEUE_CONNECTION=sync - كل عملية تُنفَّذ فوراً بلا طابور حقيقي (مقبول حالياً - لا Jobs مُوَحَّدة (ShouldQueue) بالمشروع بعد)');
-        }
-    }
-
-    protected function checkMail(): void
-    {
-        $this->comment('البريد الإلكتروني');
-
-        $mailer = config('mail.default');
-        $this->line("  <fg=cyan>INFO</> MAIL_MAILER = {$mailer}");
-
-        if ($mailer === 'log') {
-            $this->warn2('MAIL_MAILER=log - لن يصل أي بريد فعلي (توثيق بريد/استرجاع كلمة سر) - اضبط SMTP حقيقي بالإنتاج');
-        } else {
-            if (filled(config('mail.from.address'))) {
-                $this->pass('MAIL_FROM_ADDRESS مضبوط');
-            } else {
-                $this->warn2('MAIL_FROM_ADDRESS غير مضبوط');
-            }
-        }
-    }
+    protected $description = 'فحوصات جاهزية الإنتاج (Read-Only بالكامل) - Exit code صفر إذا لا مشاكل حرجة، غير صفر خلاف ذلك';
 
-    protected function checkPermissionRegistry(): void
+    public function handle(ProductionChecker $checker): int
     {
-        $this->comment('سجل الصلاحيات');
+        $json = (bool) $this->option('json');
 
-        if (File::exists(config_path('permission.php'))) {
-            $this->pass('config/permission.php (حزمة Spatie) موجود');
-        } else {
-            $this->failCheck('config/permission.php غير موجود - إعدادات Spatie الأساسية مفقودة');
+        if (! $json) {
+            $this->info('== فحص جاهزية الإنتاج (Read-Only) ==');
+            $this->newLine();
         }
 
-        if (File::exists(config_path('permissions.php'))) {
-            $this->pass('config/permissions.php (سجلّنا الخاص) موجود');
-        } else {
-            $this->failCheck('config/permissions.php غير موجود - permissions:sync لن يعمل');
-        }
-    }
-
-    protected function checkBuildManifest(): void
-    {
-        $this->comment('أصول الواجهة (Vite)');
+        $report = $checker->run();
+        $this->renderReport($report, $json);
 
-        if (File::exists(public_path('build/manifest.json'))) {
-            $this->pass('public/build/manifest.json موجود - الأصول مبنية');
-        } else {
-            $this->failCheck('public/build/manifest.json غير موجود - شغّل npm ci && npm run build قبل النشر');
+        if (! $json) {
+            $this->renderSummary($report);
         }
-    }
-
-    protected function checkMigrationStatus(): void
-    {
-        $this->comment('حالة الترحيلات (فحص فقط - بلا تنفيذ)');
 
-        try {
-            $ran = DB::table('migrations')->count();
-            $files = collect(File::files(database_path('migrations')))->count();
-
-            if ($ran >= $files) {
-                $this->pass("كل الترحيلات مُنفَّذة ({$ran}/{$files})");
-            } else {
-                $this->warn2("ترحيلات غير مُنفَّذة بعد ({$ran}/{$files}) - شغّل php artisan migrate --force بعد نسخ احتياطي");
-            }
-        } catch (Throwable $e) {
-            $this->failCheck('تعذَّر قراءة جدول migrations');
-        }
+        return $report->hasFailures() ? self::FAILURE : self::SUCCESS;
     }
-}
\ No newline at end of file
+}
```

### `app/Filament/Pages/OperationsCenter.php`

```diff
--- a/app/Filament/Pages/OperationsCenter.php
+++ b/app/Filament/Pages/OperationsCenter.php
@@ -6,6 +6,8 @@
 use App\Models\OperationalAuditLog;
 use App\Models\RedemptionRequest;
 use App\Models\User;
+use App\Services\Operations\OperationalSignals;
+use App\Support\SchedulerHeartbeat;
 use Filament\Pages\Page;
 
 /**
@@ -139,6 +141,31 @@
         });
     }
 
+    /**
+     * E25: إشارات تشغيلية للقراءة فقط (الإصدار، أسماء المشغّلات، نبض المجدول، الطابور، جاهزية القاعدة/الكاش) - بلا أسرار ولا مضيفين ولا بيانات اعتماد.
+     * تُعرض لمن يملك system.audit_view (الصلاحية النظامية الوحيدة القائمة، ويملكها المدير الأعلى تلقائيًا). اللقطة مخزّنة 30ث في OperationalSignals فلا تثقل الصفحة.
+     *
+     * @return array<string, mixed>|null
+     */
+    public function getSystemSignals(): ?array
+    {
+        return once(function () {
+            return auth()->user()?->can('system.audit_view')
+                ? app(OperationalSignals::class)->snapshot()
+                : null;
+        });
+    }
+
+    public function schedulerLabel(string $status): string
+    {
+        return match ($status) {
+            SchedulerHeartbeat::OK => 'سليم',
+            SchedulerHeartbeat::STALE => 'متأخر',
+            SchedulerHeartbeat::DOWN => 'متوقف',
+            default => 'غير معروف (لا نبضة بعد)',
+        };
+    }
+
     protected static array $actionLabels = [
         'user_frozen' => 'تجميد حساب',
         'user_unfrozen' => 'رفع تجميد حساب',
```

### `app/Http/Controllers/HealthController.php`

```diff
--- a/app/Http/Controllers/HealthController.php
+++ b/app/Http/Controllers/HealthController.php
@@ -2,6 +2,8 @@
 
 namespace App\Http\Controllers;
 
+use App\Support\ReleaseInfo;
+use App\Support\SchedulerHeartbeat;
 use Illuminate\Http\JsonResponse;
 use Illuminate\Http\Request;
 use Illuminate\Support\Facades\Cache;
@@ -12,6 +14,8 @@
  * تفحص قاعدة البيانات (SELECT 1) والكاش (كتابة/قراءة قصيرة) وتقيس زمن كلٍّ؛ تُعدّ «بطيئة=غير جاهزة» إن تجاوزت performance.observability.readiness_timeout_ms.
  * لا أسرار ولا أسماء مضيفين ولا إصدارات ولا نص استثناء: فقط ok/زمن بالميلي ثانية. 200 عند الجاهزية، 503 وإلا.
  * اختياريًا يُقيَّد برمز (PERF_READINESS_TOKEN) في الرأس X-Health-Token.
+ * E25: يضيف "release" (معرّف الإصدار المختصر، فقط إذا ضُبط APP_RELEASE/RELEASE_SHA بصيغة آمنة) - لا شيء آخر عن النظام.
+ * ويضيف "scheduler" إلى الفحوص فقط إذا فعّلت OPS_READY_FAILS_ON_SCHEDULER (افتراضيًا معطّل: تأخر المجدول لا يُسقط الجاهزية؛ يفشل فقط عند حالة down بعد عتبة down_after_seconds، لا عند unknown بعد نشر جديد).
  * ملاحظة: مهلة اتصال القاعدة الفعلية تُضبط في الاتصال (انظر docs/scaling-architecture.md)؛ هنا نُبلّغ فقط عن البطء.
  */
 class HealthController extends Controller
@@ -38,9 +42,22 @@
             }, $limit),
         ];
 
+        if (config('operations.scheduler.ready_fails_on_down', false)) {
+            $status = app(SchedulerHeartbeat::class)->status();
+            $checks['scheduler'] = ['ok' => $status !== SchedulerHeartbeat::DOWN, 'status' => $status];
+        }
+
         $ready = collect($checks)->every(fn (array $c) => $c['ok']);
 
-        return response()->json(['status' => $ready ? 'ok' : 'unavailable', 'checks' => $checks], $ready ? 200 : 503, $this->headers());
+        $payload = ['status' => $ready ? 'ok' : 'unavailable'];
+
+        if (($release = ReleaseInfo::short()) !== null) {
+            $payload['release'] = $release;
+        }
+
+        $payload['checks'] = $checks;
+
+        return response()->json($payload, $ready ? 200 : 503, $this->headers());
     }
 
     /** @return array{ok: bool, ms: float} */
```

### `app/Providers/AppServiceProvider.php`

```diff
--- a/app/Providers/AppServiceProvider.php
+++ b/app/Providers/AppServiceProvider.php
@@ -6,8 +6,11 @@
 use App\Services\AuthorizationSafetyService;
 use App\Services\Teams\TeamService;
 use Illuminate\Cache\RateLimiting\Limit;
+use App\Support\ReleaseInfo;
 use Illuminate\Http\Request;
+use Illuminate\Support\Facades\DB;
 use Illuminate\Support\Facades\Gate;
+use Illuminate\Support\Facades\Log;
 use Illuminate\Support\Facades\RateLimiter;
 use Illuminate\Support\ServiceProvider;
 
@@ -40,6 +43,22 @@
         });
 
         $this->registerRateLimiters();
+        $this->registerOperationalGuards();
+    }
+
+    /**
+     * E25: حواجز التشغيل بالإنتاج.
+     *  - الأوامر الهدّامة (db:wipe, migrate:fresh|refresh|reset|rollback) ممنوعة بالإنتاج: الترحيلات بالإنتاج تتقدّم للأمام فقط (forward-fix) والتراجع عن القاعدة غير آمن بعد كتابات الإصدار الجديد.
+     *    استثناء واعٍ ومؤقت بمتغير بيئة لتلك العملية وحدها: OPS_ALLOW_DESTRUCTIVE_COMMANDS=true (انظر docs/release-engineering.md). لا يُضاف إلى .env الدائم.
+     *  - معرّف الإصدار (إن ضُبط) يُضاف لسياق كل سطر سجل (release) بجانب request_id من E24، لربط أي خطأ بالإصدار الذي أنتجه. لا أسرار فيه.
+     */
+    protected function registerOperationalGuards(): void
+    {
+        DB::prohibitDestructiveCommands($this->app->isProduction() && ! config('operations.allow_destructive_commands', false));
+
+        if (($release = ReleaseInfo::short()) !== null) {
+            Log::shareContext(['release' => $release]);
+        }
     }
 
     /**
```

### `config/reverb.php`

```diff
--- a/config/reverb.php
+++ b/config/reverb.php
@@ -87,7 +87,8 @@
                     'scheme' => env('REVERB_SCHEME', 'https'),
                     'useTLS' => env('REVERB_SCHEME', 'https') === 'https',
                 ],
-                'allowed_origins' => ['*'],
+                // E25: الأصول المسموحة لاتصالات WebSocket. الافتراضي ['*'] (سلوك E21 دون تغيير). بالإنتاج: REVERB_ALLOWED_ORIGINS=ahjiyat.example.com,www.ahjiyat.example.com (أسماء مضيفين مفصولة بفواصل، بلا http://).
+                'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('REVERB_ALLOWED_ORIGINS', '*'))))) ?: ['*'],
                 'ping_interval' => env('REVERB_APP_PING_INTERVAL', 60),
                 'activity_timeout' => env('REVERB_APP_ACTIVITY_TIMEOUT', 30),
                 'max_connections' => env('REVERB_APP_MAX_CONNECTIONS'),
```

### `database/seeders/AdminUserSeeder.php`

```diff
--- a/database/seeders/AdminUserSeeder.php
+++ b/database/seeders/AdminUserSeeder.php
@@ -7,12 +7,22 @@
 use App\Services\Economy\CurrencyRegistry;
 use Illuminate\Database\Seeder;
 use Illuminate\Support\Facades\Hash;
+use RuntimeException;
 
 class AdminUserSeeder extends Seeder
 {
+    public const DEFAULT_EMAIL = 'admin@ahjiyat.app';
+
+    /** كلمة المرور الافتراضية للتطوير المحلي فقط - ممنوعة بالإنتاج (انظر bootstrap بالأسفل). */
+    public const DEFAULT_PASSWORD = 'change-me-now';
+
     public function run(): void
     {
-        $admin = User::where('email', 'admin@ahjiyat.app')->first();
+        // E25: بالإنتاج لا كلمة مرور افتراضية معروفة. يلزم ADMIN_BOOTSTRAP_EMAIL وADMIN_BOOTSTRAP_PASSWORD (≥ 12 خانة) من البيئة؛ تُحذفان من البيئة بعد أول تشغيل.
+        // خارج الإنتاج يبقى السلوك القديم كما هو (admin@ahjiyat.app / change-me-now).
+        [$email, $password] = $this->credentials();
+
+        $admin = User::where('email', $email)->first();
 
         if (! $admin) {
             $admin = new User();
@@ -22,8 +32,8 @@
             // يعني ما يقدر يفتح لوحة إدارة Filament إطلاقاً رغم إنه "أدمن" بالاسم.
             $admin->forceFill([
                 'name' => 'مدير المنصة',
-                'email' => 'admin@ahjiyat.app',
-                'password' => Hash::make('change-me-now'),
+                'email' => $email,
+                'password' => Hash::make($password),
                 'role' => 'admin',
                 'email_verified_at' => now(),
             ]);
@@ -37,6 +47,31 @@
             'currency_id' => app(CurrencyRegistry::class)->defaultEarnedCurrency()->id,
         ]);
 
-        $this->command->warn('تم إنشاء حساب المدير: admin@ahjiyat.app / change-me-now - غيّر كلمة المرور فوراً بعد أول دخول.');
+        if (app()->environment('production')) {
+            // لا نطبع كلمة المرور أبدًا.
+            $this->command?->warn("حساب المدير جاهز ({$email}). احذف ADMIN_BOOTSTRAP_EMAIL وADMIN_BOOTSTRAP_PASSWORD من البيئة الآن، ثم rbac:migrate-legacy-roles إن لم يوجد مدير أعلى.");
+
+            return;
+        }
+
+        $this->command?->warn('تم إنشاء حساب المدير: admin@ahjiyat.app / change-me-now - غيّر كلمة المرور فوراً بعد أول دخول.');
+    }
+
+    /** @return array{0: string, 1: string} */
+    private function credentials(): array
+    {
+        if (! app()->environment('production')) {
+            return [self::DEFAULT_EMAIL, self::DEFAULT_PASSWORD];
+        }
+
+        $email = (string) config('operations.admin_bootstrap.email');
+        $password = (string) config('operations.admin_bootstrap.password');
+        $min = (int) config('operations.admin_bootstrap.min_password_length', 12);
+
+        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($password) < $min || $password === self::DEFAULT_PASSWORD) {
+            throw new RuntimeException("AdminUserSeeder بالإنتاج يتطلب ADMIN_BOOTSTRAP_EMAIL (بريد صالح) وADMIN_BOOTSTRAP_PASSWORD (≥ {$min} خانة وليست الافتراضية) من البيئة. لا كلمة مرور افتراضية بالإنتاج.");
+        }
+
+        return [$email, $password];
     }
 }
\ No newline at end of file
```

### `database/seeders/DatabaseSeeder.php`

```diff
--- a/database/seeders/DatabaseSeeder.php
+++ b/database/seeders/DatabaseSeeder.php
@@ -2,12 +2,18 @@
 
 namespace Database\Seeders;
 
+use Database\Seeders\Concerns\RefusesProduction;
 use Illuminate\Database\Seeder;
 
 class DatabaseSeeder extends Seeder
 {
+    use RefusesProduction;
+
     public function run(): void
     {
+        // E25: الحزمة الكاملة تشمل حسابات تجريبية بكلمة مرور معروفة؛ ممنوعة بالإنتاج.
+        $this->refuseInProduction('php artisan db:seed (الحزمة الكاملة)');
+
         $this->call([
             AdminUserSeeder::class,
             RolesAndPermissionsSeeder::class,
```

### `database/seeders/UserSeeder.php`

```diff
--- a/database/seeders/UserSeeder.php
+++ b/database/seeders/UserSeeder.php
@@ -4,11 +4,14 @@
 
 use App\Models\User;
 use App\Models\Wallet;
+use Database\Seeders\Concerns\RefusesProduction;
 use Illuminate\Database\Seeder;
 use Illuminate\Support\Facades\Hash;
 
 class UserSeeder extends Seeder
 {
+    use RefusesProduction;
+
     /**
      * كلمة مرور كل المستخدمين التجريبيين (بدون استثناء) عشان تسهيل تسجيل الدخول أثناء الاختبار.
      */
@@ -16,6 +19,9 @@
 
     public function run(): void
     {
+        // E25: مستخدمون تجريبيون بكلمة مرور معروفة (password) - ممنوع بالإنتاج.
+        $this->refuseInProduction('UserSeeder');
+
         $users = [
 
             // ===== حسابات "بطلة" - مؤهلة بالكامل للاستبدال، مفيدة لاختبار كل الواجهات =====
```

### `docs/backup-restore.md`

```diff
--- a/docs/backup-restore.md
+++ b/docs/backup-restore.md
@@ -1,6 +1,9 @@
 
 # النسخ الاحتياطي والاستعادة
 
+> **تحديث E25.** استراتيجية النسخ الاحتياطي الكاملة (التكرار، الاحتفاظ، خارج الخادم، التشفير، RPO/RTO، حارس الاستعادة، تمرين الاستعادة، سيناريوهات الكوارث) في [backup-disaster-recovery](backup-disaster-recovery.md) وهي المرجع الحالي. الأدوات: `tools/backup/*.sh` (محايدة المزوّد، ترفض الوجهات الخطرة، ولا تشفّر ولا ترفع شيئًا ما لم تضبط ذلك صراحة). هذا الملف يبقى مرجعًا مبسطًا.
+
+
 ## القرار المعماري
 
 لا حزمة Backup إضافية بهذه المرحلة - `mysqldump` الأصلي + نسخ ملفات كافٍ تمامًا لحجم هذا المشروع حاليًا، وأبسط اعتمادًا.
```

### `docs/pre-launch-checklist.md`

```diff
--- a/docs/pre-launch-checklist.md
+++ b/docs/pre-launch-checklist.md
@@ -1,6 +1,9 @@
 
 # قائمة ما قبل الإطلاق
 
+> **تحديث E25.** القائمة المعتمدة للإطلاق الآن: [production-checklist](production-checklist.md) (تشمل DNS/TLS، البيئة، القاعدة، Redis، الطابور والمجدول وReverb، النسخ والاستعادة، المراقبة، التراجع والحوادث). هذه القائمة تبقى مرجعًا لمحتوى الصفحات والمنتج؛ وعند التعارض تتقدّم قائمة E25.
+
+
 ## DNS / SSL
 
 - [ ] النطاق يشير للخادم الصحيح.
```

### `docs/production-deployment.md`

```diff
--- a/docs/production-deployment.md
+++ b/docs/production-deployment.md
@@ -1,6 +1,15 @@
 
 # دليل النشر للإنتاج
 
+> **تحديث E25 — اقرأ هذا أولًا.** هذا الملف سجل تاريخي لمراحل المشروع ويبقى مرجعًا للتفاصيل، لكن **المرجع التشغيلي الحالي** هو: [release-engineering](release-engineering.md) (النشر والترحيلات والتراجع)، [first-production-deploy](first-production-deploy.md) (أول نشر)، [environment-variables](environment-variables.md) (عقد البيئة)، [operations-runbook](operations-runbook.md) (الطابور والمجدول وReverb وRedis والصيانة). ما تغيّر:
+> - **لا `git pull` على الخادم**: انشر artifact/مجلد إصدار بمعرّف ثم بدّل رابط `current`؛ التسلسل الآلي في `tools/deploy/deploy.sh` (يدعم `--dry-run`).
+> - **`CACHE_STORE=file` لخادم واحد فقط.** المجدول (`withoutOverlapping`/`onOneServer`) ونبضة المجدول وعدّادات الأداء تحتاج كاشًا يدعم الأقفال ومشتركًا بين الخوادم (`database` أو `redis`). `queue:restart` و`reverb:restart` يتطلبان كاشًا غير `array`.
+> - **عامل الطابور إلزامي بالإنتاج**، ويعمل تحت مشرف (Supervisor/systemd) بـ`--timeout=80` (أقل من `retry_after`=90).
+> - المجدول يضم الآن مهام كثيرة + **نبضة** (heartbeat) تُظهر حالته في مركز العمليات و`/ready` اختياريًا.
+> - الأوامر الهدّامة (`db:wipe`, `migrate:fresh|refresh|reset|rollback`) **ممنوعة آليًا بالإنتاج**، و`db:seed` الكامل محظور بالإنتاج (يزرع حسابات تجريبية بكلمات معروفة).
+> - قبل كل نشر: `php artisan release:preflight`، وبعده: `php artisan production:check` و`php artisan release:smoke --expect-release=<id>`.
+
+
 توثيق محايد الاستضافة - يعمل على أي VPS Linux قياسي. ملحق Ubuntu+Nginx بالنهاية كمثال فقط.
 
 ## متطلبات الخادم (من composer.json/package.json الفعليين)
```

### `docs/rollback.md`

```diff
--- a/docs/rollback.md
+++ b/docs/rollback.md
@@ -1,6 +1,12 @@
 
 # التراجع عن نشرة (Rollback)
 
+> **تحديث E25 — تصحيحات مهمة.** المرجع الحالي: [release-engineering](release-engineering.md#5-التراجع-rollback) (مصفوفة التراجع) و[backup-disaster-recovery](backup-disaster-recovery.md). ما تغيّر عمّا يلي:
+> - **`migrate:rollback` (وأخواته) محظورة آليًا بالإنتاج** (`DB::prohibitDestructiveCommands`)؛ التقدّم للأمام فقط (**forward-fix**) أو الاستعادة من نسخة. الاستثناء الواعي المؤقت `OPS_ALLOW_DESTRUCTIVE_COMMANDS=true` لعملية واحدة وبعد نسخة جديدة موثّقة، ولا يُضاف إلى `.env` الدائم. فقرة «`migrate:rollback --step=1`» أدناه **لا تنطبق على الإنتاج**.
+> - تراجع الكود بإعادة نشر **الإصدار السابق المحفوظ** (مجلد/artifact بمعرّفه) ثم `optimize` + `queue:restart` + `reverb:restart` + إعادة تحميل PHP-FPM، لا بـ`git checkout` على الخادم الحي.
+> - بعد التراجع: `release:smoke --expect-release=<المعرّف السابق>`.
+
+
 ## تحذير جوهري
 
 `php artisan migrate:rollback` **ليست الحل التلقائي** بعد كل فشل. Migration قد تكون Data Migration فعلية - التراجع عنها قد يكون **غير قابل للعكس**. التراجع الآمن الوحيد المضمون: **استعادة من نسخة احتياطية موثوقة سابقة للنشرة**.
```

### `docs/security-hardening.md`

```diff
--- a/docs/security-hardening.md
+++ b/docs/security-hardening.md
@@ -1,5 +1,7 @@
 
-# التحصين الأمني (E8)
+# التحصين الأمني (E8 + E25)
+
+> الأقسام حتى «قاعدة البيانات» من E8 (الأساس). **ما بعدها (E25)** يضيف: تدقيق الإنتاج، الأسرار وتدويرها، تحذير APP_KEY، وسياسة السجلات الحساسة. الفحص الآلي: `php artisan production:check` (قراءة فقط) — [متغيرات البيئة](environment-variables.md).
 
 ## Headers (E8-B)
 
@@ -39,3 +41,135 @@
 ## قاعدة البيانات
 
 Production: مستخدم DB مخصَّص بصلاحيات التطبيق فقط (لا root)، `utf8mb4` لـMySQL، منفذ DB لا يُفتَح للعامة إلا خلف Firewall صريح.
+
+---
+
+# E25: تحصين الإنتاج
+
+## ما يفحصه `production:check` أمنيًا
+
+| الفحص | النتيجة عند المخالفة |
+|---|---|
+| `APP_DEBUG=true` | **FAIL حاجب دائمًا** (في أي بيئة) |
+| `APP_KEY` مفقود أو غير صالح للتشفير | **FAIL حاجب** |
+| `SESSION_SECURE_COOKIE` ليست `true` | FAIL بالإنتاج / INFO محليًا |
+| `SESSION_HTTP_ONLY` ليست `true` | FAIL بالإنتاج / WARN محليًا |
+| `SESSION_SAME_SITE` ليست `lax`/`strict` | WARN |
+| `APP_URL` ليس `https://` أو مضيف محلي | FAIL بالإنتاج / WARN محليًا |
+| `TRUSTED_PROXIES=*` أو غير مضبوط خلف HTTPS | WARN |
+| `public/.env` أو `public/.git` | **FAIL حاجب** (سيُخدَّم للعامة) |
+| `public/hot` (خادم Vite التطويري) | FAIL بالإنتاج |
+| صلاحيات `.env` قابلة للقراءة من الجميع (Linux) | WARN |
+| مسارات تشخيص عامة (`_ignition`, `telescope`, `horizon`, `debug`, `phpinfo`…) | FAIL بالإنتاج |
+| أدوات تطوير مثبّتة (Telescope/Debugbar/Ignition/Pest) | WARN (استعمل `composer install --no-dev`) |
+| حسابات تجريبية `@ahjiyat.test` أو مدير بكلمة مرور افتراضية | FAIL بالإنتاج |
+| `REVERB_APP_SECRET` ضعيف/افتراضي أو أصول WebSocket `*` | FAIL / WARN بالإنتاج |
+
+المخرجات تحوي **اسم** الإعداد وحالته فقط، ولا تطبع قيمة أي سر (يمرّ كل نص عبر `SecretRedactor`).
+
+## HTTPS و HSTS
+
+- `APP_URL=https://…` إلزامي بالإنتاج. التحويل من HTTP إلى HTTPS **على مستوى الوكيل/الموازن** (Nginx/Cloudflare)، لا في التطبيق.
+- يرسل التطبيق `Strict-Transport-Security: max-age=31536000` فقط حين `APP_ENV=production` **و** الطلب آمن فعلًا (`isSecure()` — يعتمد على الوكلاء الموثوقين أدناه). **بلا** `includeSubDomains` **وبلا** `preload` عن قصد: كلاهما قرار شبه غير قابل للتراجع (preload يستمر أشهرًا في المتصفحات) ولا يصح إلا بعد التأكد أن **كل** النطاقات الفرعية تخدم HTTPS دائمًا. إن أردتهما فأضفهما عند الوكيل بوعي، لا في التطبيق.
+- خلف موازن ينهي TLS: بدون `TRUSTED_PROXIES` صحيحة يرى التطبيق الطلب HTTP فلا يُرسَل HSTS وتخرج الروابط `http://`. هذا أول شيء يُفحَص إن غاب HSTS بالإنتاج.
+
+## الكوكيز
+
+| الإعداد | الإنتاج | السبب |
+|---|---|---|
+| `SESSION_SECURE_COOKIE` | `true` | لا تُرسَل الكوكي عبر HTTP |
+| `SESSION_HTTP_ONLY` | `true` (الافتراضي) | تمنع قراءتها من JS (XSS) |
+| `SESSION_SAME_SITE` | `lax` (الافتراضي) | حماية CSRF مع سلاسة الروابط الواردة |
+| `SESSION_DOMAIN` | فارغ | كوكي مرتبطة بالمضيف فقط (لا تُرسَل لنطاقات فرعية أخرى) |
+| `SESSION_ENCRYPT` | اختياري | الجلسات بقاعدة/Redis خاص؛ التشفير طبقة إضافية بتكلفة صغيرة |
+
+كوكيز Laravel مشفّرة أصلًا بـ`APP_KEY` (EncryptCookies) — لذلك تدوير المفتاح يؤثر عليها ([التحذير](#app_key-تحذير-خاص)).
+
+## تدقيق الوكلاء الموثوقين (Trusted Proxies)
+
+`config/trustedproxy.php` ← `TRUSTED_PROXIES`:
+
+- **فارغ** (الافتراضي): لا وكلاء موثوقون؛ صحيح فقط إن استقبل الخادم المتصفح مباشرة.
+- **قائمة CIDR** (موصى به خلف موازن): مثل `10.0.0.0/8,172.16.0.0/12`، أو نطاقات Cloudflare المنشورة.
+- **`*`**: يثق بكل وكيل مباشر ⟵ آمن فقط إن كان التطبيق **غير قابل للوصول إلا عبر الموازن** (جدار ناري/مجموعة أمان). وإلا فأي عميل يستطيع انتحال `X-Forwarded-For` فيتجاوز معدّلات IP ويزوّر تتبع الجهاز. `production:check` يحذّر منه.
+- بعد التغيير: `optimize` ثم تحقق من `release:smoke` ومن أن `/` ترجع روابط `https://`.
+
+## تدقيق CORS
+
+لا يوجد `config/cors.php` عن قصد: لا مسار مسجَّل للـCORS، فلا يصدر التطبيق أي `Access-Control-Allow-Origin` — الواجهة وAPI من الأصل نفسه. تطبيقات الموبايل الأصلية لا تخضع لـCORS. **لا تضف** `config/cors.php` بـ`allowed_origins: ['*']` مع `supports_credentials`؛ إن لزم أصل خارجي مستقبلًا فأضف الأصول المحددة وحدها. اتصالات WebSocket لها فحص أصل مستقل: `REVERB_ALLOWED_ORIGINS` (أسماء مضيفين مفصولة بفواصل، بلا `http://`).
+
+## تدقيق رؤوس الأمان
+
+مطبّقة (E8) على `web` و`api` وعلى لوحة Filament: `X-Content-Type-Options`, `Referrer-Policy`, `X-Frame-Options: SAMEORIGIN`, `Permissions-Policy`, وHSTS بشرطه. CSP **مؤجّلة بقرار واعٍ** (أعلاه). تحقق بعد النشر: `curl -sI https://<النطاق>/`.
+
+## مسارات الإدارة والتشخيص
+
+- `/admin` (Filament): دخول بحساب مخوَّل، صلاحيات دقيقة (RBAC)، رؤوس الأمان مطبّقة، ومعدّل محاولات الدخول مقيّد.
+- لا Telescope/Horizon/Debugbar/Ignition بالإنتاج: `production:check` يفشل على أي مسار يطابق أنماطها، ويحذّر إن كانت الحزم مثبّتة.
+- `php artisan serve` و`npm run dev` ليسا للإنتاج؛ `public/hot` يُكتشف ويُرفض.
+
+## خصوصية نقطة الصحة
+
+- `/up` (إطار العمل): 200 بلا تفاصيل.
+- `/ready`: JSON صغير (`status`, `release`, `checks`) فقط؛ **لا** أسماء مضيفين ولا إصدارات PHP/Laravel ولا مفتاح التطبيق ولا رسائل استثناءات (تفاصيل الأخطاء تذهب للسجل). `release` معرّف إصدار اختياري لا يُعدّ سرًا لكنه يكشف التوقيت؛ قيّد `/ready` للمراقب بالموازن أو بـ`PERF_READINESS_TOKEN` (رأس `X-Health-Token`) إن رغبت.
+- مركز العمليات في الإدارة لمن يملك `system.audit_view` فقط، ويعرض **أسماء** المشغّلات (driver names) وحالات، لا قيمًا سرية.
+
+## إدارة الأسرار
+
+1. **لا أسرار في Git/الوثائق/CI.** `.env.example` يحوي placeholders فقط (يتحقق منها اختبار آلي)، و`.env` مستبعَد في `.gitignore`.
+2. المصدر الموصى به: مدير أسرار المزوّد أو متغيرات بيئة محميّة؛ وإلا ملف `.env` بصلاحيات `640` ومالك مستخدم التطبيق، **خارج** `public/`.
+3. أسرار العمليات (`ADMIN_BOOTSTRAP_*`, `MAINTENANCE_SECRET`, `RESTORE_*`) تُمرَّر لعملية واحدة ولا تُحفظ في ملف ولا في سجل الأوامر (`read -s` ثم `unset`).
+4. **فحص التسرّب قبل التسليم**: ابحث في الفروقات (لا في `.env` الحقيقي) عن أنماط: `-----BEGIN .* PRIVATE KEY-----`, `AKIA[0-9A-Z]{16}`, `base64:` بطول 44 خانة, `xox[baprs]-`, `ghp_[A-Za-z0-9]{36}`, `sk_live_`, ومفاتيح تعيينات `PASSWORD=`/`SECRET=`/`TOKEN=` ذات قيم غير فارغة وغير placeholder. نتيجة فحص E25 مدوَّنة بتقرير التسليم. اختبار `SecretHygieneTest` يكرّر الفحص الأساسي آليًا.
+5. إن ظهر سر بأي مكان عام: **افترضه مخترقًا**؛ دوّره أولًا ثم نظّف. حذف الملف أو إعادة كتابة التاريخ لا يُلغي التسرّب.
+
+## تدوير الأسرار (P4)
+
+**قاعدة عامة لكل سر**: (1) أنشئ القيمة الجديدة عند المصدر (القاعدة/المزوّد) **دون إبطال القديمة** إن أمكن، (2) حدّث مخزن الأسرار، (3) `php artisan optimize`، (4) `php artisan queue:restart` و`php artisan reverb:restart` (عمليات طويلة العمر تحتفظ بالإعداد القديم في الذاكرة)، وأعد تحميل PHP-FPM، (5) تحقق: `/ready` 200 + `release:smoke` + إرسال بريد/بثّ تجريبي حسب السر، (6) **أبطل القديمة** بعد ثبات النظام، (7) دوّن تاريخ التدوير ومن أجراه (بلا قيمة).
+
+| السر | الخطوات الخاصة | أثر متوقع |
+|---|---|---|
+| **كلمة مرور القاعدة** (`DB_PASSWORD`) | الأسلم: أنشئ مستخدمًا ثانيًا بنفس الصلاحيات وكلمة جديدة ⟵ انشر ⟵ احذف القديم. بديل بخطوة واحدة: `ALTER USER … IDENTIFIED BY` ثم تحديث البيئة فورًا (توقف قصير ممكن) — نفّذه في نافذة صيانة | اتصالات قائمة قد تفشل لحظيًا؛ العمال تعيد الاتصال بعد `queue:restart` |
+| **Redis** (`REDIS_PASSWORD`) | استعمل ACL بمستخدمين متوازيين إن أمكن، وإلا `CONFIG SET requirepass` ثم حدّث البيئة فورًا؛ أعد تشغيل العمال/Reverb/PHP-FPM | الاتصالات الجديدة فقط تتحقق بالكلمة الجديدة؛ العمليات الطويلة تحتاج إعادة تشغيل |
+| **Reverb** (`REVERB_APP_SECRET`) | غيّر القيمة بالتطبيق وخادم Reverb معًا (نفس البيئة) ثم `optimize` + `queue:restart` + `reverb:restart` | انقطاع اللحظي لدقائق؛ الحفظ يعمل. تغيير `REVERB_APP_KEY` يتطلب `npm run build` لأنه يُدمَج بالواجهة (`VITE_REVERB_APP_KEY`) |
+| **البريد** (`MAIL_PASSWORD`/توكن المزوّد) | أنشئ بيانات اعتماد جديدة عند المزوّد ⟵ حدّث ⟵ `optimize` + `queue:restart` ⟵ أرسل بريد توثيق تجريبي ⟵ ألغِ القديمة | البريد المصفوف في الطابور يستعمل ما حُمّل بذاكرة العامل: لذا أعد تشغيله |
+| **`PERF_READINESS_TOKEN`** | قيمة عشوائية جديدة ⟵ حدّث البيئة وإعداد الموازن/المراقب معًا | المراقب يفشل لحظيًا إن تأخر التحديث |
+| **`MAINTENANCE_SECRET`** | يُولَّد لكل نافذة صيانة ولا يُحفظ | لا أثر |
+| **كلمة مرور المدير** | من لوحة الإدارة ثم إنهاء جلساته القديمة | — |
+| **مفاتيح النسخ الاحتياطي** | أضف مستلمًا (recipient) جديدًا للنسخ القادمة؛ **احتفظ بمفتاح الاستعادة القديم** ما دامت نسخ قديمة مشفّرة به ضمن الاحتفاظ ([التفاصيل](backup-disaster-recovery.md#6-التشفير)) | لا أثر على التشغيل |
+| **`APP_KEY`** | **لا تتبع القاعدة العامة** ⟵ [القسم التالي](#app_key-تحذير-خاص) | تسجيل خروج/إبطال روابط إن أُهمل `APP_PREVIOUS_KEYS` |
+
+بعد أي تدوير بسبب تسرّب: راجع السجلات عن استعمال السر المسرّب في الفترة بين التسرّب والتدوير، وقيّم الأثر ([الحوادث](incident-response.md)).
+
+### APP_KEY: تحذير خاص
+
+`APP_KEY` ليس كلمة مرور عادية. يُستعمل لـ:
+
+- تشفير الكوكيز (بينها كوكي الجلسة وكوكي «تذكّرني»)؛
+- `Crypt` — يستعمله `TimedAttemptToken` (رمز بدء الأحجية المؤقتة، العمر قصير)؛
+- توقيع الروابط (`signed`) — بينها رابط توثيق البريد؛
+- كل ما يُشفَّر بالتطبيق مستقبلًا (مثل `encrypted` casts أو `Crypt::encrypt`).
+
+**وقت إعداد E25** لا يخزّن التطبيق أعمدة مشفّرة بالقاعدة (فحص: لا `encrypted` casts ولا `Crypt` سوى `TimedAttemptToken`)، لذا فقدان المفتاح اليوم **لا يُتلف بيانات** لكنه يُسجّل خروج الجميع ويُبطل الروابط المرسلة. **أعد التحقق قبل الاعتماد على هذا**: `grep -rn "encrypted\|Crypt::" app database` — لأن إضافة عمود مشفّر لاحقًا تجعل فقدان المفتاح فقدانًا فعليًا للبيانات.
+
+**لذلك:**
+
+1. **خزّن `APP_KEY` فورًا بنسختين** في خزنة أسرار (مسؤولان) ([ضمان المفتاح](backup-disaster-recovery.md#2-الأسرار-وapp_key-ضمان-مفتاح-آمن-key-escrow)). الاستعادة على خادم جديد تحتاج المفتاح **الأصلي**.
+2. **لا تدوّره عفويًا ولا ضمن حادثة** ما لم يكن هو المسرَّب فعلًا. المفتاح المسرَّب يعني أن من يملكه قادر على تزوير كوكيز/روابط موقّعة — فدوّره فورًا (وتقبّل الأثر).
+3. **التدوير الآمن (بلا تسجيل خروج جماعي)**:
+   1. ولّد مفتاحًا جديدًا على جهاز آمن: `php artisan key:generate --show` (يعرضه فقط؛ **لا** `key:generate` بلا `--show` على الخادم: يكتب في `.env`، و`--force` خطر على نظام حي).
+   2. بالمخزن: `APP_PREVIOUS_KEYS=<المفتاح القديم>` (يقبل أكثر من واحد مفصولة بفواصل) و`APP_KEY=<الجديد>`.
+   3. `php artisan optimize` ثم `queue:restart` و`reverb:restart` وإعادة تحميل PHP-FPM.
+   4. تحقق: جلسة قديمة ما زالت تعمل، `/ready` 200، `release:smoke` ناجح، رابط توثيق بريد صادر قبل التدوير يفتح.
+   5. المفاتيح السابقة تفك التشفير والتوقيع (كوكيز، `Crypt`، الروابط الموقّعة)، والجديد يُشفّر ويوقّع من الآن. احتفظ بـ`APP_PREVIOUS_KEYS` ما دام يُحتمل استعمال كوكيز/روابط/رموز قديمة (عمر الجلسة `SESSION_LIFETIME`، وكوكي «تذكّرني» أطول)، ثم احذفه و`optimize` ثانية.
+4. **بدون** `APP_PREVIOUS_KEYS`: يُسجَّل خروج كل المستخدمين، وتفشل الروابط الموقّعة الصادرة سابقًا (يعيد المستخدم طلب رابط توثيق جديد)، وتفشل رموز الأحجيات الجارية (معاملتها fail-safe: تُحتسب «وقت غير معروف» فيرفضها المحرك لا أنها تمنح نجاحًا)، ومكوّنات Livewire المفتوحة تحتاج تحديث الصفحة.
+5. دوّن من يحمل النسخ، وتاريخ آخر تدوير، وسبب التدوير.
+
+## السجلات والبيانات الحساسة
+
+**ممنوع تسجيله أبدًا**: كلمات المرور، رموز الجلسات/الاستعادة/التوثيق، `Authorization`/`Cookie`/`Set-Cookie`، `APP_KEY` وأي سر بيئة، أرقام البطاقات، نص رسائل الدردشة الخاصة، أجسام الطلبات الكاملة، قيم `.env`.
+
+- معرّفات الربط المسموحة: `request_id` (رأس `X-Request-ID` — E24) و`release` (E25، معرّف إصدار بلا أسرار). يضافان تلقائيًا لسياق كل سطر سجل.
+- سجل الطلبات البطيئة (`slow_request`) يحوي المسار المسمّى والمدة والذاكرة — لا استعلامات ولا ترويسات ولا جسم.
+- رسائل أخطاء الأوامر التشغيلية (`production:check` …) تمرّ عبر `SecretRedactor` فتُستبدل أي قيمة سرية معروفة بـ`[redacted]`.
+- **الاحتفاظ**: `LOG_STACK=daily` و`LOG_DAILY_DAYS` (الافتراضي 14) — اضبطه حسب سياسة الاحتفاظ والمساحة؛ أرسل نسخة إلى منصة مركزية (ELK/Loki/خدمة مُدارة) عند التوسع (P2). السجلات تُعامَل كبيانات مستخدمين: وصول مقيّد.
+- تدقيق E25 الساكن: لا استدعاء `Log::` / `logger()` بالمستودع يمرّر مفاتيح (`password`, `token`, `authorization`, `cookie`, `secret`, `api_key`) في سياقه (اختبار `SensitiveLoggingTest`).
```

### `docs/smoke-tests.md`

```diff
--- a/docs/smoke-tests.md
+++ b/docs/smoke-tests.md
@@ -1,6 +1,9 @@
 
 # اختبارات الدخان اليدوية (بعد كل نشرة - 15-20 دقيقة)
 
+> **تحديث E25.** يوجد الآن فاحص دخان آلي للقراءة فقط: `php artisan release:smoke --url=https://<النطاق> --expect-release=<id>` (طلبات GET فقط، بمهلات، PASS/FAIL مع زمن الاستجابة) — شغّله **قبل** هذه القائمة اليدوية؛ القائمة اليدوية تبقى لما يتطلب حسابًا: الدخول، المحفظة، الاستبدال، الإدارة. تفاصيل: [tools/smoke/README.md](../tools/smoke/README.md).
+
+
 استخدم حساب اختبار مخوَّل - لا كلمة سر حساب إنتاج حقيقي بهذا الملف إطلاقًا.
 
 ## عام (بلا تسجيل دخول)
```

### `resources/views/filament/pages/operations-center.blade.php`

```diff
--- a/resources/views/filament/pages/operations-center.blade.php
+++ b/resources/views/filament/pages/operations-center.blade.php
@@ -53,6 +53,50 @@
             </x-filament::section>
         @endif
 
+        {{-- E25: صحة النظام (قراءة فقط) --}}
+        @if (($signals = $this->getSystemSignals()) !== null)
+            <x-filament::section>
+                <x-slot name="heading">صحة النظام (قراءة فقط)</x-slot>
+                <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:1rem; margin-bottom:1rem;">
+                    <x-filament::section>
+                        <div style="font-size:1.4rem; font-weight:900;" dir="ltr">{{ $signals['release'] ?? 'غير مضبوط' }}</div>
+                        <div style="color:#94a3b8;">الإصدار (APP_RELEASE) · البيئة: <span dir="ltr">{{ $signals['environment'] }}</span></div>
+                        @if ($signals['debug'])
+                            <div style="color:#fb7185; font-weight:800;">APP_DEBUG مفعَّلة!</div>
+                        @endif
+                        @if ($signals['maintenance'])
+                            <div style="color:#f59e0b; font-weight:800;">وضع الصيانة مفعَّل</div>
+                        @endif
+                    </x-filament::section>
+                    <x-filament::section>
+                        <div style="font-size:1.4rem; font-weight:900; color:{{ $signals['scheduler']['status'] === 'ok' ? '#34d399' : ($signals['scheduler']['status'] === 'unknown' ? '#94a3b8' : '#f59e0b') }};">{{ $this->schedulerLabel($signals['scheduler']['status']) }}</div>
+                        <div style="color:#94a3b8;">المجدول @if ($signals['scheduler']['age_seconds'] !== null)· آخر نبضة منذ {{ $signals['scheduler']['age_seconds'] }} ث @endif</div>
+                    </x-filament::section>
+                    <x-filament::section>
+                        <div style="font-size:1.4rem; font-weight:900;">{{ $signals['queue']['pending'] ?? '—' }} <span style="font-size:.9rem; color:#94a3b8;">منتظرة</span></div>
+                        <div style="color:#94a3b8;">الطابور (<span dir="ltr">{{ $signals['queue']['driver'] }}</span>)
+                            @if ($signals['queue']['oldest_age_seconds'] !== null)· أقدمها منذ {{ intdiv($signals['queue']['oldest_age_seconds'], 60) }} د @endif
+                            · فاشلة: {{ $signals['queue']['failed'] ?? '—' }}</div>
+                    </x-filament::section>
+                    <x-filament::section>
+                        <div style="font-size:1rem; font-weight:800;" dir="ltr">DB {{ $signals['readiness']['database']['ok'] ? 'OK' : 'DOWN' }} · {{ $signals['readiness']['database']['ms'] }}ms</div>
+                        <div style="font-size:1rem; font-weight:800;" dir="ltr">Cache {{ $signals['readiness']['cache']['ok'] ? 'OK' : 'DOWN' }} · {{ $signals['readiness']['cache']['ms'] }}ms</div>
+                        <div style="color:#94a3b8;">الجاهزية (قراءة فقط)</div>
+                    </x-filament::section>
+                </div>
+
+                <div style="color:#94a3b8; font-size:.85rem; margin-bottom:.5rem;" dir="ltr">
+                    cache={{ $signals['drivers']['cache'] }} · session={{ $signals['drivers']['session'] }} · queue={{ $signals['drivers']['queue'] }} · broadcast={{ $signals['drivers']['broadcast'] }} · mail={{ $signals['drivers']['mail'] }}
+                </div>
+
+                @foreach ($signals['queue_warnings'] as $warning)
+                    <div style="color:#f59e0b; font-size:.85rem;">⚠ {{ $warning }}</div>
+                @endforeach
+
+                <div style="color:#64748b; font-size:.75rem; margin-top:.5rem;">لقطة مخزَّنة 30 ثانية · {{ \Illuminate\Support\Carbon::parse($signals['generated_at'])->diffForHumans() }}</div>
+            </x-filament::section>
+        @endif
+
         {{-- أمان الحسابات --}}
         @if ($this->getFrozenUsersCount() !== null)
             <x-filament::section>
```

### `routes/console.php`

```diff
--- a/routes/console.php
+++ b/routes/console.php
@@ -1,6 +1,7 @@
 <?php
 
 use App\Services\PendingGemReleaseService;
+use App\Support\SchedulerHeartbeat;
 use Illuminate\Console\Scheduling\Event;
 use Illuminate\Support\Facades\Schedule;
 
@@ -41,3 +42,8 @@
 
 // التقاط إتاحة الحملات الناتجة عن مرور الوقت. غيابه لا يكسر شيئًا: الإتاحة بالحفظ الإداري تُلتقَط فورًا بخطاف النموذج.
 $guard(Schedule::command('campaigns:sync-availability')->everyFiveMinutes()->name('campaigns-sync-availability'), 30);
+
+// E25: نبض المجدول - يكتب الطابع الزمني بالكاش الافتراضي كل دقيقة؛ عمره = صحة المجدول (مركز العمليات، production:check، وعتبة /ready الاختيارية).
+// يمرّ من $guard مثل بقية المهام (قاعدة E24: لا مهمة بلا قفل تداخل محدود ولا onOneServer). قفل 5 دقائق يكفي لعملية بطولها ميلي ثوانٍ وينتهي وحده بعد أي انهيار.
+// يُسجَّل أخيرًا كي لا يغيّر ترتيب المهام القائمة.
+$guard(Schedule::call(fn () => app(SchedulerHeartbeat::class)->beat())->everyMinute()->name('scheduler-heartbeat'), 5);
```

## C) Migrations

لا توجد ترحيلات جديدة في E25 (0). نبضة المجدول تُخزَّن بالكاش لا بالقاعدة.

## D) إضافات `.env.example` (كلها معلَّقة، placeholders فقط)

### `.env.example`

```diff
--- a/.env.example
+++ b/.env.example
@@ -114,3 +114,42 @@
 # مهل اتصال PHP -> Reverb للبثّ المتزامن (ثوانٍ). قصيرة كي لا يحجز Reverb المعلّق عامل PHP-FPM ثلاثين ثانية.
 # REVERB_HTTP_CONNECT_TIMEOUT=1
 # REVERB_HTTP_TIMEOUT=3
+
+# ==============================================================================
+# E25: التشغيل بالإنتاج / الأمان / النشر / الاستعادة  (كلها اختيارية؛ الافتراضات تحفظ سلوك المنتج كما هو)
+# القيم هنا placeholders فقط: لا أسرار حقيقية بهذا الملف ولا بأي ملف بالمستودع. أسرار الإنتاج من مدير أسرار المزوّد أو متغيرات بيئة محميّة (docs/environment-variables.md).
+# ==============================================================================
+# ملخص عقد الإنتاج (php artisan production:check يفحصه ويعطي PASS/WARN/FAIL بلا طباعة أي قيمة سرية):
+#   APP_ENV=production  APP_DEBUG=false  APP_URL=https://<نطاقك>  SESSION_SECURE_COOKIE=true
+#   DB_CONNECTION=mysql (أو pgsql)  QUEUE_CONNECTION=database|redis  MAIL_MAILER=smtp  LOG_STACK=daily
+#   BROADCAST_CONNECTION=reverb مع REVERB_SCHEME=https و REVERB_HOST=<نطاق> و REVERB_ALLOWED_ORIGINS=<نطاقك>
+
+# كوكي الجلسة: إلزامي true بالإنتاج على HTTPS (يمنع إرسالها عبر HTTP). محليًا اتركه غير مضبوط.
+# SESSION_SECURE_COOKIE=true
+# SESSION_HTTP_ONLY=true
+# SESSION_SAME_SITE=lax
+
+# هوية الإصدار (اختيارية): تُضبط عند كل نشر (SHA مختصر أو وسم). تظهر في /ready ومركز العمليات وكل سطر سجل (release). الصيغة [A-Za-z0-9._-] حتى 64 خانة.
+# APP_RELEASE=
+# RELEASE_SHA=
+
+# السجلات: بالإنتاج استعمل daily مع احتفاظ محدود (القناة single بلا تدوير ولا حد حجم). LOG_LEVEL=info أو warning بدل debug.
+# LOG_STACK=daily
+# LOG_DAILY_DAYS=14
+
+# Reverb: قيّد الأصول المسموحة لاتصالات WebSocket (أسماء مضيفين مفصولة بفواصل، بلا http://). الافتراضي * (يسمح للجميع).
+# REVERB_ALLOWED_ORIGINS=ahjiyat.example.com
+
+# أول مدير بالإنتاج فقط (AdminUserSeeder): لا كلمة مرور افتراضية بالإنتاج. ضعهما مؤقتًا بالبيئة لأول تشغيل ثم احذفهما فورًا. ≥ 12 خانة.
+# ADMIN_BOOTSTRAP_EMAIL=
+# ADMIN_BOOTSTRAP_PASSWORD=
+
+# نبض المجدول وتنبيهات الطابور (ثوانٍ؛ كلها اختيارية).
+# OPS_SCHEDULER_STALE_SECONDS=300
+# OPS_SCHEDULER_DOWN_SECONDS=900
+# OPS_READY_FAILS_ON_SCHEDULER=false     # true: /ready يفشل فقط عند حالة down (لا عند التأخر البسيط ولا بعد نشر جديد)
+# OPS_QUEUE_BACKLOG_WARN_SECONDS=900
+# OPS_FAILED_JOBS_WARN=1
+
+# الأوامر الهدّامة (db:wipe, migrate:fresh|refresh|reset|rollback) ممنوعة بالإنتاج. استثناء واعٍ لعملية واحدة فقط (لا تضعه بملف .env الدائم):
+# OPS_ALLOW_DESTRUCTIVE_COMMANDS=false
```

