<?php

/*
| SS-32..SS-37 — المكوّن المشترك <x-searchable-select>: التباين اللوني (WCAG) في الثيمين الفاتح والغامق، وحصر الأنماط بـ.ss-root،
| وعقد الإتاحة والبنية (combobox/listbox/aria-activedescendant/منطقة status/data-no-dirty، بلا إيموجي، @once).
| ملف CSS/JS مضمَّن في Blade لأن لوحة Filament هنا بلا ثيم Vite مخصّص؛ فنفحصه من المصدر. القياس على العرض الفعلي في المتصفح (Chromium) يجري خارج Pest.
*/

function ss_assets_source(): string
{
    return (string) file_get_contents(resource_path('views/components/searchable-select/assets.blade.php'));
}

function ss_component_source(): string
{
    return (string) file_get_contents(resource_path('views/components/searchable-select/index.blade.php'));
}

/** @return array<string, string> رموز --ss-* لثيم واحد من كتلة تبدأ بالمحدّد المعطى. */
function ss_tokens(string $selector): array
{
    $css = ss_assets_source();
    $start = strpos($css, "\n".$selector.' {');
    expect($start)->not->toBeFalse();
    $block = substr($css, $start, strpos($css, '}', $start) - $start);
    preg_match_all('/--(ss-[a-z0-9-]+):\s*(#[0-9a-fA-F]{6})/', $block, $m, PREG_SET_ORDER);

    return collect($m)->mapWithKeys(fn ($x) => [$x[1] => strtolower($x[2])])->all();
}

function ss_lum(string $hex): float
{
    $c = array_map(fn ($v) => ($v = hexdec($v) / 255) <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, str_split(ltrim($hex, '#'), 2));

    return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
}

function ss_ratio(string $a, string $b): float
{
    [$x, $y] = [ss_lum($a), ss_lum($b)];

    return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
}

/** أزواج (نص، خلفية) كما تُرسم فعلًا في المكوّن، والحد الأدنى المطلوب لكل زوج. */
function ss_pairs(): array
{
    return [
        'اسم الملف على السطح' => ['fg', 'surface', 4.5],
        'اسم الملف في الحقل/القائمة' => ['fg', 'field', 4.5],
        'الاسم في الصف النشط' => ['fg', 'active-bg', 4.5],
        'الخصائص على القائمة' => ['muted', 'field', 4.5],
        'الخصائص على السطح' => ['muted', 'surface', 4.5],
        'الخصائص في الصف النشط' => ['muted', 'active-bg', 4.5],
        'نص الزر على سطحه' => ['fg', 'surface-2', 4.5],
        'ما يميّز النسخ المكررة' => ['warn', 'warn-bg', 4.5],
        'خطأ البحث/الملف المسحوب' => ['danger', 'danger-bg', 4.5],
        'وسم «الاختيار الحالي»' => ['ok', 'ok-bg', 4.5],
        'نص معطّل' => ['disabled-fg', 'disabled-bg', 4.5],
        'حدّ الحقل (مكوّن غير نصي ≥ 3)' => ['border', 'field', 3.0],
        'حدّ الحقل على السطح' => ['border', 'surface', 3.0],
        'حلقة التركيز على السطح' => ['focus', 'surface', 3.0],
        'حلقة التركيز على الحقل' => ['focus', 'field', 3.0],
        'علامة الصف المختار (شريط جانبي)' => ['primary', 'field', 3.0],
    ];
}

test('SS-32: light theme tokens meet WCAG - text 4.5:1 and borders/focus/graphics 3:1', function () {
    $t = ss_tokens('.ss-root');

    expect($t)->toHaveKeys(['ss-fg', 'ss-surface', 'ss-field', 'ss-muted', 'ss-border', 'ss-focus', 'ss-active-bg', 'ss-primary'])
        ->and(ss_ratio($t['ss-primary'], $t['ss-active-bg']))->toBeGreaterThanOrEqual(3.0, 'light: check mark on the active row');

    foreach (ss_pairs() as $label => [$fg, $bg, $min]) {
        expect(ss_ratio($t['ss-'.$fg], $t['ss-'.$bg]))->toBeGreaterThanOrEqual($min, "light: {$label} ({$fg} on {$bg})");
    }
});

