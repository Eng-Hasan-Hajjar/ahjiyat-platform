<?php

namespace App\Services\Commerce;

use App\Models\DigitalPurchase;
use Throwable;

/**
 * E41 - صيانة التجارة المجدولة (idempotent ومحدودة، **بلا استطلاع لا نهائي**):
 *  1) مزامنة طلبات مستضافة «بانتظار الدفع» حديثة لم يصل لها webhook (استعلام المزوّد فقط إن دعمه) عبر المعالج الموثوق نفسه — دفعة صغيرة، حدّ أدنى للعمر، نافذة عمر قصوى.
 *  2) إعادة محاولة الإشعارات المعزولة (تأكيد/فشل/إجراء مطلوب/استرداد) لطلبات مستضافة حديثة: مفاتيح idempotency دلالية ⇒ لا إشعار مكرر.
 * انتهاء الطلبات المتأخرة يبقى في `ahjiyat-pass:process` (E40) لأن الحالة والمنتج هناك؛ هذه الصيانة لا تنهي ولا تؤكد بنفسها ولا تكتب في السجل المالي إلا عبر المسار الموثوق.
 * خطأ طلب واحد لا يوقف الباقي (عزل).
 */
class CommerceMaintenanceService
{
    public function __construct(protected CommerceSyncService $sync, protected CommerceNotifier $notifier) {}

    /** @return array{synced: int, applied: int, unavailable: int, notified: int, scanned: int, dry_run: bool} */
    public function run(bool $dryRun = false, ?int $limit = null): array
    {
        $batch = max(1, min((int) ($limit ?? config('commerce.reconcile.sync_batch', 50)), (int) config('commerce.reconcile.max_limit', 5000)));
        $stats = ['synced' => 0, 'applied' => 0, 'unavailable' => 0, 'notified' => 0, 'scanned' => 0, 'dry_run' => $dryRun];

        $minAge = max(1, (int) config('commerce.reconcile.sync_min_age_minutes', 5));
        $maxAge = max(1, (int) config('commerce.reconcile.sync_max_age_hours', 72));

        $candidates = DigitalPurchase::query()
            ->where('status', DigitalPurchase::STATUS_AWAITING)
            ->where('provider', '<>', 'manual')
            ->whereNotNull('provider_session_id')
            ->whereNull('review_flag')
            ->where('created_at', '<=', now()->subMinutes($minAge))
            ->where('created_at', '>=', now()->subHours($maxAge))
            ->where(fn ($w) => $w->whereNull('provider_last_synced_at')->orWhere('provider_last_synced_at', '<=', now()->subMinutes($minAge)))
            ->orderBy('id')
            ->limit($batch)
            ->get();

        $stats['scanned'] = $candidates->count();

        foreach ($candidates as $purchase) {
            if ($dryRun) {
                $stats['synced']++;

                continue;
            }

            try {
                $outcome = $this->sync->sync($purchase);
            } catch (Throwable $e) {
                report($e);
                $stats['unavailable']++;

                continue;
            }

            if ($outcome['result'] === 'provider_unavailable') {
                $stats['unavailable']++;

                continue;
            }

            $stats['synced']++;

            if ($outcome['result'] === 'applied') {
                $stats['applied']++;
            }
        }

        if (! $dryRun) {
            $stats['notified'] = $this->retryNotifications($batch);
        }

        return $stats;
    }

    /** إعادة محاولة الإشعارات لطلبات مستضافة حديثة (آخر 24 ساعة). الإشعار المكرر مستحيل بمفاتيح idempotency. */
    protected function retryNotifications(int $batch): int
    {
        $since = now()->subDay();
        $sent = 0;

        $recent = DigitalPurchase::query()
            ->where('provider', '<>', 'manual')
            ->where(fn ($w) => $w
                ->where('verified_at', '>=', $since)
                ->orWhere('refunded_at', '>=', $since)
                ->orWhere('review_flagged_at', '>=', $since)
                ->orWhere(fn ($f) => $f->where('status', DigitalPurchase::STATUS_AWAITING)->whereIn('provider_status', [CommerceProviderState::Failed->value, CommerceProviderState::Cancelled->value])->where('updated_at', '>=', $since)))
            ->orderByDesc('id')
            ->limit($batch)
            ->get();

        foreach ($recent as $purchase) {
            try {
                if ($purchase->status === DigitalPurchase::STATUS_VERIFIED || ($purchase->status === DigitalPurchase::STATUS_REFUNDED && $purchase->verified_at !== null && $purchase->verified_at >= $since)) {
                    $this->notifier->verified($purchase);
                }

                if ($purchase->status === DigitalPurchase::STATUS_REFUNDED) {
                    $this->notifier->refunded($purchase);
                }

                if ($purchase->needsReview()) {
                    $this->notifier->actionRequired($purchase);
                }

                if ($purchase->status === DigitalPurchase::STATUS_AWAITING && $purchase->providerState()?->isTerminalFailure()) {
                    $this->notifier->failed($purchase);
                }

                $sent++;
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $sent;
    }
}
