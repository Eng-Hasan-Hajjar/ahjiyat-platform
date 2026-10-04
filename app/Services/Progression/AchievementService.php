<?php

namespace App\Services\Progression;

use App\Events\AchievementUnlocked;
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

        $justUnlocked = false;

        $progress = DB::transaction(function () use ($user, $achievement, $evaluatedValue, &$justUnlocked) {
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
                $justUnlocked = true;
            }

            return $progress->fresh();
        });

        // E15: إشعار الفتح = أثر جانبي ثانوي بعد commit فعليًا (أو بعد commit المعاملة الخارجية إن وُجدت)؛ أي
        // فشل فيه معزول تمامًا ولا يمسّ فتح الإنجاز ولا المكافأة.
        if ($justUnlocked) {
            DB::afterCommit(function () use ($user, $achievement) {
                try {
                    event(new AchievementUnlocked($user, $achievement));
                } catch (\Throwable $e) {
                    report($e);
                }
            });
        }

        if ($progress->unlocked_at !== null && $progress->reward_granted_at === null) {
            $this->grantRewards($user, $achievement, $progress);
        }
    }

       /**
     * كل مكوِّنات المكافأة (XP + عملة + عنصر) ووسم reward_granted_at داخل
     * معاملة واحدة - الوسم يُكتَب في النهاية فقط بعد نجاح كل شيء فعليًا،
     * لا قبله أبدًا (إصلاح خطأ حقيقي: كتابته أولًا بلا معاملة تُغلِّفه كانت
     * تترك الإنجاز "موسومًا بالمنح" رغم فشل جزء منه فعليًا - فلا تُعاد
     * المحاولة أبدًا لاحقًا. مكتشَف تجريبيًا بسجل تشخيص مباشر).
     */
    protected function grantRewards(User $user, Achievement $achievement, UserAchievementProgress $progress): void
    {
        DB::transaction(function () use ($user, $achievement, $progress) {
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

            $progress->update(['reward_granted_at' => now()]);
        });
    }
}