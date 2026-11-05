<?php

namespace App\Services\Cinematic;

use RuntimeException;

/**
 * CIN-1 - استثناء مجال السلاسل السينمائية. الرسالة عربية وآمنة للعرض للمستخدم/المسؤول (لا مسارات ملفات ولا تفاصيل داخلية).
 * code = رمز ثابت قابل للاختبار والفلترة (لا يُترجَم).
 */
class CinematicException extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason = 'invalid', public readonly int $httpStatus = 422)
    {
        parent::__construct($message);
    }

    public static function make(string $reason, string $message, int $status = 422): self
    {
        return new self($message, $reason, $status);
    }
}
