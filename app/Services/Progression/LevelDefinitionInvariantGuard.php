<?php

namespace App\Services\Progression;

use App\Exceptions\StoreItemInvariantViolation;
use App\Models\LevelDefinition;

class LevelDefinitionInvariantGuard
{
    public function enforce(LevelDefinition $level): void
    {
        $isNew = ! $level->exists;

        if (! $isNew) {
            $this->assertUsedThresholdNotMutated($level);
        }

        $this->assertLevelNumberPositive($level);
        $this->assertLevel1IsZero($level);
        $this->assertStrictOrdering($level);
    }

    public function enforceDeletable(LevelDefinition $level): void
    {
        if ($level->isUsed()) {
            throw new StoreItemInvariantViolation('لا يمكن حذف مستوى وصل إليه مستخدمون فعليًا - عطِّله بدلًا من ذلك.');
        }
    }

    protected function assertUsedThresholdNotMutated(LevelDefinition $level): void
    {
        if (! $level->isUsed()) {
            return;
        }

        if ($level->isDirty(['level_number', 'xp_required_total'])) {
            throw new StoreItemInvariantViolation('لا يمكن تغيير رقم أو عتبة XP لمستوى وصل إليه مستخدمون فعليًا.');
        }
    }

    protected function assertLevelNumberPositive(LevelDefinition $level): void
    {
        if ($level->level_number <= 0) {
            throw new StoreItemInvariantViolation('رقم المستوى يجب أن يكون أكبر من صفر.');
        }
    }

    protected function assertLevel1IsZero(LevelDefinition $level): void
    {
        if ($level->level_number === 1 && (int) $level->xp_required_total !== 0) {
            throw new StoreItemInvariantViolation('المستوى الأول (Level 1) يجب أن تكون عتبته صفرًا دائمًا.');
        }
    }

    protected function assertStrictOrdering(LevelDefinition $level): void
    {
        $previous = LevelDefinition::where('level_number', $level->level_number - 1)->first();

        if ($previous !== null && $level->xp_required_total <= $previous->xp_required_total) {
            throw new StoreItemInvariantViolation('عتبة XP يجب أن تكون أكبر من عتبة المستوى الذي يسبقه مباشرة.');
        }

        $next = LevelDefinition::where('level_number', $level->level_number + 1)->first();

        if ($next !== null && $level->xp_required_total >= $next->xp_required_total) {
            throw new StoreItemInvariantViolation('عتبة XP يجب أن تكون أصغر من عتبة المستوى الذي يليه مباشرة.');
        }
    }
}