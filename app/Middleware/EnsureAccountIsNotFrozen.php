<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * إصلاح ثغرة حقيقية موجودة سابقاً: is_frozen كانت تُفحَص فقط عند تسجيل
 * الدخول. مستخدم لديه جلسة قائمة مسبقاً ثم جُمِّد حسابه كان يستطيع
 * الاستمرار بكل الأفعال الحساسة دون أي عائق. يُطبَّق مركزياً على مجموعة
 * verified بـroutes/web.php - لا تعديل بأي Controller.
 */
class EnsureAccountIsNotFrozen
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user && $user->is_frozen) {
            return back()->with('error', 'تم تقييد حسابك مؤقتاً. يرجى التواصل مع الدعم لمزيد من التفاصيل.');
        }

        return $next($request);
    }
}