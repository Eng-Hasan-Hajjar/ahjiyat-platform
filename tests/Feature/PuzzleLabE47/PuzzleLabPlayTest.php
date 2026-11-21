<?php

use App\Models\PuzzleLabExercise;
use App\Models\User;

require_once __DIR__.'/_support.php';

/*
| E47 - تجربة اللاعب في «مختبر الأحجيات»: فتح التمارين المنشورة، إكمال جولة صحيحة وسيرفريًا، رفض الإجابة الخاطئة، إعادة المحاولة بجولة جديدة،
| وعدم تسرّب الحل في بيانات البدء. (الفصل عن التقدّم الرسمي في PuzzleLabSeparationTest.)
*/

const LAB_NOTICE = 'للتدريب فقط. لا تُحتسب جواهر أو نقاط خبرة.';

test('LAB-01: the lab is for registered accounts only - guests are sent to login on every page and action', function () {
    $exercise = lab_exercise('sequence');

    $this->get(route('puzzle-lab.index'))->assertRedirect(route('login'));
    $this->get(route('puzzle-lab.show', $exercise))->assertRedirect(route('login'));
    $this->postJson(route('puzzle-lab.rounds.start', $exercise))->assertUnauthorized();
    $this->postJson(route('puzzle-lab.rounds.check', $exercise), ['round' => str_repeat('a', 24), 'order' => []])->assertUnauthorized();
});

test('LAB-02: an unverified account is turned away exactly like the official puzzles', function () {
    $exercise = lab_exercise('sequence');
    $unverified = User::factory()->unverified()->create();

    $this->actingAs($unverified)->get(route('puzzle-lab.index'))->assertRedirect(route('verification.notice'));
    $this->actingAs($unverified)->postJson(route('puzzle-lab.rounds.start', $exercise))->assertForbidden();
});

test('LAB-03: the lab page lists the two training types with description and level per published exercise, and the mandatory phrase', function () {
    $memory = lab_exercise('memory', 'published', ['title' => 'تمرين ذاكرة ظاهر', 'summary' => 'وصف ذاكرة ظاهر', 'level' => 'medium']);
    $sequence = lab_exercise('sequence', 'published', ['title' => 'تمرين تسلسل ظاهر', 'summary' => 'وصف تسلسل ظاهر', 'level' => 'hard']);
    lab_exercise('memory', 'draft', ['title' => 'مسودة سرية']);
    lab_exercise('sequence', 'hidden', ['title' => 'تمرين مخفي']);

    $response = $this->actingAs(lab_player())->get(route('puzzle-lab.index'))->assertOk();

    $response->assertSeeInOrder(['بطاقات الذاكرة', 'تمرين ذاكرة ظاهر', 'وصف ذاكرة ظاهر', 'ترتيب التسلسل', 'تمرين تسلسل ظاهر', 'وصف تسلسل ظاهر'])
        ->assertSee('متوسط')->assertSee('صعب')
        ->assertSee(LAB_NOTICE)
        ->assertSee(route('puzzle-lab.show', $memory), false)
        ->assertSee(route('puzzle-lab.show', $sequence), false)
        ->assertDontSee('مسودة سرية')->assertDontSee('تمرين مخفي');
});

test('LAB-04: the lab page says so honestly when nothing is published', function () {
    lab_exercise('memory', 'draft');

    $this->actingAs(lab_player())->get(route('puzzle-lab.index'))->assertOk()->assertSee('لا توجد تمارين منشورة بعد');
});

