<?php

namespace App\Services\Engagement\Evaluators;

use App\Models\PuzzleAttempt;
use App\Models\QuestDefinition;
use App\Models\User;
use App\Services\Engagement\PeriodContext;
use App\Services\Engagement\QuestEvaluatorRegistry;

class PuzzlesSolvedEvaluator implements QuestEvaluator
{
    public function supports(QuestDefinition $quest): bool
    {
        return $quest->condition_type === QuestEvaluatorRegistry::PUZZLES_SOLVED;
    }

    public function currentValue(User $user, QuestDefinition $quest, PeriodContext $period): int
    {
        [$start, $end] = $this->effectiveRange($quest, $period);

        return PuzzleAttempt::where('user_id', $user->id)
            ->where('is_correct', true)
            ->whereBetween('created_at', [$start, $end])
            ->distinct('puzzle_id')
            ->count('puzzle_id');
    }

    /** بند 171/172: نطاق فعّال = تقاطع فترة المستخدم مع نافذة توفُّر التعريف (starts_at/ends_at). */
    protected function effectiveRange(QuestDefinition $quest, PeriodContext $period): array
    {
        $start = $quest->starts_at !== null ? $period->start->max($quest->starts_at) : $period->start;
        $end = $quest->ends_at !== null ? $period->end->min($quest->ends_at) : $period->end;

        return [$start, $end];
    }
}
