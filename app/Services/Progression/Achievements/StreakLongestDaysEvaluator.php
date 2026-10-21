<?php

namespace App\Services\Progression\Achievements;

use App\Models\Achievement;
use App\Models\PlayerStreak;
use App\Models\User;
use App\Services\Progression\AchievementEvaluatorRegistry;

/**
 * E34 - معلم السلسلة اليومية كإنجاز (لا محرك إنجازات/سلسلة ثانٍ). المصدر الوحيد: PlayerStreak.longest_streak
 * (يُحدَّث حصرًا عبر StreakService من حلّ رسمي صحيح؛ لا يتناقص ولا يعتمد على الدخول ولا على الوقت).
 * لذلك كسر السلسلة لاحقًا لا يسحب معلَمًا سبق نيله (longest محفوظ)، ولا شيء يُسترد أبدًا.
 */
class StreakLongestDaysEvaluator implements AchievementEvaluator
{
    public function supports(Achievement $achievement): bool
    {
        return $achievement->condition_type === AchievementEvaluatorRegistry::STREAK_LONGEST_DAYS;
    }

    public function currentValue(User $user, Achievement $achievement): int
    {
        return (int) (PlayerStreak::query()->where('user_id', $user->id)->value('longest_streak') ?? 0);
    }
}
