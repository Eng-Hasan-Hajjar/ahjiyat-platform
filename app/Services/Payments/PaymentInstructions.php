<?php

namespace App\Services\Payments;

use App\Models\SponsorshipPayment;
use App\Services\PlatformSettingsService;

/**
 * E32 - تعليمات التحويل اليدوي العلنية (من إعدادات المنصة، مجموعة payments). حقول مقصودة للعرض فقط: عنوان/مستفيد/بنك/حساب/آيبان/ملاحظة مرجع/ملاحظة إضافية.
 * لا أسرار هنا ولا يُرفع منها شيء لصفحة عامة: تُعرض لصاحب الطلب فقط (صفحة طلبه). كل القيم نص عادي يُهرَّب عند العرض.
 */
class PaymentInstructions
{
    public function __construct(protected PlatformSettingsService $settings) {}

    /** @return array{title: string, beneficiary: ?string, bank_name: ?string, account: ?string, reference_note: ?string, extra_note: ?string, proof_required: bool} */
    public function manual(): array
    {
        $text = fn (string $key): ?string => filled($v = $this->settings->get('payments', $key)) ? trim((string) $v) : null;

        return [
            'title' => $text('manual_title') ?? 'تحويل بنكي',
            'beneficiary' => $text('manual_beneficiary'),
            'bank_name' => $text('manual_bank_name'),
            'account' => $text('manual_account'),
            'reference_note' => $text('manual_reference_note'),
            'extra_note' => $text('manual_extra_note'),
            'proof_required' => (bool) $this->settings->get('payments', 'proof_required', false),
        ];
    }

    /** @return array<string, mixed>|null null إن لم تكن طريقة يدوية أو لم تُضبط بيانات الحساب بعد. */
    public function forPayment(SponsorshipPayment $payment): ?array
    {
        if ($payment->provider !== ManualPaymentProvider::KEY) {
            return null;
        }

        $manual = $this->manual();

        return $manual + [
            'reference' => (string) $payment->reference,
            'amount' => $payment->amountLabel(),
            'configured' => $manual['beneficiary'] !== null || $manual['account'] !== null,
        ];
    }
}
