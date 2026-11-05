<?php

namespace App\Services\Cinematic\Interactions;

/**
 * CIN-1 - محرك التفاعلات المهيكلة. التعريف العام (ما يراه اللاعب) منفصل تمامًا عن الحل (خادمي مشفّر): العرض لا يحتوي أبدًا أي حقل من الحل.
 *
 * الأنواع: hotspot (نقطة ساخنة)، container (فتح باب/درج بشرط)، sequence (ترتيب)، drag_drop (سحب وإفلات)، choose_item (اختيار عنصر)،
 * compare (مقارنة صورة/مخطط بالمشهد)، reveal_code (كشف رمز بعد شرط)، flashlight (استكشاف بمصباح).
 * كل نوع يعمل باللمس والفأرة ولوحة المفاتيح لأن الإرسال دائمًا معرّفات عناصر (id) أو إحداثيات نسبية؛ الواجهة تقدّم بديلًا بالقوائم.
 *
 * الدوال نقية (بلا قاعدة بيانات ولا حالة) لتُختبر بسهولة. لا اقتصاد: لا جواهر ولا خبرة.
 */
final class InteractionEngine
{
    public const KINDS = ['hotspot', 'container', 'sequence', 'drag_drop', 'choose_item', 'compare', 'reveal_code', 'flashlight'];

    private const ID = '/\A[a-z0-9_]{1,40}\z/';

    /** @return list<string> */
    public function kinds(): array
    {
        return self::KINDS;
    }

    /**
     * يتحقق من اكتمال التعريف العام والحل معًا.
     *
     * @param  array<string, mixed>  $public
     * @param  array<string, mixed>  $solution
     * @return list<string> رسائل أخطاء عربية (فارغة = صالح)
     */
    public function validate(string $kind, array $public, array $solution): array
    {
        if (! in_array($kind, self::KINDS, true)) {
            return ['نوع التفاعل غير معروف.'];
        }

        $errors = [];

        if (trim((string) ($public['prompt'] ?? '')) === '') {
            $errors[] = 'نص السؤال/التعليمات مطلوب.';
        }

        switch ($kind) {
            case 'hotspot':
                $ids = $this->areaIds($public, $errors);
                $correct = $solution['correct'] ?? null;

                if (! is_array($correct) || $correct === [] || array_diff($correct, $ids) !== []) {
                    $errors[] = 'حدّد منطقة صحيحة واحدة على الأقل من المناطق المعرّفة.';
                }
                break;

            case 'container':
                $ids = $this->areaIds($public, $errors);

                if (! is_string($solution['correct'] ?? null) || ! in_array($solution['correct'], $ids, true)) {
                    $errors[] = 'حدّد الباب/الدرج الصحيح من المناطق المعرّفة.';
                }

                $this->requiresList($solution, $errors);
                break;

            case 'sequence':
                $ids = $this->itemIds($public, 'items', 2, 12, $errors);
                $order = $solution['order'] ?? null;

                if (! is_array($order) || count($order) !== count($ids) || array_diff($order, $ids) !== [] || count(array_unique($order)) !== count($order)) {
                    $errors[] = 'الترتيب الصحيح يجب أن يضم كل العناصر مرة واحدة.';
                }
                break;

            case 'drag_drop':
                $items = $this->itemIds($public, 'items', 1, 12, $errors);
                $targets = $this->itemIds($public, 'targets', 1, 12, $errors);
                $map = $solution['map'] ?? null;

                if (! is_array($map) || array_keys($map) === [] || array_diff(array_keys($map), $items) !== [] || count($map) !== count($items) || array_diff(array_values($map), $targets) !== []) {
                    $errors[] = 'حدّد هدفًا صحيحًا لكل عنصر.';
                }
                break;

            case 'choose_item':
            case 'compare':
                $field = $kind === 'choose_item' ? 'items' : 'options';
                $ids = $this->itemIds($public, $field, 2, 12, $errors);

                if (! is_string($solution['correct'] ?? null) || ! in_array($solution['correct'], $ids, true)) {
                    $errors[] = 'حدّد الخيار الصحيح من الخيارات المعرّفة.';
                }
                break;

            case 'reveal_code':
                $code = $solution['code'] ?? null;

                if (! is_string($code) || trim($code) === '' || mb_strlen($code) > 64) {
                    $errors[] = 'الرمز المراد كشفه مطلوب (حتى 64 حرفًا).';
                }

                $this->requiresList($solution, $errors);
                break;

            case 'flashlight':
                $radius = $public['light_radius'] ?? 15;

                if (! is_numeric($radius) || $radius < 3 || $radius > 60) {
                    $errors[] = 'نصف قطر الضوء يجب أن يكون بين 3 و60 (نسبة مئوية).';
                }

                $targets = $solution['targets'] ?? null;

                if (! is_array($targets) || $targets === []) {
                    $errors[] = 'حدّد عنصرًا مخفيًا واحدًا على الأقل.';
                } else {
                    $seen = [];

                    foreach ($targets as $t) {
                        if (! is_array($t) || ! $this->validId($t['id'] ?? null) || in_array($t['id'], $seen, true) || ! $this->validRect($t)) {
                            $errors[] = 'عنصر مخفي غير صالح (معرّف/إحداثيات).';
                            break;
                        }

                        $seen[] = $t['id'];
                    }

                    $required = $solution['required'] ?? count($targets);

                    if (! is_int($required) || $required < 1 || $required > count($targets)) {
                        $errors[] = 'عدد العناصر المطلوب العثور عليها غير صالح.';
                    }
                }
                break;
        }

        return array_values(array_unique($errors));
    }

