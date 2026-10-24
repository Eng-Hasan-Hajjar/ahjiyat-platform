<?php

namespace App\Http\Controllers\Pass;

use App\Http\Controllers\Controller;
use App\Models\DigitalPurchase;
use App\Services\AhjiyatPass\PassException;
use App\Services\AhjiyatPass\PassLifecycleService;
use App\Services\AhjiyatPass\PassPageData;
use App\Services\AhjiyatPass\PassPremiumService;
use App\Services\Commerce\CommerceCheckoutService;
use App\Services\Commerce\CommerceException;
use App\Services\Commerce\CommercePaymentProviderRegistry;
use App\Services\Commerce\CommercePurchasePresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * E40/E41 - شراء المسار المميز (يدوي أو دفع إلكتروني مستضاف عبر مزوّد مسجَّل). **لا سعر ولا عملة ولا مسار ولا مميز من العميل**: المسار الجاري والسعر من الخادم، والمميز لا يُفتح إلا بتحقق مالي بالإدارة.
 * الملكية: الطلب يُحلّ بمعرّف عام ULID **ومحصورًا بالمستخدم** (طلب غيره = 404، لا IDOR). تقديم الطلب لا يمنح شيئًا ويُوصف بصدق في الواجهة.
 */
class PassOrderController extends Controller
{
    public function __construct(
        protected PassPremiumService $premium,
        protected PassLifecycleService $lifecycle,
        protected PassPageData $pages,
        protected CommercePaymentProviderRegistry $providers,
        protected CommerceCheckoutService $checkout,
        protected CommercePurchasePresenter $presenter,
    ) {}

    public function premium(Request $request): View|RedirectResponse
    {
        $pass = $this->lifecycle->current();

        abort_if($pass === null, 404);

        $data = $this->pages->build($request->user(), $pass);
        $purchase = $data['premium']['purchase'];

        if ($purchase instanceof DigitalPurchase && $purchase->isOpen()) {
            return redirect()->route('pass.orders.show', $purchase->public_id);
        }

        $currency = (string) $pass->currency;
        $manual = $this->providers->has('manual') ? $this->providers->get('manual') : null;

        try {
            $default = $this->providers->forNewPurchase($currency);
        } catch (CommerceException) {
            $default = null;
        }

        return view('pass.premium', $data + [
            'requestKey' => (string) Str::uuid(),
            'providerLabel' => ($default ?? $manual)?->label() ?? 'غير متاحة',
            'hostedDefault' => $default?->supportsRedirect() ?? false,
            // بديل يدوي متاح بجانب الدفع الإلكتروني (اختيار من المشتري ضمن المزوّدين المفعَّلين؛ السعر والعملة من الخادم دائمًا).
            'manualAlternative' => ($default?->supportsRedirect() ?? false) && $manual !== null && $manual->isEnabled() && $this->providers->acceptsCurrency($manual, $currency),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'request_key' => ['nullable', 'string', 'min:8', 'max:64'],
            // اختيار طريقة فقط (لا مزوّد خام ولا مبلغ ولا عملة): يُتحقق منه في الخدمة ضمن المزوّدين المفعَّلين.
            'method' => ['nullable', 'string', 'in:online,manual'],
        ]);
        $pass = $this->lifecycle->current();

        abort_if($pass === null, 404);

        try {
            $purchase = $this->premium->request($request->user(), $pass, $data['request_key'] ?? null, ($data['method'] ?? null) === 'manual');
        } catch (PassException $e) {
            return redirect()->route('pass.premium')->with('error', $e->getMessage());
        }

        // مزوّد مستضاف: نتابع فورًا إلى صفحة الدفع. تعذّر المزوّد (انقطاع…) ⇒ الطلب سليم ونعيد المستخدم لصفحته مع رسالة قابلة لإعادة المحاولة.
        if ($purchase->isHosted() && $purchase->status === DigitalPurchase::STATUS_AWAITING) {
            try {
                $started = $this->checkout->start($request->user(), $purchase);

                return redirect()->away($started['url']);
            } catch (CommerceException $e) {
                return redirect()->route('pass.orders.show', $purchase->public_id)->with('error', $e->getMessage());
            }
        }

        return redirect()->route('pass.orders.show', $purchase->public_id);
    }

    public function show(Request $request, string $order): View
    {
        $purchase = $this->owned($request, $order);
        $provider = $this->providers->has($purchase->provider) ? $this->providers->get($purchase->provider) : null;
        $copy = $this->presenter->copy($purchase);

        return view('pass.order', [
            'purchase' => $purchase,
            'instructions' => $provider !== null && ($purchase->status === DigitalPurchase::STATUS_AWAITING || $purchase->status === DigitalPurchase::STATUS_SUBMITTED) ? $provider->instructionsFor($purchase) : null,
            'proofRequired' => $provider?->proofRequired() ?? false,
            'providerLabel' => $this->presenter->providerLabel($purchase),
            // E41 (مستضاف): تقديم يدوي لا يظهر؛ «متابعة الدفع» تظهر ما دام الطلب قابلًا للدفع والمزوّد مفعَّل؛ والحالة الموثوقة وحدها تُعرض (لا نجاح مزيّف).
            'hosted' => $purchase->isHosted(),
            'phase' => $this->presenter->phase($purchase),
            'copy' => $copy,
            'canContinue' => $purchase->isHosted() && $purchase->status === DigitalPurchase::STATUS_AWAITING && ! $purchase->needsReview() && ($provider?->isEnabled() ?? false) && $provider->supportsRedirect(),
            'receiptUrl' => in_array($purchase->status, [DigitalPurchase::STATUS_VERIFIED, DigitalPurchase::STATUS_REFUNDED], true) ? route('commerce.receipts.show', $purchase->public_id) : null,
        ]);
    }

    public function submit(Request $request, string $order): RedirectResponse
    {
        $purchase = $this->owned($request, $order);

        $data = $request->validate([
            'transfer_reference' => ['nullable', 'string', 'max:'.(int) config('ahjiyat_pass.purchase.transfer_reference_max', 120)],
            'payer_note' => ['nullable', 'string', 'max:'.(int) config('ahjiyat_pass.purchase.payer_note_max', 255)],
            'proof' => ['nullable', 'file', 'max:'.(int) config('payments.proof.max_kb', 5120)],
        ]);

        try {
            $this->premium->submit($request->user(), $purchase, $data['transfer_reference'] ?? null, $data['payer_note'] ?? null, $request->file('proof'));
        } catch (PassException $e) {
            return back()->withInput($request->only(['transfer_reference', 'payer_note']))->with('error', $e->getMessage());
        }

        return redirect()->route('pass.orders.show', $purchase->public_id)->with('success', 'تم استلام تحويلك وهو الآن قيد المراجعة. لن يُفتح المسار المميز إلا بعد تأكيد الدفع.');
    }

    public function cancel(Request $request, string $order): RedirectResponse
    {
        $purchase = $this->owned($request, $order);

        try {
            $this->premium->cancelByOwner($request->user(), $purchase);
        } catch (PassException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('pass.index')->with('success', 'تم إلغاء الطلب.');
    }

    protected function owned(Request $request, string $publicId): DigitalPurchase
    {
        return DigitalPurchase::query()
            ->where('public_id', $publicId)
            ->where('user_id', $request->user()->getKey())
            ->where('product_type', DigitalPurchase::PRODUCT_PASS_PREMIUM)
            ->firstOrFail();
    }
}
