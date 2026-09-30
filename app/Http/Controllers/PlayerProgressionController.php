<?php

namespace App\Http\Controllers;

use App\Models\Achievement;
use App\Models\LevelDefinition;
use App\Services\Progression\LevelService;
use Illuminate\Support\Facades\Auth;

class PlayerProgressionController extends Controller
{
    public function __construct(protected LevelService $levels) {}

    public function show()
    {
        $user = Auth::user();

        $progression = $this->levels->progressionFor($user);
        $currentLevel = $this->levels->currentLevelFor($user);
        $nextLevel = $this->levels->nextLevelFor($user);
        $progressPercent = $this->levels->progressPercentFor($user);

        $allLevels = LevelDefinition::where('is_active', true)->orderBy('level_number')->get();

        $userProgressByAchievement = $user->achievementProgress()->get()->keyBy('achievement_id');

        $achievements = Achievement::where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(function (Achievement $achievement) use ($userProgressByAchievement) {
                $progress = $userProgressByAchievement->get($achievement->id);
                $isUnlocked = $progress?->isUnlocked() ?? false;

                return [
                    'achievement' => $achievement,
                    'current_value' => $progress?->current_value ?? 0,
                    'is_unlocked' => $isUnlocked,
                    'unlocked_at' => $progress?->unlocked_at,
                    'reveal' => ! $achievement->is_hidden || $isUnlocked,
                ];
            });

        return view('progress.show', compact('progression', 'currentLevel', 'nextLevel', 'progressPercent', 'allLevels', 'achievements'));
    }
}