<?php

namespace App\Services\Reputation;

/**
 * E37 - رياضيات السمعة **نقيّة وحتمية** (نفس المدخلات = نفس الناتج، بلا قاعدة بيانات ولا وقت ولا عشوائية). كل إشارة تُطبَّع إلى 0..1 قبل المزج:
 * لا جمع مباشر لتقييم 4.7 + 10000 متابع + 500000 لعبة. التقريب الوحيد عند التحويل لسلّم صحيح (0..scale).
 */
final class ReputationMath
{
    /** درجة انكماش بايزية: (مجموع + متوسط_سابق × وزن_سابق) ÷ (عدد + وزن_سابق). بلا تقييمات = المتوسط السابق نفسه. */
    public static function shrunk(int $count, int $sum, float $priorMean, float $priorWeight): float
    {
        $weight = max(0.0, $priorWeight);
        $denominator = $count + $weight;

        return $denominator > 0 ? ($sum + $priorMean * $weight) / $denominator : $priorMean;
    }

    /** مكوّن جودة التقييم 0..1: ((منكمش − حدّ_أدنى) ÷ (5 − حدّ_أدنى)). بلا تقييمات = 0؛ ما دون الحدّ الأدنى = 0؛ لا رصيد لمن لا دليل عنده. */
    public static function ratingComponent(int $count, int $sum, float $priorMean, float $priorWeight, float $floor): float
    {
        if ($count <= 0) {
            return 0.0;
        }

        return self::clamp((self::shrunk($count, $sum, $priorMean, $priorWeight) - $floor) / (5.0 - $floor));
    }

    /** تطبيع لوغاريتمي مسقوف 0..1: log(1+v) ÷ log(1+cap). عوائد متناقصة؛ ما فوق السقف لا يزيد. */
    public static function logNorm(int|float $value, int|float $cap): float
    {
        if ($value <= 0 || $cap <= 0) {
            return 0.0;
        }

        return self::clamp(log1p((float) $value) / log1p((float) $cap));
    }

    /** تطبيع خطي مسقوف 0..1. */
    public static function linearNorm(int|float $value, int|float $cap): float
    {
        if ($value <= 0 || $cap <= 0) {
            return 0.0;
        }

        return self::clamp((float) $value / (float) $cap);
    }

    public static function clamp(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }

    /**
     * مزج مرجَّح لمكوّنات 0..1 بأوزان (مجموعها 100 عادةً؛ يُقسَم على المجموع الفعلي فلا يتجاوز الناتج 1).
     *
     * @param  array<string, float>  $components
     * @param  array<string, int|float>  $weights
     */
    public static function blend(array $components, array $weights): float
    {
        $total = array_sum($weights);

        if ($total <= 0) {
            return 0.0;
        }

        $accumulated = 0.0;

        foreach ($weights as $name => $weight) {
            $accumulated += self::clamp((float) ($components[$name] ?? 0.0)) * (float) $weight;
        }

        return self::clamp($accumulated / $total);
    }

    /** تحويل 0..1 إلى عدد صحيح 0..scale (تقريب لأقرب عدد صحيح؛ المنتصف للأعلى). */
    public static function toScale(float $unit, int $scale = 10000): int
    {
        return (int) round(self::clamp($unit) * $scale);
    }

    /** مدى الأيام الكامل بين طابعين زمنيين (≥ 0)، أو 0 إن غاب أحدهما. */
    public static function daysBetween(?int $fromTimestamp, ?int $toTimestamp): int
    {
        if ($fromTimestamp === null || $toTimestamp === null) {
            return 0;
        }

        return intdiv(max(0, $toTimestamp - $fromTimestamp), 86400);
    }
}
