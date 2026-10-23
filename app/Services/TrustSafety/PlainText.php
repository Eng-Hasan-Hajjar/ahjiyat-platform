<?php

namespace App\Services\TrustSafety;

/**
 * E38 - تنقية النصوص الحرة (ملاحظات، أسباب، رسائل للمستخدم، اعتراضات): نص عادي فقط.
 * المرفوض صراحةً (لا يُنظَّف بصمت): وسوم/HTML. الأحرف التحكمية تُزال، والفراغات تُطبَّع، والطول محدود. الإخراج يُهرَّب عند العرض (Blade {{ }}) دائمًا.
 */
final class PlainText
{
    public static function clean(?string $text, int $min, int $max, string $label = 'النص'): string
    {
        $text = (string) $text;
        $text = preg_replace('/[^\P{C}\n]+/u', '', $text) ?? '';
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace("/[ \t]+/u", ' ', $text) ?? '';
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? '';
        $text = trim($text);

        if ($text !== strip_tags($text)) {
            throw new ModerationException($label.' نص عادي فقط (بلا وسوم أو HTML).');
        }

        $length = mb_strlen($text);

        if ($length < $min || $length > $max) {
            throw new ModerationException('اكتب '.$label.' بين '.$min.' و'.$max.' حرفًا.');
        }

        return $text;
    }

    /** نص اختياري: فارغ ← null؛ وإلا يخضع للقيود نفسها. */
    public static function optional(?string $text, int $max, string $label = 'النص'): ?string
    {
        if (blank($text)) {
            return null;
        }

        return self::clean($text, 1, $max, $label);
    }

    /** اقتطاع آمن لعرض مقتطف (لا يرفض شيئًا؛ للعرض الداخلي فقط). */
    public static function excerpt(?string $text, int $max): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string) $text) ?? '');

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1).'…' : $text;
    }
}
