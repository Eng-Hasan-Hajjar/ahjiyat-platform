<?php

use App\Services\AiAssistant\AssistantOrchestrator;
use App\Services\AiAssistant\Conversation\AssistantTurn;
use App\Services\AiAssistant\Local\LocalEngine;

require_once __DIR__.'/_eval.php';

/*
| TATAWWUR-FINAL - فاحص نوعي يدوي: يقرأ أسئلة من ملف (TI_PROBE_FILE: سطر لكل سؤال، «||» تفصل الرسالة السابقة عن السؤال) ويكتب الردود الكاملة إلى TI_PROBE_OUT.
| للاطلاع على جودة الإجابات فقط؛ لا يقيس ولا يغيّر شيئًا. يُستعمل على أسئلة ضبط مكتوبة للفحص لا على مجموعة القبول.
*/

it('prints full answers for probe questions', function () {
    e45_enable(['tatawwur_ai.provider_enabled' => false, 'tatawwur_ai.local_engine.enabled' => true, 'tatawwur_ai.local_semantic.enabled' => getenv('TI_SEMANTIC') === '1']);
    ti_kb_full();

    $orchestrator = app(AssistantOrchestrator::class);
    $member = e45_member(['name' => 'Probe Member', 'email' => 'probe.member@example.test']);
    $out = [];

    foreach (file((string) getenv('TI_PROBE_FILE'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        [$prev, $q] = str_contains($line, '||') ? explode('||', $line, 2) : [null, $line];
        $isMember = str_starts_with($q, 'm:');
        $q = $isMember ? substr($q, 2) : $q;
        $history = [];

        if ($prev !== null) {
            $first = $orchestrator->answer(new AssistantTurn($prev, $isMember ? 'member' : 'public', $isMember ? $member : null));
            $history = [['role' => 'user', 'text' => $prev], ['role' => 'assistant', 'text' => $first->text]];
        }

        LocalEngine::forgetDecision();
        $a = $orchestrator->answer(new AssistantTurn($q, $isMember ? 'member' : 'public', $isMember ? $member : null, $history));
        $d = LocalEngine::lastDecision();
        $out[] = ['prev' => $prev, 'q' => $q, 'outcome' => $a->outcome, 'reason' => $a->reason, 'intent' => $d['intent'] ?? null, 'status' => $d['status'] ?? null, 'text' => $a->text, 'sources' => array_column($a->sources, 'url')];
    }

    file_put_contents((string) getenv('TI_PROBE_OUT'), json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    expect($out)->not->toBe([]);
})->skip(fn () => getenv('TI_EVAL') !== '1', 'فحص يدوي: TI_EVAL=1');
