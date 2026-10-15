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
