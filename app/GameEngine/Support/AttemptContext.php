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

    /** E17: سياقات تنافسية. جلساتها لا تمرّ بمسار المحاولات/المكافآت القياسي أبدًا (CompetitiveRunService فقط). */
    public const TYPE_FRIEND_CHALLENGE = 'friend_challenge';

    public const TYPE_COMPETITIVE_EVENT = 'competitive_event';

    public const TYPE_TEAM_CHALLENGE = 'team_challenge';

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

    public static function friendChallenge(int $challengeId): self
    {
        return self::for(self::TYPE_FRIEND_CHALLENGE, $challengeId);
    }

    public static function competitiveEvent(int $eventId): self
    {
        return self::for(self::TYPE_COMPETITIVE_EVENT, $eventId);
    }

    /** @return list<string> */
    public static function teamChallenge(int $challengeId): self
    {
        return self::for(self::TYPE_TEAM_CHALLENGE, $challengeId);
    }

    public static function competitiveTypes(): array
    {
        return [self::TYPE_FRIEND_CHALLENGE, self::TYPE_COMPETITIVE_EVENT, self::TYPE_TEAM_CHALLENGE];
    }

    public function isCompetitive(): bool
    {
        return in_array($this->type, self::competitiveTypes(), true);
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