<?php

namespace App\Services\Payments;

use App\Models\SponsorshipContribution;
use App\Models\SponsorshipPayment;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * E32 - لوحة العمليات المالية (إدارة). تجميعات محدودة بعدد ثابت من الاستعلامات (لا حلقات) مع كاش قصير (analytics.cache_ttl). كلها قراءة فقط.
 * الأرقام الصافية (إجمالي المؤكَّد/المتوسط) من المساهمات المؤكَّدة بالمعادل المحاسبي؛ حصة المزوّد اليدوي من الطلبات المحقَّقة.
 */
class PaymentAnalytics
{
    /**
     * @return array{pending: int, awaiting_verification: int, verified_today: int, failed: int, refunded: int, confirmed_total_minor: int, confirmed_count: int, average_minor: int, manual_share: int, currency: string}
     */
    public function summary(bool $fresh = false): array
    {
        $compute = fn (): array => $this->compute();
        $ttl = (int) config('payments.analytics.cache_ttl', 120);

        if ($fresh || $ttl <= 0) {
            return $compute();
        }

        try {
            return Cache::remember('payments:analytics:v1', $ttl, $compute);
        } catch (\Throwable) {
            return $compute();
        }
    }

    public function forget(): void
    {
        try {
            Cache::forget('payments:analytics:v1');
        } catch (\Throwable) {
            // الكاش غير متاح: لا شيء يُمسح
        }
    }

    /** @return array<string, mixed> */
    protected function compute(): array
    {
        $counts = SponsorshipPayment::query()->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status');
        $since = now()->subDays(max(1, (int) config('payments.analytics.window_days', 30)));
        $failed = SponsorshipPayment::query()->whereIn('status', [SponsorshipPayment::STATUS_REJECTED, SponsorshipPayment::STATUS_CANCELLED, SponsorshipPayment::STATUS_EXPIRED])->where('updated_at', '>=', $since)->count();

        $confirmed = SponsorshipContribution::query()->where('status', SponsorshipContribution::STATUS_CONFIRMED)
            ->selectRaw('COALESCE(SUM(accounting_amount_minor), 0) as total, COUNT(*) as n')->first();
        $total = (int) ($confirmed->total ?? 0);
        $n = (int) ($confirmed->n ?? 0);

        $verified = (int) ($counts[SponsorshipPayment::STATUS_VERIFIED] ?? 0) + (int) ($counts[SponsorshipPayment::STATUS_REFUNDED] ?? 0);
        $manual = $verified === 0 ? 0 : SponsorshipPayment::query()->whereIn('status', [SponsorshipPayment::STATUS_VERIFIED, SponsorshipPayment::STATUS_REFUNDED])->where('provider', ManualPaymentProvider::KEY)->count();

        return [
            'pending' => (int) ($counts[SponsorshipPayment::STATUS_AWAITING] ?? 0),
            'awaiting_verification' => (int) ($counts[SponsorshipPayment::STATUS_SUBMITTED] ?? 0),
            'verified_today' => SponsorshipPayment::query()->where('status', SponsorshipPayment::STATUS_VERIFIED)->where('verified_at', '>=', now()->startOfDay())->count(),
            'failed' => $failed,
            'refunded' => (int) ($counts[SponsorshipPayment::STATUS_REFUNDED] ?? 0),
            'confirmed_total_minor' => $total,
            'confirmed_count' => $n,
            'average_minor' => $n > 0 ? intdiv($total, $n) : 0,
            'manual_share' => $verified > 0 ? (int) intdiv($manual * 100, $verified) : 0,
            'currency' => \App\Services\Sponsorship\SponsorshipMoney::accountingCurrency(),
        ];
    }
}
