<?php

namespace App\Http\Controllers\Pass;

use App\Http\Controllers\Controller;
use App\Models\DigitalPurchase;
use App\Services\AhjiyatPass\PassException;
use App\Services\AhjiyatPass\PassLifecycleService;
use App\Services\AhjiyatPass\PassPageData;
use App\Services\AhjiyatPass\PassPremiumService;
use App\Services\Commerce\CommercePaymentProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * E40 - شراء المسار المميز (يدوي). **لا سعر ولا عملة ولا مسار ولا مميز من العميل**: المسار الجاري والسعر من الخادم، والمميز لا يُفتح إلا بتحقق مالي بالإدارة.
 * الملكية: الطلب يُحلّ بمعرّف عام ULID **ومحصورًا بالمستخدم** (طلب غيره = 404، لا IDOR). تقديم الطلب لا يمنح شيئًا ويُوصف بصدق في الواجهة.
 */
class PassOrderController extends Controller
{
    public function __construct(
        protected PassPremiumService $premium,
        protected PassLifecycleService $lifecycle,
        protected PassPageData $pages,
        protected CommercePaymentProvider $provider,
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

        return view('pass.premium', $data + ['requestKey' => (string) Str::uuid(), 'providerLabel' => $this->provider->label()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['request_key' => ['nullable', 'string', 'min:8', 'max:64']]);
        $pass = $this->lifecycle->current();

        abort_if($pass === null, 404);

        try {
            $purchase = $this->premium->request($request->user(), $pass, $data['request_key'] ?? null);
        } catch (PassException $e) {
            return redirect()->route('pass.premium')->with('error', $e->getMessage());
        }

        return redirect()->route('pass.orders.show', $purchase->public_id);
    }

    public function show(Request $request, string $order): View
    {
        $purchase = $this->owned($request, $order);

        return view('pass.order', [
            'purchase' => $purchase,
            'instructions' => $purchase->status === DigitalPurchase::STATUS_AWAITING || $purchase->status === DigitalPurchase::STATUS_SUBMITTED ? $this->provider->instructionsFor($purchase) : null,
            'proofRequired' => $this->provider->proofRequired(),
            'providerLabel' => $this->provider->label(),
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
