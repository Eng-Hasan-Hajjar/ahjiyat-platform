<?php

/**
 * عيب واجهة حقيقي (اكتُشف بفحص بصري، لا تلتقطه اختبارات الخادم): عنصر زائف ::before/::after مطلق الموضع يغطي
 * عنصره بلا pointer-events:none يلتقط النقر قبل أي مربع/حقل/زر غير موضوع بداخله (العنصر الموضوع يُرسَم فوق
 * المحتوى العادي حتى لو كان شفافًا). هذا الاختبار يمنع عودته لأي طبقة زخرفية مستقبلية بـapp.css.
 */
function cssRules(string $css): array
{
    preg_match_all('/([^{}@]+)\{([^{}]*)\}/', preg_replace('#/\*.*?\*/#s', '', $css), $m, PREG_SET_ORDER);

    return array_map(fn ($r) => [trim($r[1]), $r[2]], $m);
}

test('every absolutely-positioned decorative pseudo-element declares pointer-events:none (own rule or its owner rule)', function () {
    $css = file_get_contents(__DIR__.'/../../resources/css/app.css');
    $rules = cssRules($css);
    $offenders = [];
    $checked = 0;

    foreach ($rules as [$selector, $body]) {
        if (! preg_match('/::(before|after)\b/', $selector) || ! preg_match('/position:\s*absolute/', $body)) {
            continue;
        }

        $checked++;
        $base = trim(preg_replace('/::(before|after).*$/', '', $selector));
        $ownerNone = false;

        foreach ($rules as [$s, $b]) {
            if ($s === $base && preg_match('/pointer-events:\s*none/', $b)) {
                $ownerNone = true; // pointer-events موروثة: عنصره الأصلي يعطّله فيرثه الزائف
            }
        }

        if (! preg_match('/pointer-events:\s*none/', $body) && ! $ownerNone) {
            $offenders[] = $selector;
        }
    }

    expect($checked)->toBeGreaterThan(0)->and($offenders)->toBe([]);
});

test('the puzzle-card glow layer explicitly lets clicks through to the form controls inside the card', function () {
    $css = file_get_contents(__DIR__.'/../../resources/css/app.css');

    foreach (cssRules($css) as [$selector, $body]) {
        if ($selector === '.puzzle-card::before') {
            expect($body)->toMatch('/pointer-events:\s*none/');

            return;
        }
    }

    $this->fail('.puzzle-card::before rule not found');
});
