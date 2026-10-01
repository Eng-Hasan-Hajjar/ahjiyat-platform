<?php

namespace App\Services\Engagement;

use App\Exceptions\StoreItemInvariantViolation;
use App\Models\QuestDefinition;
use App\Models\StoreItem;

/** E13: نفس فلسفة AchievementInvariantGuard/LevelDefinitionInvariantGuard حرفيًا، مع إضافة period_type. */
class QuestDefinitionInvariantGuard
{
    private const SENSITIVE_FIELDS = [
        'internal_key', 'period_type', 'condition_type', 'target_value', 'scope_type', 'scope_id',
        'xp_reward', 'reward_currency_id', 'reward_currency_amount',
        'reward_store_item_id', 'reward_item_quantity',
    ];

    public function __construct(protected QuestEvaluatorRegistry $registry) {}

    public function enforce(QuestDefinition $quest): void
    {
        $isNew = ! $quest->exists;

        if (! $isNew) {
            $this->assertUsedFieldsNotMutated($quest);
        }

        $this->assertPeriodTypeKnown($quest);
        $this->assertConditionTypeKnown($quest);
        $this->normalizeScope($quest);
        $this->assertTargetValue($quest);
        $this->assertRewardItemNotManual($quest);
    }

    public function enforceDeletable(QuestDefinition $quest): void
    {
        if ($quest->isUsed()) {
            throw new StoreItemInvariantViolation('لا يمكن حذف مهمة بدأ لاعبون فعليًا بإحراز تقدُّم بها - عطِّلها بدلًا من ذلك.');
        }
    }

    protected function assertUsedFieldsNotMutated(QuestDefinition $quest): void
    {
        if (! $quest->isUsed()) {
            return;
        }

        if ($quest->isDirty(self::SENSITIVE_FIELDS)) {
            throw new StoreItemInvariantViolation('لا يمكن تغيير فترة أو شرط أو نطاق أو مكافأة مهمة بدأ لاعبون فعليًا بإحراز تقدُّم بها.');
        }
    }

    protected function assertPeriodTypeKnown(QuestDefinition $quest): void
    {
        if (! in_array($quest->period_type, QuestDefinition::PERIOD_TYPES, true)) {
            throw new StoreItemInvariantViolation('نوع فترة المهمة يجب أن يكون يومية أو أسبوعية فقط.');
        }
    }

    protected function assertConditionTypeKnown(QuestDefinition $quest): void
    {
        if (! $quest->isDirty('condition_type') && $quest->exists) {
            return;
        }

        if (! $this->registry->isValidConditionType($quest->condition_type)) {
            throw new StoreItemInvariantViolation('نوع شرط المهمة غير معروف - يجب اختياره من القائمة المتاحة فقط.');
        }
    }

    protected function normalizeScope(QuestDefinition $quest): void
    {
        if (! $this->registry->requiresScope($quest->condition_type)) {
            if ($quest->scope_type !== null || $quest->scope_id !== null) {
                $quest->setAttribute('scope_type', null);
                $quest->setAttribute('scope_id', null);
            }
        }
    }

    protected function assertTargetValue(QuestDefinition $quest): void
    {
        if ($quest->target_value <= 0) {
            throw new StoreItemInvariantViolation('قيمة هدف المهمة يجب أن تكون أكبر من صفر.');
        }
    }

    protected function assertRewardItemNotManual(QuestDefinition $quest): void
    {
        if ($quest->reward_store_item_id === null) {
            return;
        }

        if (! $quest->exists && ! $quest->isDirty('reward_store_item_id')) {
            return;
        }

        $item = StoreItem::find($quest->reward_store_item_id);

        if ($item !== null && $item->fulfillment_type === StoreItem::FULFILLMENT_MANUAL) {
            throw new StoreItemInvariantViolation('لا يمكن اختيار عنصر يدوي التسليم كمكافأة تلقائية لمهمة.');
        }
    }
}
