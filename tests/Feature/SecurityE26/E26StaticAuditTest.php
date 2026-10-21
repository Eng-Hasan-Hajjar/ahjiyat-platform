<?php

require_once __DIR__.'/_support.php';

/*
| E26 (W1-W10) - تدقيقات ساكنة تمنع عودة الأنماط الخطرة: HTML خام، mass assignment، SQL مبني من مدخلات، تنفيذ أوامر، أسرار بالمستودع، تصحيح متروك.
| تقرأ الملفات فقط (لا شبكة ولا تنفيذ). التعليقات تُزال قبل الفحص كي لا يُفشل شرحٌ بالتعليق الاختبار.
*/

if (! function_exists('e26_repo_text_files')) {
    /** ملفات النص المرفوعة للمستودع (بلا vendor/node_modules/tests/build/storage). @return list<string> */
    function e26_repo_text_files(): array
    {
        $roots = ['app', 'bootstrap', 'config', 'database', 'docs', 'resources', 'routes', 'tools', 'public'];
        $skip = '#(^|/)(vendor|node_modules|storage|build|tests|\.git)(/|$)#';
        $ext = '#\.(php|js|mjs|ts|json|md|txt|yml|yaml|xml|sh|ps1|css|blade\.php|env|example|ini|conf)$#i';
        $files = [];

        foreach ($roots as $dir) {
            if (! is_dir(base_path($dir))) {
                continue;
            }
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($dir), FilesystemIterator::SKIP_DOTS)) as $file) {
                $relative = e26_relative($file->getPathname());
                if ($file->isFile() && ! preg_match($skip, $relative) && preg_match($ext, $relative) && $file->getSize() < 2_000_000) {
                    $files[] = $file->getPathname();
                }
            }
        }

        foreach (['.env.example', 'README.md', 'SECURITY.md', 'composer.json', 'package.json', 'phpunit.xml', 'vite.config.js', 'artisan'] as $root) {
            if (is_file(base_path($root))) {
                $files[] = base_path($root);
            }
        }

        return $files;
    }
}

test('W1: no private key, cloud/API token, real-looking password or app key is committed anywhere in the repository text files', function () {
    $patterns = [
        'private key' => '/-----BEGIN (?:RSA |EC |DSA |OPENSSH |PGP )?PRIVATE KEY-----/',
        'aws access key' => '/\bAKIA[0-9A-Z]{16}\b/',
        'github token' => '/\b(?:ghp|gho|ghu|ghs|ghr)_[A-Za-z0-9]{30,}\b|\bgithub_pat_[A-Za-z0-9_]{30,}\b/',
        'slack token' => '/\bxox[abprs]-[A-Za-z0-9-]{10,}\b/',
        'stripe/openai style key' => '/\b(?:sk|rk)_(?:live|test)_[A-Za-z0-9]{16,}\b|\bsk-[A-Za-z0-9]{32,}\b/',
        'google api key' => '/\bAIza[0-9A-Za-z_\-]{35}\b/',
        'app key value' => '/APP_KEY\s*=\s*base64:[A-Za-z0-9+\/=]{20,}/',
        'jwt' => '/\beyJ[A-Za-z0-9_-]{10,}\.eyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\b/',
    ];
    $hits = [];

    foreach (e26_repo_text_files() as $file) {
        $text = (string) file_get_contents($file);
        foreach ($patterns as $label => $regex) {
            if (preg_match($regex, $text)) {
                $hits[] = e26_relative($file).' ('.$label.')';
            }
        }
    }

    expect($hits)->toBe([]);
});

test('W1b: .env.example carries placeholders only - every secret-like key is empty, null or commented', function () {
    $secretKey = '/^(?<key>[A-Z0-9_]*(?:PASSWORD|SECRET|TOKEN|API_KEY|PRIVATE_KEY|ACCESS_KEY|APP_KEY|APP_SECRET)[A-Z0-9_]*)\s*=\s*(?<value>.*)$/';
    $offenders = [];

    foreach (preg_split('/\R/', (string) file_get_contents(base_path('.env.example'))) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if (preg_match($secretKey, $line, $m)) {
            $value = trim($m['value'], " \t\"'");
            if ($value !== '' && strtolower($value) !== 'null' && ! str_starts_with($value, '${')) {
                $offenders[] = $m['key'];
            }
        }
    }

    expect($offenders)->toBe([]);
    expect(file_get_contents(base_path('.gitignore')))->toContain('.env');
});

