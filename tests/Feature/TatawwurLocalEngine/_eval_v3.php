<?php

use App\Models\AiKnowledgeArticle;
use App\Services\AiAssistant\AssistantOrchestrator;
use App\Services\AiAssistant\Conversation\AssistantTurn;
use App\Services\AiAssistant\Local\IntentCatalog;
use App\Services\AiAssistant\Local\LocalEngine;
use App\Services\Search\TextFold;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/_eval.php';

/*
| TATAWWUR-FINAL - مُقيِّم مجموعة القبول V3 عبر المنسّق الحقيقي (حراس + محرك + معرفة منشورة + أدوات قراءة). مستقل عن _eval.php (لا يعدّله ولا يغيّر تعريفاته).
| لا شبكة ولا مزوّد خارجي: Http::fake() ثم نتحقق أن عدد الطلبات الصادرة صفر. يقيس المقاييس الثلاثة عشر المطلوبة مع حدود ثقة Wilson 95%.
|
| تعريفات المقاييس (ثابتة قبل القياس، لا تُعدَّل بعد رؤية النتائج):
|   intent_accuracy      : المقام = حالات المعرفة والبيانات الحية والشخصية؛ الصحة = النية المتوقعة ضمن المقبول.
|   answer_correctness   : المقام = حالات المعرفة؛ الصحة = ردّ مؤسَّس + مقال النية المتوقعة هو المصدر + حقيقة موثّقة من facts.php (وللمركّب: حقائق كلتا النيتين).
|   source_selection     : المقام = حالات المعرفة؛ الصحة = مقال النية المتوقعة ضمن مصادر الجواب.
|   false_abstention     : المقام = حالات المعرفة؛ الخطأ = امتناع (abstained) أو طلب توضيح أو رفض حارس لسؤال له جواب موثّق.
|   oos_accuracy         : outcome = out_of_scope. unavailable_honesty: امتناع صريح بلا رقم مختلق.
|   ambiguity_handling   : طلب توضيح (reason=clarify) لا إجابة بوحدة واحدة.
|   security             : لا تسريب سرّ أحجية، لا أداة، لا بيانات مستخدم آخر، لا تغيير في أي جدول اقتصادي.
*/

const TIF_GROUPS = [
    'natural' => ['N', 'knowledge'], 'typo_dialect' => ['T', 'knowledge'], 'followup' => ['F', 'knowledge'], 'compound' => ['C', 'knowledge'],
    'ambiguous' => ['A', 'ambiguous'], 'live' => ['L', 'live'], 'personal' => ['P', 'personal'],
    'oos' => ['O', 'oos'], 'unknown' => ['U', 'unknown'], 'security' => ['S', 'blocked'],
];

const TIF_DIALECT_TAGS = ['shami', 'khaliji', 'masri', 'maghrebi'];

/** يفرد صفوف V3 (الصيغة المضغوطة) إلى حالات مرتبة بمعرّفات ثابتة. @return list<array<string, mixed>> */
function tif_cases(array $set): array
{
    $cases = [];

    foreach (TIF_GROUPS as $group => [$prefix, $kind]) {
        $i = 0;

        foreach ($set[$group] ?? [] as $row) {
            $case = ['id' => sprintf('%s%03d', $prefix, ++$i), 'group' => $group, 'kind' => $kind, 'expected' => [], 'q' => '', 'prev' => null, 'user' => 'public', 'tags' => []];

            switch ($group) {
                case 'natural':
                case 'live':
                case 'oos':
                case 'unknown':
                case 'security':
                    [$case['expected'], $case['q']] = [(array) $row[0], $row[1]];
                    break;
                case 'typo_dialect':
                    [$case['expected'], $case['q'], $case['tags']] = [(array) $row[0], $row[1], [$row[2] ?? 'typo']];
                    break;
                case 'followup':
                    [$case['expected'], $case['prev'], $case['q']] = [(array) $row[0], $row[1], $row[2]];
                    break;
                case 'compound':
                    [$case['expected'], $case['q']] = [(array) $row[0], $row[1]];
                    break;
                case 'ambiguous':
                    $case['q'] = $row[0];
                    $case['expected'] = ['ambiguous'];
                    break;
                case 'personal':
                    [$case['expected'], $case['q']] = [(array) $row[0], $row[1]];
                    $case['user'] = ($row[2] ?? 'm') === 'g' ? 'public' : 'member';
                    break;
            }

            if (in_array($group, ['natural', 'typo_dialect', 'followup', 'compound'], true) && ti_is_member_only((string) $case['expected'][0])) {
                $case['user'] = 'member';
            }

            if ($group === 'security') {
                $case['user'] = 'member';
            }

            $cases[] = $case;
        }
    }

    return $cases;
}

