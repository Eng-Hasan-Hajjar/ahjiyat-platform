<?php

namespace App\Services\Commerce;

use App\Models\DigitalPurchase;

/**
 * E40 - عقد مزوّد دفع **المشتريات الرقمية الاستهلاكية** (المسار المميز). منفصل عمدًا عن PaymentProvider (E32) الذي يخدم SponsorshipPayment:
 * لا نعيد استخدام كيان الرعاية ولا نلوّث دلالاته. المنفَّذ الآن: ManualCommercePaymentProvider فقط (تحويل يدوي + إثبات خاص + تحقق مراجع مخوَّل).
 * مزوّد إلكتروني مستقبلي ينفّذ هذا العقد ويُسجَّل بالحاوية دون المساس بنموذج DigitalPurchase أو بقواعد فتح المميز (التحقق وحده يفتحه، خدمة PassPremiumService).
 *
 * قواعد ملزمة لأي تنفيذ: لا رقم بطاقة/CVV/PIN/OTP/كلمة مرور بنكية في قاعدة البيانات أو السجلات؛ لا ثقة بمبلغ أو سعر من العميل؛ المرجع فريد؛ التحقق من التوقيع قبل أي أثر.
 */
interface CommercePaymentProvider
{
    /** مفتاح ثابت (حروف صغيرة/أرقام/شرطة سفلية، ≤32) يُحفظ في digital_purchases.provider. */
    public function key(): string;

    /** تسمية عربية للعرض. */
    public function label(): string;

    /** مفعَّل الآن؟ (شرط النشر ∧ مفتاح الإدارة). غير المفعَّل ⇒ لا طلبات شراء جديدة. */
    public function isEnabled(): bool;

    /** العملات التي يقبلها (ISO) أو null = كل المسموح عامة. */
    public function currencies(): ?array;

    /** يوجّه لصفحة دفع خارجية؟ (اليدوي: لا). */
    public function supportsRedirect(): bool;

    /** يستقبل webhook خادم-إلى-خادم؟ (اليدوي: لا). */
    public function supportsWebhook(): bool;

    /** استرداد عبر المزوّد؟ (اليدوي: لا — الاسترداد المالي خارجي ثم يُسجَّل بالنظام). */
    public function supportsRefund(): bool;

    /**
     * تعليمات الدفع العلنية لصاحب الطلب فقط (تُهرَّب عند العرض). null إن لم تُضبط بيانات الدفع بعد.
     *
     * @return array<string, mixed>|null
     */
    public function instructionsFor(DigitalPurchase $purchase): ?array;

    /** هل يجب إرفاق إثبات تحويل لتقديم الطلب؟ */
    public function proofRequired(): bool;
}
