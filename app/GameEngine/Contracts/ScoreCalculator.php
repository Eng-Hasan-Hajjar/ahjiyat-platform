<?php

namespace App\GameEngine\Contracts;

use App\GameEngine\GameResult;
use App\Models\Puzzle;

interface ScoreCalculator
{
    /**
     * يُستدعى فقط بعد تأكد الحل الصحيح. سقف الكسب اليومي (gems.daily_earn_cap)
     * يبقى مسؤولية PuzzleAttemptService حصراً - هذا الصنف يرجّع "المكافأة الخام" فقط.
     */
    public function calculate(Puzzle $puzzle, GameResult $result): int;
}