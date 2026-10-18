<?php

namespace App\Services\Payments;

use App\Models\SponsorshipPayment;
use App\Services\PlatformSettingsService;
use Illuminate\Http\Request;

/**
 * E32 - المزوّد اليدوي (تحويل بنكي/حوالة): يعمل كاملًا الآن. الدفع يتم خارج المنصة، ثم يُرسل المستخدم الإثبات، ويتحقق مراجع مخوَّل بـSponsorshipPaymentService::verify.
 * لا webhook ولا إعادة توجيه ولا استرداد عبر مزوّد (الاسترداد الفعلي خارجي ثم يُسجَّل بالنظام). التفعيل = شرط النشر (PAYMENTS_MANUAL_ENABLED) ∧ مفتاح الإدارة (payments.manual_enabled).
 */
class ManualPaymentProvider implements PaymentProvider
{
    public const KEY = 'manual';

    public function __construct(protected PlatformSettingsService $settings) {}

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

    /** @return list<string>|null قائمة إدارة اختيارية (payments.allowed_currencies)؛ null = بلا تضييق إضافي. */
    public function currencies(): ?array
    {
        $raw = trim((string) $this->settings->get('payments', 'allowed_currencies', ''));

        if ($raw === '') {
            return null;
        }

        $codes = array_values(array_unique(array_filter(array_map(static fn (string $c): string => strtoupper(trim($c)), explode(',', $raw)), static fn (string $c): bool => preg_match('/^[A-Z]{3}$/', $c) === 1)));

        return $codes === [] ? null : $codes;
    }

    public function createPayment(SponsorshipPayment $payment): PaymentInit
    {
        return new PaymentInit;
    }

    public function verifyCallback(Request $request): ProviderCallback
    {
        throw new PaymentException('هذه الطريقة لا تستقبل إشعارات تلقائية.');
    }

    public function refundReference(SponsorshipPayment $payment): ?string
    {
        return null;
    }

    public function status(SponsorshipPayment $payment): string
    {
        return (string) $payment->status;
    }
}
