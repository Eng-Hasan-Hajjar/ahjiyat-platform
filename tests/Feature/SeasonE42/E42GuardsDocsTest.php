<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| E42 - حراس البنية والأمان والوثائق وCI: PSR/namespace، لا حزم جديدة ولا شبكة ولا أسرار، لا اقتصاد داخل الموسم، لا صلاحيات ولا مسارات كتابة عامة جديدة،
| نظافة الشيفرة (E26)، الترحيلات بأسماء فهارس صريحة، المجدول والأوامر، الوثائق كاملة وروابطها صحيحة، placeholders فقط، وخطوات CI.
|--------------------------------------------------------------------------
*/

function e42g_read(string $relative): string
{
    return (string) file_get_contents(base_path($relative));
}

/** الكود بلا تعليقات (لمسح الرموز لا النصوص الشارحة). */
function e42g_code(string $relative): string
{
    $code = '';

    foreach (token_get_all(e42g_read($relative)) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $code .= is_array($token) ? $token[1] : $token;
    }

    return $code;
}

/** @return list<string> ملفات PHP الجديدة الخاصة بتطوّر الموسم (مسارات نسبية). */
function e42g_new_php(): array
{
    $files = [];

    foreach (['app/Services/Seasons', 'app/Filament/Resources/SeasonResource/RelationManagers'] as $dir) {
        foreach (glob(base_path($dir).'/*.php') as $file) {
            $files[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file);
        }
    }

    return array_merge($files, [
        'app/Models/SeasonPhase.php', 'app/Models/SeasonAnnouncement.php', 'app/Models/SeasonContentItem.php',
        'app/Jobs/DispatchSeasonPhaseStartedChunk.php', 'app/Jobs/DispatchSeasonAnnouncementChunk.php', 'app/Console/Commands/ProcessSeasons.php',
        'database/seeders/DemoE42Seeder.php', 'config/seasons.php',
    ]);
}

/** كل الملفات المتأثرة بالموسم (الجديدة + المعدّلة جوهريًا) لفحوص الأمان. */
function e42g_scope_php(): array
{
    return array_merge(e42g_new_php(), [
        'app/Services/SeasonLifecycleService.php', 'app/Services/SeasonReadinessService.php', 'app/Http/Controllers/SeasonController.php', 'app/Models/Season.php',
        'app/Filament/Resources/SeasonResource.php', 'app/Filament/Resources/SeasonResource/Pages/EditSeason.php', 'app/Filament/Resources/SeasonResource/Pages/CreateSeason.php',
    ]);
}

function e42g_views(): array
{
    $views = [];

    foreach (glob(resource_path('views/seasons/*.blade.php')) as $file) {
        $views[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file);
    }

    return array_merge($views, ['resources/views/components/season-hero.blade.php', 'resources/views/filament/resources/season-analytics.blade.php', 'resources/views/filament/resources/season-readiness.blade.php']);
}

function e42g_slug(string $heading): string
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

/** @return list<string> */
function e42g_anchors(string $markdown): array
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
            $slug = e42g_slug($m[1]);
            $n = $seen[$slug] ?? 0;
            $seen[$slug] = $n + 1;
            $anchors[] = $n === 0 ? $slug : "{$slug}-{$n}";
        }
    }

    return $anchors;
}

const E42_DOCS = ['docs/season-evolution-e42.md', 'docs/season-operations-runbook.md'];

test('E42-guard-1: every new season class is PSR-4 compliant, and all expected artifacts exist', function () {
    $violations = [];

    foreach (e42g_new_php() as $file) {
        if (! str_starts_with($file, 'app/')) {
            continue;
        }

        $source = e42g_read($file);
        $expectedNamespace = 'App\\'.str_replace('/', '\\', dirname(substr($file, strlen('app/'))));
        $class = pathinfo($file, PATHINFO_FILENAME);

        if (preg_match('/^namespace\s+([^;]+);/m', $source, $ns) !== 1 || $ns[1] !== $expectedNamespace) {
            $violations[] = "{$file}: namespace";
        }

        if (preg_match('/^(?:final\s+|abstract\s+)?(?:class|interface|enum|trait)\s+'.preg_quote($class, '/').'\b/m', $source) !== 1) {
            $violations[] = "{$file}: class name";
        }
    }

    foreach ([
        'database/migrations/2026_11_30_000001_add_orchestration_columns_to_seasons_table.php', 'database/migrations/2026_11_30_000002_create_season_phases_and_announcements_tables.php',
        'database/migrations/2026_11_30_000003_create_season_content_items_table.php', 'config/seasons.php', 'tools/e42/concurrency-probe.php', 'database/seeders/DemoE42Seeder.php',
        'tests/Feature/SeasonE42/E42MysqlSchemaTest.php', ...E42_DOCS,
    ] as $file) {
        expect(is_file(base_path($file)))->toBeTrue($file);
    }

    expect($violations)->toBe([]);
});

