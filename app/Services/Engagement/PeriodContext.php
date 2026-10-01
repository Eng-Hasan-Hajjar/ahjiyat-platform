<?php

namespace App\Services\Engagement;

use Illuminate\Support\Carbon;

/**
 * E13: بنية ثابتة تُمثِّل فترة محدَّدة (يومية/أسبوعية) - period_key
 * Deterministic بالكامل، مُشتقّ من هذه الخدمة حصرًا، لا تنسيق متفرّق
 * بأي مكان آخر بالكود.
 */
final class PeriodContext
{
    public function __construct(
        public readonly string $periodType,
        public readonly string $periodKey,
        public readonly Carbon $start,
        public readonly Carbon $end,
        public readonly string $timezone,
    ) {}
}
