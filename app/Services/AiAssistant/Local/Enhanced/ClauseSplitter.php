<?php

namespace App\Services\AiAssistant\Local\Enhanced;

use App\Services\Search\TextFold;

/**
 * TATAWWUR-FINAL - تقسيم السؤال المركّب إلى فقرات استفهامية («كيف أكسب الجواهر وأين أصرفها؟»، «ما الفرق بين الجواهر والخبرة؟»).
 * حتمي ومحافظ: لا يقسّم إلا عند أداة استفهام مسبوقة بواو العطف، أو عند علامة ترقيم فاصلة، أو في نمط «الفرق بين س وص»؛
 * والقرار النهائي (هل هو مركّب فعلًا) يبقى للمصنِّف: لا يُعتمد التقسيم إلا إن أفضت الفقرات إلى نيتين مختلفتين واثقتين.
 */
final class ClauseSplitter
{
    private const INTERROGATIVES = ['كيف', 'ماذا', 'ما', 'متي', 'اين', 'كم', 'هل', 'لماذا', 'ليش', 'وين', 'شو', 'ايش', 'وش', 'كيفاش', 'ازاي', 'علاش', 'شنو', 'امتي', 'فين', 'من'];

    private const REQUEST_VERBS = ['اشرح', 'وضح', 'عرفني', 'اذكر', 'قارن', 'اعرض', 'ابغي', 'اريد', 'بدي', 'ابي', 'قل', 'اخبرني', 'اعطني', 'علمني', 'دلني', 'ممكن'];

    private const MAX_CLAUSES = 3;

    /** @return list<string> فقرات (مطوية) من فقرتين إلى ثلاث، أو قائمة فارغة إن لم يكن مركّبًا. */
    public function split(string $text): array
    {
        $folded = TextFold::fold($text);
        $parts = preg_split('/[؟?،,؛;!\n]+/u', $folded) ?: [];
        $clauses = [];

        foreach ($parts as $part) {
            $tokens = array_values(array_filter(explode(' ', trim(preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $part) ?? '')), fn ($t) => $t !== ''));

            if ($tokens === []) {
                continue;
            }

            $current = [];

            foreach ($tokens as $i => $token) {
                if ($i >= 2 && $this->startsClause($token, $tokens[$i + 1] ?? null) && count($current) >= 2) {
                    $clauses[] = implode(' ', $current);
                    $current = [];
                    $token = $this->stripConjunction($token, $tokens[$i + 1] ?? null);
                }

                if ($token !== '') {
                    $current[] = $token;
                }
            }

            if ($current !== []) {
                $clauses[] = implode(' ', $current);
            }
        }

        $clauses = array_values(array_filter($clauses, fn (string $c) => count(explode(' ', $c)) >= 2));

        // كل فقرة يجب أن تكون سؤالًا أو طلبًا فعلًا (أداة استفهام أو فعل طلب)؛ وإلا فهي جملة واحدة مجزّأة بفاصلة لا سؤال مركّب.
        if (count($clauses) < 2 || array_filter($clauses, fn (string $c) => ! $this->isRequest($c)) !== []) {
            return $this->difference($folded);
        }

        return array_slice($clauses, 0, self::MAX_CLAUSES);
    }

    /** «الفرق بين س وص» ⇒ «ما هو س»، «ما هو ص». @return list<string> */
    private function difference(string $folded): array
    {
        if (preg_match('/بين\s+(.+?)\s+و(\S.*)$/u', $folded, $m) !== 1) {
            return [];
        }

        $left = trim(preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $m[1]) ?? '');
        $right = trim(preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $m[2]) ?? '');

        if ($left === '' || $right === '' || count(explode(' ', $left)) > 3 || count(explode(' ', $right)) > 3) {
            return [];
        }

        return ['ما هو '.$left, 'ما هو '.$right];
    }

    private function isRequest(string $clause): bool
    {
        foreach (explode(' ', $clause) as $token) {
            if (in_array($token, self::INTERROGATIVES, true) || in_array($token, self::REQUEST_VERBS, true)) {
                return true;
            }

            if (mb_strlen($token) > 2 && str_starts_with($token, 'و') && in_array(mb_substr($token, 1), self::INTERROGATIVES, true)) {
                return true;
            }
        }

        return false;
    }

    private function startsClause(string $token, ?string $next): bool
    {
        if ($token === 'و') {
            return $next !== null && in_array($next, self::INTERROGATIVES, true);
        }

        return mb_strlen($token) >= 3 && str_starts_with($token, 'و') && in_array(mb_substr($token, 1), self::INTERROGATIVES, true);
    }

    private function stripConjunction(string $token, ?string $next): string
    {
        return $token === 'و' ? '' : mb_substr($token, 1);
    }
}
