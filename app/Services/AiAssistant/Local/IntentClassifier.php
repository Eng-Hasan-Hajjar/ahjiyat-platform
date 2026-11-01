<?php

namespace App\Services\AiAssistant\Local;

use App\Services\AiAssistant\Local\Language\Analysis;
use App\Services\AiAssistant\Local\Language\Lexicon;

/**
 * TI-2 - مصنِّف النوايا: حتمي، محلي، قابل للتفسير (مجموع أوزان مفاهيم + وجه السؤال + ملكية).
 * الترتيب: (1) كشف الحجب الأمني ← (2) غموض «نقاط» ← (3) مواضيع معروفة الغياب ← (4) خارج النطاق ← (5) تسجيل النوايا.
 * يُستعمل بعد الحراس القديمة (E45) وليس بدلًا منها، ولا يخفّف أيًّا منها: يضيف رفضًا فقط.
 */
final class IntentClassifier
{
    public const MIN_SCORE = 4.0;

    public const CLOSE_MARGIN = 0.8;

    /** TI-Q1: نُدفة ترجيح الأقسام المضافة لدرجة نية لها دليل مفاهيمي (تُضرب في جيب التمام النسبي إلى الأعلى). */
    public const PRIOR_NUDGE = 3.0;

    /** TI-Q1: أدنى جيب تمام أعلى يُعتدّ به، وشروط إنقاذ حالة «لا نية» من الترجيح وحده (درجة عليا + فارق عن الثانية). */
    public const PRIOR_FLOOR = 0.10;

    public const RESCUE_COSINE = 0.20;

    public const RESCUE_GAP = 0.07;

    /** مفاهيم لا تُحتسب في عقوبة «الزائد» (سياقية لا موضوعية). */
    /** أفعال أمر تنفيذية (مطوية) تُعدّ طلب إجراء إذا جاءت في أول الجملة. */
    private const IMPERATIVE_VERBS = ['استبدل', 'استلم', 'اسحب', 'اطلب', 'صوت', 'سجل', 'اشتر', 'اشتري', 'حول', 'انقل', 'ارسل', 'نفذ', 'فعل', 'الغ', 'احذف', 'امسح', 'جدد', 'اشحن', 'ادفع', 'اهدي', 'اضف', 'قدم', 'ارفع', 'غير', 'بدل'];

    private const IMPERATIVE_FILLERS = ['لو', 'سمحت', 'من', 'فضلك', 'ارجوك', 'يا', 'مساعد', 'رجاء', 'مرحبا', 'لطفا', 'تكرمت', 'الان', 'يلا', 'طيب', 'اوكي'];

    private const IMMEDIATE_WORDS = ['الان', 'فورا', 'حالا', 'هسا', 'هلق', 'دلوقتي', 'توا', 'الحين', 'حاليا'];

    private const GENERIC = ['platform', 'can_do', 'page', 'same', 'puzzle', 'account', 'profile', 'free', 'start', 'ai'];

    /** مفاهيم تحدّد الوحدة المقصودة بكلمة «نقاط» فتُزيل الغموض. */
    private const POINT_QUALIFIERS = ['gems', 'wallet', 'xp', 'level', 'pass', 'pass_points', 'support_points', 'supporter', 'supporter_card', 'season', 'team', 'competition', 'score', 'tier', 'streak', 'quest', 'achievement', 'writer', 'star', 'vote', 'pending', 'available', 'redeem', 'championship', 'leaderboard', 'reputation', 'challenge', 'campaign', 'friend', 'referral'];

    /** @param array<string, float>|null $prior ترجيح الأقسام (نية ⇒ جيب تمام) من SectionPrior؛ null = السلوك الأصلي تمامًا. */
    public function classify(Analysis $a, ?array $prior = null): Classification
    {
        if (($blocked = $this->blocked($a)) !== null) {
            return new Classification('blocked', $blocked);
        }

        if ($this->ambiguousPoints($a)) {
            return new Classification('ambiguous', 'ambiguous.points');
        }

        if ($this->outOfScope($a)) {
            return new Classification('oos', 'oos');
        }

        if (($unknown = $this->unsupported($a)) !== null) {
            return new Classification('no_figure', 'unknown', 0.0, 0.0, null, [], $unknown);
        }

        return $this->score($a, $prior);
    }

