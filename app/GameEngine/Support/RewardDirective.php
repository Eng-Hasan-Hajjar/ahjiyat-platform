<?php

namespace App\GameEngine\Support;

/**
 * ماذا تفعل عملية الحل هذه بالمكافأة - قرار سيرفري بحت، العميل لا يؤثر
 * عليه أبداً. useDefault() تعني "اتبع سلوك النقاط الطبيعي للأحجية" (نفس ما
 * يحدث دائماً للحلول المستقلة) - مختلف تماماً عن fixed(0)/none() اللتين
 * تعنيان "صفر مقصود صراحة". لا نترك 0 غامضة بين الاثنين.
 */
final class RewardDirective
{
    private function __construct(
        public readonly bool $useDefault,
        public readonly int $amount,
    ) {}

    public static function useDefault(): self
    {
        return new self(true, 0);
    }

    public static function fixed(int $amount): self
    {
        return new self(false, max(0, $amount));
    }

    public static function none(): self
    {
        return self::fixed(0);
    }
}