test('E42-guard-2: no new package, no outbound network call and no real credential in the season code', function () {
    $violations = [];

    foreach (array_merge(e42g_scope_php(), ['tools/e42/concurrency-probe.php']) as $file) {
        $code = e42g_code($file);

        // كل `use` من مساحات الإطار والتطبيق فقط - لا مكتبة طرف ثالث جديدة.
        preg_match_all('/^use\s+([A-Za-z0-9_]+)\\\\/m', $code, $uses);

        foreach (array_unique($uses[1]) as $root) {
            if (! in_array($root, ['App', 'Illuminate', 'Carbon', 'Filament', 'Database', 'Livewire'], true)) {
                $violations[] = "{$file}: use {$root}\\";
            }
        }

        foreach (['Http::', 'curl_', 'file_get_contents(\'http', 'GuzzleHttp', 'fsockopen', 'stream_socket_client', 'new Client('] as $needle) {
            if (str_contains($code, $needle)) {
                $violations[] = "{$file}: {$needle}";
            }
        }

        foreach (['/sk_(live|test)_[A-Za-z0-9]{8,}/', '/whsec_[A-Za-z0-9]{8,}/', '/AKIA[0-9A-Z]{16}/', '/-----BEGIN [A-Z ]*PRIVATE KEY-----/'] as $pattern) {
            if (preg_match($pattern, $code) === 1) {
                $violations[] = "{$file}: credential-like value";
            }
        }
    }

    expect($violations)->toBe([]);
});

test('E42-guard-3: season code is clean - no debug leftovers, no raw output, no env() in config, no raw SQL built from variables, guarded models', function () {
    $violations = [];

    foreach (e42g_scope_php() as $file) {
        $code = e42g_code($file);

        if (preg_match('/\b(dd|dump|var_dump|print_r|ray)\s*\(/', $code) === 1 || str_contains($code, 'console.log')) {
            $violations[] = "{$file}: debug";
        }

        // SQL الخام المسموح: نص ثابت تُمرَّر قيمه عبر bindings. المرفوض: وسيط أول بمتغير أو نص مُدرَج/مُلصَق.
        if (preg_match('/(?:DB::raw|whereRaw|orWhereRaw|selectRaw|orderByRaw|havingRaw|groupByRaw|DB::statement|DB::unprepared)\(\s*(?:"[^"]*\$|"[^"]*"\s*\.|\'[^\']*\'\s*\.|\$)/', $code) === 1) {
            $violations[] = "{$file}: raw SQL with variables";
        }
    }

    foreach (e42g_views() as $view) {
        $source = e42g_read($view);

        if (str_contains($source, '{!!') || preg_match('/console\.log|<script[^>]*>[^<]*\bdd\(/', $source) === 1) {
            $violations[] = "{$view}: unescaped output / debug";
        }
    }

    expect(e42g_code('config/seasons.php'))->not->toContain('env(');

    foreach (['app/Models/SeasonPhase.php', 'app/Models/SeasonAnnouncement.php', 'app/Models/SeasonContentItem.php'] as $model) {
        expect(e42g_read($model))->toContain("protected \$guarded = ['*'];");
    }

    expect($violations)->toBe([]);
});

test('E42-guard-4: the season owns no economy - its services, jobs, controller and models never touch gems, wallets, XP, rewards, store, payments or commerce', function () {
    $forbidden = ['GemWalletService', 'PendingGemReleaseService', 'RedemptionService', 'Services\\Economy', 'Services\\Progression', 'Services\\Store', 'Services\\Payments', 'Services\\Commerce',
        'Services\\CreatorFinance', 'Services\\Sponsorship', 'Services\\Competitive\\Rewards', 'AttemptRewardResolver', 'PassClaimService', 'PassPointService', 'PassPremiumService', 'CurrencyTransaction', 'XpTransaction', 'DigitalPurchase', 'Wallet'];
    $violations = [];

    foreach (array_merge(
        glob(base_path('app/Services/Seasons').'/*.php') ?: [],
        [base_path('app/Services/SeasonLifecycleService.php'), base_path('app/Http/Controllers/SeasonController.php'), base_path('app/Models/SeasonPhase.php'), base_path('app/Models/SeasonAnnouncement.php'), base_path('app/Models/SeasonContentItem.php'), base_path('app/Jobs/DispatchSeasonPhaseStartedChunk.php'), base_path('app/Jobs/DispatchSeasonAnnouncementChunk.php'), base_path('app/Console/Commands/ProcessSeasons.php')],
    ) as $path) {
        $file = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
        $code = e42g_code($file);

        foreach ($forbidden as $needle) {
            if (str_contains($code, $needle)) {
                $violations[] = "{$file}: {$needle}";
            }
        }
    }

    expect($violations)->toBe([]);
});

