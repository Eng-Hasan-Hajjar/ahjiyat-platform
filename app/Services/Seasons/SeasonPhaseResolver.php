<?php

namespace App\Services\Seasons;

use App\Models\SeasonPhase;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * E42 - اشتقاق المرحلة الحالية/القادمة من مجموعة مراحل مرتَّبة. دوال صرفة بلا استعلامات ولا كتابة (الفترات نصف مفتوحة [starts_at, ends_at)).
 * الترتيب الحتمي: (starts_at، sort_order، id) — يضمنه Season::phases() وهذه الدالة تعيد الترتيب نفسه لمن يمرّر مجموعة غير مرتبة.
 */
final class SeasonPhaseResolver
{
    /**
     * @param  iterable<SeasonPhase>  $phases
     * @return Collection<int, SeasonPhase>
     */
    public static function ordered(iterable $phases): Collection
    {
        return collect($phases)
            ->sort(fn (SeasonPhase $a, SeasonPhase $b) => [$a->starts_at->getTimestamp(), $a->sort_order, $a->getKey()] <=> [$b->starts_at->getTimestamp(), $b->sort_order, $b->getKey()])
            ->values();
    }

    /** @param  Collection<int, SeasonPhase>  $phases */
    public static function current(Collection $phases, ?CarbonInterface $now = null): ?SeasonPhase
    {
        return self::ordered($phases)->first(fn (SeasonPhase $p) => $p->isCurrent($now));
    }

    /** @param  Collection<int, SeasonPhase>  $phases */
    public static function next(Collection $phases, ?CarbonInterface $now = null): ?SeasonPhase
    {
        return self::ordered($phases)->first(fn (SeasonPhase $p) => $p->stateAt($now) === SeasonPhase::STATE_UPCOMING);
    }

    /** ترتيب المرحلة (1-based) ضمن الموسم. @param Collection<int, SeasonPhase> $phases */
    public static function position(Collection $phases, SeasonPhase $phase): int
    {
        $index = self::ordered($phases)->search(fn (SeasonPhase $p) => $p->getKey() === $phase->getKey());

        return $index === false ? 0 : $index + 1;
    }

    /** تداخل فترتين نصف مفتوحتين. */
    public static function overlaps(CarbonInterface $aStart, CarbonInterface $aEnd, CarbonInterface $bStart, CarbonInterface $bEnd): bool
    {
        return $aStart->lt($bEnd) && $bStart->lt($aEnd);
    }
}
