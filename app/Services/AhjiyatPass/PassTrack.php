<?php

namespace App\Services\AhjiyatPass;

/**
 * E40 - مسار المكافآت لمسار واحد (الشرائح + مكافآتها + مهامه) كقيمة غير قابلة للتعديل، مبنية من لقطة الكاش.
 * كل الحسابات هنا أعداد صحيحة حتمية؛ حساب الشريحة من النقاط يعتمد عتبات تراكمية تصاعدية فقط.
 *
 * @phpstan-type Tier array{id: int, number: int, points: int, label: ?string, milestone: bool}
 * @phpstan-type Reward array{id: int, tier: int, track: string, item_id: int, item_name: string, item_type: string, slot: ?string, quantity: int, image: ?string}
 * @phpstan-type Mission array{id: int, quest_id: int, difficulty: string, points: int, max_completions: int, week: ?int, featured: bool, sort: int}
 */
final class PassTrack
{
    /** @var array<int, array<string, mixed>>|null */
    private ?array $rewardIndex = null;

    /**
     * @param  list<array<string, mixed>>  $tiers  مرتّبة تصاعديًا برقم الشريحة
     * @param  array<int, array{free: ?array<string, mixed>, premium: ?array<string, mixed>}>  $rewards  مفهرسة برقم الشريحة
     * @param  array<int, array<string, mixed>>  $missions  مفهرسة بمعرّف تعريف المهمة (quest_definition_id)
     */
    public function __construct(
        public readonly int $passId,
        public readonly array $tiers,
        public readonly array $rewards,
        public readonly array $missions,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['pass_id' => $this->passId, 'tiers' => $this->tiers, 'rewards' => $this->rewards, 'missions' => $this->missions];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self((int) $data['pass_id'], (array) $data['tiers'], (array) $data['rewards'], (array) $data['missions']);
    }

    public function maxTier(): int
    {
        return $this->tiers === [] ? 0 : (int) ($this->tiers[array_key_last($this->tiers)]['number'] ?? 0);
    }

    /** أعلى شريحة بُلغت بهذه النقاط (0 إن لم تُبلغ أي شريحة). تصاعدية صارمة ⇒ نتيجة حتمية. */
    public function tierAt(int $points): int
    {
        $reached = 0;

        foreach ($this->tiers as $tier) {
            if ($points >= (int) $tier['points']) {
                $reached = (int) $tier['number'];
            } else {
                break;
            }
        }

        return $reached;
    }

    public function pointsFor(int $tierNumber): ?int
    {
        foreach ($this->tiers as $tier) {
            if ((int) $tier['number'] === $tierNumber) {
                return (int) $tier['points'];
            }
        }

        return null;
    }

    /** الشريحة التالية بعد النقاط الحالية أو null عند القمة. @return array<string, mixed>|null */
    public function nextTier(int $points): ?array
    {
        foreach ($this->tiers as $tier) {
            if ($points < (int) $tier['points']) {
                return $tier;
            }
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    public function mission(int $questDefinitionId): ?array
    {
        return $this->missions[$questDefinitionId] ?? null;
    }

    /** @return array<string, mixed>|null مكافأة شريحة بمعرّفها. */
    public function rewardById(int $id): ?array
    {
        if ($this->rewardIndex === null) {
            $this->rewardIndex = [];

            foreach ($this->rewards as $slots) {
                foreach (['free', 'premium'] as $track) {
                    if (($slots[$track] ?? null) !== null) {
                        $this->rewardIndex[(int) $slots[$track]['id']] = $slots[$track];
                    }
                }
            }
        }

        return $this->rewardIndex[$id] ?? null;
    }

    /** كل المكافآت المسطّحة بترتيب الشريحة ثم المسار (مجاني قبل المميز). @return list<array<string, mixed>> */
    public function allRewards(): array
    {
        $out = [];

        foreach ($this->tiers as $tier) {
            foreach (['free', 'premium'] as $track) {
                $reward = $this->rewards[(int) $tier['number']][$track] ?? null;

                if ($reward !== null) {
                    $out[] = $reward;
                }
            }
        }

        return $out;
    }

    /** مكافآت قابلة للمطالبة نظريًا (شريحة بُلغت، والمميز مملوك للمميز فقط) غير المستلَمة. @param array<int, true> $claimedIds @return list<array<string, mixed>> */
    public function claimable(int $highestTier, bool $hasPremium, array $claimedIds): array
    {
        $out = [];

        foreach ($this->allRewards() as $reward) {
            if ((int) $reward['tier'] > $highestTier) {
                continue;
            }

            if ($reward['track'] === 'premium' && ! $hasPremium) {
                continue;
            }

            if (isset($claimedIds[(int) $reward['id']])) {
                continue;
            }

            $out[] = $reward;
        }

        return $out;
    }
}
