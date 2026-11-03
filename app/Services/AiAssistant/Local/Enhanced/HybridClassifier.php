<?php

namespace App\Services\AiAssistant\Local\Enhanced;

use App\Services\AiAssistant\Local\Classification;
use App\Services\AiAssistant\Local\IntentCatalog;
use App\Services\AiAssistant\Local\IntentClassifier;
use App\Services\AiAssistant\Local\Language\Analysis;
use App\Services\AiAssistant\Local\Learned\LearnedIntentScorer;

/**
 * TATAWWUR-FINAL - مصنِّف هجين: يجمع درجات المحرك الحتمي القابل للتفسير (مفاهيم + أوجه + ملكية + ترجيح أقسام) مع احتمالات المصنِّف الخطي المحلي (كلمات + n-gram حرفية).
 *
 * القواعد الحتمية للحجب الأمني والغموض وخارج النطاق والغائب المعروف تبقى هي صاحبة القرار قبل الدمج (لا يخفّفها المصنِّف التعلّمي أبدًا).
 * الدمج يرتّب النوايا فقط: لا يولّد نصًا ولا حقائق. إن لم يتوفر ملف الأوزان يعود التصنيف إلى المحرك الحتمي وحده بلا أي فرق.
 */
final class HybridClassifier
{
    /** وزن درجة المحرك الحتمي ووزن لوغاريتم احتمال المصنِّف الخطي (يُثبَّتان بالتحقق المتقاطع على مجموعات الضبط فقط). */
    public const ENGINE_WEIGHT = 1.0;

    public const LEARNED_WEIGHT = 2.0;

    /** أدنى احتمال للمصنِّف الخطي يقبل نية بلا دليل مفاهيمي كافٍ، وأقصى فارق دمجي يُعدّ تقاربًا بين نيتين. */
    public const MIN_PROBABILITY = 0.20;

    public const CLOSE_DELTA = 1.5;

    public const CLOSE_PROBABILITY = 0.15;

    public function __construct(
        private readonly IntentClassifier $engine,
        private readonly LearnedIntentScorer $learned,
    ) {}

    /**
     * @param  list<string>  $tokens  Featurizer::tokens للنص الأصلي
     * @param  array<string, float>|null  $prior
     */
    public function classify(Analysis $a, array $tokens, ?array $prior): HybridResult
    {
        $base = $this->engine->classify($a, $prior);
        $probs = $this->learned->probabilities($tokens, 200);

        // القواعد الحتمية للحجب والغموض الوحدوي هي صاحبة القرار دائمًا.
        if (in_array($base->status, ['blocked', 'ambiguous'], true) || $probs === []) {
            return new HybridResult($base, $probs, []);
        }

        // حجب إضافي بالمصنِّف الخطي (يزيد الرفض فقط؛ لا يفتح شيئًا مما حجبته القواعد أو الحراس).
        [$blockClass, $blockP] = $this->bestBlocked($probs);

        if ($blockClass !== null && $blockP >= (EnhancedPolicy::BLOCK_P_BY_CLASS[$blockClass] ?? EnhancedPolicy::BLOCK_P) && ! $this->vetoed($blockClass, $blockP, $a)) {
            return new HybridResult(new Classification('blocked', $blockClass, 0.0, 0.0, null, [], 'hybrid-block'), $probs, []);
        }

        if (in_array($base->status, ['oos', 'no_figure'], true)) {
            return new HybridResult($base, $probs, []);
        }

        [$raw] = $this->engine->rawScores($a, $prior);
        $fused = [];

        foreach (IntentCatalog::all() as $name => $_) {
            $fused[$name] = self::ENGINE_WEIGHT * ($raw[$name] ?? 0.0) + self::LEARNED_WEIGHT * log(($probs[$name] ?? 0.0) + 1.0e-4);
        }

        arsort($fused);
        $names = array_keys($fused);
        $best = $names[0];
        $second = $names[1];
        $pBest = $probs[$best] ?? 0.0;
        $eBest = $raw[$best] ?? 0.0;
        $ranked = [];

        foreach (array_slice($fused, 0, 4, true) as $name => $v) {
            $ranked[] = [$name, round($v, 2)];
        }

        $special = $this->special($probs, $pBest, $eBest, $a);

        if ($special !== null) {
            return new HybridResult(new Classification($special[0], $special[1], $eBest, 0.0, $best, $ranked, 'hybrid-special'), $probs, $raw);
        }

        if ($eBest < IntentClassifier::MIN_SCORE && $pBest < self::MIN_PROBABILITY) {
            return new HybridResult(new Classification('none', null, $eBest, 0.0, $second, $ranked), $probs, $raw);
        }

        $margin = round($fused[$best] - $fused[$second], 2);
        $close = $margin < self::CLOSE_DELTA && ($probs[$second] ?? 0.0) >= self::CLOSE_PROBABILITY && $this->differentTopic($best, $second);

        return new HybridResult(new Classification($close ? 'close' : 'ok', $best, $eBest, $margin, $second, $ranked, 'hybrid'), $probs, $raw);
    }

