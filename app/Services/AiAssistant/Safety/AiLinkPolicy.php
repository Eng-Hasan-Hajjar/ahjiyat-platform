<?php

namespace App\Services\AiAssistant\Safety;

/**
 * E45 - سياسة الروابط: الروابط التي تصل للمستخدم داخلية نسبية فقط ويبنيها الخادم (الأدوات/المقالات). النموذج لا يختار مضيفًا ولا رابطًا.
 * مسار مقبول: يبدأ بـ«/» مرة واحدة، أحرف آمنة فقط، بلا «//» ولا «..» ولا «\» ولا مخطط ولا محارف تحكم. الاستعلام/المرساة (?#) مسموحان بأحرف آمنة محدودة.
 */
final class AiLinkPolicy
{
    public static function isInternalPath(?string $path): bool
    {
        if ($path === null || $path === '' || strlen($path) > 190 || $path[0] !== '/') {
            return false;
        }

        if (str_contains($path, '//') || str_contains($path, '..') || str_contains($path, '\\') || preg_match('/[\x00-\x20\x7F]/', $path) === 1) {
            return false;
        }

        return preg_match('~^/[A-Za-z0-9._\-/]*(?:\?[A-Za-z0-9=&._\-%]*)?(?:#[A-Za-z0-9_\-]*)?$~', $path) === 1;
    }

    /** مصدر عرض آمن {label,url}، أو null إن كان الرابط غير داخلي. @return array{label: string, url: string}|null */
    public static function source(string $label, ?string $path): ?array
    {
        $label = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', strip_tags($label)) ?? '');

        if ($label === '' || ! self::isInternalPath($path)) {
            return null;
        }

        return ['label' => mb_substr($label, 0, 60), 'url' => (string) $path];
    }

    /**
     * يزيل من نص ردّ أي رابط خارجي أو مخطط خطر (يبقى الرابط الداخلي يظهر نصًّا فقط؛ الأزرار الحقيقية هي شرائح المصادر التي يبنيها الخادم).
     */
    public static function neutralize(string $text): string
    {
        $text = preg_replace('/\[([^\]]{0,200})\]\(\s*[^)]{0,500}\)/u', '$1', $text) ?? $text;   // روابط ماركداون: يبقى نصها المرئي فقط
        $text = preg_replace('~(?:https?|ftp|file)\s*:\s*//?\S*~iu', '[رابط محذوف]', $text) ?? $text;
        $text = preg_replace('~\b(?:data|javascript|vbscript|blob)\s*:\s*\S*~iu', '[رابط محذوف]', $text) ?? $text;   // مخططات بلا // (javascript:alert(1))
        $text = preg_replace('~\bwww\.\S+~iu', '[رابط محذوف]', $text) ?? $text;

        return $text;
    }
}
