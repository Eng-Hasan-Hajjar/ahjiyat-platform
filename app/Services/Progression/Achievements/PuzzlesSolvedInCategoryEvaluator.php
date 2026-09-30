<?php

namespace App\Services\Progression\Achievements;

use App\Models\Achievement;
use App\Models\User;
use App\Services\Progression\AchievementEvaluatorRegistry;

class PuzzlesSolvedInCategoryEvaluator implements AchievementEvaluator
{
    public function supports(Achievement $achievement): bool
    {
        return $achievement->condition_type === AchievementEvaluatorRegistry::PUZZLES_SOLVED_IN_CATEGORY;
    }

    public function currentValue(User $user, Achievement $achievement): int
    {
        if ($achievement->scope_type !== 'puzzle_category' || $achievement->scope_id === null) {
            return 0;
        }

        return $user->puzzleAttempts()
            ->where('is_correct', true)
            ->whereHas('puzzle', fn ($q) => $q->where('puzzle_category_id', $achievement->scope_id))
            ->distinct('puzzle_id')
            ->count('puzzle_id');
    }
}