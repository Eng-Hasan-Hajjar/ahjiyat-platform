<?php

use App\Services\Cinematic\Interactions\InteractionEngine;

/*
| CIN-2 - محرّك التفاعلات: الأنواع الثلاثة الجديدة (answer / inspect / multi_lock) والحقول العامة (reveal / hints).
|  - CIN2E-01: إجابة رقمية: الأرقام العربية والفارسية والإنجليزية والوحدات المسموحة، ورفض ما ليس الجواب بدقة (لا قبول بالقرب).
|  - CIN2E-02: التلميحات بالتتابع حسب رقم المحاولة ولا عقوبة وقت، ونص الكشف لا يخرج إلا بعد النجاح.
|  - CIN2E-03: الفحص: يلزم فحص كل المناطق المطلوبة (بما فيها الوجه الخلفي back) والعدد العام يطابق الحل.
|  - CIN2E-04: خزانة متعددة الأقفال: كل قفل يُحكم على حدة، المفاتيح مطبَّعة، وعدم الفتح قبل المشاهد المطلوبة.
|  - CIN2E-05: الواجهة العامة قائمة بيضاء: لا accepted ولا codes ولا lock_hints ولا hints ولا reveal ولا required.
|  - CIN2E-06: التحقق يرفض التعريفات الناقصة أو المتناقضة للأنواع الجديدة.
*/

function cin2e_answer(): array
{
    return [
        ['kind' => 'answer', 'prompt' => 'كم دقيقة؟', 'input' => 'number', 'unit_label' => 'دقيقة', 'facts' => [['label' => 'المكتوب', 'value' => '11:40'], ['label' => 'الساعة', 'value' => '11:20']]],
        ['accepted' => ['20'], 'units' => ['دقيقة', 'دقائق', 'min'], 'hints' => ['تلميح 1', 'تلميح 2', 'تلميح 3'], 'reveal' => 'سر بعد النجاح'],
    ];
}

function cin2e_inspect(): array
{
    return [
        ['kind' => 'inspect', 'prompt' => 'افحص', 'required_count' => 3, 'back' => ['label' => 'الظهر', 'text' => 'نص الظهر'], 'areas' => [
            ['id' => 'a', 'label' => 'أ', 'x' => 1, 'y' => 1, 'w' => 10, 'h' => 10, 'note' => 'ن1'],
            ['id' => 'b', 'label' => 'ب', 'x' => 20, 'y' => 1, 'w' => 10, 'h' => 10, 'note' => 'ن2'],
            ['id' => 'decoy', 'label' => 'ملهٍ', 'x' => 40, 'y' => 1, 'w' => 10, 'h' => 10, 'note' => 'ن3'],
        ]],
        ['required' => ['a', 'b', 'back']],
    ];
}

function cin2e_lock(): array
{
    return [
        ['kind' => 'multi_lock', 'prompt' => 'افتح', 'locks' => [
            ['id' => 'l1', 'label' => 'الأول', 'segments' => [['type' => 'digits', 'length' => 2]]],
            ['id' => 'l2', 'label' => 'الثاني', 'segments' => [['type' => 'letter', 'options' => ['أ', 'ب', 'ج']], ['type' => 'digits', 'length' => 2]]],
        ]],
        ['codes' => ['l1' => '20', 'l2' => 'ب04'], 'lock_hints' => ['l1' => 'تلميح الأول', 'l2' => 'تلميح الثاني'], 'requires_scenes' => ['need_one', 'need_two'], 'reveal' => 'انفتحت'],
    ];
}

test('CIN2E-01: the numeric answer accepts Arabic, Persian and Latin digits and allowed units, and rejects everything that is not exactly the answer', function () {
    $e = new InteractionEngine;
    [$pub, $sol] = cin2e_answer();

    expect($e->validate('answer', $pub, $sol))->toBe([]);

    foreach (['20', '٢٠', '۲۰', ' 20 ', '020', '20 دقيقة', '٢٠ دقائق', '20min', '20.0', '٢٠٫٠'] as $ok) {
        expect($e->evaluate('answer', $pub, $sol, ['answer' => $ok])['correct'])->toBeTrue(json_encode($ok, JSON_UNESCAPED_UNICODE));
    }

    foreach (['19', '21', '200', '2', '', '  ', 'abc', 'عشرون', '20 ثانية', '2 0', '2 دقيقة 0', '2دقيقة0', '20,5', '-20', '١٩', '٢٠٠', '20 20', str_repeat('2', 200)] as $bad) {
        expect($e->evaluate('answer', $pub, $sol, ['answer' => $bad])['correct'])->toBeFalse(json_encode($bad, JSON_UNESCAPED_UNICODE));
    }

    // مدخلات مشوَّهة لا ترمي استثناءً وتُعدّ خطأ.
    foreach ([[], ['answer' => ['20']], ['answer' => null], ['answer' => new stdClass], ['wrong_key' => '20']] as $weird) {
        expect($e->evaluate('answer', $pub, $sol, (array) $weird)['correct'])->toBeFalse();
    }
});

