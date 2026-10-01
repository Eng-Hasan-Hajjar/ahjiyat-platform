<?php

namespace App\Services\Analytics;

use App\Models\PlayerStreak;
use App\Models\QuestDefinition;
use App\Models\UserQuestProgress;
use App\Support\AnalyticsPeriod;
use Illuminate\Support\Facades\Cache;

/**
 * E13 (بند 410-416): لا خلط مع Store Revenue/Premium Spending إطلاقًا.
 * بند E12.1 المُستخلَص: أي عدّ "حيّ" (يتغيَّر باستمرار، لا محدود بفترة)
 * لا يُخزَّن مؤقَّتًا بمفتاح ثابت - فقط البيانات محدودة الفترة تُخزَّن.
 */
class EngagementAnalyticsService
{
    public function overview(AnalyticsPeriod $period): array
    {
        $cacheKey = $period->cacheKey('engagement.overview');

        return Cache::remember($cacheKey, now()->addMinutes(10), function () use ($period) {
            $completedInPeriod = UserQuestProgress::whereNotNull('completed_at')
                ->whereBetween('completed_at', [$period->start, $period->end]);

            return [
                'daily_quest_completions' => (clone $completedInPeriod)->where('period_type', QuestDefinition::PERIOD_DAILY)->count(),
                'weekly_quest_completions' => (clone $completedInPeriod)->where('period_type', QuestDefinition::PERIOD_WEEKLY)->count(),
            ];
        });
    }

    public function topCompletedQuests(AnalyticsPeriod $period, int $limit = 10): array
    {
        $cacheKey = $period->cacheKey("engagement.top_quests:limit:{$limit}");

        return Cache::remember($cacheKey, now()->addMinutes(10), function () use ($period, $limit) {
            return UserQuestProgress::whereNotNull('completed_at')
                ->whereBetween('completed_at', [$period->start, $period->end])
                ->selectRaw('quest_definition_id, COUNT(*) as completions_count')
                ->groupBy('quest_definition_id')
                ->orderByDesc('completions_count')
                ->limit($limit)
                ->with('questDefinition:id,name')
                ->get()
                ->map(fn ($row) => ['name' => $row->questDefinition?->name ?? '—', 'completions' => (int) $row->completions_count])
                ->all();
        });
    }

    /** عدّ حيّ - لا Cache بمفتاح ثابت (بند 148/305 مطبَّق هنا أيضًا). */
    public function activeStreakUsersCount(): int
    {
        return PlayerStreak::where('current_streak', '>', 0)->count();
    }

    public function averageCurrentStreak(): float
    {
        return round((float) PlayerStreak::where('current_streak', '>', 0)->avg('current_streak'), 1);
    }

    public function questCompletionRate(QuestDefinition $quest): float
    {
        $total = UserQuestProgress::where('quest_definition_id', $quest->id)->count();

        if ($total === 0) {
            return 0.0;
        }

        $completed = UserQuestProgress::where('quest_definition_id', $quest->id)->whereNotNull('completed_at')->count();

        return round(($completed / $total) * 100, 1);
    }
}
