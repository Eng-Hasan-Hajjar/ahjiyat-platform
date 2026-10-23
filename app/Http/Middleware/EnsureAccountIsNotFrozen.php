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
            // E26: طلبات JSON/API تتلقى 403 صريحًا (لا إعادة توجيه HTML تُربك عميل الموبايل وتُخفي التقييد).
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['message' => 'تم تقييد حسابك مؤقتاً. يرجى التواصل مع الدعم.'], 403);
            }

            $message = 'تم تقييد حسابك مؤقتاً. يرجى التواصل مع الدعم لمزيد من التفاصيل.';

            // E38: تنقّل (GET/HEAD) لصفحة محمية ← صفحة «الأمان والإشراف» الآمنة (فيها سبب التقييد وطريق الاعتراض). أي طلب كتابة يبقى كما كان (back + error): لا فعل ينفَّذ.
            if ($request->isMethodSafe() && ! $request->routeIs('account.safety.*')) {
                return redirect()->route('account.safety.index')->with('error', $message);
            }

            return back()->with('error', $message);
        }

        return $next($request);
    }
}