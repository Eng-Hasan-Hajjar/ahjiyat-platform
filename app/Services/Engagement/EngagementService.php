<?php

namespace App\Services\Engagement;

use App\Models\PuzzleAttempt;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * E13 (بند 220-222): نقطة التنسيق الوحيدة بعد حدث لعب - Quest + Streak
 * معًا، بلا إعادة تنفيذ محرك XP/الإنجازات إطلاقًا (تبقى من مسؤولية
 * GameplayProgressionService حصرًا، كما هي).
 */
class EngagementService
{
    public function __construct(
        protected QuestService $quests,
        protected StreakService $streaks,
    ) {}

    /** الحدث المؤهِّل الوحيد للـStreak: حل أحجية صحيح فعليًا (مضمون بالتصميم - يُستدعى فقط عند نجاح الحل). */
    public function onPuzzleSolved(User $user, PuzzleAttempt $attempt): void
    {
        try {
            $this->streaks->recordQualifyingActivity($user, $attempt->created_at);
        } catch (\Throwable $e) {
            Log::error('فشل تحديث السلسلة (Streak)', ['attempt_id' => $attempt->id, 'user_id' => $user->id, 'error' => $e->getMessage()]);
        }

        $this->quests->evaluateForEvent('puzzle_solved', $user);
    }

    public function onCampaignStepCompleted(User $user): void
    {
        $this->quests->evaluateForEvent('campaign_step_completed', $user);
    }

    public function onQualificationEarned(User $user): void
    {
        $this->quests->evaluateForEvent('qualification_earned', $user);
    }
}
