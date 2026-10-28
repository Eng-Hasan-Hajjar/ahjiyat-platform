<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| E43 (7/9) - حراس البنية والأمان والوثائق وCI: PSR/namespace، لا حزم ولا شبكة ولا أسرار، نظافة الشيفرة (E26)، عزل الاقتصاد (جواهر/XP/مسار/موسم/تنافس/إحالة/رعاية/سمعة/صرف)،
| حارس مسارات E18، لا صلاحيات جديدة غير الثلاث، الترحيلات بأسماء فهارس صريحة وRESTRICT، الإعداد، الأمر، تسجيل حارس التاريخ المالي (E31)، الوثائق وروابطها، وخطوات CI.
|--------------------------------------------------------------------------
*/

const E43_DOCS = ['docs/creator-premium-marketplace-e43.md', 'docs/creator-premium-operations-runbook.md'];

const E43_TABLES = ['creator_series_sale_offers', 'creator_series_accesses', 'creator_direct_sales'];

function e43g_read(string $relative): string
{
    return (string) file_get_contents(base_path($relative));
}

/** الكود بلا تعليقات (لمسح الرموز لا النصوص الشارحة). */
function e43g_code(string $relative): string
{
    $code = '';

    foreach (token_get_all(e43g_read($relative)) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $code .= is_array($token) ? $token[1] : $token;
    }

    return $code;
}

/** @return list<string> */
function e43g_services(): array
{
    return array_map(fn ($p) => str_replace(base_path().DIRECTORY_SEPARATOR, '', $p), glob(base_path('app/Services/CreatorPremium').'/*.php') ?: []);
}

/** @return list<string> ملفات PHP الجديدة الخاصة بالسوق المميز. */
function e43g_new_php(): array
{
    return array_merge(e43g_services(), [
        'app/Models/CreatorSeriesSaleOffer.php', 'app/Models/CreatorSeriesAccess.php', 'app/Models/CreatorDirectSale.php',
        'app/Http/Controllers/CreatorPremium/CreatorPremiumStudioController.php', 'app/Http/Controllers/CreatorPremium/SeriesPremiumOrderController.php',
        'app/Filament/Pages/CreatorPremiumPage.php', 'app/Console/Commands/ReconcileCreatorPremium.php', 'database/seeders/DemoE43Seeder.php', 'config/creator_premium.php',
    ]);
}

function e43g_views(): array
{
    return ['resources/views/creator/premium/index.blade.php', 'resources/views/filament/pages/creator-premium.blade.php'];
}

function e43g_slug(string $heading): string
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
function e43g_anchors(string $markdown): array
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
            $slug = e43g_slug($m[1]);
            $n = $seen[$slug] ?? 0;
            $seen[$slug] = $n + 1;
            $anchors[] = $n === 0 ? $slug : "{$slug}-{$n}";
        }
    }

    return $anchors;
}

/** كل رمز سبب تُرميه خدمات السوق المميز (استثناء مجال أو حجب تنفيذ). @return list<string> */
function e43g_reason_codes(): array
{
    $codes = [];

    foreach (e43g_services() as $file) {
        preg_match_all("/new (?:CreatorPremiumException|CommerceFulfillmentException)\\('([a-z_]+)'/", e43g_code($file), $m);
        $codes = array_merge($codes, $m[1]);
    }

    return array_values(array_unique($codes));
}

test('E43-guard-1: every new premium class is PSR-4 compliant and every expected artifact exists', function () {
    $violations = [];

    foreach (e43g_new_php() as $file) {
        if (! str_starts_with($file, 'app/')) {
            continue;
        }

        $source = e43g_read($file);
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
        'database/migrations/2026_12_07_000001_create_creator_series_sale_offers_table.php', 'database/migrations/2026_12_07_000002_create_creator_series_accesses_table.php',
        'database/migrations/2026_12_07_000003_create_creator_direct_sales_table.php', 'config/creator_premium.php', 'tools/e43/concurrency-probe.php', 'database/seeders/DemoE43Seeder.php',
        'tests/Feature/CreatorPremiumE43/E43MysqlSchemaTest.php', ...E43_DOCS,
    ] as $file) {
        expect(is_file(base_path($file)))->toBeTrue($file);
    }

    expect($violations)->toBe([]);
});