test('E42-guard-5: the only new public route is a GET with a neutral name, and no season route can write or carries a reward-like name', function () {
    $seasonRoutes = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with((string) $r->getName(), 'seasons.'));

    expect($seasonRoutes->map->getName()->sort()->values()->all())->toBe(['seasons.archive', 'seasons.index', 'seasons.show']);

    foreach ($seasonRoutes as $route) {
        expect($route->methods())->not->toContain('POST')->not->toContain('PUT')->not->toContain('PATCH')->not->toContain('DELETE')
            ->and(preg_match('/reward|grant|claim|payout|prize/i', (string) $route->getName().' '.$route->uri()))->toBe(0);
    }

    // الأرشيف يسبق {season:slug} ومعرّفه محجوز.
    $uris = array_values(array_map(fn ($r) => $r->uri(), $seasonRoutes->values()->all()));
    expect(array_search('seasons/archive', $uris, true))->toBeLessThan(array_search('seasons/{season}', $uris, true))->and(config('seasons.reserved_slugs'))->toContain('archive');

    foreach (e42g_views() as $view) {
        preg_match_all("/route\\('([^']+)'/", e42g_read($view), $names);

        foreach ($names[1] as $name) {
            expect(preg_match('/reward|grant|claim|payout|prize/i', $name))->toBe(0, "{$view}: {$name}");
        }
    }
});

test('E42-guard-6: no new permission - the season code uses only the six existing seasons.* abilities', function () {
    $seeder = e42g_read('database/seeders/RolesAndPermissionsSeeder.php');
    preg_match_all("/'(seasons\\.[a-z]+)'/", $seeder, $declared);

    $allowed = ['seasons.view', 'seasons.create', 'seasons.update', 'seasons.delete', 'seasons.publish', 'seasons.preview'];

    expect(array_values(array_unique($declared[1])))->toEqualCanonicalizing($allowed);

    $used = [];

    foreach (array_merge(e42g_scope_php(), ['app/Policies/SeasonPolicy.php']) as $file) {
        preg_match_all("/(?:can|cannot|canAny|authorize|hasPermissionTo|givePermissionTo)\\([^)]*'(seasons\\.[a-z_]+)'/", e42g_code($file), $m);
        $used = array_merge($used, $m[1]);
    }

    expect(array_values(array_diff(array_unique($used), $allowed)))->toBe([]);
});

test('E42-guard-7: the season commands are registered and scheduled with overlap protection, and the jobs are bounded', function () {
    $commands = Artisan::all();

    foreach (['seasons:process', 'seasons:sync-live-state'] as $name) {
        expect(array_keys($commands))->toContain($name);

        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, $name));

        expect($event)->not->toBeNull()->and($event->withoutOverlapping)->toBeTrue()->and($event->expression)->toBe('*/5 * * * *');
    }

    // المعالج لا يستقبل خيارات تكتب أو تتجاوز الحراسة.
    expect(array_keys($commands['seasons:process']->getDefinition()->getOptions()))->not->toContain('force')->not->toContain('repair');

    foreach (['app/Jobs/DispatchSeasonPhaseStartedChunk.php', 'app/Jobs/DispatchSeasonAnnouncementChunk.php'] as $job) {
        $source = e42g_read($job);

        expect($source)->toContain('public int $tries = 3;')->toContain('BoundedRetries')->toContain('ShouldQueue');
    }
});

