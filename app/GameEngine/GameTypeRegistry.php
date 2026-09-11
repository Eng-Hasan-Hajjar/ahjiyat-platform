<?php

namespace App\GameEngine;

use App\GameEngine\Contracts\GameTypeDefinition;
use App\GameEngine\Contracts\GameValidator;
use App\GameEngine\Contracts\ScoreCalculator;
use App\Models\Puzzle;
use InvalidArgumentException;

/**
 * موزّع رقيق فقط - لا يعرف شيئًا عن sequence أو memory أو أي نوع لعبة
 * محدَّد بالاسم. كل المعرفة الفعلية تعيش داخل أصناف Definition المسجَّلة
 * بـ config/game_types.php. إضافة نوع لعبة جديد لا تلمس هذا الملف إطلاقاً.
 */
class GameTypeRegistry
{
    /** @var array<string, GameTypeDefinition>|null */
    protected ?array $definitions = null;

    public function definitionFor(?string $gameType): GameTypeDefinition
    {
        $key = $gameType ?? '';
        $definition = $this->definitions()[$key] ?? null;

        if (! $definition) {
            throw new InvalidArgumentException("لا يوجد Game Type مسجّل بمفتاح: {$key}");
        }

        return $definition;
    }

    public function validatorFor(Puzzle $puzzle): GameValidator
    {
        return $this->definitionFor($puzzle->game_type)->validator();
    }

    public function scorerFor(Puzzle $puzzle): ScoreCalculator
    {
        return $this->definitionFor($puzzle->game_type)->scorer();
    }

    public function rendererFor(Puzzle $puzzle): string
    {
        // القيمة المخزَّنة بالأحجية نفسها لها الأولوية دائمًا (سلوك موروث
        // محفوظ كما هو) - الـDefinition توفّر فقط الافتراضي حين تكون فارغة.
        return $puzzle->renderer ?: $this->definitionFor($puzzle->game_type)->renderer();
    }

    /**
     * القيم الافتراضية لنوع لعبة معيّن - تُستخدم بلوحة الإدارة لتعبئة
     * validation_type/score_mode/renderer تلقائياً عند اختيار game_type.
     * هذه القيم تُخزَّن للعرض/التوثيق فقط - التنفيذ الفعلي (validatorFor/
     * scorerFor أعلاه) يمر عبر الـDefinition مباشرة وليس عبر هذه الأعمدة.
     */
    public function defaultsFor(?string $gameType): array
    {
        if (blank($gameType)) {
            return [];
        }

        $definition = $this->definitions()[$gameType] ?? null;

        if (! $definition) {
            return [];
        }

        return [
            'validation_type' => $definition->validationType(),
            'score_mode' => $definition->scoreMode(),
            'renderer' => $definition->renderer(),
        ];
    }

    /** خيارات حقل game_type بلوحة الإدارة - مشتقة من التسجيل الفعلي، بدون أي قائمة مكرّرة يدويًا */
    public function gameTypeOptions(): array
    {
        return collect($this->definitions())
            ->reject(fn (GameTypeDefinition $definition) => $definition->key() === '')
            ->mapWithKeys(fn (GameTypeDefinition $definition) => [$definition->key() => $definition->label()])
            ->all();
    }

    /**
     * يُستدعى تلقائياً من App\Models\Puzzle::booted() عند كل حفظ - يفوّض
     * الاشتقاق بالكامل لِـ Definition النوع المعني. كل نوع لعبة جديد لاحقاً
     * يضيف منطقه داخل Definition خاص به، بدون أي تعديل على هذا الملف.
     */
    public function prepareForSave(Puzzle $puzzle): void
    {
        $this->definitionFor($puzzle->game_type)->normalizeAuthoringData($puzzle);

        if (filled($puzzle->game_type) && blank($puzzle->answer_hash)) {
            $puzzle->answer_hash = hash('sha256', $puzzle->game_type.':'.now()->timestamp.':'.random_int(100000, 999999));
        }
    }

    /** @return array<string, GameTypeDefinition> */
    protected function definitions(): array
    {
        return $this->definitions ??= collect(config('game_types.definitions', []))
            ->map(fn (string $class) => app($class))
            ->keyBy(fn (GameTypeDefinition $definition) => $definition->key())
            ->all();
    }
}