<?php

namespace App\Services\Commerce;

use App\Models\DigitalPurchase;

/**
 * E40/E41 - عقد مزوّد دفع **المشتريات الرقمية الاستهلاكية** (المسار المميز). منفصل عمدًا عن PaymentProvider (E32) الذي يخدم SponsorshipPayment:
 * لا نعيد استخدام كيان الرعاية ولا نلوّث دلالاته. DigitalPurchase هو السلطة؛ المزوّد آلية دفع خارجية لا تصير سلطة أبدًا.
 *
 * المنفَّذ: ManualCommercePaymentProvider (E40: تحويل يدوي + إثبات خاص + تحقق مراجع) و SandboxCommercePaymentProvider (E41: محاكاة محلية للاختبار فقط).
 * مزوّد حيّ مستقبلي ينفّذ هذا العقد ويُسجَّل في config/commerce.php دون المساس بنموذج DigitalPurchase أو بقواعد فتح المميز (التحقق وحده يفتحه، عبر مسار التحقق الموحَّد).
 *
 * قواعد ملزمة لأي تنفيذ:
 *  - لا رقم بطاقة/CVV/PIN/OTP/كلمة مرور بنكية في قاعدة البيانات أو السجلات؛ لا ثقة بمبلغ أو سعر من العميل؛ التحقق من التوقيع قبل أي أثر؛ لا أسرار في السجلات.
 *  - عناوين المزوّد من إعداد/محوّل موثوق فقط (لا جلب عناوين من المستخدم). أي HTTP عبر عميل Laravel بمهلات (timeout/connect_timeout)، وإعادة محاولة محدودة للعمليات الآمنة فقط؛
 *    **لا إعادة محاولة تلقائية لإنشاء الدفعة** إلا بمفتاح idempotency ثابت (CommerceCheckoutRequest::$idempotencyKey) يضمن عدم الازدواج.
 *  - الاختبارات لا تتصل بشبكة حقيقية أبدًا (محوّل تجريبي أو Http::fake).
 */
interface CommercePaymentProvider
{
    /** مفتاح ثابت (حروف صغيرة/أرقام/شرطة سفلية، ≤32) يُحفظ في digital_purchases.provider. */
    public function key(): string;

    /** تسمية عربية للعرض. */
    public function label(): string;

    /** وضع المزوّد للعرض التشغيلي: manual | sandbox | live. */
    public function mode(): string;

    /** مفعَّل الآن لـ**إنشاء طلبات/جلسات جديدة**؟ (شرط النشر ∧ مفتاح الإدارة). تعطيله لا يوقف webhook ولا الاسترداد ولا المطابقة لما أُنشئ سابقًا. */
    public function isEnabled(): bool;

    /** هل ضبطه التشغيلي مكتمل (أسراره/إعداداته موجودة)؟ منطقي فقط — لا يكشف قيمة أي سر أبدًا. */
    public function isConfigured(): bool;

    /** هل يستقبل webhook لعمليات قائمة؟ مستقل عن isEnabled عمدًا (AV). */
    public function acceptsWebhooks(): bool;

    /** العملات التي يقبلها (ISO) أو null = كل المسموح عامة. */
    public function currencies(): ?array;

    /** يوجّه لصفحة دفع خارجية؟ (اليدوي: لا). */
    public function supportsRedirect(): bool;

    /** يستقبل webhook خادم-إلى-خادم؟ (اليدوي: لا). */
    public function supportsWebhook(): bool;

    /** استرداد كامل عبر المزوّد؟ (اليدوي: لا — الاسترداد المالي خارجي ثم يُسجَّل بالنظام). */
    public function supportsRefund(): bool;

    /** يدعم استعلام حالة الدفعة (للمزامنة/المطابقة)؟ لا يُخترع استعلام إن لم يدعمه. */
    public function supportsStatusQuery(): bool;

    /**
     * تعليمات الدفع العلنية لصاحب الطلب فقط (تُهرَّب عند العرض). null إن لم تُضبط بيانات الدفع بعد.
     *
     * @return array<string, mixed>|null
     */
    public function instructionsFor(DigitalPurchase $purchase): ?array;

    /** هل يجب إرفاق إثبات تحويل لتقديم الطلب؟ */
    public function proofRequired(): bool;

    /**
     * ينشئ جلسة دفع مستضافة. **Idempotent** بمفتاح الطلب: نفس المفتاح ⇒ نفس الجلسة (لا ازدواج). عنوان الصفحة من المزوّد حصرًا.
     *
     * @throws CommerceException فشل مؤقت (retryable) أو دائم؛ الشراء المحلي يبقى سليمًا
     */
    public function createCheckout(CommerceCheckoutRequest $request): CommerceCheckoutInit;

    /**
     * يتحقق من توقيع webhook ويطبّعه. **قبل أي أثر مالي.** لا يقرأ قاعدة البيانات ولا يغيّر شيئًا.
     *
     * @param  array<string, list<string|null>|string|null>  $headers
     *
     * @throws CommerceWebhookException توقيع غائب/خاطئ/طابع قديم/حمولة مشوّهة
     */
    public function verifyWebhook(string $payload, array $headers): CommerceWebhookEvent;

    /**
     * استرداد **كامل** فقط (لا جزئي). المفتاح ثابت ('refund:'.public_id) ⇒ تكرار النداء لا يكرّر الاسترداد عند المزوّد.
     * لا يرمي للأخطاء المتوقعة: يعيد CommerceRefundResult::failed(...). الاستثناء للأعطال غير المتوقعة فقط (يُعامل كفشل).
     */
    public function refund(DigitalPurchase $purchase, string $idempotencyKey): CommerceRefundResult;

    /** حالة الدفعة عند المزوّد، أو null إن لم يكن الاستعلام مدعومًا/لا معلومة. لا يخترع حالة. */
    public function fetchStatus(DigitalPurchase $purchase): ?CommerceProviderStatus;
}
