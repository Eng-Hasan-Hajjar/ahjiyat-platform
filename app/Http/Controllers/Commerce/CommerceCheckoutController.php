<?php

namespace App\Http\Controllers\Commerce;

use App\Http\Controllers\Controller;
use App\Models\DigitalPurchase;
use App\Services\Commerce\CommerceCheckoutService;
use App\Services\Commerce\CommerceException;
use App\Services\Commerce\CommercePurchasePresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * E41 - بدء الدفع المستضاف + صفحة العودة. **لا شيء من العميل يحدد المبلغ أو العملة أو المنتج أو المستخدم أو رابط العودة**: الطلب يُحلّ بمعرّف عام محصورًا بالمستخدم (طلب غيره = 404، لا IDOR).
 *  - POST /commerce/checkout/{order}: CSRF (مجموعة web) + حد معدّل. يعيد توجيه إلى عنوان المزوّد الذي اعتمده CheckoutUrlGuard فقط.
 *  - GET  /commerce/checkout/{order}/return: **عرض فقط**. عودة المتصفح ليست دليل دفع: تتجاهل كل معاملات الاستعلام (status/success/paid/amount…) ولا تكتب شيئًا أبدًا.
 *    الحالة المعروضة هي حالة DigitalPurchase الموثوقة من القاعدة (الـwebhook/المزامنة وحدهما يؤكدان الدفع).
 */
class CommerceCheckoutController extends Controller
{
    public function __construct(protected CommerceCheckoutService $checkout, protected CommercePurchasePresenter $presenter) {}

    public function start(Request $request, string $order): RedirectResponse
    {
        $purchase = $this->owned($request, $order);

        try {
            $started = $this->checkout->start($request->user(), $purchase);
        } catch (CommerceException $e) {
            return redirect($this->backTo($purchase))->with('error', $e->getMessage());
        }

        return redirect()->away($started['url']);
    }

    public function return(Request $request, string $order): View
    {
        $purchase = $this->owned($request, $order);

        return view('commerce.return', [
            'purchase' => $purchase,
            'presenter' => $this->presenter,
            'copy' => $this->presenter->copy($purchase, true),
            'phase' => $this->presenter->phase($purchase),
            'amount' => $this->presenter->amountLabel($purchase),
            'providerLabel' => $this->presenter->providerLabel($purchase),
            'poll' => [
                'url' => route('commerce.orders.status', $purchase->public_id),
                'min' => max(1, (int) config('commerce.status.poll_min_seconds', 5)),
                'max' => max(1, (int) config('commerce.status.poll_max_attempts', 24)),
            ],
            'ordersUrl' => $this->backTo($purchase),
        ]);
    }

    protected function owned(Request $request, string $publicId): DigitalPurchase
    {
        return DigitalPurchase::query()
            ->where('public_id', $publicId)
            ->where('user_id', $request->user()->getKey())
            ->firstOrFail();
    }

    /** صفحة الطلب في مجال المنتج: المسار المميز (E40)، أو صفحة السلسلة المميزة (E43). */
    protected function backTo(DigitalPurchase $purchase): string
    {
        return match ($purchase->product_type) {
            DigitalPurchase::PRODUCT_PASS_PREMIUM => route('pass.orders.show', $purchase->public_id),
            DigitalPurchase::PRODUCT_CREATOR_SERIES => route('series.show', $purchase->product_public_id),
            default => route('commerce.purchases.index'),
        };
    }
}