    /**
     * العرض العام للاعب: قائمة بيضاء صارمة. لا يمر منه أي حقل غير معروف، ولا شيء من الحل أبدًا.
     *
     * @param  array<string, mixed>  $public
     * @return array<string, mixed>
     */
    public function publicView(string $kind, array $public): array
    {
        $out = ['kind' => $kind, 'prompt' => $this->text($public['prompt'] ?? ''), 'instructions' => $this->text($public['instructions'] ?? ''), 'alt_text' => $this->text($public['alt_text'] ?? '')];

        foreach (['wrong_message', 'success_message', 'locked_message', 'reveal_label'] as $field) {
            if (isset($public[$field]) && is_string($public[$field])) {
                $out[$field] = $this->text($public[$field]);
            }
        }

        if (in_array($kind, ['hotspot', 'container'], true)) {
            $out['areas'] = array_map(fn (array $a) => ['id' => $a['id'], 'label' => $this->text($a['label'] ?? ''), 'x' => (float) $a['x'], 'y' => (float) $a['y'], 'w' => (float) $a['w'], 'h' => (float) $a['h']], $this->listOf($public, 'areas'));
        }

        if (in_array($kind, ['sequence', 'drag_drop', 'choose_item'], true)) {
            $out['items'] = array_map(fn (array $i) => ['id' => $i['id'], 'label' => $this->text($i['label'] ?? ''), 'symbol' => $this->text($i['symbol'] ?? '')], $this->listOf($public, 'items'));
        }

        if ($kind === 'drag_drop') {
            $out['targets'] = array_map(fn (array $i) => ['id' => $i['id'], 'label' => $this->text($i['label'] ?? '')], $this->listOf($public, 'targets'));
        }

        if ($kind === 'compare') {
            $out['options'] = array_map(fn (array $i) => ['id' => $i['id'], 'label' => $this->text($i['label'] ?? ''), 'symbol' => $this->text($i['symbol'] ?? '')], $this->listOf($public, 'options'));
        }

        if ($kind === 'flashlight') {
            $out['light_radius'] = (float) ($public['light_radius'] ?? 15);
        }

        return $out;
    }

