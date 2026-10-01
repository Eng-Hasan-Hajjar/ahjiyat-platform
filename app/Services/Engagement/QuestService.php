<?php

namespace App\Services\Engagement;

use App\Models\QuestDefinition;
use App\Models\User;
use App\Models\UserQuestProgress;
use App\Models\XpTransaction;
use App\Services\Progression\ProgressionRewardService;
use App\Services\Progression\XpService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * E13 (بند 19-27، 128-140): التنسيق الرئيسي لتقدُّم Quest وإكمالها ومنح
 * مكافأتها. يُطبِّق بدقة الدرس المُستخلَص من E12.1: reward_granted_at
 * تُوسَم في النهاية فقط بعد نجاح المنح فعليًا، أبدًا قبله، وداخل معاملة
 * واحدة تُغلِّف كل مكوّنات المكافأة معًا.
 */
class QuestService
{
    public function __construct(
        protected QuestPeriodService $periods,
        protected QuestEvaluatorRegistry $registry,
        protected ProgressionRewardService $rewards,
        protected XpService $xp,
    ) {}

    /** Lazy Creation صارمة (بند 168-170): لا صف يُنشأ إلا عند فتح فعلي لصفحة المهام أو حدث لعب مرتبط. */
    public function progressFor(User $user, QuestDefinition $quest, ?PeriodContext $period = null): UserQuestProgress
    {
        $period ??= $this->periods->contextFor($quest->period_type);

        return UserQuestProgress::firstOrCreate(
            ['user_id' => $user->id, 'quest_definition_id' => $quest->id, 'period_key' => $period->periodKey],
            [
                'period_type' => $period->periodType,
                'period_start' => $period->start,
                'period_end' => $period->end,
                'current_value' => 0,
                'target_value_snapshot' => $quest->target_value,
            ],
        );
    }

    /** بند 366: مهمة غير نشطة لا تظهر للاعب إلا إذا كان له تقدُّم قائم فعليًا بالفعل بها. */
    public function isVisibleTo(User $user, QuestDefinition $quest): bool
    {
        if ($quest->is_active) {
            return true;
        }

        return UserQuestProgress::where('user_id', $user->id)->where('quest_definition_id', $quest->id)->exists();
    }

    /**
     * بند 226/305: شفاء ذاتي عند فتح صفحة المهام - يُعيد تقييم كل الأحداث
     * الموثوقة الثلاثة من بيانات اللعب الحقيقية الموجودة بالفعل (لا يمنح
     * تقدُّمًا جديدًا، فقط يُصحِّح ما لم يُعالَج سابقًا). لا علاقة له بالـ
     * Streak إطلاقًا - عمداً، فآلية إصلاح الـStreak منفصلة (recalculateFromHistory).
     */
    public function syncCurrentQuests(User $user): void
    {
        $this->evaluateForEvent('puzzle_solved', $user);
        $this->evaluateForEvent('campaign_step_completed', $user);
        $this->evaluateForEvent('qualification_earned', $user);
    }

    public function evaluateForEvent(string $event, User $user): void
    {
        $conditionTypes = $this->registry->conditionTypesForEvent($event);

        if ($conditionTypes === []) {
            return;
        }

        $quests = QuestDefinition::where('is_active', true)
            ->whereIn('condition_type', $conditionTypes)
            ->get();

        if ($quests->isEmpty()) {
            return;
        }

        // بند 241: تُحسَب مرة واحدة لكل نوع فترة بهذا الحدث، لا لكل مهمة على حدة.
        $periodsByType = [
            QuestDefinition::PERIOD_DAILY => $this->periods->dailyContext(),
            QuestDefinition::PERIOD_WEEKLY => $this->periods->weeklyContext(),
        ];

        foreach ($quests as $quest) {
            try {
                $this->evaluateQuest($user, $quest, $periodsByType[$quest->period_type]);
            } catch (\Throwable $e) {
                Log::error('فشل تقييم مهمة', ['quest_id' => $quest->id, 'user_id' => $user->id, 'error' => $e->getMessage()]);
            }
        }
    }

    public function evaluateQuest(User $user, QuestDefinition $quest, ?PeriodContext $period = null): void
    {
        $period ??= $this->periods->contextFor($quest->period_type);

        // بند 171: نافذة توفُّر التعريف تُقصي فترات خارج starts_at/ends_at كليًا.
        if ($quest->starts_at !== null && $period->end->lt($quest->starts_at)) {
            return;
        }
        if ($quest->ends_at !== null && $period->start->gt($quest->ends_at)) {
            return;
        }

        $evaluator = $this->registry->evaluatorFor($quest->condition_type);

        if ($evaluator === null) {
            return;
        }

        $evaluatedValue = $evaluator->currentValue($user, $quest, $period);

        $progress = DB::transaction(function () use ($user, $quest, $period, $evaluatedValue) {
            $progress = UserQuestProgress::query()
                ->where('user_id', $user->id)
                ->where('quest_definition_id', $quest->id)
                ->where('period_key', $period->periodKey)
                ->lockForUpdate()
                ->first();

            if ($progress === null) {
                $progress = UserQuestProgress::create([
                    'user_id' => $user->id,
                    'quest_definition_id' => $quest->id,
                    'period_type' => $period->periodType,
                    'period_key' => $period->periodKey,
                    'period_start' => $period->start,
                    'period_end' => $period->end,
                    'current_value' => 0,
                    'target_value_snapshot' => $quest->target_value,
                ]);
            }

            $newValue = max($progress->current_value, $evaluatedValue);

            if ($newValue !== $progress->current_value) {
                $progress->update(['current_value' => $newValue]);
            }

            if ($progress->completed_at === null && $newValue >= $progress->target_value_snapshot) {
                $progress->update(['completed_at' => now()]);
            }

            return $progress->fresh();
        });

        if ($progress->completed_at !== null && $progress->reward_granted_at === null) {
            $this->grantRewards($user, $quest, $progress);
        }
    }

    /**
     * بند 139/436: معاملة واحدة تُغلِّف XP+عملة+عنصر+الوسم معًا - الوسم في
     * النهاية فقط بعد نجاح كل شيء. فشل جزئي = إعادة محاولة آمنة لاحقًا،
     * حتى بعد انتهاء الفترة (بند 141 - غير "ضائعة" أبدًا).
     */
    protected function grantRewards(User $user, QuestDefinition $quest, UserQuestProgress $progress): void
    {
        DB::transaction(function () use ($user, $quest, $progress) {
            if ($quest->xp_reward > 0) {
                $this->xp->grantXp(
                    $user,
                    $quest->xp_reward,
                    XpTransaction::TYPE_QUEST_REWARD,
                    "quest:{$quest->internal_key}",
                    $progress,
                    "quest:{$quest->id}:user:{$user->id}:period:{$progress->period_key}:xp",
                );
            }

            $this->rewards->grantQuestRewards($quest, $user, $progress);

            $progress->update(['reward_granted_at' => now()]);
        });
    }
}