test('LAB-05: a published exercise opens with its instructions and the training phrase beside the start button (no solution in the page)', function () {
    $exercise = lab_exercise('sequence', 'published', [
        'title' => 'ترتيب أيام الأسبوع', 'instructions' => 'رتّب الأيام كما تتوالى.',
        'game_config' => ['items' => ['السبت', 'الأحد', 'الاثنين']],
    ]);

    $html = $this->actingAs(lab_player())->get(route('puzzle-lab.show', $exercise))->assertOk()
        ->assertSee('ترتيب أيام الأسبوع')->assertSee('رتّب الأيام كما تتوالى.')->assertSee(LAB_NOTICE)->getContent();

    // العبارة والزر داخل كتلة البدء نفسها (قرب زر البدء).
    expect($html)->toMatch('/lab-start.*?'.preg_quote(LAB_NOTICE, '/').'.*?ابدأ التدريب/su');

    // لا عناصر التسلسل ولا ملاحظة الخطأ ولا شرح النجاح في صفحة العرض (تأتي من الخادم فقط بعد التحقق).
    $page = $exercise->only(['wrong_note', 'success_explanation']);
    expect($html)->not->toContain('السبت')->not->toContain('الاثنين')->not->toContain($page['wrong_note'])->not->toContain($page['success_explanation']);
});

test('LAB-06: sequence round - correct order is verified on the server with the explanation; a wrong order gets the educational note only', function () {
    $user = lab_player();
    $exercise = lab_exercise('sequence');
    $start = lab_start($user, $exercise);

    expect($start)->toHaveKeys(['round', 'type', 'items'])->and($start['type'])->toBe('sequence')->and($start['items'])->toHaveCount(4)
        ->and($start['round'])->toMatch('/^[a-f0-9]{24}$/')
        // بيانات البدء لا تحمل أي حقل حل، ورموز العناصر معتمة لا فهارس.
        ->and(array_keys($start))->not->toContain('solution')->not->toContain('order')
        ->and(collect($start['items'])->pluck('idx')->every(fn ($t) => preg_match('/^[a-f0-9]{16}$/', $t) === 1))->toBeTrue();

    // العرض الأول ليس هو الترتيب الصحيح نفسه.
    $correct = lab_correct_tokens($exercise, $start);
    expect(collect($start['items'])->pluck('idx')->all())->not->toBe($correct);

    $wrong = array_reverse($correct);
    $bad = $this->actingAs($user)->postJson(route('puzzle-lab.rounds.check', $exercise), ['round' => $start['round'], 'order' => $wrong])->assertOk()->json();
    expect($bad['correct'])->toBeFalse()->and($bad['note'])->toBe($exercise->wrong_note)->and($bad['explanation'])->toBeNull()
        ->and(json_encode($bad, JSON_UNESCAPED_UNICODE))->not->toContain('الأول');

    $ok = $this->actingAs($user)->postJson(route('puzzle-lab.rounds.check', $exercise), ['round' => $start['round'], 'order' => $correct])->assertOk()->json();
    expect($ok['correct'])->toBeTrue()->and($ok['explanation'])->toBe($exercise->success_explanation)->and($ok['note'])->toBeNull();
});

test('LAB-07: memory round - start data has positions only; faces appear only when flipped; matching all pairs is verified on the server', function () {
    $user = lab_player();
    $exercise = lab_exercise('memory');
    $start = lab_start($user, $exercise);

    expect($start['type'])->toBe('memory')->and($start['pairs'])->toBe(4)->and($start['cards'])->toHaveCount(8)
        ->and(collect($start['cards'])->pluck('id')->sort()->values()->all())->toBe(range(0, 7));

    // لا وجوه ولا خريطة أزواج في بيانات البدء.
    $encoded = json_encode($start, JSON_UNESCAPED_UNICODE);
    foreach (['شمس', 'قمر', 'نجمة', 'غيمة', 'face', 'faces', 'matches'] as $leak) {
        expect($encoded)->not->toContain($leak);
    }

    $pairs = lab_memory_pairs($user, $exercise, $start);
    expect($pairs)->toHaveCount(4);

    $ok = $this->actingAs($user)->postJson(route('puzzle-lab.rounds.check', $exercise), ['round' => $start['round'], 'matches' => $pairs])->assertOk()->json();
    expect($ok['correct'])->toBeTrue()->and($ok['explanation'])->toBe($exercise->success_explanation);
});

