<?php

namespace App\Http\Controllers\Commerce;

use App\Http\Controllers\Controller;
use App\Services\Commerce\CommerceException;
use App\Services\Commerce\CommerceWebhookException;
use App\Services\Commerce\CommerceWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * E41 - مدخل webhook التجارة الاستهلاكية: POST /api/commerce/webhooks/{provider}. متحكم رفيع: كل المنطق في CommerceWebhookService.
 *  - مجموعة api (بلا جلسة/كوكيز/CSRF بطبيعتها)؛ **المصادقة = توقيع المزوّد حصرًا**، يُتحقق منه قبل أي أثر مالي. لا استثناء عام من CSRF.
 *  - مزوّد مجهول/لا يستقبل أحداثًا ⇒ 404 مجرَّدة (لا تكشف وجوده)؛ توقيع غائب/خاطئ ⇒ 401؛ طابع قديم/حمولة مشوّهة ⇒ 400؛ حمولة كبيرة ⇒ 413 قبل قراءة الجسم؛ فشل معالجة ⇒ 500 (المزوّد يعيد الإرسال، ولا أثر جزئي).
 *  - الاستجابة JSON صغير بلا تفاصيل داخلية ولا أسرار؛ الجسم والترويسات لا تُسجَّل ولا تُخزَّن.
 */
class CommerceWebhookController extends Controller
{
    public function __construct(protected CommerceWebhookService $webhooks) {}

    public function __invoke(Request $request, string $provider): JsonResponse
    {
        $declared = $request->headers->get('Content-Length');

        if ($declared !== null && ctype_digit($declared) && (int) $declared > (int) config('commerce.webhooks.max_body_bytes', 65_536)) {
            return response()->json(['ok' => false, 'error' => 'payload_too_large'], 413);
        }

        try {
            $outcome = $this->webhooks->handle($provider, (string) $request->getContent(), $request->headers->all());
        } catch (CommerceWebhookException $e) {
            return response()->json($e->httpStatus === 404 ? ['ok' => false] : ['ok' => false, 'error' => $e->reason], $e->httpStatus);
        } catch (CommerceException) {
            return response()->json(['ok' => false], 400);
        } catch (Throwable) {
            // سُجِّل وعُدَّ في الخدمة؛ لا نكشف شيئًا. 500 ⇒ المزوّد يعيد الإرسال ولا أثر جزئي (تراجعت المعاملة).
            return response()->json(['ok' => false, 'error' => 'processing_error'], 500);
        }

        return response()->json(['ok' => true, 'result' => $outcome['result']]);
    }
}
