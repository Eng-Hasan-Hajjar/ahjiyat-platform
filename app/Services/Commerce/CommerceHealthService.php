<?php

namespace App\Services\Commerce;

use App\Models\CommerceProviderEvent;
use App\Models\DigitalPurchase;
use Illuminate\Support\Facades\DB;

/**
 * E41 - صحة المزوّدين للإدارة. **لا يعرض أي سر أبدًا** (لا مفتاح API ولا سر توقيع ولا token، ولا جزءًا منها): الحالة «مُهيَّأ ✅ / ناقص ❌» فقط (`isConfigured()`).
 * استعلامات مجمّعة (لا استعلام لكل مزوّد): آخر حدث ناجح من السجل، وأعداد الطلبات المفتوحة/المراجعة/الاسترداد المتعثر. فشل التوقيع من عدّاد الكاش (صحة تشغيلية لا تُخزَّن في القاعدة).
 */
class CommerceHealthService
{
    public function __construct(protected CommercePaymentProviderRegistry $registry, protected CommerceWebhookService $webhooks) {}

    /** @return list<array<string, mixed>> */
    public function providers(int $failureHours = 24): array
    {
        $providers = $this->registry->all();

        $lastApplied = CommerceProviderEvent::query()->where('processing_result', CommerceProviderEvent::RESULT_APPLIED)
            ->selectRaw('provider, MAX(received_at) AS last_at')->groupBy('provider')->pluck('last_at', 'provider');

        $counts = DigitalPurchase::query()
            ->selectRaw('provider,
                SUM(CASE WHEN status = ? AND review_flag IS NULL THEN 1 ELSE 0 END) AS awaiting,
                SUM(CASE WHEN review_flag IS NOT NULL AND review_resolved_at IS NULL THEN 1 ELSE 0 END) AS needs_review,
                SUM(CASE WHEN status = ? AND refund_requested_at IS NOT NULL THEN 1 ELSE 0 END) AS refund_pending', [DigitalPurchase::STATUS_AWAITING, DigitalPurchase::STATUS_VERIFIED])
            ->groupBy('provider')->get()->keyBy('provider');

        $out = [];

        foreach ($providers as $key => $provider) {
            $c = $counts[$key] ?? null;
            $out[] = [
                'key' => $key,
                'label' => $provider->label(),
                'mode' => $provider->mode(),
                'enabled' => $provider->isEnabled(),
                'configured' => $provider->isConfigured(),
                'redirect' => $provider->supportsRedirect(),
                'webhook' => $provider->supportsWebhook(),
                'accepts_webhooks' => $provider->acceptsWebhooks(),
                'refund' => $provider->supportsRefund(),
                'status_query' => $provider->supportsStatusQuery(),
                'currencies' => $provider->currencies(),
                'last_event_at' => isset($lastApplied[$key]) ? \Illuminate\Support\Carbon::parse($lastApplied[$key]) : null,
                'recent_failures' => $this->webhooks->recentFailures($key, $failureHours),
                'awaiting' => (int) ($c->awaiting ?? 0),
                'needs_review' => (int) ($c->needs_review ?? 0),
                'refund_pending' => (int) ($c->refund_pending ?? 0),
            ];
        }

        return $out;
    }

    /** أحداث السجل غير المطابقة لطلب (مراجعة تشغيلية). بلا حمولة خام. @return \Illuminate\Support\Collection<int, CommerceProviderEvent> */
    public function unmatchedEvents(?int $limit = null)
    {
        return CommerceProviderEvent::query()->whereNull('digital_purchase_id')
            ->orderByDesc('id')->limit(max(1, (int) ($limit ?? config('commerce.limits.admin_events_per_page', 25))))
            ->get(['id', 'provider', 'provider_event_id', 'event_type', 'normalized_state', 'amount_minor', 'currency', 'processing_result', 'result_detail', 'received_at']);
    }
}
