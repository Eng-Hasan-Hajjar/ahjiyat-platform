<?php

namespace App\Services\AiAssistant\Knowledge;

use RuntimeException;

/** E45 - مقال لا يصلح للنشر (محتوى حساس/داخلي أو بيانات ناقصة/غير صالحة). violations رموز مغلقة تُعرَض للمحرر بلا نص الأسرار نفسها. */
final class KnowledgeSafetyException extends RuntimeException
{
    /** @param list<string> $violations */
    public function __construct(string $message, public readonly array $violations = [])
    {
        parent::__construct($message);
    }
}
