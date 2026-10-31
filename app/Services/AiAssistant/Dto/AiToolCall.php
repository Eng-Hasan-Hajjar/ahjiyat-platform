<?php

namespace App\Services\AiAssistant\Dto;

/** E45 - طلب أداة من النموذج. الاسم والوسائط **غير موثوقة** حتى يتحقق منها ToolRegistry خادميًا. */
final class AiToolCall
{
    /** @param array<string, mixed> $arguments */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly array $arguments = [],
    ) {}
}
