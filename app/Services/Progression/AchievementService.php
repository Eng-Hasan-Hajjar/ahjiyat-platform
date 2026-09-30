<?php

namespace App\Services\Progression;

use App\Models\Achievement;
use App\Models\User;
use App\Models\UserAchievementProgress;
use App\Models\XpTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AchievementService
{
    public function __construct(
        protected AchievementEvaluatorRegistry $registry,
        protected ProgressionRewardService $rewards,
        protected XpService $xp,
    ) {}

    public function evaluateForEvent(string $event, User $user): void
    {
        $conditionTypes = $this->registry->conditionTypesForEvent($event);

        if ($conditionTypes === []) {
            return;
        }

        $achievements = Achievement::where('is_active', true)
            ->whereIn('condition_type', $conditionTypes)
            ->get();

        foreach ($achievements as $achievement) {
            try {
                $this->evaluateAchievement($user, $achievement);
            } catch (\Throwable $e) {
                Log::error('فشل تقييم إنجاز', [
                    'achievement_id' => $achievement->id,
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    public function evaluateAchievement(User $user, Achievement $achievement): void
    {
        $evaluator = $this->registry->evaluatorFor($achievement->condition_type);

        if ($evaluator === null) {
            return;
        }

        $evaluatedValue = $evaluator->currentValue($user, $achievement);

        $progress = DB::transaction(function () use ($user, $achievement, $evaluatedValue) {
            $progress = UserAchievementProgress::query()
                ->where('user_id', $user->id)
                ->where('achievement_id', $achievement->id)
                ->lockForUpdate()
                ->first();

            if ($progress === null) {
                $progress = UserAchievementProgress::create([
                    'user_id' => $user->id,
                    'achievement_id' => $achievement->id,
                    'current_value' => 0,
                ]);
            }

            $newValue = max($progress->current_value, $evaluatedValue);

            if ($newValue !== $progress->current_value) {
                $progress->update(['current_value' => $newValue]);
            }

            if ($progress->unlocked_at === null && $achievement->target_value !== null && $newValue >= $achievement->target_value) {
                $progress->update(['unlocked_at' => now()]);
            }

            return $progress->fresh();
        });

        if ($progress->unlocked_at !== null && $progress->reward_granted_at === null) {
            $this->grantRewards($user, $achievement, $progress);
        }
    }

    protected function grantRewards(User $user, Achievement $achievement, UserAchievementProgress $progress): void
    {
        $progress->update(['reward_granted_at' => now()]);

        if ($achievement->xp_reward > 0) {
            $this->xp->grantXp(
                $user,
                $achievement->xp_reward,
                XpTransaction::TYPE_ACHIEVEMENT_REWARD,
                "achievement:{$achievement->internal_key}",
                $progress,
                "achievement:{$achievement->id}:user:{$user->id}:xp",
            );
        }

        $this->rewards->grantAchievementRewards($achievement, $user, $progress);
    }
}