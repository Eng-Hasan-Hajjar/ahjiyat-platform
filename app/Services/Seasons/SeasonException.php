<?php

namespace App\Services\Seasons;

use RuntimeException;

/**
 * E42 - خطأ أعمال متوقَّع في منشئ الموسم (رسالة عربية آمنة للعرض). $reason رمز آلي ثابت للاختبارات والواجهة، و$details قائمة مشاكل اختيارية.
 */
class SeasonException extends RuntimeException
{
    /** @param  list<string>  $details */
    public function __construct(public readonly string $reason, string $message, public readonly array $details = [])
    {
        parent::__construct($message);
    }
}
