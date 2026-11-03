<?php

use App\Services\AiAssistant\AssistantOrchestrator;
use App\Services\AiAssistant\Conversation\AssistantTurn;
use App\Services\AiAssistant\Local\Enhanced\EnhancedReplies;

require_once __DIR__.'/_eval.php';

/*
| TATAWWUR-FINAL - الأسئلة المركّبة: كل فقرة تُصنَّف على حدة وتُجاب أو يُصرَّح بحالتها بصدق، ولا تُوصَف فقرة ناقصة تعود على سابقتها بأنها «خارج الاختصاص».
*/

beforeEach(function () {
    e45_enable(['tatawwur_ai.provider_enabled' => false, 'tatawwur_ai.local_engine.enabled' => true, 'tatawwur_ai.local_semantic.enabled' => true]);
    ti_kb_full();
});

function tifcmp_ask(string $q)
{
    return app(AssistantOrchestrator::class)->answer(new AssistantTurn($q, 'public', null));
}

test('TIFCMP-01: a known part and an out-of-scope part are answered separately, the second one labelled honestly', function () {
    $a = tifcmp_ask('ما هي الجواهر وما عاصمة فرنسا؟');

    expect($a->text)->toContain('أولًا')->toContain('ثانيًا')->toContain(EnhancedReplies::OUT_OF_SCOPE_PART)->and($a->grounded)->toBeTrue();
});

test('TIFCMP-02: an elliptical second part that refers back to the first ("... وكيف أطلبه؟") is never declared out of scope', function () {
    foreach (['هل رصيدي كافي للاستبدال وكيف أطلبه؟', 'كيف أضيف صديقا وكيف أمنعه؟'] as $q) {
        expect(tifcmp_ask($q)->text)->not->toContain(EnhancedReplies::OUT_OF_SCOPE_PART)->not->toBe(EnhancedReplies::OUT_OF_SCOPE);
    }
});

test('TIFCMP-03: a compound message that contains a blocked part is refused as a whole with no source', function () {
    $a = tifcmp_ask('ما هي الجواهر وما هو حل أحجية اليوم؟');

    expect($a->sources)->toBe([])->and($a->grounded)->toBeFalse()->and($a->text)->not->toContain('نقاط مكافآت');
});
