<?php

require_once __DIR__.'/_eval.php';

/*
| TI-1 - قياس خط الأساس للمسار المحلي القديم (العلم مغلق): يُشغَّل يدويًا فقط (TI_EVAL=1) ويكتب تقريرًا خارج المستودع (TI_EVAL_OUT).
| ليس اختبار نجاح/فشل للجودة: أرقام خط الأساس تُسجَّل ولا تُستعمل لتعديل الاختبارات القديمة.
*/

it('records the legacy local-path baseline on the dev and held-out sets', function () {
    config(['tatawwur_ai.local_engine.enabled' => false]);
    e45_enable(['tatawwur_ai.provider_enabled' => false]);
    e45_kb();

    $dev = ti_run(ti_cases(require __DIR__.'/Datasets/gold_dev.php', 'D'));
    ti_dump('baseline-dev', $dev);

    // المجموعة المستقلة: تُسجَّل المقاييس المجمّعة فقط (لا حالات) كي لا تتسرّب صياغاتها إلى ضبط المعجم.
    $held = ti_run(ti_cases(require __DIR__.'/Datasets/gold_heldout.php', 'H'));
    ti_dump('baseline-heldout', $held, false);

    expect($dev['metrics']['total'])->toBeGreaterThanOrEqual(240);
    expect($held['metrics']['total'])->toBeGreaterThanOrEqual(100);
})->skip(fn () => getenv('TI_EVAL') !== '1', 'قياس يدوي: TI_EVAL=1');
