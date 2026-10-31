<?php

namespace App\Services\AiAssistant\Dto;

/** E45 - ردّ مزوّد واحد: نص و/أو طلبات أدوات + استهلاك + عناصر المزوّد الخام (لإعادتها كما هي في الدور التالي). */
final class AiResponse
{
    /**
     * @param  list<AiToolCall>  $toolCalls
     * @param  list<array<string, mixed>>  $providerItems
     */
    public function __construct(
        public readonly string $text = '',
        public readonly array $toolCalls = [],
        public readonly AiUsage $usage = new AiUsage,
        public readonly array $providerItems = [],
        public readonly string $finishReason = 'stop',
        public readonly string $model = '',
    ) {}

    public function wantsTools(): bool
    {
        return $this->toolCalls !== [];
    }
}
