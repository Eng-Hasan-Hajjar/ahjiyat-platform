<?php

namespace App\Http\Middleware;

use App\Services\Referrals\ReferralIntent;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * E33 (C) - يلتقط ?ref=CODE (و?c=slug) من روابط المشاركة العامة لزائر **ضيف** فقط، على طلبات GET لصفحات HTML.
 * بلا أي أثر عند غياب ?ref (فحص مفتاح واحد في الطلب). محدود المعدّل بنفس مفتاح /r/{code} (referral-redirect) ويتجاهل الزيادة بصمت (لا خطأ للزائر).
 * لا يغيّر الاستجابة ولا يعيد التوجيه؛ الرمز غير الصالح لا يُكشف (لا رسالة). الخادم هو الحَكَم: لا يؤثر الرمز على المحتوى المعروض.
 */
class CaptureReferralIntent
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('GET') && $request->query->has('ref') && (bool) config('referrals.enabled') && $request->user() === null && ! $request->expectsJson()) {
            $key = 'referral-redirect:'.$request->ip();
            $max = max(1, (int) config('referrals.limits.redirect_per_minute'));

            if (! RateLimiter::tooManyAttempts($key, $max)) {
                RateLimiter::hit($key, 60);
                app(ReferralIntent::class)->capture($request, $request->query('ref'), $request->query('c'), 'share_param');
            }
        }

        return $next($request);
    }
}
