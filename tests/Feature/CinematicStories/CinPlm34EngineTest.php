<?php

use App\Services\Cinematic\Admin\SceneFormCodec;
use App\Services\Cinematic\Interactions\InteractionEngine;

/*
| PLM-3/4 - محرّك التفاعلات: النوعان الجديدان board_move (رقعة: نقلة حصان) و inference (استنتاج + مصدره).
|  - PLM34E-01: تعريف الرقعة: حدود، حصان واحد، آثار فريدة، وأثر واحد فقط معروض هو نقلة حصان قانونية وهو الصحيح.
|  - PLM34E-02: الاتجاه: الصف 1 في الأسفل والعمود 1 على اليسار، وهذا ثابت في الواجهة العامة لا يتغير بإعدادات المحرّر.
|  - PLM34E-03: تقييم النقلة: (3,4) فقط؛ الأثران الآخران المعروضان مرفوضان؛ نقلة قانونية غير معروضة مرفوضة؛ أرقام عربية مقبولة؛ مدخلات مشوَّهة خطأ لا استثناء.
|  - PLM34E-04: الاستنتاج: الزوج (الاستنتاج + مجموعة المصادر نفسها) أو لا شيء؛ لا قبول بنصف الجواب ولا بكل المصادر.
|  - PLM34E-05: الواجهة العامة قائمة بيضاء: لا solution ولا correct ولا sources الصحيحة ولا تلميحات ولا reveal، ولا أي كشف في نتيجة الخطأ.
|  - PLM34E-06: مرمّز نموذج المحرّر يحفظ النوعين ذهابًا وإيابًا دون فقد.
*/

function plm34e_board(): array
{
    return [
        [
            'kind' => 'board_move', 'prompt' => 'انقل الحصان إلى أثره الصحيح.',
            'board' => ['rows' => 8, 'cols' => 8],
            'piece' => ['type' => 'knight', 'id' => 'knight', 'label' => 'الحصان', 'row' => 5, 'col' => 5],
            'targets' => [
                ['id' => 'trace_a', 'label' => 'الأثر أ', 'row' => 3, 'col' => 4],
                ['id' => 'trace_b', 'label' => 'الأثر ب', 'row' => 3, 'col' => 5],
                ['id' => 'trace_c', 'label' => 'الأثر ج', 'row' => 4, 'col' => 4],
            ],
        ],
        ['correct' => 'trace_a', 'hints' => ['راجع حركة الحصان.']],
    ];
}

function plm34e_inference(): array
{
    return [
        [
            'kind' => 'inference', 'prompt' => 'أي العبارتين تسندها الأدلة؟',
            'options' => [['id' => 'opt_a', 'label' => 'العبارة أ'], ['id' => 'opt_b', 'label' => 'العبارة ب']],
            'sources' => [
                ['id' => 'src_message', 'label' => 'الرسالة', 'text' => 'نص الرسالة', 'group' => 'الرسالة'],
                ['id' => 'src_photo', 'label' => 'الصورة', 'text' => 'نص الصورة', 'group' => 'الصورة'],
                ['id' => 'src_card', 'label' => 'البطاقة', 'text' => 'نص البطاقة', 'group' => 'الصورة'],
            ],
        ],
        ['conclusion' => 'opt_b', 'sources' => ['src_photo', 'src_card'], 'hints' => ['تلميح 1', 'تلميح 2'], 'reveal' => 'نص الكشف السري', 'requires_scenes' => ['prior_scene']],
    ];
}

