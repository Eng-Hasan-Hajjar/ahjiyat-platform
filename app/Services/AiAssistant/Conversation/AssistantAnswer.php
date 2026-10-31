<?php

namespace App\Services\AiAssistant\Conversation;

use App\Services\AiAssistant\Dto\AiUsage;

/** E45 - ناتج الدور: نص آمن + مصادر داخلية + نتيجة مغلقة (للمقاييس) بلا أي نص خام من النموذج أو الأدوات. */
final class AssistantAnswer
{
    /** @param list<array{label: string, url: string}> $sources */
    public function __construct(
        public readonly string $text,
        public readonly array $sources,
        public readonly string $outcome,
        public readonly ?string $reason = null,
        public readonly ?string $area = null,
        public readonly bool $grounded = false,
        public readonly bool $streamed = false,
        public readonly int $toolCalls = 0,
        public readonly AiUsage $usage = new AiUsage,
        public readonly int $latencyMs = 0,
        public readonly ?string $provider = null,
        public readonly ?string $model = null,
        public readonly ?string $errorCategory = null,
    ) {}

    public function withLatency(int $ms): self
    {
        return new self($this->text, $this->sources, $this->outcome, $this->reason, $this->area, $this->grounded, $this->streamed, $this->toolCalls, $this->usage, $ms, $this->provider, $this->model, $this->errorCategory);
    }

    /** هل يُحفظ هذا الردّ ضمن التاريخ المُرسَل للنموذج لاحقًا؟ ردود الأعطال/الانشغال لا تلوّث السياق. */
    public function entersContext(): bool
    {
        return in_array($this->outcome, ['answered', 'abstained', 'refused', 'out_of_scope'], true);
    }
}
