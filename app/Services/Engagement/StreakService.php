<?php

namespace App\Services\Engagement;

use App\Models\PlayerStreak;
use App\Models\PuzzleAttempt;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * E13 (بند 65-90، 155-160): الحدث المؤهِّل الوحيد = حل أحجية صحيح فعليًا.
 * PlayerStreak ملخَّص مُجسَّد فقط - PuzzleAttempt الصحيحة هي مصدر الحقيقة
 * التاريخي، قابل لإعادة البناء الكامل عبر recalculateFromHistory().
 * كسر السلسلة لا يسحب أي شيء سبق اكتسابه - Idempotent يوميًا بالتصميم.
 */
class StreakService
{
    public function __construct(protected QuestPeriodService $periods) {}

    public function streakFor(User $user): PlayerStreak
    {
        return PlayerStreak::firstOrCreate(['user_id' => $user->id]);
    }

    /** يُستدعى بعد حل أحجية صحيح فعليًا فقط - لا تسجيل دخول، لا مشاهدة صفحة، لا شراء. */
    public function recordQualifyingActivity(User $user, Carbon $occurredAt): void
    {
        $today = $this->periods->localDateFor($occurredAt);

        DB::transaction(function () use ($user, $today, $occurredAt) {
            $streak = PlayerStreak::query()->where('user_id', $user->id)->lockForUpdate()->first();

            if ($streak === null) {
                $streak = PlayerStreak::create(['user_id' => $user->id, 'current_streak' => 0, 'longest_streak' => 0]);
            }

            $lastActive = $streak->last_active_date?->format('Y-m-d');

            // بند 66/166: نفس اليوم = لا تغيير إطلاقًا - Idempotent (10 حلول بنفس اليوم = زيادة واحدة فقط، وهي زيادة اليوم الأول).
            if ($lastActive === $today) {
                return;
            }

            $yesterday = Carbon::parse($today, $this->periods->timezone())->subDay()->format('Y-m-d');
            $newCurrent = ($lastActive === $yesterday) ? $streak->current_streak + 1 : 1;

            $streak->update([
                'current_streak' => $newCurrent,
                'longest_streak' => max($streak->longest_streak, $newCurrent),
                'last_active_date' => $today,
                'last_qualified_at' => $occurredAt,
            ]);
        });
    }

    /**
     * بند 158-160/440: مسار إصلاح منفصل تمامًا عن المسار التزايدي اليومي -
     * يُعيد بناء current_streak/longest_streak بالكامل من PuzzleAttempt
     * الصحيحة الفعلية. current_streak الناتج يعكس "حال السلسلة عند آخر
     * نشاط فعلي فعلًا" - لا يُصفَّر لمجرد مرور الوقت منذ ذلك الحين (نفس
     * دلالة المسار التزايدي: لا يتغيّر إلا بحدث جديد فعلي).
     */
    public function recalculateFromHistory(User $user): PlayerStreak
    {
        $timestamps = PuzzleAttempt::where('user_id', $user->id)
            ->where('is_correct', true)
            ->orderBy('created_at')
            ->pluck('created_at');

        $distinctDays = $timestamps
            ->map(fn (Carbon $ts) => $this->periods->localDateFor($ts))
            ->unique()
            ->values();

        $current = 0;
        $longest = 0;
        $previousDate = null;

        foreach ($distinctDays as $date) {
            if ($previousDate === null) {
                $current = 1;
            } else {
                $expectedNext = Carbon::parse($previousDate, $this->periods->timezone())->addDay()->format('Y-m-d');
                $current = ($date === $expectedNext) ? $current + 1 : 1;
            }
            $longest = max($longest, $current);
            $previousDate = $date;
        }

        return DB::transaction(function () use ($user, $current, $longest, $distinctDays, $timestamps) {
            $streak = PlayerStreak::query()->where('user_id', $user->id)->lockForUpdate()->first()
                ?? PlayerStreak::create(['user_id' => $user->id, 'current_streak' => 0, 'longest_streak' => 0]);

            $streak->update([
                'current_streak' => $current,
                'longest_streak' => max($streak->longest_streak, $longest),
                'last_active_date' => $distinctDays->last(),
                'last_qualified_at' => $timestamps->last(),
            ]);

            return $streak->fresh();
        });
    }
}