    /**
     * يقيّم إرسال اللاعب. لا يرمي استثناءات لمدخلات مشوَّهة: تُعدّ إجابة خاطئة.
     *
     * @param  array<string, mixed>  $public
     * @param  array<string, mixed>  $solution
     * @param  array<string, mixed>  $submission
     * @param  list<string>  $completedKeys  مفاتيح المشاهد المكتملة في تقدّم اللاعب (لشروط requires_scenes)
     * @return array{correct: bool, locked: bool, message: ?string, reveal: ?string}
     */
    public function evaluate(string $kind, array $public, array $solution, array $submission, array $completedKeys = []): array
    {
        $wrong = $this->text($public['wrong_message'] ?? 'ليست الإجابة الصحيحة، حاول مرة أخرى.');
        $ok = fn (?string $reveal = null) => ['correct' => true, 'locked' => false, 'message' => isset($public['success_message']) ? $this->text($public['success_message']) : null, 'reveal' => $reveal];
        $no = fn (bool $locked = false) => ['correct' => false, 'locked' => $locked, 'message' => $locked ? $this->text($public['locked_message'] ?? 'لا يمكنك فعل ذلك بعد.') : $wrong, 'reveal' => null];

        switch ($kind) {
            case 'hotspot':
                $area = $submission['area'] ?? null;

                return is_string($area) && in_array($area, (array) ($solution['correct'] ?? []), true) ? $ok() : $no();

            case 'container':
                $area = $submission['area'] ?? null;

                if (! is_string($area) || $area !== ($solution['correct'] ?? null)) {
                    return $no();
                }

                return array_diff((array) ($solution['requires_scenes'] ?? []), $completedKeys) === [] ? $ok() : $no(true);

            case 'sequence':
                $order = $submission['order'] ?? null;

                return is_array($order) && array_values($order) === array_values((array) ($solution['order'] ?? [])) ? $ok() : $no();

            case 'drag_drop':
                $placements = $submission['placements'] ?? null;
                $expected = (array) ($solution['map'] ?? []);

                if (! is_array($placements)) {
                    return $no();
                }

                ksort($placements);
                ksort($expected);

                return $placements === $expected ? $ok() : $no();

            case 'choose_item':
                $item = $submission['item'] ?? null;

                return is_string($item) && $item === ($solution['correct'] ?? null) ? $ok() : $no();

            case 'compare':
                $option = $submission['option'] ?? null;

                return is_string($option) && $option === ($solution['correct'] ?? null) ? $ok() : $no();

            case 'reveal_code':
                if (array_diff((array) ($solution['requires_scenes'] ?? []), $completedKeys) !== []) {
                    return $no(true);
                }

                return $ok((string) ($solution['code'] ?? ''));

            case 'flashlight':
                $targets = (array) ($solution['targets'] ?? []);
                $required = (int) ($solution['required'] ?? count($targets));
                $points = $submission['points'] ?? null;

                if (! is_array($points) || $points === [] || count($points) > max(6, $required * 3)) {
                    return $no();
                }

                $found = [];

                foreach ($points as $p) {
                    if (! is_array($p) || ! is_numeric($p['x'] ?? null) || ! is_numeric($p['y'] ?? null)) {
                        continue;
                    }

                    foreach ($targets as $t) {
                        if ($p['x'] >= $t['x'] && $p['x'] <= $t['x'] + $t['w'] && $p['y'] >= $t['y'] && $p['y'] <= $t['y'] + $t['h']) {
                            $found[$t['id']] = true;
                        }
                    }
                }

                return count($found) >= $required ? $ok() : $no();
        }

        return $no();
    }

    /** @param list<string> $errors @return list<string> */
    private function areaIds(array $public, array &$errors): array
    {
        $areas = $public['areas'] ?? null;
        $ids = [];

        if (! is_array($areas) || $areas === [] || count($areas) > 24) {
            $errors[] = 'عرّف منطقة واحدة على الأقل (حتى 24).';

            return [];
        }

        foreach ($areas as $a) {
            if (! is_array($a) || ! $this->validId($a['id'] ?? null) || in_array($a['id'], $ids, true) || ! $this->validRect($a) || trim((string) ($a['label'] ?? '')) === '') {
                $errors[] = 'منطقة غير صالحة (معرّف فريد، اسم، وإحداثيات نسبية 0–100).';

                return $ids;
            }

            $ids[] = $a['id'];
        }

        return $ids;
    }

    /** @param list<string> $errors @return list<string> */
    private function itemIds(array $public, string $field, int $min, int $max, array &$errors): array
    {
        $items = $public[$field] ?? null;
        $ids = [];

        if (! is_array($items) || count($items) < $min || count($items) > $max) {
            $errors[] = "عرّف من {$min} إلى {$max} عناصر في «{$field}».";

            return [];
        }

        foreach ($items as $i) {
            if (! is_array($i) || ! $this->validId($i['id'] ?? null) || in_array($i['id'], $ids, true) || trim((string) ($i['label'] ?? '')) === '') {
                $errors[] = "عنصر غير صالح في «{$field}» (معرّف فريد واسم).";

                return $ids;
            }

            $ids[] = $i['id'];
        }

        return $ids;
    }

    /** @param list<string> $errors */
    private function requiresList(array $solution, array &$errors): void
    {
        $req = $solution['requires_scenes'] ?? [];

        if (! is_array($req) || array_filter($req, fn ($k) => ! is_string($k) || preg_match('/\A[a-z0-9_]{2,64}\z/', $k) !== 1) !== []) {
            $errors[] = 'شروط المشاهد المطلوبة غير صالحة.';
        }
    }

    private function validId(mixed $id): bool
    {
        return is_string($id) && preg_match(self::ID, $id) === 1;
    }

    /** @param array<string, mixed> $r */
    private function validRect(array $r): bool
    {
        foreach (['x', 'y', 'w', 'h'] as $k) {
            if (! isset($r[$k]) || ! is_numeric($r[$k]) || $r[$k] < 0 || $r[$k] > 100) {
                return false;
            }
        }

        return $r['w'] > 0 && $r['h'] > 0 && $r['x'] + $r['w'] <= 100.0001 && $r['y'] + $r['h'] <= 100.0001;
    }

    private function text(mixed $value): string
    {
        return mb_substr(trim(strip_tags((string) $value)), 0, 600);
    }

    /** @return list<array<string, mixed>> */
    private function listOf(array $public, string $field): array
    {
        return array_values(array_filter((array) ($public[$field] ?? []), 'is_array'));
    }
}