    /** حجب أمني إضافي لما قد يفلت من الحراس القديمة؛ لا يخفّف شيئًا منها. */
    public function blocked(Analysis $a): ?string
    {
        $facets = $a->facets;
        $interrogative = array_intersect($facets, ['what', 'how', 'where', 'when', 'why', 'diff', 'which', 'howmuch']) !== [];

        if ($a->has('injection')) {
            return 'blocked.injection';
        }

        if ($a->has('env_secret') && ! $a->hasAny(['ai', 'retention', 'platform_name', 'search'])) {
            return 'blocked.secrets';
        }

        if ($a->has('hint') && $a->hasAny(['give_me', 'reveal']) && ! $a->has('hint_official') && ! $a->hasAny(['price', 'spend', 'cap']) && ! in_array('how', $facets, true)) {
            return 'blocked.hint_bypass';
        }

        // أمر مباشر بحل الأحجية أو تخمين جوابها («جاوبني على اللغز»، «خمّن لي الجواب») ليس سؤالًا عن آلية المنصة.
        if ($a->has('solve_verb') && $a->hasAny(['puzzle', 'solution']) && ! $interrogative && ! in_array('can', $facets, true)) {
            return 'blocked.puzzle_secret';
        }

        if ($a->has('solution')) {
            $probe = $a->hasAny(['letters', 'yes_no']);
            $ask = $a->hasAny(['give_me', 'reveal']);
            $explain = $a->hasAny(['verification', 'server_word']) || in_array('how', $facets, true) || in_array('why', $facets, true);

            if ($probe || ($ask && ! $explain)) {
                return 'blocked.puzzle_secret';
            }

            if ($a->has('puzzle') && ! $explain && ! $a->hasAny(['competition', 'prize', 'winner', 'wrong', 'score'])) {
                return 'blocked.puzzle_secret';
            }
        }

        // بيانات شخص آخر.
        if ($a->has('other_person') && $a->hasAny(['gems', 'figure_gems', 'wallet', 'email', 'private_data', 'private_message', 'violation', 'review', 'xp', 'level', 'account', 'redeem', 'order', 'private_word'])) {
            return 'blocked.other_user';
        }

        if ($a->has('private_data') && $a->hasAny(['other_player', 'who_top', 'friend']) && $a->hasAny(['give_me', 'reveal', 'what', 'who'])) {
            return 'blocked.other_user';
        }

        if ($a->has('private_message') && $a->hasAny(['give_me', 'reveal']) && ! $a->hasAny(['ai', 'read', 'delete', 'report'])) {
            return 'blocked.other_user';
        }

        // طلب تنفيذ فعلي (أمر لا سؤال).
        if ($a->hasAny(['act_verb', 'transfer']) && ! $interrogative && ! in_array('can', $facets, true)) {
            return 'blocked.action';
        }

        if ($a->has('act_verb') && $a->has('do_for_me') && ! $interrogative) {
            return 'blocked.action';
        }

        if ($this->imperativeAction($a, $interrogative)) {
            return 'blocked.action';
        }

        if ($a->has('do_for_me') && ! $interrogative && ! in_array('can', $facets, true) && ! $a->hasAny(['ai', 'cannot_do']) && $a->hasAny(['redeem', 'buy', 'vote', 'gems', 'pass', 'premium', 'order', 'star', 'writer', 'claim', 'transfer', 'register'])) {
            return 'blocked.action';
        }

        return null;
    }

    /**
     * صيغة أمر في أول الجملة موجّهة إلى المساعد («استبدل جواهري الآن»، «استلم لي مكافأة المسار»): فعل تنفيذ + ما يدل على أنه مطلوب
     * منه شخصيًا («لي» أو ظرف فوري) + موضوع اقتصادي/حسابي. الأمر المجرد («غير كلمة السر») يبقى سؤال إرشاد لأن شكله يحتمل الوجهين،
     * فيُجاب بالخطوات دون أن ينفّذ المساعد شيئًا.
     */
    private function imperativeAction(Analysis $a, bool $interrogative): bool
    {
        if ($interrogative || in_array('can', $a->facets, true) || $a->hasAny(['ai', 'cannot_do'])) {
            return false;
        }

        $tokens = $a->tokens;
        $i = 0;

        while (isset($tokens[$i]) && in_array($tokens[$i], self::IMPERATIVE_FILLERS, true)) {
            $i++;
        }

        if (! isset($tokens[$i]) || ! in_array($tokens[$i], self::IMPERATIVE_VERBS, true)) {
            return false;
        }

        $directed = in_array('لي', $tokens, true) || in_array('عني', $tokens, true) || array_intersect($tokens, self::IMMEDIATE_WORDS) !== [];

        return $directed && $a->hasAny(['redeem', 'gems', 'wallet', 'pass', 'reward', 'claim', 'order', 'account', 'password', 'premium', 'vote', 'star', 'supporter_card', 'pending', 'available', 'email']);
    }

