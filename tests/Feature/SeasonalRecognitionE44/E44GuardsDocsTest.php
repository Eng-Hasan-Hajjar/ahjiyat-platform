<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

require_once __DIR__.'/_support.php';

/*
|--------------------------------------------------------------------------
| E44 (حراس) - البنية والأمان والعزل والوثائق وCI: PSR/namespace، لا حزم ولا شبكة ولا أسرار، نظافة الشيفرة، عزل الاقتصاد (جواهر/XP/مسار/موسم/تنافس/إحالة/سمعة/أرباح المبدعين)
| وعزل العكس (لا يقرأ الاقتصاد نقاط الدعم)، محرك الدفع الواحد (E30/E32)، حارس المسارات والطلبات، الصلاحيات الست، أمر المطابقة للقراءة فقط، الترحيلات، الإعداد، الواجهة (JS/Blade)،
| الوثائق (رموز الأسباب والمخالفات، الروابط والمراسي)، متغيرات البيئة، خطوتا CI، ولا ملفات تسليم في الجذر.
|--------------------------------------------------------------------------
*/

const E44G_DOCS = ['docs/seasonal-writers-supporters-e44.md', 'docs/seasonal-recognition-operations-runbook.md'];

const E44G_PERMISSIONS = ['seasonal-recognition.view', 'seasonal-recognition.manage', 'seasonal-recognition.vote', 'seasonal-recognition.audit', 'seasonal-recognition.finalize', 'seasonal-recognition.award'];

const E44G_MODELS = ['SeasonRecognitionSetting', 'SeasonWriterEntry', 'SeasonWriterSeries', 'SeasonWriterVoteBudget', 'SeasonWriterStarAllocation', 'SeasonWriterAward',
    'SeasonSupporterPackage', 'SeasonSupporterCard', 'SeasonSupportPointEntry', 'SeasonSupporterStat'];

const E44G_MIGRATIONS = ['database/migrations/2026_12_14_000001_create_season_recognition_settings_and_writer_tables.php', 'database/migrations/2026_12_14_000002_create_season_support_tables.php',
    'database/migrations/2026_12_14_000003_add_support_points_to_store_tables.php'];

/** @return list<string> ملفات PHP الجديدة التابعة لـE44 (مسارات نسبية). */
function e44g_php(): array
{
    $glob = fn (string $dir) => array_map('e44_rel', glob(base_path($dir).'/*.php') ?: []);

    return array_merge(
        $glob('app/Services/SeasonalRecognition'),
        $glob('app/Http/Controllers/SeasonalRecognition'),
        $glob('app/Http/Requests/SeasonalRecognition'),
        $glob('app/Events/SeasonalRecognition'),
        $glob('app/Observers/SeasonalRecognition'),
        array_map(fn ($m) => "app/Models/{$m}.php", E44G_MODELS),
        ['app/Providers/SeasonalRecognitionServiceProvider.php', 'app/Console/Commands/ReconcileSeasonalRecognition.php', 'app/Filament/Pages/SeasonalRecognitionPage.php',
            'app/View/Components/SeasonalRecognitionStrip.php', 'database/seeders/DemoE44Seeder.php', 'config/seasonal_recognition.php', 'routes/seasonal_recognition.php'],
    );
}

/** @return list<string> */
function e44g_services(): array
{
    return array_values(array_filter(e44g_php(), fn ($f) => str_starts_with($f, 'app/Services/SeasonalRecognition/')));
}

/** @return list<string> */
function e44g_views(): array
{
    $views = array_map('e44_rel', glob(base_path('resources/views/seasonal').'/*.blade.php') ?: []);

    return array_merge($views, ['resources/views/filament/pages/seasonal-recognition.blade.php', 'resources/views/components/season-supporter-name.blade.php',
        'resources/views/components/seasonal-recognition-strip.blade.php']);
}

function e44g_slug(string $heading): string
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
function e44g_anchors(string $markdown): array
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
            $slug = e44g_slug($m[1]);
            $n = $seen[$slug] ?? 0;
            $seen[$slug] = $n + 1;
            $anchors[] = $n === 0 ? $slug : "{$slug}-{$n}";
        }
    }

    return $anchors;
}

/** كل رمز سبب تُرميه خدمات E44 (استثناء مجال، أو مصفوفة رفض الأهلية). @return list<string> */
function e44g_reason_codes(): array
{
    $codes = [];

    foreach (e44g_services() as $file) {
        $code = e44_code($file);
        // الرمز الأول متبوعًا بفاصلة (يتخطى الرمز الديناميكي 'invalid_'.$field)؛ ومصفوفات الرفض ['reason' => '...'].
        preg_match_all("/new SeasonalRecognitionException\\(\\s*'([a-z_]+)'\\s*,/", $code, $m);
        $codes = array_merge($codes, $m[1]);
        preg_match_all("/'reason' => '([a-z_]+)'/", $code, $m2);
        $codes = array_merge($codes, $m2[1]);
    }

    return array_values(array_unique($codes));
}

