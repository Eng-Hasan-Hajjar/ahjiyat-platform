<?php

namespace App\Services\CreatorFinance;

use RuntimeException;

/** E39 - خطأ تشغيلي متوقع في مالية المبدعين (حالة غير صالحة/رصيد غير كافٍ/تعليق/مدخل غير مسموح). الرسالة عربية آمنة للعرض ولا تحمل بيانات داخلية. */
class CreatorFinanceException extends RuntimeException {}
