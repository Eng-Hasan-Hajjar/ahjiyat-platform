<?php

namespace App\Services\LiveOps;

use App\Models\Campaign;
use App\Models\PlayerStreak;
use App\Models\Puzzle;
use App\Models\PuzzleAttempt;
use App\Models\Season;
use App\Models\User;
use App\Models\UserCampaignProgress;
use App\Services\Engagement\QuestPeriodService;
use Illuminate\Support\Carbon;

/**
 * E34 - رحلة العودة (Comeback). مشتقة بالكامل من **اللعب الرسمي الموثوق** (آخر يوم نشاط مؤهِّل بـPlayerStreak.last_active_date)
 * لا من تسجيل الدخول: فتح الموقع وحده لا يُنهي الحالة ولا يُنشئها.
 *
 * لا حالة مخزَّنة ولا جدول: تزول الحالة تلقائيًا عند أول حل رسمي صحيح (StreakService يحدّث last_active_date)،
 * فلا تظهر بعد استئناف فعلي. الافتراضي: **لا مكافأة جواهر للعودة** (لا مفتاح لها في config).
 * لا عقوبة ولا لغة ضغط: رسالة ترحيب ومقترحات سهلة فقط.
 */
class ComebackService
{
    public function __construct(protected QuestPeriodService $periods) {}

    public static function minInactiveDays(): int
    {
        return max(1, (int) config('liveops.comeback.min_inactive_days', 7));
    }

    /** غياب ≥ $minDays أيام عن آخر يوم لعب رسمي (بالتاريخ المحلي الموحَّد للمنصة). بلا تاريخ لعب = لاعب جديد لا عائد. */
    public static function isInactiveFor(?string $lastActiveDate, int $minDays): bool
    {
        $days = self::daysSince($lastActiveDate);

        return $days !== null && $days >= max(1, $minDays);
    }

    public static function daysSince(?string $lastActiveDate): ?int
    {
        if ($lastActiveDate === null || $lastActiveDate === '') {
            return null;
        }

        $today = Carbon::parse(app(QuestPeriodService::class)->todayLocal())->startOfDay();
        $last = Carbon::parse($lastActiveDate)->startOfDay();

        return max(0, (int) $last->diffInDays($today));
    }

    /**
     * آخر تاريخ لعب رسمي صحيح (Y-m-d). المصدر المعتمد PlayerStreak؛ وللحسابات القديمة بلا صف سلسلة نرجع لآخر محاولة صحيحة.
     */
    public function lastQualifyingDate(User $user): ?string
    {
        $date = PlayerStreak::query()->where('user_id', $user->id)->value('last_active_date');

        if ($date !== null) {
            return Carbon::parse($date)->toDateString();
        }

        $last = PuzzleAttempt::query()->where('user_id', $user->id)->where('is_correct', true)->max('created_at');

        return $last !== null ? $this->periods->localDateFor(Carbon::parse($last)) : null;
    }

    public function inactiveDays(User $user): ?int
    {
        return self::daysSince($this->lastQualifyingDate($user));
    }

    public function isEligible(User $user): bool
    {
        return self::isInactiveFor($this->lastQualifyingDate($user), self::minInactiveDays());
    }

    /**
     * @return array{last_active_date:string, inactive_days:int, suggestions:list<array{kind:string,title:string,url:string}>}|null
     */
    public function stateFor(User $user): ?array
    {
        $last = $this->lastQualifyingDate($user);

        if (! self::isInactiveFor($last, self::minInactiveDays())) {
            return null;
        }

        return [
            'last_active_date' => $last,
            'inactive_days' => (int) self::daysSince($last),
            'suggestions' => $this->suggestions($user),
        ];
    }

    /**
     * مقترحات سهلة ومحدودة (≤ 3). المهمة المخصَّصة تضيفها طبقة مركز المهام (فهي من يعرف إسناد المستخدم).
     *
     * @return list<array{kind:string,title:string,url:string}>
     */
    public function suggestions(User $user): array
    {
        $out = [];

        $puzzle = $this->easyOfficialPuzzle($user);

        if ($puzzle !== null) {
            $out[] = ['kind' => 'puzzle', 'title' => 'أحجية سهلة للبدء: '.$puzzle->title, 'url' => route('puzzles.show', $puzzle)];
        }

        if (UserCampaignProgress::query()->where('user_id', $user->id)->exists() && Campaign::query()->where('is_active', true)->exists()) {
            $out[] = ['kind' => 'campaign', 'title' => 'أكمل حملتك من حيث توقفت', 'url' => route('campaigns.index')];
        } elseif (Season::query()->publiclyVisible()->exists()) {
            $out[] = ['kind' => 'season', 'title' => 'اطّلع على الموسم الحالي', 'url' => route('seasons.index')];
        }

        return $out;
    }

    /**
     * أحجية رسمية سهلة مفعّلة لم يحلّها المستخدم. اختيار حتمي بحسب (مستخدم، اليوم): لا random() ويثبت طوال اليوم.
     * رسمية فقط: لا أحجيات مجتمع (صفرية الاقتصاد ولا تُحسب مهامًا).
     */
    protected function easyOfficialPuzzle(User $user): ?Puzzle
    {
        $ids = Puzzle::query()->official()
            ->where('is_active', true)
            ->where('difficulty', 'easy')
            ->whereNotExists(function ($q) use ($user) {
                $q->selectRaw('1')->from('puzzle_attempts')
                    ->whereColumn('puzzle_attempts.puzzle_id', 'puzzles.id')
                    ->where('puzzle_attempts.user_id', $user->id)
                    ->where('puzzle_attempts.is_correct', true);
            })
            ->orderBy('id')
            ->limit(25)
            ->pluck('id')->all();

        if ($ids === []) {
            return null;
        }

        usort($ids, fn (int $a, int $b) => hexdec(substr(sha1($user->id.'|'.$this->periods->todayLocal().'|'.$a), 0, 12)) <=> hexdec(substr(sha1($user->id.'|'.$this->periods->todayLocal().'|'.$b), 0, 12)));

        return Puzzle::query()->find($ids[0]);
    }
}
