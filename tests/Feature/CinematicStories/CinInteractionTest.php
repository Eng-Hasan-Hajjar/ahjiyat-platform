<?php

use App\Services\Cinematic\Interactions\InteractionEngine;

require_once __DIR__.'/_support.php';

/*
| CIN-1 - محرك التفاعلات: الأنواع الثمانية (نقطة ساخنة، باب/درج، ترتيب، سحب وإفلات، اختيار عنصر، مقارنة، كشف رمز بشرط، مصباح).
| التحقق من التعريف، العرض العام بقائمة بيضاء بلا أي حل، التقييم الصحيح/الخاطئ، المدخلات المشوَّهة، الشروط.
*/

test('INT-01: every one of the eight kinds validates a complete definition and rejects an incomplete or inconsistent one', function () {
    $engine = new InteractionEngine;
    $defs = cin_defs();

    expect(array_keys($defs))->toEqualCanonicalizing($engine->kinds())->and(count($engine->kinds()))->toBe(8);

    foreach ($defs as $kind => [$public, $solution]) {
        expect($engine->validate($kind, $public, $solution))->toBe([], "kind {$kind} should be valid");
        expect($engine->validate($kind, $public, []))->not->toBe([], "kind {$kind} without a solution must be rejected");
        expect($engine->validate($kind, ['kind' => $kind] + array_diff_key($public, ['prompt' => 1]), $solution))->toContain('نص السؤال/التعليمات مطلوب.');
    }

    expect($engine->validate('teleport', [], []))->toBe(['نوع التفاعل غير معروف.'])
        ->and($engine->validate('hotspot', $defs['hotspot'][0], ['correct' => ['ghost']]))->not->toBe([])
        ->and($engine->validate('sequence', $defs['sequence'][0], ['order' => ['circle', 'circle', 'square']]))->not->toBe([])
        ->and($engine->validate('flashlight', array_merge($defs['flashlight'][0], ['light_radius' => 90]), $defs['flashlight'][1]))->not->toBe([])
        ->and($engine->validate('hotspot', ['kind' => 'hotspot', 'prompt' => 'x', 'areas' => [['id' => 'Bad Id', 'label' => 'x', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10]]], ['correct' => ['Bad Id']]))->not->toBe([])
        ->and($engine->validate('hotspot', ['kind' => 'hotspot', 'prompt' => 'x', 'areas' => [['id' => 'ok', 'label' => 'x', 'x' => 95, 'y' => 0, 'w' => 20, 'h' => 10]]], ['correct' => ['ok']]))->not->toBe([]);
});

test('INT-02: the public view is a strict whitelist - it never carries any part of a solution, even when the author tries to smuggle one into the public definition', function () {
    $engine = new InteractionEngine;

    foreach (cin_defs() as $kind => [$public, $solution]) {
        $leaky = $public + ['correct' => $solution, 'solution' => $solution, 'order' => ['x'], 'map' => ['a' => 'b'], 'code' => '7421', 'targets_secret' => $solution, 'answer' => 'x'];
        $json = json_encode($engine->publicView($kind, $leaky), JSON_UNESCAPED_UNICODE);

        expect($json)->not->toContain('7421')->not->toContain('"correct"')->not->toContain('"solution"')->not->toContain('"map"')->not->toContain('"answer"')->not->toContain('targets_secret');

        if ($kind === 'flashlight') {
            // مواقع العناصر المخفية جزء من الحل ولا تظهر للاعب أبدًا.
            expect($json)->not->toContain('"targets"')->not->toContain('mark');
        }
    }

    $view = $engine->publicView('choose_item', ['kind' => 'choose_item', 'prompt' => '<script>alert(1)</script>سؤال', 'items' => [['id' => 'a', 'label' => '<b>أ</b>']]]);
    expect($view['prompt'])->not->toContain('<script>')->and($view['items'][0]['label'])->toBe('أ');
});

