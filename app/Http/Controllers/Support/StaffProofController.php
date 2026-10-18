<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Models\SponsorshipPayment;
use App\Services\OperationalAuditService;
use App\Services\Payments\PaymentProofService;
use Illuminate\Support\Facades\Gate;

/**
 * E32 - تنزيل الإثبات للمراجع المالي (sponsorships.confirm عبر SponsorshipPaymentPolicy::downloadProof). الملف خاص: لا رابط مباشر، التنزيل مرفق خامل، وكل تنزيل يُدقَّق (من ومتى).
 */
class StaffProofController extends Controller
{
    public function __construct(protected PaymentProofService $proofs, protected OperationalAuditService $audit) {}

    public function __invoke(SponsorshipPayment $payment)
    {
        Gate::authorize('downloadProof', $payment);
        abort_unless($this->proofs->exists($payment), 404);

        $this->audit->log('payment_proof_downloaded', $payment, ['public_id' => $payment->public_id, 'reference' => $payment->reference], auth()->user());

        return $this->proofs->download($payment);
    }
}
