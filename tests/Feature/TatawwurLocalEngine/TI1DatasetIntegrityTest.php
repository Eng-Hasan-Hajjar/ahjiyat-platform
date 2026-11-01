<?php

use App\Services\AiAssistant\Local\IntentCatalog;
use App\Services\Search\TextFold;

/*
| TI-1 - سلامة مجموعات التقييم: ≥240 سؤالًا ذهبيًا للتطوير + ≥100 صياغة جديدة مستقلة (held-out) مجمّدة ببصمة، بلا تداخل بينهما،
| وكل مفتاح نية معروف في الكتالوج أو من الأنواع الخاصة. تعديل المجموعة المجمّدة بعد التجميد يفشل هنا ⇒ لا ضبط على مجموعة الاختبار.
*/

const TI1_SPECIAL = ['oos', 'unknown', 'ambiguous'];

function ti1_flat(array $set): array
{
    $out = [];

    foreach ($set as $intent => $rows) {
        foreach ($rows as $q) {
            $out[] = [$intent, $q];
        }
    }

    return $out;
}

test('TI1-01: the dev gold set has at least 240 questions and the held-out set at least 100', function () {
    expect(count(ti1_flat(require __DIR__.'/Datasets/gold_dev.php')))->toBeGreaterThanOrEqual(240)
        ->and(count(ti1_flat(require __DIR__.'/Datasets/gold_heldout.php')))->toBeGreaterThanOrEqual(100);
});

test('TI1-02: the held-out set is frozen - its SHA-256 matches the frozen hash recorded at creation', function () {
    $frozen = trim((string) file_get_contents(__DIR__.'/Datasets/gold_heldout.sha256'));

    expect(hash_file('sha256', __DIR__.'/Datasets/gold_heldout.php'))->toBe($frozen);
});

test('TI1-03: no question appears in both sets (after folding) and no duplicate inside a set', function () {
    $fold = fn (string $q) => preg_replace('/\s+/u', ' ', TextFold::fold($q));
    $dev = array_map(fn ($r) => $fold($r[1]), ti1_flat(require __DIR__.'/Datasets/gold_dev.php'));
    $held = array_map(fn ($r) => $fold($r[1]), ti1_flat(require __DIR__.'/Datasets/gold_heldout.php'));

    expect(array_intersect($dev, $held))->toBe([])
        ->and(count(array_unique($dev)))->toBe(count($dev))
        ->and(count(array_unique($held)))->toBe(count($held));
});

test('TI1-04: every intent key in both sets is a known catalog intent, a blocked rule or a special kind', function () {
    $blocked = ['blocked.puzzle_secret', 'blocked.hint_bypass', 'blocked.injection', 'blocked.action', 'blocked.other_user', 'blocked.env_secret'];

    foreach ([require __DIR__.'/Datasets/gold_dev.php', require __DIR__.'/Datasets/gold_heldout.php'] as $set) {
        foreach (array_keys($set) as $intent) {
            expect(IntentCatalog::get($intent) !== null || in_array($intent, TI1_SPECIAL, true) || in_array($intent, $blocked, true) || str_starts_with($intent, 'blocked.'))->toBeTrue("unknown intent key: $intent");
        }
    }
});

test('TI1-05: the held-out set covers all question kinds including blocked, out-of-scope, unknown and ambiguous', function () {
    $kinds = [];

    foreach (array_keys(require __DIR__.'/Datasets/gold_heldout.php') as $intent) {
        $kinds[ti_kind_of($intent)] = true;
    }

    expect(array_keys($kinds))->toContain('know', 'live', 'my', 'blocked', 'oos', 'unknown', 'ambiguous');
});

function ti_kind_of(string $intent): string
{
    return match (true) {
        str_starts_with($intent, 'live.') => 'live',
        str_starts_with($intent, 'my.') => 'my',
        str_starts_with($intent, 'blocked.') => 'blocked',
        in_array($intent, TI1_SPECIAL, true) => $intent,
        default => 'know',
    };
}
