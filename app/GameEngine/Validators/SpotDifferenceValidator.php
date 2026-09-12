<?php

namespace App\GameEngine\Validators;

use App\GameEngine\Contracts\GameValidator;
use App\GameEngine\GameResult;
use App\Models\Puzzle;

/**
 * يُستدعى فقط عند إنهاء (Finalize) جلسة spot_difference - يعيد اشتقاق
 * الصحة بالكامل من قائمة المؤشرات المُكتشفة الفعلية (وليس من أي علم
 * "مكتمل" جاهز)، كطبقة تحقق ثانية مستقلة عن منطق reveal() نفسه.
 */
class SpotDifferenceValidator implements GameValidator
{
    public function check(Puzzle $puzzle, array $submission): GameResult
    {
        $required = count((array) ($puzzle->solution_data['hotspots'] ?? []));

        $found = (array) ($submission['server_state']['found_indices'] ?? []);
        $validFound = array_unique(array_filter(
            $found,
            fn ($index) => is_int($index) && $index >= 0 && $index < $required
        ));

        return new GameResult(correct: $required > 0 && count($validFound) === $required);
    }
}