test('CIN2E-02: hints arrive in order by attempt number (the last one repeats), no time is involved, and the reveal text leaves the server only on success', function () {
    $e = new InteractionEngine;
    [$pub, $sol] = cin2e_answer();

    $hints = [];

    foreach ([1, 2, 3, 4, 9] as $attempt) {
        $r = $e->evaluate('answer', $pub, $sol, ['answer' => '5'], [], ['attempt' => $attempt]);
        expect($r['correct'])->toBeFalse()->and($r['reveal'])->toBeNull();
        $hints[] = $r['hint'];
    }

    expect($hints)->toBe(['تلميح 1', 'تلميح 2', 'تلميح 3', 'تلميح 3', 'تلميح 3']);

    $ok = $e->evaluate('answer', $pub, $sol, ['answer' => '٢٠'], [], ['attempt' => 5]);
    expect($ok['correct'])->toBeTrue()->and($ok['reveal'])->toBe('سر بعد النجاح')->and($ok['hint'])->toBeNull();

    // لا حقل زمني في الإدخال ولا في النتيجة: التأخر لا يغيّر الحكم.
    expect(array_keys($ok))->not->toContain('time')->not->toContain('penalty')->not->toContain('elapsed');
});

test('CIN2E-03: the inspect needs every required area including the back of the photo, and the public count must match the solution', function () {
    $e = new InteractionEngine;
    [$pub, $sol] = cin2e_inspect();

    expect($e->validate('inspect', $pub, $sol))->toBe([]);

    expect($e->evaluate('inspect', $pub, $sol, ['inspected' => ['a', 'b']])['correct'])->toBeFalse()
        ->and($e->evaluate('inspect', $pub, $sol, ['inspected' => ['a', 'b', 'decoy']])['correct'])->toBeFalse()
        ->and($e->evaluate('inspect', $pub, $sol, ['inspected' => ['a', 'b', 'back']])['correct'])->toBeTrue()
        ->and($e->evaluate('inspect', $pub, $sol, ['inspected' => ['back', 'decoy', 'b', 'a']])['correct'])->toBeTrue()
        ->and($e->evaluate('inspect', $pub, $sol, ['inspected' => 'a,b,back'])['correct'])->toBeFalse()
        ->and($e->evaluate('inspect', $pub, $sol, [])['correct'])->toBeFalse();

    // back لا يصلح مطلوبًا إن لم يُعرَّف وجه خلفي، والعدد العام يجب أن يطابق.
    $noBack = array_diff_key($pub, ['back' => 1]);
    expect($e->validate('inspect', $noBack, $sol))->not->toBe([])
        ->and($e->validate('inspect', array_merge($pub, ['required_count' => 2]), $sol))->not->toBe([])
        ->and($e->validate('inspect', $pub, ['required' => ['a', 'ghost', 'back']]))->not->toBe([])
        ->and($e->validate('inspect', $pub, ['required' => []]))->not->toBe([])
        ->and($e->validate('inspect', array_merge($pub, ['back' => ['label' => '', 'text' => 'x']]), $sol))->not->toBe([]);
});

test('CIN2E-04: every lock is judged on its own, codes are normalised, a lock cannot open before the required scenes, and one wrong lock never resets the right one', function () {
    $e = new InteractionEngine;
    [$pub, $sol] = cin2e_lock();

    expect($e->validate('multi_lock', $pub, $sol))->toBe([]);

    $both = ['values' => ['l1' => ['20'], 'l2' => ['ب', '04']]];
    $done = ['need_one', 'need_two'];

    // الشروط غير متحققة: مقفل حتى لو كانت الرموز صحيحة، ولا يخرج نص الكشف.
    $locked = $e->evaluate('multi_lock', $pub, $sol, $both, ['need_one']);
    expect($locked['correct'])->toBeFalse()->and($locked['locked'])->toBeTrue()->and($locked['reveal'])->toBeNull();

    $ok = $e->evaluate('multi_lock', $pub, $sol, $both, $done);
    expect($ok['correct'])->toBeTrue()->and($ok['reveal'])->toBe('انفتحت')->and($ok['detail'])->toBe(['l1' => true, 'l2' => true]);

    // الأرقام العربية والخيارات المنفصلة والنص المدمج كلها مقبولة.
    foreach ([['l1' => '٢٠', 'l2' => ['ب', '٠٤']], ['l1' => '20', 'l2' => 'ب04'], ['l1' => ['2', '0'], 'l2' => ['ب', '0', '4']]] as $values) {
        expect($e->evaluate('multi_lock', $pub, $sol, ['values' => $values], $done)['correct'])->toBeTrue(json_encode($values, JSON_UNESCAPED_UNICODE));
    }

    // قفل واحد خاطئ: يُبلَّغ عنه وبتلميحه فقط، والصحيح يبقى مُعلَّمًا صحيحًا.
    $half = $e->evaluate('multi_lock', $pub, $sol, ['values' => ['l1' => ['20'], 'l2' => ['أ', '04']]], $done);
    expect($half['correct'])->toBeFalse()->and($half['detail'])->toBe(['l1' => true, 'l2' => false])->and($half['hint'])->toBe('تلميح الثاني')->and($half['reveal'])->toBeNull();

    $first = $e->evaluate('multi_lock', $pub, $sol, ['values' => ['l1' => ['21'], 'l2' => ['ب', '04']]], $done);
    expect($first['detail'])->toBe(['l1' => false, 'l2' => true])->and($first['hint'])->toBe('تلميح الأول');

    // مدخلات ناقصة/مشوَّهة.
    foreach ([[], ['values' => 'x'], ['values' => ['l1' => ['20']]], ['values' => ['l1' => [['20']], 'l2' => 5]]] as $bad) {
        expect($e->evaluate('multi_lock', $pub, $sol, $bad, $done)['correct'])->toBeFalse();
    }
});

