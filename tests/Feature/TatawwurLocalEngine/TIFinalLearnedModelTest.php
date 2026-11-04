<?php

use App\Services\AiAssistant\Local\Enhanced\ClauseSplitter;
use App\Services\AiAssistant\Local\Enhanced\EnhancedReplies;
use App\Services\AiAssistant\Local\Enhanced\EntityTools;
use App\Services\AiAssistant\Local\IntentCatalog;
use App\Services\AiAssistant\Local\Learned\Featurizer;
use App\Services\AiAssistant\Local\Learned\LearnedIntentScorer;
use App\Services\AiAssistant\TatawwurSettings;

/*
| TATAWWUR-FINAL - المصنِّف الخطي المحلي: تكافؤ الخصائص، سلامة ملف الأوزان (لا مصادر تقييم، فئات كاملة)، ومكوّنات الطبقة المحسَّنة النقية (مقسِّم الفقرات، الردود، استخراج الكيانات).
| لا شبكة ولا مزوّد ولا قاعدة بيانات هنا.
*/

test('TIL-01: Featurizer word/char counts equal the committed parity fixture (and tools/tatawwur/check_parity.py proves the Python trainer agrees)', function () {
    $fixture = json_decode((string) file_get_contents(__DIR__.'/Datasets/learned_parity_fixture.json'), true);

    expect($fixture)->toHaveCount(30);

    foreach ($fixture as $row) {
        $counts = Featurizer::counts($row['tokens']);

        expect($counts['word'] ?? $counts[0])->toEqual($row['counts']['word'])->and($counts['char'] ?? $counts[1])->toEqual($row['counts']['char']);
    }
});

test('TIL-02: the model file covers every catalog intent plus the special and blocked classes, with sane calibration', function () {
    LearnedIntentScorer::forget();
    $meta = LearnedIntentScorer::meta();
    $model = require LearnedIntentScorer::path();
    $classes = $model['classes'];

    expect(LearnedIntentScorer::available())->toBeTrue()
        ->and(array_diff(array_keys(IntentCatalog::all()), $classes))->toBe([])
        ->and($classes)->toContain('oos', 'unknown', 'ambiguous', 'blocked.puzzle_secret', 'blocked.other_user', 'blocked.action', 'blocked.injection', 'blocked.hint_bypass', 'blocked.secrets')
        ->and($model['temperature'])->toBeGreaterThan(0.0)->toBeLessThanOrEqual(5.0)
        ->and(filesize(LearnedIntentScorer::path()))->toBeLessThan(3 * 1024 * 1024)
        ->and($meta['algorithm'])->toContain('logistic-regression');
});

test('TIL-03: the model was trained on development sets only - never on V2, held-out or acceptance data', function () {
    $sources = LearnedIntentScorer::meta()['sources'];

    expect(array_intersect($sources, ['gold_acceptance_v3', 'gold_heldout', 'gold_validation_q1']))->toBe([])
        ->and($sources)->toContain('gold_dev_final');
});

test('TIL-04: probabilities are a proper distribution and the scorer is deterministic', function () {
    $scorer = new LearnedIntentScorer;
    $tokens = Featurizer::tokens('كيف اكسب الجواهر وين اصرفها');
    $a = $scorer->probabilities($tokens, 500);
    $b = $scorer->probabilities($tokens, 500);

    expect($a)->toBe($b)->and(array_sum($a))->toBeGreaterThan(0.98)->toBeLessThan(1.02)->and(array_key_first($a))->toStartWith('gems.');
    expect($scorer->probabilities([], 5))->toBe([]);
});

