<?php

namespace App\GameEngine\Validators;

use App\GameEngine\Contracts\GameValidator;
use App\GameEngine\GameResult;
use App\Models\Puzzle;

/**
 * يغلّف Puzzle::checkAnswer() الحالي حرفياً بدون أي تغيير سلوك -
 * يخدم الأنواع الكلاسيكية الثلاثة (نصية/صورة/اختيار من متعدد) تماماً كاليوم.
 */
class ExactStringValidator implements GameValidator
{
    public function check(Puzzle $puzzle, array $submission): GameResult
    {
        $answer = (string) ($submission['answer'] ?? '');

        return new GameResult(correct: $puzzle->checkAnswer($answer));
    }
}