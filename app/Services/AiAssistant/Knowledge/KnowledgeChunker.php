<?php

namespace App\Services\AiAssistant\Knowledge;

/**
 * E45 - يقسّم نص مقال منشور إلى مقاطع آمنة قصيرة تحتفظ بعنوان القسم. أقسام Markdown الخفيفة (# / ##) تحدّد حدود الأقسام؛ الفقرات تُجمَّع حتى الحجم المستهدف
 * ولا يتجاوز المقطع الحدّ الأعلى (التقسيم الإجباري عند حدود الجُمل). حتمي: نفس النص ⇒ نفس المقاطع ونفس البصمات.
 */
final class KnowledgeChunker
{
    /**
     * @return list<array{section: ?string, body: string, hash: string}>
     */
    public function chunk(string $title, string $summary, string $body, ?int $target = null, ?int $max = null): array
    {
        $target ??= max(200, (int) config('tatawwur_ai.knowledge.chunk_target_chars', 600));
        $max ??= max($target, (int) config('tatawwur_ai.knowledge.chunk_max_chars', 800));

        $sections = [];
        $current = ['section' => null, 'paragraphs' => []];

        foreach (preg_split('/\n{2,}/u', KnowledgeNormalizer::normalize($body)) ?: [] as $block) {
            $block = trim($block);

            if ($block === '') {
                continue;
            }

            if (preg_match('/^#{1,3}\s+(.+)$/mu', $block, $m) === 1 && trim(preg_replace('/^#{1,3}\s+.+$/mu', '', $block) ?? '') === '') {
                if ($current['paragraphs'] !== [] || $current['section'] !== null) {
                    $sections[] = $current;
                }
                $current = ['section' => trim($m[1]), 'paragraphs' => []];

                continue;
            }

            $current['paragraphs'][] = preg_replace('/^#{1,3}\s+/mu', '', $block) ?? $block;
        }

        $sections[] = $current;

        $chunks = [];

        // الملخص مقطع تعريفي أول (قصير) يجيب عن «ما هذا؟» ويحمل عنوان المقال.
        $summary = trim($summary);

        if ($summary !== '') {
            $chunks[] = $this->make($title, $summary);
        }

        foreach ($sections as $section) {
            $buffer = '';

            foreach ($section['paragraphs'] as $paragraph) {
                foreach ($this->split($paragraph, $max) as $piece) {
                    if ($buffer !== '' && mb_strlen($buffer) + mb_strlen($piece) + 2 > $target) {
                        $chunks[] = $this->make($section['section'] ?? $title, $buffer);
                        $buffer = '';
                    }

                    $buffer .= ($buffer === '' ? '' : "\n\n").$piece;
                }
            }

            if (trim($buffer) !== '') {
                $chunks[] = $this->make($section['section'] ?? $title, $buffer);
            }
        }

        return $chunks;
    }

    /** @return array{section: ?string, body: string, hash: string} */
    private function make(?string $section, string $body): array
    {
        $body = trim($body);

        return ['section' => $section !== null ? mb_substr(trim($section), 0, 160) : null, 'body' => $body, 'hash' => hash('sha256', ($section ?? '')."\n".$body)];
    }

    /** @return list<string> */
    private function split(string $paragraph, int $max): array
    {
        if (mb_strlen($paragraph) <= $max) {
            return [$paragraph];
        }

        $pieces = [];
        $buffer = '';

        foreach (preg_split('/(?<=[\.\!\?؟۔])\s+|\n/u', $paragraph) ?: [] as $sentence) {
            $sentence = trim($sentence);

            if ($sentence === '') {
                continue;
            }

            while (mb_strlen($sentence) > $max) {
                $pieces[] = mb_substr($sentence, 0, $max);
                $sentence = mb_substr($sentence, $max);
            }

            if ($buffer !== '' && mb_strlen($buffer) + mb_strlen($sentence) + 1 > $max) {
                $pieces[] = $buffer;
                $buffer = '';
            }

            $buffer .= ($buffer === '' ? '' : ' ').$sentence;
        }

        if ($buffer !== '') {
            $pieces[] = $buffer;
        }

        return $pieces;
    }
}
