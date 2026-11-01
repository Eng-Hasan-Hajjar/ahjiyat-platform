<?php

require_once __DIR__.'/_eval.php';

/*
| TI-8 (تطوير) - قياس المحرك الداخلي على مجموعة التطوير فقط عبر المنسّق الحقيقي. يدوي (TI_EVAL=1) ويكتب التقرير خارج المستودع (TI_EVAL_OUT).
| لا يلمس المجموعة المجمّدة (heldout)؛ تشغيلها الرسمي الوحيد في TIHeldoutOfficialTest.
*/

it('measures the local engine on the dev set', function () {
    e45_enable(['tatawwur_ai.provider_enabled' => false, 'tatawwur_ai.local_engine.enabled' => true]);
    ti_kb_full();

    $dev = ti_run(ti_cases(require __DIR__.'/Datasets/gold_dev.php', 'D'));
    ti_dump('engine-dev', $dev);

    expect($dev['metrics']['total'])->toBeGreaterThanOrEqual(240);
})->skip(fn () => getenv('TI_EVAL') !== '1', 'قياس يدوي: TI_EVAL=1');
