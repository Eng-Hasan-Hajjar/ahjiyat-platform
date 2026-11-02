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
        ['ون', '', false], ['ين', '', false], ['ان', '', false], ['ات', 'ه', false], ['ات', '', false], ['وا', '', false],
        ['ي', '', true], ['ك', '', false], ['ه', '', false], ['ا', '', false], ['ت', '', false],
    ];

    /** بادئات المضارعة (ي/ت/ن/أ): المعجم يسرد صيغة واحدة غالبًا فنولّد البقية عند عدم التطابق (TI-Q1). */
    private const IMPERFECT = ['ي', 'ت', 'ن', 'ا'];

    /** صيغ غائب لأفعال القدرة/الفعل لا تُولَّد من صيغة المتكلم. */
    private const ABILITY_THIRD_PERSON = ['يقدر', 'تقدر', 'يستطيع', 'تستطيع', 'يقدم', 'تقدم', 'يفعل', 'تفعل', 'يسوي', 'تسوي', 'يفعله', 'تفعله', 'يعرف', 'تعرف'];

    /** بادئات المبني للمجهول العامّي (ينفتح، تنصرف، انحسبت): تُجرَّد ثم تُعاد بادئة المضارعة. */
    private const PASSIVE = ['ين', 'تن', 'نن', 'ان'];

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

        // TI-Q1: صيغ المضارعة والمبني للمجهول العامّي تأتي أخيرًا (أدنى أسبقية) فلا تغلب مطابقةً حرفية أو لاحقةً معروفة.
        foreach ($out as [$form, $possessive]) {
            foreach (self::verbVariants($form) as $variant) {
                $push($variant, $possessive);
            }
        }

        return $out;
    }

    /**
     * صيغ فعلية بديلة لكلمة: تبديل بادئة المضارعة، وتجريد بادئة المبني للمجهول العامّي. لا تُولَّد لكلمات قصيرة (جذر ≥ 3).
     *
     * @return list<string>
     */
    public static function verbVariants(string $form): array
    {
        $length = mb_strlen($form);
        $out = [];

        if ($length < 5) {
            return $out;
        }

        $first = mb_substr($form, 0, 1);
        $rest = mb_substr($form, 1);

        if (in_array($first, self::IMPERFECT, true)) {
            foreach (self::IMPERFECT as $prefix) {
                if ($prefix !== $first) {
                    $out[] = $prefix.$rest;
                }
            }
        }

        foreach (self::PASSIVE as $prefix) {
            if ($length >= 5 && str_starts_with($form, $prefix)) {
                $stem = mb_substr($form, mb_strlen($prefix));

                if (mb_strlen($stem) >= 3) {
                    $out[] = $stem;

                    foreach (self::IMPERFECT as $p) {
                        $out[] = $p.$stem;
                    }
                }
            }
        }

        // أفعال القدرة/الخدمة بصيغة المتكلم («أستطيع»، «أقدر») تخص السائل لا المنصة: لا تُحوَّل إلى صيغة الغائب فتصير مفهوم «يقدر».
        if (in_array($first, ['ا', 'ن'], true)) {
            $out = array_values(array_diff($out, self::ABILITY_THIRD_PERSON));
        }

        return array_values(array_unique($out));
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
