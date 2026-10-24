<?php

namespace App\Http\Controllers\Commerce;

use App\Http\Controllers\Controller;
use App\Models\DigitalPurchase;
use App\Services\Commerce\CommercePurchasePresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * E41 - سجل مشتريات اللاعب + الإيصال القابل للطباعة + استطلاع حالة الشراء. **المالك وحده** (طلب غيره = 404) في كل مخرج؛ بلا أي بيانات لشراء مستخدم آخر.
 *  - السجل مُرقَّم ببساطة (simplePaginate) بأعمدة محددة: لا تحميل كل المشتريات ولا N+1.
 *  - الإيصال «Receipt / Purchase reference»: صفحة HTML قابلة للطباعة لمشتريات مؤكَّدة/مستردة فقط؛ لا فاتورة ضريبية ولا ادعاء امتثال ضريبي. لا أسرار ولا معرّفات مزوّد ولا ملاحظات مراجعة ولا مسارات إثبات.
 *  - الاستطلاع JSON قراءة فقط، من القاعدة مباشرة (بلا كاش للحالة)، بحد معدّل مسمّى وفاصل أدنى يفرضه العميل.
 */
class CommercePurchaseController extends Controller
{
    public function __construct(protected CommercePurchasePresenter $presenter) {}

    public function index(Request $request): View
    {
        $purchases = DigitalPurchase::query()
            ->where('user_id', $request->user()->getKey())
            ->select(['id', 'public_id', 'product_type', 'product_name', 'amount_minor', 'currency', 'provider', 'status', 'reference_code', 'review_flag', 'review_resolved_at', 'provider_status', 'created_at', 'verified_at', 'refunded_at'])
            ->orderByDesc('id')
            ->simplePaginate(max(1, (int) config('commerce.limits.history_per_page', 15)));

        return view('commerce.history', [
            'purchases' => $purchases,
            'presenter' => $this->presenter,
        ]);
    }

    public function receipt(Request $request, string $order): View
    {
        $purchase = $this->owned($request, $order);

        // الإيصال لمدفوع مؤكَّد أو مسترد فقط؛ غير المدفوع لا إيصال له.
        abort_unless(in_array($purchase->status, [DigitalPurchase::STATUS_VERIFIED, DigitalPurchase::STATUS_REFUNDED], true), 404);

        return view('commerce.receipt', ['receipt' => $this->presenter->receipt($purchase)]);
    }

    public function status(Request $request, string $order): JsonResponse
    {
        $purchase = $this->owned($request, $order);

        return response()->json($this->presenter->status($purchase))->header('Cache-Control', 'private, no-store');
    }

    protected function owned(Request $request, string $publicId): DigitalPurchase
    {
        return DigitalPurchase::query()
            ->where('public_id', $publicId)
            ->where('user_id', $request->user()->getKey())
            ->firstOrFail();
    }
}
