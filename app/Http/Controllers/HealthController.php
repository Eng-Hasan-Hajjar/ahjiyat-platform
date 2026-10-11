<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * E24 - جاهزية (readiness) للموازن: GET /ready. الحيوية (liveness) هي /up المدمجة (لا تلمس أي تابع).
 * تفحص قاعدة البيانات (SELECT 1) والكاش (كتابة/قراءة قصيرة) وتقيس زمن كلٍّ؛ تُعدّ «بطيئة=غير جاهزة» إن تجاوزت performance.observability.readiness_timeout_ms.
 * لا أسرار ولا أسماء مضيفين ولا إصدارات ولا نص استثناء: فقط ok/زمن بالميلي ثانية. 200 عند الجاهزية، 503 وإلا.
 * اختياريًا يُقيَّد برمز (PERF_READINESS_TOKEN) في الرأس X-Health-Token.
 * ملاحظة: مهلة اتصال القاعدة الفعلية تُضبط في الاتصال (انظر docs/scaling-architecture.md)؛ هنا نُبلّغ فقط عن البطء.
 */
class HealthController extends Controller
{
    public function ready(Request $request): JsonResponse
    {
        $token = (string) config('performance.observability.readiness_token', '');

        if ($token !== '' && ! hash_equals($token, (string) $request->header('X-Health-Token', ''))) {
            return response()->json(['status' => 'forbidden'], 403, $this->headers());
        }

        $limit = (int) config('performance.observability.readiness_timeout_ms', 1500);
        $checks = [
            'database' => $this->probe(fn () => DB::select('select 1'), $limit),
            'cache' => $this->probe(function () {
                $key = 'health:ready:'.bin2hex(random_bytes(4));
                Cache::put($key, 1, 10);

                if (Cache::get($key) !== 1) {
                    throw new \RuntimeException('cache round-trip mismatch');
                }
                Cache::forget($key);
            }, $limit),
        ];

        $ready = collect($checks)->every(fn (array $c) => $c['ok']);

        return response()->json(['status' => $ready ? 'ok' : 'unavailable', 'checks' => $checks], $ready ? 200 : 503, $this->headers());
    }

    /** @return array{ok: bool, ms: float} */
    private function probe(\Closure $fn, int $limitMs): array
    {
        $start = hrtime(true);

        try {
            $fn();
            $ok = true;
        } catch (\Throwable) {
            $ok = false; // لا نُسرّب نص الخطأ؛ الخطأ الكامل يصل لسجلات التطبيق عبر الطرق المعتادة عند الحاجة.
        }

        $ms = round((hrtime(true) - $start) / 1e6, 1);

        return ['ok' => $ok && ($limitMs <= 0 || $ms <= $limitMs), 'ms' => $ms];
    }

    private function headers(): array
    {
        return ['Cache-Control' => 'no-store, private', 'X-Robots-Tag' => 'noindex'];
    }
}
