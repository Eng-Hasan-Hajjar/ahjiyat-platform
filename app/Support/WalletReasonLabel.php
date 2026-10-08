<?php

namespace App\Support;

/**
 * E22 (عرض فقط): يحوّل رمز سبب معاملة المحفظة إلى نص عربي مقروء للاعب.
 *
 * لا يغيّر شيئًا في المحفظة أو في تخزين السبب: الرمز الأصلي يبقى كما هو في قاعدة البيانات،
 * والأسباب غير المعروفة (نصوص حرة من الإدارة مثلًا) تُعرض كما هي حرفيًا.
 */
final class WalletReasonLabel
{
    /** @var array<string, string> بادئة الرمز ← النص المعروض */
    private const PREFIXES = [
        'solved_puzzle' => 'حلّ أحجية',
        'achievement' => 'مكافأة إنجاز',
        'store_purchase' => 'شراء من المتجر',
        'hint' => 'شراء تلميح',
        'redemption_request' => 'طلب استبدال',
    ];

    /** @var array<string, string> رمز كامل ← النص المعروض */
    private const EXACT = [
        'competitive_event_reward' => 'جائزة منافسة',
    ];

    /**
     * @return array{label: string, raw: string, known: bool}
     */
    public static function describe(?string $reason): array
    {
        $raw = trim((string) $reason);

        if ($raw === '') {
            return ['label' => 'معاملة', 'raw' => '', 'known' => false];
        }

        if (isset(self::EXACT[$raw])) {
            return ['label' => self::EXACT[$raw], 'raw' => $raw, 'known' => true];
        }

        $head = strstr($raw, ':', true);
        $tail = $head === false ? '' : substr($raw, strlen($head) + 1);

        if ($head === 'quest') {
            $label = str_starts_with($tail, 'weekly') ? 'مهمة أسبوعية' : (str_starts_with($tail, 'daily') ? 'مهمة يومية' : 'إكمال مهمة');

            return ['label' => $label, 'raw' => $raw, 'known' => true];
        }

        if ($head === 'level' && ctype_digit($tail)) {
            return ['label' => 'ترقية إلى المستوى '.$tail, 'raw' => $raw, 'known' => true];
        }

        if ($head !== false && isset(self::PREFIXES[$head])) {
            return ['label' => self::PREFIXES[$head], 'raw' => $raw, 'known' => true];
        }

        return ['label' => $raw, 'raw' => $raw, 'known' => false];
    }
}
