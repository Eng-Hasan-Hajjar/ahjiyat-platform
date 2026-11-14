<?php

namespace App\Services\Cinematic\Interactions;

/**
 * CIN-1 - محرك التفاعلات المهيكلة. التعريف العام (ما يراه اللاعب) منفصل تمامًا عن الحل (خادمي مشفّر): العرض لا يحتوي أبدًا أي حقل من الحل.
 *
 * الأنواع: hotspot (نقطة ساخنة)، container (فتح باب/درج بشرط)، sequence (ترتيب)، drag_drop (سحب وإفلات)، choose_item (اختيار عنصر)،
 * compare (مقارنة صورة/مخطط بالمشهد)، reveal_code (كشف رمز بعد شرط)، flashlight (استكشاف بمصباح).
 * CIN-2: answer (إدخال إجابة رقمية/نصية بتطبيع الأرقام العربية)، inspect (فحص صورة بتكبير: مناطق فحص مطلوبة)، multi_lock (قفل مركب من عدة أقفال).
 * حقلان عامان في الحل لكل الأنواع: reveal (نص يُكشف بعد النجاح فقط) وhints (تلميحات تُعرض بالتتابع عند الخطأ) — كلاهما خادمي ولا يصل المتصفح قبل وقته.
 * كل نوع يعمل باللمس والفأرة ولوحة المفاتيح لأن الإرسال دائمًا معرّفات عناصر (id) أو إحداثيات نسبية؛ الواجهة تقدّم بديلًا بالقوائم.
 *
 * الدوال نقية (بلا قاعدة بيانات ولا حالة) لتُختبر بسهولة. لا اقتصاد: لا جواهر ولا خبرة.
 */
final class InteractionEngine
{
    public const KINDS = ['hotspot', 'container', 'sequence', 'drag_drop', 'choose_item', 'compare', 'reveal_code', 'flashlight', 'answer', 'inspect', 'multi_lock'];

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

            case 'answer':
                $input = $public['input'] ?? 'text';

                if (! in_array($input, ['number', 'text'], true)) {
                    $errors[] = 'نوع الإدخال: number أو text.';
                }

                $accepted = $solution['accepted'] ?? null;

                if (! is_array($accepted) || $accepted === [] || count($accepted) > 12 || array_filter($accepted, fn ($a) => ! is_scalar($a) || trim((string) $a) === '' || mb_strlen((string) $a) > 80) !== []) {
                    $errors[] = 'حدّد إجابة مقبولة واحدة على الأقل (حتى 12، كل منها حتى 80 حرفًا).';
                } elseif ($input === 'number' && array_filter($accepted, fn ($a) => $this->numeric((string) $a) === null) !== []) {
                    $errors[] = 'الإجابة المقبولة في الإدخال الرقمي يجب أن تكون رقمًا.';
                }

                $units = $solution['units'] ?? [];

                if (! is_array($units) || count($units) > 12 || array_filter($units, fn ($u) => ! is_string($u) || mb_strlen($u) > 24) !== []) {
                    $errors[] = 'وحدات القياس المسموح بكتابتها غير صالحة.';
                }

                $facts = $public['facts'] ?? [];

                if (! is_array($facts) || count($facts) > 6 || array_filter($facts, fn ($f) => ! is_array($f) || trim((string) ($f['label'] ?? '')) === '' || trim((string) ($f['value'] ?? '')) === '') !== []) {
                    $errors[] = 'المعطيات المعروضة: حتى 6، لكل منها اسم وقيمة.';
                }

                $this->requiresList($solution, $errors);
                break;

            case 'inspect':
                $ids = $this->areaIds($public, $errors);
                $back = $public['back'] ?? null;

                // «قلب الصورة»: وجه خلفي اختياري (نص بخط اليد مثلًا). يُعدّ منطقة فحص افتراضية بالمعرّف back.
                if ($back !== null) {
                    if (! is_array($back) || trim((string) ($back['label'] ?? '')) === '' || mb_strlen((string) $back['label']) > 40 || trim((string) ($back['text'] ?? '')) === '' || mb_strlen((string) $back['text']) > 600) {
                        $errors[] = 'الوجه الخلفي: اسم (حتى 40 حرفًا) ونص (حتى 600 حرف).';
                    } else {
                        $ids[] = 'back';
                    }
                }

                $required = $solution['required'] ?? null;