/** رموز مخالفات المطابقة في المطابقين. @return list<string> */
function e44g_finding_codes(): array
{
    $codes = [];

    foreach (['app/Services/SeasonalRecognition/SeasonWriterReconciler.php', 'app/Services/SeasonalRecognition/SeasonSupporterReconciler.php'] as $file) {
        preg_match_all("/'code' => (?:[^']*\\? )?'([a-z_]+)'(?: : '([a-z_]+)')?/", e44_code($file), $m, PREG_SET_ORDER);

        foreach ($m as $set) {
            $codes[] = $set[1];

            if (isset($set[2]) && $set[2] !== '') {
                $codes[] = $set[2];
            }
        }
    }

    return array_values(array_unique($codes));
}

test('E44-G-01: every new E44 class is PSR-4 compliant and every expected artifact exists', function () {
    $violations = [];

    foreach (e44g_php() as $file) {
        if (! str_starts_with($file, 'app/')) {
            continue;
        }

        $source = e44_read($file);
        $expectedNamespace = 'App\\'.str_replace('/', '\\', dirname(substr($file, strlen('app/'))));
        $class = pathinfo($file, PATHINFO_FILENAME);

        if (preg_match('/^namespace\s+([^;]+);/m', $source, $ns) !== 1 || $ns[1] !== $expectedNamespace) {
            $violations[] = "{$file}: namespace";
        }

        if (preg_match('/^(?:final\s+|abstract\s+)?(?:class|interface|enum|trait)\s+'.preg_quote($class, '/').'\b/m', $source) !== 1) {
            $violations[] = "{$file}: class name";
        }
    }

    $expected = array_merge(E44G_MIGRATIONS, E44G_DOCS, e44g_views(), [
        'tools/e44/concurrency-probe.php', 'resources/js/seasonal.js', 'database/seeders/DemoE44Seeder.php',
        'tests/Feature/SeasonalRecognitionE44/_support.php', 'tests/Feature/SeasonalRecognitionE44/E44MysqlSchemaTest.php', 'tests/Feature/SeasonalRecognitionE44/E44GuardsDocsTest.php',
        'tests/Feature/SeasonalRecognitionE44/E44SettingsRosterTest.php', 'tests/Feature/SeasonalRecognitionE44/E44VotingTest.php', 'tests/Feature/SeasonalRecognitionE44/E44LeaderboardPagesTest.php',
        'tests/Feature/SeasonalRecognitionE44/E44FinalizeAwardsTest.php', 'tests/Feature/SeasonalRecognitionE44/E44SupportPointsTest.php', 'tests/Feature/SeasonalRecognitionE44/E44SupporterCardsTest.php',
        'tests/Feature/SeasonalRecognitionE44/E44SupporterBoardTest.php', 'tests/Feature/SeasonalRecognitionE44/E44AdminPageTest.php', 'tests/Feature/SeasonalRecognitionE44/E44SeederTest.php',
        'tests/Feature/SeasonalRecognitionE44/E44PerformanceTest.php',
    ]);

    foreach ($expected as $file) {
        expect(is_file(base_path($file)))->toBeTrue($file);
    }

    expect(count(e44g_services()))->toBeGreaterThanOrEqual(20)->and($violations)->toBe([]);
});