test('PLM34E-01: a board definition needs one knight, unique targets inside the board, and exactly one displayed target that is a legal knight move and is the correct one', function () {
    $e = new InteractionEngine;
    [$pub, $sol] = plm34e_board();

    expect($e->kinds())->toContain('board_move', 'inference')->and($e->validate('board_move', $pub, $sol))->toBe([]);

    // بلا حل أو بحل خارج الآثار.
    expect($e->validate('board_move', $pub, []))->not->toBe([])
        ->and($e->validate('board_move', $pub, ['correct' => 'nope']))->not->toBe([]);

    // الصحيح هو الأثر غير القانوني: يُرفض (الأثر ب = (3,5) ليس نقلة حصان).
    expect($e->validate('board_move', $pub, ['correct' => 'trace_b']))->not->toBe([]);

    // أثران قانونيان معروضان معًا = غموض فيُرفض.
    $two = $pub;
    $two['targets'][1] = ['id' => 'trace_b', 'label' => 'الأثر ب', 'row' => 3, 'col' => 6];
    expect($e->validate('board_move', $two, $sol))->not->toBe([]);

    // لا أثر قانونيًا معروضًا إطلاقًا.
    $none = $pub;
    $none['targets'][0] = ['id' => 'trace_a', 'label' => 'الأثر أ', 'row' => 5, 'col' => 6];
    expect($e->validate('board_move', $none, $sol))->not->toBe([]);

    // أثر خارج الرقعة / مكرر الموضع / فوق موضع القطعة / معرّف مكرر.
    $out = $pub;
    $out['targets'][2] = ['id' => 'trace_c', 'label' => 'ج', 'row' => 9, 'col' => 4];
    $dup = $pub;
    $dup['targets'][2] = ['id' => 'trace_c', 'label' => 'ج', 'row' => 3, 'col' => 5];
    $onStart = $pub;
    $onStart['targets'][2] = ['id' => 'trace_c', 'label' => 'ج', 'row' => 5, 'col' => 5];
    $sameId = $pub;
    $sameId['targets'][2] = ['id' => 'trace_a', 'label' => 'ج', 'row' => 4, 'col' => 4];

    foreach ([$out, $dup, $onStart, $sameId] as $bad) {
        expect($e->validate('board_move', $bad, $sol))->not->toBe([]);
    }

    // القطعة: حصان فقط وداخل الرقعة. الرقعة: بين 5 و12.
    $piece = $pub;
    $piece['piece']['row'] = 0;
    $big = $pub;
    $big['board'] = ['rows' => 13, 'cols' => 8];
    $small = $pub;
    $small['board'] = ['rows' => 3, 'cols' => 3];
    $rook = $pub;
    $rook['piece']['type'] = 'rook';

    foreach ([$piece, $big, $small, $rook] as $bad) {
        expect($e->validate('board_move', $bad, $sol))->not->toBe([]);
    }

    // آثار: 1–6 فقط، ولا آثار فارغة.
    $empty = $pub;
    $empty['targets'] = [];
    $seven = $pub;
    $seven['targets'] = array_map(fn ($i) => ['id' => "t{$i}", 'label' => "ث{$i}", 'row' => 1 + $i, 'col' => 1], range(1, 7));
    expect($e->validate('board_move', $empty, $sol))->not->toBe([])->and($e->validate('board_move', $seven, ['correct' => 't1']))->not->toBe([]);
});

test('PLM34E-02: the board orientation is fixed - row 1 at the bottom, column 1 at the left - and cannot be changed through the public definition', function () {
    $e = new InteractionEngine;
    [$pub, $sol] = plm34e_board();

    expect(InteractionEngine::BOARD_ORIGIN)->toBe('bottom-left');

    $view = $e->publicView('board_move', $pub);
    expect($view['board'])->toBe(['rows' => 8, 'cols' => 8, 'origin' => 'bottom-left']);

    // محاولة فرض أصل آخر من التعريف: تُتجاهل في الواجهة العامة.
    $tampered = $pub;
    $tampered['board']['origin'] = 'top-left';
    expect($e->publicView('board_move', $tampered)['board']['origin'])->toBe('bottom-left');

    // الحل بالإحداثيات المعرَّفة (الصف 3، العمود 4) لا بالاتجاه الرأسي المقلوب (9−3=6).
    expect($e->evaluate('board_move', $pub, $sol, ['row' => 3, 'col' => 4])['correct'])->toBeTrue()
        ->and($e->evaluate('board_move', $pub, $sol, ['row' => 6, 'col' => 4])['correct'])->toBeFalse()
        ->and($e->evaluate('board_move', $pub, $sol, ['row' => 3, 'col' => 5])['correct'])->toBeFalse();
});

