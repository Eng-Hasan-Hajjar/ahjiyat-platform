<?php

namespace App\GameEngine\Definitions;

use App\GameEngine\Contracts\GameTypeDefinition;
use App\GameEngine\Contracts\GameValidator;
use App\GameEngine\Contracts\ScoreCalculator;
use App\GameEngine\Scoring\FlatScoreCalculator;
use App\GameEngine\Validators\ExactStringValidator;
use App\Models\Puzzle;

/**
 * محول توافقية (Compatibility Adapter) للسلوك الكلاسيكي الكامل الحالي -
 * يغطي الأنواع الثلاثة الأصلية (نصية/صورة/اختيار من متعدد)، أي puzzle
 * لها game_type = null. هذا ليس "نوع نصي فقط" - هو الحالة الافتراضية
 * لكل ما كان يعمل قبل محرك الألعاب، ويجب أن يبقى سلوكها 1:1 كما هو.
 */
class LegacyGameTypeDefinition implements GameTypeDefinition
{
    public function key(): string
    {
        return '';
    }

    public function label(): string
    {
        return 'كلاسيكي (نصي / صورة / اختيار من متعدد)';
    }

    public function validationType(): string
    {
        return 'exact_string';
    }

    public function scoreMode(): string
    {
        return 'flat';
    }

    public function renderer(): string
    {
        return 'games.legacy-input';
    }

    public function validator(): GameValidator
    {
        return app(ExactStringValidator::class);
    }

    public function scorer(): ScoreCalculator
    {
        return app(FlatScoreCalculator::class);
    }

    public function normalizeAuthoringData(Puzzle $puzzle): void
    {
        // لا يوجد اشتقاق مطلوب - answer_raw -> answer_hash تُعالَج مباشرة
        // بـ Puzzle::setAnswerRawAttribute() كما كانت دائمًا، بدون علاقة بمحرك الألعاب.
    }

    public function publicPayload(Puzzle $puzzle): array
    {
        return [
            'type' => $puzzle->type,
            'prompt' => $puzzle->prompt,
            'choices' => $puzzle->choices,
            'image_path' => $puzzle->image_path,
        ];
    }
}