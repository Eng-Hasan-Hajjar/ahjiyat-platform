<?php

namespace App\Services\LiveOps;

use App\Models\CompetitiveEvent;
use App\Models\CompetitiveEventParticipant;
use App\Models\PlayerStreak;
use App\Models\QuestDefinition;
use App\Models\User;
use App\Models\UserQuestProgress;
use App\Services\Engagement\QuestPeriodService;
use App\Services\Notifications\DispatchResult;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationType;
use App\Services\Notifications\ReEngagementPolicy;
use App\Services\PlatformSettingsService;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;

/**
 * E34 - تذكيرات LiveOps (يشغّلها المجدوِل الساعي فقط). تعيد استعمال E15 كاملًا: NotificationDispatcher (تفضيلات الفئة + المفتاح الشامل) وميزانية التذكيرات.
 *
 * ثوابت حاكمة (نفس فلسفة ReEngagementService/LifecycleReminderService):
 *  - **قراءة فقط من حالة اللعب**: لا تُنشئ UserQuestProgress ولا تستدعي syncCurrentQuests ولا تعدّل سلسلة ولا تمنح شيئًا؛ تكتب إشعارات فقط.
 *  - Idempotent بمفتاح دلالي لكل (مستخدم + موضوع/فترة): تشغيل الأمر 10 مرات = إشعار واحد.
 *  - لا User::all(): جمهور كل نوع مبنيّ من سجلات الحدث/النشاط نفسها بدفعات (chunk/chunkById) ثم تحميل مستخدمي الدفعة باستعلام واحد.
 *  - لا إزعاج: سقف يومي خاص بـLiveOps (config) **و**ميزانية E15 (max_reengagement_per_day) كلاهما يُحترَم؛ والأنواع الأربعة ليست "تذكيرات استعادية" إلحاحية.
 *  - الصحة لا تعتمد عليه: غياب المجدوِل = لا إشعارات فقط. فشل إشعار واحد لا يوقف بقية المستخدمين.
 *  - الافتراضيات متحفظة: العودة والأسبوعية متوقفتان؛ انتهاء المهمة وبدء الحدث مفعّلان (config/liveops.php).
 */
class LiveOpsReminderService
{
    public function __construct(
        protected NotificationDispatcher $dispatcher,
        protected ReEngagementPolicy $policy,
        protected QuestPeriodService $periods,
        protected PlatformSettingsService $settings,
        protected ComebackService $comeback,
    ) {}

    /** @return array<string, array<string, mixed>> */
    public function run(): array
    {
        return [
            'mission_expiring' => $this->missionExpiring(),
            'weekly_missions' => $this->weeklyMissions(),
            'event_starting' => $this->eventStarting(),
            'comeback' => $this->comeback(),
        ];
    }

    /** @return array<string, int|string> */
    protected function stats(): array
    {
        return ['candidates' => 0, 'ineligible' => 0, 'already' => 0, 'over_budget' => 0, 'created' => 0, 'suppressed' => 0, 'failed' => 0];
    }

    protected function enabled(string $flag): bool
    {
        return (bool) config("liveops.notifications.{$flag}", false) && (bool) $this->settings->get('notifications', 'notifications_enabled', true);
    }

    protected function chunk(): int
    {
        return max(1, (int) config('liveops.notifications.chunk_size', 200));
    }

    // ───────────────────────── 1) مهام جارية تنتهي مع نهاية اليوم ─────────────────────────

    /**
     * مستخدمون لديهم مهمة يومية **بدأوها فعلًا** (تقدّم > 0) ولم يكملوها، في الساعات الأخيرة من اليوم المحلي. لا إشعار لمن لم يبدأ شيئًا.
     *
     * @return array<string, int|string>
     */
    public function missionExpiring(): array
    {
        $stats = $this->stats();

        if (! $this->enabled('mission_expiring')) {
            return $stats + ['skipped' => 'disabled'];
        }

        $ctx = $this->periods->dailyContext();
        $hours = max(1, (int) config('liveops.notifications.mission_expiring_hours_before_end', 4));

        if (now()->diffInSeconds($ctx->end, false) > $hours * 3600) {
            return $stats + ['skipped' => 'before_window'];
        }

        $now = now();

        UserQuestProgress::query()
            ->join('quest_definitions as q', 'q.id', '=', 'user_quest_progress.quest_definition_id')
            ->where('user_quest_progress.period_key', $ctx->periodKey)
            ->whereNull('user_quest_progress.completed_at')
            ->where('user_quest_progress.current_value', '>', 0)
            ->where('q.is_active', true)->where('q.period_type', QuestDefinition::PERIOD_DAILY)
            ->where(fn ($w) => $w->whereNull('q.ends_at')->orWhere('q.ends_at', '>', $now))
            ->selectRaw('user_quest_progress.user_id as user_id, count(*) as in_progress')
            ->groupBy('user_quest_progress.user_id')
            ->orderBy('user_id')
            ->chunk($this->chunk(), function ($rows) use (&$stats, $ctx) {
                $counts = $rows->pluck('in_progress', 'user_id')->map(fn ($n) => (int) $n)->all();

                $this->deliver(array_keys($counts), $stats, NotificationType::MissionExpiring,
                    fn (User $u) => "mission-expiring:{$u->id}:{$ctx->periodKey}",
                    fn (User $u) => ['count' => $counts[$u->id] ?? 1],
                );
            });

        return $stats;
    }

