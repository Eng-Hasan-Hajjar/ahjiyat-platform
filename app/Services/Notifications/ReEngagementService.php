<?php

namespace App\Services\Notifications;

use App\Models\PlayerStreak;
use App\Models\User;
use App\Services\Engagement\QuestPeriodService;
use App\Services\Engagement\QuestService;
use App\Services\PlatformSettingsService;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * تذكيرات العودة (يشغّلها المجدوِل الساعي فقط). ثوابت حاكمة:
 *  - **قراءة فقط من حالة اللعب**: يقرأ player_streaks (ملخّص E13) ويسأل QuestService::hasCurrentDailyQuests()
 *    - لا يُنشئ UserQuestProgress ولا يستدعي syncCurrentQuests/progressFor ولا يعدّل Streak (لا Side effect؛ لا
 *    إعادة لخلل E13 التاريخي "الشفاء الذاتي يُنشئ صفوفًا لغير المؤهَّلين").
 *  - الصحة لا تعتمد عليه: بغياب المجدوِل لا تصل التذكيرات فقط؛ لا فساد للعب ولا للسلسلة.
 *  - Idempotent: مفتاح دلالي لكل (مستخدم + يوم) - تشغيل الأمر 10 مرات = إشعار واحد.
 *  - لا تحميل لكل المستخدمين: chunkById على صفوف ضيقة + تحميل مستخدمي الدفعة باستعلام واحد.
 *  - التوقيت بالمنطقة الزمنية للمنصة (QuestPeriodService::timezone) وبـnow() الحقيقي (درس E13.1).
 */
class ReEngagementService
{
    public function __construct(
        protected PlatformSettingsService $settings,
        protected NotificationDispatcher $dispatcher,
        protected ReEngagementPolicy $policy,
        protected QuestPeriodService $periods,
        protected QuestService $quests,
    ) {}

    /** @return array{streak_risk: array<string, mixed>, daily: array<string, mixed>} */
    public function run(): array
    {
        return [
            'streak_risk' => $this->dispatchStreakRisk(),
            'daily' => $this->dispatchDailyReminders(),
        ];
    }

    /**
     * سلسلة حيّة (>= الحد الأدنى) وآخر نشاط مؤهِّل **أمس** محليًا (أي لم يتأهل اليوم بعد)، ونحن داخل نافذة
     * التحذير (الساعة المحلية >= streak_warning_hour، وتنتهي بنهاية اليوم طبيعيًا).
     */
    public function dispatchStreakRisk(): array
    {
        $stats = $this->emptyStats();

        if (! $this->flag('notifications_enabled') || ! $this->flag('streak_warning_enabled')) {
            return $stats + ['skipped' => 'disabled'];
        }

        if ($this->localHour() < (int) $this->settings->get('notifications', 'streak_warning_hour', 18)) {
            return $stats + ['skipped' => 'before_window'];
        }

        $today = $this->periods->todayLocal();
        $yesterday = $this->shiftDate($today, -1);
        $min = max(1, (int) $this->settings->get('notifications', 'min_streak_for_warning', 2));

        PlayerStreak::query()
            ->join('users', 'users.id', '=', 'player_streaks.user_id')
            ->where('users.is_frozen', false)
            ->where('player_streaks.current_streak', '>=', $min)
            ->whereDate('player_streaks.last_active_date', $yesterday)
            ->select('player_streaks.id as id', 'player_streaks.user_id as user_id', 'player_streaks.current_streak as current_streak')
            ->chunkById($this->chunkSize(), function (Collection $rows) use (&$stats, $today) {
                $used = $this->policy->usedToday($rows->pluck('user_id')->all());
                $users = User::query()->whereIn('id', $rows->pluck('user_id')->all())->get()->keyBy('id');
                $max = $this->policy->maxPerDay();

                foreach ($rows as $row) {
                    $stats['candidates']++;
                    $user = $users->get($row->user_id);

                    if ($user === null || ($used[$row->user_id] ?? 0) >= $max) {
                        $stats['suppressed']++;

                        continue;
                    }

                    $this->tally($stats, $this->dispatcher->dispatch(
                        $user,
                        NotificationType::StreakAtRisk,
                        ['streak' => (int) $row->current_streak],
                        "streak-risk:{$row->user_id}:{$today}",
                        ['date' => $today],
                    ));
                }
            }, 'player_streaks.id', 'id');

        return $stats;
    }

