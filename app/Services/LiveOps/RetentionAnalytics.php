<?php

namespace App\Services\LiveOps;

use App\Models\CompetitiveEventParticipant;
use App\Models\PlayerStreak;
use App\Models\PuzzleAttempt;
use App\Models\User;
use App\Models\UserCampaignProgress;
use App\Models\UserQuestProgress;
use App\Services\Engagement\QuestPeriodService;
use App\GameEngine\Support\AttemptContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * E34 - تحليلات الاحتفاظ **مُجمَّعة فقط** (لا صفوف مستخدمين، لا أسماء، لا SDK خارجي، لا تتبّع جهاز). مبنية على سجلات اللعب الموجودة.
 *
 * "لعب ذو معنى" = محاولة أحجية **رسمية صحيحة** (puzzle_attempts.is_correct = 1) - نفس المصدر الموثوق للسلسلة؛ تسجيل الدخول وحده لا يُحسب أبدًا،
 * وأحجيات المجتمع/سلاسل المبدعين خارج الجدول فلا تدخل. اليوم = التاريخ المحلي للمنصة (QuestPeriodService؛ UTC افتراضيًا).
 *
 * الصيغ (وثّقها docs/liveops-retention-e34.md):
 *  - mission_completion[daily|weekly]: completed / total لصفوف user_quest_progress لهذا النوع التي period_start >= بداية النافذة.
 *    تنبيه: الصف يُنشأ كسولًا عند فتح مركز المهام أو حدث لعب، فالمقام = "معرَّضون للمهمة" لا "كل المسجَّلين".
 *  - participation: عدد المستخدمين المميَّزين الذين لهم تقدّم > 0 في كل فترة (period_key)؛ متوسط الأيام + الأمس/الأسبوع الأخير.
 *  - streak_distribution: أعلى سلاسل *فعّالة* فقط (last_active_date >= أمس)؛ current_streak المخزَّن لا يتناقص بالوقت فلا يُقرأ خامًا.
 *  - event_participation: مسجَّلو المنافسات الرسمية بالنافذة (registered_at) + مستخدمو الحملات المميَّزون (إكمال خطوة سردية/تأمل أو حل صحيح بسياق حملة).
 *  - returners: مستخدمون لعبوا ضمن النافذة وكان آخر لعب لهم *قبل أول لعبة بالنافذة* قبل N يومًا فأكثر (N = liveops.comeback.min_inactive_days).
 *  - retention D{1,7,30}: لفوج التسجيل (users.created_at بتاريخ محلي d) المنضج فقط (d + N <= اليوم)، النسبة = من لعب لعبًا ذا معنى في اليوم d+N **بالضبط** / حجم الفوج
 *    (يشمل من لم يلعب قط في المقام). فوج أصغر من cohort_min_size => null (غير كافٍ) لا نسبة مضلِّلة.
 * الكاش قصير (analytics.cache_seconds) ومشترك لأنه بلا بيانات مستخدمين.
 */
class RetentionAnalytics
{
    public function __construct(protected QuestPeriodService $periods) {}

    /** @return array<string, mixed> */
    public function overview(?int $days = null): array
    {
        $days = max(1, min(180, $days ?? (int) config('liveops.analytics.window_days', 30)));
        $seconds = max(0, (int) config('liveops.analytics.cache_seconds', 300));
        $build = fn () => $this->build($days);

        return $seconds === 0 ? $build() : Cache::remember("liveops:analytics:v1:{$days}", $seconds, $build);
    }

    public static function flush(): void
    {
        foreach ([7, 14, 30, 60, 90, 180] as $d) {
            Cache::forget("liveops:analytics:v1:{$d}");
        }

        Cache::forget('liveops:analytics:v1:'.(int) config('liveops.analytics.window_days', 30));
    }

    /** @return array<string, mixed> */
    protected function build(int $days): array
    {
        $today = Carbon::parse($this->periods->todayLocal())->startOfDay();
        $since = $today->copy()->subDays($days);

        return [
            'window_days' => $days,
            'generated_for' => $today->toDateString(),
            'mission_completion' => [
                'daily' => $this->completion('daily', $since),
                'weekly' => $this->completion('weekly', $since),
            ],
            'participation' => [
                'daily' => $this->participation('daily', $since),
                'weekly' => $this->participation('weekly', $since),
            ],
            'streak_distribution' => $this->streakDistribution($today),
            'event_participation' => $this->eventParticipation($since),
            'returners' => $this->returners($since),
            'retention' => $this->retention($today),
        ];
    }

    /** @return array{total:int, completed:int, rate:?float} */
    protected function completion(string $type, Carbon $since): array
    {
        $base = UserQuestProgress::query()->where('period_type', $type)->where('period_start', '>=', $since);
        $total = (clone $base)->count();
        $completed = (clone $base)->whereNotNull('completed_at')->count();

        return ['total' => $total, 'completed' => $completed, 'rate' => $total > 0 ? round($completed / $total, 4) : null];
    }

    /** @return array{periods:int, average:?float, latest:int} */
    protected function participation(string $type, Carbon $since): array
    {
        $rows = UserQuestProgress::query()
            ->where('period_type', $type)->where('period_start', '>=', $since)->where('current_value', '>', 0)
            ->selectRaw('period_key, count(distinct user_id) as users')
            ->groupBy('period_key')->orderBy('period_key')
            ->pluck('users', 'period_key');

        // الفترة الجارية ناقصة بطبيعتها؛ "الأحدث" = آخر فترة مكتملة إن وُجدت وإلا الجارية.
        $currentKey = $this->periods->contextFor($type)->periodKey;
        $closed = $rows->except($currentKey);

        return [
            'periods' => $rows->count(),
            'average' => $rows->isEmpty() ? null : round($rows->avg(), 2),
            'latest' => (int) ($closed->isNotEmpty() ? $closed->last() : ($rows->get($currentKey) ?? 0)),
        ];
    }

