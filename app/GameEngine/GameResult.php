<?php

namespace App\GameEngine;

/**
 * نتيجة تحقق واحدة من محرك الألعاب - حاوية بيانات بسيطة تنتقل من
 * الـ Validator إلى الـ ScoreCalculator ثم إلى PuzzleAttemptService.
 */
final class GameResult
{
    public function __construct(
        public readonly bool $correct,
        public readonly array $meta = [],
    ) {}
}