<?php

use Illuminate\Support\Facades\Route;

require_once __DIR__.'/_support.php';

/*
| E32 - حراس ثابتة (لا تحتاج بيانات): الوثائق، البيئة، الحزم، المسارات، الواجهات، المخطط، وقواعد التسجيل. أي كسر هنا يعني انحرافًا عن عقد E32.
*/

function e32_file(string $relative): string
{
    return (string) file_get_contents(base_path($relative));
}

/** @return list<string> */
function e32_php_files(string $relativeDir): array
{
    $out = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($relativeDir), FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
            $out[] = $file->getPathname();
        }
    }

    return $out;
}

test('E32-S1: .env.example documents every PAYMENTS_* variable the config reads, with placeholders only and no bank details or secrets', function () {
    $env = e32_file('.env.example');
    $config = e32_file('config/payments.php');
    preg_match_all("/env\\('(PAYMENTS_[A-Z_]+)'/", $config, $m);
    $keys = array_unique($m[1]);

    expect(count($keys))->toBeGreaterThanOrEqual(14);
    foreach ($keys as $key) {
        expect($env)->toContain($key)->and(e32_file('docs/environment-variables.md'))->toContain('`'.$key.'`');
    }

    foreach (['/\b[A-Z]{2}\d{2}[A-Z0-9]{10,30}\b/', '/sk_live_|pk_live_|whsec_/', '/\b(?:\d[ -]?){15,16}\b/'] as $pattern) {
        preg_match_all('/^#?\s*PAYMENTS_[A-Z_]+=.*$/m', $env, $lines);
        expect(preg_match($pattern, implode("\n", $lines[0])))->toBe(0, "PAYMENTS_ lines match {$pattern}");
    }

    expect($env)->not->toMatch('/^PAYMENTS_[A-Z_]*(SECRET|KEY|TOKEN|PASSWORD)[A-Z_]*=/m');
});

test('E32-S2: the E32 documents exist with their core sections and claim nothing that was not done', function () {
    $doc = e32_file('docs/sponsorship-payments-e32.md');
    $runbook = e32_file('docs/payment-operations-runbook.md');

    foreach (['المبادئ الصلبة', 'آلة الحالات', 'بنية المزوّدين', 'مسار Webhook المستقبلي', 'إضافة مزوّد إلكتروني لاحقًا', 'التحقق وتجسيد المساهمة', 'الإثبات', 'المطابقة المالية', 'ما لم يُنفَّذ عمدًا', 'MySQL', 'USER-LOCAL MYSQL VALIDATION REQUIRED', 'رحلة الداعم'] as $needle) {
        expect($doc)->toContain($needle);
    }

    foreach (['التحقق (Verify)', 'الرفض (Reject)', 'الاسترداد (Refund)', 'المطابقة المالية', 'تحويل مكرر', 'إثبات مكرر', 'إثبات مفقود', 'اشتباه احتيال', 'حادثة بوابة دفع', 'مال وصل لطلب منتهٍ'] as $needle) {
        expect($runbook)->toContain($needle);
    }

    expect($doc)->not->toContain('تم اختبار MySQL')->and($doc)->toContain('لا بوابة دفع حيّة');
});

test('E32-S3: no payment SDK or card library was added to composer or npm', function () {
    $composer = strtolower(e32_file('composer.json'));
    $package = strtolower(e32_file('package.json'));

    foreach (['stripe', 'paypal', 'paytabs', 'hyperpay', 'myfatoorah', 'braintree', 'mollie', 'razorpay', 'square', 'adyen', 'checkout.com', 'tap-payments', 'cashier'] as $sdk) {
        expect($composer)->not->toContain($sdk)->and($package)->not->toContain($sdk);
    }
});

test('E32-S4: every mutating support route is inside the web group (session + CSRF) behind auth; the webhook is POST-only, throttled, in the api group and not CSRF-excluded', function () {
    $checked = 0;

    foreach (Route::getRoutes() as $route) {
        $name = (string) $route->getName();
        $mutating = array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) !== [];

        if (str_starts_with($name, 'support.') && $mutating) {
            $checked++;
            expect($route->gatherMiddleware())->toContain('web')->toContain('auth')->and(collect($route->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_starts_with($m, 'throttle:')))->toBeTrue("{$name} is not throttled");
        }
    }

    expect($checked)->toBeGreaterThanOrEqual(4);

    $webhook = Route::getRoutes()->getByName('payments.webhook');
    expect($webhook)->not->toBeNull()->and($webhook->uri())->toStartWith('api/')->and($webhook->methods())->toBe(['POST'])->and($webhook->gatherMiddleware())->toContain('api')->and($webhook->gatherMiddleware())->toContain('throttle:payment-webhook')
        ->and($webhook->gatherMiddleware())->not->toContain('web');

    $bootstrap = e32_file('bootstrap/app.php');
    expect($bootstrap)->not->toMatch('/validateCsrfTokens\s*\(\s*except/');
    expect(file_exists(base_path('app/Http/Middleware/VerifyCsrfToken.php')) ? e32_file('app/Http/Middleware/VerifyCsrfToken.php') : '')->not->toContain('payments');
});