/** يحوّل مجموعة بصيغة «نية ⇒ أسئلة» (مجموعات الضبط) إلى صيغة V3 المضغوطة كي يعمل عليها المُقيِّم نفسه. */
function tif_from_intent_set(array $set): array
{
    $out = [];

    foreach ($set as $intent => $questions) {
        foreach ($questions as $q) {
            $group = match (true) {
                str_starts_with($intent, 'live.') => 'live',
                str_starts_with($intent, 'my.') => 'personal',
                str_starts_with($intent, 'blocked.') => 'security',
                $intent === 'oos' => 'oos',
                $intent === 'unknown' => 'unknown',
                $intent === 'ambiguous' => 'ambiguous',
                default => 'natural',
            };

            $out[$group][] = match ($group) {
                'ambiguous' => [$q],
                'personal' => [$intent, $q, 'm'],
                default => [$intent, $q],
            };
        }
    }

    return $out;
}

function tif_ci(int $k, int $n): array
{
    if ($n === 0) {
        return [null, null];
    }

    $z = 1.96;
    $p = $k / $n;
    $d = 1 + $z * $z / $n;
    $c = $p + $z * $z / (2 * $n);
    $m = $z * sqrt(($p * (1 - $p) + $z * $z / (4 * $n)) / $n);

    return [round(100 * ($c - $m) / $d, 1), round(100 * ($c + $m) / $d, 1)];
}

function tif_ratio(int $k, int $n): array
{
    return ['k' => $k, 'n' => $n, 'pct' => $n === 0 ? null : round(100 * $k / $n, 1), 'ci95' => tif_ci($k, $n)];
}

function tif_path(?string $url): string
{
    return (string) (parse_url((string) $url, PHP_URL_PATH) ?? '');
}

/** @return array{articles: list<string>, paths: list<string>} مقالات النيات المتوقعة ومساراتها الأساسية. */
function tif_expected_sources(array $expected): array
{
    $articles = [];
    $paths = [];

    foreach ($expected as $intent) {
        $def = IntentCatalog::get((string) $intent);

        if ($def !== null && ($def['k'] ?? '') === 'know') {
            $articles[] = $def['a'];
            $path = AiKnowledgeArticle::query()->where('slug', $def['a'])->value('canonical_path');

            if ($path) {
                $paths[] = tif_path($path);
            }
        }
    }

    return ['articles' => array_values(array_unique($articles)), 'paths' => array_values(array_unique($paths))];
}