test('E42-guard-8: the season migrations are additive, use explicit bounded index names, and every foreign key has a stated delete policy', function () {
    $files = glob(base_path('database/migrations/2026_11_30_00000*.php'));

    expect(count($files))->toBe(3);

    foreach ($files as $file) {
        $source = file_get_contents($file);
        // صيغتان بأسماء صريحة: ->unique(['a','b'], 'name') و->unique('name') بعد تعريف العمود.
        preg_match_all("/->(?:unique|index)\\(\\[[^\\]]+\\],\\s*'([^']+)'\\)/", $source, $arrayForm);
        preg_match_all("/->(?:unique|index)\\('([^']+)'\\)/", $source, $nameForm);
        $names = array_merge($arrayForm[1], $nameForm[1]);

        foreach ($names as $name) {
            expect(strlen($name))->toBeLessThanOrEqual(64, $name);
        }

        // كل unique/index لها اسم صريح (لا أسماء تلقائية قد تتجاوز 64 على MySQL).
        expect(preg_match_all('/->(?:unique|index)\(/', $source))->toBe(count($names), basename($file))
            ->and($source)->not->toContain('dropColumn(\'campaign_id\'')->and($source)->not->toContain('->change()');

        // لا مفتاح أجنبي بلا سياسة حذف صريحة.
        expect(preg_match_all('/->foreignId\(/', $source))->toBe(preg_match_all('/->(cascadeOnDelete|nullOnDelete|restrictOnDelete)\(/', $source), basename($file));
    }

    // الأعمدة الجديدة على seasons كلها قابلة لـNULL (لا تغيير لسلوك المواسم القائمة).
    $columns = file_get_contents(base_path('database/migrations/2026_11_30_000001_add_orchestration_columns_to_seasons_table.php'));
    expect(substr_count($columns, '->nullable()'))->toBeGreaterThanOrEqual(5);
});

test('E42-guard-9: the new admin surfaces write only through the builder - no raw rich-text or file upload, and no default create/edit/delete actions on the relation managers', function () {
    foreach (glob(base_path('app/Filament/Resources/SeasonResource/RelationManagers').'/*.php') as $path) {
        $file = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
        $code = e42g_code($file);

        expect($code)->not->toContain('FileUpload')->not->toContain('RichEditor')->not->toContain('MarkdownEditor')->not->toContain('->html()')
            ->not->toContain('Tables\\Actions\\CreateAction')->not->toContain('Tables\\Actions\\EditAction')->not->toContain('Tables\\Actions\\DeleteAction')
            ->not->toContain('DeleteBulkAction')->and($code)->toContain('SeasonBuilderService');
    }
});

test('E42-guard-10: the configuration is bounded data only - positive integer limits and closed allow-lists', function () {
    $c = config('seasons');

    foreach ($c['limits'] as $key => $value) {
        expect($value)->toBeInt()->toBeGreaterThan(0, "limits.{$key}");
    }

    expect($c['limits']['phases_per_season'])->toBeLessThanOrEqual(50)->and($c['limits']['announcement_body_max'])->toBeLessThanOrEqual(5000)
        ->and($c['theme']['presets'])->toBe(['generic', 'aseel'])->and($c['notifications']['audiences'])->toBe(['participants', 'all'])
        ->and($c['notifications']['phase_notice_max_age_hours'])->toBeGreaterThan(0)->toBeLessThanOrEqual(168);
});

test('E42-guard-11: both documents exist, are substantial, cover every required topic, and their internal links and anchors resolve', function () {
    foreach (E42_DOCS as $doc) {
        expect(is_file(base_path($doc)))->toBeTrue($doc)->and(strlen(e42g_read($doc)))->toBeGreaterThan(6000, "{$doc} looks thin");
    }

    $design = e42g_read('docs/season-evolution-e42.md');

    foreach (['الموسم منسِّق لا اقتصاد', 'الحالة المشتقة', 'المراحل', 'المحتوى المرتبط', 'المسار المرتبط', 'الإعلانات', 'مركز الموسم', 'المعالج المجدول', 'الإشعارات', 'الجاهزية', 'الأرشفة', 'الصلاحيات',
        'التحليلات', 'الأمان', 'الخصوصية', 'التوافق مع الوراء', 'seasons:process', 'seasons:sync-live-state', 'SeasonBuilderService', 'نصف مفتوحة', 'went_live_at', 'DemoE42Seeder', 'غير متاح بعد', 'E40', 'E41'] as $topic) {
        expect($design)->toContain($topic);
    }

    $runbook = e42g_read('docs/season-operations-runbook.md');

    foreach (['الفحص السريع', 'إنشاء موسم جديد', 'تعديل النافذة', 'المراحل', 'المحتوى المرتبط', 'الإعلانات والإشعار', 'النشر وإيقاف النشر', 'الأرشفة', 'المعالج والتأخر', 'أكواد الرفض', 'التزامن الحي',
        'حوادث شائعة', 'الخصوصية', 'seasons:process', 'concurrency-probe', 'إيقاف النشر', 'phase_overlap', 'start_locked', 'notify_locked'] as $topic) {
        expect($runbook)->toContain($topic);
    }

    // كل كود رفض في الباني موثَّق في الدليل.
    preg_match_all("/new SeasonException\\('([a-z_]+)'/", e42g_read('app/Services/Seasons/SeasonBuilderService.php').e42g_read('app/Services/Seasons/SeasonTheme.php'), $reasons);

    foreach (array_unique($reasons[1]) as $reason) {
        expect($runbook)->toContain($reason);
    }

    $broken = [];

    foreach (E42_DOCS as $doc) {
        preg_match_all('/\]\(([^)\s]+)\)/u', e42g_read($doc), $m);

        foreach ($m[1] as $target) {
            if (str_starts_with($target, 'http') || str_starts_with($target, 'mailto:')) {
                continue;
            }

            [$path, $anchor] = array_pad(explode('#', $target, 2), 2, null);
            $file = $path === '' ? $doc : 'docs/'.$path;

            if (! is_file(base_path($file))) {
                $broken[] = "{$doc} -> {$target} (missing file)";

                continue;
            }

            if ($anchor !== null && ! in_array(rawurldecode($anchor), e42g_anchors(e42g_read($file)), true)) {
                $broken[] = "{$doc} -> {$target} (missing anchor)";
            }
        }
    }

    expect($broken)->toBe([]);
});