test('E44-G-02: no new package, no outbound network call and no real credential in E44 code, the probe or the client script', function () {
    $violations = [];

    foreach (array_merge(e44g_php(), ['tools/e44/concurrency-probe.php']) as $file) {
        $code = e44_code($file);

        preg_match_all('/^use\s+([A-Za-z0-9_]+)\\\\/m', $code, $uses);

        foreach (array_unique($uses[1]) as $root) {
            if (! in_array($root, ['App', 'Illuminate', 'Carbon', 'Filament', 'Database', 'Livewire', 'Symfony'], true)) {
                $violations[] = "{$file}: use {$root}\\";
            }
        }

        foreach (['Http::', 'curl_', "file_get_contents('http", 'GuzzleHttp', 'fsockopen', 'stream_socket_client', 'new Client(', 'Mail::', 'Notification::route'] as $needle) {
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

    $js = e44_read('resources/js/seasonal.js');

    foreach (['http://', 'https://', 'eval(', 'document.write', 'new WebSocket(', 'indexedDB'] as $needle) {
        if (str_contains($js, $needle)) {
            $violations[] = "resources/js/seasonal.js: {$needle}";
        }
    }

    // التعليق يذكر localStorage ليقول إنه لا يُستخدم؛ المحظور هو الاستخدام الفعلي (وصول بنقطة أو قوسين).
    if (preg_match('/\b(localStorage|sessionStorage)\s*[.\[]/', $js) === 1) {
        $violations[] = 'resources/js/seasonal.js: browser storage access';
    }

    // لا حزمة جديدة (E44 لا يضيف Composer ولا npm): لا اعتمادية باسم يوحي بحزمة ترتيب/تصويت/شارات/تلعيب.
    $composer = json_decode(e44_read('composer.json'), true);
    $package = json_decode(e44_read('package.json'), true);

    expect(collect(array_merge(array_keys($composer['require'] ?? []), array_keys($composer['require-dev'] ?? [])))->filter(fn ($p) => preg_match('/season|leaderboard|ranking|gamif|badge|vote|rating/i', $p) === 1)->all())->toBe([])
        ->and(collect(array_merge(array_keys($package['dependencies'] ?? []), array_keys($package['devDependencies'] ?? [])))->filter(fn ($p) => preg_match('/season|leaderboard|ranking|gamif|badge|vote|rating/i', $p) === 1)->all())->toBe([])
        ->and($violations)->toBe([]);
});

test('E44-G-03: E44 code is clean - no debug leftovers, no env() outside config, no raw SQL built from variables, no unescaped output, guarded append-only models', function () {
    $violations = [];

    foreach (e44g_php() as $file) {
        $code = e44_code($file);

        if (preg_match('/\b(dd|dump|var_dump|print_r|ray)\s*\(/', $code) === 1 || str_contains($code, 'console.log')) {
            $violations[] = "{$file}: debug";
        }

        if ($file !== 'config/seasonal_recognition.php' && preg_match('/\benv\s*\(/', $code) === 1) {
            $violations[] = "{$file}: env() outside config";
        }

        if (preg_match('/(?:DB::raw|whereRaw|orWhereRaw|selectRaw|orderByRaw|havingRaw|groupByRaw|DB::statement|DB::unprepared)\(\s*(?:"[^"]*\$|"[^"]*"\s*\.|\'[^\']*\'\s*\.|\$)/', $code) === 1) {
            $violations[] = "{$file}: raw SQL with variables";
        }
    }

    foreach (e44g_views() as $view) {
        $source = e44_read($view);

        if (str_contains($source, '{!!') || preg_match('/console\.log|<script[^>]*>[^<]*\bdd\(/', $source) === 1) {
            $violations[] = "{$view}: unescaped output / debug";
        }
    }

    foreach (E44G_MODELS as $model) {
        expect(e44_read("app/Models/{$model}.php"))->toContain("protected \$guarded = ['*'];");
    }

    expect($violations)->toBe([]);
});

test('E44-G-04: E44 owns no foreign economy — it never touches gems, wallets, XP, pass, competitive rewards, referrals, reputation, creator earnings or payouts — and no economy code reads support points', function () {
    $forbidden = ['GemWalletService', 'PendingGemReleaseService', 'RedemptionService', 'Services\\Economy', 'Services\\Progression', 'Services\\Pass', 'Services\\Competitive', 'Services\\Referral',
        'Services\\Reputation', 'Services\\CreatorFinance', 'Services\\Commerce', 'CreatorEarning', 'CreatorLedger', 'CreatorPayout', 'CreatorRevenue', 'RevenueAllocator', 'CurrencyTransaction',
        'XpTransaction', 'GemTransaction', 'AttemptRewardResolver', 'PassClaimService', 'PassPointService', 'Wallet'];
    $violations = [];

    foreach (e44g_php() as $file) {
        if (str_starts_with($file, 'config/') || str_starts_with($file, 'routes/') || str_contains($file, 'DemoE44Seeder')) {
            continue;
        }

        $code = e44_code($file);

        foreach ($forbidden as $needle) {
            if (str_contains($code, $needle)) {
                $violations[] = "{$file}: {$needle}";
            }
        }

        // الجائزة التجميلية تمرّ بخدمة المخزون وحدها من حزمة المتجر.
        preg_match_all('/Services\\\\Store\\\\([A-Za-z]+)/', $code, $store);

        foreach (array_unique($store[1]) as $class) {
            if ($class !== 'InventoryService') {
                $violations[] = "{$file}: Services\\Store\\{$class}";
            }
        }
    }

    // العكس: خارج E44 لا يقرأ الاقتصاد/التقدّم/المبدعون/الرعاية نقاط الدعم ولا دفترها (المسموح: حقل السعر في المتجر وحارس التاريخ والإشعارات وملف اللاعب وواجهات E44).
    $allowed = ['app/Models/StoreItemPrice.php', 'app/Filament/Resources/StoreItemResource/RelationManagers/PricesRelationManager.php', 'app/Services/Database/FinancialHistoryGuard.php',
        'app/Services/Notifications/NotificationType.php', 'app/Http/Controllers/PlayerProfileController.php'];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app'), FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $fileInfo) {
        if ($fileInfo->getExtension() !== 'php') {
            continue;
        }

        $relative = e44_rel($fileInfo->getPathname());

        if (in_array($relative, $allowed, true) || in_array($relative, e44g_php(), true) || preg_match('#/(SeasonalRecognition|SeasonSupport|SeasonWriter|SeasonRecognition)#', $relative) === 1) {
            continue;
        }

        if (preg_match('/support_points|SupportPoint|SeasonSupport|season_support/', e44_code($relative)) === 1) {
            $violations[] = "{$relative}: reads support points";
        }
    }

    expect($violations)->toBe([]);
});

test('E44-G-05: supporter cards use the one real payment engine (E30/E32) and write no payment, contribution or money row themselves', function () {
    $card = e44_code('app/Services/SeasonalRecognition/SeasonSupporterCardService.php');

    expect($card)->toContain('SponsorshipPaymentService')->toContain('SponsorshipPayment::STATUS_VERIFIED')->toContain('SponsorshipMoney');

    foreach (e44g_php() as $file) {
        $code = e44_code($file);

        foreach (['new SponsorshipPayment', 'SponsorshipPayment::create', 'SponsorshipPayment::query()->create', 'SponsorshipContribution::create', "DB::table('sponsorship_payments')->insert", "DB::table('sponsorship_payments')->update",
            "DB::table('sponsorship_contributions')", 'Services\\Payments\\Providers', 'Stripe', 'PayPal', 'checkout.session'] as $needle) {
            expect(str_contains($code, $needle))->toBeFalse("{$file}: {$needle}");
        }
    }

    // السعر والعملة والنقاط والنمط من لقطة الباقة على الخادم: الطلب لا يحمل إلا هوية المستوى وظهورًا واسمًا اختياريًا ومفتاح تكرار.
    $rules = e44_code('app/Http/Requests/SeasonalRecognition/RequestSupporterCardRequest.php');
    preg_match_all("/'([a-z_]+)' => \\[(?:'required'|'nullable')/", $rules, $keys);

    expect($keys[1])->toBe(['tier', 'visibility', 'display_name', 'request_key']);

    $allocate = e44_code('app/Http/Requests/SeasonalRecognition/AllocateStarsRequest.php');
    preg_match_all("/'([a-z_]+)' => \\[(?:'required'|'nullable')/", $allocate, $allocKeys);

    expect($allocKeys[1])->toBe(['stars']);
});

test('E44-G-06: the six public routes — reads are open, every write is POST behind auth, verified and active account, and none grants, finalizes or confirms anything', function () {
    $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with((string) $r->getName(), 'seasonal.'));

    expect($routes->map->getName()->sort()->values()->all())->toBe(['seasonal.supporters.cards.store', 'seasonal.supporters.show', 'seasonal.supporters.standings', 'seasonal.writers.show',
        'seasonal.writers.standings', 'seasonal.writers.stars']);

    foreach ($routes as $route) {
        $name = (string) $route->getName();
        $writes = array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) !== [];
        $isWrite = in_array($name, ['seasonal.writers.stars', 'seasonal.supporters.cards.store'], true);
        $middleware = $route->gatherMiddleware();

        expect($writes)->toBe($isWrite, $name)
            ->and(preg_match('/finalize|award|grant|confirm|verify|payout|redeem|claim/i', $name.' '.$route->uri()))->toBe(0, $name);

        if ($isWrite) {
            expect($middleware)->toContain('auth')->toContain('verified')->toContain('account.active')->and(collect($middleware)->contains(fn ($m) => str_starts_with((string) $m, 'throttle:')))->toBeTrue($name);
        } else {
            expect($middleware)->not->toContain('auth')->and(collect($middleware)->contains(fn ($m) => str_starts_with((string) $m, 'throttle:')))->toBeTrue($name);
        }
    }

    // الأمر الحاسم بالخدمات: المتحكمان لا يقرآن سعرًا ولا نقاطًا ولا حدًا ولا هوية ناخب من العميل.
    foreach (['SeasonalWritersController', 'SeasonalSupportersController'] as $controller) {
        $code = e44_code("app/Http/Controllers/SeasonalRecognition/{$controller}.php");

        foreach (['price', 'amount', 'currency', 'points', 'budget', 'voter', 'user_id', 'tier_price', 'support_points'] as $field) {
            expect(preg_match("/(?:input|get|post|query)\\(\\s*'{$field}[a-z_]*'/", $code))->toBe(0, "{$controller}: {$field}");
        }
    }
});