test('PLM34E-03: only the displayed legal trace (3,4) is accepted; the other displayed traces, legal-but-hidden moves and malformed input are refused without leaking or throwing', function () {
    $e = new InteractionEngine;
    [$pub, $sol] = plm34e_board();

    // الصحيح: (3,4) بأي كتابة رقمية.
    foreach ([['row' => 3, 'col' => 4], ['row' => '3', 'col' => '4'], ['row' => '٣', 'col' => '٤'], ['row' => '۳', 'col' => '۴'], ['row' => ' 3 ', 'col' => ' 4 ']] as $ok) {
        expect($e->evaluate('board_move', $pub, $sol, $ok)['correct'])->toBeTrue(json_encode($ok, JSON_UNESCAPED_UNICODE));
    }

    // آثار معروضة أخرى: (3,5) و(4,4) — ليست نقلة حصان ولا هي الجواب.
    foreach ([[3, 5], [4, 4]] as [$r, $c]) {
        $res = $e->evaluate('board_move', $pub, $sol, ['row' => $r, 'col' => $c]);
        expect($res['correct'])->toBeFalse()->and($res['reveal'])->toBeNull();
    }

    // نقلات حصان قانونية لكنها ليست بين الآثار المعروضة: كلها مرفوضة (القرار للخادم لا لقانونية النقلة وحدها).
    foreach ([[3, 6], [4, 3], [4, 7], [6, 3], [6, 7], [7, 4], [7, 6]] as [$r, $c]) {
        expect($e->evaluate('board_move', $pub, $sol, ['row' => $r, 'col' => $c])['correct'])->toBeFalse("{$r},{$c}");
    }

    // موضع القطعة نفسه وخارج الرقعة وصفر وسالب وكسور وأنواع غير رقمية ونقص حقول.
    foreach ([
        ['row' => 5, 'col' => 5], ['row' => 0, 'col' => 0], ['row' => 9, 'col' => 4], ['row' => -3, 'col' => 4], ['row' => 3.5, 'col' => 4], ['row' => '3.0', 'col' => '4'],
        ['row' => 'ثلاثة', 'col' => 'أربعة'], ['row' => [3], 'col' => [4]], ['row' => null, 'col' => null], ['row' => 3], ['col' => 4], [], ['row' => true, 'col' => true],
        ['row' => '3 4', 'col' => ''], ['target' => 'trace_a'], ['placements' => ['knight' => 'trace_a']], ['row' => str_repeat('3', 500), 'col' => '4'],
    ] as $bad) {
        $res = $e->evaluate('board_move', $pub, $sol, $bad);
        expect($res['correct'])->toBeFalse(json_encode($bad, JSON_UNESCAPED_UNICODE))->and($res['locked'])->toBeFalse();
    }

    // requires_scenes: مقفل حتى تكتمل المشاهد المطلوبة (لا قيمة لإجابة صحيحة قبل ذلك).
    $locked = $sol + ['requires_scenes' => ['intro']];
    expect($e->validate('board_move', $pub, $locked))->toBe([]);
    $res = $e->evaluate('board_move', $pub, $locked, ['row' => 3, 'col' => 4], []);
    expect($res['locked'])->toBeTrue()->and($res['correct'])->toBeFalse()
        ->and($e->evaluate('board_move', $pub, $locked, ['row' => 3, 'col' => 4], ['intro'])['correct'])->toBeTrue();
});