    // ───────────────────────── 2) أهداف الأسبوع الجديدة (الاثنين، لنشطي اللعب مؤخرًا) ─────────────────────────

    /** @return array<string, int|string> */
    public function weeklyMissions(): array
    {
        $stats = $this->stats();

        if (! $this->enabled('weekly_missions_available')) {
            return $stats + ['skipped' => 'disabled'];
        }

        $local = now()->setTimezone($this->periods->timezone());

        if ($local->dayOfWeekIso !== 1) {
            return $stats + ['skipped' => 'not_week_start'];
        }

        if (! QuestDefinition::query()->where('is_active', true)->where('period_type', QuestDefinition::PERIOD_WEEKLY)->exists()) {
            return $stats + ['skipped' => 'no_weekly_missions'];
        }

        $weekKey = $this->periods->weeklyContext()->periodKey;
        $since = Carbon::parse($this->periods->todayLocal())->subDays(max(1, (int) config('player_notifications.recent_activity_days', 7)))->toDateString();

        PlayerStreak::query()->where('last_active_date', '>=', $since)->select('id', 'user_id')
            ->chunkById($this->chunk(), function ($rows) use (&$stats, $weekKey) {
                $this->deliver($rows->pluck('user_id')->all(), $stats, NotificationType::WeeklyMissionsAvailable,
                    fn (User $u) => "weekly-missions:{$u->id}:{$weekKey}",
                    fn (User $u) => [],
                );
            });

        return $stats;
    }

    // ───────────────────────── 3) بدء منافسة قريبًا (للمسجَّلين فقط) ─────────────────────────

    /** @return array<string, int|string> */
    public function eventStarting(): array
    {
        $stats = $this->stats();

        if (! $this->enabled('event_starting_soon')) {
            return $stats + ['skipped' => 'disabled'];
        }

        $now = now();
        $window = max(1, (int) config('liveops.notifications.event_starting_window_hours', 24));

        CompetitiveEvent::query()
            ->where('status', CompetitiveEvent::STATUS_PUBLISHED)
            ->where('starts_at', '>', $now)->where('starts_at', '<=', $now->copy()->addHours($window))
            ->orderBy('id')
            ->chunkById(50, function ($events) use (&$stats, $now) {
                foreach ($events as $event) {
                    $hours = max(1, (int) ceil($now->diffInMinutes($event->starts_at, false) / 60));

                    CompetitiveEventParticipant::query()
                        ->where('competitive_event_id', $event->id)->where('status', 'registered')
                        ->select('id', 'user_id')
                        ->chunkById($this->chunk(), function ($rows) use (&$stats, $event, $hours) {
                            $this->deliver($rows->pluck('user_id')->all(), $stats, NotificationType::EventStartingSoon,
                                fn (User $u) => "event-starting-soon:{$u->id}:{$event->id}",
                                fn (User $u) => ['title' => $event->title, 'hours' => $hours],
                                ['competitive_event_id' => $event->id], ['event' => $event->slug], 'competitions.show',
                            );
                        });
                }
            });

        return $stats;
    }

    // ───────────────────────── 4) العودة بعد غياب (مرة لكل فترة غياب) ─────────────────────────

