<?php

use App\Services\AiAssistant\AssistantOrchestrator;
use App\Services\AiAssistant\Conversation\AssistantTurn;
use App\Services\AiAssistant\Local\Enhanced\EnhancedReplies;
use App\Services\AiAssistant\Local\Enhanced\EntityTools;
use App\Services\AiAssistant\Local\LocalEngine;
use App\Services\AiAssistant\TatawwurSettings;

require_once __DIR__.'/_eval.php';

/*
| TATAWWUR-FINAL - إغلاق العلم يحفظ سلوك E45/TI السابق: الطبقة الجديدة تُفتح بعلم مستقل مغلق افتراضيًا، ومغلقةً لا يتغير شيء في المسار القديم.
*/

const TIFF_OLD_OOS = 'أختص بالإجابة عن منصة الأحجيات وميزاتها فقط (الأحجيات، الجواهر، الفرق، المواسم، المتجر، الإنجازات وغيرها). جرّب سؤالًا عن المنصة.';

function tiff_ask(string $q, array $history = [])
{
    LocalEngine::forgetDecision();

    return app(AssistantOrchestrator::class)->answer(new AssistantTurn($q, 'public', null, $history));
}

test('TIFF-01: every operational flag defaults to false in the shipped config (nothing is activated by the delivery)', function () {
    $config = require base_path('config/tatawwur_ai.php');

    expect($config['enabled'])->toBeFalse()->and($config['provider_enabled'])->toBeFalse()->and($config['guests_enabled'])->toBeFalse()->and($config['streaming_enabled'])->toBeFalse()
        ->and($config['local_engine']['enabled'])->toBeFalse()->and($config['local_semantic']['enabled'])->toBeFalse();
});

test('TIFF-02: the semantic flag cannot work without the local-engine flag (no hidden activation path)', function () {
    e45_enable(['tatawwur_ai.provider_enabled' => false, 'tatawwur_ai.local_engine.enabled' => false, 'tatawwur_ai.local_semantic.enabled' => true]);
    expect(TatawwurSettings::localSemanticEnabled())->toBeFalse();

    config(['tatawwur_ai.local_engine.enabled' => true, 'tatawwur_ai.local_semantic.enabled' => false]);
    expect(TatawwurSettings::localSemanticEnabled())->toBeFalse();

    config(['tatawwur_ai.local_engine.enabled' => true, 'tatawwur_ai.local_semantic.enabled' => true]);
    expect(TatawwurSettings::localSemanticEnabled())->toBeTrue();
});

test('TIFF-03: with the semantic flag closed the legacy engine answers exactly as before (no enhanced decision keys, old out-of-scope text, no entity route)', function () {
    e45_enable(['tatawwur_ai.provider_enabled' => false, 'tatawwur_ai.local_engine.enabled' => true, 'tatawwur_ai.local_semantic.enabled' => false]);
    ti_kb_full();

    $known = tiff_ask('كيف أكسب الجواهر؟');
    $decision = LocalEngine::lastDecision();
    $oos = tiff_ask('ما هي عاصمة فرنسا؟');
    $entity = tiff_ask('ما تفاصيل الموسم /seasons/anything؟');

    expect($known->grounded)->toBeTrue()->and($decision)->not->toBeNull()->and($decision)->not->toHaveKey('context')->and($decision)->not->toHaveKey('probability')
        ->and($oos->text)->toBe(TIFF_OLD_OOS)->and($oos->text)->not->toBe(EnhancedReplies::OUT_OF_SCOPE)
        ->and($entity->reason)->not->toBe(EntityTools::REASON);
});

test('TIFF-04: with the flag open the enhanced path is used (decision keys present, approved out-of-scope text) - and only then', function () {
    e45_enable(['tatawwur_ai.provider_enabled' => false, 'tatawwur_ai.local_engine.enabled' => true, 'tatawwur_ai.local_semantic.enabled' => true]);
    ti_kb_full();

    tiff_ask('كيف أكسب الجواهر؟');
    $decision = LocalEngine::lastDecision();
    $oos = tiff_ask('ما هي عاصمة فرنسا؟');

    expect($decision)->toHaveKey('context')->toHaveKey('probability')->and($oos->text)->toBe(EnhancedReplies::OUT_OF_SCOPE);
});

test('TIFF-05: the closed-flag legacy path loads no learned model file and makes no network call', function () {
    e45_enable(['tatawwur_ai.provider_enabled' => false, 'tatawwur_ai.local_engine.enabled' => true, 'tatawwur_ai.local_semantic.enabled' => false]);
    ti_kb_full();
    Illuminate\Support\Facades\Http::fake();

    $included = array_map('realpath', get_included_files());
    $model = realpath(app_path('Services/AiAssistant/Local/Learned/model/intent_model.php'));

    tiff_ask('كيف أكسب الجواهر؟');
    tiff_ask('كم تلزم محاولة لحل اللغز؟');

    // الاختبارات تعمل في عملية واحدة؛ لذلك نقارن «قبل/بعد» ولا نفترض أن ملفًا لم يُحمَّل سابقًا في الجلسة.
    expect(in_array($model, array_map('realpath', get_included_files()), true))->toBe(in_array($model, $included, true));
    Illuminate\Support\Facades\Http::assertNothingSent();
});