test('E42-guard-12: the documents state that no environment variable was added, and docs carry no secret-like value', function () {
    foreach (E42_DOCS as $doc) {
        preg_match_all('/^\s*(?:export\s+)?([A-Z][A-Z0-9_]*(?:PASSWORD|SECRET|TOKEN|KEY))=([^\s#`]+)/m', e42g_read($doc), $matches, PREG_SET_ORDER);

        foreach ($matches as [$all, $name, $value]) {
            expect(preg_match('/^(<[^>]+>|\$\{?[A-Z_]+\}?|\.{3}|…|null|"")$/i', trim($value, '"\'')))->toBe(1, "{$doc}: {$name} has a non-placeholder value");
        }
    }

    expect(e42g_read('docs/environment-variables.md'))->toContain('لا يضيف أي متغير بيئة')
        ->and(e42g_read('docs/production-deployment.md'))->toContain('seasons:process')
        ->and(e42g_read('docs/notifications.md'))->toContain('season_phase_started')->toContain('season_announcement_published');

    // لا متغيرات SEASON* جديدة في .env.example.
    expect(preg_match('/^\s*#?\s*SEASONS?_[A-Z0-9_]+=/m', e42g_read('.env.example')))->toBe(0);
});

test('E42-guard-13: the CI workflow runs the season MySQL schema test and the real-concurrency probe, after E41, on the temporary test database only', function () {
    $ci = e42g_read('.github/workflows/ci.yml');

    expect($ci)->toContain('tests/Feature/SeasonE42/E42MysqlSchemaTest.php')->toContain('php tools/e42/concurrency-probe.php --rounds=4')
        ->and(strpos($ci, 'tools/e42/concurrency-probe.php'))->toBeGreaterThan(strpos($ci, 'tools/e41/concurrency-probe.php'))
        ->and(strpos($ci, 'E42MysqlSchemaTest.php'))->toBeGreaterThan(strpos($ci, 'E41MysqlSchemaTest.php'));

    $probe = e42g_read('tools/e42/concurrency-probe.php');

    foreach (['app()->environment(\'production\')', 'in_array($driver, [\'mysql\', \'mariadb\']', '(test|testing)', 'migrate:fresh', 'exit(2)'] as $guard) {
        expect($probe)->toContain($guard);
    }

    expect($probe)->not->toContain('Http::')->not->toContain('curl_');
});

test('E42-guard-14: the season builder authorizes inside the service, locks the season row, and audits every write', function () {
    $builder = e42g_code('app/Services/Seasons/SeasonBuilderService.php');
    preg_match_all('/public function (updateSchedule|publish|unpublish|archive|addPhase|updatePhase|deletePhase|addContent|updateContent|removeContent|linkPass|createAnnouncement|updateAnnouncement|deleteAnnouncement)\(/', $builder, $methods);

    expect(array_unique($methods[1]))->toHaveCount(14);

    foreach (array_unique($methods[1]) as $method) {
        $start = strpos($builder, "public function {$method}(");
        $next = strpos($builder, 'public function ', $start + 10);
        $body = substr($builder, $start, ($next === false ? strlen($builder) : $next) - $start);

        expect($body)->toContain('$this->authorize(')->toContain('DB::transaction(')->toContain('$this->audit->log(');
    }

    expect($builder)->toContain('lockForUpdate()');
});
