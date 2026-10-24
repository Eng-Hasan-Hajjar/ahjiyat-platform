<?php

namespace App\Services\AhjiyatPass;

/** E40 - خطأ أعمال مفهوم لمسار الأحجيات (رسالة عربية آمنة للعرض). `reason` رمز آلي ثابت للاختبارات والواجهة؛ لا يحمل بيانات خاصة. */
class PassException extends \RuntimeException
{
    /** @param list<string> $details مشكلات إضافية (مثل قائمة عدم الجاهزية للنشر). */
    public function __construct(public readonly string $reason, string $message, public readonly array $details = [])
    {
        parent::__construct($message);
    }
}
