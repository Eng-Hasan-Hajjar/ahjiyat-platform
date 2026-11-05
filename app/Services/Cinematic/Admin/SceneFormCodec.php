<?php

namespace App\Services\Cinematic\Admin;

/**
 * CIN-1 - ترميز نماذج محرّر المشاهد في لوحة الإدارة: يحوّل بين حقول نصية بسيطة (سطر لكل عنصر، الفاصل «|») وبين البنى التي تفهمها الخدمات.
 * نقي بلا قاعدة بيانات؛ لا يتحقق من الصلاحية الدلالية (هذا عمل InteractionEngine::validate)، بل يضمن فقط شكلًا سليمًا وأنواعًا صحيحة.
 *
 * التنسيقات (سطر لكل عنصر):
 *   حوار/نص:        المتحدث|النص          (أو النص وحده)
 *   مناطق:           المعرّف|الاسم|x|y|العرض|الارتفاع   (نسب مئوية 0–100)
 *   عناصر/خيارات:    المعرّف|الاسم|الرمز(اختياري)
 *   أهداف السحب:     المعرّف|الاسم
 *   ربط السحب:       معرّف العنصر=معرّف الهدف
 *   مخفيات المصباح:  المعرّف|x|y|العرض|الارتفاع
 *   قوائم:           معرّفات مفصولة بفواصل
 */
final class SceneFormCodec
{
    /** @param list<array<string, mixed>> $lines */
    public static function linesToText(array $lines): string
    {
        return implode("\n", array_map(fn ($l) => is_array($l) ? (($l['speaker'] ?? '') !== '' ? $l['speaker'].'|' : '').($l['text'] ?? '') : (string) $l, $lines));
    }

    /** @return list<array{speaker: string, text: string}> */
    public static function textToLines(string $text): array
    {
        $out = [];

        foreach (self::rows($text) as $row) {
            $parts = explode('|', $row, 2);
            $out[] = count($parts) === 2 ? ['speaker' => trim($parts[0]), 'text' => trim($parts[1])] : ['speaker' => '', 'text' => trim($row)];
        }

        return $out;
    }

    /** @return list<string> */
    public static function csv(string $text): array
    {
        return array_values(array_unique(array_filter(array_map('trim', preg_split('/[,\x{060C}\s]+/u', $text) ?: []), fn ($v) => $v !== '')));
    }

    /**
     * @param  array<string, mixed>  $form
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} [التعريف العام، الحل]
     */
    public static function decodeInteraction(string $kind, array $form): array
    {
        $public = [
            'kind' => $kind,
            'prompt' => trim((string) ($form['prompt'] ?? '')),
            'instructions' => trim((string) ($form['instructions'] ?? '')),
            'alt_text' => trim((string) ($form['alt_text'] ?? '')),
            'wrong_message' => trim((string) ($form['wrong_message'] ?? '')),
            'success_message' => trim((string) ($form['success_message'] ?? '')),
            'locked_message' => trim((string) ($form['locked_message'] ?? '')),
            'reveal_label' => trim((string) ($form['reveal_label'] ?? '')),
        ];
        $public = array_filter($public, fn ($v) => $v !== '');
        $solution = [];
        $areas = self::areas((string) ($form['areas'] ?? ''));
        $items = self::items((string) ($form['items'] ?? ''));

        switch ($kind) {
            case 'hotspot':
                $public['areas'] = $areas;
                $solution['correct'] = self::csv((string) ($form['correct'] ?? ''));
                break;
            case 'container':
                $public['areas'] = $areas;
                $solution['correct'] = trim((string) ($form['correct'] ?? ''));
                $solution['requires_scenes'] = self::csv((string) ($form['requires'] ?? ''));
                break;
            case 'sequence':
                $public['items'] = $items;
                $solution['order'] = self::csv((string) ($form['order'] ?? ''));
                break;
            case 'drag_drop':
                $public['items'] = $items;
                $public['targets'] = self::targets((string) ($form['targets'] ?? ''));
                $solution['map'] = self::map((string) ($form['map'] ?? ''));
                break;
            case 'choose_item':
                $public['items'] = $items;
                $solution['correct'] = trim((string) ($form['correct'] ?? ''));
                break;
            case 'compare':
                $public['options'] = $items;
                $solution['correct'] = trim((string) ($form['correct'] ?? ''));
                break;
            case 'reveal_code':
                $solution['code'] = trim((string) ($form['code'] ?? ''));
                $solution['requires_scenes'] = self::csv((string) ($form['requires'] ?? ''));
                break;
            case 'flashlight':
                $public['light_radius'] = is_numeric($form['light_radius'] ?? null) ? (float) $form['light_radius'] : 15;
                $targets = self::hidden((string) ($form['hidden'] ?? ''));
                $solution['targets'] = $targets;
                $solution['required'] = is_numeric($form['required'] ?? null) ? (int) $form['required'] : count($targets);
                break;
        }

        return [$public, $solution];
    }

