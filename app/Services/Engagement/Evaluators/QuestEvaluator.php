<?php

namespace App\Services\Engagement\Evaluators;

use App\Models\QuestDefinition;
use App\Models\User;
use App\Services\Engagement\PeriodContext;

/**
 * E13 (بند 14/16): عقد منفصل تمامًا عن AchievementEvaluator - فرق جوهري
 * هو PeriodContext: المُقيِّم يقرأ "كم أنجز داخل هذه الفترة تحديدًا؟" لا
 * إجمالًا. لا اعتماد على AchievementService إطلاقًا.
 */
interface QuestEvaluator
{
    public function supports(QuestDefinition $quest): bool;

    public function currentValue(User $user, QuestDefinition $quest, PeriodContext $period): int;
}
