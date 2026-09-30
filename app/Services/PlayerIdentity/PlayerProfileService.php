<?php

namespace App\Services\PlayerIdentity;

use App\Models\ChallengeParticipant;
use App\Models\User;
use App\Services\Progression\LevelService;

class PlayerProfileService
{
    public function __construct(protected LevelService $levels) {}

    public function safeStatsFor(User $user): array
    {
        return [
            'puzzles_solved' => $user->puzzleAttempts()
                ->where('is_correct', true)
                ->distinct('puzzle_id')
                ->count('puzzle_id'),
            'challenges_participated' => ChallengeParticipant::where('user_id', $user->id)->count(),
            'gate_qualifications' => $user->campaignQualifications()->count(),
            'current_level' => $this->levels->currentLevelFor($user)->level_number,
            'achievements_unlocked' => $user->achievementProgress()->whereNotNull('unlocked_at')->count(),
        ];
    }
}