test('W2: no debug leftovers (dd, dump, ray, var_dump, print_r, console.log, debugger) in application code, routes, views or scripts', function () {
    $offenders = [];

    foreach (e26_php_files(['app', 'routes', 'bootstrap', 'config', 'database/seeders', 'database/factories', 'database/migrations']) as $file) {
        if (preg_match('/(?<![\w>:$])(?:dd|dump|ray|var_dump|print_r|var_export|phpinfo|xdebug_break)\s*\(/', e26_php_code($file))) {
            $offenders[] = e26_relative($file);
        }
    }

    foreach ([base_path('resources/js'), base_path('resources/views')] as $dir) {
        if (! is_dir($dir)) {
            continue;
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
            if (! $file->isFile() || ! preg_match('/\.(js|mjs|ts|php)$/', $file->getFilename())) {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (preg_match('/\bconsole\.(log|debug|trace)\s*\(|^\s*debugger\s*;?\s*$/m', $source)) {
                $offenders[] = e26_relative($file->getPathname());
            }
        }
    }

    expect($offenders)->toBe([]);
});

test('W3: unescaped Blade output ({!! !!}) exists only for the static game-icon SVG map, never for data', function () {
    $found = [];

    $dir = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('resources/views'), FilesystemIterator::SKIP_DOTS));
    foreach ($dir as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
            // نزيل تعليقات Blade {{-- --}} قبل الفحص.
            $source = preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents($file->getPathname()));
            if (str_contains($source, '{!!') || str_contains($source, '@php echo') || preg_match('/<\?=/', $source)) {
                $found[] = e26_relative($file->getPathname());
            }
        }
    }

    expect($found)->toBe(['resources/views/components/game-icon.blade.php']);

    $icon = (string) file_get_contents(base_path('resources/views/components/game-icon.blade.php'));
    expect($icon)->toContain("\$inner = \$paths[\$name] ?? \$paths['puzzle'];")->and(substr_count($icon, '{!!'))->toBe(1);

    // لا حقن HTML خام من PHP في Filament/Livewire (HtmlString/new HtmlString من بيانات المستخدم).
    $offenders = [];
    foreach (e26_php_files(['app/Filament', 'app/Livewire', 'app/Http']) as $file) {
        if (preg_match('/new\s+HtmlString\s*\(\s*\$|Blade::render\s*\(\s*\$|->html\s*\(\s*\$/', e26_php_code($file))) {
            $offenders[] = e26_relative($file);
        }
    }
    expect($offenders)->toBe([]);
});

test('W4: no request-wide mass assignment - no $request->all(), no unguard(), no empty $guarded, and the fillable lists of identity/state models hold no privilege fields', function () {
    $offenders = [];

    foreach (e26_php_files(['app', 'routes', 'database/seeders']) as $file) {
        $code = e26_php_code($file);
        if (preg_match('/\$request->all\s*\(|request\(\)->all\s*\(|Request::all\s*\(|->unguard\s*\(|Model::unguard|\$guarded\s*=\s*\[\s*\]|->fill\s*\(\s*\$request->(?:validated|input)\s*\(\s*\)\s*\)/', $code)) {
            $offenders[] = e26_relative($file);
        }
    }
    expect($offenders)->toBe([]);

    foreach (['is_frozen', 'email_verified_at', 'public_id', 'remember_token', 'is_admin'] as $field) {
        expect((new \App\Models\User)->getFillable())->not->toContain($field);
    }
});

test('W5: raw SQL never receives request data, and interpolated raw SQL exists only at the five reviewed server-built sites', function () {
    $rawCall = '/(?:whereRaw|selectRaw|orderByRaw|havingRaw|groupByRaw|DB::raw|DB::statement|DB::select|DB::unprepared|DB::insert|DB::update|DB::delete)\s*\(/';
    $allowedInterpolation = [
        'app/Services/Analytics/EconomyAnalyticsService.php',      // تعبير فرق ساعات يُبنى من اسم سائق القاعدة
        'app/Services/Analytics/UserAnalyticsService.php',         // تعبير تجميع زمني يُبنى من match على قيمة داخلية
        'app/Services/Competitive/CompetitiveLifecycleService.php', // استعلام فرعي ثابت
        'app/Services/Teams/TeamChampionshipStandingsService.php', // CASE يُبنى من نقاط الإعداد بعد (int)
        'app/Services/Collections/CollectionOrderService.php',     // E35: تحديث CASE واحد لمواضع الترتيب؛ القطع ثابتة (' WHEN ? THEN ?') وكل القيم ومعرّفات IN مربوطة (?)، ولا شيء من الطلب يدخل النص
    ];
    $unsafe = [];
    $interpolated = [];

    foreach (e26_php_files(['app', 'routes', 'database/seeders']) as $file) {
        $relative = e26_relative($file);
        foreach (preg_split('/\R/', e26_php_code($file)) as $n => $line) {
            if (! preg_match($rawCall, $line)) {
                continue;
            }
            if (preg_match('/\$request\b|request\(|\$_(?:GET|POST|REQUEST|COOKIE)|->input\(|->query\(|->get\(\s*[\'"]/', $line)) {
                $unsafe[] = $relative.':'.($n + 1);
            }
            if (preg_match('/\{\$|"\s*\.\s*\$|\'\s*\.\s*\$/', $line) && ! in_array($relative, $allowedInterpolation, true)) {
                $interpolated[] = $relative.':'.($n + 1);
            }
        }
    }

    expect($unsafe)->toBe([])->and($interpolated)->toBe([]);
});

