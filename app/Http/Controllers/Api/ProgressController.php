<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Progression\LevelService;
use Illuminate\Http\Request;

class ProgressController extends Controller
{
    public function __construct(protected LevelService $levels) {}

    public function show(Request $request)
    {
        $user = $request->user();
        $progression = $this->levels->progressionFor($user);
        $nextLevel = $this->levels->nextLevelFor($user);

        return [
            'current_level' => $progression->current_level,
            'total_xp' => $progression->total_xp,
            'next_level_xp' => $nextLevel?->xp_required_total,
            'progress_percent' => $this->levels->progressPercentFor($user),
            'achievement_count' => $user->achievementProgress()->whereNotNull('unlocked_at')->count(),
        ];
    }
}