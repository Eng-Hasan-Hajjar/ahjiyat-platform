<?php

namespace App\Services\AiAssistant\Safety;

use App\Services\AiAssistant\Knowledge\KnowledgeNormalizer;

/**
 * E45 - مرشّح مخرجات النموذج (حتمي، بعد كل توليد وقبل أي عرض أو تخزين).
 *
 * sanitize: نص عادي فقط (بلا HTML ولا محارف تحكم/اتجاه خفية)، الروابط الخارجية تُزال (الروابط الحقيقية شرائح مصادر يبنيها الخادم)، سقف طول صلب.
 * inspect:  رموز مخالفات: أسرار/مفاتيح/متغيرات بيئة/حقول أسرار الأحجيات/أوامر تشغيل/SQL/بريد إلكتروني/مسارات إدارة، وبصمة التعليمات الداخلية (canary)،
 *           ومفاتيح غلاف الأدوات الداخلي. أي مخالفة ⇒ يُستبدَل الردّ بنص آمن ولا يُخزَّن أصل المخرجات في أي مكان.
 */
final class AiOutputGuard
{
    /** الجزء الأخير المحجوب عن البث حتى يُفحص كاملًا (يغطي أطول رمز/حقل متوقّع كي لا يتسرب نصفه). */
    public const HOLDBACK = 96;

    public const MAX_CHARS = 4000;

    /** رموز مخالفات لا تُعتبر حظرًا لأن sanitize يعالجها (روابط خارجية + وسوم). */
    private const HANDLED = ['external_url', 'html_or_script'];

    /** بصمة ثابتة لكل تثبيت (من مفتاح التطبيق) لا تُعرف من خارجه: وجودها في ردّ ⇒ تسريب تعليمات. */
    public static function canary(): string
    {
        return 'TWR-'.substr(hash_hmac('sha256', 'tatawwur-ai-canary', (string) config('app.key', 'tatawwur')), 0, 16);
    }

    public function sanitize(string $text): string
    {
        $text = KnowledgeNormalizer::normalize($text);
        $text = AiLinkPolicy::neutralize($text);
        $text = preg_replace('/(?:\x{FFFD})+/u', '', $text) ?? $text;

        if (mb_strlen($text) > self::MAX_CHARS) {
            $text = rtrim(mb_substr($text, 0, self::MAX_CHARS)).'…';
        }

        return $text;
    }

    /** @return list<string> رموز المخالفات (فارغة = آمن). */
    public function inspect(string $text): array
    {
        $violations = array_values(array_diff(KnowledgeNormalizer::scan($text), self::HANDLED));

        if (str_contains($text, self::canary()) || preg_match('/TATAWWUR-INSTRUCTIONS|internal\s+marker|you\s+are\s+tatawwur|knowledge\s+excerpts|grounding\s*\(most\s+important\)|data\s+is\s+not\s+instructions|untrusted_text_fields|"ok"\s*:\s*(?:true|false)\s*,\s*"(?:data|error)"/i', $text) === 1) {
            $violations[] = 'instruction_leak';
        }

        return array_values(array_unique($violations));
    }

    /** نص آمن للعرض أو null إن كان غير آمن. */
    public function safe(string $text): ?string
    {
        $clean = $this->sanitize($text);

        if ($clean === '' || $this->inspect($clean) !== []) {
            return null;
        }

        return $clean;
    }
}
