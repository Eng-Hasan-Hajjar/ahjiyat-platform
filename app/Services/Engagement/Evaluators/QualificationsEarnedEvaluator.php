<?php

namespace App\Services\Engagement\Evaluators;

use App\Models\CampaignGateQualification;
use App\Models\QuestDefinition;
use App\Models\User;
use App\Services\Engagement\PeriodContext;
use App\Services\Engagement\QuestEvaluatorRegistry;

class QualificationsEarnedEvaluator implements QuestEvaluator
{
    public function supports(QuestDefinition $quest): bool
    {
        return $quest->condition_type === QuestEvaluatorRegistry::QUALIFICATIONS_EARNED;
    }

    public function currentValue(User $user, QuestDefinition $quest, PeriodContext $period): int
    {
        $start = $quest->starts_at !== null ? $period->start->max($quest->starts_at) : $period->start;
        $end = $quest->ends_at !== null ? $period->end->min($quest->ends_at) : $period->end;

        return CampaignGateQualification::where('user_id', $user->id)
            ->whereBetween('created_at', [$start, $end])
            ->count();
    }
}
