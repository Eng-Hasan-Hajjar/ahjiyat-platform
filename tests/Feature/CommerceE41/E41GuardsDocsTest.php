<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;

/*
|--------------------------------------------------------------------------
| E41 - حراس البنية والوثائق وCI: PSR/namespace، لا حزم جديدة ولا شبكة ولا أسرار، وثائق كاملة وروابطها صحيحة، placeholders فقط، خطوات CI، أوامر مسجلة
|--------------------------------------------------------------------------
*/

function e41_read(string $relative): string
{
    return (string) file_get_contents(base_path($relative));
}

/** الكود بلا تعليقات (لمسح الرموز لا النصوص الشارحة). */
function e41_code(string $relative): string
{
    $code = '';

    foreach (token_get_all(e41_read($relative)) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $code .= is_array($token) ? $token[1] : $token;
    }

    return $code;
}

/** @return list<string> */
function e41_php_files(): array
{
    $files = [];

    foreach (['app/Services/Commerce', 'app/Http/Controllers/Commerce'] as $dir) {
        foreach (glob(base_path($dir).'/*.php') as $file) {
            $files[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file);
        }
    }

    foreach (['app/Models/CommerceProviderEvent.php', 'app/Services/AhjiyatPass/PassPremiumProductHandler.php', 'app/Console/Commands/ReconcileCommerce.php', 'app/Console/Commands/ProcessCommerce.php', 'app/Console/Commands/CommerceProviderHealth.php', 'app/Console/Commands/ExpireCommerce.php', 'app/Filament/Pages/CommerceOperationsPage.php'] as $file) {
        $files[] = $file;
    }

    return $files;
}

function e41_slug(string $heading): string
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
function e41_anchors(string $markdown): array
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
            $slug = e41_slug($m[1]);
            $n = $seen[$slug] ?? 0;
            $seen[$slug] = $n + 1;
            $anchors[] = $n === 0 ? $slug : "{$slug}-{$n}";
        }
    }

    return $anchors;
}

const E41_DOCS = ['docs/consumer-commerce-e41.md', 'docs/consumer-commerce-operations-runbook.md'];

test('E41-guard-1: every commerce class is PSR-4 compliant — file name = class name, namespace = directory', function () {
    $violations = [];

    foreach (e41_php_files() as $file) {
        $source = e41_read($file);
        $expectedNamespace = 'App\\'.str_replace('/', '\\', dirname(substr($file, strlen('app/'))));
        $class = pathinfo($file, PATHINFO_FILENAME);

        if (preg_match('/^namespace\s+([^;]+);/m', $source, $ns) !== 1 || $ns[1] !== $expectedNamespace) {
            $violations[] = "{$file}: namespace";
        }

        if (preg_match('/^(?:final\s+|abstract\s+)?(?:class|interface|enum|trait)\s+'.preg_quote($class, '/').'\b/m', $source) !== 1) {
            $violations[] = "{$file}: class name";
        }
    }

    foreach (['database/migrations/2026_11_23_000001_extend_digital_purchases_for_commerce.php', 'database/migrations/2026_11_23_000002_create_commerce_provider_events_table.php', 'config/commerce.php', 'routes/commerce.php', 'routes/commerce_webhooks.php', 'tools/e41/concurrency-probe.php'] as $file) {
        expect(is_file(base_path($file)))->toBeTrue($file);
    }

    expect($violations)->toBe([]);
});