    private function ambiguousPoints(Analysis $a): bool
    {
        if (! $a->has('points')) {
            return false;
        }

        if ($a->hasAny(self::POINT_QUALIFIERS) || array_diff_key($a->inherited, ['points' => 1]) !== []) {
            return false;
        }

        $ignorable = array_merge(Lexicon::STRUCTURAL, ['points', 'earn', 'convert', 'cash_value', 'how_many', 'reward', 'spend', 'buy', 'status', 'can_do', 'platform', 'is_currency']);

        return array_diff(array_keys($a->concepts), $ignorable) === [];
    }

    private function unsupported(Analysis $a): ?string
    {
        foreach (['mobile_app', 'company', 'language_other', 'support_contact', 'user_count', 'subscription', 'crypto', 'payment_method'] as $topic) {
            if (! $a->has($topic)) {
                continue;
            }

            // «هل هناك اشتراك إجباري/مدفوع؟» سؤال عن مجانية المنصة (موثّق) لا عن تسعير اشتراك: لا يُعدّ غير مدعوم ما لم يُطلب سعر أو مبلغ.
            if ($topic === 'subscription' && $a->hasAny(['free', 'payment', 'conditions']) && ! $a->has('price') && ! in_array('howmuch', $a->facets, true)) {
                continue;
            }

            return $topic;
        }

        if ($a->has('puzzle_difficulty_easy') && $a->hasAny(['figure_gems', 'gems', 'reward', 'earn'])) {
            return 'exact_amounts';
        }

        if ($a->has('figure_gems') && $a->hasAny(['puzzle', 'earn', 'reward', 'quest', 'achievement']) && $a->personal < 2 && ! $a->hasAny(['wallet', 'pending', 'available', 'cap', 'daily_word', 'redeem', 'minimum', 'other_person'])) {
            return 'exact_amounts';
        }

        return null;
    }

    private function outOfScope(Analysis $a): bool
    {
        if (! $a->hasAny(['oos_topic', 'oos_write'])) {
            return false;
        }

        if ($a->hasAny(['mobile_app', 'company', 'support_contact', 'user_count', 'subscription', 'crypto', 'payment_method'])) {
            return false;
        }

        $topics = array_diff(array_keys($a->concepts), array_merge(Lexicon::STRUCTURAL, ['oos_topic', 'oos_write', 'give_me', 'winner', 'who_top', 'platform', 'can_do']));

        return $topics === [] || ($a->has('oos_topic') && count($topics) <= 1 && ! $a->hasAny(['gems', 'xp', 'pass', 'season', 'store', 'team', 'competition', 'puzzle', 'wallet']));
    }

    /** @param array<string, float>|null $prior */
    public function score(Analysis $a, ?array $prior = null): Classification
    {
        $scores = [];
        $maxPrior = ($prior !== null && $prior !== []) ? max($prior) : 0.0;
        $usePrior = $maxPrior >= self::PRIOR_FLOOR;

        foreach (IntentCatalog::all() as $name => $def) {
            $s = $this->scoreOne($a, $def);

            if ($s > 0) {
                $scores[$name] = $s;
            }
        }

        // النُدفة تخصّ الترجيح بين نوايا معرفية فقط: إن كان للحيّ/الشخصي حضور قريب من الأعلى فلا نخلّ بميزانهما (ليس لهما ملف نصي).
        if ($usePrior && $scores !== []) {
            arsort($scores);
            $best = reset($scores);
            $nonKnowNear = false;

            foreach ($scores as $name => $s) {
                if ($best - $s > 4.0) {
                    break;
                }

                if ((IntentCatalog::get($name)['k'] ?? '') !== 'know') {
                    $nonKnowNear = true;

                    break;
                }
            }

            if (! $nonKnowNear) {
                foreach ($scores as $name => $s) {
                    if ((IntentCatalog::get($name)['k'] ?? '') === 'know') {
                        $scores[$name] = $s + self::PRIOR_NUDGE * (($prior[$name] ?? 0.0) / $maxPrior);
                    }
                }
            }
        }

        // إنقاذ: لا دليل مفاهيمي كافٍ، لكن قسمًا واحدًا يتفوق بوضوح على غيره في تشابه النص.
        $rescued = null;

        if ($usePrior && ($scores === [] || max($scores) < self::MIN_SCORE)) {
            $names = array_keys($prior);
            $top = $prior[$names[0]];
            $second = $prior[$names[1]] ?? 0.0;
            $def = IntentCatalog::get($names[0]);

            if ($top >= self::RESCUE_COSINE && $top - $second >= self::RESCUE_GAP && ($def['k'] ?? '') === 'know') {
                $scores[$names[0]] = max($scores[$names[0]] ?? 0.0, self::MIN_SCORE + 0.5);
                $rescued = $names[0];
            }
        }

        if ($scores === []) {
            return new Classification('none', null);
        }

        arsort($scores);
        $ranked = [];

        foreach (array_slice($scores, 0, 4, true) as $name => $s) {
            $ranked[] = [$name, round($s, 2)];
        }

        $best = $ranked[0][0];
        $top = $ranked[0][1];
        $second = $ranked[1] ?? null;
        $margin = $second === null ? $top : round($top - $second[1], 2);

        if ($top < self::MIN_SCORE) {
            return new Classification('none', null, $top, $margin, $second[0] ?? null, $ranked);
        }

        $status = ($second !== null && $margin < self::CLOSE_MARGIN && $this->differentTopic($best, $second[0])) ? 'close' : 'ok';

        return new Classification($status, $best, $top, $margin, $second[0] ?? null, $ranked, $rescued === $best ? 'prior_rescue' : null);
    }

