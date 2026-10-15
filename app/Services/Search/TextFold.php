<?php

namespace App\Services\Search;

use InvalidArgumentException;

/**
 * E27: مصدر واحد لتطبيع نص البحث (للمُدخَل بـPHP ولطيّ العمود بـSQL) كي لا ينحرفا.
 *
 * على جانب PHP (المُدخَل): NFKC إن توفّرت intl، حذف المحارف غير المرئية (صفر العرض، اتجاه، BOM) وتحويل المسافات/التحكم لمسافة واحدة، أحرف صغيرة،
 * حذف التطويل والتشكيل، توحيد الألف (أ إ آ ٱ ← ا) والألف المقصورة (ى ← ي) والتاء المربوطة (ة ← ه) والهمزة على واو/ياء، توحيد الأرقام العربية/الفارسية إلى ASCII،
 * وتوحيد الحرف التركي ı/İ إلى i.
 *
 * على جانب SQL (العمود): نفس التحويلات الحرفية المتاحة بـREPLACE متداخل على LOWER(العمود)، وتُستعمل **فقط** داخل طبقة المطابقة الجزئية المحدودة بحجم الجدول
 * (انظر config/search.php: partial_match_max_id). الثوابت حرفية داخل الكود؛ المعرّف (اسم العمود) يُتحقَّق منه بنمط صارم ولا يصل إليه أي مُدخَل مستخدم.
 */
final class TextFold
{
    /** @var array<string, string> */
    private const LETTER_MAP = [
        "\u{0623}" => "\u{0627}", // أ → ا
        "\u{0625}" => "\u{0627}", // إ → ا
        "\u{0622}" => "\u{0627}", // آ → ا
        "\u{0671}" => "\u{0627}", // ٱ → ا
        "\u{0649}" => "\u{064A}", // ى → ي
        "\u{0629}" => "\u{0647}", // ة → ه
        "\u{0624}" => "\u{0648}", // ؤ → و
        "\u{0626}" => "\u{064A}", // ئ → ي
        "\u{0131}" => 'i',        // ı → i
    ];

    /** تطويل + تشكيل شائع (فتحتان…سكون) + ألف خنجرية: يُحذف على جانبي PHP وSQL. */
    private const STRIP = ["\u{0640}", "\u{064B}", "\u{064C}", "\u{064D}", "\u{064E}", "\u{064F}", "\u{0650}", "\u{0651}", "\u{0652}", "\u{0670}"];

    /** @var array<string, string> */
    private const DIGITS = [
        "\u{0660}" => '0', "\u{0661}" => '1', "\u{0662}" => '2', "\u{0663}" => '3', "\u{0664}" => '4', "\u{0665}" => '5', "\u{0666}" => '6', "\u{0667}" => '7', "\u{0668}" => '8', "\u{0669}" => '9',
        "\u{06F0}" => '0', "\u{06F1}" => '1', "\u{06F2}" => '2', "\u{06F3}" => '3', "\u{06F4}" => '4', "\u{06F5}" => '5', "\u{06F6}" => '6', "\u{06F7}" => '7', "\u{06F8}" => '8', "\u{06F9}" => '9',
    ];

    /** يحوّل نصًا إلى شكله المطوي. نص غير صالح UTF-8 ← نص فارغ (يُعامَل كقصير جدًا). */
    public static function fold(?string $text): string
    {
        $s = (string) $text;

        if ($s === '' || ! mb_check_encoding($s, 'UTF-8')) {
            return '';
        }

        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($s, \Normalizer::FORM_KC);
            if (is_string($normalized)) {
                $s = $normalized;
            }
        }

        $s = str_replace("\u{0130}", 'i', $s);                                             // İ قبل خفض الحالة (وإلا تصير i + نقطة)
        $s = str_replace("\u{200B}", ' ', $s);                                              // مسافة صفرية العرض = فاصل كلمات (لا تُلصق الكلمتين)
        $s = preg_replace('/\p{Cf}/u', '', $s) ?? '';                                       // ZWJ/ZWNJ، علامات الاتجاه، BOM
        $s = preg_replace('/[\p{Cc}\p{Z}]+/u', ' ', $s) ?? '';                              // تحكم + أي فاصل ← مسافة
        $s = mb_strtolower($s, 'UTF-8');
        $s = preg_replace('/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}\x{0640}\x{0307}]/u', '', $s) ?? '';
        $s = strtr($s, self::LETTER_MAP);
        $s = strtr($s, self::DIGITS);

        return trim(preg_replace('/\s+/u', ' ', $s) ?? '');
    }

    /** يهرب رموز LIKE (% _ ومحرف الهروب !) فتصير حرفية. يُستعمل مع `escape '!'` دائمًا. */
    public static function escapeLike(string $text): string
    {
        return strtr($text, ['!' => '!!', '%' => '!%', '_' => '!_']);
    }

    /**
     * تعبير SQL يطوي العمود بنفس تحويلات الحروف (بلا الأرقام ولا NFKC). المعرّف **يجب** أن يطابق `table.column` أو `column` بأحرف صغيرة/أرقام/شرطة سفلية فقط.
     */
    public static function sqlFold(string $identifier): string
    {
        $expression = 'LOWER('.self::identifier($identifier).')';

        foreach (self::STRIP as $char) {
            $expression = "REPLACE({$expression}, '{$char}', '')";
        }

        foreach (self::LETTER_MAP as $from => $to) {
            $expression = "REPLACE({$expression}, '{$from}', '{$to}')";
        }

        return $expression;
    }

    /** يتحقق من معرّف عمود ويعيده كما هو؛ يرمي استثناءً لأي شيء آخر (لا يصل مُدخَل مستخدم هنا أصلًا). */
    public static function identifier(string $identifier): string
    {
        if (! preg_match('/^[a-z_][a-z0-9_]*(\.[a-z_][a-z0-9_]*)?$/', $identifier)) {
            throw new InvalidArgumentException('Invalid SQL identifier.');
        }

        return $identifier;
    }
}
