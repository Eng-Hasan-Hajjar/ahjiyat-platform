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
 *
 * TI-Q1 - المرحلة الثانية (احتياطية، بعد فشل الأنماط الصارمة أعلاه): تصحيح بمسافة تحريرية واحدة (Damerau-Levenshtein = 1) بقيود إضافية:
 *   طول الكلمة ≥ 5، أول حرف متطابق، الاستبدال فقط بين حرفين متجاورين على لوحة المفاتيح العربية أو متشابهين، والمرشَّح وحيد المفهوم،
 *   ومن مفاهيم غير حساسة، ولا تُصحَّح كلمة شائعة صحيحة (COMMON_VALID) إلى كلمة المعجم. تبقى ضمانات الأمان كما هي (إعادة فحص الحراس على النص المصحَّح).
 */
final class SpellCorrector
{
    /** أزواج حروف متشابهة نطقًا/رسمًا. */
    private const CONFUSABLE = [['ض', 'ظ'], ['س', 'ص'], ['ت', 'ط'], ['ك', 'ق'], ['غ', 'ع'], ['ح', 'خ'], ['ذ', 'ز'], ['ث', 'س'], ['د', 'ض']];

    private const MEDIAL = ['ا', 'و', 'ي'];

    /** أحرف متجاورة على لوحة المفاتيح العربية (استبدال خطأ كتابة بالضغط على الزر المجاور). */
    private const ADJACENT = [
        ['ض', 'ص'], ['ص', 'ث'], ['ث', 'ق'], ['ق', 'ف'], ['ف', 'غ'], ['غ', 'ع'], ['ع', 'ه'], ['ه', 'خ'], ['خ', 'ح'], ['ح', 'ج'], ['ج', 'د'],
        ['ش', 'س'], ['س', 'ي'], ['ي', 'ب'], ['ب', 'ل'], ['ل', 'ا'], ['ا', 'ت'], ['ت', 'ن'], ['ن', 'م'], ['م', 'ك'], ['ك', 'ط'],
        ['ئ', 'ء'], ['ء', 'ؤ'], ['ؤ', 'ر'], ['ر', 'ل'], ['ل', 'ى'], ['ى', 'ة'], ['ة', 'و'], ['و', 'ز'], ['ز', 'ظ'],
        ['ي', 'ى'], ['ه', 'ة'], ['ا', 'ى'],
    ];

    /** كلمات عربية صحيحة شائعة قريبة (مسافة 1) من كلمات المعجم: لا تُصحَّح أبدًا (تفاديًا لتحويل كلمة سليمة إلى مفهوم آخر). */
    private const COMMON_VALID = ['تعليمات', 'معلومات', 'مساعده', 'طريق', 'حقيقه', 'سريع', 'كبير', 'صغير', 'جديد', 'قديم', 'قريب', 'بعيد', 'سهل', 'صعب', 'مهم', 'مهمه', 'اكثر', 'اقل', 'احسن', 'افضل', 'اسهل', 'اصعب', 'مباشر', 'مباشره', 'بسيط', 'بسيطه', 'ضروري', 'موجود', 'موجوده', 'مطلوب', 'مطلوبه', 'جهاز', 'الجهاز', 'اجهزه', 'الاجهزه', 'العاب', 'الاعاب', 'لعبه', 'اللعبه', 'العبه', 'اللاعب', 'لاعب', 'لاعبين', 'اللاعبين', 'الجاهز', 'جاهز', 'المباشر', 'المباشره'];

    /** @var array<int, list<array{0: string, 1: string}>>|null طول ⇒ [(كلمة، مفهوم)] */
    private static ?array $byLength = null;

    /** يعيد الكلمة المصحَّحة (بصيغة معجمية) أو null. */
    public static function correct(string $token, bool $loose = false): ?string
    {
        $length = mb_strlen($token);

        if ($length < 5 || preg_match('/^[\p{Arabic}]+$/u', $token) !== 1) {
            return null;
        }

        self::boot();

        // كلمة صحيحة شائعة (مع تعريفها أو بدونه) لا تُصحَّح في أي مرحلة.
        if (in_array($token, self::COMMON_VALID, true) || (str_starts_with($token, 'ال') && in_array(mb_substr($token, 2), self::COMMON_VALID, true))) {
            return null;
        }

        // (1) الأنماط الصارمة الأصلية (طول ≥ 6) - سلوكها لم يتغيّر.
        if ($length >= 6) {
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

            if ($found !== []) {
                return null;      // غموض صريح بين مفاهيم: لا نواصل إلى المرحلة التالية.
            }
        }

        // (2) TI-Q1: مسافة تحريرية واحدة بقيود مشدّدة؛ لا تعمل إلا عند الطلب الصريح (المحاولة الثانية في LocalEngine، وتُقبل نتيجتها بشرط زيادة الدليل).
        if (! $loose || in_array($token, self::COMMON_VALID, true)) {
            return null;
        }

        $found = [];

        foreach ([$length - 1, $length, $length + 1] as $l) {
            foreach (self::$byLength[$l] ?? [] as [$word, $concept]) {
                if (self::withinOne($token, $word)) {
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

    /** هل $a على مسافة تحريرية واحدة من $b (إدراج/حذف/استبدال بمجاور/تبديل متجاورين) مع تطابق الحرف الأول؟ */
    private static function withinOne(string $a, string $b): bool
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

        if (abs($lx - $ly) > 1) {
            return false;
        }

        $i = 0;
        $min = min($lx, $ly);

        while ($i < $min && $x[$i] === $y[$i]) {
            $i++;
        }

        if ($lx === $ly) {
            if (array_slice($x, $i + 1) === array_slice($y, $i + 1)) {
                return self::adjacent($x[$i], $y[$i]);       // استبدال حرف واحد
            }

            return $i + 1 < $lx && $x[$i] === $y[$i + 1] && $x[$i + 1] === $y[$i] && array_slice($x, $i + 2) === array_slice($y, $i + 2);   // تبديل
        }

        // إدراج/حذف حرف واحد (الأطول يزيد حرفًا).
        [$long, $short] = $lx > $ly ? [$x, $y] : [$y, $x];

        return array_slice($long, $i + 1) === array_slice($short, $i);
    }

    private static function adjacent(string $p, string $q): bool
    {
        foreach (array_merge(self::ADJACENT, self::CONFUSABLE) as [$m, $n]) {
            if (($p === $m && $q === $n) || ($p === $n && $q === $m)) {
                return true;
            }
        }

        return false;
    }

    private static function boot(): void
    {
        if (self::$byLength !== null) {
            return;
        }

        self::$byLength = [];

        foreach (Lexicon::singles() as $word => $concept) {
            if (in_array($concept, Lexicon::SENSITIVE, true) || mb_strlen($word) < 4 || preg_match('/^[\p{Arabic}]+$/u', $word) !== 1) {
                continue;
            }

            self::$byLength[mb_strlen($word)][] = [$word, $concept];

            // صيغة التعريف: أخطاء الكتابة كثيرًا ما تقع عند «ال» (اجلواهر، املسار) فيجب أن يكون الهدف «الجواهر» لا «جواهر» فقط.
            if (! str_starts_with($word, 'ال')) {
                self::$byLength[mb_strlen($word) + 2][] = ['ال'.$word, $concept];
            }
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