    /**
     * @param  array<string, mixed>  $public
     * @param  array<string, mixed>  $solution
     * @return array<string, string>
     */
    public static function encodeInteraction(array $public, array $solution): array
    {
        $kind = (string) ($public['kind'] ?? '');
        $form = [
            'prompt' => (string) ($public['prompt'] ?? ''), 'instructions' => (string) ($public['instructions'] ?? ''), 'alt_text' => (string) ($public['alt_text'] ?? ''),
            'wrong_message' => (string) ($public['wrong_message'] ?? ''), 'success_message' => (string) ($public['success_message'] ?? ''),
            'locked_message' => (string) ($public['locked_message'] ?? ''), 'reveal_label' => (string) ($public['reveal_label'] ?? ''),
            'areas' => '', 'items' => '', 'targets' => '', 'correct' => '', 'order' => '', 'map' => '', 'code' => '', 'requires' => '', 'hidden' => '', 'required' => '', 'light_radius' => '15',
        ];

        $form['areas'] = implode("\n", array_map(fn ($a) => implode('|', [$a['id'], $a['label'], $a['x'], $a['y'], $a['w'], $a['h']]), (array) ($public['areas'] ?? [])));
        $form['items'] = implode("\n", array_map(fn ($i) => $i['id'].'|'.($i['label'] ?? '').(($i['symbol'] ?? '') !== '' ? '|'.$i['symbol'] : ''), (array) ($public[$kind === 'compare' ? 'options' : 'items'] ?? [])));
        $form['targets'] = implode("\n", array_map(fn ($t) => $t['id'].'|'.($t['label'] ?? ''), (array) ($public['targets'] ?? [])));
        $form['correct'] = is_array($solution['correct'] ?? null) ? implode(',', $solution['correct']) : (string) ($solution['correct'] ?? '');
        $form['order'] = implode(',', (array) ($solution['order'] ?? []));
        $form['map'] = implode("\n", array_map(fn ($k, $v) => $k.'='.$v, array_keys((array) ($solution['map'] ?? [])), array_values((array) ($solution['map'] ?? []))));
        $form['code'] = (string) ($solution['code'] ?? '');
        $form['requires'] = implode(',', (array) ($solution['requires_scenes'] ?? []));
        $form['hidden'] = implode("\n", array_map(fn ($t) => implode('|', [$t['id'], $t['x'], $t['y'], $t['w'], $t['h']]), (array) ($solution['targets'] ?? [])));
        $form['required'] = isset($solution['required']) ? (string) $solution['required'] : '';
        $form['light_radius'] = (string) ($public['light_radius'] ?? '15');

        return $form;
    }

    /** @return list<string> */
    private static function rows(string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/u', $text) ?: []), fn ($r) => $r !== ''));
    }

    /** @return list<array<string, mixed>> */
    private static function areas(string $text): array
    {
        $out = [];

        foreach (self::rows($text) as $row) {
            $p = array_map('trim', explode('|', $row));
            $out[] = ['id' => $p[0] ?? '', 'label' => $p[1] ?? '', 'x' => self::num($p[2] ?? null), 'y' => self::num($p[3] ?? null), 'w' => self::num($p[4] ?? null), 'h' => self::num($p[5] ?? null)];
        }

        return $out;
    }

    /** @return list<array<string, string>> */
    private static function items(string $text): array
    {
        $out = [];

        foreach (self::rows($text) as $row) {
            $p = array_map('trim', explode('|', $row));
            $out[] = ['id' => $p[0] ?? '', 'label' => $p[1] ?? '', 'symbol' => $p[2] ?? ''];
        }

        return $out;
    }

    /** @return list<array<string, string>> */
    private static function targets(string $text): array
    {
        return array_map(fn ($i) => ['id' => $i['id'], 'label' => $i['label']], self::items($text));
    }

    /** @return array<string, string> */
    private static function map(string $text): array
    {
        $out = [];

        foreach (self::rows($text) as $row) {
            $p = array_map('trim', explode('=', $row, 2));

            if (count($p) === 2 && $p[0] !== '') {
                $out[$p[0]] = $p[1];
            }
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private static function hidden(string $text): array
    {
        $out = [];

        foreach (self::rows($text) as $row) {
            $p = array_map('trim', explode('|', $row));
            $out[] = ['id' => $p[0] ?? '', 'x' => self::num($p[1] ?? null), 'y' => self::num($p[2] ?? null), 'w' => self::num($p[3] ?? null), 'h' => self::num($p[4] ?? null)];
        }

        return $out;
    }

    private static function num(mixed $v): float|string
    {
        return is_numeric($v) ? (float) $v : '';
    }
}
