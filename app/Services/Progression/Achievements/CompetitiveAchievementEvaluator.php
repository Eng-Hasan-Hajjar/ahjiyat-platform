<?php

namespace App\Services\Progression\Achievements;

use App\Models\Achievement;
use App\Models\User;
use App\Services\Competitive\CompetitiveStatsService;
use App\Services\Progression\AchievementEvaluatorRegistry;

/**
 * شروط إنجازات المنافسات (E18-C8): ثلاثة أنواع تُضبط بعتبات من لوحة الإدارة (لا إنجازات مبرمجة جامدة). القيمة من CompetitiveStatsService (مصدر الحقيقة
 * الوحيد): نتائج صحيحة بأحداث **معتمَدة** فقط، فلا تُحصد من تحدّيات الأصدقاء ولا من أحداث جارية.
 */
class CompetitiveAchievementEvaluator implements AchievementEvaluator
{
    public function __construct(protected CompetitiveStatsService $stats) {}

    public function supports(Achievement $achievement): bool
    {
        return in_array($achievement->condition_type, [
            AchievementEvaluatorRegistry::COMPETITIVE_EVENTS_WON,
            AchievementEvaluatorRegistry::COMPETITIVE_TOP3_FINISHES,
            AchievementEvaluatorRegistry::COMPETITIVE_EVENTS_COMPLETED,
        ], true);
    }

    public function currentValue(User $user, Achievement $achievement): int
    {
        $stats = $this->stats->eventStats($user);

        return match ($achievement->condition_type) {
            AchievementEvaluatorRegistry::COMPETITIVE_EVENTS_WON => $stats['events_won'],
            AchievementEvaluatorRegistry::COMPETITIVE_TOP3_FINISHES => $stats['top3'],
            default => $stats['events_valid_finalized'],
        };
    }
}
