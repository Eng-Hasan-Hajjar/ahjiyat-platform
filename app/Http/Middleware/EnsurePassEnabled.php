<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** E40 - مفتاح إيقاف شامل لمسار الأحجيات: معطَّل ⇒ 404 لكل صفحات اللاعب (البيانات التاريخية تبقى للإدارة). */
class EnsurePassEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) config('ahjiyat_pass.enabled', true), 404);

        return $next($request);
    }
}
