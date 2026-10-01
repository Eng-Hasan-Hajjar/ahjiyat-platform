<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\QuestDefinition;
use App\Services\Engagement\QuestPeriodService;
use App\Services\Engagement\QuestService;
use App\Services\Engagement\StreakService;
use Illuminate\Http\Request;

/** E13 (بند 379-381): قراءة فقط بالكامل - لا POST/PUT/PATCH لتعديل التقدُّم أو السلسلة إطلاقًا. */
class EngagementController extends Controller
{
    public function __construct(
        protected QuestService $quests,
        protected QuestPeriodService $periods,
        protected StreakService $streaks,
    ) {}

    public function show(Request $request)
    {
        $user = $request->user();
        $dailyPeriod = $this->periods->dailyContext();
        $weeklyPeriod = $this->periods->weeklyContext();

        $format = function (string $periodType, $period) use ($user) {
            return QuestDefinition::where('period_type', $periodType)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get()
                ->filter(fn (QuestDefinition $q) => $this->quests->isVisibleTo($user, $q))
                ->map(function (QuestDefinition $q) use ($user, $period) {
                    $progress = $this->quests->progressFor($user, $q, $period);

                    return [
                        'name' => $q->name,
                        'description' => $q->description,
                        'current_value' => $progress->current_value,
                        'target_value' => $progress->target_value_snapshot,
                        'completed' => $progress->completed_at !== null,
                    ];
                })->values();
        };

        $streak = $this->streaks->streakFor($user);

        return [
            'daily_quests' => $format(QuestDefinition::PERIOD_DAILY, $dailyPeriod),
            'weekly_quests' => $format(QuestDefinition::PERIOD_WEEKLY, $weeklyPeriod),
            'current_streak' => $streak->current_streak,
            'longest_streak' => $streak->longest_streak,
        ];
    }
}
