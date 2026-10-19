<?php

namespace App\Http\Controllers\Referrals;

use App\Http\Controllers\Controller;
use App\Services\Referrals\ReferralAnalytics;
use App\Services\Referrals\ReferralCampaignService;
use App\Services\Referrals\ReferralClickRecorder;
use App\Services\Referrals\ReferralCodeService;
use App\Services\Referrals\ReferralException;
use App\Services\Referrals\ReferralShareStats;
use App\Services\Referrals\ShareLinkBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * E33 - صفحة «دعوة الأصدقاء» الخاصة باللاعب. مجاميع فقط: لا أسماء ولا بريد ولا بيانات عن المُحالين ولا IP.
 * كل ما يُعرض مُستخرَج بمعرّف المستخدم الحالي من الجلسة (لا معرّف من الرابط). لا قائمة صدارة علنية للإحالات.
 */
class ReferralController extends Controller
{
    public function index(Request $request, ReferralCodeService $codes, ReferralAnalytics $analytics, ReferralCampaignService $campaigns, ShareLinkBuilder $shares)
    {
        abort_unless((bool) config('referrals.enabled'), 404);

        $user = $request->user();
        $code = $codes->ensureFor($user);

        return view('referrals.index', [
            'code' => $code->code,
            'link' => route('referrals.landing', $code->code),
            'stats' => $analytics->forReferrer($user),
            'campaigns' => $campaigns->publicLive(),
            'share' => $shares->build('platform', null, 'انضم إلي في أحجيات', 'أحجيات: ألغاز عربية وتحديات ممتعة. انضم عبر دعوتي.', $user),
            'rewardsOn' => (bool) config('referrals.rewards.enabled'),
        ]);
    }

    public function rotate(Request $request, ReferralCodeService $codes): RedirectResponse
    {
        abort_unless((bool) config('referrals.enabled'), 404);

        try {
            $codes->rotate($request->user());
        } catch (ReferralException $e) {
            return redirect()->route('referrals.index')->with('error', $e->getMessage());
        }

        return redirect()->route('referrals.index')->with('status', 'تم تغيير رمز دعوتك. الرمز القديم لم يعد يُسنِد دعوات جديدة.');
    }

    /** عدّاد مشاركة تقريبي (قوائم مغلقة). لا يمنح شيئًا ولا يؤثر على أي حالة؛ 204 دائمًا لمدخل صالح. */
    public function share(Request $request, ReferralShareStats $stats, ReferralClickRecorder $clicks, ReferralCodeService $codes): JsonResponse
    {
        abort_unless((bool) config('referrals.enabled'), 404);

        $data = $request->validate([
            'kind' => ['required', 'string', Rule::in((array) config('referrals.share_kinds'))],
            'target' => ['required', 'string', Rule::in((array) config('referrals.share_targets'))],
            'with_ref' => ['sometimes', 'boolean'],
        ]);

        $stats->record($data['kind'], $data['target']);

        if (! empty($data['with_ref']) && ($mine = $codes->activeFor($request->user())) !== null) {
            $clicks->recordShare($mine->id);
        }

        return response()->json([], 204);
    }
}
