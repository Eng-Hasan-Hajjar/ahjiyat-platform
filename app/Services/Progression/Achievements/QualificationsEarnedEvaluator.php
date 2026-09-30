<?php

namespace App\Services\Progression\Achievements;

use App\Models\Achievement;
use App\Models\User;
use App\Services\Progression\AchievementEvaluatorRegistry;

class QualificationsEarnedEvaluator implements AchievementEvaluator
{
    public function supports(Achievement $achievement): bool
    {
        return $achievement->condition_type === AchievementEvaluatorRegistry::QUALIFICATIONS_EARNED_TOTAL;
    }

    public function currentValue(User $user, Achievement $achievement): int
    {
        return $user->campaignQualifications()->count();
    }
}