<?php

namespace App\GameEngine\Support;

final class XpDirective
{
    private function __construct(
        public readonly bool $useDefault,
        public readonly int $amount,
    ) {}

    public static function useDefault(): self
    {
        return new self(true, 0);
    }

    public static function fixed(int $amount): self
    {
        return new self(false, max(0, $amount));
    }

    public static function none(): self
    {
        return self::fixed(0);
    }
}