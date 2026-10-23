<?php

namespace App\Http\Controllers\Creators;

use App\Models\CreatorPayout;
use App\Services\CreatorFinance\CreatorFinanceException;
use App\Services\CreatorFinance\CreatorPayoutService;
use App\Services\CreatorFinance\CreatorStatementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * E39 - أرباح المبدع: أرصدته وكشوف فتراته وطلبات دفعه. كل قراءة محصورة بملف المبدع الحالي (من الميدلوير)، والمالك يُحلّ بـpublic_id مع شرط user_id.
 * الإيقاف الشامل (creator_monetization.enabled=false) يردّ 404 لصفحات العرض. لا رصيد ولا تخصيص ولا مرجع يُكتب من هنا: طلب/إلغاء دفعة فقط عبر الخدمة.
 */
class CreatorEarningsController extends CreatorController
{
    public function __construct(protected CreatorStatementService $statements, protected CreatorPayoutService $payouts) {}

    public function index(Request $request)
    {
        abort_unless((bool) config('creator_monetization.enabled', true), 404);

        $data = $this->statements->dashboard($this->profile($request), max(1, (int) $request->integer('page', 1)));

        return view('creator.earnings', $data + ['profile' => $this->profile($request)]);
    }

    public function statement(Request $request, string $period)
    {
        abort_unless((bool) config('creator_monetization.enabled', true), 404);

        $data = $this->statements->statement($this->profile($request), $period);
        abort_if($data === null, 404);

        return view('creator.earnings-statement', $data + ['profile' => $this->profile($request)]);
    }

    public function requestPayout(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'string', 'max:20'],
            'method' => ['required', 'string', 'max:32'],
        ]);

        try {
            $this->payouts->request($request->user(), $validated['amount'], $validated['method']);
        } catch (CreatorFinanceException $e) {
            return redirect()->route('creator.earnings')->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('creator.earnings')->with('success', 'وصل طلب دفعتك وحُجز المبلغ لحين مراجعته.');
    }

    public function cancelPayout(Request $request, string $payout): RedirectResponse
    {
        $model = CreatorPayout::query()->where('public_id', $payout)->where('user_id', $request->user()->getKey())->firstOrFail();

        try {
            $this->payouts->cancel($request->user(), $model);
        } catch (CreatorFinanceException $e) {
            return redirect()->route('creator.earnings')->with('error', $e->getMessage());
        }

        return redirect()->route('creator.earnings')->with('success', 'أُلغي الطلب وأُعيد المبلغ إلى رصيدك المتاح.');
    }
}
