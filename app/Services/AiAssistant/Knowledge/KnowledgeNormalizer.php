<?php

namespace App\Services\AiAssistant\Knowledge;

use App\Services\Search\TextFold;

/**
 * E45 - تطبيع نص المعرفة وفحصه قبل النشر.
 *
 * normalize: نص عادي فقط (لا HTML ولا أكواد تحكم ولا فواصل اتجاه خفية) بأسطر موحّدة.
 * scan: يرصد أي محتوى **لا يجوز أن يصل لنموذج موجَّه للعملاء**: أسرار ومفاتيح، حقول إجابات الأحجيات وأسماء أعمدتها، متغيرات بيئة، أوامر تشغيل، SQL، بيانات اتصال شخصية،
 * وروابط خارجية (الروابط يبنيها الخادم فقط). النتيجة رموز مغلقة؛ أي رمز يمنع النشر (والفحص يتكرر عند كل إعادة بناء).
 * هذا حاجز دفاعي إضافي فوق «المعرفة منقّحة يدويًا»، وليس بديلًا عن المراجعة البشرية.
 */
final class KnowledgeNormalizer
{
    /** @var array<string, string> رمز ⇒ نمط (على النص المطوي أو الخام). */
    private const RULES = [
        'secret_key' => '/\b(?:sk|rk|pk)[-_](?:live|test|proj)?[-_]?[A-Za-z0-9]{16,}|\bAKIA[0-9A-Z]{16}\b|-----BEGIN [A-Z ]*PRIVATE KEY-----|\beyJ[A-Za-z0-9_-]{10,}\.eyJ/',
        'credential_assignment' => '/\b(?:password|passwd|secret|api[_-]?key|token|authorization)\s*[:=]\s*\S+/i',
        'env_variable' => '/\b(?:APP_KEY|DB_PASSWORD|DB_USERNAME|OPENAI_API_KEY|REVERB_APP_SECRET|MAIL_PASSWORD|AWS_SECRET[A-Z_]*|[A-Z][A-Z0-9]+_(?:SECRET|PASSWORD|API_KEY))\b|\.env\b/',
        'puzzle_secret_field' => '/\b(?:answer_hash|answer_raw|answer_normalized|solution_data|game_config|correct_option|hidden_choices)\b/i',
        'operations_command' => '/\bphp\s+artisan\b|\bsudo\b|\bsupervisorctl\b|\bnginx\b|\bcomposer\s+(?:install|update)\b|\bdocker\b/i',
        'sql' => '/\b(?:select\s+.+\s+from|insert\s+into|delete\s+from|drop\s+table|update\s+\w+\s+set)\b/is',
        'email_address' => '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/',
        'external_url' => '/https?:\/\/|\bwww\.[a-z0-9\-]+\./i',
        'internal_admin' => '/\/admin\b|\bfilament\b|\bsuper[_ -]?admin\b/i',
        'html_or_script' => '/<\s*(?:script|iframe|object|embed|style|img|svg|form|a)\b|javascript:|on(?:error|load|click)\s*=/i',
    ];

    public static function normalize(string $text): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';
        $text = preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', '', $text) ?? '';
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? '';
        $text = preg_replace('/ *\n */u', "\n", $text) ?? '';
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? '';

        return trim($text);
    }

    /**
     * @return list<string> رموز المخالفات (فارغة = نظيف)
     */
    public static function scan(string ...$texts): array
    {
        $violations = [];

        foreach ($texts as $text) {
            // النص الخام قبل التطبيع للكشف عن وسوم HTML نفسها؛ ثم الخام للباقي.
            foreach (self::RULES as $code => $pattern) {
                if (preg_match($pattern, $text) === 1) {
                    $violations[$code] = $code;
                }
            }

            $folded = TextFold::fold($text);

            if ($folded !== '' && preg_match('/(?:كلمة\s*(?:مرور|سر)|رمز\s*(?:سري|الوصول)|مفتاح\s*(?:التشفير|api))\s*[:=]/u', $text) === 1) {
                $violations['credential_assignment'] = 'credential_assignment';
            }
        }

        return array_values($violations);
    }
}
