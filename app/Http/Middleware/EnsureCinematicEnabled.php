<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CIN-1 - علم تشغيل السلاسل السينمائية. مغلقًا (الافتراضي) ⇒ 404 لكل مساراتها دون أي أثر على نظام السلاسل التقليدي ودون حذف أي بيانات.
 */
class EnsureCinematicEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) config('cinematic_stories.enabled'), 404);

        return $next($request);
    }
}
