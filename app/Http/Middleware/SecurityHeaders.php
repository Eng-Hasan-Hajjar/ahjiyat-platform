<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /**
     * E26: صفحات بيانات الاعتماد/الحساب/الإدارة: Cache-Control: no-store كي لا يُعاد عرضها من كاش المتصفح/وسيط بعد تسجيل الخروج (زر الرجوع). باقي الصفحات تبقى على
     * افتراضي Laravel (no-cache, private) دون خسارة التنقل. المسارات بلا بادئة اللغة.
     */
    protected const NO_STORE_PATHS = [
        'login', 'register', 'forgot-password', 'reset-password/*', 'confirm-password', 'verify-email', 'verify-email/*',
        'profile', 'profile/*', 'wallet', 'redemption', 'redemption/*', 'admin', 'admin/*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');

        // E26: خط أساس CSP لا يؤثر على تنفيذ السكربتات (Alpine/Livewire/Filament تحتاج inline/eval فلا script-src هنا): يمنع التأطير من نطاق آخر وحقن <base> والمكوّنات
        // الملحقة. قرار تأجيل CSP كامل موثَّق في docs/security-e26.md.
        if (config('security.headers.csp_baseline', true) && ! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', "frame-ancestors 'self'; base-uri 'self'; object-src 'none'");
        }

        if ($request->isMethodCacheable() && $request->is(...self::NO_STORE_PATHS)) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        if ($request->isSecure() && app()->environment('production')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }
}