test('CIN2E-05: the public view of the new kinds is a strict whitelist - no accepted answer, codes, hints, reveal text or required areas ever reach the browser', function () {
    $e = new InteractionEngine;
    $secrets = ['accepted' => ['20'], 'codes' => ['l1' => '20'], 'lock_hints' => ['l1' => 'SECRET_LOCK_HINT'], 'hints' => ['SECRET_HINT'], 'reveal' => 'SECRET_REVEAL', 'required' => ['a'], 'units' => ['SECRET_UNIT'], 'solution' => ['x' => 1], 'requires_scenes' => ['SECRET_REQ']];

    foreach (['answer' => cin2e_answer(), 'inspect' => cin2e_inspect(), 'multi_lock' => cin2e_lock()] as $kind => [$pub, $sol]) {
        $view = $e->publicView($kind, $pub + $secrets + $sol);
        $json = json_encode($view, JSON_UNESCAPED_UNICODE);

        foreach (['SECRET_LOCK_HINT', 'SECRET_HINT', 'SECRET_REVEAL', 'SECRET_UNIT', 'SECRET_REQ', '"accepted"', '"codes"', '"lock_hints"', '"solution"', '"required"', '"units"', 'ب04'] as $needle) {
            expect($json)->not->toContain($needle, "{$kind}: {$needle}");
        }
    }

    // ما يلزم الواجهة موجود.
    expect($e->publicView('answer', cin2e_answer()[0])['facts'])->toHaveCount(2)
        ->and($e->publicView('inspect', cin2e_inspect()[0])['required_count'])->toBe(3)
        ->and($e->publicView('inspect', cin2e_inspect()[0])['back'])->toBe(['label' => 'الظهر', 'text' => 'نص الظهر'])
        ->and($e->publicView('multi_lock', cin2e_lock()[0])['locks'][1]['segments'][0]['options'])->toBe(['أ', 'ب', 'ج']);
});

test('CIN2E-06: incomplete or inconsistent definitions of the new kinds are rejected before they can be saved', function () {
    $e = new InteractionEngine;
    [$ap, $as] = cin2e_answer();
    [$lp, $ls] = cin2e_lock();

    expect($e->validate('answer', $ap, []))->not->toBe([])
        ->and($e->validate('answer', $ap, ['accepted' => ['abc']]))->not->toBe([])   // عددي ⇒ الجواب عدد
        ->and($e->validate('answer', $ap, ['accepted' => []]))->not->toBe([])
        ->and($e->validate('answer', array_merge($ap, ['facts' => array_fill(0, 7, ['label' => 'x', 'value' => 'y'])]), $as))->not->toBe([])
        ->and($e->validate('answer', $ap, array_merge($as, ['hints' => array_fill(0, 9, 'x')])))->not->toBe([])
        ->and($e->validate('answer', $ap, array_merge($as, ['reveal' => str_repeat('ا', 700)])))->not->toBe([])
        ->and($e->validate('multi_lock', $lp, ['codes' => ['l1' => '2', 'l2' => 'ب04']]))->not->toBe([])         // طول رقمي خاطئ
        ->and($e->validate('multi_lock', $lp, ['codes' => ['l1' => '20', 'l2' => 'د04']]))->not->toBe([])         // حرف خارج الخيارات
        ->and($e->validate('multi_lock', $lp, ['codes' => ['l1' => '20']]))->not->toBe([])                       // قفل بلا رمز
        ->and($e->validate('multi_lock', array_merge($lp, ['locks' => []]), $ls))->not->toBe([])
        ->and($e->validate('multi_lock', $lp, array_merge($ls, ['requires_scenes' => ['Bad Key']])))->not->toBe([]);
});
