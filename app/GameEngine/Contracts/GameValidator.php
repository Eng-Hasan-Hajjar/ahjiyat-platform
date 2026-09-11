<?php

namespace App\GameEngine\Contracts;

use App\GameEngine\GameResult;
use App\Models\Puzzle;

/**
 * كل نوع تحقق (validation_type) بمحرك الألعاب ينفّذ هذا العقد.
 * عدة أنواع ألعاب (game_type) ممكن تشترك بنفس صنف Validator واحد -
 * التسجيل يصير عبر config/game_types.php وليس بتعديل هذا الملف.
 */
interface GameValidator
{
    /**
     * @param  array<string, mixed>  $submission  البيانات الخام من المتصفح (مفكوكة من JSON مسبقاً)
     */
    public function check(Puzzle $puzzle, array $submission): GameResult;
}