test('E44-G-07: the only E44 permissions are the six seasonal-recognition ones — administrator holds all, moderator only view and vote — and nothing else grants or uses another', function () {
    $seeder = e44_read('database/seeders/RolesAndPermissionsSeeder.php');
    $config = e44_read('config/permissions.php');

    foreach ([$seeder, $config] as $source) {
        preg_match_all("/'(seasonal-recognition\\.[a-z]+)'/", $source, $declared);
        expect(array_values(array_unique($declared[1])))->toEqualCanonicalizing(E44G_PERMISSIONS);
    }

    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

    foreach (E44G_PERMISSIONS as $permission) {
        expect(\Spatie\Permission\Models\Role::findByName('administrator')->hasPermissionTo($permission))->toBeTrue($permission);
    }

    foreach (E44G_PERMISSIONS as $permission) {
        $expected = in_array($permission, ['seasonal-recognition.view', 'seasonal-recognition.vote'], true);
        expect(\Spatie\Permission\Models\Role::findByName('moderator')->hasPermissionTo($permission))->toBe($expected, "moderator {$permission}");
    }

    $used = [];

    foreach (array_merge(e44g_php(), ['tools/e44/concurrency-probe.php', 'resources/views/filament/pages/seasonal-recognition.blade.php']) as $file) {
        preg_match_all("/'(seasonal-recognition\\.[a-z_]+)'/", e44_read($file), $m);
        $used = array_merge($used, $m[1]);
    }

    expect(array_values(array_diff(array_unique($used), E44G_PERMISSIONS)))->toBe([]);

    // لا اسم دور مضمَّن في قرار الأهلية (القرار بصلاحية seasonal-recognition.vote فقط).
    $eligibility = e44_code('app/Services/SeasonalRecognition/SeasonWriterEligibilityService.php');
    expect($eligibility)->not->toContain("hasRole('")->not->toContain('hasAnyRole')->not->toContain("'moderator'")->not->toContain("'administrator'");
});