test('E43-guard-2: no new package, no outbound network call and no real credential in the premium code', function () {
    $violations = [];

    foreach (array_merge(e43g_new_php(), ['tools/e43/concurrency-probe.php']) as $file) {
        $code = e43g_code($file);

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

test('E43-guard-3: premium code is clean - no debug leftovers, no env() outside config, no raw SQL built from variables, no unescaped output, guarded models', function () {
    $violations = [];

    foreach (e43g_new_php() as $file) {
        $code = e43g_code($file);

        if (preg_match('/\b(dd|dump|var_dump|print_r|ray)\s*\(/', $code) === 1 || str_contains($code, 'console.log')) {
            $violations[] = "{$file}: debug";
        }

        if ($file !== 'config/creator_premium.php' && preg_match('/\benv\s*\(/', $code) === 1) {
            $violations[] = "{$file}: env() outside config";
        }

        if (preg_match('/(?:DB::raw|whereRaw|orWhereRaw|selectRaw|orderByRaw|havingRaw|groupByRaw|DB::statement|DB::unprepared)\(\s*(?:"[^"]*\$|"[^"]*"\s*\.|\'[^\']*\'\s*\.|\$)/', $code) === 1) {
            $violations[] = "{$file}: raw SQL with variables";
        }
    }

    foreach (e43g_views() as $view) {
        $source = e43g_read($view);

        if (str_contains($source, '{!!') || preg_match('/console\.log|<script[^>]*>[^<]*\bdd\(/', $source) === 1) {
            $violations[] = "{$view}: unescaped output / debug";
        }
    }

    foreach (['app/Models/CreatorSeriesSaleOffer.php', 'app/Models/CreatorSeriesAccess.php', 'app/Models/CreatorDirectSale.php'] as $model) {
        expect(e43g_read($model))->toContain("protected \$guarded = ['*'];");
    }

    expect($violations)->toBe([]);
});

test('E43-guard-4: the marketplace owns no foreign economy - it never touches gems, wallets, XP, pass, season, competitive rewards, referrals, reputation, sponsorship money or payouts', function () {
    $forbidden = ['GemWalletService', 'PendingGemReleaseService', 'RedemptionService', 'Services\\Economy', 'Services\\Progression', 'Services\\Store', 'Services\\Pass', 'Services\\Seasons', 'Services\\Competitive',
        'Services\\Referral', 'Services\\Reputation', 'SponsorshipPayment', 'SponsorshipContribution', 'AttemptRewardResolver', 'PassClaimService', 'PassPointService', 'PassPremiumService',
        'CurrencyTransaction', 'XpTransaction', 'Wallet', 'CreatorPayout', 'CreatorRevenuePeriod', 'RevenueAllocator'];
    $violations = [];

    foreach (array_merge(e43g_services(), ['app/Http/Controllers/CreatorPremium/CreatorPremiumStudioController.php', 'app/Http/Controllers/CreatorPremium/SeriesPremiumOrderController.php',
        'app/Models/CreatorSeriesSaleOffer.php', 'app/Models/CreatorSeriesAccess.php', 'app/Models/CreatorDirectSale.php']) as $file) {
        $code = e43g_code($file);

        foreach ($forbidden as $needle) {
            if (str_contains($code, $needle)) {
                $violations[] = "{$file}: {$needle}";
            }
        }
    }

    expect($violations)->toBe([]);
});

test('E43-guard-5: the order route accepts no money, currency, creator or purchase id from the client, and the client never writes through a GET', function () {
    $controller = e43g_code('app/Http/Controllers/CreatorPremium/SeriesPremiumOrderController.php');

    foreach (['price', 'amount', 'currency', 'creator', 'purchase_id', 'share', 'bps'] as $field) {
        expect(preg_match("/input\\(\\s*'{$field}[a-z_]*'/", $controller))->toBe(0, $field);
    }

    expect($controller)->toContain("input('request_key')");

    $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => in_array((string) $r->getName(), ['series.premium.order'], true) || str_starts_with((string) $r->getName(), 'creator.premium.'));

    expect($routes->map->getName()->sort()->values()->all())->toBe(['creator.premium.index', 'creator.premium.offers.activate', 'creator.premium.offers.pause', 'creator.premium.offers.price',
        'creator.premium.offers.retire', 'creator.premium.offers.store', 'series.premium.order']);

    foreach ($routes as $route) {
        $name = (string) $route->getName();
        $writes = array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) !== [];

        expect($writes)->toBe($name !== 'creator.premium.index', $name)
            ->and(preg_match('/reward|grant|claim|payout|prize/i', $name.' '.$route->uri()))->toBe(0, $name)
            ->and(in_array('auth', $route->gatherMiddleware(), true))->toBeTrue($name);
    }
});

test('E43-guard-6: the only permissions are creator-premium.view|manage|refund, granted to the administrator and never to ordinary staff', function () {
    $seeder = e43g_read('database/seeders/RolesAndPermissionsSeeder.php');
    preg_match_all("/'(creator-premium\\.[a-z]+)'/", $seeder, $declared);

    expect(array_values(array_unique($declared[1])))->toEqualCanonicalizing(['creator-premium.view', 'creator-premium.manage', 'creator-premium.refund']);

    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

    foreach (['creator-premium.view', 'creator-premium.manage', 'creator-premium.refund'] as $permission) {
        expect(\Spatie\Permission\Models\Role::findByName('administrator')->hasPermissionTo($permission))->toBeTrue($permission)
            ->and(\Spatie\Permission\Models\Role::findByName('moderator')->hasPermissionTo($permission))->toBeFalse($permission);
    }

    $used = [];

    foreach (array_merge(e43g_new_php(), ['tools/e43/concurrency-probe.php']) as $file) {
        preg_match_all("/(?:can|cannot|canAny|authorize|hasPermissionTo|givePermissionTo)\\([^)]*'(creator-premium\\.[a-z_]+)'/", e43g_code($file), $m);
        $used = array_merge($used, $m[1]);
    }

    expect(array_values(array_diff(array_unique($used), ['creator-premium.view', 'creator-premium.manage', 'creator-premium.refund'])))->toBe([]);
});

test('E43-guard-7: the reconcile command is registered, read-only and without any repair or force switch', function () {
    $commands = Artisan::all();

    expect(array_keys($commands))->toContain('creator-premium:reconcile')
        ->and(array_keys($commands['creator-premium:reconcile']->getDefinition()->getOptions()))->not->toContain('force')->not->toContain('repair')->not->toContain('fix');

    $code = e43g_code('app/Services/CreatorPremium/CreatorPremiumReconciler.php');

    foreach (['->update(', '->insert(', '->delete(', 'forceFill', '->save(', 'lockForUpdate', 'DB::transaction'] as $write) {
        expect($code)->not->toContain($write);
    }
});

test('E43-guard-8: the migrations are additive, use explicit bounded index names, RESTRICT every financial/history foreign key, and use no float or decimal', function () {
    $files = glob(base_path('database/migrations/2026_12_07_00000*.php'));
    expect(count($files))->toBe(3);

    foreach ($files as $file) {
        $source = file_get_contents($file);
        preg_match_all("/->(?:unique|index)\\(\\[[^\\]]+\\],\\s*'([^']+)'\\)/", $source, $arrayForm);
        preg_match_all("/->(?:unique|index)\\('([^']+)'\\)/", $source, $nameForm);
        preg_match_all("/->(?:unique|index)\\('[^']+',\\s*'([^']+)'\\)/", $source, $columnAndName);
        $names = array_merge($arrayForm[1], $nameForm[1], $columnAndName[1]);

        foreach ($names as $name) {
            expect(strlen($name))->toBeLessThanOrEqual(64, $name);
        }

        expect(preg_match_all('/->(?:unique|index)\(/', $source))->toBe(count($names), basename($file))
            ->and($source)->not->toContain('->change()')->not->toContain('->decimal(')->not->toContain('->float(')->not->toContain('->double(')->not->toContain('cascadeOnDelete')
            ->and(preg_match_all('/->foreignId\(/', $source))->toBe(preg_match_all('/->(nullOnDelete|restrictOnDelete)\(/', $source), basename($file));
    }
});

test('E43-guard-9: the live database agrees - every foreign key of the three tables on users, purchases, profiles, series, accounts and ledger entries is restrictive, and the history guard lists the history tables', function () {
    foreach (E43_TABLES as $table) {
        $foreignKeys = Schema::getForeignKeys($table);
        expect($foreignKeys)->not->toBeEmpty();

        foreach ($foreignKeys as $fk) {
            $isActorReference = in_array($fk['columns'], [['paused_by'], ['review_resolved_by']], true);

            expect(in_array(strtolower((string) $fk['on_delete']), $isActorReference ? ['set null'] : ['restrict', 'no action'], true))->toBeTrue("{$table}.".implode(',', $fk['columns']).' on_delete='.$fk['on_delete']);
        }
    }

    $guard = \App\Services\Database\FinancialHistoryGuard::HISTORY_TABLES;

    expect($guard)->toHaveKey('creator_series_accesses')->toHaveKey('creator_direct_sales')->and($guard['creator_series_accesses'][0])->toBe('user_id')->and($guard['creator_direct_sales'][0])->toBe('user_id');
});

test('E43-guard-10: the configuration is bounded integers and closed allow-lists, and the safe default is OFF', function () {
    $c = require base_path('config/creator_premium.php');

    expect($c['enabled'])->toBeFalse();

    $share = $c['sales']['creator_share_bps'];
    $platform = $c['sales']['platform_share_bps'];

    expect($share)->toBeInt()->toBeGreaterThan(0)->toBeLessThanOrEqual(10000)->and($share + $platform)->toBe(10000)->and($c['sales']['rounding_rule'])->toBe('creator_floor')
        ->and($c['sales']['min_price_minor'])->toBeInt()->toBeGreaterThan(0)->and($c['sales']['max_price_minor'])->toBeGreaterThan($c['sales']['min_price_minor'])
        ->and(array_keys($c['refund']['reasons']))->toBe(['user_request', 'duplicate', 'payment_issue', 'content_issue', 'fraud', 'error', 'other']);

    foreach ($c['limits'] as $key => $value) {
        expect($value)->toBeInt()->toBeGreaterThan(0, "limits.{$key}");
    }
});

test('E43-guard-11: the commerce product registration is the single generic type and the notification types exist', function () {
    expect(config('commerce.products'))->toBeArray();

    $handlers = e43g_read('config/commerce.php');
    expect($handlers)->toContain('creator_series_access');

    $types = e43g_read('app/Services/Notifications/NotificationType.php');

    foreach (['premium_series_access_granted', 'creator_premium_sale', 'creator_premium_sale_adjusted'] as $type) {
        expect($types)->toContain($type);
    }
});

test('E43-guard-12: both documents exist, are substantial, cover every required topic, document every reason and finding code, and their links and anchors resolve', function () {
    foreach (E43_DOCS as $doc) {
        expect(is_file(base_path($doc)))->toBeTrue($doc)->and(strlen(e43g_read($doc)))->toBeGreaterThan(6000, "{$doc} looks thin");
    }

    $design = e43g_read('docs/creator-premium-marketplace-e43.md');

    foreach (['الوصول الدائم', 'عرض البيع', 'الحماية عند نقاط المحتوى', 'التقادم', 'الخيار A', 'لا تحويل عملات', 'قاعدة القسمة', 'floor', 'الباقي للمنصة', 'مفتاح المصدر', 'معاملة التحقق الواحدة',
        'الاسترداد', 'needs_finance_review', 'تحميل المنصة', 'صندوق المبدعين', 'سجل المشتريات', 'الخصوصية', 'المصالحة', 'creator-premium:reconcile', 'CREATOR_PREMIUM_ENABLED', 'creator_series_access',
        'CreatorSeriesAccessProductHandler', 'CreatorPremiumAccess', 'PremiumLockQuery', 'FinancialHistoryGuard', 'DemoE43Seeder', 'غير متاح بعد', 'E30', 'E39', 'E41', 'direct_sale_credit', 'direct_sale_reversal'] as $topic) {
        expect($design)->toContain($topic);
    }

    $runbook = e43g_read('docs/creator-premium-operations-runbook.md');

    foreach (['الفحص السريع', 'إيقاف البيع', 'تعطيل الميزة', 'الاسترداد', 'استرداد بعد لعب المشتري', 'حصة المبدع المدفوعة', 'إعادة محاولة العكس', 'تحميل المنصة', 'علم المراجعة', 'دفعة متأخرة',
        'المصالحة', 'التزامن الحي', 'حوادث شائعة', 'الخصوصية', 'creator-premium:reconcile', 'concurrency-probe'] as $topic) {
        expect($runbook)->toContain($topic);
    }

    foreach (e43g_reason_codes() as $reason) {
        expect($design.$runbook)->toContain($reason);
    }

    preg_match_all("/this->finding\\('([a-z_]+)'/", e43g_code('app/Services/CreatorPremium/CreatorPremiumReconciler.php'), $findings);

    foreach (array_unique($findings[1]) as $code) {
        expect($runbook)->toContain($code);
    }

    $broken = [];

    foreach (E43_DOCS as $doc) {
        preg_match_all('/\]\(([^)\s]+)\)/u', e43g_read($doc), $m);

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

            if ($anchor !== null && ! in_array(rawurldecode($anchor), e43g_anchors(e43g_read($file)), true)) {
                $broken[] = "{$doc} -> {$target} (missing anchor)";
            }
        }
    }

    expect($broken)->toBe([]);
});

