<?php

namespace App\Services\CreatorPremium;

use App\Services\Creators\CreatorException;

/**
 * E43 - خطأ مجال متوقَّع في سوق المبدعين المميز. `reason` رمز آلي ثابت؛ الرسالة عربية آمنة للعرض (لا بيانات مشترين ولا مزوّد).
 * يرث CreatorException فتلتقطه متحكمات المبدعين/اللعب نفسها وتعرضه رسالةً بلا 500.
 */
class CreatorPremiumException extends CreatorException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