    /**
     * جمهور ضيق: لاعبون كان لهم نشاط مؤهِّل خلال آخر N يومًا ولم يلعبوا اليوم، **ولا** سلسلة حيّة لديهم (هؤلاء
     * يتلقون تحذير السلسلة الأعلى أولوية بدل هذا التذكير). نافذة الإرسال: [daily_reminder_hour, streak_warning_hour).
     * يتطلب وجود مهام يومية قائمة فعلًا (قراءة فقط).
     */
    public function dispatchDailyReminders(): array
    {
        $stats = $this->emptyStats();

        if (! $this->flag('notifications_enabled') || ! $this->flag('daily_reminder_enabled')) {
            return $stats + ['skipped' => 'disabled'];
        }

        $hour = $this->localHour();
        $from = (int) $this->settings->get('notifications', 'daily_reminder_hour', 10);
        $until = (int) $this->settings->get('notifications', 'streak_warning_hour', 18);

        if ($hour < $from || $hour >= $until) {
            return $stats + ['skipped' => 'outside_window'];
        }

        if (! $this->quests->hasCurrentDailyQuests()) {
            return $stats + ['skipped' => 'no_daily_quests'];
        }

        $today = $this->periods->todayLocal();
        $yesterday = $this->shiftDate($today, -1);
        $earliest = $this->shiftDate($today, -max(1, (int) config('player_notifications.recent_activity_days')));
        $min = max(1, (int) $this->settings->get('notifications', 'min_streak_for_warning', 2));

        PlayerStreak::query()
            ->join('users', 'users.id', '=', 'player_streaks.user_id')
            ->where('users.is_frozen', false)
            ->whereDate('player_streaks.last_active_date', '>=', $earliest)
            ->whereDate('player_streaks.last_active_date', '<=', $yesterday)
            ->where(function ($q) use ($min, $yesterday) {
                // ليست سلسلة حيّة: (سلسلة < الحد الأدنى) أو (آخر نشاط قبل أمس).
                $q->where('player_streaks.current_streak', '<', $min)
                    ->orWhereDate('player_streaks.last_active_date', '<', $yesterday);
            })
            ->select('player_streaks.id as id', 'player_streaks.user_id as user_id')
            ->chunkById($this->chunkSize(), function (Collection $rows) use (&$stats, $today) {
                $ids = $rows->pluck('user_id')->all();
                $used = $this->policy->usedToday($ids);
                $warned = $this->policy->streakWarnedToday($ids);
                $users = User::query()->whereIn('id', $ids)->get()->keyBy('id');
                $max = $this->policy->maxPerDay();

                foreach ($rows as $row) {
                    $stats['candidates']++;
                    $user = $users->get($row->user_id);

                    if ($user === null || isset($warned[$row->user_id]) || ($used[$row->user_id] ?? 0) >= $max) {
                        $stats['suppressed']++;

                        continue;
                    }

                    $this->tally($stats, $this->dispatcher->dispatch(
                        $user,
                        NotificationType::DailyQuestsAvailable,
                        [],
                        "daily-quests:{$row->user_id}:daily:{$today}",
                        ['period' => "daily:{$today}"],
                    ));
                }
            }, 'player_streaks.id', 'id');

        return $stats;
    }

    protected function emptyStats(): array
    {
        return ['candidates' => 0, 'created' => 0, 'duplicate' => 0, 'suppressed' => 0, 'failed' => 0];
    }

    protected function tally(array &$stats, DispatchResult $result): void
    {
        $key = $result->value;
        $stats[$key] = ($stats[$key] ?? 0) + 1;
    }

    protected function flag(string $key): bool
    {
        return (bool) $this->settings->get('notifications', $key, true);
    }

    protected function localHour(): int
    {
        return (int) now()->copy()->setTimezone($this->periods->timezone())->format('G');
    }

    protected function shiftDate(string $localDate, int $days): string
    {
        return Carbon::parse($localDate, $this->periods->timezone())->addDays($days)->format('Y-m-d');
    }

    protected function chunkSize(): int
    {
        return max(1, (int) config('player_notifications.chunk_size', 200));
    }
}
