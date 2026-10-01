<?php

namespace App\Services\Engagement\Evaluators;

use App\Models\PuzzleAttempt;
use App\Models\QuestDefinition;
use App\Models\User;
use App\Services\Engagement\PeriodContext;
use App\Services\Engagement\QuestEvaluatorRegistry;

class PuzzlesSolvedInCategoryEvaluator implements QuestEvaluator
{
    public function supports(QuestDefinition $quest): bool
    {
        return $quest->condition_type === QuestEvaluatorRegistry::PUZZLES_SOLVED_IN_CATEGORY;
    }

    public function currentValue(User $user, QuestDefinition $quest, PeriodContext $period): int
    {
        if ($quest->scope_type !== 'puzzle_category' || $quest->scope_id === null) {
            return 0;
        }

        $start = $quest->starts_at !== null ? $period->start->max($quest->starts_at) : $period->start;
        $end = $quest->ends_at !== null ? $period->end->min($quest->ends_at) : $period->end;

        return PuzzleAttempt::where('user_id', $user->id)
            ->where('is_correct', true)
            ->whereBetween('created_at', [$start, $end])
            ->whereHas('puzzle', fn ($q) => $q->where('puzzle_category_id', $quest->scope_id))
            ->distinct('puzzle_id')
            ->count('puzzle_id');
    }
}
