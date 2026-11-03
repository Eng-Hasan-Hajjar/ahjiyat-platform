<?php

namespace App\Services\AiAssistant\Local\Enhanced;

use App\Services\AiAssistant\Local\Classification;

/** TATAWWUR-FINAL - ناتج التصنيف الهجين: التصنيف النهائي + احتمالات المصنِّف الخطي (كل الفئات) + درجات المحرك الحتمي. قيمة ثابتة بلا نص السؤال. */
final class HybridResult
{
    /**
     * @param  array<string, float>  $probabilities  فئة ⇒ احتمال
     * @param  array<string, float>  $engineScores  نية ⇒ درجة المحرك الحتمي
     */
    public function __construct(
        public readonly Classification $classification,
        public readonly array $probabilities,
        public readonly array $engineScores,
    ) {}

    public function probability(string $class): float
    {
        return $this->probabilities[$class] ?? 0.0;
    }
}
