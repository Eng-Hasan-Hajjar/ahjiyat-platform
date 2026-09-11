<?php

namespace App\GameEngine\Definitions;

use App\GameEngine\Contracts\GameTypeDefinition;
use App\GameEngine\Contracts\GameValidator;
use App\GameEngine\Contracts\ScoreCalculator;
use App\GameEngine\Scoring\FlatScoreCalculator;
use App\GameEngine\Validators\MemoryMatchValidator;
use App\Models\Puzzle;

class MemoryGameTypeDefinition implements GameTypeDefinition
{
    public function key(): string
    {
        return 'memory';
    }

    public function label(): string
    {
        return 'بطاقات الذاكرة (Memory)';
    }

    public function validationType(): string
    {
        return 'memory_match';
    }

    public function scoreMode(): string
    {
        return 'flat';
    }

    public function renderer(): string
    {
        return 'games.memory';
    }

    public function validator(): GameValidator
    {
        return app(MemoryMatchValidator::class);
    }

    public function scorer(): ScoreCalculator
    {
        return app(FlatScoreCalculator::class);
    }

    public function normalizeAuthoringData(Puzzle $puzzle): void
    {
        // نفس المنطق حرفيًا المنقول من GameTypeRegistry::prepareForSave() السابقة -
        // صفر تغيير سلوك، فقط انتقل مكانه.
        $config = (array) $puzzle->game_config;
        $uniqueFaces = array_values(array_filter((array) ($config['faces'] ?? [])));

        $cards = [];
        $id = 0;

        foreach ($uniqueFaces as $face) {
            $cards[] = ['id' => $id++, 'face' => $face];
            $cards[] = ['id' => $id++, 'face' => $face];
        }

        $puzzle->game_config = ['faces' => $uniqueFaces, 'cards' => $cards];
    }

    public function publicPayload(Puzzle $puzzle): array
    {
        return [
            'cards' => $puzzle->game_config['cards'] ?? [],
        ];
    }
}