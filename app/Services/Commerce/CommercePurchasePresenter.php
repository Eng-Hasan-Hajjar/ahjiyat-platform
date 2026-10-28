<?php

namespace App\Services\Commerce;

use App\Models\DigitalPurchase;
use App\Services\Sponsorship\SponsorshipMoney;

/**
 * E41 - عرض آمن لحالة الشراء للاعب (الصفحة، الاستطلاع JSON، السجل، الإيصال). **لا يقرأ إلا الحالة الموثوقة في DigitalPurchase** (لا معاملات متصفح):
 * «تم التفعيل» لا يُعرض إلا لـverified. لا أسرار ولا معرّفات مزوّد/webhook ولا ملاحظات مراجعة ولا مسارات إثبات في أي مخرج هنا.
 *
 * مراحل اللاعب: awaiting | processing | under_review (يدوي مقدَّم) | review (مراجعة مالية) | failed | verified | refunded | rejected | cancelled | expired.
 *   processing = جلسة مستضافة قائمة بعد العودة (تُعرض فقط بصفحة العودة)؛ review = علم مراجعة مالية قائم؛ failed = المزوّد أبلغ فشل/إلغاء الجلسة.
 */
class CommercePurchasePresenter
{
    public function __construct(protected CommercePaymentProviderRegistry $providers) {}

    public function phase(DigitalPurchase $purchase): string
    {
        return match ($purchase->status) {
            DigitalPurchase::STATUS_VERIFIED => 'verified',
            DigitalPurchase::STATUS_REFUNDED => 'refunded',
            DigitalPurchase::STATUS_REJECTED => 'rejected',
            DigitalPurchase::STATUS_CANCELLED => 'cancelled',
            DigitalPurchase::STATUS_EXPIRED => 'expired',
            DigitalPurchase::STATUS_SUBMITTED => 'under_review',
            default => $purchase->needsReview()
                ? 'review'
                : (($purchase->providerState()?->isTerminalFailure() ?? false) ? 'failed' : 'awaiting'),
        };
    }

    public function isFinal(string $phase): bool
    {
        return in_array($phase, ['verified', 'refunded', 'rejected', 'cancelled', 'expired', 'review', 'failed'], true);
    }

    /** @return array{label: string, message: string} */
    public function copy(DigitalPurchase $purchase, bool $returned = false): array
    {
        $phase = $this->phase($purchase);

        if ($phase === 'awaiting' && $returned && $purchase->isHosted() && $purchase->provider_session_id !== null) {
            $phase = 'processing';
        }

        if ($purchase->product_type === DigitalPurchase::PRODUCT_CREATOR_SERIES) {
            return $this->seriesCopy($phase);
        }

        return match ($phase) {
            'verified' => ['label' => 'تم تفعيل المسار المميز', 'message' => 'تأكّد الدفع وفُتح المسار المميز. مكافآتك المميزة جاهزة للاستلام من صفحة المسار.'],
            'refunded' => ['label' => 'مسترد', 'message' => 'سُجّل استرداد هذا الطلب.'],
            'rejected' => ['label' => 'مرفوض', 'message' => 'لم يُؤكَّد هذا الطلب.'],
            'cancelled' => ['label' => 'ملغى', 'message' => 'أُلغي هذا الطلب.'],
            'expired' => ['label' => 'منتهي الصلاحية', 'message' => 'انتهت صلاحية هذا الطلب. يمكنك بدء طلب جديد.'],
            'under_review' => ['label' => 'قيد التحقق', 'message' => 'استلمنا مرجع تحويلك وهو قيد المراجعة. لن يُفتح المسار المميز إلا بعد تأكيد الدفع.'],
            'review' => ['label' => 'قيد المراجعة المالية', 'message' => 'وصلتنا عملية دفع تحتاج مراجعة يدوية قبل التفعيل. يتولى الفريق المالي الأمر ولا يلزمك فعل شيء الآن.'],
            'failed' => ['label' => 'لم تكتمل العملية', 'message' => 'لم تكتمل عملية الدفع ولم يُفعَّل المسار المميز. يمكنك المحاولة مجددًا.'],
            'processing' => ['label' => 'جارٍ التحقق من الدفع', 'message' => 'يتم التحقق من العملية... قد يستغرق ذلك لحظات. لا يُفعَّل المسار المميز إلا بعد تأكيد الدفع من مزوّد الدفع نفسه.'],
            default => ['label' => 'بانتظار الدفع', 'message' => 'لم يكتمل الدفع بعد. يُفعَّل المسار المميز فقط بعد تأكيد الدفع.'],
        };
    }

