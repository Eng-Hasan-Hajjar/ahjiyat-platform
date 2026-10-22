<?php

namespace App\Services\Discussions;

use App\Rules\NoMarkup;

/**
 * E36 - تطبيع نص التعليق والتحقق منه (نص عادي فقط). التطبيع **لا يمسّ النص العربي**: لا حذف لـZWNJ/ZWJ (مشروعة في العربية/الفارسية) ولا تغيير للحروف.
 *  - توحيد نهايات الأسطر إلى \n، ودمج تكرار المسافات/التبويب/NBSP داخل السطر إلى مسافة واحدة، وقصّ المسافات حول الأسطر، وبحدّ أقصى سطر فارغ واحد، وقصّ الأطراف.
 *  - الرفض (لا التنظيف الصامت): أي وسم HTML/XML/تعليق، أي محرف تحكّم أو تحكّم اتجاهي مخادع (القاعدة المشتركة NoMarkup لمحتوى المستخدمين)،
 *    ترميز غير صالح، أقل/أكثر من الحدّ، أو نص بلا أي حرف/رقم (ضجيج). لا Markdown ولا روابط قابلة للنقر: العرض نص مهرَّب فقط.
 */
final class DiscussionBody
{
    public const HARD_MAX = 1000;

    public static function normalize(string $raw): string
    {
        $s = str_replace(["\r\n", "\r"], "\n", $raw);
        $s = preg_replace('/[ \t\x{00A0}\x{3000}]+/u', ' ', $s) ?? $s;
        $s = preg_replace('/ *\n */u', "\n", $s) ?? $s;
        $s = preg_replace('/\n{3,}/', "\n\n", $s) ?? $s;

        return trim($s);
    }

    /** @throws DiscussionException */
    public static function prepare(string $raw): string
    {
        if (! mb_check_encoding($raw, 'UTF-8')) {
            throw new DiscussionException('النص غير صالح.');
        }

        $body = self::normalize($raw);
        $min = max(1, (int) config('discussions.body.min'));
        $max = min(self::HARD_MAX, max($min, (int) config('discussions.body.max')));
        $length = mb_strlen($body);

        if ($length < $min) {
            throw new DiscussionException('اكتب تعليقًا لا يقل عن '.$min.' أحرف.');
        }

        if ($length > $max) {
            throw new DiscussionException('التعليق طويل جدًا (الحدّ '.$max.' حرفًا).');
        }

        $rejected = false;
        (new NoMarkup)->validate('body', $body, function () use (&$rejected) {
            $rejected = true;
        });

        if ($rejected) {
            throw new DiscussionException('لا يُسمح بالوسوم أو الأكواد أو المحارف الخاصة في التعليق؛ اكتب نصًا عاديًا فقط.');
        }

        if (preg_match('/[\p{L}\p{N}]/u', $body) !== 1) {
            throw new DiscussionException('اكتب تعليقًا مفهومًا.');
        }

        return $body;
    }
}
