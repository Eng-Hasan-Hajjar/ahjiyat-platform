<?php

namespace App\GameEngine\Support;

/**
 * Value Object صغير غير قابل للتغيير يمثّل "أين حدثت هذه المحاولة" (سياق
 * الاستدعاء). لا يُبنى أبداً من بيانات Request خام - فقط من كود سيرفري
 * موثوق (Controller/Service) يعرف بالفعل السياق الحقيقي.
 *
 * Phase C2: أضيف Constant + Named Constructor لسياق خطوة الحملة تحديداً -
 * حتى لا تتكرر السلسلة الحرفية 'campaign_step' بأماكن متعددة بالكود.
 */
final class AttemptContext
{
    public const TYPE_CAMPAIGN_STEP = 'campaign_step';

    private function __construct(
        public readonly ?string $type,
        public readonly ?int $id,
    ) {}

    public static function none(): self
    {
        return new self(null, null);
    }

    public static function for(string $type, int $id): self
    {
        return new self($type, $id);
    }

    public static function campaignStep(int $stepId): self
    {
        return self::for(self::TYPE_CAMPAIGN_STEP, $stepId);
    }

    public function isPresent(): bool
    {
        return $this->type !== null;
    }

    /** @return array{context_type: ?string, context_id: ?int} */
    public function toAttributes(): array
    {
        return ['context_type' => $this->type, 'context_id' => $this->id];
    }
}