test('E44-G-08: the reconcile command is registered, read-only and without any repair, rebuild or force switch', function () {
    $commands = Artisan::all();

    expect(array_keys($commands))->toContain('seasonal-recognition:reconcile')
        ->and(array_keys($commands['seasonal-recognition:reconcile']->getDefinition()->getOptions()))->not->toContain('force')->not->toContain('repair')->not->toContain('fix')->not->toContain('rebuild');

    $code = e44_code('app/Console/Commands/ReconcileSeasonalRecognition.php');

    expect($code)->toContain('inspect(');

    foreach (['->update(', '->insert(', '->delete(', 'forceFill', '->save(', 'lockForUpdate', 'DB::transaction', 'rebuild', 'activatePending', 'backfill', 'finalize', 'grant('] as $write) {
        expect($code)->not->toContain($write);
    }

    // المطابقان نفسهما: inspect() بلا أي كتابة؛ الكتابة في دوال إجرائية صريحة منفصلة.
    foreach (['SeasonWriterReconciler', 'SeasonSupporterReconciler'] as $class) {
        $source = e44_code("app/Services/SeasonalRecognition/{$class}.php");
        preg_match('/public function inspect\(.*?\n    \}\n/s', $source, $inspect);

        expect($inspect)->not->toBeEmpty();

        foreach (['->update(', '->insert(', '->delete(', 'forceFill', '->save(', 'lockForUpdate', 'DB::transaction'] as $write) {
            expect($inspect[0])->not->toContain($write, "{$class}::inspect writes ({$write})");
        }
    }
});

test('E44-G-09: the three migrations are additive, use explicit bounded index names, restrict history foreign keys (one cascade only), and use no float or decimal', function () {
    $foreignKeys = 0;
    $onDelete = 0;
    $cascades = 0;

    foreach (E44G_MIGRATIONS as $file) {
        $source = e44_read($file);

        // كل unique/index باسم صريح (الوسيط الأخير نصي ينتهي بـ _unique/_index) وبطول ≤ 64 (حد MySQL) — لا أسماء تلقائية.
        $declared = preg_match_all('/->(?:unique|index)\(/', $source);
        preg_match_all("/->(?:unique|index)\\((?:\\[[^\\]]*\\]\\s*,\\s*|'[^']*'\\s*,\\s*)?'([a-z0-9_]+_(?:unique|index))'\\)/", $source, $named);

        foreach ($named[1] as $name) {
            expect(strlen($name))->toBeLessThanOrEqual(64, $name);
        }

        expect($declared)->toBe(count($named[1]), basename($file).': every index/unique needs an explicit name');

        // up() لا يحذف ولا يغيّر شيئًا قائمًا (الإضافة فقط)؛ down() وحده يتراجع.
        preg_match('/function up\(\)[^{]*\{(.*?)\n    \}\n/s', $source, $up);

        expect($up)->not->toBeEmpty()
            ->and($up[1])->not->toContain('drop')->not->toContain('->change()')
            ->and($source)->not->toContain('->change()')->not->toContain('->decimal(')->not->toContain('->float(')->not->toContain('->double(');

        $foreignKeys += preg_match_all('/->foreignId\(/', $source) + preg_match_all('/->foreign\(/', $source);
        $onDelete += preg_match_all('/->(?:nullOnDelete|restrictOnDelete|cascadeOnDelete)\(/', $source);
        $cascades += preg_match_all('/cascadeOnDelete\(/', $source);
    }

    // كل مفتاح أجنبي له سلوك حذف صريح؛ الحذف التتابعي لصف إعدادات الموسم وحده (يتبع موسمه)، وما سواه restrict (تاريخ مالي/تدقيقي) أو nullOnDelete (مرجع منفّذ فقط).
    expect($foreignKeys)->toBeGreaterThan(30)->and($onDelete)->toBe($foreignKeys)->and($cascades)->toBe(1);

    // الإضافة على جداول المتجر أعمدة أعداد صحيحة بافتراضي صفر (لا تغيّر سعرًا ولا عملة).
    $store = e44_read('database/migrations/2026_12_14_000003_add_support_points_to_store_tables.php');
    expect($store)->toContain('support_points')->toContain('support_points_snapshot')->toContain('unsignedInteger')->toContain('->default(0)');
});

