<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Services\Payments\PaymentException;
use App\Services\Payments\PaymentProviderRegistry;
use App\Services\Payments\SponsorshipPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * E32 - مدخل Webhook عام لمزوّدين إلكترونيين **مستقبليين** (POST /api/payments/webhooks/{provider}). لا مزوّد إلكتروني منفَّذ الآن، والمسار مغلق افتراضيًا (PAYMENTS_WEBHOOKS_ENABLED=false):
 *  - مزوّد غير معروف / معطَّل / لا يدعم webhook (مثل manual) ⇒ 404 بلا أي أثر.
 *  - لا يُعتمد أي حدث دون أن يتحقق المزوّد نفسه من **توقيعه** (verifyCallback)؛ فشل التحقق ⇒ 400 بلا أثر.
 *  - الجسم لا يُسجَّل ولا يُخزَّن (سقف حجم فقط)؛ لا أسرار في الاستجابة. تكرار الحدث (replay) آمن: الأثر مرتبط بحالة الطلب وقيود قاعدة البيانات الفريدة.
 * الاستجابة دائمًا JSON صغير بلا تفاصيل داخلية.
 */
class PaymentWebhookController extends Controller
{
    public function __construct(protected PaymentProviderRegistry $providers, protected SponsorshipPaymentService $payments) {}

    public function __invoke(Request $request, string $provider): JsonResponse
    {
        abort_unless((bool) config('payments.webhooks.enabled', false), 404);

        try {
            $adapter = $this->providers->get($provider);
        } catch (PaymentException) {
            abort(404);
        }

        abort_unless($adapter->supportsWebhook(), 404);
        abort_if(strlen((string) $request->getContent()) > (int) config('payments.webhooks.max_body_bytes', 65536), 413);

        try {
            $callback = $adapter->verifyCallback($request);
        } catch (PaymentException) {
            return response()->json(['ok' => false], 400);
        }

        $result = $this->payments->applyProviderCallback($adapter->key(), $callback);

        return response()->json(['ok' => true, 'result' => $result]);
    }
}