test('E41-guard-2: no new package, no payment SDK, no outbound network call and no real credential in the commerce code or config', function () {
    $composer = strtolower(e41_read('composer.json').e41_read('package.json'));

    foreach (['stripe', 'paypal', 'iyzico', 'iyzipay', 'paytr', 'braintree', 'adyen', 'checkout.com', 'mollie', 'razorpay', 'square', 'twocheckout', 'omnipay', 'cashier'] as $sdk) {
        expect($composer)->not->toContain($sdk);
    }

    $violations = [];

    foreach (array_merge(e41_php_files(), ['config/commerce.php', 'routes/commerce.php', 'routes/commerce_webhooks.php']) as $file) {
        $code = e41_code($file);

        foreach (['Http::', 'curl_', 'file_get_contents(\'http', 'GuzzleHttp', 'fsockopen', 'stream_socket_client', 'new Client('] as $needle) {
            if (str_contains($code, $needle)) {
                $violations[] = "{$file}: {$needle}";
            }
        }

        foreach (['/sk_(live|test)_[A-Za-z0-9]{8,}/', '/whsec_[A-Za-z0-9]{8,}/', '/AKIA[0-9A-Z]{16}/', '/-----BEGIN [A-Z ]*PRIVATE KEY-----/', '/pk_(live|test)_[A-Za-z0-9]{8,}/'] as $pattern) {
            if (preg_match($pattern, $code) === 1) {
                $violations[] = "{$file}: credential-like value";
            }
        }
    }

    expect($violations)->toBe([]);
});

test('E41-guard-3: the commerce migrations use explicit, bounded index names and leave E40 migrations untouched in kind (new files only)', function () {
    foreach (glob(base_path('database/migrations/2026_11_23_00000*.php')) as $file) {
        $source = file_get_contents($file);
        preg_match_all("/->(?:unique|index)\(\[[^\]]+\],\s*'([^']+)'\)/", $source, $names);

        expect($names[1])->not->toBe([]);

        foreach ($names[1] as $name) {
            expect(strlen($name))->toBeLessThanOrEqual(64, $name);
        }

        // كل unique/index لها اسم صريح (لا أسماء تلقائية قد تتجاوز 64 على MySQL).
        expect(preg_match_all('/->(?:unique|index)\(/', $source))->toBe(count($names[1]));
    }
});

test('E41-guard-4: both documents exist, are substantial, cover every required topic, and their internal links and anchors resolve', function () {
    foreach (E41_DOCS as $doc) {
        expect(is_file(base_path($doc)))->toBeTrue($doc)->and(strlen(e41_read($doc)))->toBeGreaterThan(6000, "{$doc} looks thin");
    }

    $design = e41_read('docs/consumer-commerce-e41.md');

    foreach (['DigitalPurchase', 'سجل المزوّدين', 'المزوّد اليدوي', 'sandbox', 'Checkout المستضاف', 'عودة المتصفح', 'Webhook الموقَّع', 'idempotency', 'عدم التطابق', 'سياسة الاسترداد', 'المطابقة', 'الأمان', 'الخصوصية', 'E32', 'E39', 'E40', 'عقد مزوّد حيّ مستقبلي'] as $topic) {
        expect($design)->toContain($topic);
    }

    $runbook = e41_read('docs/consumer-commerce-operations-runbook.md');

    foreach (['تفعيل مزوّد', 'تعطيل checkout', 'مراجعة عدم تطابق', 'تحقق من شراء يدوي', 'دفعة متأخرة', 'الاسترداد', 'المطابقة', 'انقطاع المزوّد', 'فشل توقيع webhook', 'تدوير سر مزوّد', 'استجابة الحوادث'] as $topic) {
        expect($runbook)->toContain($topic);
    }

    expect(substr_count($design, "\n1. **")+substr_count($design, "\n2. **"))->toBeGreaterThan(0);

    $broken = [];

    foreach (E41_DOCS as $doc) {
        preg_match_all('/\]\(([^)\s]+)\)/u', e41_read($doc), $m);

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

            if ($anchor !== null && ! in_array(rawurldecode($anchor), e41_anchors(e41_read($file)), true)) {
                $broken[] = "{$doc} -> {$target} (missing anchor)";
            }
        }
    }

    expect($broken)->toBe([]);
});

test('E41-guard-5: the docs name the seven idempotency layers and the expiry owner', function () {
    $design = e41_read('docs/consumer-commerce-e41.md');
    preg_match('/## 8\) طبقات الـidempotency \(سبع\)(.*?)\n## 9\)/su', $design, $section);

    expect($section)->not->toBe([])->and(preg_match_all('/^\d\.\s\*\*/m', $section[1]))->toBe(7)
        ->and($design)->toContain('ahjiyat-pass:process')->toContain('commerce:expire');
});

