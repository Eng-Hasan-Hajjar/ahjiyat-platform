<?php

namespace App\Services\Payments;

use App\Models\SponsorshipPayment;
use Illuminate\Http\Request;

/**
 * E32 - عقد مزوّد الدفع (محايد للمزوّد). المزوّد الوحيد المنفَّذ الآن: ManualPaymentProvider (تحويل يدوي + تحقق مالي).
 * مزوّد إلكتروني مستقبلي (Stripe/PayPal/PayTabs/HyperPay/Tap/MyFatoorah/بوابة بنكية) ينفّذ هذا العقد ويُسجَّل في config/payments.php دون المساس بنموذج SponsorshipContribution
 * (المساهمة تُنشأ دائمًا عبر SponsorshipPaymentService::verify فقط). تفاصيل ما يجب تنفيذه: docs/sponsorship-payments-e32.md («عقد المزوّد الإلكتروني المستقبلي»).
 *
 * قواعد ملزمة لأي تنفيذ:
 *  - لا يُحفظ رقم بطاقة/CVV/أسرار مزوّد/جسم webhook خام في قاعدة البيانات أو السجلات.
 *  - verifyCallback **يتحقق من توقيع المزوّد** (أو يرمي استثناءً) قبل أي أثر؛ لا يعتمد على مصدر الطلب أو عنوانه.
 *  - مرجع المزوّد فريد داخل المزوّد (قيد قاعدة بيانات)، ومعرّف الحدث يجعل المعالجة idempotent.
 */
interface PaymentProvider
{
    /** مفتاح فريد ثابت (حروف صغيرة/أرقام/شرطة سفلية، ≤32). */
    public function key(): string;

    /** تسمية عربية للعرض. */
    public function label(): string;

    /** مفعَّل الآن؟ (إعداد النشر + أي مفتاح إدارة). المزوّد غير المفعَّل يرفضه السجل كأنه غير موجود. */
    public function isEnabled(): bool;

    /** هل يوجّه المستخدم لصفحة دفع خارجية؟ (اليدوي: لا). */
    public function supportsRedirect(): bool;

    /** هل يستقبل إشعارات خادم-إلى-خادم (webhook)؟ (اليدوي: لا). */
    public function supportsWebhook(): bool;

    /** هل يدعم استرداد عبر المزوّد؟ (اليدوي: لا — الاسترداد يُنفَّذ خارجيًا ثم يُسجَّل بالنظام). */
    public function supportsRefund(): bool;

    /**
     * العملات التي يقبلها هذا المزوّد (رموز ISO) أو null = كل العملات المسموحة عامة. تُقاطَع دائمًا مع قوائم الرعاية والدفع.
     *
     * @return list<string>|null
     */
    public function currencies(): ?array;

    /** ينشئ الدفع لدى المزوّد (اليدوي: لا شيء). يُستدعى مرة واحدة بعد إنشاء الطلب. */
    public function createPayment(SponsorshipPayment $payment): PaymentInit;

    /**
     * يتحقق من توقيع واستجابة المزوّد ويُرجع حدثًا موحَّدًا. يرمي PaymentException عند توقيع غير صالح/مزوّد لا يدعم webhook.
     *
     * @throws PaymentException
     */
    public function verifyCallback(Request $request): ProviderCallback;

    /** يطلب استردادًا لدى المزوّد ويُرجع مرجع الاسترداد، أو null إن لم يدعم. @throws PaymentException */
    public function refundReference(SponsorshipPayment $payment): ?string;

    /** حالة الدفع لدى المزوّد (للمطابقة). اليدوي: يعكس حالة الطلب المحلية. */
    public function status(SponsorshipPayment $payment): string;
}