test('LAB-08: memory round - incomplete or fabricated pairs are rejected with the educational note, never the layout', function () {
    $user = lab_player();
    $exercise = lab_exercise('memory');
    $start = lab_start($user, $exercise);
    $pairs = lab_memory_pairs($user, $exercise, $start);
    $check = fn (array $matches) => $this->actingAs($user)->postJson(route('puzzle-lab.rounds.check', $exercise), ['round' => $start['round'], 'matches' => $matches])->assertOk()->json();

    // ناقصة (الحالة التي يستعملها العميل عند أول عدم تطابق)
    $partial = $check([$pairs[0]]);
    expect($partial['correct'])->toBeFalse()->and($partial['note'])->toBe($exercise->wrong_note);

    $empty = $check([]);
    expect($empty['correct'])->toBeFalse()->and($empty['note'])->toBe($exercise->wrong_note);

    // أزواج مفبركة (بطاقتان مختلفتا الوجه) وإعادة استعمال بطاقة
    $fabricated = $check([[$pairs[0][0], $pairs[1][0]], [$pairs[0][1], $pairs[1][1]], $pairs[2], $pairs[3]]);
    expect($fabricated['correct'])->toBeFalse();

    $reuse = $check([$pairs[0], [$pairs[0][0], $pairs[1][0]], $pairs[2], $pairs[3]]);
    expect($reuse['correct'])->toBeFalse();
});

test('LAB-09: malformed or stale requests are refused with 422 and reveal nothing', function () {
    $user = lab_player();
    $memory = lab_exercise('memory');
    $sequence = lab_exercise('sequence');
    $mStart = lab_start($user, $memory);

    $post = fn ($route, $exercise, array $body) => $this->actingAs($user)->postJson(route($route, $exercise), $body);

    // رمز جولة مفقود/مشوّه
    $post('puzzle-lab.rounds.check', $sequence, ['order' => ['x']])->assertStatus(422);
    $post('puzzle-lab.rounds.check', $sequence, ['round' => 'short', 'order' => ['x']])->assertStatus(422);
    $post('puzzle-lab.rounds.flip', $memory, ['round' => 'zzzz', 'card' => 0])->assertStatus(422);

    // بطاقة خارج النطاق أو بصيغة غير صالحة
    $post('puzzle-lab.rounds.flip', $memory, ['round' => $mStart['round'], 'card' => 99])->assertStatus(422);
    $post('puzzle-lab.rounds.flip', $memory, ['round' => $mStart['round'], 'card' => ['a']])->assertStatus(422);
    $post('puzzle-lab.rounds.flip', $memory, ['round' => $mStart['round'], 'card' => -1])->assertStatus(422);

    // flip لتمرين تسلسل غير منطقي
    $sStart = lab_start($user, $sequence);
    $post('puzzle-lab.rounds.flip', $sequence, ['round' => $sStart['round'], 'card' => 0])->assertStatus(422);

    // مدخلات التحقق المشوّهة: مصفوفات متداخلة / أنواع خاطئة / أحجام كبيرة
    $post('puzzle-lab.rounds.check', $sequence, ['round' => $sStart['round'], 'order' => [['x']]])->assertStatus(422);
    $post('puzzle-lab.rounds.check', $sequence, ['round' => $sStart['round'], 'order' => array_fill(0, 20, 'a')])->assertStatus(422);
    $post('puzzle-lab.rounds.check', $sequence, ['round' => $sStart['round']])->assertStatus(422);
    $post('puzzle-lab.rounds.check', $memory, ['round' => $mStart['round'], 'matches' => [[1]]])->assertStatus(422);
    $post('puzzle-lab.rounds.check', $memory, ['round' => $mStart['round'], 'matches' => [['a', 'b']]])->assertStatus(422);
    $post('puzzle-lab.rounds.check', $memory, ['round' => $mStart['round'], 'matches' => 'x'])->assertStatus(422);
});