    /**
     * حق النقض على الحجب الخطي: سؤال استفهامي عن آلية (كيف/لماذا/كم/هل يمكن) أو عن حساب السائل نفسه ليس طلبًا محجوبًا ما لم يبلغ المصنِّف يقينًا شبه كامل.
     * (لا يمسّ حجب القواعد الحتمية ولا الحراس؛ يمنع فقط زيادة الحجب الناتجة عن المصنِّف الخطي من أن تصيب أسئلة مشروعة.)
     */
    private function vetoed(string $class, float $p, Analysis $a): bool
    {
        if ($p >= EnhancedPolicy::BLOCK_CERTAIN_P) {
            return false;
        }

        $mechanism = array_intersect($a->facets, ['what', 'how', 'where', 'when', 'why', 'who', 'diff', 'which', 'howmuch', 'can']) !== [];

        return match ($class) {
            'blocked.injection' => false,
            'blocked.action' => $mechanism,
            'blocked.hint_bypass' => $mechanism || $a->has('hint_official') || ! $a->hasAny(['give_me', 'reveal']),
            'blocked.puzzle_secret' => $mechanism || $a->hasAny(['verification', 'server_word', 'competition', 'prize', 'winner', 'score', 'attempts']),
            'blocked.other_user' => ! $a->hasAny(['other_person', 'other_player', 'private_data', 'private_message']) || ($a->personal > 0 && ! $a->has('other_person')),
            'blocked.secrets' => ! $a->has('env_secret'),
            default => false,
        };
    }

    /** @param array<string, float> $probs @return array{0: ?string, 1: float} أعلى فئة محجوبة واحتمالها. */
    private function bestBlocked(array $probs): array
    {
        $class = null;
        $p = 0.0;

        foreach ($probs as $name => $value) {
            if (str_starts_with($name, 'blocked.') && $value > $p) {
                [$class, $p] = [$name, $value];
            }
        }

        return [$class, $p];
    }

    /**
     * الفئات الخاصة التي يقترحها المصنِّف الخطي: خارج النطاق | موضوع غير موثّق | غموض عام. تُقبل فقط إن غابت النية المعرفية القوية
     * (دليل المحرك الحتمي ضعيف) وكان احتمالها أعلى من احتمال أفضل نية.
     *
     * @param  array<string, float>  $probs
     * @return array{0: string, 1: string}|null [الحالة، المفتاح]
     */
    private function special(array $probs, float $pBest, float $eBest, Analysis $a): ?array
    {
        $checks = [
            ['oos', 'oos', 'oos', EnhancedPolicy::OOS_P, EnhancedPolicy::OOS_MAX_ENGINE],
            ['unknown', 'no_figure', 'unknown', EnhancedPolicy::UNKNOWN_P, EnhancedPolicy::UNKNOWN_MAX_ENGINE],
            ['ambiguous', 'ambiguous_general', 'ambiguous.general', EnhancedPolicy::AMBIGUOUS_P, EnhancedPolicy::AMBIGUOUS_MAX_ENGINE],
        ];

        foreach ($checks as [$class, $status, $key, $threshold, $maxEngine]) {
            $p = $probs[$class] ?? 0.0;

            // سؤال يذكر مفهومًا من مفاهيم المنصة لا يُصنَّف «خارج النطاق» بالمصنِّف الخطي (قاعدة النطاق الحتمية وحدها تحكم بذلك).
            if ($class === 'oos' && $a->topicCount() > 0) {
                continue;
            }

            if ($p >= $threshold && $p >= $pBest && $eBest <= $maxEngine) {
                return [$status, $key];
            }
        }

        return null;
    }

    private function differentTopic(string $a, string $b): bool
    {
        $x = IntentCatalog::get($a);
        $y = IntentCatalog::get($b);

        if ($x === null || $y === null) {
            return true;
        }

        if (($x['k'] ?? '') === 'know' && ($y['k'] ?? '') === 'know') {
            return ($x['a'] ?? '') !== ($y['a'] ?? '') || ($x['s'] ?? []) !== ($y['s'] ?? []);
        }

        return true;
    }
}
