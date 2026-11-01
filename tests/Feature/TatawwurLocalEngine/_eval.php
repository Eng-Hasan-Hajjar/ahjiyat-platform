<?php

use App\Models\User;
use App\Services\AiAssistant\AssistantOrchestrator;
use App\Services\AiAssistant\Conversation\AssistantAnswer;
use App\Services\AiAssistant\Conversation\AssistantTurn;
use App\Services\Search\TextFold;

require_once __DIR__.'/../TatawwurAiE45/_support.php';

/*
| TI-1 - مُقيِّم مشترك لمجموعات الأسئلة الذهبية (dev / held-out) عبر المنسّق الحقيقي (المحارس + المحرك + المعرفة المنشورة).
| مقاييس الجودة (النية، الجواب المباشر، الامتناع الصادق، الغموض) منفصلة عن مقاييس الأمان (الصدّ، التسريب).
| لا شبكة: مزوّد الذكاء الخارجي معطّل (provider_enabled=false) فيعمل المسار المحلي وحده.
*/

const TI_MEMBER_ONLY = ['friends.', 'chat.', 'my.'];

/** @return array{0: string, 1: string} [kind, intent] */
function ti_kind(string $intent): string
{
    return match (true) {
        str_starts_with($intent, 'live.') => 'live',
        str_starts_with($intent, 'my.') => 'personal',
        str_starts_with($intent, 'blocked.') => 'blocked',
        $intent === 'oos' => 'oos',
        $intent === 'unknown' => 'unknown',
        $intent === 'ambiguous' => 'ambiguous',
        default => 'knowledge',
    };
}

/** يزرع دليل E45 المنشور + بطاقات TI المنشورة (نشر صريح كما يفعل المحرر؛ الكود نفسه لا ينشر آليًا خارج الاختبار). */
function ti_kb_full(): void
{
    e45_kb();
    Illuminate\Support\Facades\Artisan::call('ai:knowledge:sync-cards', ['--publish' => true]);
}

function ti_is_member_only(string $intent): bool
{
    foreach (TI_MEMBER_ONLY as $prefix) {
        if (str_starts_with($intent, $prefix)) {
            return true;
        }
    }

    return false;
}

/** يفرد مجموعة (intent => أسئلة) إلى حالات مرتبة بمعرّفات ثابتة. @return list<array{id: string, q: string, intent: string, kind: string}> */
function ti_cases(array $set, string $prefix): array
{
    $cases = [];
    $i = 0;

    foreach ($set as $intent => $questions) {
        foreach ($questions as $q) {
            $cases[] = ['id' => sprintf('%s%03d', $prefix, ++$i), 'q' => $q, 'intent' => $intent, 'kind' => ti_kind($intent)];
        }
    }

    return $cases;
}

function ti_facts(): array
{
    static $facts = null;

    return $facts ??= require __DIR__.'/Datasets/facts.php';
}

function ti_fact_hit(string $intent, string $text): bool
{
    $folded = TextFold::fold($text);

    foreach (ti_facts()[$intent] ?? [] as $marker) {
        if (str_contains($folded, TextFold::fold($marker))) {
            return true;
        }
    }

    return false;
}

/** مسمّيات الوحدات التي لا يجوز خلطها. */
function ti_unit_names(string $text): array
{
    $folded = TextFold::fold($text);
    $units = [];

    foreach (['جواهر' => 'gems', 'خبره' => 'xp', 'نقاط المسار' => 'pass', 'نقاط الدعم' => 'support'] as $needle => $unit) {
        if (str_contains($folded, TextFold::fold($needle))) {
            $units[$unit] = true;
        }
    }

    return array_keys($units);
}

/**
 * يشغّل المجموعة ويعيد سجل الحالات + المقاييس.
 *
 * @param  list<array{id: string, q: string, intent: string, kind: string}>  $cases
 * @return array{cases: list<array<string, mixed>>, metrics: array<string, mixed>}
 */
function ti_run(array $cases): array
{
    $orchestrator = app(AssistantOrchestrator::class);
    $member = e45_member();
    $rows = [];

    foreach ($cases as $case) {
        $asMember = ti_is_member_only($case['intent']) || $case['kind'] === 'personal';
        $turn = new AssistantTurn($case['q'], $asMember ? 'member' : 'public', $asMember ? $member : null);

        $t0 = hrtime(true);
        $answer = $orchestrator->answer($turn);
        $ms = (hrtime(true) - $t0) / 1e6;

        $engine = class_exists(App\Services\AiAssistant\Local\LocalEngine::class) ? App\Services\AiAssistant\Local\LocalEngine::lastDecision() : null;
        $guarded = $answer->sources === [] && ! $answer->grounded;
        $hit = $case['kind'] === 'knowledge' ? ti_fact_hit($case['intent'], $answer->text) : null;

        $rows[] = [
            'id' => $case['id'], 'intent' => $case['intent'], 'kind' => $case['kind'], 'q' => $case['q'],
            'outcome' => $answer->outcome, 'reason' => $answer->reason, 'grounded' => $answer->grounded, 'guarded' => $guarded,
            'predicted' => $engine['intent'] ?? null, 'engine_status' => $engine['status'] ?? null, 'fact_hit' => $hit,
            'tool_calls' => $answer->toolCalls, 'units' => ti_unit_names($answer->text), 'digits' => preg_match('/\d/u', TextFold::fold($answer->text)) === 1,
            'text' => mb_substr($answer->text, 0, 700), 'ms' => round($ms, 2),
        ];
    }

    return ['cases' => $rows, 'metrics' => ti_metrics($rows)];
}

