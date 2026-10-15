<?php

namespace App\GameEngine\Validators;

use App\GameEngine\Contracts\GameValidator;
use App\GameEngine\GameResult;
use App\GameEngine\Support\SequenceTokens;
use App\Models\Puzzle;

/**
 * يقارن ترتيب المؤشرات المُرسل من العميل بالترتيب الصحيح المخزّن حصراً
 * بـ solution_data - هذه القيمة لا تصل أبداً لأي Blade view.
 */
class SequenceMatchValidator implements GameValidator
{
    public function check(Puzzle $puzzle, array $submission): GameResult
    {
        // E26: المُرسَل رموز معتمة (SequenceTokens) لا فهارس خام؛ أي مدخل غير نصي/رقمي أو مصفوفة متداخلة يُرفض فورًا.
        $raw = (array) ($submission['order'] ?? []);
        $expected = SequenceTokens::expectedOrder($puzzle);

        foreach ($raw as $value) {
            if (! is_string($value)) {
                return new GameResult(correct: false);
            }
        }

        $submitted = array_values($raw);

        $isCorrect = $expected !== []
            && count($submitted) === count($expected)
            && hash_equals(implode(',', $expected), implode(',', $submitted));

        return new GameResult(correct: $isCorrect);
    }
}
