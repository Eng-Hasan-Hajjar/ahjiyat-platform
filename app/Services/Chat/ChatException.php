<?php

namespace App\Services\Chat;

/** رفض مجال بالدردشة (E21): رمز مغلق + رسالة عربية آمنة للعرض. لا تحمل نص رسالة المستخدم. */
class ChatException extends \RuntimeException
{
    public function __construct(public readonly string $reason, ?string $message = null)
    {
        parent::__construct($message ?? ChatAccess::message($reason));
    }
}