test('E32-S5: the support views use escaped output only and never promise payment before verification', function () {
    foreach (glob(base_path('resources/views/support/*.blade.php')) as $view) {
        $code = (string) file_get_contents($view);
        expect($code)->not->toContain('{!!')->and($code)->not->toContain('@include(\'partials.raw');
    }

    $payment = (string) preg_replace('/\{\{--.*?--\}\}/s', '', e32_file('resources/views/support/payment.blade.php'));
    expect($payment)->toContain('بانتظار التحقق')->and($payment)->not->toContain('تم الدفع')->and($payment)->not->toContain('نجحت عملية الدفع');
});

test('E32-S6: the payments schema stores no card data, secrets or floats; money is unsigned integers; index names fit MySQL', function () {
    $migration = e32_file('database/migrations/2026_10_24_000001_create_sponsorship_payments_table.php');

    foreach (['float(', 'double(', 'decimal(', '->json(', 'pan', 'cvv', 'cvc', 'card_number', 'password', 'secret', 'token', 'webhook_body', 'raw_payload', 'email'] as $forbidden) {
        // التعليقات العربية مستثناة: نفحص أسطر الكود فقط
        $code = implode("\n", array_filter(explode("\n", $migration), fn ($l) => str_contains($l, '$table->')));
        expect(str_contains(strtolower($code), '$table->'.$forbidden) || preg_match('/\$table->\w+\(\''.preg_quote($forbidden, '/').'/', $code) === 1)->toBeFalse("column pattern {$forbidden}");
    }

    expect($migration)->toContain("unsignedBigInteger('amount_minor')")->and($migration)->toContain("unsignedBigInteger('accounting_amount_minor')");
    preg_match_all("/,\\s*'([a-z0-9_]+)'\\)/", $migration, $names);
    foreach ($names[1] as $name) {
        expect(strlen($name))->toBeLessThanOrEqual(64);
    }
});

test('E32-S7: payment code never reads whole files, echoes raw requests, logs sensitive words or interpolates SQL', function () {
    $files = array_merge(e32_php_files('app/Services/Payments'), e32_php_files('app/Http/Controllers/Support'), e32_php_files('app/Http/Requests/Support'), e32_php_files('app/Console/Commands'), e32_php_files('app/Filament/Resources/SponsorshipPaymentResource'));
    $files[] = base_path('app/Filament/Resources/SponsorshipPaymentResource.php');
    $files[] = base_path('app/Filament/Pages/PaymentSettingsPage.php');

    foreach ($files as $file) {
        $code = (string) file_get_contents($file);
        $stripped = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $code);

        expect($stripped)->not->toMatch('/file_get_contents\s*\(|fopen\s*\(\s*\$|readfile\s*\(/')
            ->and($stripped)->not->toMatch('/\$request->all\(\)|request\(\)->all\(\)|Request::all\(/')
            ->and($stripped)->not->toMatch('/DB::raw\([^)]*\$|whereRaw\([^)]*\{\$|selectRaw\([^)]*\{\$/')
            ->and($stripped)->not->toMatch('/(Log::\w+|logger)\([^;]*(password|secret|token|proof|cvv|iban|email)/i')
            ->and($stripped)->not->toMatch('/\b(dd|dump|var_dump|print_r)\s*\(/');
    }
});

test('E32-S8: only confirmed contributions feed funding, tiers, the leaderboard and the boost - the E30 read side never touches payment requests', function () {
    foreach (array_merge(e32_php_files('app/Services/Sponsorship')) as $file) {
        $code = (string) file_get_contents($file);
        $name = basename($file);

        if ($name === 'ContributionService.php') {
            // الاستثناء الوحيد: خطاف مزامنة الاسترداد (وجهة الطلب بعد استرداد المساهمة) داخل معاملة الاسترداد.
            expect(substr_count($code, 'SponsorshipPaymentService'))->toBeLessThanOrEqual(3);

            continue;
        }

        if ($name === 'SponsorshipNotifier.php') {
            continue;   // إشعارات صاحب الطلب (E32) تقرأ كائن الطلب الممرَّر فقط
        }

        expect($code)->not->toContain('sponsorship_payments')->and($code)->not->toContain('SponsorshipPayment::');
    }

    foreach (['SupportTotals', 'SupporterTierService', 'SupporterLeaderboardService', 'SponsorshipRewardService'] as $class) {
        expect(e32_file("app/Services/Sponsorship/{$class}.php"))->not->toContain('SponsorshipPayment');
    }
});