test('E41-guard-6: docs and .env.example carry placeholders only — no secret-like value, and no key for a sandbox signing secret', function () {
    foreach (E41_DOCS as $doc) {
        preg_match_all('/^\s*(?:export\s+)?([A-Z][A-Z0-9_]*(?:PASSWORD|SECRET|TOKEN|KEY))=([^\s#`]+)/m', e41_read($doc), $matches, PREG_SET_ORDER);

        foreach ($matches as [$all, $name, $value]) {
            expect(preg_match('/^(<[^>]+>|\$\{?[A-Z_]+\}?|\.{3}|…|null|"")$/i', trim($value, '"\'')))->toBe(1, "{$doc}: {$name} has a non-placeholder value");
        }
    }

    $env = e41_read('.env.example');
    preg_match_all('/^\s*#?\s*(COMMERCE_[A-Z0-9_]+)=(.*)$/m', $env, $entries, PREG_SET_ORDER);
    $keys = array_column($entries, 1);

    foreach (['COMMERCE_ENABLED', 'COMMERCE_DEFAULT_PROVIDER', 'COMMERCE_FALLBACK_PROVIDER', 'COMMERCE_WEBHOOKS_ENABLED', 'COMMERCE_SANDBOX_ENABLED', 'COMMERCE_WEBHOOK_RATE_PER_MINUTE', 'COMMERCE_WEBHOOK_REPLAY_TOLERANCE'] as $key) {
        expect($keys)->toContain($key);
    }

    foreach ($keys as $key) {
        expect(preg_match('/(SECRET|TOKEN|PASSWORD|API_KEY|PRIVATE|CREDENTIAL)/', $key))->toBe(0, "{$key} must not be listed in .env.example");
    }

    expect(trim(preg_replace('/\s+#.*$/', '', (string) collect($entries)->firstWhere(1, 'COMMERCE_DEFAULT_PROVIDER')[2])))->toBe('manual')
        ->and(e41_read('docs/environment-variables.md'))->toContain('COMMERCE_ENABLED')->toContain('COMMERCE_WEBHOOKS_ENABLED')->toContain('COMMERCE_DEFAULT_PROVIDER');
});

test('E41-guard-7: the CI workflow runs the commerce MySQL schema test and the real-concurrency probe, after the E40 probe, on the temporary test database only', function () {
    $ci = e41_read('.github/workflows/ci.yml');

    expect($ci)->toContain('tests/Feature/CommerceE41/E41MysqlSchemaTest.php')->toContain('php tools/e41/concurrency-probe.php --rounds=4')
        ->and(strpos($ci, 'tools/e41/concurrency-probe.php'))->toBeGreaterThan(strpos($ci, 'tools/e40/concurrency-probe.php'));

    $probe = e41_read('tools/e41/concurrency-probe.php');

    foreach (['app()->environment(\'production\')', 'in_array($driver, [\'mysql\', \'mariadb\']', '(test|testing)', 'migrate:fresh', 'exit(2)'] as $guard) {
        expect($probe)->toContain($guard);
    }

    expect($probe)->not->toContain('Http::')->not->toContain('curl_');
});

test('E41-guard-8: the commerce commands are registered, bounded, and the processor is scheduled with overlap protection', function () {
    $commands = array_keys(Artisan::all());

    foreach (['commerce:reconcile', 'commerce:process', 'commerce:provider-health', 'commerce:expire'] as $command) {
        expect($commands)->toContain($command);
    }

    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'commerce:process'));

    expect($event)->not->toBeNull()->and($event->withoutOverlapping)->toBeTrue();

    // الأوامر المالية الكاتبة تدعم dry-run أو تعمل للقراءة فقط.
    foreach (['commerce:process', 'commerce:expire'] as $command) {
        expect(Artisan::all()[$command]->getDefinition()->hasOption('dry-run'))->toBeTrue($command);
    }

    expect(Artisan::all()['commerce:reconcile']->getDefinition()->hasOption('fix-derived'))->toBeTrue()
        ->and(Artisan::all()['commerce:reconcile']->getDefinition()->hasOption('limit'))->toBeTrue()
        ->and(e41_read('app/Console/Commands/ReconcileCommerce.php'))->not->toContain('DigitalPurchase::query()->update');
});
