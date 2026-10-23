<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\TrustSafety\RestrictedAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthenticatedSessionController extends Controller
{
    public function create()
    {
        return view('auth.login');
    }

    /**
     * E26: الدخول عبر LoginRequest (تحديد معدّل مزدوج + رسالة موحّدة + معادلة زمنية). تجديد معرّف الجلسة بعد النجاح (منع تثبيت الجلسة)، والحساب المجمَّد لا يبقى
     * مصادَقًا ولا تبقى جلسته: تسجيل خروج + إبطال الجلسة + تجديد الرمز، لا مجرد logout يترك الجلسة حيّة.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        if (Auth::user()->is_frozen) {
            $frozen = Auth::user();

            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            // E38: بيانات الدخول صحيحة لحساب مجمَّد ⇒ سياق وصول مقيَّد (جلسة جديدة، بمدة قصيرة) لصفحة «الأمان والإشراف» وحدها؛ الحارس يبقى غير مصادَق (E26).
            RestrictedAccess::grant($request->session(), $frozen);

            return back()->withErrors(['email' => 'تم تجميد هذا الحساب مؤقتاً، يرجى التواصل مع الدعم.'])->with('safety_link', true)->onlyInput('email');
        }

        Auth::user()->update(['last_seen_at' => now()]);

        return redirect()->intended(route('home'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