test('INT-03: correct answers pass and wrong ones fail for every kind, and malformed submissions are simply wrong (never an exception)', function () {
    $engine = new InteractionEngine;
    $good = [
        'hotspot' => ['area' => 'door'],
        'container' => ['area' => 'drawer'],
        'sequence' => ['order' => ['circle', 'triangle', 'square']],
        'drag_drop' => ['placements' => ['square' => 'right', 'circle' => 'left', 'triangle' => 'mid']],
        'choose_item' => ['item' => 'square'],
        'compare' => ['option' => 'triangle'],
        'reveal_code' => [],
        'flashlight' => ['points' => [['x' => 42, 'y' => 44]]],
    ];
    $bad = [
        'hotspot' => ['area' => 'drawer'],
        'container' => ['area' => 'door'],
        'sequence' => ['order' => ['square', 'triangle', 'circle']],
        'drag_drop' => ['placements' => ['circle' => 'right', 'triangle' => 'mid', 'square' => 'left']],
        'choose_item' => ['item' => 'circle'],
        'compare' => ['option' => 'square'],
        'flashlight' => ['points' => [['x' => 5, 'y' => 5]]],
    ];

    foreach (cin_defs() as $kind => [$public, $solution]) {
        $keys = ['riddle_one'];
        expect($engine->evaluate($kind, $public, $solution, $good[$kind], $keys)['correct'])->toBeTrue("good {$kind}");

        if (isset($bad[$kind])) {
            $r = $engine->evaluate($kind, $public, $solution, $bad[$kind], $keys);
            expect($r['correct'])->toBeFalse("bad {$kind}")->and($r['reveal'])->toBeNull();
        }

        foreach ([[], ['area' => ['x']], ['order' => 'circle'], ['placements' => 'x'], ['item' => 7], ['option' => null], ['points' => 'x'], ['points' => [['x' => 'a']]], ['points' => array_fill(0, 500, ['x' => 1, 'y' => 1])]] as $junk) {
            if ($kind === 'reveal_code') {
                continue; // لا مدخل لإجابة؛ يعتمد على الشرط فقط (INT-04)
            }
            expect($engine->evaluate($kind, $public, $solution, $junk, $keys)['correct'])->toBeFalse("junk {$kind}");
        }
    }
});

test('INT-04: a door/drawer and a code reveal stay locked until the required scenes are completed, and the code is only ever returned once the condition holds', function () {
    $engine = new InteractionEngine;
    [$container, $containerSolution] = cin_defs()['container'];
    [$reveal, $revealSolution] = cin_defs()['reveal_code'];

    $locked = $engine->evaluate('container', $container, $containerSolution, ['area' => 'drawer'], []);
    expect($locked['correct'])->toBeFalse()->and($locked['locked'])->toBeTrue()->and($locked['reveal'])->toBeNull();

    $open = $engine->evaluate('container', $container, $containerSolution, ['area' => 'drawer'], ['riddle_one']);
    expect($open['correct'])->toBeTrue();

    $early = $engine->evaluate('reveal_code', $reveal, $revealSolution, [], ['something_else']);
    expect($early['correct'])->toBeFalse()->and($early['locked'])->toBeTrue()->and(json_encode($early))->not->toContain('7421');

    $ready = $engine->evaluate('reveal_code', $reveal, $revealSolution, [], ['riddle_one']);
    expect($ready['correct'])->toBeTrue()->and($ready['reveal'])->toBe('7421');
});

test('INT-05: the flashlight needs the configured number of hidden items and rejects scatter-shot submissions', function () {
    $engine = new InteractionEngine;
    [$public, $solution] = cin_defs()['flashlight'];
    $two = $solution + [];
    $two['required'] = 2;

    expect($engine->evaluate('flashlight', $public, $two, ['points' => [['x' => 41, 'y' => 41]]])['correct'])->toBeFalse()
        ->and($engine->evaluate('flashlight', $public, $two, ['points' => [['x' => 41, 'y' => 41], ['x' => 72, 'y' => 22]]])['correct'])->toBeTrue()
        ->and($engine->evaluate('flashlight', $public, $two, ['points' => array_map(fn ($i) => ['x' => $i * 3, 'y' => $i * 3], range(1, 30))])['correct'])->toBeFalse();
});

test('INT-06: the authoring service stores each kind with its solution apart and refuses an invalid pair without changing a saved solution', function () {
    cin_seed();
    $w = cin_story();

    foreach (cin_defs() as $kind => [$public, $solution]) {
        $scene = cin_interaction($w['chapter'], 'k_'.$kind, $public, $solution);
        expect($scene->config['interaction']['kind'])->toBe($kind);
    }

    $scene = $w['chapter']->scenes()->where('stable_key', 'k_choose_item')->first();
    expect(cin_authoring()->setInteractionSolution($scene, ['correct' => 'does_not_exist']))->not->toBe([]);
});
