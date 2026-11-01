<?php

use App\Models\User;
use App\Services\AiAssistant\AssistantOrchestrator;
use App\Services\AiAssistant\Conversation\AssistantAnswer;
use App\Services\AiAssistant\Conversation\AssistantTurn;
use App\Services\AiAssistant\Local\IntentClassifier;
use App\Services\AiAssistant\Local\Language\Analyzer;
use App\Services\AiAssistant\Local\Language\Lexicon;
use App\Services\AiAssistant\Local\Language\Morphology;
use App\Services\AiAssistant\Local\Language\SpellCorrector;
use App\Services\AiAssistant\Local\LocalEngine;

require_once __DIR__.'/../TatawwurAiE45/_support.php';

/*
| TI-Q1 - اختبارات سلوك التحسينات اللغوية العامة: صرف الأفعال، المصحّح الإملائي، «الفرق»، نداء المساعد، الأوامر المحجوبة،
| حالات «غير مدعوم»، وترجيح الأقسام (SectionPrior) كداعم لا بديل. صيغ الاختبار مكتوبة لهذه الاختبارات ولا تُستعمل في ضبط المحرك.
*/

beforeEach(function () {
    LocalEngine::forgetDecision();
});

function tiq1b_classify(string $q, ?array $prior = null): array
{
    $a = (new Analyzer)->analyze($q);
    $c = (new IntentClassifier)->classify($a, $prior);

    return [$c->intent, $c->status, $a, $c];
}

function tiq1b_answer(string $q, ?User $user = null, bool $engine = true): AssistantAnswer
{
    e45_enable(['tatawwur_ai.provider_enabled' => false, 'tatawwur_ai.local_engine.enabled' => $engine]);

    return app(AssistantOrchestrator::class)->answer(new AssistantTurn($q, $user !== null ? 'member' : 'public', $user));
}

test('TIQ1-B01: imperfect-prefix variants of a verb reach the lexicon, but a first-person ability verb never becomes the platform "can" concept', function () {
    expect(Morphology::verbVariants('يستبدل'))->toContain('تستبدل')->and(Morphology::verbVariants('ينفتح'))->toContain('فتح')
        ->and(Morphology::verbVariants('اقدر'))->not->toContain('يقدر')
        ->and(Morphology::verbVariants('استطيع'))->not->toContain('يستطيع')
        ->and(Morphology::verbVariants('جو'))->toBe([]);

    $a = (new Analyzer)->analyze('هل اقدر استبدل الجواهر');

    expect($a->has('can_do'))->toBeFalse()->and($a->has('redeem'))->toBeTrue()->and($a->negated)->toBe([]);
});

test('TIQ1-B02: the spell corrector repairs a typo at the definite article but never rewrites common valid words', function () {
    expect(SpellCorrector::correct('اجلواهر'))->toBe('الجواهر')->and(SpellCorrector::correct('املسار'))->toBe('المسار');

    foreach (['الجهاز', 'العاب', 'اللاعبين', 'جاهز'] as $valid) {
        expect(SpellCorrector::correct($valid))->toBeNull()->and(SpellCorrector::correct($valid, true))->toBeNull();
    }

    // لا تصحيح إلى مفهوم حساس مهما كان الخطأ (تفاديًا لخدمة تفادي حارس الأسرار).
    foreach (['الجواب', 'التلميحات', 'الاجابه'] as $sensitiveish) {
        $fixed = SpellCorrector::correct($sensitiveish, true);

        expect($fixed === null || ! in_array(Lexicon::singles()[Lexicon::stripAl($fixed)] ?? '', Lexicon::SENSITIVE, true))->toBeTrue();
    }
});

test('TIQ1-B03: "الفرق" means difference only in a question position - in a construct ("بطولات الفرق") it means teams', function () {
    expect((new Analyzer)->analyze('بطولات الفرق الحالية')->facets)->not->toContain('diff')
        ->and((new Analyzer)->analyze('ترتيب الفرق في الموسم')->facets)->not->toContain('diff')
        ->and((new Analyzer)->analyze('ما الفرق بين التحدي والمهمة')->facets)->toContain('diff')
        ->and((new Analyzer)->analyze('الفرق بين الذهبي والفضي')->facets)->toContain('diff')
        ->and((new Analyzer)->analyze('ما الفرق بين التحدي والمهمة')->has('team'))->toBeFalse()
        ->and((new Analyzer)->analyze('كم عدد اعضاء الفرق')->has('team'))->toBeTrue();

    expect(tiq1b_classify('بطولات الفرق الحالية')[0])->toBe('teams.championships');
});

