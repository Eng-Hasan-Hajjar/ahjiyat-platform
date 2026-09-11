<?php

namespace App\GameEngine\Scoring;

use App\GameEngine\Contracts\ScoreCalculator;
use App\GameEngine\GameResult;
use App\Models\Puzzle;

/** نفس سلوك اليوم حرفياً: مكافأة ثابتة = puzzles.gem_reward. */
class FlatScoreCalculator implements ScoreCalculator
{
    public function calculate(Puzzle $puzzle, GameResult $result): int
    {
        return $result->correct ? (int) $puzzle->gem_reward : 0;
    }
}