test('E44-G-10: the configuration is bounded integers and closed allow-lists, and the safe default is OFF for both flags', function () {
    $c = require base_path('config/seasonal_recognition.php');

    expect($c['writers']['enabled'])->toBeFalse()->and($c['supporters']['enabled'])->toBeFalse();

    expect($c['writers']['default_capacity'])->toBe(20)->and($c['writers']['max_capacity'])->toBe(100)->and($c['writers']['stars_per_voter'])->toBe(10)
        ->and($c['writers']['max_stars_per_voter'])->toBe(10)->and($c['writers']['min_close_before_end_days'])->toBe(7)->and($c['writers']['places'])->toBe(3)
        ->and($c['writers']['eliminated_count'])->toBe(5)->and($c['supporters']['leaderboard_limit'])->toBe(100)
        ->and($c['rewards']['kinds'])->toBe(['cosmetic', 'monetary'])->and($c['rewards']['currencies'])->toBe(['USD'])->and($c['social_queue']['statuses'])->toBe(['eligible', 'queued', 'published', 'skipped']);

    $tiers = $c['supporters']['card_tiers'];

    expect(array_keys($tiers))->toBe(['silver', 'gold', 'diamond'])
        ->and(array_column($tiers, 'price_minor'))->toBe([2500, 10000, 25000])
        ->and(array_column($tiers, 'support_points'))->toBe([250, 1000, 2500])
        ->and($c['supporters']['name_styles'])->toBe(['silver', 'gold', 'diamond'])
        ->and($c['supporters']['card_currency'])->toBe('USD');

    foreach ($tiers as $key => $tier) {
        expect($tier['price_minor'])->toBeInt()->and($tier['support_points'])->toBeInt()->and(in_array($tier['name_style_key'], $c['supporters']['name_styles'], true))->toBeTrue($key);
    }

    foreach (['limits'] as $section) {
        foreach ($c[$section] as $key => $value) {
            expect($value)->toBeInt()->toBeGreaterThan(0, "{$section}.{$key}");
        }
    }

    // الأسرار والروابط: لا قيم نصية حرة غير القوائم المغلقة وأسماء العرض.
    expect(json_encode($c))->not->toMatch('/https?:\/\//');
});

test('E44-G-11: the notification types exist, are documented, and the only E44 events are the two public aggregate-only signals', function () {
    $types = e44_read('app/Services/Notifications/NotificationType.php');

    foreach (['writer_final_standing', 'supporter_card_verified', 'supporter_final_rank'] as $type) {
        expect($types)->toContain($type)->and(e44_read('docs/notifications.md'))->toContain($type);
    }

    $events = array_map('basename', glob(base_path('app/Events/SeasonalRecognition/*.php')) ?: []);
    sort($events);

    expect($events)->toBe(['SeasonSupportersLeaderboardUpdated.php', 'SeasonWriterLeaderboardUpdated.php']);

    foreach ($events as $event) {
        $code = e44_code('app/Events/SeasonalRecognition/'.$event);

        expect($code)->toContain('new Channel(')->not->toContain('PrivateChannel')->not->toContain('PresenceChannel')->toContain("'leaderboard.updated'")->not->toContain('user_id')->not->toContain('voter');
    }
});

test('E44-G-12: no external social-network integration exists — the mention queue is manual admin state only', function () {
    $networks = ['facebook', 'instagram', 'twitter', 'tiktok', 'telegram', 'whatsapp', 'snapchat', 'linkedin', 'youtube', 'discord', 'graph.facebook', 'api.x.com'];

    foreach (array_merge(e44g_php(), ['resources/js/seasonal.js']) as $file) {
        $code = strtolower(str_ends_with($file, '.js') ? e44_read($file) : e44_code($file));

        foreach ($networks as $network) {
            expect(str_contains($code, $network))->toBeFalse("{$file}: {$network}");
        }
    }

    $queue = e44_code('app/Services/SeasonalRecognition/SeasonSupporterSocialQueueService.php');

    expect($queue)->toContain('SOCIAL_ELIGIBLE')->not->toContain('dispatch(')->not->toContain('Queue::')->not->toContain('Http');
});

