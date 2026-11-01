<?php

namespace App\Services\AiAssistant\Local\Language;

/**
 * TI-2 - تحليل صرفي خفيف قائم على «توليد مرشَّحات»: لكل كلمة مطوية نولّد صيغًا محتملة بعد تجريد السوابق (و/ف، ب/ك/ل/س، ال، لل) واللواحق
 * (ضمائر الملكية والمفعول، المثنى/الجمع السالم، جمع المؤنث ات→ة)، ثم يبحث المعجم عن أول مرشَّح معروف. التطابق حرفي مع المعجم فلا «تجذير» عشوائي ولا أحكام تخمينية.
 * ملاحظة: التاء المربوطة مطوية إلى «ه» (TextFold) فتُعامَل «ه» الأخيرة لاحقة محتملة أيضًا.
 */
final class Morphology
{
    /** @var list<array{0: string, 1: string, 2: bool}> [لاحقة، بديل، ملكية-المتكلم] بترتيب الأسبقية (الأطول أولًا). */
    private const SUFFIXES = [
        ['كم', '', false], ['هم', '', false], ['هن', '', false], ['ها', '', false], ['نا', '', false], ['ني', '', true],
        ['ون', '', false], ['ين', '', false], ['ان', '', false], ['ات', 'ه', false], ['ات', '', false],
        ['ي', '', true], ['ك', '', false], ['ه', '', false], ['ا', '', false],
    ];

    /**
     * سوابق/لواحق مجرَّدة: قائمة (صيغة، هل جُرِّدت لاحقة ملكية المتكلم) بأول-الأسبقية.
     *
     * @return list<array{0: string, 1: bool}>
     */
    public static function candidates(string $token): array
    {
        $bases = self::bases($token);
        $out = [];
        $seen = [];
        $push = function (string $form, bool $possessive) use (&$out, &$seen): void {
            if ($form === '' || isset($seen[$form])) {
                return;
            }

            $seen[$form] = true;
            $out[] = [$form, $possessive];
        };

        foreach ($bases as $base) {
            $push($base, false);
        }

        foreach ($bases as $base) {
            foreach (self::SUFFIXES as [$suffix, $replacement, $possessive]) {
                $length = mb_strlen($suffix);

                if (mb_strlen($base) - $length >= 3 && str_ends_with($base, $suffix)) {
                    $stem = mb_substr($base, 0, mb_strlen($base) - $length);
                    $push($stem.$replacement, $possessive);
                    // لاحقتان متتاليتان شائعتان: «ات»+ضمير، «ين»+ضمير ...
                    foreach (self::SUFFIXES as [$suffix2, $replacement2, $possessive2]) {
                        $length2 = mb_strlen($suffix2);

                        if ($suffix2 !== $suffix && mb_strlen($stem) - $length2 >= 3 && str_ends_with($stem, $suffix2) && in_array($suffix, ['ي', 'ك', 'ه', 'ها', 'هم', 'كم', 'نا', 'ني'], true)) {
                            $push(mb_substr($stem, 0, mb_strlen($stem) - $length2).$replacement2, $possessive || $possessive2);
                        }
                    }
                }
            }
        }

        return $out;
    }

    /**
     * صيغ بعد تجريد السوابق فقط (بلا لواحق): تُستعمل لمطابقة العبارات.
     *
     * @return list<string>
     */
    public static function bases(string $token): array
    {
        $bases = [$token];
        $add = function (string $form) use (&$bases): void {
            if ($form !== '' && ! in_array($form, $bases, true)) {
                $bases[] = $form;
            }
        };

        foreach ($bases as $b) {
            if (mb_strlen($b) >= 4 && (str_starts_with($b, 'و') || str_starts_with($b, 'ف'))) {
                $add(mb_substr($b, 1));
            }
        }

        foreach ($bases as $b) {
            if (str_starts_with($b, 'هال') && mb_strlen($b) >= 5) {
                $add(mb_substr($b, 1));            // هالمنصة ← المنصة
                $add(mb_substr($b, 3));            // ← منصة
            }
        }

        foreach ($bases as $b) {
            if (str_starts_with($b, 'لل') && mb_strlen($b) >= 5) {
                $add('ل'.mb_substr($b, 2));
            }
        }

        foreach ($bases as $b) {
            if (mb_strlen($b) >= 4 && (str_starts_with($b, 'ب') || str_starts_with($b, 'ك') || str_starts_with($b, 'ل') || str_starts_with($b, 'س'))) {
                $add(mb_substr($b, 1));
            }
        }

        foreach ($bases as $b) {
            if (mb_strlen($b) >= 5 && str_starts_with($b, 'ال')) {
                $add(mb_substr($b, 2));
            }
        }

        return $bases;
    }
}