test('W6: no code-execution or unsafe-deserialization primitives in application code (eval, shell, unserialize, dynamic include, extract)', function () {
    $offenders = [];

    foreach (e26_php_files(['app', 'routes', 'config', 'bootstrap']) as $file) {
        $code = e26_php_code($file);
        if (preg_match('/(?<![\w>:$])(?:eval|shell_exec|exec|system|passthru|proc_open|popen|pcntl_exec|unserialize|extract|create_function|assert)\s*\(|`[^`\n]*\$[^`\n]*`|(?:include|require)(?:_once)?\s*\(?\s*\$/', $code)) {
            $offenders[] = e26_relative($file);
        }
    }

    expect($offenders)->toBe([]);
});

test('W7: outbound HTTP and file reads are confined to the operations tooling (SmokeChecker with a configured URL, PreflightChecker reading local files) - no SSRF primitive in request paths', function () {
    $http = [];
    $files = [];

    foreach (e26_php_files(['app', 'routes']) as $file) {
        $code = e26_php_code($file);
        $relative = e26_relative($file);

        if (preg_match('/\bHttp::|curl_init|fsockopen|stream_socket_client|new\s+\\\\?GuzzleHttp\\\\Client|Guzzle/', $code)) {
            $http[] = $relative;
        }
        if (preg_match('/file_get_contents\s*\(|\bfopen\s*\(\s*[^\'")]*\$|readfile\s*\(|->get\s*\(\s*\$request/', $code)) {
            $files[] = $relative;
        }
    }

    expect($http)->toBe(['app/Services/Operations/SmokeChecker.php']);
    expect($files)->toBe(['app/Services/Operations/PreflightChecker.php']);
});

test('W8: nothing excludes a route from CSRF or sessions, and no route registers a closure that mutates state', function () {
    $offenders = [];

    foreach (e26_php_files(['routes', 'bootstrap', 'app/Providers']) as $file) {
        if (preg_match('/validateCsrfTokens|\$except\s*=|withoutMiddleware\s*\(\s*\[?[^)]*(?:Csrf|VerifyCsrfToken|StartSession|ShareErrorsFromSession)/', e26_php_code($file))) {
            $offenders[] = e26_relative($file);
        }
    }
    expect($offenders)->toBe([]);

    // لوحة Filament تعمل خلف VerifyCsrfToken نفسه (مُضمَّن لا مستثنى).
    expect(e26_php_code(base_path('app/Providers/Filament/AdminPanelProvider.php')))->toContain('VerifyCsrfToken::class');

    foreach (Route::getRoutes() as $route) {
        if (array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) && $route->getAction('uses') instanceof Closure && ! str_starts_with($route->uri(), 'livewire') && $route->uri() !== 'storage/{path}') {
            $offenders[] = implode('|', $route->methods()).' '.$route->uri().' (closure)';
        }
    }
    expect($offenders)->toBe([]);
});

test('W9: sensitive values never reach the logs - no Log:: call mentions passwords, tokens, secrets, cookies, authorization headers or whole request bodies', function () {
    $offenders = [];
    $logCall = '/(?:Log::(?:debug|info|notice|warning|error|critical|alert|emergency|log)|logger\s*\(|->(?:warning|error|info)\s*\(\s*[\'"][^\'"]+[\'"]\s*,)/';

    foreach (e26_php_files(['app']) as $file) {
        $code = e26_php_code($file);
        $relative = e26_relative($file);

        foreach (preg_split('/\R/', $code) as $n => $line) {
            if (preg_match($logCall, $line) && preg_match('/password|passwd|secret|api_key|authorization|cookie|remember|bearer|plainTextToken|\$request->all|->all\(\)|->input\(\s*\)|getContent\(\)/i', $line)) {
                $offenders[] = $relative.':'.($n + 1);
            }
        }
    }

    expect($offenders)->toBe([]);

    // سطر قفل الدخول يحوي بصمة البريد لا البريد الخام.
    $login = e26_php_code(base_path('app/Http/Requests/Auth/LoginRequest.php'));
    expect($login)->toContain("'email_fp' => Fingerprint::email(")->not->toMatch("/Log::warning\\([^;]*'email'\\s*=>\\s*\\\$this->(?:input|email)/");
});

test('W10: E26 added no security package - the lock file is valid, commonmark was not downgraded below the audited floor, and no CAPTCHA/2FA/WAF/SIEM dependency exists', function () {
    $lock = json_decode((string) file_get_contents(base_path('composer.lock')), true);
    $packages = collect($lock['packages'] ?? [])->keyBy('name');

    expect(base_path('composer.lock'))->toBeFile();
    expect(version_compare(ltrim((string) ($packages->get('league/commonmark')['version'] ?? '0'), 'v'), '2.10.1', '>='))->toBeTrue();

    // لا حزمة أمان إضافية (CAPTCHA/2FA/WAF/SIEM) دخلت المشروع ضمن E26.
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true);
    $required = array_keys(($composer['require'] ?? []) + ($composer['require-dev'] ?? []));
    foreach ($required as $name) {
        expect($name)->not->toMatch('#captcha|recaptcha|turnstile|2fa|two-factor|fortify|google2fa|waf|modsecurity|sentry|bugsnag|honeypot|firewall#i');
    }
});