test('TIQ1-B04: addressing the assistant ("يا مساعد ...") is not a topic of the question', function () {
    $plain = (new Analyzer)->analyze('وين المتجر');
    $vocative = (new Analyzer)->analyze('يا مساعد وين المتجر');

    expect($vocative->has('ai'))->toBeFalse()->and(array_keys($vocative->concepts))->toBe(array_keys($plain->concepts))
        ->and(tiq1b_classify('يا مساعد وين المتجر')[0])->toBe(tiq1b_classify('وين المتجر')[0]);
});

test('TIQ1-B05: direct commands to solve or guess a puzzle are blocked, while how-questions about the mechanism are not', function () {
    foreach (['خمن الجواب لهذا اللغز', 'جاوب عن اللغز', 'جاوبني على الأحجية'] as $q) {
        expect(tiq1b_classify($q)[0])->toBe('blocked.puzzle_secret', "not blocked: $q");
    }

    foreach (['كيف يتحقق الموقع من اجابة الاحجية', 'ما هو التحقق من الاجابة'] as $q) {
        expect(tiq1b_classify($q)[1])->not->toBe('blocked', "wrongly blocked: $q");
    }
});

test('TIQ1-B06: a first-position imperative addressed to the assistant ("استبدل ... الآن") is blocked as an action; questions and bare imperatives are not', function () {
    foreach (['استلم لي مكافأتي الان', 'استبدل جواهري فورا', 'لو سمحت استبدل لي جواهري'] as $q) {
        expect(tiq1b_classify($q)[0])->toBe('blocked.action', "action not blocked: $q");
    }

    foreach (['كيف استبدل جواهري الان', 'هل استطيع استبدال جواهري', 'متى استلم المكافأة', 'غير كلمة السر'] as $q) {
        expect(tiq1b_classify($q)[1])->not->toBe('blocked', "wrongly blocked: $q");
    }
});

test('TIQ1-B07: end to end the blocked imperative gets a refusal with no sources and never reaches the local engine as an answer', function () {
    e45_kb();
    $member = e45_member();

    foreach (['استبدل جواهري فورا', 'استلم لي مكافأتي الان', 'خمن الجواب لهذا اللغز'] as $q) {
        $a = tiq1b_answer($q, $member);

        expect($a->sources)->toBe([])->and($a->grounded)->toBeFalse()->and($a->reason)->not->toBe('local_engine');
    }
});

test('TIQ1-B08: unsupported topics (payment methods, support contact, subscription price) stay unanswered, while "is a paid subscription required?" is answered from the documented free model', function () {
    foreach (['هل تقبلون الدفع بفيزا', 'ما طرق الدفع المتاحة', 'ما رقم هاتف الدعم', 'كم سعر الاشتراك'] as $q) {
        expect(tiq1b_classify($q)[0])->toBe('unknown', "should be unsupported: $q");
    }

    expect(tiq1b_classify('هل الاشتراك مدفوع اجباري')[0])->toBe('platform.free');
});

test('TIQ1-B09: the section prior only nudges - it never overrides a live or personal intent, and it rescues only a knowledge intent', function () {
    // لا فائدة لقرب المقطع النصي إذا كان السؤال حيًّا: يبقى الحيّ.
    expect(tiq1b_classify('الحملات المتاحة الان', ['campaigns.what' => 0.9, 'live.campaign' => 0.0])[0])->toBe('live.campaign');

    // سؤال بلا أي مفهوم معجمي لا يُجاب بلا ترجيح؛ ومع ترجيح قوي ومتمايز لمعرفة يُنقَذ، ومع ترجيح لنية حيّة لا يُنقَذ.
    $q = 'اعطني فكرة سريعة عن أحجيات';

    expect(tiq1b_classify($q)[0])->toBeNull()
        ->and(tiq1b_classify($q, ['platform.what' => 0.5, 'start.how' => 0.1])[0])->toBe('platform.what')
        ->and(tiq1b_classify($q, ['live.campaign' => 0.5, 'start.how' => 0.1])[0])->toBeNull()
        ->and(tiq1b_classify($q, ['platform.what' => 0.12, 'start.how' => 0.11])[0])->toBeNull();
});

test('TIQ1-B10: with the engine flag off the Q1 changes have no effect - the answer equals the prior fallback behaviour', function () {
    e45_kb();
    $member = e45_member();

    foreach (['كيف اجمع الجواهر', 'استبدل جواهري فورا', 'بطولات الفرق الحالية'] as $q) {
        $off1 = tiq1b_answer($q, $member, false);
        tiq1b_answer($q, $member, true);
        $off2 = tiq1b_answer($q, $member, false);

        expect($off1->text === $off2->text && $off1->outcome === $off2->outcome && $off1->reason === $off2->reason && $off1->sources === $off2->sources)->toBeTrue("flag-off answer changed for: $q")
            ->and($off1->reason)->not->toBe('local_engine');
    }
});