/** @param list<array<string, mixed>> $rows */
function ti_metrics(array $rows): array
{
    $by = fn (string $kind) => array_values(array_filter($rows, fn ($r) => $r['kind'] === $kind));
    $pct = fn (int $n, int $d) => $d === 0 ? null : round(100 * $n / $d, 1);
    $m = ['total' => count($rows)];

    $k = $by('knowledge');
    $answered = array_filter($k, fn ($r) => in_array($r['outcome'], ['answered', 'fallback'], true) && $r['grounded'] && ! $r['guarded']);
    $m['knowledge'] = [
        'n' => count($k),
        'answered_pct' => $pct(count($answered), count($k)),
        'direct_answer_correct_pct' => $pct(count(array_filter($k, fn ($r) => $r['fact_hit'] === true && in_array($r['outcome'], ['answered', 'fallback'], true))), count($k)),
        'abstained_pct' => $pct(count(array_filter($k, fn ($r) => $r['outcome'] === 'abstained')), count($k)),
        'guard_false_positive' => count(array_filter($k, fn ($r) => $r['guarded'])),
        'intent_accuracy_pct' => $k !== [] && $k[0]['predicted'] !== null || array_filter($k, fn ($r) => $r['predicted'] !== null) !== []
            ? $pct(count(array_filter($k, fn ($r) => $r['predicted'] === $r['intent'])), count($k)) : null,
    ];

    foreach (['live', 'personal'] as $kind) {
        $rs = $by($kind);
        $m[$kind] = [
            'n' => count($rs),
            'intent_accuracy_pct' => array_filter($rs, fn ($r) => $r['predicted'] !== null) !== [] ? $pct(count(array_filter($rs, fn ($r) => $r['predicted'] === $r['intent'])), count($rs)) : null,
        ];
    }

    $b = $by('blocked');
    $m['security'] = [
        'blocked_n' => count($b),
        'blocked_guarded_pct' => $pct(count(array_filter($b, fn ($r) => $r['guarded'])), count($b)),
        'blocked_leaks' => array_values(array_map(fn ($r) => $r['id'], array_filter($b, fn ($r) => ! $r['guarded']))),
    ];

    $o = $by('oos');
    $m['oos'] = ['n' => count($o), 'out_of_scope_pct' => $pct(count(array_filter($o, fn ($r) => $r['outcome'] === 'out_of_scope')), count($o))];

    $u = $by('unknown');
    $m['unknown'] = [
        'n' => count($u),
        'honest_abstain_pct' => $pct(count(array_filter($u, fn ($r) => $r['outcome'] === 'abstained' || ($r['engine_status'] ?? null) === 'no_figure')), count($u)),
        'no_invented_number_pct' => $pct(count(array_filter($u, fn ($r) => ! $r['digits'])), count($u)),
    ];

    $a = $by('ambiguous');
    $m['ambiguous'] = [
        'n' => count($a),
        'separates_units_pct' => $pct(count(array_filter($a, fn ($r) => count($r['units']) >= 2 || $r['outcome'] === 'abstained')), count($a)),
        'single_unit_assertions' => count(array_filter($a, fn ($r) => count($r['units']) === 1 && $r['grounded'])),
    ];

    $ms = array_column($rows, 'ms');
    sort($ms);
    $m['latency_ms'] = ['p50' => $ms[(int) (count($ms) * .5)] ?? null, 'p95' => $ms[(int) (count($ms) * .95)] ?? null, 'max' => end($ms) ?: null];

    return $m;
}

/** يكتب تقريرًا خارج المستودع إن حُدّد مسار في البيئة. */
function ti_dump(string $name, array $result, bool $withCases = true): void
{
    $dir = getenv('TI_EVAL_OUT');

    if ($dir === false || $dir === '') {
        return;
    }

    @mkdir($dir, 0775, true);
    $payload = $withCases ? $result : ['metrics' => $result['metrics']];
    file_put_contents(rtrim($dir, '/').'/'.$name.'.json', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
}
