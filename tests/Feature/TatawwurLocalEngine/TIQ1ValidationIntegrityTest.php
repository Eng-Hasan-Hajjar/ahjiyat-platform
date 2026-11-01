<?php

use App\Services\AiAssistant\Local\IntentCatalog;
use App\Services\Search\TextFold;

/*
| TI-Q1 - سلامة مجموعة التحقق المستقلة V2: ≥150 سؤالًا جديدًا، مجمّدة ببصمة SHA-256 قبل أي تعديل على المحرك في هذه الجولة،
| بلا تطابق نصي ولا تشابه شديد مع مجموعتي التطوير والـ held-out القديمة، وكل مفتاح نية معروف، وتغطي كل أنواع الأسئلة.
| لا يعدّل هذا الملف أي مجموعة قديمة (gold_dev / gold_heldout تبقى كما هي وبصمتها تُفحص في TI1DatasetIntegrityTest).
*/

const TIQ1_SPECIAL = ['oos', 'unknown', 'ambiguous'];

function tiq1_flat(array $set): array
{
    $out = [];

    foreach ($set as $intent => $rows) {
        foreach ($rows as $q) {
            $out[] = [$intent, $q];
        }
    }

    return $out;
}

function tiq1_fold(string $q): string
{
    return trim((string) preg_replace('/\s+/u', ' ', TextFold::fold($q)));
}

function tiq1_kind(string $intent): string
{
    return match (true) {
        str_starts_with($intent, 'live.') => 'live',
        str_starts_with($intent, 'my.') => 'my',
        str_starts_with($intent, 'blocked.') => 'blocked',
        in_array($intent, TIQ1_SPECIAL, true) => $intent,
        default => 'know',
    };
}

test('TIQ1-01: the V2 validation set has at least 150 questions', function () {
    expect(count(tiq1_flat(require __DIR__.'/Datasets/gold_validation_q1.php')))->toBeGreaterThanOrEqual(150);
});

test('TIQ1-02: the V2 set is frozen - its SHA-256 matches the hash recorded before any engine change', function () {
    $frozen = trim((string) file_get_contents(__DIR__.'/Datasets/gold_validation_q1.sha256'));

    expect($frozen)->toMatch('/^[0-9a-f]{64}$/')
        ->and(hash_file('sha256', __DIR__.'/Datasets/gold_validation_q1.php'))->toBe($frozen);
});

test('TIQ1-03: no V2 question equals a dev or held-out question after folding, and there is no duplicate inside V2', function () {
    $v2 = array_map(fn ($r) => tiq1_fold($r[1]), tiq1_flat(require __DIR__.'/Datasets/gold_validation_q1.php'));
    $old = array_map(fn ($r) => tiq1_fold($r[1]), array_merge(
        tiq1_flat(require __DIR__.'/Datasets/gold_dev.php'),
        tiq1_flat(require __DIR__.'/Datasets/gold_heldout.php'),
    ));

    expect(array_values(array_intersect($v2, $old)))->toBe([])
        ->and(count(array_unique($v2)))->toBe(count($v2));
});

test('TIQ1-04: no V2 question is a near-duplicate (token Jaccard >= 0.7) of a dev or held-out question', function () {
    $tokens = fn (string $q) => array_values(array_unique(array_filter(explode(' ', tiq1_fold($q)))));
    $old = array_map(fn ($r) => $tokens($r[1]), array_merge(
        tiq1_flat(require __DIR__.'/Datasets/gold_dev.php'),
        tiq1_flat(require __DIR__.'/Datasets/gold_heldout.php'),
    ));
    $near = [];

    foreach (tiq1_flat(require __DIR__.'/Datasets/gold_validation_q1.php') as [$intent, $q]) {
        $a = $tokens($q);

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

test('TIQ1-05: every V2 intent key is a known catalog intent, a blocked rule or a special kind', function () {
    foreach (array_keys(require __DIR__.'/Datasets/gold_validation_q1.php') as $intent) {
        expect(IntentCatalog::get($intent) !== null || in_array($intent, TIQ1_SPECIAL, true) || str_starts_with($intent, 'blocked.'))->toBeTrue("unknown intent key: $intent");
    }
});

test('TIQ1-06: V2 covers all question kinds and most knowledge intents', function () {
    $set = require __DIR__.'/Datasets/gold_validation_q1.php';
    $kinds = [];

    foreach (array_keys($set) as $intent) {
        $kinds[tiq1_kind($intent)] = true;
    }

    $knowledge = array_filter(array_keys($set), fn ($i) => tiq1_kind($i) === 'know');

    expect(array_keys($kinds))->toContain('know', 'live', 'my', 'blocked', 'oos', 'unknown', 'ambiguous')
        ->and(count($knowledge))->toBeGreaterThanOrEqual(95);
});

test('TIQ1-07: the dev supplement (gold_dev_q1) shares no question with V2 or the held-out set, exactly or nearly, and has no internal duplicate', function () {
    $tokens = fn (string $q) => array_values(array_unique(array_filter(explode(' ', tiq1_fold($q)))));
    $supplement = tiq1_flat(require __DIR__.'/Datasets/gold_dev_q1.php');
    $frozen = array_merge(
        tiq1_flat(require __DIR__.'/Datasets/gold_validation_q1.php'),
        tiq1_flat(require __DIR__.'/Datasets/gold_heldout.php'),
    );
    $frozenFolded = array_map(fn ($r) => tiq1_fold($r[1]), $frozen);
    $frozenTokens = array_map(fn ($r) => $tokens($r[1]), $frozen);
    $supplementFolded = array_map(fn ($r) => tiq1_fold($r[1]), $supplement);
    $near = [];

    foreach ($supplement as [$intent, $q]) {
        $a = $tokens($q);

        foreach ($frozenTokens as $b) {
            $union = count(array_unique(array_merge($a, $b)));

            if ($union > 0 && count(array_intersect($a, $b)) / $union >= 0.7) {
                $near[] = $q;
                break;
            }
        }
    }

    expect(array_values(array_intersect($supplementFolded, $frozenFolded)))->toBe([])
        ->and($near)->toBe([])
        ->and(count(array_unique($supplementFolded)))->toBe(count($supplementFolded))
        ->and(count($supplementFolded))->toBeGreaterThanOrEqual(150);
});
