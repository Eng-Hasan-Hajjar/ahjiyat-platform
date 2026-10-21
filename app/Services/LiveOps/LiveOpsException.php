<?php

namespace App\Services\LiveOps;

/** E34 - خطأ أعمال مفهوم (رسالة عربية آمنة للعرض). `reason` رمز آلي ثابت للاختبارات والواجهة؛ لا يحمل بيانات خاصة. */
class LiveOpsException extends \RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
