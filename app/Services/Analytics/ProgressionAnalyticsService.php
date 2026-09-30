<?php

namespace App\Services\Analytics;

use App\Models\LevelDefinition;
use App\Models\PlayerProgression;
use App\Models\UserAchievementProgress;
use App\Models\XpTransaction;
use App\Support\AnalyticsPeriod;
use Illuminate\Support\Facades\Cache;

class ProgressionAnalyticsService
{
    public function overview(AnalyticsPeriod $period): array
    {
        $cacheKey = $period->cacheKey('progression.overview');

        return Cache::remember($cacheKey, now()->addMinutes(10), function () use ($period) {
            $xpInPeriod = XpTransaction::whereBetween('created_at', [$period->start, $period->end]);

            return [
                'xp_earned_total' => (int) (clone $xpInPeriod)->sum('amount'),
                'active_progression_users' => (clone $xpInPeriod)->distinct('user_id')->count('user_id'),
                'achievements_unlocked_count' => UserAchievementProgress::whereNotNull('unlocked_at')
                    ->whereBetween('unlocked_at', [$period->start, $period->end])
                    ->count(),
                'average_current_level' => round((float) PlayerProgression::avg('current_level'), 1),
            ];
        });
    }

    public function levelDistribution(): array
    {
        $cacheKey = 'progression.level_distribution';

        return Cache::remember($cacheKey, now()->addMinutes(10), function () {
            return LevelDefinition::where('is_active', true)
                ->orderBy('level_number')
                ->get()
                ->map(fn (LevelDefinition $level) => [
                    'level_number' => $level->level_number,
                    'name' => $level->name,
                    'users_count' => PlayerProgression::where('current_level', $level->level_number)->count(),
                ])
                ->all();
        });
    }

    public function topUnlockedAchievements(int $limit = 10): array
    {
        $cacheKey = "progression.top_achievements:limit:{$limit}";

        return Cache::remember($cacheKey, now()->addMinutes(10), function () use ($limit) {
            return UserAchievementProgress::whereNotNull('unlocked_at')
                ->selectRaw('achievement_id, COUNT(*) as unlocks_count')
                ->groupBy('achievement_id')
                ->orderByDesc('unlocks_count')
                ->limit($limit)
                ->with('achievement:id,name')
                ->get()
                ->map(fn ($row) => ['name' => $row->achievement?->name ?? '—', 'unlocks' => (int) $row->unlocks_count])
                ->all();
        });
    }
}