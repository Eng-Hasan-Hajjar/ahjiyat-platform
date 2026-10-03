<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * E14: تُرمى عند كسر قاعدة نطاق Advertising (موضع غير معروف بالسجلّ، رابط
 * غير آمن، تعديل حقل حساس بحملة مُعتمَدة بلا إعادة مراجعة...). نفس نمط
 * StoreItemInvariantViolation حرفيًا - اتساق مع بقية أخطاء الأعمال بالمشروع.
 */
class AdInvariantViolation extends RuntimeException
{
}
