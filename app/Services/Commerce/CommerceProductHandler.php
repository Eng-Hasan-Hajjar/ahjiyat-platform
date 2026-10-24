<?php

namespace App\Services\Commerce;

use App\Models\DigitalPurchase;

/**
 * E41 - مالك المنتج: عقد صغير يفصل نواة التجارة عن المنتج الأول (المسار المميز) كي لا تتزاوج بنيتها معه. كل الاستدعاءات داخل معاملة المتصل
 * والطلب **مقفول** (lockForUpdate) مسبقًا، فتبقى الحالة وفتح/سحب الاستحقاق ذريين معًا.
 *
 * سلطة منح الاستحقاق (التحقق ⇒ فتح المنتج) تبقى للمالك: PassPremiumProductHandler يملك «شراء مؤكَّد يفتح المسار المميز». لا يمنح نقاطًا ولا مكافآت ولا أرصدة.
 */
interface CommerceProductHandler
{
    public function productType(): string;

    /**
     * يفتح الاستحقاق للمشتري (idempotent بحارس المنتج). يرمي CommerceFulfillmentException إن منع المنطق ذلك.
     *
     * @throws CommerceFulfillmentException
     */
    public function fulfill(DigitalPurchase $locked): void;

    /** عدد عناصر الاستحقاق المستلَمة سابقًا (سياسة الاسترداد: لا سحب صامت لها، ويلزم تأكيد صريح إن وُجدت). */
    public function claimedItemCount(DigitalPurchase $locked): int;

    /** يسحب الاستحقاق الذي منحه هذا الشراء فقط (آمن إن لم يعد يخصّه). لا يسحب العناصر المستلَمة. */
    public function reverse(DigitalPurchase $locked): void;

    /** إشعار «تم التفعيل» بعد الـcommit (معزول الفشل؛ idempotent بمفتاح دلالي). */
    public function notifyVerified(DigitalPurchase $purchase): void;
}