test('SS-33: dark theme tokens (the admin panel is forced dark) meet WCAG - text 4.5:1 and borders/focus/graphics 3:1', function () {
    $dark = ss_tokens('.dark .ss-root');
    $t = $dark + ss_tokens('.ss-root');

    foreach (ss_pairs() as $label => [$fg, $bg, $min]) {
        expect(ss_ratio($t['ss-'.$fg], $t['ss-'.$bg]))->toBeGreaterThanOrEqual($min, "dark: {$label} ({$fg} on {$bg})");
    }

    // علامة ✓ في الغامق تُرسم بلون التركيز (قاعدة .dark .ss-root .ss-check) على الصف النشط؛ وفي الفاتح بلون primary.
    expect(ss_ratio($t['ss-focus'], $t['ss-active-bg']))->toBeGreaterThanOrEqual(3.0, 'dark: check mark on the active row')
        ->and(ss_assets_source())->toContain('.dark .ss-root .ss-check { color: var(--ss-focus); }');

    // الغامق يعرّف كل الألوان المستعملة (لا يرث لونًا فاتحًا على خلفية غامقة بالخطأ).
    foreach (['ss-surface', 'ss-field', 'ss-fg', 'ss-muted', 'ss-active-bg', 'ss-warn', 'ss-warn-bg', 'ss-danger', 'ss-danger-bg', 'ss-ok', 'ss-ok-bg', 'ss-disabled-fg', 'ss-disabled-bg', 'ss-focus'] as $k) {
        expect($dark)->toHaveKey($k);
    }
});

test('SS-34: every CSS rule is scoped under .ss-root, so the component cannot restyle other parts of the panel or the site', function () {
    $css = ss_assets_source();
    preg_match('/<style[^>]*>(.*?)<\/style>/s', $css, $m);
    $body = preg_replace('/\/\*.*?\*\//s', '', $m[1]);
    $body = preg_replace('/@media[^{]*\{(.*)\}\s*$/sm', '$1', $body) ?? $body;
    preg_match_all('/([^{}]+)\{[^{}]*\}/', $body, $rules);
    $unscoped = [];

    foreach ($rules[1] as $selectorList) {
        // فواصل القائمة فقط (لا فواصل داخل :where(a, b)).
        foreach (preg_split('/,(?![^()]*\))/', $selectorList) as $selector) {
            if (trim($selector) !== '' && ! str_contains($selector, '.ss-root')) {
                $unscoped[] = trim($selector);
            }
        }
    }

    expect($unscoped)->toBe([]);
});

test('SS-35: the component follows the combobox/listbox contract for screen readers and keyboard', function () {
    $c = ss_component_source();

    expect($c)->toContain('role="combobox"')->toContain('aria-haspopup="listbox"')->toContain('aria-expanded')->toContain('aria-controls')
        ->toContain('aria-activedescendant')->toContain('role="listbox"')->toContain('role="option"')->toContain('aria-selected')
        ->toContain('role="status"')->toContain('aria-live="polite"')->toContain('role="alert"')
        ->toContain('data-no-dirty')->toContain('autocomplete="off"')
        ->toContain('x-on:keydown.escape')->toContain('x-on:click.outside');

    $js = ss_assets_source();

    // الأسهم وEnter في حقل البحث (onKey)، وEscape على جذر المكوّن (يغلق ويعيد التركيز للزر)، وTab يغلق عند خروج التركيز (focusin.window).
    foreach (["'ArrowDown'", "'ArrowUp'", "'Enter'"] as $key) {
        expect($js)->toContain("e.key === {$key}");
    }
    expect($c)->toContain('x-on:focusin.window')->and($js)->toContain('close(true)');
});

test('SS-36: file names and numbers are isolated left-to-right inside right-to-left pages (bdi for tokens, isolate for names)', function () {
    $c = ss_component_source();
    $css = ss_assets_source();

    expect($c)->toContain('<bdi')->toContain('segs(')->toContain(":dir=\"nameDir\"")
        ->and($css)->toContain('unicode-bidi: isolate')->toContain('[dir=rtl] .ss-root .ss-name[dir=ltr]')
        // الفاصل «·» على الحاويات لا على الـbdi (اتجاه كل bdi يختلف فكان الفاصل يقع في جهات مختلفة).
        ->and($css)->toContain('.ss-part + .ss-part::before')->not->toContain('bdi + bdi::before');
});

test('SS-37: the shared assets are emitted once, contain no emoji, and the component never includes a storage path or internal column', function () {
    $css = ss_assets_source();
    $c = ss_component_source();

    expect(substr_count($css, '@once'))->toBe(1)->and(substr_count($css, '@endonce'))->toBe(1)
        ->and(preg_match('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}]/u', $css.$c))->toBe(0)
        ->and(strtolower($css.$c))->not->toContain('storage_path')->not->toContain('->path')->not->toContain('sha256');
});