    /** @return array<string, int|string> */
    public function comeback(): array
    {
        $stats = $this->stats();

        if (! $this->enabled('comeback_available')) {
            return $stats + ['skipped' => 'disabled'];
        }

        $today = Carbon::parse($this->periods->todayLocal());
        $newest = $today->copy()->subDays(ComebackService::minInactiveDays())->toDateString();
        $oldest = $today->copy()->subDays(max(ComebackService::minInactiveDays(), (int) config('liveops.comeback.notify_max_days', 45)))->toDateString();

        PlayerStreak::query()
            ->whereNotNull('last_active_date')->where('last_active_date', '<=', $newest)->where('last_active_date', '>=', $oldest)
            ->select('id', 'user_id', 'last_active_date')
            ->chunkById($this->chunk(), function ($rows) use (&$stats) {
                $dates = $rows->mapWithKeys(fn ($r) => [(int) $r->user_id => Carbon::parse($r->last_active_date)->toDateString()])->all();

                $this->deliver(array_keys($dates), $stats, NotificationType::ComebackAvailable,
                    fn (User $u) => "comeback:{$u->id}:{$dates[$u->id]}",
                    fn (User $u) => [],
                );
            });

        return $stats;
    }

    // ───────────────────────── الإرسال المشترك (ميزانية + تكرار + عزل أخطاء) ─────────────────────────

    /**
     * @param  list<int|string>  $userIds
     * @param  array<string, int|string>  $stats
     * @param  \Closure(User): string  $key
     * @param  \Closure(User): array<string, mixed>  $params
     * @param  array<string, scalar>  $refs
     * @param  array<string, scalar>  $actionParams
     */
    protected function deliver(array $userIds, array &$stats, NotificationType $type, \Closure $key, \Closure $params, array $refs = [], array $actionParams = [], ?string $route = null): void
    {
        $ids = array_values(array_unique(array_map('intval', $userIds)));

        if ($ids === []) {
            return;
        }

        $users = User::query()->whereIn('id', $ids)->where('is_frozen', false)->whereNotNull('email_verified_at')->get();
        $stats['candidates'] += count($ids);
        $stats['ineligible'] += count($ids) - $users->count();

        if ($users->isEmpty()) {
            return;
        }

        $keys = $users->mapWithKeys(fn (User $u) => [$u->id => $key($u)])->all();

        $sent = array_flip(DatabaseNotification::query()
            ->where('notifiable_type', (new User)->getMorphClass())
            ->where('type_key', $type->value)
            ->whereIn('idempotency_key', array_values($keys))
            ->pluck('notifiable_id')->all());

        $e15 = $this->policy->usedToday($users->pluck('id')->all());
        $liveOps = $this->usedTodayLiveOps($users->pluck('id')->all());
        $maxE15 = $this->policy->maxPerDay();
        $cap = max(0, (int) config('liveops.notifications.daily_cap', 1));

        foreach ($users as $user) {
            if (isset($sent[$user->id])) {
                $stats['already']++;
            } elseif (($e15[$user->id] ?? 0) >= $maxE15 || ($liveOps[$user->id] ?? 0) >= $cap) {
                $stats['over_budget']++;
            } else {
                $this->send($user, $type, $params($user), $keys[$user->id], $refs, $actionParams, $route, $stats);
            }
        }
    }

    /** @param  array<string, int|string>  $stats */
    protected function send(User $user, NotificationType $type, array $params, string $key, array $refs, array $actionParams, ?string $route, array &$stats): void
    {
        try {
            match ($this->dispatcher->dispatch($user, $type, $params, $key, $refs, $actionParams, $route)) {
                DispatchResult::Created => $stats['created']++,
                DispatchResult::Suppressed => $stats['suppressed']++,
                DispatchResult::Duplicate => $stats['already']++,
                DispatchResult::Failed => $stats['failed']++,
            };
        } catch (\Throwable $e) {
            report($e); // فشل إشعار واحد لا يمسّ أي شيء آخر ولا يوقف بقية المستخدمين.
            $stats['failed']++;
        }
    }

    /** @return list<string> */
    public static function typeKeys(): array
    {
        return [
            NotificationType::MissionExpiring->value, NotificationType::WeeklyMissionsAvailable->value,
            NotificationType::EventStartingSoon->value, NotificationType::ComebackAvailable->value,
        ];
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, int> عدد إشعارات LiveOps المُنشأة اليوم المحلي لكل مستخدم (استعلام واحد للدفعة).
     */
    public function usedTodayLiveOps(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        [$start, $end] = $this->policy->dayBounds();

        return DatabaseNotification::query()
            ->where('notifiable_type', (new User)->getMorphClass())
            ->whereIn('notifiable_id', $userIds)
            ->whereIn('type_key', self::typeKeys())
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('notifiable_id, count(*) as used')
            ->groupBy('notifiable_id')
            ->pluck('used', 'notifiable_id')
            ->map(fn ($n) => (int) $n)
            ->all();
    }
}
