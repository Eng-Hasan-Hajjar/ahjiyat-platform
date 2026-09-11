<?php

namespace App\GameEngine\Definitions;

use App\GameEngine\Contracts\GameTypeDefinition;
use App\GameEngine\Contracts\GameValidator;
use App\GameEngine\Contracts\ScoreCalculator;
use App\GameEngine\Scoring\FlatScoreCalculator;
use App\GameEngine\Validators\SequenceMatchValidator;
use App\Models\Puzzle;

class SequenceGameTypeDefinition implements GameTypeDefinition
{
    public function key(): string
    {
        return 'sequence';
    }

    public function label(): string
    {
        return 'ترتيب تسلسل (Sequence)';
    }

    public function validationType(): string
    {
        return 'sequence_match';
    }

    public function scoreMode(): string
    {
        return 'flat';
    }

    public function renderer(): string
    {
        return 'games.sequence';
    }

    public function validator(): GameValidator
    {
        return app(SequenceMatchValidator::class);
    }

    public function scorer(): ScoreCalculator
    {
        return app(FlatScoreCalculator::class);
    }

    public function normalizeAuthoringData(Puzzle $puzzle): void
    {
        // نفس المنطق حرفيًا المنقول من GameTypeRegistry::prepareForSave() السابقة -
        // صفر تغيير سلوك، فقط انتقل مكانه.
        $items = (array) ($puzzle->game_config['items'] ?? []);
        $puzzle->solution_data = ['order' => array_keys($items)];
    }

    public function publicPayload(Puzzle $puzzle): array
    {
        return [
            'items' => $puzzle->game_config['items'] ?? [],
        ];
    }
}