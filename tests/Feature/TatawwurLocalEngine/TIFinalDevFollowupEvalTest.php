<?php

require_once __DIR__.'/_eval_v3.php';

/*
| TATAWWUR-FINAL - قياس آلية المتابعة على أزواج الضبط gold_dev_followups (للتحقق الميكانيكي من السياق فقط؛ هذه الأزواج مستعملة في التدريب فهي ليست مقياس تعميم).
*/

it('measures follow-up handling on the development pairs', function () {
    e45_enable(['tatawwur_ai.provider_enabled' => false, 'tatawwur_ai.local_engine.enabled' => true, 'tatawwur_ai.local_semantic.enabled' => getenv('TI_SEMANTIC') === '1']);
    ti_kb_full();

    $result = tif_run(tif_cases(['followup' => require __DIR__.'/Datasets/gold_dev_followups.php']), true);
    tif_dump('devfollowup-'.(getenv('TI_DEV_TAG') ?: 'run'), $result);

    expect($result['metrics']['total'])->toBeGreaterThan(0);
})->skip(fn () => getenv('TI_EVAL') !== '1', 'قياس يدوي: TI_EVAL=1');
