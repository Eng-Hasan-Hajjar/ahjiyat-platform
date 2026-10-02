<?php

namespace App\Http\Controllers;

use App\Models\QuestDefinition;
use App\Services\Engagement\QuestPeriodService;
use App\Services\Engagement\QuestService;
use App\Services\Engagement\StreakService;
use Illuminate\Support\Facades\Auth;

class PlayerQuestsController extends Controller
{
    public function __construct(
        protected QuestService $quests,
        protected QuestPeriodService $periods,
        protected StreakService $streaks,
    ) {}

    public function show()
    {
        $user = Auth::user();

        // بند 187-190/439: شفاء ذاتي - إعادة تقييم من بيانات اللعب الحقيقية
        // الموجودة بالفعل، لا منح تقدُّم جديد. لا يمسّ الـStreak إطلاقًا
        // (مصححَّة: الإصدار السابق استخدم PuzzleAttempt وهمية غير محفوظة
        // كانت ستُفسد تحديث الـStreak بـcreated_at فارغة - اكتُشِف ذاتيًا).
        $this->quests->syncCurrentQuests($user);

        $dailyContext = $this->periods->dailyContext();
        $weeklyContext = $this->periods->weeklyContext();

        $daily = $this->questsFor($user, QuestDefinition::PERIOD_DAILY, $dailyContext);
        $weekly = $this->questsFor($user, QuestDefinition::PERIOD_WEEKLY, $weeklyContext);

        $streak = $this->streaks->streakFor($user);

        return view('quests.show', compact('daily', 'weekly', 'streak'));
    }

    protected function questsFor($user, string $periodType, $period)
    {
        return QuestDefinition::where('period_type', $periodType)
            ->orderBy('sort_order')
            ->get()
            ->filter(fn (QuestDefinition $quest) => $this->quests->isVisibleTo($user, $quest, $period))
            ->map(function (QuestDefinition $quest) use ($user, $period) {
                $progress = $this->quests->progressFor($user, $quest, $period);

                return [
                    'quest' => $quest,
                    'progress' => $progress,
                    'percent' => $progress->target_value_snapshot > 0
                        ? min(100, (int) round(($progress->current_value / $progress->target_value_snapshot) * 100))
                        : 0,
                ];
            })
            ->values();
    }
}