test('E44-G-13: both documents exist, are substantial, cover every required topic, document every reason and finding code, and their links and anchors resolve', function () {
    foreach (E44G_DOCS as $doc) {
        expect(is_file(base_path($doc)))->toBeTrue($doc)->and(strlen(e44_read($doc)))->toBeGreaterThan(5000, "{$doc} looks thin");
    }

    $design = e44_read('docs/seasonal-writers-supporters-e44.md');

    foreach (['القرارات الملزمة', 'السعة', '100', 'الميزانية', '10 نجوم', 'التصويت لنفسه', 'seasonal-recognition.vote', '7 أيام', 'دفتر التوزيعات هو السلطة', 'SeasonWriterFinalizer',
        'خارج الموسم التالي', 'الاستقطاب', 'InventoryService', 'pending_finance', 'نقاط الدعم', 'ليست', 'مالًا', 'source_key', 'reverses_entry_id', 'لا تخمين', 'بطاقات الداعم', '25.00', '100.00',
        '250.00', '2500', '10000', '25000', 'E30', 'E32', 'SponsorshipPayment', 'رفع الإثبات لا يمنح شيئًا', 'داعم مجهول', 'data-season-style', 'داعم الموسم #N', 'طابور', 'لاعبون فقط',
        'أعلى 100', 'SeasonSupporterFinalizer', 'FinancialHistoryGuard', 'التزامن', 'upsert', 'FOR UPDATE', 'قفل الفجوة', 'seasonal.writers', 'seasonal.supporters', 'SEASON_WRITERS_ENABLED',
        'SEASON_SUPPORTERS_ENABLED', 'التعطيل يوقف الإجراءات الجديدة فقط', 'seasonal-writers.', 'leaderboard.updated', 'مرجع رموز الأسباب', 'concurrency-probe'] as $topic) {
        expect($design)->toContain($topic);
    }

    $runbook = e44_read('docs/seasonal-recognition-operations-runbook.md');

    foreach (['قبل التفعيل', 'دوري الكتّاب', 'الداعمون', 'المطابقة', 'seasonal-recognition:reconcile', 'الإيقاف الطارئ', 'استكشاف الأعطال', 'التحقق الحي من التزامن', 'concurrency-probe',
        'لا خيار إصلاح في الأمر عمدًا', 'RolesAndPermissionsSeeder', 'migrate', 'منح شراء فائت إلى هذا الموسم', 'تفعيل البطاقات المحقَّق دفعها', 'إعادة بناء'] as $topic) {
        expect($runbook)->toContain($topic);
    }

    $reasons = e44g_reason_codes();
    expect(count($reasons))->toBeGreaterThan(50);

    foreach ($reasons as $reason) {
        expect($design.$runbook)->toContain($reason);
    }

    $findings = e44g_finding_codes();
    expect(count($findings))->toBe(20);

    foreach ($findings as $code) {
        expect($runbook)->toContain($code);
    }

    $broken = [];

    foreach (E44G_DOCS as $doc) {
        preg_match_all('/\]\(([^)\s]+)\)/u', e44_read($doc), $m);

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

            if ($anchor !== null && ! in_array(rawurldecode($anchor), e44g_anchors(e44_read($file)), true)) {
                $broken[] = "{$doc} -> {$target} (missing anchor)";
            }
        }
    }

    expect($broken)->toBe([]);
});

test('E44-G-14: environment documentation lists exactly the two E44 variables (and .env.example none), and the docs carry no secret-like value', function () {
    foreach (E44G_DOCS as $doc) {
        preg_match_all('/^\s*(?:export\s+)?([A-Z][A-Z0-9_]*(?:PASSWORD|SECRET|TOKEN|KEY))=([^\s#`]+)/m', e44_read($doc), $matches, PREG_SET_ORDER);

        foreach ($matches as [$all, $name, $value]) {
            expect(preg_match('/^(<[^>]+>|\$\{?[A-Z_]+\}?|\.{3}|…|null|"")$/i', trim($value, '"\'')))->toBe(1, "{$doc}: {$name} has a non-placeholder value");
        }
    }

    // حارس E42 يمنع أي سطر SEASON*_ في .env.example (حتى المعلَّق): المتغيران موثَّقان في docs/environment-variables.md فقط.
    expect(e44_read('.env.example'))->not->toMatch('/SEASONS?_[A-Z_]*=/');

    $docs = e44_read('docs/environment-variables.md');

    foreach (['SEASON_WRITERS_ENABLED', 'SEASON_SUPPORTERS_ENABLED'] as $variable) {
        expect($docs)->toContain($variable);
    }

    preg_match_all('/env\(\s*\'([A-Z0-9_]+)\'/', e44_code('config/seasonal_recognition.php'), $used);
    expect(array_values(array_unique($used[1])))->toEqualCanonicalizing(['SEASON_WRITERS_ENABLED', 'SEASON_SUPPORTERS_ENABLED']);

    expect(e44_read('docs/production-deployment.md'))->toContain('seasonal-recognition:reconcile')->toContain('seasonal-recognition-operations-runbook.md');
});

test('E44-G-15: CI runs exactly one E44 MySQL schema step and one real-concurrency probe step, after E43, on the temporary test database only, without disturbing the E43 steps', function () {
    $ci = e44_read('.github/workflows/ci.yml');

    expect($ci)->toContain('tests/Feature/SeasonalRecognitionE44/E44MysqlSchemaTest.php')->toContain('php tools/e44/concurrency-probe.php --rounds=4')
        ->and(substr_count($ci, 'tests/Feature/SeasonalRecognitionE44'))->toBe(1)
        ->and(substr_count($ci, 'tools/e44/concurrency-probe.php'))->toBe(1)
        ->and(substr_count($ci, '- name: E44 seasonal writers league'))->toBe(1)
        ->and(substr_count($ci, '- name: E44 real-concurrency probe'))->toBe(1)
        // أخطاء الترتيب: المخطط بعد مخطط E43 وقبل مطابقة المخطط، والمسبار بعد مسبار E43 (آخر الخطوات).
        ->and(strpos($ci, 'E44MysqlSchemaTest.php'))->toBeGreaterThan(strpos($ci, 'E43MysqlSchemaTest.php'))
        ->and(strpos($ci, 'E44MysqlSchemaTest.php'))->toBeLessThan(strpos($ci, 'Schema parity against the migrated MySQL database'))
        ->and(strpos($ci, 'tools/e44/concurrency-probe.php'))->toBeGreaterThan(strpos($ci, 'tools/e43/concurrency-probe.php'))
        // خطوات E43 لم تتأثر (حارس E43 يطلب ظهورًا واحدًا لكلٍّ).
        ->and(substr_count($ci, 'tests/Feature/CreatorPremiumE43'))->toBe(1)
        ->and(substr_count($ci, 'tools/e43/concurrency-probe.php'))->toBe(1);

    // لا خطوة CI تشغّل مجلد E44 كله ولا مجموعة الاختبارات الكاملة (التراجع الواسع ملك CI الموجود).
    expect($ci)->not->toContain('tests/Feature/SeasonalRecognitionE44 ')->not->toContain('tests/Feature/SeasonalRecognitionE44/E44Voting');

    $probe = e44_read('tools/e44/concurrency-probe.php');

    expect($probe)->toContain('_test')->toContain('migrate:fresh')->toContain('exit(2)')->and(preg_match('/phpunit|pest\s/', $probe))->toBe(0)
        ->and($probe)->toContain('APP_ENV=production')->toContain('1213')->toContain('1205');
});

