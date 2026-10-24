<?php

namespace App\Services\Commerce;

use App\Models\DigitalPurchase;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * E41 - مزامنة حالة مزوّد لطلب مستضاف (للمطابقة المجدولة، وخروج آمن حين لا يصل webhook أو لا يعود المتصفح). تستعلم المزوّد **فقط إن دعم الاستعلام** (لا تخترع حالة)،
 * ثم تمرّر النتيجة كحدث اصطناعي بمعرّف حتمي إلى المعالج نفسه (CommerceWebhookService::process) — فكل قواعد المبلغ/العملة/المرجع/الانتقال/الـidempotency هي هي.
 * انقطاع المزوّد لا يمسّ الشراء: يُسجَّل بأمان ويُعاد لاحقًا. pending لا يولّد حدثًا.
 */
class CommerceSyncService
{
    public function __construct(protected CommercePaymentProviderRegistry $registry, protected CommerceWebhookService $webhooks) {}

    /** @return array{result: string, detail: ?string, state: ?string} */
    public function sync(DigitalPurchase $purchase): array
    {
        try {
            $provider = $this->registry->get($purchase->provider);
        } catch (CommerceException) {
            return ['result' => 'skipped', 'detail' => 'unknown_provider', 'state' => null];
        }

        if (! $provider->supportsStatusQuery() || $purchase->provider_session_id === null) {
            return ['result' => 'skipped', 'detail' => 'unsupported', 'state' => null];
        }

        try {
            $status = $provider->fetchStatus($purchase);
        } catch (Throwable $e) {
            Log::warning('commerce.sync_failed', ['provider' => $purchase->provider, 'exception' => $e::class]);

            return ['result' => 'provider_unavailable', 'detail' => null, 'state' => null];
        }

        if ($status === null) {
            return ['result' => 'skipped', 'detail' => 'no_status', 'state' => null];
        }

        DigitalPurchase::query()->whereKey($purchase->getKey())->update(['provider_last_synced_at' => now()]);

        if ($status->state === CommerceProviderState::Pending || $status->state === CommerceProviderState::Unknown) {
            return ['result' => 'unchanged', 'detail' => $status->state->value, 'state' => $status->state->value];
        }

        $event = new CommerceWebhookEvent(
            provider: $provider->key(),
            eventId: 'sync:'.$purchase->public_id.':'.$status->state->value,
            eventType: 'sync.'.$status->state->value,
            state: $status->state,
            sessionId: $status->sessionId ?? $purchase->provider_session_id,
            paymentReference: $status->paymentReference,
            refundReference: $status->refundReference,
            reference: $purchase->reference_code,
            amountMinor: $status->amountMinor,
            currency: $status->currency,
            occurredAt: $status->paidAt,
            payloadSha256: null,
        );

        $outcome = $this->webhooks->process($event, 'sync');

        return ['result' => $outcome['result'], 'detail' => $outcome['detail'], 'state' => $status->state->value];
    }
}