test('PLM34E-04: an inference is accepted only as a complete pair - the right conclusion together with exactly the right set of sources', function () {
    $e = new InteractionEngine;
    [$pub, $sol] = plm34e_inference();
    $done = ['prior_scene'];

    expect($e->validate('inference', $pub, $sol))->toBe([]);

    $ok = fn (array $s) => $e->evaluate('inference', $pub, $sol, $s, $done)['correct'];

    // الزوج الصحيح بأي ترتيب وبتكرار لا يغيّر المجموعة.
    expect($ok(['conclusion' => 'opt_b', 'sources' => ['src_photo', 'src_card']]))->toBeTrue()
        ->and($ok(['conclusion' => 'opt_b', 'sources' => ['src_card', 'src_photo']]))->toBeTrue()
        ->and($ok(['conclusion' => 'opt_b', 'sources' => ['src_card', 'src_photo', 'src_card']]))->toBeTrue();

    // الاستنتاج الصحيح بنصف المصدر، أو بمصدر زائد، أو بكل المصادر، أو بمصدر خاطئ، أو بلا مصدر: كلها مرفوضة.
    foreach ([['src_photo'], ['src_card'], ['src_photo', 'src_card', 'src_message'], ['src_message'], ['src_message', 'src_photo'], ['src_message', 'src_card'], []] as $sources) {
        expect($ok(['conclusion' => 'opt_b', 'sources' => $sources]))->toBeFalse('opt_b + '.json_encode($sources));
    }

    // مصدر صحيح مع استنتاج خاطئ، ومصدر مجهول، ومدخلات مشوَّهة: كلها مرفوضة بلا استثناء.
    foreach ([
        ['conclusion' => 'opt_a', 'sources' => ['src_photo', 'src_card']], ['conclusion' => 'opt_a', 'sources' => ['src_message']], ['conclusion' => 'opt_b', 'sources' => ['src_photo', 'src_card', 'ghost']],
        ['conclusion' => 'ghost', 'sources' => ['src_photo', 'src_card']], ['conclusion' => 'opt_b'], ['sources' => ['src_photo', 'src_card']], ['conclusion' => ['opt_b'], 'sources' => ['src_photo', 'src_card']],
        ['conclusion' => 'opt_b', 'sources' => 'src_photo,src_card'], ['conclusion' => 'opt_b', 'sources' => [['src_photo'], ['src_card']]], ['conclusion' => null, 'sources' => null], [],
        ['conclusion' => 'opt_b', 'sources' => array_fill(0, 40, 'src_photo')], ['option' => 'opt_b', 'inspected' => ['src_photo', 'src_card']],
    ] as $bad) {
        $res = $e->evaluate('inference', $pub, $sol, $bad, $done);
        expect($res['correct'])->toBeFalse(json_encode($bad, JSON_UNESCAPED_UNICODE))->and($res['locked'])->toBeFalse();
    }

    // قبل إتمام المشهد المطلوب: مقفل ولو كان الزوج صحيحًا.
    $early = $e->evaluate('inference', $pub, $sol, ['conclusion' => 'opt_b', 'sources' => ['src_photo', 'src_card']], []);
    expect($early['locked'])->toBeTrue()->and($early['correct'])->toBeFalse()->and($early['reveal'])->toBeNull();

    // التعريف: الحل يجب ألا يساوي كل المصادر (وإلا لا معنى للاختيار)، ولا يشير إلى مصدر أو استنتاج غير معرَّف.
    expect($e->validate('inference', $pub, ['conclusion' => 'opt_b', 'sources' => ['src_message', 'src_photo', 'src_card']]))->not->toBe([])
        ->and($e->validate('inference', $pub, ['conclusion' => 'opt_b', 'sources' => []]))->not->toBe([])
        ->and($e->validate('inference', $pub, ['conclusion' => 'opt_b', 'sources' => ['ghost']]))->not->toBe([])
        ->and($e->validate('inference', $pub, ['conclusion' => 'ghost', 'sources' => ['src_card']]))->not->toBe([])
        ->and($e->validate('inference', $pub, []))->not->toBe([]);

    // استنتاج واحد فقط أو مصدر واحد فقط = تعريف ناقص.
    $oneOpt = $pub;
    $oneOpt['options'] = [['id' => 'opt_a', 'label' => 'أ']];
    $oneSrc = $pub;
    $oneSrc['sources'] = [['id' => 'src_card', 'label' => 'ب', 'text' => 'ن', 'group' => 'ص']];
    expect($e->validate('inference', $oneOpt, ['conclusion' => 'opt_a', 'sources' => ['src_photo']]))->not->toBe([])
        ->and($e->validate('inference', $oneSrc, ['conclusion' => 'opt_b', 'sources' => ['src_card']]))->not->toBe([]);
});

