<?php

namespace App\Http\Middleware;

use App\Services\Creators\CreatorEligibility;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * E28 - بوابة استوديو المبدعين: ملف مبدع معتمَد + بريد موثَّق + حساب غير مجمَّد (CreatorEligibility، نفس مصدر الحقيقة الذي تفحصه الخدمات).
 * غير المؤهَّل يُحوَّل لصفحة الطلب بسبب واضح (JSON: 403). الملف يُمرَّر بسمات الطلب كي لا يُستعلم مرتين. لا يتطلب أي صلاحية إدارة.
 * يُوضع بعد auth/verified/account.active.
 */
class EnsureCreatorStudioAccess
{
    public function __construct(protected CreatorEligibility $eligibility) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $reason = $this->eligibility->studioDenial($user);

        if ($reason !== null) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $reason], 403);
            }

            return redirect()->route('creator.apply')->with('error', $reason);
        }

        $request->attributes->set('creator_profile', $this->eligibility->profileFor($user));

        return $next($request);
    }
}
