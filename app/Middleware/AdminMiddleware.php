<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        // غير مسجّل الدخول → أرسله لصفحة تسجيل الدخول
        if (!auth()->check()) {
            return redirect()->route('login')
                             ->with('error', 'يجب تسجيل الدخول أولاً للوصول إلى لوحة الإدارة.');
        }

        // مسجّل دخول لكن ليس مديراً → صفحة 403 واضحة
        if (!auth()->user()->is_admin) {
            abort(403, 'غير مصرح لك بالوصول إلى لوحة الإدارة.');
        }

        return $next($request);
    }
}