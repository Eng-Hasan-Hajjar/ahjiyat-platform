<?php

namespace App\Services\Progression;

use App\Exceptions\StoreItemInvariantViolation;
use App\Models\Achievement;
use App\Models\StoreItem;

class AchievementInvariantGuard
{
    private const SENSITIVE_FIELDS = [
        'internal_key', 'condition_type', 'target_value', 'scope_type', 'scope_id',
        'xp_reward', 'reward_currency_id', 'reward_currency_amount',
        'reward_store_item_id', 'reward_item_quantity',
    ];

    public function __construct(protected AchievementEvaluatorRegistry $registry) {}

    public function enforce(Achievement $achievement): void
    {
        $isNew = ! $achievement->exists;

        if (! $isNew) {
            $this->assertUsedFieldsNotMutated($achievement);
        }

        $this->assertConditionTypeKnown($achievement);
        $this->normalizeScope($achievement);
        $this->assertTargetValue($achievement);
        $this->assertRewardItemNotManual($achievement);
        $this->assertCommunityRecognitionHasNoRewards($achievement);
    }

    public function enforceDeletable(Achievement $achievement): void
    {
        if ($achievement->isUsed()) {
            throw new StoreItemInvariantViolation('لا يمكن حذف إنجاز بدأ مستخدمون فعليًا بتحقيقه - عطِّله بدلًا من ذلك.');
        }
    }

    protected function assertUsedFieldsNotMutated(Achievement $achievement): void
    {
        if (! $achievement->isUsed()) {
            return;
        }

        if ($achievement->isDirty(self::SENSITIVE_FIELDS)) {
            throw new StoreItemInvariantViolation('لا يمكن تغيير شرط أو نطاق أو مكافأة إنجاز بدأ مستخدمون فعليًا بتحقيقه.');
        }
    }

    protected function assertConditionTypeKnown(Achievement $achievement): void
    {
        if (! $achievement->isDirty('condition_type') && $achievement->exists) {
            return;
        }

        if (! $this->registry->isValidConditionType($achievement->condition_type)) {
            throw new StoreItemInvariantViolation('نوع شرط الإنجاز غير معروف - يجب اختياره من القائمة المتاحة فقط.');
        }
    }

    protected function normalizeScope(Achievement $achievement): void
    {
        if (! $this->registry->requiresScope($achievement->condition_type)) {
            if ($achievement->scope_type !== null || $achievement->scope_id !== null) {
                $achievement->setAttribute('scope_type', null);
                $achievement->setAttribute('scope_id', null);
            }
        }
    }

    protected function assertTargetValue(Achievement $achievement): void
    {
        if ($achievement->target_value !== null && $achievement->target_value <= 0) {
            throw new StoreItemInvariantViolation('قيمة الهدف يجب أن تكون أكبر من صفر.');
        }
    }

    protected function assertRewardItemNotManual(Achievement $achievement): void
    {
        if ($achievement->reward_store_item_id === null) {
            return;
        }

        if (! $achievement->exists && ! $achievement->isDirty('reward_store_item_id')) {
            return;
        }

        $item = StoreItem::find($achievement->reward_store_item_id);

        if ($item !== null && $item->fulfillment_type === StoreItem::FULFILLMENT_MANUAL) {
            throw new StoreItemInvariantViolation('لا يمكن اختيار عنصر يدوي التسليم كمكافأة تلقائية لإنجاز.');
        }
    }

    /**
     * E37: الاعتراف المجتمعي اعتراف لا اقتصاد: لا XP ولا عملة ولا عنصر متجر على أي إنجاز من أنواع الاعتراف
     * (السمعة لا تُشترى ولا تُحوَّل إلى جواهر/XP ولا تفتح مكافآت؛ حتى لا يصير الاعتراف حافزًا لتضخيم المؤشرات).
     */
    protected function assertCommunityRecognitionHasNoRewards(Achievement $achievement): void
    {
        if (! in_array($achievement->condition_type, AchievementEvaluatorRegistry::COMMUNITY_TYPES, true)) {
            return;
        }

        if ((int) $achievement->xp_reward > 0
            || $achievement->reward_currency_id !== null || (int) $achievement->reward_currency_amount > 0
            || $achievement->reward_store_item_id !== null) {
            throw new StoreItemInvariantViolation('إنجازات الاعتراف المجتمعي لا تحمل مكافآت (لا XP ولا عملة ولا عناصر): السمعة اعتراف لا اقتصاد.');
        }
    }
}
