<?php

namespace App\GameEngine\Support;

use App\Models\Currency;

final class RewardDirective
{
    private function __construct(
        public readonly bool $useDefault,
        public readonly int $amount,
        public readonly ?Currency $currency = null,
    ) {}

    public static function useDefault(): self
    {
        return new self(true, 0, null);
    }

    public static function fixed(int $amount, ?Currency $currency = null): self
    {
        return new self(false, max(0, $amount), $currency);
    }

    public static function none(): self
    {
        return self::fixed(0);
    }
}