<?php

require_once __DIR__.'/_eval_v3.php';

/*
| TATAWWUR-FINAL - قياس المحرك على مجموعة الضبط الجديدة dev-final (ليست مجموعة قبول): للتحليل والضبط. يدوي (TI_EVAL=1)، التقرير الكامل خارج المستودع (TI_EVAL_OUT).
| TI_DEV_SET يحدد المجموعة (افتراضي gold_dev_final)، وتقبل أي ملف بصيغة «نية ⇒ أسئلة» ضمن Datasets.
*/

it('measures the local engine on a development set (full per-case report)', function () {
    e45_enable(['tatawwur_ai.provider_enabled' => false, 'tatawwur_ai.local_engine.enabled' => true, 'tatawwur_ai.local_semantic.enabled' => getenv('TI_SEMANTIC') === '1']);
    ti_kb_full();

    $name = (string) (getenv('TI_DEV_SET') ?: 'gold_dev_final');
    $result = tif_run(tif_cases(tif_from_intent_set(require __DIR__.'/Datasets/'.$name.'.php')), false);

    tif_dump('dev-'.$name.'-'.(getenv('TI_DEV_TAG') ?: 'run'), $result);

    expect($result['metrics']['total'])->toBeGreaterThan(0);
})->skip(fn () => getenv('TI_EVAL') !== '1', 'قياس يدوي: TI_EVAL=1');
