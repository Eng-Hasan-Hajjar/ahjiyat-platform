<?php

namespace App\GameEngine\Validators;

use App\GameEngine\Contracts\GameValidator;
use App\GameEngine\GameResult;
use App\Models\Puzzle;

/**
 * يقارن ترتيب المؤشرات المُرسل من العميل بالترتيب الصحيح المخزّن حصراً
 * بـ solution_data - هذه القيمة لا تصل أبداً لأي Blade view.
 */
class SequenceMatchValidator implements GameValidator
{
    public function check(Puzzle $puzzle, array $submission): GameResult
    {
        $submitted = array_map('intval', (array) ($submission['order'] ?? []));
        $correct = array_map('intval', (array) ($puzzle->solution_data['order'] ?? []));

        $isCorrect = $correct !== [] && $submitted === $correct;

        return new GameResult(correct: $isCorrect);
    }
}