<?php

use App\Services\AiAssistant\AssistantOrchestrator;
use App\Services\AiAssistant\Conversation\AssistantTurn;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/_eval.php';

/*
| TI-8 (أداء) - عدد استعلامات قاعدة البيانات لكل سؤال عبر المنسّق الحقيقي والمحرك الداخلي، على مجموعة التطوير فقط (لا تُلمس المجموعة المجمّدة).
| يدوي (TI_EVAL=1) ويكتب التقرير خارج المستودع (TI_EVAL_OUT)؛ لا يعدّل أي شيء ولا يفرض حدودًا (للقياس والإبلاغ).
*/

it('counts database queries per question by kind on the dev set', function () {
    e45_enable(['tatawwur_ai.provider_enabled' => false, 'tatawwur_ai.local_engine.enabled' => true]);
    ti_kb_full();

    $orchestrator = app(AssistantOrchestrator::class);
    $member = e45_member();
    $by = [];
    $n = 0;
    DB::listen(function () use (&$n) {
        $n++;
    });

    foreach (ti_cases(require __DIR__.'/Datasets/gold_dev.php', 'D') as $case) {
        $asMember = ti_is_member_only($case['intent']) || $case['kind'] === 'personal';
        $turn = new AssistantTurn($case['q'], $asMember ? 'member' : 'public', $asMember ? $member : null);

        $n = 0;
        $orchestrator->answer($turn);
        $by[$case['kind']][] = $n;
    }

    $stat = function (array $v): array {
        sort($v);
        $c = count($v);

        return ['n' => $c, 'min' => $v[0], 'avg' => round(array_sum($v) / $c, 2), 'p95' => $v[(int) floor(0.95 * ($c - 1))], 'max' => $v[$c - 1]];
    };

    $report = ['queries_per_question' => array_map($stat, $by)];
    $dir = getenv('TI_EVAL_OUT');

    if ($dir !== false && $dir !== '') {
        @mkdir($dir, 0775, true);
        file_put_contents(rtrim($dir, '/').'/query-count.json', json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    expect($report['queries_per_question'])->not->toBeEmpty();
})->skip(fn () => getenv('TI_EVAL') !== '1', 'قياس يدوي: TI_EVAL=1');
