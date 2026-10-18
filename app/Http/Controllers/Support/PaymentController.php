<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Http\Requests\Support\ProofUploadRequest;
use App\Http\Requests\Support\TransferredRequest;
use App\Models\SponsorshipPayment;
use App\Services\Payments\PaymentException;
use App\Services\Payments\PaymentInstructions;
use App\Services\Payments\PaymentProofService;
use App\Services\Payments\SponsorshipPaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * E32 - صفحات صاحب الطلب: تفاصيل الطلب والتعليمات، «حوّلت المبلغ»، رفع/استبدال الإثبات، الإلغاء قبل الإرسال، السجل، وتنزيل الإثبات.
 * كل وصول مقيَّد بالملكية **داخل الاستعلام نفسه** (ownedOrFail ⇒ 404 لغير المالك حتى لو كان مديرًا؛ المديرون يستعملون لوحة الإدارة): لا تسريب وجود الطلب، ولا ثغرة IDOR.
 * المعرّف العلني ULID فقط (المعرّف التسلسلي لا يُوجَّه). لا يُعرض للمالك: ملاحظات المراجع/رمز الرفض/مسار الإثبات/بصمته/مرجع المزوّد.
 */
class PaymentController extends Controller
{
    public function __construct(
        protected SponsorshipPaymentService $payments,
        protected PaymentInstructions $instructions,
        protected PaymentProofService $proofs,
    ) {}

    public function history(Request $request)
    {
        $payments = SponsorshipPayment::query()->where('user_id', $request->user()->id)
            ->with('program:id,slug,title')
            ->orderByDesc('id')
            ->select(['id', 'public_id', 'reference', 'sponsorship_program_id', 'amount_minor', 'currency', 'status', 'is_anonymous', 'created_at', 'user_id'])
            ->paginate(15);

        return view('support.history', ['payments' => $payments]);
    }

    public function show(Request $request, string $payment)
    {
        $model = $this->ownedOrFail($request, $payment, ['program:id,slug,title']);

        return view('support.payment', [
            'payment' => $model,
            'instructions' => $model->isOpen() ? $this->instructions->forPayment($model) : null,
            'proofAvailable' => $this->proofs->exists($model),
        ]);
    }

    public function transferred(TransferredRequest $request, string $payment): RedirectResponse
    {
        $model = $this->ownedOrFail($request, $payment);

        try {
            $this->payments->markTransferred($request->user(), $model, $request->input('payer_note'), $request->file('proof'));
        } catch (PaymentException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('support.payments.show', ['payment' => $model->public_id])
            ->with('success', 'طلب الدعم بانتظار التحقق. سنُعلمك فور مراجعته.');
    }

    public function uploadProof(ProofUploadRequest $request, string $payment): RedirectResponse
    {
        $model = $this->ownedOrFail($request, $payment);

        try {
            $this->payments->attachProof($request->user(), $model, $request->file('proof'));
        } catch (PaymentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'تم حفظ الإثبات.');
    }

    public function cancel(Request $request, string $payment): RedirectResponse
    {
        $model = $this->ownedOrFail($request, $payment);

        try {
            $this->payments->cancelByOwner($request->user(), $model);
        } catch (PaymentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('support.history')->with('success', 'تم إلغاء طلب الدعم.');
    }

    /** تنزيل المالك لإثباته هو فقط (مرفق خامل). */
    public function proof(Request $request, string $payment)
    {
        return $this->proofs->download($this->ownedOrFail($request, $payment));
    }

    /**
     * @param  list<string>  $with
     */
    protected function ownedOrFail(Request $request, string $publicId, array $with = []): SponsorshipPayment
    {
        abort_unless(preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $publicId) === 1, 404);

        return SponsorshipPayment::query()->where('user_id', $request->user()->id)->where('public_id', $publicId)->with($with)->firstOrFail();
    }
}
