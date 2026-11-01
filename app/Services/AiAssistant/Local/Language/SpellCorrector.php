<?php

namespace App\Services\AiAssistant\Local\Language;

/**
 * TI-2 - مصحّح إملائي **محافظ جدًا**: لا يصحّح بالمسافة التحريرية العامة (فهي تحوّل كلمات صحيحة إلى أخرى: «تعليمات»→«تعليقات»).
 * يصحّح كلمة مجهولة فقط عندما يكون خطؤها من الأنماط الكتابية المعروفة:
 *   (1) تبديل حرفين متجاورين،  (2) تكرار حرف زائد أو نقصه،  (3) استبدال حرف بحرف مشابه نطقًا/رسمًا (ض↔ظ، س↔ص، ت↔ط، ك↔ق، غ↔ع، ح↔خ، ذ↔ز)،
 *   (4) سقوط حرف مدّ (ا/و/ي) أو زيادته،
 * بشروط: طول الكلمة ≥6، أول حرف متطابق، الكلمة المعجمية الهدف ≥5 أحرف وحيدة المفهوم، وألّا تكون كلمة شائعة صحيحة معروفة.
 *
 * ضمانات الأمان:
 *  - الهدف لا يكون أبدًا من المفاهيم الحساسة (Lexicon::SENSITIVE): خطأ إملائي بريء لا يتحوّل إلى أمر مالي/أمني ولا يخدم تفادي حارس أسرار الأحجيات.
 *  - التصحيح داخلي للتحليل فقط؛ لا يُعرَض للمستخدم، ويُعاد فحص الحراس على النص المصحَّح (LocalEngine) قبل أي إجابة.
 *  - غموض (أكثر من مفهوم مرشَّح) ⇒ لا تصحيح.
 */
final class SpellCorrector
{
    /** أزواج حروف متشابهة نطقًا/رسمًا. */
    private const CONFUSABLE = [['ض', 'ظ'], ['س', 'ص'], ['ت', 'ط'], ['ك', 'ق'], ['غ', 'ع'], ['ح', 'خ'], ['ذ', 'ز'], ['ث', 'س'], ['د', 'ض']];

    private const MEDIAL = ['ا', 'و', 'ي'];

    /** @var array<int, list<array{0: string, 1: string}>>|null طول ⇒ [(كلمة، مفهوم)] */
    private static ?array $byLength = null;

    /** يعيد الكلمة المصحَّحة (بصيغة معجمية) أو null. */
    public static function correct(string $token): ?string
    {
        $length = mb_strlen($token);

        if ($length < 6 || preg_match('/^[\p{Arabic}]+$/u', $token) !== 1) {
            return null;
        }

        self::boot();
        $found = [];

        foreach ([$length - 1, $length, $length + 1] as $l) {
            foreach (self::$byLength[$l] ?? [] as [$word, $concept]) {
                if (self::typoOf($token, $word)) {
                    $found[$word] = $concept;

                    if (count($found) > 2) {
                        return null;
                    }
                }
            }
        }

        if ($found !== [] && count(array_unique($found)) === 1) {
            return (string) array_key_first($found);
        }

        return null;
    }

    private static function boot(): void
    {
        if (self::$byLength !== null) {
            return;
        }

        self::$byLength = [];

        foreach (Lexicon::singles() as $word => $concept) {
            if (in_array($concept, Lexicon::SENSITIVE, true) || mb_strlen($word) < 5 || preg_match('/^[\p{Arabic}]+$/u', $word) !== 1) {
                continue;
            }

            self::$byLength[mb_strlen($word)][] = [$word, $concept];
        }
    }

    /** هل $a خطأ كتابي محتمل لـ$b بأحد الأنماط المسموحة فقط؟ */
    private static function typoOf(string $a, string $b): bool
    {
        if ($a === $b) {
            return false;
        }

        $x = mb_str_split($a);
        $y = mb_str_split($b);

        if ($x[0] !== $y[0]) {
            return false;
        }

        $lx = count($x);
        $ly = count($y);

        if ($lx === $ly) {
            $i = 0;

            while ($i < $lx && $x[$i] === $y[$i]) {
                $i++;
            }

            // تبديل متجاورين
            if ($i + 1 < $lx && $x[$i] === $y[$i + 1] && $x[$i + 1] === $y[$i] && array_slice($x, $i + 2) === array_slice($y, $i + 2)) {
                return true;
            }

            // استبدال بحرف مشابه
            if (array_slice($x, $i + 1) === array_slice($y, $i + 1)) {
                foreach (self::CONFUSABLE as [$p, $q]) {
                    if (($x[$i] === $p && $y[$i] === $q) || ($x[$i] === $q && $y[$i] === $p)) {
                        return true;
                    }
                }
            }

            return false;
        }

        // حرف زائد/ناقص: مكرَّر أو حرف مدّ فقط.
        [$long, $short] = $lx > $ly ? [$x, $y] : [$y, $x];
        $i = 0;

        while ($i < count($short) && $long[$i] === $short[$i]) {
            $i++;
        }

        if (array_slice($long, $i + 1) !== array_slice($short, $i)) {
            return false;
        }

        $extra = $long[$i];
        $dup = ($i > 0 && $long[$i - 1] === $extra) || (isset($long[$i + 1]) && $long[$i + 1] === $extra);

        return $dup || in_array($extra, self::MEDIAL, true);
    }
}