test('E32-S9: only the manual provider exists, webhooks are closed by default and the proof disk default is private', function () {
    $providers = array_keys((array) config('payments.providers'));
    expect($providers)->toBe(['manual']);

    $source = e32_file('config/payments.php');
    expect($source)->toContain("env('PAYMENTS_WEBHOOKS_ENABLED', false)")->and($source)->toContain("env('PAYMENTS_PROOF_DISK', 'local')")->and($source)->toContain("env('PAYMENTS_DEFAULT_PROVIDER', 'manual')");

    foreach (['PAYMENTS_ENABLED', 'PAYMENTS_WEBHOOKS_ENABLED', 'PAYMENTS_PROOF_DISK', 'PAYMENTS_PROOF_MAX_KB'] as $key) {
        expect($source)->toContain($key);
    }

    expect(config('filesystems.disks.local.visibility'))->not->toBe('public');
});

test('E32-S10: community puzzles and creator series are not part of the payment surface', function () {
    foreach (array_merge(e32_php_files('app/Services/Payments'), e32_php_files('app/Http/Controllers/Support')) as $file) {
        $code = (string) file_get_contents($file);
        expect($code)->not->toContain('CommunityPuzzle')->and($code)->not->toContain('CreatorSeries')->and($code)->not->toContain('creator_series');
    }

    foreach (array_keys(Route::getRoutes()->getRoutesByName()) as $name) {
        if (str_starts_with((string) $name, 'support.')) {
            expect($name)->not->toContain('series')->and($name)->not->toContain('community');
        }
    }
});

test('E32-S11: the real-concurrency probe is a read-only-to-production tool - hard guards, disposable test database only, no secrets in output', function () {
    $source = e32_file('tools/e32/concurrency-probe.php');

    expect($source)->toContain("app()->environment('production')")
        ->and($source)->toContain("['mysql', 'mariadb']")
        ->and($source)->toContain('_(mysql|mariadb))?$/')
        ->and($source)->toContain('migrate:fresh')
        ->and($source)->toContain('exit(2)');

    // الحارس يسبق أي كتابة: استدعاء probe_guard() الفعلي يأتي قبل migrate:fresh
    expect(strpos($source, 'if (($error = probe_guard()) !== null)'))->toBeLessThan(strpos($source, "Artisan::call('migrate:fresh'"));

    // لا يقرأ كلمة المرور ولا يطبع مضيفًا ولا رسائل SQL الخام
    expect($source)->not->toContain(".password")->and($source)->not->toContain("['password']")->and($source)->not->toContain('.host')
        ->and($source)->not->toContain('getTraceAsString');

    // الاسم المسموح: _test | _testing | _test_mysql | _test_mariadb فقط
    $pattern = '/(^|_)(test|testing)(_(mysql|mariadb))?$/';
    foreach (['ahjiyat_platform_test_mysql', 'ahjiyat_platform_test', 'x_testing'] as $ok) {
        expect(preg_match($pattern, $ok))->toBe(1);
    }
    foreach (['ahjiyat_platform', 'ahjiyat_platform_production', 'test_data', 'contest'] as $bad) {
        expect(preg_match($pattern, $bad))->toBe(0);
    }
});

test('E32-S12: CI runs the payment suite, the engagement suite and the real-concurrency probe against MySQL with test-only credentials', function () {
    $ci = preg_replace('/^\s*#.*$/m', '', e32_file('.github/workflows/ci.yml'));

    expect($ci)->toContain('--configuration=phpunit.mysql.xml.dist tests/Feature/PaymentsE32')
        ->and($ci)->toContain('tests/Feature/EngagementE29')
        ->and($ci)->toContain('php tools/e32/concurrency-probe.php')
        ->and($ci)->not->toContain('secrets.');

    // الخطوة تحمل DB_CONNECTION: mysql صراحةً (المسبار يرفض أي سائق غير MySQL) ولها مهلة
    $position = strpos($ci, 'php tools/e32/concurrency-probe.php');
    $window = substr($ci, max(0, $position - 220), 220);
    expect($window)->toContain('DB_CONNECTION: mysql')->and($window)->toContain('timeout-minutes');
});
