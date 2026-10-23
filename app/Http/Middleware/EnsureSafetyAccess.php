<?php

namespace App\Http\Middleware;

use App\Services\TrustSafety\RestrictedAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * E38 - بوابة صفحة «الأمان والإشراف»: مستخدم مصادَق (حتى لو مجمَّد بجلسة قائمة) أو صاحب سياق وصول مقيَّد صالح (حساب مجمَّد بعد دخول ناجح).
 * تضع المستخدم المعني في attribute `safety_user` فقط؛ لا تُصادق حارس web ولا تمنح أي وصول خارج مسارات /account/safety.
 * لا account.active هنا عمدًا: المجمَّد يجب أن يصل لهذه الصفحة وحدها.
 */
class EnsureSafetyAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('web')->user() ?? RestrictedAccess::user($request->session());

        if ($user === null) {
            return redirect()->guest(route('login'));
        }

        $request->attributes->set('safety_user', $user);

        return $next($request);
    }
}