                if (! is_array($required) || $required === [] || array_diff($required, $ids) !== [] || count(array_unique($required)) !== count($required)) {
                    $errors[] = 'حدّد مناطق الفحص المطلوبة من المناطق المعرّفة.';
                } elseif ((int) ($public['required_count'] ?? 0) !== count($required)) {
                    $errors[] = 'عدد مناطق الفحص المطلوبة لا يطابق التعريف العام (required_count).';
                }

                $this->requiresList($solution, $errors);
                break;

            case 'multi_lock':
                $locks = $public['locks'] ?? null;
                $codes = $solution['codes'] ?? null;

                if (! is_array($locks) || $locks === [] || count($locks) > 4) {
                    $errors[] = 'عرّف من 1 إلى 4 أقفال.';
                    break;
                }

                $seen = [];

                foreach ($locks as $lock) {
                    $segments = is_array($lock) ? ($lock['segments'] ?? null) : null;

                    if (! is_array($lock) || ! $this->validId($lock['id'] ?? null) || in_array($lock['id'], $seen, true) || trim((string) ($lock['label'] ?? '')) === '' || ! $this->validSegments($segments)) {
                        $errors[] = 'قفل غير صالح (معرّف فريد، اسم، وأجزاء: أرقام 1–6 خانات أو حروف من 2–12 خيارًا).';
                        break;
                    }

                    $seen[] = $lock['id'];

                    if (! is_array($codes) || ! is_string($codes[$lock['id']] ?? null) || ! $this->codeMatches($segments, (string) $codes[$lock['id']])) {
                        $errors[] = "الرمز الصحيح للقفل «{$lock['id']}» مفقود أو لا يطابق أجزاءه.";
                        break;
                    }
                }

