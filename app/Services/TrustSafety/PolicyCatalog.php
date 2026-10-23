<?php

namespace App\Services\TrustSafety;

use App\Models\ModerationCase;
use Carbon\CarbonInterface;

/**
 * E38 - تصنيف السياسات وأهداف التشغيل (كلها قراءة من config/trust_safety.php؛ لا حالة ولا كتابة).
 *  - خريطة الأسباب التاريخية ← تصنيف السياسة (لا إعادة كتابة للقيم الأصلية).
 *  - أولوية **مقترحة** حتمية من التصنيف (أو من severity لإشارات النظام). لا تدخل فيها الشهرة/السمعة/الدعم/عدد البلاغات.
 *  - أهداف SLA داخلية مقترحة (ليست وعدًا للمستخدم).
 */
final class PolicyCatalog
{
    /** @return array<string,string> مفتاح ← تسمية */
    public static function categories(): array
    {
        return array_map(fn ($c) => (string) $c['label'], (array) config('trust_safety.categories'));
    }

    public static function categoryLabel(?string $key): string
    {
        return (string) (config('trust_safety.categories.'.$key.'.label') ?? config('trust_safety.categories.other.label'));
    }

    public static function normalizeCategory(string $sourceType, ?string $reason, ?string $severity = null): string
    {
        if ($sourceType === 'fraud_flag') {
            return 'fraud_or_abuse';
        }

        $category = config('trust_safety.reason_map.'.$sourceType.'.'.$reason);

        return is_string($category) && self::isCategory($category) ? $category : 'other';
    }

    public static function isCategory(?string $key): bool
    {
        return is_string($key) && array_key_exists($key, (array) config('trust_safety.categories'));
    }

    /** أولوية مقترحة. severity تخص إشارات النظام فقط. */
    public static function suggestPriority(string $category, ?string $severity = null): string
    {
        if ($severity !== null && $severity !== '') {
            $mapped = config('trust_safety.fraud_severity_priority.'.$severity);

            if (is_string($mapped) && array_key_exists($mapped, ModerationCase::PRIORITIES)) {
                return $mapped;
            }
        }

        $priority = (string) config('trust_safety.categories.'.$category.'.priority', 'normal');

        return array_key_exists($priority, ModerationCase::PRIORITIES) ? $priority : 'normal';
    }

    /** أسباب المصدر الخام التي تُحلّ إلى أحد هذه التصنيفات (لترشيح SQL دون نسخ أي شيء). @param list<string> $categories @return list<string> */
    public static function reasonsForCategories(string $sourceType, array $categories): array
    {
        $map = (array) config('trust_safety.reason_map.'.$sourceType, []);

        return array_values(array_keys(array_filter($map, fn ($category) => in_array($category, $categories, true))));
    }

    /** أسباب المصدر الخام التي أولويتها المقترحة هي $priority. @return list<string> */
    public static function reasonsForPriority(string $sourceType, string $priority): array
    {
        $categories = array_keys(array_filter((array) config('trust_safety.categories'), fn ($c) => ($c['priority'] ?? 'normal') === $priority));

        return self::reasonsForCategories($sourceType, $categories);
    }

    /** أولوية 'other' للأسباب غير المعروفة بالخريطة (تُحلّ لـother) */
    public static function otherPriority(): string
    {
        return (string) config('trust_safety.categories.other.priority', 'normal');
    }

    /** @return array{first_response_due_at: CarbonInterface, resolution_due_at: CarbonInterface} */
    public static function slaDue(string $priority, CarbonInterface $from): array
    {
        $priority = array_key_exists($priority, ModerationCase::PRIORITIES) ? $priority : 'normal';

        return [
            'first_response_due_at' => $from->copy()->addHours((int) config('trust_safety.sla.first_response_hours.'.$priority, 48)),
            'resolution_due_at' => $from->copy()->addHours((int) config('trust_safety.sla.resolution_hours.'.$priority, 168)),
        ];
    }

    /** @return array<string,mixed> */
    public static function action(string $key): array
    {
        $action = config('trust_safety.actions.'.$key);

        if (! is_array($action)) {
            throw new ModerationException('إجراء غير مسموح.');
        }

        return $action;
    }

    public static function isAction(?string $key): bool
    {
        return is_string($key) && is_array(config('trust_safety.actions.'.$key));
    }

    public static function policyVersion(): int
    {
        return (int) config('trust_safety.policy_version', 1);
    }
}
