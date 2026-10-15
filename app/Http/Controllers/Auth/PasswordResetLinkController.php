<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;

class PasswordResetLinkController extends Controller
{
    /**
     * Display the password reset link request view.
     */
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    /**
     * Handle an incoming password reset link request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        // E26: رد **موحّد** سواء وُجد الحساب أم لا (لا تعداد حسابات)، والإرسال الفعلي يتم أو لا يتم بصمت. حالات الفشل الداخلية (مستخدم غير موجود، تحديد
        // معدّل الوسيط الداخلي) لا تظهر للمُرسِل؛ الحدّان الخارجيان (IP + مستلم) بـthrottle:password-reset يبقيان.
        Password::sendResetLink($request->only('email'));

        return back()->with('status', 'إن كان هذا البريد مسجَّلًا لدينا فقد أرسلنا إليه رابط إعادة تعيين كلمة المرور.');
    }
}
