<?php

namespace App\Services\LiveOps;

use App\Models\QuestDefinition;
use App\Models\User;
use App\Models\UserAchievementProgress;
use App\Models\UserQuestProgress;
use App\Services\Engagement\PeriodContext;
use App\Services\Engagement\QuestPeriodService;
use App\Services\Engagement\QuestService;
use App\Services\Engagement\StreakService;
use App\Services\LiveOps\Calendar\EventCalendarService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * E34 - مركز المهام: طبقة **عرض مُجمَّعة** فوق خدمات موجودة (QuestService/StreakService/Achievements/EventCalendar). لا تحسب تقدّمًا ولا تمنح
 * ولا تعدّل سلسلة: كل ما تفعله جمع بيانات للقراءة بعدد استعلامات ثابت (لا N+1) وتنسيقها للواجهة.
 *
 *  - الحالات: in_progress / completed (مكتملة، المكافأة قيد المعالجة) / reward_granted. لا "طالب بالمكافأة" يدويًا: المنح تلقائي عبر QuestService.
 *  - الوقت من خدمة فترات المنصة نفسها (QuestPeriodService)؛ المتبقي حقيقي (نهاية الفترة أو نهاية نافذة المهمة الأقرب) بلا لغة ضغط.
 *  - widgetFor() للوحة الرئيسية: قراءة فقط بالكامل (لا مزامنة ولا إنشاء صفوف تقدّم).
 */
class MissionCenterService
{
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_REWARD_GRANTED = 'reward_granted';

    public function __construct(
        protected QuestService $quests,
        protected QuestPeriodService $periods,
        protected StreakService $streaks,
        protected StreakMilestoneService $milestones,
        protected EventCalendarService $calendar,
        protected ComebackService $comeback,
    ) {}

    /**
     * صفحة المهام الكاملة. $sync=true يُبقي سلوك E13 (شفاء ذاتي من بيانات اللعب الحقيقية عند الفتح الفعلي) ويُنشئ صفوف تقدّم للمهام الظاهرة فقط.
     *
     * @return array<string, mixed>
     */
    public function forUser(User $user, bool $sync = true): array
    {
        if ($sync) {
            $this->quests->syncCurrentQuests($user);
        }

        $daily = $this->rowsFor($user, QuestDefinition::PERIOD_DAILY, $sync);
        $weekly = $this->rowsFor($user, QuestDefinition::PERIOD_WEEKLY, $sync);
        $streak = $this->streaks->streakFor($user);
        $sections = $this->calendar->sections();
        $agenda = $this->calendar->agendaFor($user);
        $comeback = $this->comeback->stateFor($user);

        if ($comeback !== null) {
            $first = $daily->concat($weekly)->first(fn (array $r) => $r['status'] === self::STATUS_IN_PROGRESS);

            if ($first !== null) {
                $comeback['suggestions'][] = ['kind' => 'mission', 'title' => 'مهمة خفيفة: '.$first['quest']->name, 'url' => route('missions.index')];
            }
        }

        return [
            'daily' => $daily,
            'weekly' => $weekly,
            'streak' => $streak,
            'milestones' => $this->milestones->statusFor($user),
            'nearAchievements' => $this->nearAchievements($user),
            'agenda' => $agenda,
            'events' => $sections['now']->concat($sections['today'])->concat($sections['week'])->take(4)->values(),
            'comeback' => $comeback,
            'timezone' => $this->periods->timezone(),
        ];
    }

    /**
     * عنصر اللوحة الرئيسية: 2–3 مهام أولوية + السلسلة + أقرب حدث. قراءة فقط (الإسناد الكسول لهذا المستخدم فقط مسموح؛ لا صفوف تقدّم).
     *
     * @return array{missions: Collection<int, array<string,mixed>>, streak: int, next_event: ?\App\Services\LiveOps\Calendar\EventItem, total: int, completed: int}
     */
    public function widgetFor(User $user, ?\App\Models\PlayerStreak $streak = null): array
    {
        $daily = $this->rowsFor($user, QuestDefinition::PERIOD_DAILY, false);
        $weekly = $this->rowsFor($user, QuestDefinition::PERIOD_WEEKLY, false);
        $all = $daily->concat($weekly);

        $priority = $all->filter(fn (array $r) => $r['status'] === self::STATUS_IN_PROGRESS)
            ->sortByDesc('percent')->take(3)->values();

        $next = $this->calendar->items()->first();

        return [
            'missions' => $priority,
            'streak' => StreakMilestoneService::effectiveCurrent($streak ?? $this->streaks->streakFor($user)),
            'next_event' => $next,
            'total' => $daily->count(),
            'completed' => $daily->where('status', '!=', self::STATUS_IN_PROGRESS)->count(),
        ];
    }