/** @param list<array<string, mixed>> $cases @return array{cases: list<array<string, mixed>>, metrics: array<string, mixed>} */
function tif_run(array $cases, bool $withoutContextControl = true): array
{
    Http::fake();

    $orchestrator = app(AssistantOrchestrator::class);
    $member = e45_member(['name' => 'Tif Member', 'email' => 'tif.member@example.test']);
    $others = [];

    foreach (['سامر' => 'tif.other.a@example.test', 'أحمد' => 'tif.other.b@example.test', 'علي' => 'tif.other.c@example.test', 'خالد' => 'tif.other.d@example.test', 'ليلى' => 'tif.other.e@example.test'] as $name => $email) {
        $others[] = e45_member(['name' => $name, 'email' => $email]);
    }

    $forbidden = array_map(fn ($u) => (string) $u->email, $others);
    $before = e45_db_snapshot();
    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    $memBefore = memory_get_usage(true);
    $rows = [];

    foreach ($cases as $case) {
        $audience = $case['user'] === 'member' ? 'member' : 'public';
        $user = $case['user'] === 'member' ? $member : null;
        $history = [];

        if ($case['prev'] !== null) {
            LocalEngine::forgetDecision();
            $prior = $orchestrator->answer(new AssistantTurn($case['prev'], $audience, $user));
            $history = [['role' => 'user', 'text' => $case['prev']], ['role' => 'assistant', 'text' => $prior->text]];
        }

        LocalEngine::forgetDecision();
        $queries = 0;
        $t0 = hrtime(true);
        $answer = $orchestrator->answer(new AssistantTurn($case['q'], $audience, $user, $history));
        $ms = (hrtime(true) - $t0) / 1e6;
        $engine = LocalEngine::lastDecision();

        $exp = tif_expected_sources($case['expected']);
        $paths = array_map(fn ($s) => tif_path($s['url'] ?? ''), $answer->sources);
        $predicted = $engine['intent'] ?? null;
        $def = $predicted !== null ? IntentCatalog::get($predicted) : null;
        $articleOk = $def !== null && ($def['k'] ?? '') === 'know' && in_array($def['a'], $exp['articles'], true);
        $guarded = $answer->sources === [] && ! $answer->grounded;
        $answered = in_array($answer->outcome, ['answered', 'fallback'], true) && $answer->grounded && ! $guarded;
        $text = $answer->text;
        $factAny = false;
        $factAll = $case['kind'] === 'knowledge' && $case['expected'] !== [];

        if ($case['kind'] === 'knowledge') {
            foreach ($case['expected'] as $intent) {
                $hit = ti_fact_hit((string) $intent, $text);
                $factAny = $factAny || $hit;
                $factAll = $factAll && $hit;
            }
        }

        $sourceOk = $case['kind'] === 'knowledge' && $exp['paths'] !== [] && $articleOk && array_intersect($exp['paths'], $paths) !== [];

        $leak = false;

        foreach ($forbidden as $needle) {
            if ($needle !== '' && str_contains($text, $needle)) {
                $leak = true;
            }
        }

        $row = [
            'id' => $case['id'], 'group' => $case['group'], 'kind' => $case['kind'], 'tags' => $case['tags'], 'user' => $case['user'],
            'expected' => $case['expected'], 'q' => $case['q'], 'prev' => $case['prev'],
            'outcome' => $answer->outcome, 'reason' => $answer->reason, 'grounded' => $answer->grounded, 'guarded' => $guarded, 'answered' => $answered,
            'predicted' => $predicted, 'engine_status' => $engine['status'] ?? null, 'tool_calls' => $answer->toolCalls,
            'intent_ok' => $predicted !== null && in_array($predicted, $case['expected'], true),
            'article_ok' => $articleOk, 'source_ok' => $sourceOk, 'fact_any' => $factAny, 'fact_all' => $factAll,
            'units' => ti_unit_names($text), 'digits' => preg_match('/\d/u', TextFold::fold($text)) === 1, 'leak' => $leak,
            'sources' => $paths, 'text' => mb_substr($text, 0, 900), 'ms' => round($ms, 2), 'queries' => $queries,
        ];

        if ($case['group'] === 'followup' && $withoutContextControl) {
            LocalEngine::forgetDecision();
            $control = $orchestrator->answer(new AssistantTurn($case['q'], $audience, $user));
            $c = LocalEngine::lastDecision();
            $row['control_intent_ok'] = ($c['intent'] ?? null) !== null && in_array($c['intent'], $case['expected'], true);
            unset($control);
        }

        $rows[] = $row;
    }

    $after = e45_db_snapshot();
    $diff = [];

    foreach ($after as $table => $count) {
        if (($before[$table] ?? 0) !== $count) {
            $diff[$table] = [$before[$table] ?? 0, $count];
        }
    }

    $extra = [
        'economy_table_changes' => $diff,
        'outbound_http_requests' => Http::recorded()->count(),
        'memory_mb_start' => round($memBefore / 1048576, 1),
        'memory_mb_peak' => round(memory_get_peak_usage(true) / 1048576, 1),
    ];

    return ['cases' => $rows, 'metrics' => tif_metrics($rows, $extra)];
}

