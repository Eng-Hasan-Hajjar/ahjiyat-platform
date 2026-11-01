<?php

namespace App\Services\AiAssistant\Local;

use App\Services\AiAssistant\Local\Language\Analysis;
use App\Services\AiAssistant\Local\Language\Analyzer;
use App\Services\AiAssistant\Local\Language\Lexicon;
use App\Services\AiAssistant\Safety\AiLinkPolicy;
use App\Services\Search\TextFold;

/**
 * TI-4 - مُركِّب الإجابة: **استخراجي فقط** من أقسام مقال منشور (لا يُولِّد جملًا ولا أرقامًا ولا أسعارًا).
 * يختار الأسطر الأقرب لمفاهيم السؤال ضمن سقف طول، محافظًا على ترتيبها الأصلي، ويبني مصادر داخلية من رابط المقال المنشور.
 */
final class AnswerComposer
{
    public const MAX_CHARS = 560;

    public function __construct(private readonly Analyzer $analyzer = new Analyzer) {}

    /**
     * @param  list<array<string, mixed>>  $entries  مقاطع المقال المنشورة (من KnowledgeIndex)
     * @param  list<string>  $sections  عناوين الأقسام المقصودة
     * @return array{text: string, sources: list<array{label: string, url: string}>, area: string, section: string}|null
     */
    public function compose(array $entries, array $sections, Analysis $question): ?array
    {
        [$lines, $first] = $this->scan($entries, $sections);

        if ($lines === [] || $first === null) {
            return null;
        }

        $text = $this->select($lines, $question);
        $source = AiLinkPolicy::source((string) ($first['label'] ?: $first['title']), $first['url'] ?? null);

        return [
            'text' => $text,
            'sources' => $source === null ? [] : [$source],
            'area' => (string) ($first['area'] ?? ''),
            'section' => (string) ($first['section'] ?? ''),
        ];
    }

    /**
     * TI-Q1: كل أسطر الأقسام المقصودة بلا اقتطاع (لبناء ملف تعريف القسم في SectionPrior). لا تغيّر compose().
     *
     * @param  list<array<string, mixed>>  $entries
     * @param  list<string>  $sections
     * @return list<string>
     */
    public function sectionLines(array $entries, array $sections): array
    {
        return $this->scan($entries, $sections)[0];
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @param  list<string>  $sections
     * @return array{0: list<string>, 1: array<string, mixed>|null}
     */
    private function scan(array $entries, array $sections): array
    {
        $wanted = array_map(fn (string $s) => self::foldTitle($s), $sections);
        $lines = [];
        $first = null;
        $active = false;

        foreach ($entries as $entry) {
            $text = (string) ($entry['text'] ?? '');
            $explicit = self::foldTitle((string) ($entry['section'] ?? ''));
            $isTitle = $explicit === '' || $explicit === self::foldTitle((string) ($entry['title'] ?? ''));

            // قسم صريح من المُقطِّع (عنوان منفصل بسطر فارغ): المقطع كله تابع لهذا القسم.
            if (! $isTitle) {
                $active = in_array($explicit, $wanted, true);

                if ($active) {
                    $first ??= $entry;

                    foreach ($this->lines($text) as $line) {
                        if (self::foldTitle($line) !== $explicit) {
                            $lines[] = $line;
                        }
                    }
                }

                continue;
            }

            // عنوان القسم هو السطر الأول من الفقرة (عنوان ملاصق للنص)؛ الفقرات اللاحقة بلا عنوان تتبع القسم النشط.
            foreach (preg_split('/\n{2,}/u', $text) ?: [] as $paragraph) {
                $raw = array_values(array_filter(array_map('trim', preg_split('/\R/u', $paragraph) ?: []), fn (string $l) => $l !== ''));

                if ($raw === []) {
                    continue;
                }

                if (self::looksLikeHeading($raw)) {
                    $active = in_array(self::foldTitle($raw[0]), $wanted, true);
                    $raw = array_slice($raw, 1);
                }

                if (! $active) {
                    continue;
                }

                $first ??= $entry;

                foreach ($this->lines(implode("\n", $raw)) as $line) {
                    $lines[] = $line;
                }
            }
        }

        return [$lines, $first];
    }

    /**
     * فقرة تبدأ بعنوان قسم: سطر أول قصير بلا علامة ختام جملة ولا رمز قائمة، ويليه نص.
     *
     * @param  list<string>  $lines
     */
    private static function looksLikeHeading(array $lines): bool
    {
        if (count($lines) < 2) {
            return false;
        }

        $head = $lines[0];

        return mb_strlen($head) <= 70
            && preg_match('/^([-*•]|\d+[\.\)])\s/u', $head) !== 1
            && preg_match('/[\.\!،:;؛]$/u', $head) !== 1;
    }

    public static function foldTitle(string $title): string
    {
        return trim(preg_replace('/[\s؟?:،,.]+/u', ' ', TextFold::fold($title)) ?? '');
    }

    /** @return list<string> */
    private function lines(string $text): array
    {
        $out = [];

        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || preg_match('/^#{1,3}\s/u', $line) === 1) {
                continue;
            }

            $out[] = preg_replace('/^[-*•]\s+/u', '• ', $line) ?? $line;
        }

        return $out;
    }

    /** @param list<string> $lines */
    private function select(array $lines, Analysis $question): string
    {
        $total = array_sum(array_map(fn (string $l) => mb_strlen($l) + 1, $lines));

        if ($total <= self::MAX_CHARS) {
            return implode("\n", $lines);
        }

        $topics = array_diff(array_keys($question->concepts + $question->inherited), Lexicon::STRUCTURAL);
        $tokens = array_flip(array_filter($question->tokens, fn ($t) => mb_strlen($t) >= 3));
        $scores = [];

        foreach ($lines as $i => $line) {
            $a = $this->analyzer->analyze($line);
            $score = 0.0;

            foreach ($topics as $concept) {
                if (isset($a->concepts[$concept])) {
                    $score += 2.0;
                }
            }

            foreach ($a->tokens as $token) {
                if (isset($tokens[$token])) {
                    $score += 0.5;
                }
            }

            // الجملة الأولى تعريفية وغالبًا لازمة للسياق.
            $scores[$i] = $score + ($i === 0 ? 0.75 : 0.0);
        }

        arsort($scores);
        $picked = [];
        $budget = self::MAX_CHARS;

        foreach (array_keys($scores) as $i) {
            $len = mb_strlen($lines[$i]) + 1;

            if ($len > $budget && $picked !== []) {
                continue;
            }

            $picked[$i] = true;
            $budget -= $len;

            if ($budget <= 40) {
                break;
            }
        }

        ksort($picked);

        return implode("\n", array_map(fn (int $i) => $lines[$i], array_keys($picked)));
    }
}
