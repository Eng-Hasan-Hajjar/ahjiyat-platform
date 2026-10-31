<?php

namespace App\Services\AiAssistant\Tools;

use App\Services\AiAssistant\Safety\AiLinkPolicy;
use App\Services\AiAssistant\Knowledge\KnowledgeNormalizer;
use Carbon\CarbonInterface;

/**
 * E45 - أدوات عرض مشتركة للعارضات (presenters). كل نص قادم من محتوى مستخدمين أو إداريين يمرّ من text(): نص عادي مقصوص بلا وسوم ولا أكواد تحكم.
 * لا يُسلسَل نموذج Eloquent أبدًا: كل أداة تبني مصفوفتها بحقول صريحة.
 */
final class Presenter
{
    public static function text(?string $value, int $max = 200, bool $multiline = false): ?string
    {
        if ($value === null) {
            return null;
        }

        $clean = KnowledgeNormalizer::normalize($value);
        $clean = AiLinkPolicy::neutralize($clean);
        $clean = $multiline
            ? trim(preg_replace("/[ \t]+/u", ' ', $clean) ?? '')
            : trim(preg_replace('/\s+/u', ' ', $clean) ?? '');

        if ($clean === '') {
            return null;
        }

        return mb_strlen($clean) > $max ? rtrim(mb_substr($clean, 0, $max)).'…' : $clean;
    }

    /** تاريخ/وقت للعرض بالتوقيت المحلي للتطبيق، أو null. */
    public static function date(?CarbonInterface $date, bool $withTime = true): ?string
    {
        return $date?->copy()->timezone((string) config('app.timezone', 'UTC'))->format($withTime ? 'Y-m-d H:i' : 'Y-m-d');
    }

    /** @return list<array{label: string, url: string}> */
    public static function sources(?array ...$sources): array
    {
        $out = [];

        foreach ($sources as $source) {
            if (is_array($source) && isset($source['label'], $source['url'])) {
                $out[$source['url']] = $source;
            }
        }

        return array_values($out);
    }

    /** مصدر داخلي آمن من تسمية ومسار نسبي، أو null. @return array{label: string, url: string}|null */
    public static function link(string $label, ?string $path): ?array
    {
        return AiLinkPolicy::source($label, $path);
    }

    /** مسار نسبي لمسار مسمّى (لا مضيف). */
    public static function path(string $routeName, mixed $parameters = []): ?string
    {
        try {
            $url = route($routeName, $parameters, false);
        } catch (\Throwable) {
            return null;
        }

        return AiLinkPolicy::isInternalPath($url) ? $url : null;
    }
}
