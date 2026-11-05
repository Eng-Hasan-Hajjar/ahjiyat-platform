<?php

namespace App\Services\Cinematic;

use App\Models\Cinematic\CinematicScene;
use App\Services\Cinematic\Interactions\InteractionEngine;

/**
 * CIN-1 - تنظيف إعداد المشهد العام بقائمة بيضاء حسب النوع. لا يمر شيء غير معروف إلى التخزين، فلا يتسرب «حل» بالخطأ إلى الإعداد العام.
 * كل النصوص بلا وسوم HTML (تُعرض مهرَّبة في الواجهة أيضًا).
 */
class SceneConfigNormalizer
{
    public function __construct(protected InteractionEngine $engine) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function normalize(string $type, array $input): array
    {
        $out = [];

        $description = $this->text($input['description'] ?? '', 800);

        if ($description !== '') {
            $out['description'] = $description;
        }

        switch ($type) {
            case CinematicScene::TYPE_VIDEO:
                $out['transcript'] = $this->lines($input['transcript'] ?? []);
                break;

            case CinematicScene::TYPE_NARRATIVE:
                $out['lines'] = $this->lines($input['lines'] ?? []);
                break;

            case CinematicScene::TYPE_TRANSITION:
                $out['title'] = $this->text($input['title'] ?? '', 160);
                $out['subtitle'] = $this->text($input['subtitle'] ?? '', 300);
                $out['next_stage_title'] = $this->text($input['next_stage_title'] ?? '', 160);
                break;

            case CinematicScene::TYPE_PUZZLE:
            case CinematicScene::TYPE_INTERACTION:
            case CinematicScene::TYPE_EXPLORATION:
                $out['intro_lines'] = $this->lines($input['intro_lines'] ?? []);

                foreach (['wrong_message', 'success_message'] as $field) {
                    $value = $this->text($input[$field] ?? '', 300);

                    if ($value !== '') {
                        $out[$field] = $value;
                    }
                }

                if (isset($input['max_attempts']) && is_numeric($input['max_attempts'])) {
                    $out['max_attempts'] = max(1, min(50, (int) $input['max_attempts']));
                }

                if ($type !== CinematicScene::TYPE_PUZZLE && is_array($input['interaction'] ?? null)) {
                    $kind = (string) ($input['interaction']['kind'] ?? '');
                    $out['interaction'] = in_array($kind, InteractionEngine::KINDS, true) ? $this->engine->publicView($kind, $input['interaction']) : [];
                }
                break;
        }

        if (strlen((string) json_encode($out, JSON_UNESCAPED_UNICODE)) > (int) config('cinematic_stories.limits.max_config_bytes')) {
            throw CinematicException::make('config_too_large', 'إعداد المشهد أكبر من الحد المسموح.');
        }

        return $out;
    }

    /** @param array<string, mixed> $rule @return array{requires: list<string>} */
    public function unlockRule(mixed $rule): array
    {
        $requires = is_array($rule) ? ($rule['requires'] ?? []) : [];
        $clean = [];

        foreach ((array) $requires as $key) {
            if (is_string($key) && preg_match('/\A[a-z0-9_]{2,64}\z/', $key) === 1) {
                $clean[] = $key;
            }
        }

        return ['requires' => array_values(array_unique($clean))];
    }

    /** @return list<array{speaker: string, text: string}> */
    private function lines(mixed $lines): array
    {
        $out = [];

        foreach (array_slice(is_array($lines) ? $lines : [], 0, 40) as $line) {
            if (is_string($line)) {
                $line = ['speaker' => '', 'text' => $line];
            }

            if (! is_array($line)) {
                continue;
            }

            $text = $this->text($line['text'] ?? '', 600);

            if ($text !== '') {
                $out[] = ['speaker' => $this->text($line['speaker'] ?? '', 60), 'text' => $text];
            }
        }

        return $out;
    }

    private function text(mixed $value, int $max): string
    {
        return mb_substr(trim(strip_tags((string) $value)), 0, $max);
    }
}
