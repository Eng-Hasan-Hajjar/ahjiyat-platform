<?php

namespace App\Services\AiAssistant\Safety;

/**
 * E45 - قرار حارس حتمي قبل أي استدعاء للنموذج. action: allow | refuse | redirect | canned.
 * reason رمز مغلق يغذّي المقاييس وتقرير الفجوات (puzzle_secret_refusal | privacy_refusal | action_refusal | out_of_scope | injection)، و code تفصيل الحالة لاختيار الردّ الجاهز.
 */
final class GuardVerdict
{
    public const ALLOW = 'allow';

    public const REFUSE = 'refuse';

    public const REDIRECT = 'redirect';

    public const CANNED = 'canned';

    public function __construct(
        public readonly string $action,
        public readonly string $code = '',
        public readonly ?string $reason = null,
    ) {}

    public static function allow(): self
    {
        return new self(self::ALLOW);
    }

    public function isAllowed(): bool
    {
        return $this->action === self::ALLOW;
    }
}