                $this->requiresList($solution, $errors);
                break;
        }

        $reveal = $solution['reveal'] ?? null;

        if ($reveal !== null && (! is_string($reveal) || mb_strlen($reveal) > 600)) {
            $errors[] = 'نص الكشف بعد النجاح: حتى 600 حرف.';
        }

        $hints = $solution['hints'] ?? [];

        if (! is_array($hints) || count($hints) > 8 || array_filter($hints, fn ($h) => ! is_string($h) || mb_strlen($h) > 300) !== []) {
            $errors[] = 'التلميحات: حتى 8 نصوص (كل منها حتى 300 حرف).';
        }

        $hintsBy = $solution['lock_hints'] ?? [];

        if (! is_array($hintsBy) || array_filter($hintsBy, fn ($h) => ! is_string($h) || mb_strlen($h) > 300) !== []) {
            $errors[] = 'تلميحات الأقفال غير صالحة.';
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

        if ($kind === 'answer') {
            $out['input'] = ($public['input'] ?? 'text') === 'number' ? 'number' : 'text';
            $out['placeholder'] = $this->text($public['placeholder'] ?? '');
            $out['unit_label'] = $this->text($public['unit_label'] ?? '');
            $out['facts'] = array_map(fn (array $f) => ['label' => $this->text($f['label'] ?? ''), 'value' => $this->text($f['value'] ?? '')], array_slice($this->listOf($public, 'facts'), 0, 6));
        }

        if ($kind === 'inspect') {
            $out['areas'] = array_map(fn (array $a) => ['id' => $a['id'], 'label' => $this->text($a['label'] ?? ''), 'x' => (float) $a['x'], 'y' => (float) $a['y'], 'w' => (float) $a['w'], 'h' => (float) $a['h'], 'note' => $this->text($a['note'] ?? '')], $this->listOf($public, 'areas'));
            $out['required_count'] = (int) ($public['required_count'] ?? 0);

            if (is_array($public['back'] ?? null)) {
                $out['back'] = ['label' => $this->text($public['back']['label'] ?? ''), 'text' => $this->text($public['back']['text'] ?? '')];
            }
        }

        if ($kind === 'multi_lock') {
            $out['locks'] = array_map(fn (array $l) => ['id' => $l['id'], 'label' => $this->text($l['label'] ?? ''), 'segments' => array_map(fn (array $g) => ($g['type'] ?? '') === 'letter'
                ? ['type' => 'letter', 'options' => array_values(array_map(fn ($o) => $this->text($o), (array) ($g['options'] ?? [])))]
                : ['type' => 'digits', 'length' => (int) ($g['length'] ?? 1)], array_values(array_filter((array) ($l['segments'] ?? []), 'is_array')))], $this->listOf($public, 'locks'));
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
    public function evaluate(string $kind, array $public, array $solution, array $submission, array $completedKeys = [], array $context = []): array
    {
        $r = $this->evaluateCore($kind, $public, $solution, $submission, $completedKeys) + ['hint' => null, 'detail' => null];

        // CIN-2: نص الكشف يُسلَّم بعد النجاح فقط، والتلميحات بالتتابع عند الخطأ فقط (تقدير التلميح = رقم المحاولة؛ لا عقوبة زمنية).
        $reveal = $solution['reveal'] ?? null;

        if ($r['correct'] && $r['reveal'] === null && is_string($reveal) && trim($reveal) !== '') {
            $r['reveal'] = $this->text($reveal);
        }

        if (! $r['correct'] && ! $r['locked'] && $r['hint'] === null) {
            $hints = array_values(array_filter((array) ($solution['hints'] ?? []), 'is_string'));

            if ($hints !== []) {
                $attempt = max(1, (int) ($context['attempt'] ?? 1));
                $r['hint'] = $this->text($hints[min($attempt, count($hints)) - 1]);
            }
        }

        return $r;
    }

    /**
     * @param  array<string, mixed>  $public
     * @param  array<string, mixed>  $solution
     * @param  array<string, mixed>  $submission
     * @param  list<string>  $completedKeys
     * @return array{correct: bool, locked: bool, message: ?string, reveal: ?string, hint?: ?string, detail?: ?array<string, bool>}
     */
    protected function evaluateCore(string $kind, array $public, array $solution, array $submission, array $completedKeys = []): array
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

            case 'answer':
                if (array_diff((array) ($solution['requires_scenes'] ?? []), $completedKeys) !== []) {
                    return $no(true);
                }

                $given = $submission['answer'] ?? null;

                if (! is_scalar($given) || mb_strlen((string) $given) > 80) {
                    return $no();
                }

                $number = ($public['input'] ?? 'text') === 'number';
                $units = array_values(array_filter((array) ($solution['units'] ?? []), 'is_string'));

                foreach ((array) ($solution['accepted'] ?? []) as $accepted) {
                    $match = $number
                        ? ($this->numeric((string) $given, $units) !== null && $this->numeric((string) $given, $units) === $this->numeric((string) $accepted))
                        : ($this->normText((string) $given) !== '' && $this->normText((string) $given) === $this->normText((string) $accepted));

                    if ($match) {
                        return $ok();
                    }
                }

                return $no();

            case 'inspect':
                if (array_diff((array) ($solution['requires_scenes'] ?? []), $completedKeys) !== []) {
                    return $no(true);
                }

                $inspected = $submission['inspected'] ?? null;
                $inspected = is_array($inspected) ? array_values(array_filter($inspected, 'is_string')) : [];

                if (array_diff((array) ($solution['required'] ?? []), $inspected) === [] && (array) ($solution['required'] ?? []) !== []) {
                    return $ok();
                }

                return ['correct' => false, 'locked' => false, 'message' => isset($public['wrong_message']) ? $this->text($public['wrong_message']) : 'ما زال هناك ما يستحق الفحص.', 'reveal' => null];

            case 'multi_lock':
                if (array_diff((array) ($solution['requires_scenes'] ?? []), $completedKeys) !== []) {
                    return $no(true);
                }

                $values = is_array($submission['values'] ?? null) ? $submission['values'] : [];
                $codes = (array) ($solution['codes'] ?? []);
                $detail = [];
                $firstWrong = null;

                foreach ($this->listOf($public, 'locks') as $lock) {
                    $id = (string) ($lock['id'] ?? '');
                    $v = $values[$id] ?? null;
                    $given = is_array($v) ? implode('', array_map(fn ($x) => is_scalar($x) ? (string) $x : '', $v)) : (is_scalar($v) ? (string) $v : '');
                    $ok1 = mb_strlen($given) <= 40 && $this->normText($given) !== '' && $this->normText($given) === $this->normText((string) ($codes[$id] ?? ''));
                    $detail[$id] = $ok1;

                    if (! $ok1 && $firstWrong === null) {
                        $firstWrong = $id;
                    }
                }

                if ($firstWrong === null && $detail !== []) {
                    return $ok() + ['hint' => null, 'detail' => $detail];
                }

                $hint = isset($solution['lock_hints'][$firstWrong]) && is_string($solution['lock_hints'][$firstWrong]) ? $this->text($solution['lock_hints'][$firstWrong]) : null;

                return $no() + ['hint' => $hint, 'detail' => $detail];

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

    /** رقم معيَّر (نص عشري قانوني) من إدخال يقبل الأرقام العربية والفارسية والفاصلة العشرية العربية ووحدات مسموحة؛ null إن لم يكن رقمًا. */
    private function numeric(string $raw, array $units = []): ?string
    {
        $s = trim(strtr($this->digits(trim($raw)), ['٫' => '.', '٬' => '', ',' => '.', '،' => '.']));
        $list = array_values(array_filter(array_map(fn ($u) => is_string($u) ? $this->digits(trim($u)) : '', $units), fn ($u) => $u !== ''));
        usort($list, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        // الوحدة المسموحة تُقبل قبل الرقم أو بعده فقط (لا في وسطه): «20 دقيقة» و«دقيقة 20» مقبولتان، و«2 دقيقة 0» ليست كذلك.
        foreach ($list as $unit) {
            $q = preg_quote($unit, '/');
            $s = trim((string) preg_replace(['/\A'.$q.'(?=[\s\d.\-]|\z)/ui', '/(?<=[\d.\s])'.$q.'\z/ui'], '', $s));
        }

        // مسافة بين رقمين تعني إدخالًا مختلفًا («2 0»، «20 20») لا الرقم 20.
        if (preg_match('/\d\s+\d/u', $s) === 1) {
            return null;
        }

        $s = (string) preg_replace('/\s+/u', '', $s);

        if (preg_match('/\A-?\d{1,9}(\.\d{1,4})?\z/', $s) !== 1) {
            return null;
        }

        return rtrim(rtrim(number_format((float) $s, 4, '.', ''), '0'), '.');
    }

    private function digits(string $s): string
    {
        return strtr($s, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);
    }

    /** تطبيع نص للمقارنة: أرقام لاتينية، بلا تشكيل/تطويل/مسافات/ترقيم، وتوحيد الألف والياء والتاء المربوطة، وأحرف صغيرة. */
    private function normText(string $s): string
    {
        $s = $this->digits($s);
        $s = (string) preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $s);
        $s = strtr($s, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي', 'ة' => 'ه', 'ؤ' => 'و', 'ئ' => 'ي']);

        return (string) preg_replace('/[\s\p{P}\p{S}]+/u', '', mb_strtolower($s));
    }

    /** @param mixed $segments */
    private function validSegments(mixed $segments): bool
    {
        if (! is_array($segments) || $segments === [] || count($segments) > 4) {
            return false;
        }

        foreach ($segments as $g) {
            if (! is_array($g)) {
                return false;
            }

            if (($g['type'] ?? null) === 'digits') {
                if (! is_int($g['length'] ?? null) && ! (is_numeric($g['length'] ?? null) && (int) $g['length'] == $g['length'])) {
                    return false;
                }

                if ((int) $g['length'] < 1 || (int) $g['length'] > 6) {
                    return false;
                }
            } elseif (($g['type'] ?? null) === 'letter') {
                $o = $g['options'] ?? null;

                if (! is_array($o) || count($o) < 2 || count($o) > 12 || array_filter($o, fn ($x) => ! is_string($x) || trim($x) === '' || mb_strlen($x) > 3) !== [] || count(array_unique($o)) !== count($o)) {
                    return false;
                }
            } else {
                return false;
            }
        }

        return true;
    }

    /** هل الرمز الصحيح المحفوظ يطابق أجزاء القفل (أرقام بالعدد المطلوب، أو حرف من الخيارات)؟ */
    private function codeMatches(array $segments, string $code): bool
    {
        $rest = $this->digits(trim($code));

        foreach ($segments as $g) {
            if (($g['type'] ?? '') === 'digits') {
                $n = (int) $g['length'];

                if (preg_match('/\A\d{'.$n.'}/', $rest) !== 1) {
                    return false;
                }

                $rest = substr($rest, $n);
            } else {
                $hit = null;

                foreach ((array) ($g['options'] ?? []) as $opt) {
                    if (str_starts_with($rest, $this->digits((string) $opt))) {
                        $hit = $this->digits((string) $opt);
                        break;
                    }
                }

                if ($hit === null) {
                    return false;
                }

                $rest = substr($rest, strlen($hit));
            }
        }

        return $rest === '';
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
