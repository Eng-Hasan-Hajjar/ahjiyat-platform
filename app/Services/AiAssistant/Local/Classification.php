<?php

namespace App\Services\AiAssistant\Local;

/**
 * TI-2 - نتيجة تصنيف سؤال. status:
 *   ok | close (نيتان متقاربتان) | none (لا نية كافية) | blocked (محجوب أمنيًا) | oos (خارج النطاق)
 *   no_figure (موضوع معروف الغياب عن المعرفة المعتمدة) | ambiguous (غموض وحدات «نقاط»)
 */
final class Classification
{
    /**
     * @param  list<array{0: string, 1: float}>  $ranked  أفضل النوايا بدرجاتها
     */
    public function __construct(
        public readonly string $status,
        public readonly ?string $intent,
        public readonly float $score = 0.0,
        public readonly float $margin = 0.0,
        public readonly ?string $second = null,
        public readonly array $ranked = [],
        public readonly ?string $note = null,
    ) {}

    public function kind(): ?string
    {
        if ($this->intent === null) {
            return null;
        }

        return IntentCatalog::get($this->intent)['k'] ?? null;
    }
}