    /** E43: نصوص السلسلة المميزة (وصول دائم بشراء مرة واحدة). لا يُفتح الوصول إلا بعد تأكيد الدفع من المزوّد نفسه. @return array{label: string, message: string} */
    protected function seriesCopy(string $phase): array
    {
        return match ($phase) {
            'verified' => ['label' => 'تم فتح السلسلة المميزة', 'message' => 'تأكّد الدفع وصار لك وصول دائم إلى أحجيات هذه السلسلة.'],
            'refunded' => ['label' => 'مسترد', 'message' => 'سُجّل استرداد هذا الطلب وأُغلق الوصول إلى السلسلة.'],
            'rejected' => ['label' => 'مرفوض', 'message' => 'لم يُؤكَّد هذا الطلب.'],
            'cancelled' => ['label' => 'ملغى', 'message' => 'أُلغي هذا الطلب.'],
            'expired' => ['label' => 'منتهي الصلاحية', 'message' => 'انتهت صلاحية هذا الطلب. يمكنك بدء طلب جديد من صفحة السلسلة.'],
            'review' => ['label' => 'قيد المراجعة المالية', 'message' => 'وصلتنا عملية دفع تحتاج مراجعة يدوية قبل فتح السلسلة. يتولى الفريق المالي الأمر ولا يلزمك فعل شيء الآن.'],
            'failed' => ['label' => 'لم تكتمل العملية', 'message' => 'لم تكتمل عملية الدفع ولم تُفتح السلسلة. يمكنك المحاولة مجددًا.'],
            'processing' => ['label' => 'جارٍ التحقق من الدفع', 'message' => 'يتم التحقق من العملية... قد يستغرق ذلك لحظات. لا تُفتح السلسلة إلا بعد تأكيد الدفع من مزوّد الدفع نفسه.'],
            default => ['label' => 'بانتظار الدفع', 'message' => 'لم يكتمل الدفع بعد. تُفتح السلسلة فقط بعد تأكيد الدفع.'],
        };
    }

    public function amountLabel(DigitalPurchase $purchase): string
    {
        return SponsorshipMoney::format((int) $purchase->amount_minor, (string) $purchase->currency);
    }

    public function providerLabel(DigitalPurchase $purchase): string
    {
        try {
            return $this->providers->has($purchase->provider) ? $this->providers->get($purchase->provider)->label() : 'طريقة دفع غير متاحة حاليًا';
        } catch (CommerceException) {
            return 'طريقة دفع غير متاحة حاليًا';
        }
    }

    /** مخرج الاستطلاع JSON (قراءة فقط). @return array<string, mixed> */
    public function status(DigitalPurchase $purchase, bool $returned = true): array
    {
        $copy = $this->copy($purchase, $returned);
        $phase = $this->phase($purchase);

        return [
            'status' => $purchase->status,
            'phase' => $phase,
            'label' => $copy['label'],
            'message' => $copy['message'],
            'final' => $this->isFinal($phase),
            'verified' => $purchase->status === DigitalPurchase::STATUS_VERIFIED,
        ];
    }

    /**
     * بيانات الإيصال/سطر السجل الآمنة. «Receipt / Purchase reference» — ليس فاتورة ضريبية ولا يدّعي امتثالًا ضريبيًا.
     *
     * @return array<string, mixed>
     */
    public function receipt(DigitalPurchase $purchase): array
    {
        return [
            'reference' => $purchase->reference_code,
            'product' => $purchase->product_name,
            'amount' => $this->amountLabel($purchase),
            'currency' => $purchase->currency,
            'provider' => $this->providerLabel($purchase),
            'status' => $purchase->status,
            'status_label' => $purchase->statusLabel(),
            'created_at' => $purchase->created_at,
            'paid_at' => $purchase->verified_at,
            'refunded_at' => $purchase->refunded_at,
            'refund' => $purchase->status === DigitalPurchase::STATUS_REFUNDED ? 'مسترد' : null,
        ];
    }
}
