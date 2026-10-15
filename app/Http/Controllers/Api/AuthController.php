<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Security\AccountSecurityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

// تسجيل الدخول عبر Sanctum Personal Access Tokens - جاهز لتطبيق Kotlin
// من دون أي تعديل على منطق الأعمال، فقط طبقة مصادقة موازية للويب.
//
// E26: (1) حدّ معدّل مزدوج على المسار (throttle:api-login)، (2) معادلة زمنية للحساب غير الموجود (لا تعداد حسابات بالزمن)،
// (3) لا يصدر رمز لحساب مجمَّد أو لبريد غير موثَّق (نفس شروط اللعب بالويب)، (4) للرمز تاريخ انتهاء (security.api.token_ttl_minutes)،
// (5) سقف لعدد الرموز الحيّة لكل مستخدم يمنع تراكمًا غير محدود.
class AuthController extends Controller
{
    private const MAX_LIVE_TOKENS = 10;

    private static ?string $dummyHash = null;

    public function login(Request $request)
    {
        // E26: جدول personal_access_tokens غير موجود بهذا المستودع (لا هجرة Sanctum) فكان الدخول ينهار بـ500 ويُسرّب تتبّع استثناء بالسجلات. واجهة الرموز خاملة
        // عمدًا حتى تُنشأ الهجرة مع تطبيق الموبايل؛ نُجيب 503 صريحًا بدل 500.
        if (! AccountSecurityService::tokensTableExists()) {
            return response()->json(['message' => 'تسجيل دخول التطبيقات غير مفعَّل بعد.'], 503);
        }

        $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:1024'],
            'device_name' => ['required', 'string', 'max:100'],
        ]);

        $user = User::where('email', $request->email)->first();

        // نجزّئ دائمًا (حقيقيًا أو وهميًا) كي لا يفرّق الزمن بين "لا حساب" و"كلمة مرور خاطئة".
        self::$dummyHash ??= Hash::make('e26-timing-equalizer');
        $passwordOk = Hash::check((string) $request->password, $user?->password ?? self::$dummyHash);

        if (! $user || ! $passwordOk) {
            throw ValidationException::withMessages([
                'email' => ['بيانات الدخول غير صحيحة.'],
            ]);
        }

        if ($user->is_frozen) {
            return response()->json(['message' => 'تم تقييد حسابك مؤقتاً. يرجى التواصل مع الدعم.'], 403);
        }

        if (! $user->hasVerifiedEmail()) {
            return response()->json(['message' => 'وثّق بريدك الإلكتروني أولاً.'], 403);
        }

        $this->trimLiveTokens($user);

        $ttl = (int) config('security.api.token_ttl_minutes', 43200);

        return response()->json([
            'token' => $user->createToken($request->device_name, ['*'], $ttl > 0 ? now()->addMinutes($ttl) : null)->plainTextToken,
            'expires_in_minutes' => $ttl > 0 ? $ttl : null,
            'user' => $user->only(['id', 'name', 'email']),
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'تم تسجيل الخروج.']);
    }

    /** يُبقي أحدث (MAX-1) رمزًا فقط قبل إصدار الجديد، فيحافظ السقف على MAX رمزًا حيًّا كحدّ أعلى. */
    private function trimLiveTokens(User $user): void
    {
        $ids = $user->tokens()->orderByDesc('id')->pluck('id');

        if ($ids->count() >= self::MAX_LIVE_TOKENS) {
            $user->tokens()->whereIn('id', $ids->slice(self::MAX_LIVE_TOKENS - 1)->all())->delete();
        }
    }
}
