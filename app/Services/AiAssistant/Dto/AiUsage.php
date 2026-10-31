<?php

namespace App\Services\AiAssistant\Dto;

/** E45 - استهلاك رموز طلب مزوّد واحد (أعداد فقط). */
final class AiUsage
{
    public function __construct(public readonly int $inputTokens = 0, public readonly int $outputTokens = 0) {}

    public function total(): int
    {
        return $this->inputTokens + $this->outputTokens;
    }

    public function plus(self $other): self
    {
        return new self($this->inputTokens + $other->inputTokens, $this->outputTokens + $other->outputTokens);
    }
}