    /** @return array<string, int> */
    protected function streakDistribution(Carbon $today): array
    {
        $yesterday = $today->copy()->subDay()->toDateString();
        $thresholds = app(StreakMilestoneService::class)->thresholds();
        $active = PlayerStreak::query()->where('last_active_date', '>=', $yesterday);
        $out = [];
        $activeTotal = 0;

        // حدود الأقسام: 1..(m1-1) ثم كل معلَم حتى (التالي-1) وآخرها مفتوح. المحتوى بحسب المعالم المضبوطة (لا أرقام ثابتة).
        $edges = array_values(array_unique(array_merge([1], $thresholds)));
        sort($edges);

        foreach ($edges as $i => $from) {
            $to = $edges[$i + 1] ?? null;
            $q = (clone $active)->where('current_streak', '>=', $from);

            if ($to !== null) {
                $q->where('current_streak', '<', $to);
            }

            $n = $q->count();
            $activeTotal += $n;
            $out[$to === null ? "{$from}+" : ($to - $from === 1 ? (string) $from : "{$from}-".($to - 1))] = $n;
        }

        return ['none_or_broken' => max(0, PlayerStreak::query()->count() - $activeTotal)] + $out;
    }

    /** @return array{competitive_registrations:int, competitive_users:int, campaign_users:int} */
    protected function eventParticipation(Carbon $since): array
    {
        $reg = CompetitiveEventParticipant::query()->where('registered_at', '>=', $since);

        $narrative = UserCampaignProgress::query()->where('completed_at', '>=', $since)->pluck('user_id');
        $puzzle = PuzzleAttempt::query()
            ->where('is_correct', true)->where('context_type', AttemptContext::TYPE_CAMPAIGN_STEP)->where('created_at', '>=', $since)
            ->distinct()->pluck('user_id');

        return [
            'competitive_registrations' => (clone $reg)->count(),
            'competitive_users' => (clone $reg)->distinct()->count('user_id'),
            'campaign_users' => $narrative->merge($puzzle)->unique()->count(),
        ];
    }

    /** @return array{min_inactive_days:int, returners:int, active_in_window:int, rate:?float} */
    protected function returners(Carbon $since): array
    {
        $n = ComebackService::minInactiveDays();
        $first = PuzzleAttempt::query()->where('is_correct', true)->where('created_at', '>=', $since)
            ->selectRaw('user_id, min(created_at) as first_in')->groupBy('user_id')->get();

        if ($first->isEmpty()) {
            return ['min_inactive_days' => $n, 'returners' => 0, 'active_in_window' => 0, 'rate' => null];
        }

        $returners = 0;

        foreach ($first->chunk(500) as $chunk) {
            $prev = PuzzleAttempt::query()->where('is_correct', true)->where('created_at', '<', $since)
                ->whereIn('user_id', $chunk->pluck('user_id'))
                ->selectRaw('user_id, max(created_at) as last_before')->groupBy('user_id')
                ->pluck('last_before', 'user_id');

            foreach ($chunk as $row) {
                $last = $prev->get($row->user_id);

                if ($last !== null && Carbon::parse($last)->startOfDay()->diffInDays(Carbon::parse($row->first_in)->startOfDay()) >= $n) {
                    $returners++;
                }
            }
        }

        return ['min_inactive_days' => $n, 'returners' => $returners, 'active_in_window' => $first->count(), 'rate' => round($returners / $first->count(), 4)];
    }

    /**
     * D1/D7/D30 بصيغة اليوم-المحدّد (لا "في أي وقت بعد"). فوج منضج فقط، بحدّ أعلى لحجم الفوج المقروء (حماية الذاكرة).
     *
     * @return array<string, array{cohort:int, retained:int, rate:?float}>
     */
    protected function retention(Carbon $today): array
    {
        $out = [];
        $window = max(1, (int) config('liveops.analytics.window_days', 30));
        $min = max(1, (int) config('liveops.analytics.cohort_min_size', 5));
        $cap = 20000;

        foreach ([1, 7, 30] as $n) {
            // أيام تسجيل منضجة: من (اليوم - النافذة - N) حتى (اليوم - N) شاملين.
            $from = $today->copy()->subDays($window + $n);
            $to = $today->copy()->subDays($n)->endOfDay();

            $users = User::query()->where('created_at', '>=', $from)->where('created_at', '<=', $to)
                ->orderBy('id')->limit($cap)->get(['id', 'created_at']);

            $cohort = $users->count();
            $retained = 0;

            foreach ($users->chunk(1000) as $chunk) {
                $dates = PuzzleAttempt::query()->where('is_correct', true)
                    ->whereIn('user_id', $chunk->pluck('id'))->where('created_at', '>=', $from)
                    ->get(['user_id', 'created_at'])
                    ->groupBy('user_id')
                    ->map(fn ($rows) => $rows->map(fn ($r) => $this->periods->localDateFor(Carbon::parse($r->created_at)))->flip());

                foreach ($chunk as $u) {
                    $target = Carbon::parse($this->periods->localDateFor($u->created_at))->addDays($n)->toDateString();

                    if (isset(($dates->get($u->id) ?? collect())[$target])) {
                        $retained++;
                    }
                }
            }

            $out["d{$n}"] = ['cohort' => $cohort, 'retained' => $retained, 'rate' => $cohort >= $min ? round($retained / $cohort, 4) : null];
        }

        return $out;
    }
}
