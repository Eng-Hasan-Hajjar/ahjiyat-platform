<?php

namespace App\Services\TrustSafety;

use RuntimeException;

/** E38 - خطأ تشغيلي متوقع بمركز الثقة والسلامة (حالة غير صالحة/تعارض/مدخل غير مسموح). الرسالة عربية آمنة للعرض للمشرف؛ لا تحمل بيانات داخلية. */
class ModerationException extends RuntimeException {}
