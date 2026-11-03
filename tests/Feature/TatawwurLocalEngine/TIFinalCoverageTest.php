<?php

use App\Services\AiAssistant\Local\Cards\FeatureRegistry;
use App\Services\AiAssistant\Local\Enhanced\EntityTools;
use App\Services\AiAssistant\Local\IntentCatalog;
use App\Services\AiAssistant\Local\LiveResolver;
use App\Services\AiAssistant\Tools\ToolRegistry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

require_once __DIR__.'/_eval.php';

/*
| TATAWWUR-FINAL - بوابة التغطية الآلية.
|
| (1) بطاقة كاملة لكل ميزة (اسم، وصف، جمهور، صفحة، مصدر حقيقة، أقسام، نوايا، مرادفات) ⇒ matrixGaps() فارغة.
| (2) كل أداة قراءة من الـ23 مربوطة بميزة في الخريطة وموصولة فعليًا بالمحرك (LiveResolver أو EntityTools) ⇒ لا أداة يتيمة.
| (3) أمر ai:local:coverage --strict --matrix ينجح على الكود المُسلَّم ويفشل حين تظهر ميزة/مسار مستخدم جديد غير مصنَّف.
| (4) كل نية معرفة لها أسئلة ضبط كافية (تقرير تغطية لا حيلة): العدّ من مجموعات الضبط فقط، لا من مجموعات القبول.
*/

test('TIFC-01: the feature matrix has no gap - every feature card has name, description, audience, page, source of truth, sections, intents and synonyms', function () {
    expect(FeatureRegistry::matrixGaps())->toBe([])->and(count(FeatureRegistry::matrix()))->toBeGreaterThanOrEqual(30);
});

test('TIFC-02: every one of the 23 read tools is mapped to a feature, and every mapped name is a real tool', function () {
    $registry = app(ToolRegistry::class)->names();
    $mapped = [];

    foreach (FeatureRegistry::LIVE_TOOLS as $feature => $tools) {
        expect(FeatureRegistry::FEATURES)->toHaveKey($feature);

        foreach ($tools as $tool) {
            $mapped[$tool] = true;
        }
    }

    expect($registry)->toHaveCount(23)->and(array_values(array_diff($registry, array_keys($mapped))))->toBe([])->and(array_values(array_diff(array_keys($mapped), $registry)))->toBe([]);
});

test('TIFC-03: every one of the 23 read tools is actually wired into the local engine (no orphan tool, six new tools through EntityTools)', function () {
    $registry = app(ToolRegistry::class)->names();
    $resolver = new ReflectionClass(LiveResolver::class);
    $wired = array_merge(array_values($resolver->getReflectionConstant('PUBLIC')->getValue()), array_values($resolver->getReflectionConstant('PERSONAL')->getValue()));
    $entity = EntityTools::wiredTools();
    $all = array_values(array_unique(array_merge($wired, $entity)));
    sort($all);
    $expected = $registry;
    sort($expected);

    expect($entity)->toHaveCount(6)->and(array_values(array_intersect($wired, $entity)))->toBe([])->and($all)->toBe($expected);
});

test('TIFC-04: the coverage gate passes on the shipped code and fails for a new unclassified user-facing route (matrix mode included)', function () {
    expect(Artisan::call('ai:local:coverage', ['--strict' => true, '--matrix' => true]))->toBe(0);

    Route::get('/zz-final-new-feature', fn () => 'x')->name('zzfinal.index');

    expect(Artisan::call('ai:local:coverage', ['--strict' => true, '--matrix' => true]))->not->toBe(0)->and(Artisan::output())->toContain('zzfinal.index');
});

test('TIFC-05: a new admin or webhook route does not leak into the knowledge base and does not require a card', function () {
    Route::get('/admin/zz-final-tool', fn () => 'x')->name('filament.admin.pages.zz-final-tool');
    Route::post('/webhooks/zz-final', fn () => 'x')->name('webhooks.zzfinal');

    expect(Artisan::call('ai:local:coverage', ['--strict' => true, '--matrix' => true]))->toBe(0);

    foreach (FeatureRegistry::matrix() as $row) {
        expect($row['related_page'])->not->toContain('/admin')->not->toContain('webhook');
    }
});

test('TIFC-06: every knowledge intent has at least three tuning questions (coverage by tuning data, never by acceptance data)', function () {
    $counts = [];

    foreach (['gold_dev.php', 'gold_dev_q1.php', 'gold_dev_final.php', 'gold_dev_final_extra.php'] as $file) {
        foreach (require __DIR__.'/Datasets/'.$file as $intent => $questions) {
            $counts[$intent] = ($counts[$intent] ?? 0) + count($questions);
        }
    }

    foreach (['gold_dev_followups.php'] as $file) {
        foreach (require __DIR__.'/Datasets/'.$file as [$intent]) {
            foreach ((array) $intent as $i) {
                $counts[$i] = ($counts[$i] ?? 0) + 1;
            }
        }
    }

    $thin = [];

    foreach (array_keys(IntentCatalog::knowledge()) as $intent) {
        if (($counts[$intent] ?? 0) < 3) {
            $thin[] = $intent.' ('.($counts[$intent] ?? 0).')';
        }
    }

    expect($thin)->toBe([]);
});
