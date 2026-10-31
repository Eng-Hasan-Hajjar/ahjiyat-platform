<?php

namespace App\Services\AiAssistant\Providers;

use RuntimeException;

/**
 * E45 - فشل مزوّد معزول بفئة مغلقة (لا نص من المزوّد ولا ترويسات ولا مفتاح فيها). يُلتقط في المنسّق ويتحول لردّ احتياطي من الدليل المحلي: لا صفحة تفشل ولا تتبّع مكدّس للمستخدم.
 */
final class AiProviderException extends RuntimeException
{
    public const NOT_CONFIGURED = 'not_configured';

    public const DISABLED = 'disabled';

    public const TIMEOUT = 'timeout';

    public const NETWORK = 'network';

    public const RATE_LIMITED = 'rate_limited';

    public const AUTH = 'auth';

    public const SERVER = 'server';

    public const INVALID_RESPONSE = 'invalid_response';

    public const CATEGORIES = [self::NOT_CONFIGURED, self::DISABLED, self::TIMEOUT, self::NETWORK, self::RATE_LIMITED, self::AUTH, self::SERVER, self::INVALID_RESPONSE];

    public function __construct(public readonly string $category, string $message = '')
    {
        parent::__construct($message !== '' ? $message : $category);
    }
}