test('LAB-10: retry starts a brand-new round - new token, new layout, and tokens of an older round do not solve the new one', function () {
    $user = lab_player();
    $sequence = lab_exercise('sequence');

    $first = lab_start($user, $sequence);
    $second = lab_start($user, $sequence);
    expect($second['round'])->not->toBe($first['round']);

    // رموز الجولة الأولى لا تحلّ الجولة الثانية (الرموز خاصة بالجولة).
    $firstCorrect = lab_correct_tokens($sequence, $first);
    $replay = $this->actingAs($user)->postJson(route('puzzle-lab.rounds.check', $sequence), ['round' => $second['round'], 'order' => $firstCorrect])->assertOk()->json();
    expect($replay['correct'])->toBeFalse();

    // والجولة الثانية تُحلّ بحلّها هي.
    $ok = $this->actingAs($user)->postJson(route('puzzle-lab.rounds.check', $sequence), ['round' => $second['round'], 'order' => lab_correct_tokens($sequence, $second)])->assertOk()->json();
    expect($ok['correct'])->toBeTrue();

    // ذاكرة: التوزيع حتمي لنفس الجولة ويتغيّر بين الجولات.
    $memory = lab_exercise('memory');
    $layouts = [];
    foreach (range(1, 5) as $i) {
        $start = lab_start($user, $memory);
        $layouts[] = json_encode(lab_memory_pairs($user, $memory, $start));
        // نفس الجولة تعيد نفس الوجه لنفس البطاقة.
        $a = $this->actingAs($user)->postJson(route('puzzle-lab.rounds.flip', $memory), ['round' => $start['round'], 'card' => 3])->json('face');
        $b = $this->actingAs($user)->postJson(route('puzzle-lab.rounds.flip', $memory), ['round' => $start['round'], 'card' => 3])->json('face');
        expect($a)->toBe($b);
    }
    expect(count(array_unique($layouts)))->toBeGreaterThan(1);
});

test('LAB-11: a round belongs to the player who started it - another account cannot use its tokens or faces', function () {
    $owner = lab_player();
    $other = lab_player();
    $sequence = lab_exercise('sequence');
    $start = lab_start($owner, $sequence);

    $steal = $this->actingAs($other)->postJson(route('puzzle-lab.rounds.check', $sequence), ['round' => $start['round'], 'order' => lab_correct_tokens($sequence, $start)])->assertOk()->json();
    expect($steal['correct'])->toBeFalse();
});

test('LAB-12: every sequence exercise page offers the reorder buttons and every page keeps the training phrase beside the start button', function () {
    $sequence = lab_exercise('sequence');
    $memory = lab_exercise('memory');
    $player = lab_player();

    $this->actingAs($player)->get(route('puzzle-lab.show', $sequence))->assertOk()
        ->assertSee('تحريك لأعلى')->assertSee('تحريك لأسفل')->assertSee(LAB_NOTICE)->assertSee('أعد المحاولة')->assertSee('تحقّق من الترتيب');

    $this->actingAs($player)->get(route('puzzle-lab.show', $memory))->assertOk()
        ->assertSee(LAB_NOTICE)->assertSee('أعد المحاولة')->assertDontSee('تحريك لأعلى');
});

test('LAB-13: no loss timer - nothing in the lab pages or the round API mentions a time limit', function () {
    $user = lab_player();
    $memory = lab_exercise('memory');
    $start = lab_start($user, $memory);

    expect($start)->not->toHaveKey('time_limit_seconds')->and($start)->not->toHaveKey('start_token');

    $html = $this->actingAs($user)->get(route('puzzle-lab.show', $memory))->getContent();
    expect($html)->not->toContain('time_limit')->not->toContain('انتهى الوقت');
});
