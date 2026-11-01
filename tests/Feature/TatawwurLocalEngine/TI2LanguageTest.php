<?php

use App\Services\AiAssistant\Local\IntentClassifier;
use App\Services\AiAssistant\Local\Language\Analyzer;
use App\Services\AiAssistant\Local\Language\Lexicon;
use App\Services\AiAssistant\Local\Language\SpellCorrector;

/*
| TI-2 - المحرك اللغوي: تطبيع (همزات/تشكيل/تطويل)، صرف (جمع/اشتقاق/سوابق)، استفهام لهجي، مفاهيم/مرادفات، نية، نفي، غموض، سياق.
| صيغ غير مرئية سابقًا فقط (ليست من مجموعتي الذهبية). مقاييس الجودة منفصلة عن اختبارات الأمان.
*/

function ti2_intent(string $q, ?string $previous = null): array
{
    $a = (new Analyzer)->analyze($q, $previous);
    $c = (new IntentClassifier)->classify($a);

    return [$c->intent, $c->status, $a];
}

test('TI2-01: hamza, tashkeel, tatweel and letter variants normalise to the same intent', function () {
    $variants = ['كيف أجمع الجواهر', 'كيف اجمع الجواهر', 'كَيْفَ أَجْمَعُ الجَوَاهِرَ', 'كيـــف أجمـــع الجواهـــر', 'كيف اجمع الجواهر؟؟'];
    $intents = array_map(fn ($q) => ti2_intent($q)[0], $variants);

    expect(array_unique($intents))->toBe(['gems.earn']);
});

test('TI2-02: plural, derivation and prefixed forms map to the same concept', function () {
    foreach (['الجواهر', 'جواهري', 'بالجواهر', 'والجواهر', 'لجوهرة'] as $word) {
        expect((new Analyzer)->analyze('ما '.$word)->has('gems'))->toBeTrue("no gems concept in: $word");
    }

    foreach (['المنافسة', 'المنافسات', 'منافسات', 'بالمنافسات'] as $word) {
        expect((new Analyzer)->analyze('ما '.$word)->has('competition'))->toBeTrue("no competition concept in: $word");
    }
});

test('TI2-03: dialect interrogatives map to the right facet', function () {
    $cases = [
        'شلون اجمع جواهر' => 'how', 'كيف أجمع جواهر' => 'how', 'وين اشوف تقدمي' => 'where', 'فين صفحة المتجر' => 'where', 'ليش ما اقدر استبدل' => 'why',
        'لماذا لم تصل جواهري' => 'why', 'ايش الفرق بين الجواهر والخبرة' => 'diff', 'امتى ينتهي الموسم' => 'when', 'كم يلزمني من الخبرة' => 'howmuch',
    ];

    foreach ($cases as $q => $facet) {
        expect(in_array($facet, (new Analyzer)->analyze($q)->facets, true))->toBeTrue("facet $facet missing in: $q");
    }
});

test('TI2-04: concept synonyms from different registers reach the same knowledge intent', function () {
    foreach (['كيف اسحب فلوسي', 'هل يمكن تحويل الجواهر الى كاش', 'ممكن اسحب جواهري كفلوس'] as $q) {
        expect(ti2_intent($q)[0])->toBe('gems.cash', "wrong intent for: $q");
    }

    foreach (['كيف أنضم إلى فريق', 'ابغى انضم لفريق', 'شلون ادخل فريق'] as $q) {
        expect(str_starts_with((string) ti2_intent($q)[0], 'teams.'))->toBeTrue("wrong intent for: $q");
    }
});

test('TI2-05: the bare word "points" is never silently resolved - it asks which kind the user means', function () {
    foreach (['كيف اجمع نقاط', 'كيف احصل على نقاط', 'كم نقاطي', 'ما هي النقاط'] as $q) {
        expect(ti2_intent($q)[1])->toBe('ambiguous', "should be ambiguous: $q");
    }

    // الأنواع المحددة تُحلّ لنيتها وتبقى منفصلة
    expect(ti2_intent('ما هي نقاط الدعم')[0])->toStartWith('supporters.')
        ->and(ti2_intent('كم خبرتي')[0])->toBe('my.progress')
        ->and(ti2_intent('كم رصيد جواهري')[0])->toBe('my.wallet');
});

test('TI2-06: negation lowers a concept and never flips a refusal into an answer', function () {
    expect((new Analyzer)->analyze('لا استطيع استبدال الجواهر')->negated)->toHaveKey('redeem')
        ->and((new Analyzer)->analyze('بدون استبدال')->negated)->toHaveKey('redeem')
        ->and((new Analyzer)->analyze('هل اقدر استبدل')->negated)->toBe([]);
});

test('TI2-07: personal-ownership detection separates "my" questions from general ones', function () {
    expect((new Analyzer)->analyze('كم رصيدي')->personal)->toBeGreaterThanOrEqual(1)
        ->and((new Analyzer)->analyze('كيف يعمل الرصيد')->personal)->toBe(0);
});

test('TI2-08: conversation context resolves an elliptical follow-up only when it has no topic of its own', function () {
    [$withContext, $status] = ti2_intent('ومتى تصل؟', 'كيف استبدل الجواهر؟');
    [$withoutContext] = ti2_intent('ومتى تصل؟');

    expect($withContext)->toBe('redeem.how')->and($status)->toBe('ok')->and($withoutContext)->toBeNull();

    // سؤال له موضوعه لا يرث سياقًا
    $a = (new Analyzer)->analyze('ما هي الجواهر', 'ما هو المسار المميز');
    expect($a->inherited)->toBe([]);
});

test('TI2-09: the spell corrector is conservative - it fixes a clear typo but never touches harmless words', function () {
    expect(SpellCorrector::correct('جواهرر'))->toBe('جواهر');

    // كلمات سليمة أو بعيدة لا تتحول إلى مفاهيم (خصوصًا الحساسة)
    foreach (['تعليمات', 'ساعدني', 'حالتي', 'ارسال', 'تعيين', 'الاعدادات', 'المعلومات'] as $word) {
        expect(SpellCorrector::correct($word))->toBeNull("harmless word was corrected: $word")
            ->and((new Analyzer)->analyze('ما '.$word)->corrections)->toBe([]);
    }
});

test('TI2-10: harmless typo-like phrasing never turns into a blocked/financial command by correction', function () {
    foreach (['كيف أعدل ملفي', 'اين ارى ترتيبي', 'ما هي شروط الانضمام للفريق', 'هل يمكنني تغيير كلمة المرور'] as $q) {
        [$intent, $status] = ti2_intent($q);
        expect($status)->not->toBe('blocked', "harmless question blocked: $q");
    }
});

test('TI2-11: the lexicon exposes the structural/sensitive split used by safety rules', function () {
    expect(Lexicon::STRUCTURAL)->toContain('live_now', 'how_many')->and(count(Lexicon::STRUCTURAL))->toBeGreaterThan(20);
});

test('TI2-12: classification is deterministic and fast (no network, same input => same output)', function () {
    $q = 'كيف احصل على جواهر اكثر';
    $first = ti2_intent($q);
    $t0 = hrtime(true);

    for ($i = 0; $i < 50; $i++) {
        [$intent, $status] = ti2_intent($q);
        expect([$intent, $status])->toBe([$first[0], $first[1]]);
    }

    expect((hrtime(true) - $t0) / 1e6 / 50)->toBeLessThan(40.0);
});
