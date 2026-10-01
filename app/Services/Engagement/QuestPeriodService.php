<?php

namespace App\Services\Engagement;

use App\Models\QuestDefinition;
use Illuminate\Support\Carbon;

/**
 * E13 (بند 18-26، بند 128-130): المصدر الوحيد لحساب حدود الفترة ومفتاحها
 * لكل من Quest وStreak معًا - لا يجوز وجود منطقة زمنية مختلفة بينهما.
 *
 * المنطقة الزمنية: config('app.timezone') فعليًا (UTC، مؤكَّد بتدقيق فعلي
 * لا افتراض) - لا إعداد "المنطقة الزمنية المعروضة" الإداري، فهو معلَّم
 * صراحةً بالكود القائم "للعرض فقط - لا تغيّر منطقة السيرفر الزمنية
 * الفعلية"، وغير مُستهلَك بأي منطق حسابي فعليًا.
 *
 * الأسبوع يبدأ الاثنين 00:00 (بند 24: لا سياسة منصة موجودة تُحدِّد خلاف
 * ذلك - هذا الافتراضي الآمن الموثَّق صراحةً).
 *
 * أسبوع ISO: نستخدم format('o')/format('W') القياسيين بـPHP (لا خاصية
 * Carbon قد تختلف توفُّرها بين الإصدارات) - يحلّان بدقة مشكلة اختلاف
 * "سنة الأسبوع" عن "السنة التقويمية" عند حدود ديسمبر/يناير (بند 195).
 */
class QuestPeriodService
{
    public function timezone(): string
    {
        return config('app.timezone');
    }

    public function dailyContext(?Carbon $now = null): PeriodContext
    {
        $now = ($now ?? now())->copy()->setTimezone($this->timezone());
        $start = $now->copy()->startOfDay();
        $end = $now->copy()->endOfDay();

        return new PeriodContext(
            QuestDefinition::PERIOD_DAILY,
            'daily:'.$start->format('Y-m-d'),
            $start,
            $end,
            $this->timezone(),
        );
    }

    public function weeklyContext(?Carbon $now = null): PeriodContext
    {
        $now = ($now ?? now())->copy()->setTimezone($this->timezone());
        $start = $now->copy()->startOfWeek(Carbon::MONDAY)->startOfDay();
        $end = $now->copy()->endOfWeek(Carbon::SUNDAY)->endOfDay();

        return new PeriodContext(
            QuestDefinition::PERIOD_WEEKLY,
            sprintf('weekly:%s-W%s', $start->format('o'), $start->format('W')),
            $start,
            $end,
            $this->timezone(),
        );
    }

    public function contextFor(string $periodType, ?Carbon $now = null): PeriodContext
    {
        return match ($periodType) {
            QuestDefinition::PERIOD_DAILY => $this->dailyContext($now),
            QuestDefinition::PERIOD_WEEKLY => $this->weeklyContext($now),
            default => throw new \InvalidArgumentException("نوع فترة غير معروف: {$periodType}"),
        };
    }

    /** بند 129: التاريخ المحلي المُعتمَد - نفس المصدر بالضبط تستخدمه الـStreak أيضًا، لا تباين بينهما أبدًا. */
    public function localDateFor(Carbon $timestamp): string
    {
        return $timestamp->copy()->setTimezone($this->timezone())->format('Y-m-d');
    }

    public function todayLocal(): string
    {
        return $this->localDateFor(now());
    }
}