test('E44-G-16: the probe covers every race it promises (budget, re-allocation, many voters, opposite orders, withdrawal, finalizers, duplicate grant, parallel grants, duplicate reversal, finalize vs grants) and stays inside the safety fence', function () {
    $probe = e44_read('tools/e44/concurrency-probe.php');

    foreach (['W1:', 'W2:', 'W3:', 'W4:', 'W5:', 'W6:', 'S1:', 'S2:', 'S3:', 'S4:', 'S5:', 'R:'] as $scenario) {
        expect($probe)->toContain("echo \"\\n{$scenario}");
    }

    foreach (['SeasonWriterVotingService', 'SeasonWriterFinalizer', 'SeasonSupporterFinalizer', 'SeasonSupportPointService', 'SeasonWriterRosterService', 'SeasonWriterReconciler', 'SeasonSupporterReconciler'] as $service) {
        expect($probe)->toContain($service);
    }

    // لا يُشغَّل على بيئة الإنتاج ولا يمسّ كاش التطبيق ولا يستخدم شبكة.
    expect($probe)->toContain('e44-probe-cache')->not->toContain('Http::')->not->toContain('curl_');

    $code = e44_code('tools/e44/concurrency-probe.php');

    // الكتابة المباشرة الوحيدة المسموحة: محاكاة مرور الوقت (إغلاق النافذة) وإدراج محاولات اللعب (دليل الأهلية).
    preg_match_all("/DB::table\\('([a-z_]+)'\\)(?:->[a-zA-Z]+\\([^;]*?)?->(insert|update|delete|upsert)\\(/", $code, $writes, PREG_SET_ORDER);

    foreach ($writes as $w) {
        expect(in_array($w[1], ['community_puzzle_attempts', 'season_recognition_settings'], true))->toBeTrue("direct write to {$w[1]}");
    }
});

test('E44-G-17: no E44 delivery artifact leaked into the repository root (pack, zip, patch, manifest, overlay, install script, temp commands)', function () {
    // الفحص خاص بملفات تسليم E44 فقط. جذر المستودع قد يحوي ملفات تسليم تاريخية من مراحل سابقة (manifests وverify-eNN.ps1 وحزم قديمة)
    // وهي ليست من E44 ولا يجوز أن يفشل اختبار هذه المرحلة بسببها؛ والمرفوض هنا: كل ما يحمل اسم E44 أو أسماء مجلدات حزمة التسليم نفسها.
    $leakPattern = '/e44|^overlay$|^BASE-SHA256|^seasonal-recognition.*\.(patch|diff|zip|7z|tar|tgz|ps1)$/i';
    $leaks = [];

    foreach (glob(base_path('*')) ?: [] as $path) {
        $name = basename($path);

        if (preg_match($leakPattern, $name) === 1) {
            $leaks[] = $name;
        }
    }

    // اتساق المفتاح: المرفوض يُكشف على جذر المستودع بنمط يطابق أسماء ملفات الحزمة فعلًا (حارس ضد نمط ميّت لا يطابق شيئًا).
    foreach (['E44-seasonal-recognition-delivery.zip', 'E44-delivery', 'install-e44.ps1', 'overlay', 'e44-manifest-sha256.txt', 'BASE-SHA256.txt'] as $sample) {
        expect(preg_match($leakPattern, $sample))->toBe(1, "pattern misses {$sample}");
    }

    foreach (['MANIFEST-SHA256.txt', 'e26-manifest-sha256.txt', 'verify-e21.ps1', 'composer.json', 'artisan'] as $legacyOrNormal) {
        expect(preg_match($leakPattern, $legacyOrNormal))->toBe(0, "pattern wrongly flags {$legacyOrNormal}");
    }

    foreach (['tools/e44'] as $dir) {
        // أداة E44 الوحيدة المسموحة: مسبار التزامن (ليست ملف تسليم).
        expect(array_map('basename', glob(base_path($dir).'/*') ?: []))->toBe(['concurrency-probe.php']);
    }

    expect($leaks)->toBe([]);
});

