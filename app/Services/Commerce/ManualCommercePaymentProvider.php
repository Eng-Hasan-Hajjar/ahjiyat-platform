<?php

namespace App\Services\Commerce;

use App\Models\DigitalPurchase;
use App\Services\Payments\PaymentInstructions;
use App\Services\PlatformSettingsService;

/**
 * E40 - المزوّد اليدوي للمشتريات الرقمية: الدفع يتم خارج المنصة (تحويل بنكي/حوالة) ثم يقدّم المستخدم مرجع التحويل (وإثباتًا خاصًا إن لزم) ويتحقق مراجع مخوَّل.
 * يعيد استخدام تعليمات التحويل العلنية في إعدادات المنصة (مجموعة payments) كما هي، دون أي أسرار هنا. لا webhook ولا إعادة توجيه ولا استرداد عبر مزوّد.
 * E41: سلوكه كما كان (يبقى مزوّدًا صالحًا بالكامل)؛ العمليات الإلكترونية الجديدة غير مدعومة صراحةً (ترفع CommerceException('unsupported')) ولا تُستدعى له أصلًا.
 */
class ManualCommercePaymentProvider implements CommercePaymentProvider
{
    public const KEY = 'manual';

    public function __construct(protected PlatformSettingsService $settings, protected PaymentInstructions $instructions) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return (string) ($this->settings->get('payments', 'manual_title') ?: 'تحويل بنكي');
    }

    public function isEnabled(): bool
    {
        return (bool) config('payments.providers.manual.enabled', true) && (bool) $this->settings->get('payments', 'manual_enabled', true);
    }

    public function currencies(): ?array
    {
        $raw = trim((string) $this->settings->get('payments', 'allowed_currencies', ''));

        if ($raw === '') {
            return null;
        }

        $codes = array_values(array_unique(array_filter(
            array_map(static fn (string $c): string => strtoupper(trim($c)), explode(',', $raw)),
            static fn (string $c): bool => preg_match('/^[A-Z]{3}$/', $c) === 1
        )));

        return $codes === [] ? null : $codes;
    }

    public function mode(): string
    {
        return 'manual';
    }

    public function isConfigured(): bool
    {
        $manual = $this->instructions->manual();

        return $manual['beneficiary'] !== null || $manual['account'] !== null;
    }

    public function acceptsWebhooks(): bool
    {
        return false;
    }

    public function supportsStatusQuery(): bool
    {
        return false;
    }

    public function supportsRedirect(): bool
    {
        return false;
    }

    public function supportsWebhook(): bool
    {
        return false;
    }

    public function supportsRefund(): bool
    {
        return false;
    }

    public function instructionsFor(DigitalPurchase $purchase): ?array
    {
        if ($purchase->provider !== self::KEY) {
            return null;
        }

        $manual = $this->instructions->manual();

        return $manual + [
            'reference' => (string) $purchase->reference_code,
            'configured' => $manual['beneficiary'] !== null || $manual['account'] !== null,
        ];
    }

    public function proofRequired(): bool
    {
        return (bool) config('ahjiyat_pass.purchase.proof_enabled', true) && (bool) $this->instructions->manual()['proof_required'];
    }

    public function createCheckout(CommerceCheckoutRequest $request): CommerceCheckoutInit
    {
        throw new CommerceException('unsupported', 'هذه الطريقة لا تدعم الدفع الإلكتروني المستضاف.');
    }

    public function verifyWebhook(string $payload, array $headers): CommerceWebhookEvent
    {
        throw new CommerceWebhookException('unsupported', 'هذه الطريقة لا تستقبل أحداثًا خارجية.', 404);
    }

    public function refund(DigitalPurchase $purchase, string $idempotencyKey): CommerceRefundResult
    {
        return CommerceRefundResult::failed('unsupported');
    }

    public function fetchStatus(DigitalPurchase $purchase): ?CommerceProviderStatus
    {
        return null;
    }
}
