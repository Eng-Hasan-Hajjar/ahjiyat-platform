<?php

namespace App\Http\Middleware;

use App\Support\RequestMemo;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * E24 - مراقبة خفيفة بلا مزوّد خارجي:
 *  1) X-Request-ID: يُقبل من الوكيل الأمامي فقط إن كان بصيغة آمنة (حروف/أرقام/. _ -، 8..64) وإلا يُولَّد UUID. يُعاد في الاستجابة ويُضاف لسياق السجلّات (Context) فيظهر في كل سطر سجل وفي المهام المُرسلة من الطلب.
 *  2) سجل الطلبات البطيئة (terminate: بعد إرسال الاستجابة): method + مسار القالب (route name/uri، لا الرابط الفعلي) + status + المدة + ذروة الذاكرة (+ معرّف المستخدم الرقمي).
 *     لا أجسام، لا رؤوس، لا كوكيز، لا سلسلة استعلام، لا نصوص دردشة، لا بيانات محفظة. مع أخذ عيّنات (performance.observability.slow_request_sample) كي لا يغرق السجل تحت الحِمل.
 *  3) Server-Timing (عدد استعلامات DB ووقتها) **محليًا/اختباريًا فقط**؛ لا مستمع DB ولا رأس إضافي في الإنتاج.
 * الحالة تُحفظ في attributes الطلب لا في الكائن: Laravel ينشئ نسخة جديدة من الوسيط عند terminate.
 */
class RequestObservability
{
    private const ID_PATTERN = '/^[A-Za-z0-9._\-]{8,64}$/';

    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('_obs_started', hrtime(true));

        $requestId = null;

        if (config('performance.observability.request_id', true)) {
            $incoming = (string) $request->headers->get('X-Request-ID', '');
            $requestId = preg_match(self::ID_PATTERN, $incoming) === 1 ? $incoming : (string) Str::uuid();
            $request->attributes->set('request_id', $requestId);
            Context::add('request_id', $requestId);
        }

        $measureDb = app()->environment(['local', 'testing']);

        if ($measureDb) {
            $stats = (object) ['count' => 0, 'ms' => 0.0];
            $request->attributes->set('_obs_db', $stats);
            DB::listen(function ($query) use ($stats) {
                $stats->count++;
                $stats->ms += $query->time;
            });
        }

        // نافذة RequestMemo: من هنا حتى انتهاء المعالجة فقط (يشمل رسم القالب الذي يحدث داخل $next). بعدها تقرأ الاستدعاءات القاعدة مباشرةً.
        RequestMemo::begin($request);

        try {
            $response = $next($request);
        } finally {
            RequestMemo::end($request);
        }

        if ($requestId !== null) {
            $response->headers->set('X-Request-ID', $requestId);
        }

        if ($measureDb) {
            $total = (hrtime(true) - $request->attributes->get('_obs_started')) / 1e6;
            $response->headers->set('Server-Timing', sprintf('app;dur=%.1f, db;dur=%.1f;desc="%d queries"', $total, $stats->ms, $stats->count));
        }

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        $threshold = (int) config('performance.observability.slow_request_ms', 0);
        $started = $request->attributes->get('_obs_started');

        if ($threshold <= 0 || $started === null) {
            return;
        }

        $durationMs = (hrtime(true) - $started) / 1e6;

        if ($durationMs < $threshold) {
            return;
        }

        $sample = max(0.0, min(1.0, (float) config('performance.observability.slow_request_sample', 1.0)));

        if ($sample < 1.0 && random_int(1, 1_000_000) > (int) ($sample * 1_000_000)) {
            return;
        }

        $route = $request->route();
        $db = $request->attributes->get('_obs_db');

        Log::warning('slow_request', array_filter([
            'request_id' => $request->attributes->get('request_id'),
            'method' => $request->method(),
            'route' => $route?->getName() ?? ($route ? '/'.ltrim($route->uri(), '/') : null),
            'status' => $response->getStatusCode(),
            'duration_ms' => round($durationMs, 1),
            'peak_memory_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
            'user_id' => $request->user()?->getAuthIdentifier(),
            'db_queries' => $db?->count,
            'db_ms' => $db ? round($db->ms, 1) : null,
        ], fn ($v) => $v !== null));
    }
}
