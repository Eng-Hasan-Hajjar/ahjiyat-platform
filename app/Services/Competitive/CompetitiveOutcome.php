<?php

namespace App\Services\Competitive;

use App\Models\GameSession;

/** ناتج جلسة تنافسية: كله محسوب بالسيرفر (صحة بالمُتحقِّق، مدة بطوابع السيرفر، نقاط بالصيغة الثابتة). */
final class CompetitiveOutcome
{
    public function __construct(
        public readonly bool $correct,
        public readonly int $durationMs,
        public readonly int $score,
        public readonly bool $timedOut,
        public readonly GameSession $session,
    ) {}
}
