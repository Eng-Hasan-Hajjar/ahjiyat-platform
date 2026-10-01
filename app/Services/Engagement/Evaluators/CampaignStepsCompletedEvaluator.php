<?php

namespace App\Services\Engagement\Evaluators;

use App\Models\PuzzleAttempt;
use App\Models\QuestDefinition;
use App\Models\User;
use App\Models\UserCampaignProgress;
use App\Services\Engagement\PeriodContext;
use App\Services\Engagement\QuestEvaluatorRegistry;

/** نفس تعريف "الاكتمال" الموثوق من E12 حرفيًا - لا اختراع بديل، فقط محصور بالفترة زمنيًا هنا. */
class CampaignStepsCompletedEvaluator implements QuestEvaluator
{
    public function supports(QuestDefinition $quest): bool
    {
        return $quest->condition_type === QuestEvaluatorRegistry::CAMPAIGN_STEPS_COMPLETED;
    }

    public function currentValue(User $user, QuestDefinition $quest, PeriodContext $period): int
    {
        $start = $quest->starts_at !== null ? $period->start->max($quest->starts_at) : $period->start;
        $end = $quest->ends_at !== null ? $period->end->min($quest->ends_at) : $period->end;

        $completedPuzzleSteps = PuzzleAttempt::where('user_id', $user->id)
            ->where('is_correct', true)
            ->where('context_type', 'campaign_step')
            ->whereBetween('created_at', [$start, $end])
            ->distinct('context_id')
            ->count('context_id');

        $completedNarrativeOrReflectionSteps = UserCampaignProgress::where('user_id', $user->id)
            ->whereNotNull('completed_at')
            ->whereBetween('completed_at', [$start, $end])
            ->count();

        return $completedPuzzleSteps + $completedNarrativeOrReflectionSteps;
    }
}
