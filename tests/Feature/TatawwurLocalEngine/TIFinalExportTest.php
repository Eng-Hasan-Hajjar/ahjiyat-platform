<?php

use App\Services\AiAssistant\Knowledge\KnowledgeIndex;
use App\Services\AiAssistant\Local\AnswerComposer;
use App\Services\AiAssistant\Local\IntentCatalog;
use App\Services\AiAssistant\Local\IntentClassifier;
use App\Services\AiAssistant\Local\Language\Analyzer;
use App\Services\AiAssistant\Local\Learned\Featurizer;
use App\Services\AiAssistant\Local\SectionPrior;

require_once __DIR__.'/_eval.php';

/*
| TATAWWUR-FINAL - مُصدِّر بيانات تدريب المصنِّف التعلّمي (يدوي: TI_EVAL=1، الإخراج خارج المستودع في TI_EVAL_OUT).
| يصدّر لكل سؤال في مجموعات الضبط (لا V3 ولا أي مجموعة قبول) كلماته المطوية ودرجات المحرك الحتمي، ونصوص أقسام المقالات المنشورة، وعينة تحقق من تطابق الخصائص.
| المجموعات: TI_EXPORT_SETS (قائمة أسماء ملفات Datasets مفصولة بفواصل).
*/

it('exports tokens, deterministic-engine scores and article sections for model training', function () {
    e45_enable(['tatawwur_ai.provider_enabled' => false, 'tatawwur_ai.local_engine.enabled' => true]);
    ti_kb_full();

    $dir = rtrim((string) getenv('TI_EVAL_OUT'), '/');
    expect($dir)->not->toBe('');
    @mkdir($dir, 0775, true);

    $analyzer = app(Analyzer::class);
    $classifier = app(IntentClassifier::class);
    $prior = app(SectionPrior::class);
    $sets = explode(',', (string) (getenv('TI_EXPORT_SETS') ?: 'gold_dev,gold_dev_q1,gold_dev_final,gold_dev_final_extra,gold_heldout,gold_validation_q1'));
    $out = fopen($dir.'/train-export.jsonl', 'w');
    $parity = [];
    $n = 0;

    foreach ($sets as $set) {
        foreach (require __DIR__.'/Datasets/'.$set.'.php' as $label => $questions) {
            $member = ti_is_member_only($label) || str_starts_with($label, 'my.');

            foreach ($questions as $q) {
                $a = $analyzer->analyze($q, null);
                $pri = $prior->scores($a, $member);
                $c = $classifier->classify($a, $pri);
                [$raw, $rescued] = $classifier->rawScores($a, $pri);
                arsort($raw);
                $ranked = [];

                foreach (array_slice($raw, 0, 12, true) as $name => $s) {
                    $ranked[] = [$name, round($s, 3)];
                }

                $tokens = Featurizer::tokens($q);
                fwrite($out, json_encode([
                    'src' => $set, 'label' => $label, 'q' => $q, 'tokens' => $tokens,
                    'st' => $c->status, 'ei' => $c->intent, 'es' => $c->score, 'em' => $c->margin, 'note' => $c->note, 'ranked' => $ranked, 'rescued' => $rescued,
                    'topics' => $a->topicCount(), 'corr' => count($a->corrections), 'residual' => count($a->residual),
                ], JSON_UNESCAPED_UNICODE)."\n");

                if (count($parity) < 120) {
                    $parity[] = ['tokens' => $tokens, 'counts' => Featurizer::counts($tokens)];
                }

                $n++;
            }
        }
    }

    // أزواج المتابعة: كلمات (سؤال المتابعة + الرسالة السابقة) مع تسمية النية الأولى المقبولة؛ تدريب فقط، لا تدخل مقاييس المحرك.
    foreach (require __DIR__.'/Datasets/gold_dev_followups.php' as [$expected, $prev, $q]) {
        fwrite($out, json_encode(['src' => 'gold_dev_followups', 'label' => (array) $expected === [] ? '' : ((array) $expected)[0], 'q' => $q.' '.$prev, 'tokens' => Featurizer::tokens($q.' '.$prev), 'st' => 'followup', 'ei' => null, 'es' => 0, 'em' => 0, 'note' => null, 'ranked' => [], 'rescued' => null, 'topics' => 0, 'corr' => 0, 'residual' => 0], JSON_UNESCAPED_UNICODE)."\n");
        $n++;
    }

    fclose($out);
    file_put_contents($dir.'/parity-sample.json', json_encode($parity, JSON_UNESCAPED_UNICODE));

    $entries = app(KnowledgeIndex::class)->entries(true);
    $composer = app(AnswerComposer::class);
    $docs = [];

    foreach (IntentCatalog::knowledge() as $intent => $def) {
        $slugEntries = array_values(array_filter($entries, fn (array $e) => $e['slug'] === ($def['a'] ?? '')));

        if ($slugEntries === []) {
            continue;
        }

        foreach ($composer->sectionLines($slugEntries, $def['s']) as $line) {
            $docs[] = ['label' => $intent, 'tokens' => Featurizer::tokens($line)];
        }

        $docs[] = ['label' => $intent, 'tokens' => Featurizer::tokens((string) $slugEntries[0]['title'])];
    }

    file_put_contents($dir.'/train-docs.json', json_encode($docs, JSON_UNESCAPED_UNICODE));

    expect($n)->toBeGreaterThan(1000);
})->skip(fn () => getenv('TI_EVAL') !== '1', 'تصدير يدوي: TI_EVAL=1');
