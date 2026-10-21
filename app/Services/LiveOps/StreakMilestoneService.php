<?php

namespace App\Services\LiveOps;

use App\Models\Achievement;
use App\Models\PlayerStreak;
use App\Models\User;
use App\Services\Progression\AchievementEvaluatorRegistry;
use App\Services\Progression\AchievementService;
use Illuminate\Support\Facades\Log;

/**
 * E34 - معالم السلسلة اليومية (3/7/14/30/60/100 قابلة للضبط).
 *
 *  - StreakService يبقى السلطة الوحيدة: هذه الخدمة **تقرأ** longest_streak فقط ولا تكتب سلسلة.
 *  - المعلَم = إنجاز (Achievement) من نوع streak_longest_days؛ المنح/الإشعار/الأمان عبر AchievementService كما هو.
 *    الافتراضي: تقدير بلا مكافأة (xp_reward = 0)؛ أي مكافأة يضبطها المدير من لوحة الإنجازات وتمر بسلطة الاقتصاد الموجودة (مرة واحدة، idempotent).
 *  - لا Freeze ولا حماية مدفوعة ولا استرداد: longest_streak لا يتناقص، فكسر السلسلة لا يسحب معلمًا نيل.
 *  - محدودة التكلفة: لا تفعل شيئًا ما لم يبلغ longest أصغر عتبة، ولا تقيّم إلا إنجازات معلم فعّالة لم تُفتح بعد وهدفها ≤ longest (استعلام واحد).
 */
class StreakMilestoneService
{
    public function __construct(protected AchievementService $achievements) {}

    /** @return list<int> عتبات مرتَّبة تصاعديًا بلا تكرار. */
    public function thresholds(): array
    {
        $t = array_values(array_unique(array_map('intval', (array) config('liveops.streak_milestones', [3, 7, 14, 30, 60, 100]))));
        $t = array_values(array_filter($t, fn (int $d) => $d > 0));
        sort($t);

        return $t;
    }

    /** يُستدعى بعد تحديث السلسلة من حلّ رسمي صحيح. آمن لإعادة الاستدعاء (idempotent). */
    public function onStreakUpdated(User $user): void
    {
        $thresholds = $this->thresholds();

        if ($thresholds === []) {
            return;
        }

        $longest = (int) (PlayerStreak::query()->where('user_id', $user->id)->value('longest_streak') ?? 0);

        if ($longest < $thresholds[0]) {
            return;
        }

        $pending = Achievement::query()
            ->where('is_active', true)
            ->where('condition_type', AchievementEvaluatorRegistry::STREAK_LONGEST_DAYS)
            ->where('target_value', '<=', $longest)
            ->whereNotExists(function ($q) use ($user) {
                $q->selectRaw('1')->from('user_achievement_progress')
                    ->whereColumn('user_achievement_progress.achievement_id', 'achievements.id')
                    ->where('user_achievement_progress.user_id', $user->id)
                    ->whereNotNull('user_achievement_progress.unlocked_at');
            })
            ->get();

        foreach ($pending as $achievement) {
            try {
                $this->achievements->evaluateAchievement($user, $achievement);
            } catch (\Throwable $e) {
                Log::error('فشل تقييم معلم سلسلة', ['achievement_id' => $achievement->id, 'user_id' => $user->id, 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * السلسلة **الفعّالة** للعرض: current_streak المخزَّن لا يتناقص بمرور الوقت (يُصفَّر عند أول نشاط جديد فقط)، فنعرض 0 إن كان آخر يوم لعب رسمي
     * أقدم من أمس بالتاريخ المحلي للمنصة. عرض فقط: لا كتابة ولا عقوبة ولا مساس بـlongest_streak (يبقى محفوظًا دائمًا).
     */
    public static function effectiveCurrent(?PlayerStreak $row): int
    {
        if ($row === null || (int) $row->current_streak <= 0) {
            return 0;
        }

        if ($row->last_active_date === null) {
            return (int) $row->current_streak;
        }

        $today = \Illuminate\Support\Carbon::parse(app(\App\Services\Engagement\QuestPeriodService::class)->todayLocal())->startOfDay();
        $last = \Illuminate\Support\Carbon::parse($row->last_active_date)->startOfDay();

        return $last->diffInDays($today) <= 1 ? (int) $row->current_streak : 0;
    }

    /**
     * حالة العرض (للقراءة فقط، مشتقة من longest/current بلا كتابة): المعالم المنجزة، والمعلم التالي والأيام المتبقية.
     *
     * @return array{current:int, longest:int, reached:list<int>, next:?int, days_to_next:?int}
     */
    public function statusFor(User $user): array
    {
        $row = PlayerStreak::query()->where('user_id', $user->id)->first(['current_streak', 'longest_streak', 'last_active_date']);
        $current = self::effectiveCurrent($row);
        $longest = (int) ($row?->longest_streak ?? 0);
        $thresholds = $this->thresholds();

        $reached = array_values(array_filter($thresholds, fn (int $d) => $d <= $longest));
        // "التالي" = أول معلَم **لم يُنَل بعد** (أكبر من أطول سلسلة)، والأيام المتبقية = ما ينقص سلسلتك الحالية لبلوغه (لا يُعرض معلَم نِيل سابقًا كأنه قادم).
        $next = collect($thresholds)->first(fn (int $d) => $d > $longest);

        return [
            'current' => $current,
            'longest' => $longest,
            'reached' => $reached,
            'next' => $next,
            'days_to_next' => $next === null ? null : max(0, $next - $current),
        ];
    }
}
