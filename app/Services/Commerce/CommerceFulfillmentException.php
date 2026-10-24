<?php

namespace App\Services\Commerce;

/**
 * E41 - منع أعمال لفتح المنتج (مسار مؤرشف، مشتري مجمَّد، يملك المنتج بالفعل…). يرفعه معالج المنتج داخل معاملة التحقق.
 * على مسار المراجع اليدوي يتحول إلى رسالة للمراجع؛ على مسار المزوّد (المال وصل فعلًا) يتحول إلى علم مراجعة مالية fulfillment_blocked بدل فتح صامت أو ضياع مال.
 */
class CommerceFulfillmentException extends CommerceException {}
