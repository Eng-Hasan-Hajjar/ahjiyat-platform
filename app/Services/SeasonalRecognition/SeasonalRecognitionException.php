<?php

namespace App\Services\SeasonalRecognition;

use RuntimeException;

/**
 * E44 - خطأ مجال متوقَّع في دوري الكتّاب أو تقدير الداعمين. `reason` رمز آلي ثابت (للاختبار والواجهة)؛ الرسالة عربية آمنة للعرض
 * (لا هوية ناخبين ولا بيانات دفع ولا تفاصيل داخلية).
 */
class SeasonalRecognitionException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
