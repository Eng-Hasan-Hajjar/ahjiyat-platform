<?php

namespace App\Services\Payments;

use App\Models\SponsorshipPayment;
use App\Services\OperationalAuditService;
use Illuminate\Support\Facades\DB;

/**
 * E32 - الاحتفاظ بإثباتات التحويل (بيانات مالية/شخصية). **لا يُحذف سجل الطلب أبدًا**؛ يُقلَّم ملف الإثبات فقط بعد مدة من الحالة النهائية (PAYMENTS_PROOF_RETENTION_DAYS)،
 * وتُحفظ بصمته ونوعه وحجمه لأغراض التدقيق. لا يُقلَّم أبدًا إثبات طلب مفتوح (بانتظار الدفع/قيد التحقق)، ولا إثبات مرفوض أو مسترد قبل انقضاء نافذة النزاع (PAYMENTS_DISPUTE_WINDOW_DAYS).
 * --dry-run يعدّ فقط بلا أي حذف.
 */
class PaymentRetentionService
{
    public function __construct(protected PaymentProofService $proofs, protected OperationalAuditService $audit) {}

    /** @return \Illuminate\Database\Eloquent\Builder<SponsorshipPayment> */
    public function candidates()
    {
        $retention = max(1, (int) config('payments.retention.proof_days_after_final', 365));
        $dispute = max($retention, max(0, (int) config('payments.retention.dispute_window_days', 90)));
        $cut = now()->subDays($retention);
        $disputeCut = now()->subDays($dispute);

        return SponsorshipPayment::query()->whereNotNull('proof_path')->whereNull('proof_purged_at')
            ->where(function ($q) use ($cut, $disputeCut) {
                $q->where(fn ($w) => $w->where('status', SponsorshipPayment::STATUS_VERIFIED)->where('verified_at', '<', $cut))
                    ->orWhere(fn ($w) => $w->where('status', SponsorshipPayment::STATUS_REFUNDED)->where('refunded_at', '<', $disputeCut))
                    ->orWhere(fn ($w) => $w->where('status', SponsorshipPayment::STATUS_REJECTED)->where('rejected_at', '<', $disputeCut))
                    ->orWhere(fn ($w) => $w->where('status', SponsorshipPayment::STATUS_CANCELLED)->where('cancelled_at', '<', $cut))
                    ->orWhere(fn ($w) => $w->where('status', SponsorshipPayment::STATUS_EXPIRED)->where('expired_at', '<', $cut));
            });
    }

    public function count(): int
    {
        return $this->candidates()->count();
    }

    /** @return int عدد الإثباتات المُقلَّمة. */
    public function prune(?int $limit = null): int
    {
        $limit ??= max(1, (int) config('payments.retention.prune_batch', 200));
        $count = 0;

        foreach ($this->candidates()->orderBy('id')->limit($limit)->pluck('id') as $id) {
            $path = null;

            $done = DB::transaction(function () use ($id, &$path): bool {
                $locked = SponsorshipPayment::query()->lockForUpdate()->find($id);

                if ($locked === null || $locked->proof_path === null || $locked->proof_purged_at !== null || $locked->isOpen()) {
                    return false;
                }

                $path = $locked->proof_path;
                $locked->forceFill(['proof_path' => null, 'proof_purged_at' => now()])->save();
                $this->audit->log('payment_proof_pruned', $locked, ['public_id' => $locked->public_id, 'status' => $locked->status]);

                return true;
            });

            if ($done) {
                $this->proofs->delete($path);
                $count++;
            }
        }

        return $count;
    }
}
