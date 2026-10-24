<?php

namespace App\Services\Commerce;

/**
 * E41 - ناتج استرداد كامل عند المزوّد: succeeded (مؤكَّد الآن) | pending (سيصل تأكيده بحدث refunded) | failed (لم يتم؛ errorCode رمز آمن قصير بلا نص مزوّد خام).
 * الشراء لا يصير refunded محليًا إلا عند succeeded أو بحدث refunded موثَّق.
 */
final class CommerceRefundResult
{
    public const SUCCEEDED = 'succeeded';

    public const PENDING = 'pending';

    public const FAILED = 'failed';

    private function __construct(
        public readonly string $outcome,
        public readonly ?string $refundReference,
        public readonly ?string $errorCode,
    ) {}

    public static function succeeded(string $refundReference): self
    {
        return new self(self::SUCCEEDED, $refundReference, null);
    }

    public static function pending(?string $refundReference = null): self
    {
        return new self(self::PENDING, $refundReference, null);
    }

    public static function failed(string $errorCode): self
    {
        return new self(self::FAILED, null, mb_substr(preg_replace('/[^a-z0-9_\-]/i', '_', $errorCode) ?: 'refund_failed', 0, 48));
    }

    public function isSucceeded(): bool
    {
        return $this->outcome === self::SUCCEEDED;
    }

    public function isPending(): bool
    {
        return $this->outcome === self::PENDING;
    }

    public function isFailed(): bool
    {
        return $this->outcome === self::FAILED;
    }
}
