<?php

namespace App\Services\Progression\Achievements;

use App\Models\Achievement;
use App\Models\User;
use App\Services\Progression\AchievementEvaluatorRegistry;

class PuzzlesSolvedTotalEvaluator implements AchievementEvaluator
{
    public function supports(Achievement $achievement): bool
    {
        return $achievement->condition_type === AchievementEvaluatorRegistry::PUZZLES_SOLVED_TOTAL;
    }

    public function currentValue(User $user, Achievement $achievement): int
    {
        return $user->puzzleAttempts()
            ->where('is_correct', true)
            ->distinct('puzzle_id')
            ->count('puzzle_id');
    }
}