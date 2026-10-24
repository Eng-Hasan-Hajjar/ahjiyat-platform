<?php

namespace App\Services\Commerce;

/**
 * E41 - خطأ تجارة/مزوّد مفهوم. `reason` رمز آلي ثابت؛ الرسالة عربية آمنة للعرض (لا أسرار ولا نص خام من المزوّد). `retryable` يحدد إن كانت إعادة المحاولة مفيدة (انقطاع مؤقت).
 */
class CommerceException extends \RuntimeException
{
    /** @param list<string> $details */
    public function __construct(public readonly string $reason, string $message, public readonly bool $retryable = false, public readonly array $details = [])
    {
        parent::__construct($message);
    }
}
