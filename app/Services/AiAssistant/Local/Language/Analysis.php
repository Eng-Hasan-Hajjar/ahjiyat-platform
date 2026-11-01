<?php

namespace App\Services\AiAssistant\Local\Language;

/** TI-2 - نتيجة تحليل سؤال: مفاهيم + وجه السؤال + ملكية + نفي + تصحيحات. قيمة ثابتة بلا اعتماد على قاعدة البيانات. */
final class Analysis
{
    /**
     * @param  list<string>  $tokens  كلمات مطوية (بعد التصحيح)
     * @param  array<string, int>  $concepts  مفهوم ⇒ عدد مرات الظهور
     * @param  array<string, true>  $negated  مفاهيم منفية مباشرة
     * @param  list<string>  $facets  أوجه السؤال المرصودة بترتيب الأسبقية
     * @param  list<array{0: string, 1: string}>  $corrections  (الأصل، المصحَّح)
     * @param  list<string>  $residual  كلمات محتوى بلا مفهوم
     * @param  array<string, int>  $inherited  مفاهيم موروثة من السؤال السابق (سياق المحادثة)
     */
    public function __construct(
        public readonly string $folded,
        public readonly array $tokens,
        public readonly array $concepts,
        public readonly array $negated,
        public readonly array $facets,
        public readonly int $personal,
        public readonly array $corrections,
        public readonly array $residual,
        public readonly array $inherited = [],
    ) {}

    public function facet(): ?string
    {
        return $this->facets[0] ?? null;
    }

    public function has(string $concept): bool
    {
        return isset($this->concepts[$concept]);
    }

    /** @param list<string> $concepts */
    public function hasAny(array $concepts): bool
    {
        foreach ($concepts as $concept) {
            if (isset($this->concepts[$concept])) {
                return true;
            }
        }

        return false;
    }

    /** عدد مفاهيم «الموضوع» (غير البنيوية). */
    public function topicCount(): int
    {
        return count(array_diff(array_keys($this->concepts), Lexicon::STRUCTURAL));
    }

    /** النص المصحَّح بعد التطبيع (للفحص الأمني اللاحق للتحليل اللغوي؛ لا يُعرَض للمستخدم). */
    public function correctedText(): string
    {
        return implode(' ', $this->tokens);
    }

    /** @return list<string> */
    public function conceptList(): array
    {
        return array_keys($this->concepts);
    }
}
