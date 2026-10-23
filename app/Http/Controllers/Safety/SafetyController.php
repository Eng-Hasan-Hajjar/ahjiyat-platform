<?php

namespace App\Http\Controllers\Safety;

use App\Http\Controllers\Controller;
use App\Models\ModerationAppeal;
use App\Services\TrustSafety\AppealService;
use App\Services\TrustSafety\ModerationException;
use App\Services\TrustSafety\RestrictedAccess;
use App\Services\TrustSafety\UserSafetyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;

/**
 * E38 - «الأمان والإشراف» للمستخدم المعني. يقرأ إجراءاته هو وحده (UserSafetyService يبدأ دائمًا من target_user_id)،
 * ويقدّم/يسحب اعتراضًا عبر AppealService. لا يظهر هنا مبلِّغ ولا مشرف ولا سبب داخلي ولا مستخدم آخر.
 */
class SafetyController extends Controller
{
    public function __construct(protected UserSafetyService $safety, protected AppealService $appeals) {}

    public function index(Request $request): View
    {
        $user = $request->attributes->get('safety_user');

        return view('safety.index', ['overview' => $this->safety->overview($user), 'restricted' => Auth::guard('web')->guest()]);
    }

    public function show(Request $request, string $action): View
    {
        $user = $request->attributes->get('safety_user');
        $item = $this->safety->item($user, $action);

        abort_if($item === null, 404);

        return view('safety.show', ['item' => $item, 'restricted' => Auth::guard('web')->guest(), 'frozen' => (bool) $user->is_frozen]);
    }

    public function appeal(Request $request, string $action): RedirectResponse
    {
        $user = $request->attributes->get('safety_user');
        $target = $this->safety->find($user, $action);

        abort_if($target === null, 404);

        $data = $request->validate(['reason' => ['required', 'string', 'max:'.(int) config('trust_safety.appeals.reason_max')]]);

        try {
            $this->appeals->file($user, $target, $data['reason']);
        } catch (ModerationException $e) {
            return back()->withErrors(['reason' => $e->getMessage()])->withInput();
        }

        return redirect()->route('account.safety.show', $action)->with('success', 'وصلنا اعتراضك وسيراجعه فريق الإشراف.');
    }

    public function withdraw(Request $request, string $appeal): RedirectResponse
    {
        $user = $request->attributes->get('safety_user');
        $model = ModerationAppeal::query()->where('public_id', $appeal)->where('appellant_id', $user->id)->first();

        abort_if($model === null, 404);

        try {
            $this->appeals->withdraw($user, $model);
        } catch (ModerationException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return back()->with('success', 'سُحب اعتراضك.');
    }

    /** إنهاء سياق الوصول المقيَّد (للمجمَّد غير المصادَق). المصادَق يستعمل تسجيل الخروج المعتاد. */
    public function leave(Request $request): RedirectResponse
    {
        RestrictedAccess::revoke($request->session());

        return redirect()->route('login');
    }
}
