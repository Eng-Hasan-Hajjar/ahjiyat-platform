<?php

namespace App\Services\Competitive;

use App\Models\Puzzle;

/**
 * مصدر الحقيقة الوحيد لنقاط المنافسة (حتمي، عدد صحيح، الأعلى أفضل HIGHER_IS_BETTER). مقياسان فعليان فقط من التدقيق: الصحة (مُتحقِّق
 * اللعبة بالسيرفر) والمدة (طوابع السيرفر). خاطئة = 0؛ صحيحة = score_base + intdiv(score_speed_max × (السقف − المدة), السقف).
 * السقف = time_limit_seconds للأحجية أو default_time_cap_seconds. كلا الطرفين يلعب الأحجية نفسها فالصيغة والسقف متطابقان (عدالة).
 * ترتيب الأحداث: النقاط تنازليًا ثم المدة تصاعديًا ثم وقت الإكمال ثم المعرّف (حتمي ومستقر): راجع CompetitiveLeaderboardService.
 */
class CompetitiveScoringService
{
    public const HIGHER_IS_BETTER = true;

    public function capMs(Puzzle $puzzle): int
    {
        return max(1, (int) ($puzzle->time_limit_seconds ?: config('competitive.default_time_cap_seconds', 600))) * 1000;
    }

    public function score(bool $correct, int $durationMs, int $capMs): int
    {
        if (! $correct) {
            return 0;
        }

        $capMs = max(1, $capMs);
        $remaining = max(0, $capMs - max(0, $durationMs));

        return (int) config('competitive.score_base', 1000) + intdiv((int) config('competitive.score_speed_max', 1000) * $remaining, $capMs);
    }
}
