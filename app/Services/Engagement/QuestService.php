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

    /**
     * E13.1 (بند 18-25، إصلاح حقيقي): كانت تتحقَّق من وجود أي تقدُّم تاريخي
     * مطلقًا (->exists() بلا تقييد بالفترة) وبلا فحص starts_at/ends_at - ما
     * يجعل مهمة مُعطَّلة أو منتهية النافذة **ظاهرة للأبد** لمجرد أثر تاريخي
     * واحد، بل وتُنشئ تقدُّمًا **جديدًا** لها (progressFor()'s firstOrCreate
     * يُستدعى لاحقًا لكل ما يمر من هنا). الإصلاح: التحقُّق يكون من تقدُّم
     * الفترة **الحالية** تحديدًا، ومن النافذة الزمنية الفعلية لا من is_active وحدها.
     */
    public function isVisibleTo(User $user, QuestDefinition $quest, ?PeriodContext $period = null): bool
    {
        $period ??= $this->periods->contextFor($quest->period_type);

        $withinWindow = ($quest->starts_at === null || $period->end->gte($quest->starts_at))
            && ($quest->ends_at === null || $period->start->lte($quest->ends_at));

        if ($quest->is_active && $withinWindow) {
            return true;
        }

        // غير نشطة أو خارج نافذتها حاليًا - تبقى ظاهرة فقط إذا بدأها المستخدم
        // فعلًا بالفترة الحالية **قبل** التعطيل/الانتهاء (بند 19: يُسمَح بإكمالها، لا إسناد جديد).
        return UserQuestProgress::where('user_id', $user->id)
            ->where('quest_definition_id', $quest->id)
            ->where('period_key', $period->periodKey)
            ->exists();
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

        $this->recoverPendingRewards($user);
    }

    /**
     * E13.1 (إصلاح حقيقي - بند 2-7): مصدر الاستعادة هو UserQuestProgress
     * المكتملة وغير المكافأة نفسها - بصرف النظر تمامًا عن الفترة أو حالة
     * تعريف المهمة الحالية (نشطة/مُعطَّلة/منتهية النافذة). لا يُعيد فتح
     * التقدُّم، لا يُعدِّل current_value، لا يُنشئ إكمالًا جديدًا - يمنح
     * المكافأة فقط لسجل اكتمل بالفعل سابقًا. لا اعتماد على Cron: يُستدعى
     * من مزامنة فتح الصفحة، مستقل تمامًا عن أي لعب جديد (بند 7).
     */
    public function recoverPendingRewards(User $user): void
    {
        $pending = UserQuestProgress::where('user_id', $user->id)
            ->whereNotNull('completed_at')
            ->whereNull('reward_granted_at')
            ->with('questDefinition')
            ->get();

        foreach ($pending as $progress) {
            if ($progress->questDefinition === null) {
                continue;
            }

            try {
                $this->grantRewards($user, $progress->questDefinition, $progress);
            } catch (\Throwable $e) {
                Log::error('فشل استرجاع مكافأة مهمة متأخرة', [
                    'progress_id' => $progress->id, 'user_id' => $user->id, 'error' => $e->getMessage(),
                ]);
            }
        }
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
     *
     * E13.1 (إصلاح سباق حقيقي - بند 8-12): القفل السابق بمعاملة evaluateQuest()
     * يُفَكّ **قبل** وصولنا هنا - فحص "هل ما زالت تحتاج مكافأة؟" يجب أن يكون
     * ذرّيًا مع المنح نفسه، لا معتمدًا على قفل سابق انتهى. نُعيد قفل نفس
     * الصف وإعادة قراءة reward_granted_at **داخل** هذه المعاملة بالذات -
     * طلب متزامن ثانٍ سيجد العلامة مضبوطة فعلًا فور حصوله على القفل بعد
     * التزام الأول، فيعود فورًا بلا أي استدعاء لأي خدمة منح.
     */
    protected function grantRewards(User $user, QuestDefinition $quest, UserQuestProgress $progress): void
    {
        DB::transaction(function () use ($user, $quest, $progress) {
            $locked = UserQuestProgress::where('id', $progress->id)->lockForUpdate()->first();

            if ($locked === null || $locked->reward_granted_at !== null) {
                return; // خسرنا السباق - جهة أخرى منحتها بالفعل، أو السجل حُذف.
            }

            if ($quest->xp_reward > 0) {
                $this->xp->grantXp(
                    $user,
                    $quest->xp_reward,
                    XpTransaction::TYPE_QUEST_REWARD,
                    "quest:{$quest->internal_key}",
                    $locked,
                    "quest-progress:{$locked->id}:xp",
                );
            }

            $this->rewards->grantQuestRewards($quest, $user, $locked, "quest-progress:{$locked->id}:currency");

            $locked->update(['reward_granted_at' => now()]);
        });
    }
}
