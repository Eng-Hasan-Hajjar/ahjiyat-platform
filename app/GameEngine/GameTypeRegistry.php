<?php

namespace App\GameEngine;

use App\GameEngine\Contracts\GameValidator;
use App\GameEngine\Contracts\ScoreCalculator;
use App\Models\Puzzle;
use InvalidArgumentException;

/**
 * نقطة الدخول الوحيدة لأي كود يحتاج يعرف "كيف نتحقق من هذه الأحجية"
 * أو "كم جوهرة تستحق" أو "بأي Blade view تُعرض". كل التسجيل الفعلي
 * موجود بـ config/game_types.php - إضافة نوع لعبة جديد لاحقاً يعني
 * تعديل ملف الإعدادات فقط، بدون لمس هذا الصنف.
 */
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

    /**
     * القيم الافتراضية لنوع لعبة معيّن - تُستخدم بلوحة إدارة Filament
     * لتعبئة validation_type/score_mode/renderer تلقائياً عند اختيار game_type.
     */
    public function defaultsFor(?string $gameType): array
    {
        if (blank($gameType)) {
            return [];
        }

        return config("game_types.game_types.$gameType", []);
    }

    /** خيارات حقل game_type بلوحة الإدارة */
    public function gameTypeOptions(): array
    {
        return collect(config('game_types.game_types', []))
            ->mapWithKeys(fn (array $definition, string $key) => [$key => $definition['label'] ?? $key])
            ->toArray();
    }

    /**
     * يُستدعى تلقائياً من App\Models\Puzzle::booted() عند كل حفظ - يشتق
     * solution_data من game_config حسب نوع اللعبة. كل نوع لعبة جديد لاحقاً
     * يضيف حالة هون فقط، بدون أي تعديل على Puzzle model نفسه.
     */
    public function prepareForSave(Puzzle $puzzle): void
    {
        if ($puzzle->game_type === 'sequence') {
            $items = (array) ($puzzle->game_config['items'] ?? []);
            $puzzle->solution_data = ['order' => array_keys($items)];
        }

        // أنواع الألعاب الجديدة لا تستخدم answer_hash إطلاقاً (لكل نوع Validator
        // خاص)، لكن العمود ما زال NOT NULL بقاعدة البيانات - نعبّئه بقيمة
        // عشوائية غير قابلة للتخمين بدل تعديل السكيما الحساسة هذه.
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