test('E43-guard-13: environment documentation lists exactly the four new variables as commented placeholders and the docs carry no secret-like value', function () {
    foreach (E43_DOCS as $doc) {
        preg_match_all('/^\s*(?:export\s+)?([A-Z][A-Z0-9_]*(?:PASSWORD|SECRET|TOKEN|KEY))=([^\s#`]+)/m', e43g_read($doc), $matches, PREG_SET_ORDER);

        foreach ($matches as [$all, $name, $value]) {
            expect(preg_match('/^(<[^>]+>|\$\{?[A-Z_]+\}?|\.{3}|…|null|"")$/i', trim($value, '"\'')))->toBe(1, "{$doc}: {$name} has a non-placeholder value");
        }
    }

    $env = e43g_read('.env.example');
    $docs = e43g_read('docs/environment-variables.md');

    foreach (['CREATOR_PREMIUM_ENABLED', 'CREATOR_PREMIUM_CREATOR_SHARE_BPS', 'CREATOR_PREMIUM_MIN_PRICE_MINOR', 'CREATOR_PREMIUM_MAX_PRICE_MINOR'] as $variable) {
        expect($env)->toContain('# '.$variable.'=')->and($docs)->toContain($variable);
    }

    // لا يظهر أي متغير جديد غير الأربعة.
    preg_match_all('/CREATOR_PREMIUM_[A-Z0-9_]+/', e43g_code('config/creator_premium.php'), $used);
    expect(array_values(array_unique($used[0])))->toEqualCanonicalizing(['CREATOR_PREMIUM_ENABLED', 'CREATOR_PREMIUM_CREATOR_SHARE_BPS', 'CREATOR_PREMIUM_MIN_PRICE_MINOR', 'CREATOR_PREMIUM_MAX_PRICE_MINOR']);

    expect(e43g_read('docs/notifications.md'))->toContain('premium_series_access_granted')->toContain('creator_premium_sale')->toContain('creator_premium_sale_adjusted')
        ->and(e43g_read('docs/production-deployment.md'))->toContain('creator-premium:reconcile');
});

