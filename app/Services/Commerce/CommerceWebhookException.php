<?php

namespace App\Services\Commerce;

/** E41 - رفض webhook قبل أي أثر مالي. httpStatus يحدده النوع: 401 توقيع غائب/خاطئ، 400 طابع قديم/حمولة مشوّهة، 413 حمولة كبيرة، 404 مزوّد غير معروف/غير مستقبِل. */
class CommerceWebhookException extends CommerceException
{
    public function __construct(string $reason, string $message, public readonly int $httpStatus = 400)
    {
        parent::__construct($reason, $message, false);
    }
}
