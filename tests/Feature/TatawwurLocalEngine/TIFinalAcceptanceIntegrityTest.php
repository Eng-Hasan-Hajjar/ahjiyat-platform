<?php

use App\Services\AiAssistant\Local\IntentCatalog;
use App\Services\Search\TextFold;

/*
| TATAWWUR-FINAL - سلامة مجموعة القبول V3: ≥350 حالة بالتوزيع المطلوب، مجمّدة ببصمة SHA-256 قبل أي تعديل على المحرك في هذه الجولة،
| بلا تطابق نصي ولا تشابه شديد (Jaccard ≥ 0.7) مع مجموعات الضبط والتحقق السابقة، وكل مفتاح نية معروف.
| الاختبار لا يعدّل أي مجموعة قديمة ولا يقيس المحرك (القياس في TIFinalAcceptanceEvalTest يدويًا).
*/

function tif_int_fold(string $q): string
{
    return trim((string) preg_replace('/\s+/u', ' ', TextFold::fold($q)));
}

function tif_int_tokens(string $q): array
{
    return array_values(array_unique(array_filter(explode(' ', tif_int_fold($q)))));
}

/** @return list<string> الأسئلة المقيَّمة فقط (بلا رسائل السياق السابقة). */
function tif_int_scored(array $set): array
{
    $out = [];

    foreach ($set as $group => $rows) {
        foreach ($rows as $row) {
            $out[] = match ($group) {
                'followup' => $row[2],
                'ambiguous' => $row[0],
                default => $row[1],
            };
        }
    }

    return $out;
}

function tif_int_old_questions(): array
{
    $out = [];

    foreach (['gold_dev', 'gold_dev_q1', 'gold_heldout', 'gold_validation_q1'] as $file) {
        foreach (require __DIR__.'/Datasets/'.$file.'.php' as $questions) {
            foreach ($questions as $q) {
                $out[] = $q;
            }
        }
    }

    return $out;
}

test('TIF-01: V3 has at least 350 cases with the required group sizes', function () {
    $set = require __DIR__.'/Datasets/gold_acceptance_v3.php';
    $n = fn (string ...$g) => array_sum(array_map(fn ($x) => count($set[$x] ?? []), $g));

    expect($n(...array_keys($set)))->toBeGreaterThanOrEqual(350)
        ->and($n('natural'))->toBeGreaterThanOrEqual(100)
        ->and($n('typo_dialect'))->toBeGreaterThanOrEqual(50)
        ->and($n('followup'))->toBeGreaterThanOrEqual(40)
        ->and($n('compound', 'ambiguous'))->toBeGreaterThanOrEqual(40)
        ->and($n('live', 'personal'))->toBeGreaterThanOrEqual(40)
        ->and($n('oos', 'unknown'))->toBeGreaterThanOrEqual(40)
        ->and($n('security'))->toBeGreaterThanOrEqual(40);
});

test('TIF-02: V3 is frozen - its SHA-256 matches the hash recorded before any engine change in this round', function () {
    $frozen = trim((string) file_get_contents(__DIR__.'/Datasets/gold_acceptance_v3.sha256'));

    expect($frozen)->toMatch('/^[0-9a-f]{64}$/')
        ->and(hash_file('sha256', __DIR__.'/Datasets/gold_acceptance_v3.php'))->toBe($frozen);
});

test('TIF-03: no V3 scored question equals an older dev/held-out/V2 question after folding, and V3 has no internal duplicate', function () {
    $v3 = array_map('tif_int_fold', tif_int_scored(require __DIR__.'/Datasets/gold_acceptance_v3.php'));
    $old = array_map('tif_int_fold', tif_int_old_questions());

    expect(array_values(array_intersect($v3, $old)))->toBe([])
        ->and(count(array_unique($v3)))->toBe(count($v3));
});

test('TIF-04: no V3 scored question is a near-duplicate (token Jaccard >= 0.7) of an older dev/held-out/V2 question', function () {
    $old = array_map('tif_int_tokens', tif_int_old_questions());
    $near = [];

    foreach (tif_int_scored(require __DIR__.'/Datasets/gold_acceptance_v3.php') as $q) {
        $a = tif_int_tokens($q);

        foreach ($old as $b) {
            $union = count(array_unique(array_merge($a, $b)));

            if ($union > 0 && count(array_intersect($a, $b)) / $union >= 0.7) {
                $near[] = $q;
                break;
            }
        }
    }

    expect($near)->toBe([]);
});

test('TIF-05: every expected intent in V3 is a catalog intent, a blocked rule or a special kind', function () {
    $set = require __DIR__.'/Datasets/gold_acceptance_v3.php';
    $special = ['oos', 'unknown', 'ambiguous'];

    foreach (['natural', 'typo_dialect', 'followup', 'compound', 'live', 'personal', 'oos', 'unknown', 'security'] as $group) {
        foreach ($set[$group] as $row) {
            foreach ((array) $row[0] as $intent) {
                expect(IntentCatalog::get($intent) !== null || in_array($intent, $special, true) || str_starts_with($intent, 'blocked.'))->toBeTrue("unknown intent in $group: $intent");
            }
        }
    }
});

test('TIF-06: V3 covers at least 95 distinct knowledge intents and every dialect tag', function () {
    $set = require __DIR__.'/Datasets/gold_acceptance_v3.php';
    $intents = [];

    foreach (['natural', 'typo_dialect', 'followup', 'compound'] as $group) {
        foreach ($set[$group] as $row) {
            foreach ((array) $row[0] as $intent) {
                $intents[$intent] = true;
            }
        }
    }

    $tags = array_unique(array_map(fn ($r) => $r[2] ?? 'typo', $set['typo_dialect']));

    expect(count($intents))->toBeGreaterThanOrEqual(95)
        ->and($tags)->toContain('shami', 'khaliji', 'masri', 'maghrebi', 'typo');
});