test('E43-guard-14: CI runs exactly the E43 MySQL schema test and the real-concurrency probe, after E42, on the temporary test database only, without duplicating historical steps', function () {
    $ci = e43g_read('.github/workflows/ci.yml');

    expect($ci)->toContain('tests/Feature/CreatorPremiumE43/E43MysqlSchemaTest.php')->toContain('php tools/e43/concurrency-probe.php --rounds=4')
        ->and(substr_count($ci, 'tests/Feature/CreatorPremiumE43'))->toBe(1)
        ->and(substr_count($ci, 'tools/e43/concurrency-probe.php'))->toBe(1)
        ->and(strpos($ci, 'tools/e43/concurrency-probe.php'))->toBeGreaterThan(strpos($ci, 'tools/e42/concurrency-probe.php'))
        ->and(strpos($ci, 'E43MysqlSchemaTest.php'))->toBeGreaterThan(strpos($ci, 'E42MysqlSchemaTest.php'));

    $probe = e43g_read('tools/e43/concurrency-probe.php');

    expect($probe)->toContain('_test')->toContain('migrate:fresh')->and(preg_match('/phpunit|pest\s/', $probe))->toBe(0);
});

test('E43-guard-15: no root delivery artifact leaked into the repository (pack, zip, patch, manifest, overlay, temp commands)', function () {
    // ملفات patch التاريخية المحفوظة في جذر المستودع من مراحل سابقة (مُلتزَم بها على main قبل E43) — مستثناة بالاسم لا بالنمط،
    // فأي ترقيع جديد في الجذر يظل مرفوضًا.
    $legacy = ['e24-performance.patch', 'e25-operations.patch', 'e26-security.patch', 'e31-e32-hotfix-mysql-critical.patch'];
    $leaks = [];

    foreach (glob(base_path('*')) ?: [] as $path) {
        $name = basename($path);

        if (in_array($name, $legacy, true)) {
            continue;
        }

        // ملفات الجذر التاريخية (E22/E23...) سابقة لهذه المرحلة؛ الممنوع هنا ما يخص E43 أو أي ترقيع/تراكب/ملف FIX جديد.
        if (preg_match('/e43|\.(patch|diff)$|^FIX|^overlay/i', $name) === 1) {
            $leaks[] = $name;
        }
    }

    expect($leaks)->toBe([]);
});
