<?php

namespace App\Support;

use App\Services\AiAssistant\Knowledge\KnowledgeNormalizer;

/**
 * E45 - يحوّل نص مقال «دليل المنصة» (Markdown خفيف: # عناوين، "- " نقاط، فقرات) إلى كتل بسيطة يعرضها القالب بـ{{ }} المهرَّبة فقط.
 * لا HTML خام ولا روابط مدمجة ولا صور: المقال نص بحت، فلا مدخل XSS حتى لو دخل محرر نصًّا غير متوقع.
 */
final class HelpArticleBody
{
    /** @return list<array{type: 'h2'|'p'|'ul', text?: string, items?: list<string>}> */
    public static function blocks(string $body): array
    {
        $blocks = [];

        foreach (preg_split('/\n{2,}/u', KnowledgeNormalizer::normalize($body)) ?: [] as $block) {
            $block = trim($block);

            if ($block === '') {
                continue;
            }

            $lines = array_values(array_filter(array_map('trim', preg_split('/\n/u', $block) ?: []), fn (string $l) => $l !== ''));

            if (count($lines) === 1 && preg_match('/^#{1,3}\s+(.+)$/u', $lines[0], $m) === 1) {
                $blocks[] = ['type' => 'h2', 'text' => trim($m[1])];

                continue;
            }

            $items = [];
            $text = [];

            foreach ($lines as $line) {
                if (preg_match('/^[-•*]\s+(.+)$/u', $line, $m) === 1) {
                    $items[] = trim($m[1]);
                } elseif (preg_match('/^#{1,3}\s+(.+)$/u', $line, $m) === 1) {
                    if ($text !== []) {
                        $blocks[] = ['type' => 'p', 'text' => implode(' ', $text)];
                        $text = [];
                    }
                    if ($items !== []) {
                        $blocks[] = ['type' => 'ul', 'items' => $items];
                        $items = [];
                    }
                    $blocks[] = ['type' => 'h2', 'text' => trim($m[1])];
                } else {
                    if ($items !== []) {
                        $blocks[] = ['type' => 'ul', 'items' => $items];
                        $items = [];
                    }
                    $text[] = $line;
                }
            }

            if ($text !== []) {
                $blocks[] = ['type' => 'p', 'text' => implode(' ', $text)];
            }

            if ($items !== []) {
                $blocks[] = ['type' => 'ul', 'items' => $items];
            }
        }

        return $blocks;
    }
}
