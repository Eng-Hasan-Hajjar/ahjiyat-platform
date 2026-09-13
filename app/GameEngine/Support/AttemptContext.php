<?php

namespace App\GameEngine\Support;

/**
 * Value Object صغير غير قابل للتغيير يمثّل "أين حدثت هذه المحاولة" (سياق
 * الاستدعاء). لا يُبنى أبداً من بيانات Request خام - فقط من كود سيرفري
 * موثوق (Controller/Service) يعرف بالفعل السياق الحقيقي. هذا يمنع أي عميل
 * من ادّعاء context_type/context_id مزوَّرين والتأثير على منطق المكافأة
 * أو التتبّع لاحقاً.
 *
 * بالمرحلة الحالية (Phase B) يُستخدم AttemptContext::none() حصراً (حل
 * مستقل). القيمة المُسمّاة (::for) جاهزة لطبقة تنسيق مستقبلية (حملة قصصية،
 * منافسة راعٍ...) بدون أي تعديل على هذا الصنف أو على PuzzleAttemptService.
 */
final class AttemptContext
{
    private function __construct(
        public readonly ?string $type,
        public readonly ?int $id,
    ) {}

    public static function none(): self
    {
        return new self(null, null);
    }

    public static function for(string $type, int $id): self
    {
        return new self($type, $id);
    }

    public function isPresent(): bool
    {
        return $this->type !== null;
    }

    /** @return array{context_type: ?string, context_id: ?int} */
    public function toAttributes(): array
    {
        return ['context_type' => $this->type, 'context_id' => $this->id];
    }




        /**
     * افتراضياً يفحص الحل المستقل (Standalone) فقط. تمرير Context (لاحقاً من
     * خطوة حملة/تحدٍّ راعٍ) يفحص الحل ضمن ذاك السياق حصراً - حل مستقل لا
     * يُعتبر أبداً حلاً لسياق آخر، والعكس صحيح.
     */
    public function hasSolvedPuzzle(Puzzle $puzzle, ?AttemptContext $context = null): bool
    {
        $context ??= AttemptContext::none();

        return $this->puzzleAttempts()
            ->where('puzzle_id', $puzzle->id)
            ->where('is_correct', true)
            ->where('context_type', $context->type)
            ->where('context_id', $context->id)
            ->exists();
    }


    
}