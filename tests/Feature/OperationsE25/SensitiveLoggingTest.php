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
