<?php

namespace App\Services\Progression\Achievements;

use App\Models\Achievement;
use App\Models\PuzzleAttempt;
use App\Models\User;
use App\Models\UserCampaignProgress;
use App\Services\Progression\AchievementEvaluatorRegistry;

class CampaignStepsCompletedTotalEvaluator implements AchievementEvaluator
{
    public function supports(Achievement $achievement): bool
    {
        return $achievement->condition_type === AchievementEvaluatorRegistry::CAMPAIGN_STEPS_COMPLETED_TOTAL;
    }

    public function currentValue(User $user, Achievement $achievement): int
    {
        $completedPuzzleSteps = PuzzleAttempt::where('user_id', $user->id)
            ->where('is_correct', true)
            ->where('context_type', 'campaign_step')
            ->distinct('context_id')
            ->count('context_id');

        $completedNarrativeOrReflectionSteps = UserCampaignProgress::where('user_id', $user->id)
            ->whereNotNull('completed_at')
            ->count();

        return $completedPuzzleSteps + $completedNarrativeOrReflectionSteps;
    }
}