test('PLM34E-05: the public view is a whitelist - no solution, no correct trace, no right sources, no hints and no reveal - and a wrong submission discloses nothing of the answer', function () {
    $e = new InteractionEngine;

    [$bp, $bs] = plm34e_board();
    $view = json_encode($e->publicView('board_move', $bp), JSON_UNESCAPED_UNICODE);
    expect($view)->not->toContain('"correct"')->and($view)->not->toContain('hints')->and($view)->not->toContain('requires_scenes')
        ->and($e->publicView('board_move', $bp))->toHaveKeys(['kind', 'prompt', 'board', 'piece', 'targets'])
        ->and($e->publicView('board_move', $bp)['targets'])->toHaveCount(3);

    [$ip, $is] = plm34e_inference();
    $iv = $e->publicView('inference', $ip);
    $json = json_encode($iv, JSON_UNESCAPED_UNICODE);
    expect($json)->not->toContain('conclusion')->and($json)->not->toContain('"correct"')->and($json)->not->toContain('نص الكشف السري')->and($json)->not->toContain('تلميح 1')
        ->and($json)->not->toContain('requires_scenes')->and($iv['options'])->toHaveCount(2)->and($iv['sources'])->toHaveCount(3);

    // نتيجة الخطأ: لا كشف ولا تفصيل (detail خاص بالأقفال فقط).
    $wrongBoard = $e->evaluate('board_move', $bp, $bs, ['row' => 3, 'col' => 5]);
    $wrongInf = $e->evaluate('inference', $ip, $is, ['conclusion' => 'opt_b', 'sources' => ['src_photo']], ['prior_scene']);
    foreach ([$wrongBoard, $wrongInf] as $r) {
        expect($r['correct'])->toBeFalse()->and($r['reveal'])->toBeNull()->and($r['detail'])->toBeNull();
        $blob = json_encode($r, JSON_UNESCAPED_UNICODE);
        expect($blob)->not->toContain('trace_a')->and($blob)->not->toContain('src_card')->and($blob)->not->toContain('opt_b')->and($blob)->not->toContain('نص الكشف السري');
    }

    // التلميح يتقدّم برقم المحاولة، ونص الكشف لا يخرج إلا مع النجاح.
    $first = $e->evaluate('inference', $ip, $is, ['conclusion' => 'opt_a', 'sources' => ['src_message']], ['prior_scene'], ['attempt' => 1]);
    $second = $e->evaluate('inference', $ip, $is, ['conclusion' => 'opt_a', 'sources' => ['src_message']], ['prior_scene'], ['attempt' => 2]);
    $win = $e->evaluate('inference', $ip, $is, ['conclusion' => 'opt_b', 'sources' => ['src_photo', 'src_card']], ['prior_scene'], ['attempt' => 3]);
    expect($first['hint'])->toBe('تلميح 1')->and($second['hint'])->toBe('تلميح 2')->and($first['reveal'])->toBeNull()->and($win['correct'])->toBeTrue()->and($win['reveal'])->toBe('نص الكشف السري');
});

test('PLM34E-06: the admin form codec round-trips both new kinds without losing a field', function () {
    $e = new InteractionEngine;

    foreach (['board_move' => plm34e_board(), 'inference' => plm34e_inference()] as $kind => [$pub, $sol]) {
        $form = SceneFormCodec::encodeInteraction($pub, $sol);
        [$pub2, $sol2] = SceneFormCodec::decodeInteraction($kind, $form);
        $errors = $e->validate($kind, $pub2, $sol2);

        expect($errors)->toBe([], $kind)
            ->and($e->publicView($kind, $pub2))->toBe($e->publicView($kind, $pub), $kind);

        // الحل نفسه: التقييم على الزوج الصحيح ينجح بعد الدورة.
        $answer = $kind === 'board_move' ? ['row' => 3, 'col' => 4] : ['conclusion' => 'opt_b', 'sources' => ['src_photo', 'src_card']];
        expect($e->evaluate($kind, $pub2, $sol2, $answer, ['prior_scene'])['correct'])->toBeTrue($kind);
    }

    // حقول الرقعة تُرمَّز بصيغة المحرّر المقروءة.
    $form = SceneFormCodec::encodeInteraction(...plm34e_board());
    expect($form['board_rows'])->toBe('8')->and($form['board_cols'])->toBe('8')->and($form['piece'])->toBe('knight|الحصان|5|5')->and($form['correct'])->toBe('trace_a');
});
