<?php

namespace App\Http\Controllers\Referrals;

use App\Http\Controllers\Controller;
use App\Services\Referrals\ReferralIntent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * E33 (A) - /r/{code}: يلتقط النية (للضيف فقط) ثم يعيد التوجيه لوجهة **داخلية من قائمة بيضاء** (config attribution.landings).
 * لا open redirect: ?to= مفتاح من قائمة مغلقة (لا URL)، وغير المعروف يسقط للتسجيل. الرمز غير الصالح يتصرف مثل الصالح تمامًا من ناحية الاستجابة (لا تسريب).
 * المصادَق لا يُسنَد إليه شيء ويُوجَّه للرئيسية.
 */
class ReferralLandingController extends Controller
{
    public function __invoke(Request $request, string $code, ReferralIntent $intent): RedirectResponse
    {
        if (! (bool) config('referrals.enabled')) {
            return redirect()->route('home');
        }

        if ($request->user() !== null) {
            return redirect()->route('home');
        }

        $intent->capture($request, $code, $request->query('c'), 'link');

        $landings = (array) config('referrals.attribution.landings');
        $to = $request->query('to');
        $key = is_string($to) && isset($landings[$to]) ? $to : (string) config('referrals.attribution.default_landing');

        return redirect()->route($landings[$key] ?? 'register');
    }
}