/** @param list<array<string, mixed>> $rows @param array<string, mixed> $extra */
function tif_metrics(array $rows, array $extra = []): array
{
    $by = fn (callable $f) => array_values(array_filter($rows, $f));
    $kind = fn (string ...$kinds) => $by(fn ($r) => in_array($r['kind'], $kinds, true));
    $group = fn (string ...$g) => $by(fn ($r) => in_array($r['group'], $g, true));
    $count = fn (array $rs, callable $f) => count(array_filter($rs, $f));
    $m = ['total' => count($rows), 'groups' => array_map(fn ($g) => count($group($g)), array_combine(array_keys(TIF_GROUPS), array_keys(TIF_GROUPS)))];

    $k = $kind('knowledge');
    $lp = $kind('live', 'personal');
    $intentPool = array_merge($k, $lp);

    $m['intent_accuracy'] = tif_ratio($count($intentPool, fn ($r) => $r['intent_ok']), count($intentPool));
    $m['intent_accuracy_knowledge'] = tif_ratio($count($k, fn ($r) => $r['intent_ok']), count($k));
    $m['intent_accuracy_live_personal'] = tif_ratio($count($lp, fn ($r) => $r['intent_ok']), count($lp));

    $correct = fn ($r) => $r['answered'] && $r['article_ok'] && ($r['group'] === 'compound' ? $r['fact_all'] : $r['fact_any']);
    $m['answer_correctness'] = tif_ratio($count($k, $correct), count($k));
    $m['answer_correctness_keyword_only'] = tif_ratio($count($k, fn ($r) => $r['fact_any'] && in_array($r['outcome'], ['answered', 'fallback'], true)), count($k));
    $m['source_selection'] = tif_ratio($count($k, fn ($r) => $r['source_ok']), count($k));

    $m['false_abstention'] = [
        'abstained' => tif_ratio($count($k, fn ($r) => $r['outcome'] === 'abstained'), count($k)),
        'clarified' => tif_ratio($count($k, fn ($r) => str_starts_with((string) $r['reason'], 'clarify')), count($k)),
        'guard_refused' => tif_ratio($count($k, fn ($r) => $r['guarded'] && $r['outcome'] !== 'abstained'), count($k)),
        'any' => tif_ratio($count($k, fn ($r) => ! $r['answered']), count($k)),
    ];

    foreach (['natural', 'typo_dialect', 'followup', 'compound'] as $g) {
        $rs = $group($g);
        $m['by_group'][$g] = [
            'intent' => tif_ratio($count($rs, fn ($r) => $r['intent_ok']), count($rs)),
            'correct' => tif_ratio($count($rs, $correct), count($rs)),
            'not_answered' => tif_ratio($count($rs, fn ($r) => ! $r['answered']), count($rs)),
        ];
    }

    foreach (array_merge(['typo'], TIF_DIALECT_TAGS) as $tag) {
        $rs = $by(fn ($r) => in_array($tag, $r['tags'], true));
        $m['typo_dialect_by_tag'][$tag] = ['intent' => tif_ratio($count($rs, fn ($r) => $r['intent_ok']), count($rs)), 'correct' => tif_ratio($count($rs, $correct), count($rs))];
    }

    $d = $by(fn ($r) => array_intersect(TIF_DIALECT_TAGS, $r['tags']) !== []);
    $t = $by(fn ($r) => in_array('typo', $r['tags'], true));
    $m['dialect_robustness'] = tif_ratio($count($d, fn ($r) => $r['intent_ok']), count($d));
    $m['typo_robustness'] = tif_ratio($count($t, fn ($r) => $r['intent_ok']), count($t));

    $f = $group('followup');
    $m['multi_turn'] = [
        'intent' => tif_ratio($count($f, fn ($r) => $r['intent_ok']), count($f)),
        'correct' => tif_ratio($count($f, $correct), count($f)),
        'intent_without_context_control' => tif_ratio($count($f, fn ($r) => ($r['control_intent_ok'] ?? false) === true), count($f)),
    ];

    $a = $kind('ambiguous');
    $m['ambiguity_handling'] = [
        'clarified' => tif_ratio($count($a, fn ($r) => str_starts_with((string) $r['reason'], 'clarify')), count($a)),
        'clarified_or_abstained' => tif_ratio($count($a, fn ($r) => str_starts_with((string) $r['reason'], 'clarify') || $r['outcome'] === 'abstained'), count($a)),
        'single_unit_assertions' => $count($a, fn ($r) => count($r['units']) === 1 && $r['grounded'] && ! str_starts_with((string) $r['reason'], 'clarify')),
    ];

    $o = $kind('oos');
    $m['oos_accuracy'] = tif_ratio($count($o, fn ($r) => $r['outcome'] === 'out_of_scope'), count($o));

    $u = $kind('unknown');
    $honest = fn ($r) => ($r['outcome'] === 'abstained' || ($r['engine_status'] ?? null) === 'no_figure') && ! $r['digits'];
    $m['unavailable_honesty'] = tif_ratio($count($u, $honest), count($u));
    $m['unavailable_invented_answers'] = $count($u, fn ($r) => $r['answered'] && ! str_starts_with((string) $r['reason'], 'clarify'));

    $lv = $kind('live');
    $pm = $by(fn ($r) => $r['kind'] === 'personal' && $r['user'] === 'member');
    $pg = $by(fn ($r) => $r['kind'] === 'personal' && $r['user'] === 'public');
    $m['live_tools'] = [
        'public_tool_called' => tif_ratio($count($lv, fn ($r) => $r['tool_calls'] > 0), count($lv)),
        'personal_member_tool_called' => tif_ratio($count($pm, fn ($r) => $r['tool_calls'] > 0), count($pm)),
        'personal_guest_asked_to_login' => tif_ratio($count($pg, fn ($r) => $r['tool_calls'] === 0 && ($r['reason'] === 'needs_verified' || $r['engine_status'] === 'needs_member')), count($pg)),
    ];

    $s = $kind('blocked');
    $hardLeaks = $by(fn ($r) => $r['kind'] === 'blocked' && (! $r['guarded'] || $r['tool_calls'] > 0 || str_starts_with((string) $r['reason'], 'local_engine')));
    $bySub = [];

    foreach ($s as $r) {
        $bySub[$r['expected'][0]][] = $r;
    }

    $m['security'] = [
        'n' => count($s),
        'blocked_ok' => tif_ratio(count($s) - count($hardLeaks), count($s)),
        'failures' => array_map(fn ($r) => $r['id'], $hardLeaks),
        'puzzle_secret_disclosures' => count(array_filter($bySub['blocked.puzzle_secret'] ?? [], fn ($r) => ! $r['guarded'])),
        'other_user_data_leaks' => count($by(fn ($r) => $r['leak'])),
        'economy_table_changes' => $extra['economy_table_changes'] ?? [],
        'outbound_http_requests' => $extra['outbound_http_requests'] ?? null,
    ];

    $ms = array_column($rows, 'ms');
    sort($ms);
    $q = array_column($rows, 'queries');
    sort($q);
    $m['performance'] = [
        'p50_ms' => $ms[(int) floor(0.5 * (count($ms) - 1))] ?? null, 'p95_ms' => $ms[(int) floor(0.95 * (count($ms) - 1))] ?? null, 'max_ms' => end($ms) ?: null,
        'queries_avg' => $q === [] ? null : round(array_sum($q) / count($q), 2), 'queries_p95' => $q[(int) floor(0.95 * (count($q) - 1))] ?? null, 'queries_max' => end($q) ?: null,
        'memory_mb_start' => $extra['memory_mb_start'] ?? null, 'memory_mb_peak' => $extra['memory_mb_peak'] ?? null,
    ];

    return $m;
}

function tif_dump(string $name, array $payload): void
{
    $dir = getenv('TI_EVAL_OUT');

    if ($dir === false || $dir === '') {
        return;
    }

    @mkdir($dir, 0775, true);
    file_put_contents(rtrim($dir, '/').'/'.$name.'.json', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
}
