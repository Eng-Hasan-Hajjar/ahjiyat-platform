<?php

namespace App\GameEngine;

use App\GameEngine\Contracts\GameValidator;
use App\GameEngine\Contracts\ScoreCalculator;
use App\Models\Puzzle;
use InvalidArgumentException;

class GameTypeRegistry
{
    protected const LEGACY_VALIDATION_TYPE = 'exact_string';

    protected const DEFAULT_SCORE_MODE = 'flat';

    protected const DEFAULT_RENDERER = 'games.legacy-input';

    public function validatorFor(Puzzle $puzzle): GameValidator
    {
        $key = $puzzle->validation_type ?? self::LEGACY_VALIDATION_TYPE;

        return $this->resolve('validators', $key, 'Validator');
    }

    public function scorerFor(Puzzle $puzzle): ScoreCalculator
    {
        $key = $puzzle->score_mode ?? self::DEFAULT_SCORE_MODE;

        return $this->resolve('scorers', $key, 'Score Calculator');
    }

    public function rendererFor(Puzzle $puzzle): string
    {
        return $puzzle->renderer ?: self::DEFAULT_RENDERER;
    }

    public function defaultsFor(?string $gameType): array
    {
        if (blank($gameType)) {
            return [];
        }

        return config("game_types.game_types.$gameType", []);
    }

    public function gameTypeOptions(): array
    {
        return collect(config('game_types.game_types', []))
            ->mapWithKeys(fn (array $definition, string $key) => [$key => $definition['label'] ?? $key])
            ->toArray();
    }

    /**
     * يُستدعى تلقائياً من App\Models\Puzzle::booted() عند كل حفظ - يشتق
     * solution_data/game_config حسب نوع اللعبة. كل نوع لعبة جديد لاحقاً
     * يضيف حالة هون فقط، بدون أي تعديل على Puzzle model نفسه.
     */
    public function prepareForSave(Puzzle $puzzle): void
    {
        if ($puzzle->game_type === 'sequence') {
            $items = (array) ($puzzle->game_config['items'] ?? []);
            $puzzle->solution_data = ['order' => array_keys($items)];
        }

        if ($puzzle->game_type === 'memory') {
            $config = (array) $puzzle->game_config;
            $uniqueFaces = array_values(array_filter((array) ($config['faces'] ?? [])));

            $cards = [];
            $id = 0;

            foreach ($uniqueFaces as $face) {
                $cards[] = ['id' => $id++, 'face' => $face];
                $cards[] = ['id' => $id++, 'face' => $face];
            }

            // نحافظ على faces (كما أدخلها الأدمن، لإعادة عرضها بالتعديل لاحقاً)
            // بجانب cards المُشتقة (بيانات اللعب الفعلية) - داخل نفس العمود.
            $puzzle->game_config = ['faces' => $uniqueFaces, 'cards' => $cards];
        }

        if (filled($puzzle->game_type) && blank($puzzle->answer_hash)) {
            $puzzle->answer_hash = hash('sha256', $puzzle->game_type.':'.now()->timestamp.':'.random_int(100000, 999999));
        }
    }

    protected function resolve(string $group, string $key, string $label): object
    {
        $class = config("game_types.$group.$key");

        if (! $class || ! class_exists($class)) {
            throw new InvalidArgumentException("لا يوجد {$label} مسجّل بمفتاح: {$key}");
        }

        return app($class);
    }
}