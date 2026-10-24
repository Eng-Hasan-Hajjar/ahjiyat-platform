<?php

namespace App\Http\Controllers\Commerce;

use App\Http\Controllers\Controller;
use App\Models\DigitalPurchase;
use App\Services\Commerce\CommerceEnvironment;
use App\Services\Commerce\CommerceException;
use App\Services\Commerce\CommercePaymentProviderRegistry;
use App\Services\Commerce\CommerceProviderState;
use App\Services\Commerce\CommerceWebhookService;
use App\Services\Commerce\SandboxCommercePaymentProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * E41 - صفحة «الدفع المستضاف» **التجريبية** (محاكاة لموقع المزوّد) للتطوير والاختبار وCI فقط. ليست بوابة دفع حقيقية ولا تُسجَّل مساراتها في الإنتاج أصلًا
 * (routes/commerce.php) وهذا المتحكم يرفض أيضًا (404) خارج البيئات المسموحة — دفاع بعمق.
 * «الدفع» هنا يولّد حدثًا موقَّعًا بسر المحاكاة ثم يمرّ بـCommerceWebhookService::handle كاملًا (توقيع ← مرجع ← مبلغ/عملة ← حالة ← سجل) تمامًا كمزوّد حقيقي، ثم يعود المستخدم لصفحة العودة.
 * المالك وحده (المستخدم الذي بدأ الجلسة)؛ CSRF مفعَّل (مجموعة web).
 */
class CommerceSandboxController extends Controller
{
    public function __construct(protected CommercePaymentProviderRegistry $providers, protected CommerceWebhookService $webhooks) {}

    public function show(Request $request, string $session): View
    {
        $purchase = $this->owned($request, $session);

        return view('commerce.sandbox', [
            'purchase' => $purchase,
            'amount' => \App\Services\Sponsorship\SponsorshipMoney::format((int) $purchase->amount_minor, (string) $purchase->currency),
            'session' => $session,
        ]);
    }

    public function complete(Request $request, string $session, string $outcome): RedirectResponse
    {
        $purchase = $this->owned($request, $session);

        $state = match ($outcome) {
            'paid' => CommerceProviderState::Paid,
            'failed' => CommerceProviderState::Failed,
            'cancelled' => CommerceProviderState::Cancelled,
            default => abort(404),
        };

        try {
            /** @var SandboxCommercePaymentProvider $provider */
            $provider = $this->providers->get(SandboxCommercePaymentProvider::KEY);
            $event = $provider->simulate($session, $state);
            $this->webhooks->handle(SandboxCommercePaymentProvider::KEY, $event['payload'], $event['headers']);
        } catch (CommerceException) {
            // المحاكاة لا تكشف تفاصيل؛ الحالة تُقرأ من صفحة العودة.
        }

        return redirect()->route('commerce.checkout.return', ['order' => $purchase->public_id]);
    }

    protected function owned(Request $request, string $session): DigitalPurchase
    {
        abort_unless(CommerceEnvironment::sandboxAllowed() && preg_match('/^sbx_[a-f0-9]{24}$/', $session) === 1, 404);

        return DigitalPurchase::query()
            ->where('provider', SandboxCommercePaymentProvider::KEY)
            ->where('provider_session_id', $session)
            ->where('user_id', $request->user()->getKey())
            ->firstOrFail();
    }
}
