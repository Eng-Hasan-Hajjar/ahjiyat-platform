<?php

namespace App\Services\PlayerIdentity;

use App\Models\ChallengeParticipant;
use App\Models\User;

class PlayerProfileService
{
    public function safeStatsFor(User $user): array
    {
        return [
            'puzzles_solved' => $user->puzzleAttempts()
                ->where('is_correct', true)
                ->distinct('puzzle_id')
                ->count('puzzle_id'),
            'challenges_participated' => ChallengeParticipant::where('user_id', $user->id)->count(),
            'gate_qualifications' => $user->campaignQualifications()->count(),
        ];
    }
}