test('TIL-05: the flags are independent; the semantic flag also needs the local engine flag (the shipped defaults themselves are checked by TIFF-01, which does not depend on a developer\'s local .env)', function () {
    config(['tatawwur_ai.local_engine.enabled' => false, 'tatawwur_ai.local_semantic.enabled' => true]);
    expect(TatawwurSettings::localSemanticEnabled())->toBeFalse();

    config(['tatawwur_ai.local_engine.enabled' => true, 'tatawwur_ai.local_semantic.enabled' => true]);
    expect(TatawwurSettings::localSemanticEnabled())->toBeTrue();

    config(['tatawwur_ai.local_semantic.enabled' => false]);
    expect(TatawwurSettings::localSemanticEnabled())->toBeFalse();
});

test('TIL-06: ClauseSplitter splits real compound questions, leaves single questions alone, and caps at three clauses', function () {
    $s = new ClauseSplitter;

    expect($s->split('كيف أكسب الجواهر وأين أصرفها؟'))->toHaveCount(2)
        ->and($s->split('ما الفرق بين الجواهر والخبرة؟'))->toBe(['ما هو الجواهر', 'ما هو الخبره'])
        ->and($s->split('كيف أكسب الجواهر؟'))->toBe([])
        ->and($s->split('تعليق فيه اساءة، وين ابلغ'))->toBe([])
        ->and(count($s->split('كيف أكسب الجواهر وأين أصرفها وكم الحد الأقصى وما فائدتها؟')))->toBeLessThanOrEqual(3);
});

test('TIL-07: EnhancedReplies are fixed texts: spec out-of-scope sentence, honest undocumented replies, clarification with options', function () {
    expect(EnhancedReplies::OUT_OF_SCOPE)->toBe('أنا تطوّر، مساعد منصة الأحجيات. أستطيع مساعدتك في استخدام المنصة، والأحجيات، والمكافآت، والمواسم، والمتجر وبقية الميزات. اسألني عما تريد معرفته داخل المنصة.')
        ->and(EnhancedReplies::undocumented('mobile_app'))->toContain('تطبيق للجوال')->toContain('لا أستطيع تأكيد')
        ->and(EnhancedReplies::undocumented(null))->toContain('هذا الموضوع')
        ->and(EnhancedReplies::clarify(['الجواهر', 'الخبرة']))->toContain('«الجواهر»')->toContain('«الخبرة»')
        ->and(EnhancedReplies::clarify([]))->not->toContain('«')
        ->and(EnhancedReplies::topicOf('هل في تطبيق للجوال'))->toBe('mobile_app')
        ->and(EnhancedReplies::topicOf('كيف اكسب الجواهر'))->toBeNull();
});

test('TIL-08: EntityTools only plans explicit entity requests (quoted title, internal URL, ULID) and never a how-to or generic help question', function () {
    $tools = app(EntityTools::class);

    expect($tools->applies('ابحث عن «الباب الأخضر»'))->toBeTrue()
        ->and($tools->applies('ما تفاصيل الموسم «الصيف»؟'))->toBeTrue()
        ->and($tools->applies('ما تفاصيل الموسم /seasons/summer-2026'))->toBeTrue()
        ->and($tools->applies('معلومات عن المبدع /creators/abc-def'))->toBeTrue()
        ->and($tools->applies('كم سعر السلسلة 01ARZ3NDEKTSV4RRFFQ69G5FAV'))->toBeTrue()
        ->and($tools->applies('كيف أبحث عن أحجية؟'))->toBeFalse()
        ->and($tools->applies('كيف أبحث عن «لغز الباب»؟'))->toBeFalse()
        ->and($tools->applies('ابحث عن شرح للميزات'))->toBeFalse()
        ->and($tools->applies('ما هي الجواهر؟'))->toBeFalse()
        ->and($tools->applies('ما دور «المبدع»؟'))->toBeFalse();
});

test('TIL-09: the six formerly unwired tools are exactly the ones EntityTools wires', function () {
    expect(EntityTools::wiredTools())->toBe(['search_platform', 'get_platform_guide_article', 'get_season_information', 'get_creator_public_information', 'get_creator_series_public_information', 'get_my_premium_series_access']);
});