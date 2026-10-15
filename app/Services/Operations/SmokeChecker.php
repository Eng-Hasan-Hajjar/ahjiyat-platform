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