    /**
     * صفوف المهام لنوع فترة: استعلام تعريفات + تقدّم + إسناد ثابت العدد.
     *
     * @return Collection<int, array<string,mixed>>
     */
    public function rowsFor(User $user, string $periodType, bool $materialize): Collection
    {
        $period = $this->periods->contextFor($periodType);
        $quests = $this->quests->visibleQuestsFor($user, $periodType, $period);

        if ($quests->isEmpty()) {
            return collect();
        }

        $progress = UserQuestProgress::query()
            ->where('user_id', $user->id)->where('period_key', $period->periodKey)
            ->whereIn('quest_definition_id', $quests->pluck('id'))
            ->get()->keyBy('quest_definition_id');

        $now = now();

        return $quests->map(function (QuestDefinition $quest) use ($user, $period, $progress, $materialize, $now) {
            $row = $progress->get($quest->id);

            if ($row === null && $materialize) {
                $row = $this->quests->progressFor($user, $quest, $period);
            }

            // بلا صف (قراءة فقط): تقدّم افتراضي صفري غير محفوظ - لا يُكتب شيء.
            $row ??= new UserQuestProgress([
                'user_id' => $user->id, 'quest_definition_id' => $quest->id, 'period_type' => $period->periodType, 'period_key' => $period->periodKey,
                'current_value' => 0, 'target_value_snapshot' => $quest->target_value,
            ]);

            return $this->row($quest, $row, $period, $now);
        })->values();
    }

    /**
     * @return array<string, mixed>
     */
    protected function row(QuestDefinition $quest, UserQuestProgress $progress, PeriodContext $period, Carbon $now): array
    {
        $target = max(0, (int) $progress->target_value_snapshot);
        $percent = $target > 0 ? min(100, (int) round(($progress->current_value / $target) * 100)) : 0;

        $status = match (true) {
            $progress->completed_at === null => self::STATUS_IN_PROGRESS,
            $progress->reward_granted_at === null => self::STATUS_COMPLETED,
            default => self::STATUS_REWARD_GRANTED,
        };

        $end = $period->end;

        if ($quest->ends_at !== null && $quest->ends_at->lt($end)) {
            $end = $quest->ends_at;
        }

        return [
            'quest' => $quest,
            'progress' => $progress,
            'percent' => $percent,
            'status' => $status,
            'remaining' => $status === self::STATUS_IN_PROGRESS ? self::formatRemaining($now, $end) : null,
        ];
    }

    /** وقت متبقٍ حقيقي بصيغة محايدة (أيام/ساعات/دقائق). null إن انقضى. */
    public static function formatRemaining(Carbon $now, \DateTimeInterface $end): ?string
    {
        $seconds = (int) $now->diffInSeconds(Carbon::instance($end), false);

        if ($seconds <= 0) {
            return null;
        }

        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return match (true) {
            $days > 0 => "{$days} يوم و{$hours} ساعة",
            $hours > 0 => "{$hours} ساعة و{$minutes} دقيقة",
            default => max(1, $minutes).' دقيقة',
        };
    }

    /**
     * إنجازات قاربت الاكتمال (≥ 50%): استعلام واحد محدود، بلا إنجازات مخفية. تحفيز بلا ضغط؛ المنح من نظام الإنجازات.
     *
     * @return Collection<int, array{name:string, current:int, target:int, percent:int}>
     */
    public function nearAchievements(User $user, int $limit = 3): Collection
    {
        return UserAchievementProgress::query()
            ->join('achievements', 'achievements.id', '=', 'user_achievement_progress.achievement_id')
            ->where('user_achievement_progress.user_id', $user->id)
            ->whereNull('user_achievement_progress.unlocked_at')
            ->where('achievements.is_active', true)->where('achievements.is_hidden', false)
            ->where('user_achievement_progress.current_value', '>', 0)
            ->whereColumn('user_achievement_progress.current_value', '<', 'achievements.target_value')
            ->orderByDesc('user_achievement_progress.current_value')
            ->limit(25)
            ->get(['achievements.name', 'achievements.target_value', 'user_achievement_progress.current_value'])
            ->map(fn ($r) => [
                'name' => (string) $r->name,
                'current' => (int) $r->current_value,
                'target' => (int) $r->target_value,
                'percent' => (int) floor(((int) $r->current_value / max(1, (int) $r->target_value)) * 100),
            ])
            ->filter(fn (array $a) => $a['percent'] >= 50)
            ->sortByDesc('percent')
            ->take($limit)
            ->values();
    }
}
