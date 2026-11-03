<?php

require_once __DIR__.'/TIFinalAcceptanceIntegrityTest.php';

/*
| TATAWWUR-FINAL - فصل الضبط عن القبول: مجموعات الضبط/التدريب الجديدة (dev_final, dev_final_extra, dev_followups) لا تشترك مع V3 المجمّدة بسؤال مطابق ولا بتشابه شديد
| (Jaccard ≥ 0.75 على الكلمات المطوية). وV2 والمحتجزة (مجموعتا انحدار خارجيتان) لا تدخلان ملف التدريب أصلًا (يفرض ذلك TIL-03).
| ملاحظة نزاهة: كاتب المجموعتين واحد، لذا الاستقلال محدود؛ هذا الاختبار يمنع التداخل اللفظي فقط ولا يثبت استقلال الأسلوب.
*/

/** @return list<string> */
function tifd_dev_questions(): array
{
    $out = [];

    foreach (['gold_dev_final', 'gold_dev_final_extra'] as $file) {
        foreach (require __DIR__.'/Datasets/'.$file.'.php' as $questions) {
            foreach ($questions as $q) {
                $out[] = $q;
            }
        }
    }

    foreach (require __DIR__.'/Datasets/gold_dev_followups.php' as [, $prev, $q]) {
        $out[] = $q;
        $out[] = $prev;
    }

    return $out;
}

test('TIFD-01: no development question equals an acceptance (V3) question after folding', function () {
    $v3 = array_map('tif_int_fold', tif_int_scored(require __DIR__.'/Datasets/gold_acceptance_v3.php'));
    $dev = array_map('tif_int_fold', tifd_dev_questions());

    expect(array_values(array_intersect($v3, $dev)))->toBe([]);
});

test('TIFD-02: no development question is a near-duplicate (token Jaccard >= 0.75) of an acceptance (V3) scored question', function () {
    $v3 = array_map('tif_int_tokens', tif_int_scored(require __DIR__.'/Datasets/gold_acceptance_v3.php'));
    $near = [];

    foreach (tifd_dev_questions() as $q) {
        $a = tif_int_tokens($q);

        foreach ($v3 as $b) {
            $union = count(array_unique(array_merge($a, $b)));

            if ($union > 0 && count(array_intersect($a, $b)) / $union >= 0.75) {
                $near[] = $q;
                break;
            }
        }
    }

    expect($near)->toBe([]);
});