    /** @param array<string, mixed> $def */
    private function scoreOne(Analysis $a, array $def): float
    {
        $weights = $def['w'];
        $inherit = $a->inherited;

        // مجموعات إلزامية: واحد على الأقل من كل مجموعة (المفاهيم الموروثة تُحتسب).
        foreach ($def['r'] as $group) {
            $ok = false;

            foreach ($group as $c) {
                if (isset($a->concepts[$c]) || isset($inherit[$c])) {
                    $ok = true;

                    break;
                }
            }

            if (! $ok) {
                return 0.0;
            }
        }

        $score = 0.0;

        foreach ($weights as $c => $w) {
            if (isset($a->concepts[$c])) {
                $score += isset($a->negated[$c]) ? $w * 0.3 : $w;
            } elseif (isset($inherit[$c])) {
                $score += $w * 0.6;
            }
        }

        if ($score <= 0) {
            return 0.0;
        }

        $first = true;

        foreach ($a->facets as $facet) {
            if (isset($def['f'][$facet])) {
                $score += $def['f'][$facet] * ($first ? 1.0 : 0.6);
            }

            $first = false;
        }

        // عقوبة المفاهيم الموضوعية الزائدة عن النية.
        $extra = 0;

        foreach ($a->concepts as $c => $_) {
            if (! isset($weights[$c]) && ! in_array($c, Lexicon::STRUCTURAL, true) && ! in_array($c, self::GENERIC, true) && ! in_array($c, ['oos_topic', 'oos_write'], true)) {
                $extra++;
            }
        }

        $kind = $def['k'];
        $score -= min($kind === 'my' ? 4.0 : 3.0, $extra * ($kind === 'my' ? 1.0 : 0.5));

        $liveHint = $a->hasAny(['live_now', 'upcoming', 'ends_when', 'participants', 'who_top', 'members_count', 'available', 'recommend', 'packages', 'limited']) || ($a->has('status') && $a->hasAny(['season', 'writer', 'league', 'campaign', 'competition']))
            || ($a->has('supporter') && array_intersect($a->facets, ['who', 'howmuch']) !== []);

        if ($kind === 'my') {
            $score += match ($a->personal) {
                2 => 3.0,
                1 => 1.5,
                default => 0.0,
            };

            if ($a->personal === 0) {
                $score *= 0.3;
            }

            $explain = array_intersect($a->facets, ['why', 'diff']) !== [] || (in_array('how', $a->facets, true) && ! in_array('howmuch', $a->facets, true) && ! in_array($def['t'], ['season', 'campaign'], true));

            if ($explain) {
                $score -= 3.0;
            }
        } elseif ($kind === 'live') {
            if (! $liveHint) {
                $score *= 0.5;
            }

            if ($a->personal === 2) {
                $score *= 0.6;
            }
        } else {
            if ($liveHint) {
                $score *= 0.85;
            }

            if ($a->personal === 2 && $a->hasAny(['how_many', 'remaining'])) {
                $score *= 0.9;
            }
        }

        return max(0.0, $score);
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
