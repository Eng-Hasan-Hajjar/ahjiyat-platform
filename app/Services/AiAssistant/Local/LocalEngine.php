<?php

namespace App\Services\AiAssistant\Local;

use App\Services\AiAssistant\Conversation\AssistantAnswer;
use App\Services\AiAssistant\Conversation\AssistantTurn;
use App\Services\AiAssistant\Local\Enhanced\ClauseSplitter;
use App\Services\AiAssistant\Local\Enhanced\ContextualClassifier;
use App\Services\AiAssistant\Local\Enhanced\EnhancedPolicy;
use App\Services\AiAssistant\Local\Enhanced\EnhancedReplies;
use App\Services\AiAssistant\Local\Enhanced\EntityTools;
use App\Services\AiAssistant\Local\Enhanced\HybridResult;
use App\Services\AiAssistant\Knowledge\KnowledgeIndex;
use App\Services\AiAssistant\Local\Language\Analysis;
use App\Services\AiAssistant\Local\Language\Analyzer;
use App\Services\AiAssistant\Safety\AiLinkPolicy;
use App\Services\AiAssistant\Safety\AiOutputGuard;
use App\Services\AiAssistant\Safety\AiPuzzleSafetyGuard;
use App\Services\AiAssistant\Safety\AiScopeGuard;
use App\Services\AiAssistant\Safety\CannedReplies;
use App\Services\AiAssistant\Safety\GuardVerdict;
use App\Services\AiAssistant\TatawwurSettings;
use App\Services\Search\TextFold;
use App\Services\AiAssistant\Tools\Presenter;
use App\Services\AiAssistant\Tools\ToolContext;

/**
 * TI-4 - المحرك الداخلي للفهم والإجابة (محلي بالكامل: بلا شبكة ولا مزوّد ولا نموذج لغوي).
 *
 * يعمل **بعد** حراس E45 (أسرار الأحجيات والنطاق) داخل فرع «لا مزوّد» فقط، خلف علم TATAWWUR_AI_LOCAL_ENGINE_ENABLED (مغلق افتراضيًا)،
 * ولا يخفّف أيًّا منها: يضيف رفضًا فقط. التسلسل: تحليل لغوي ← تصنيف ← فحص أمني لاحق للتصحيح الإملائي ← إجابة من المعرفة المنشورة / أداة قراءة موجودة
 * ← AiOutputGuard. أي عدم يقين ⇒ null فيرجع المنسّق إلى مسار الاسترجاع القديم بلا تغيير.
 *
 * لا يخترع طرق كسب ولا أرقامًا ولا أسعارًا: كل جملة تأتي من نص مقال منشور أو من ناتج أداة قراءة عامة/شخصية.
 */
final class LocalEngine
{
    public const REASON = 'local_engine';

    public const CLARIFY_POINTS = 'للنقاط في المنصة أنواع مستقلة لا تتحوّل إحداها إلى الأخرى: الجواهر، والخبرة التي تحدد المستوى، ونقاط مسار الأحجيات، ونقاط الدعم. أخبرني أيّها تقصد لأشرحه لك.';

    /** @var array{intent: ?string, status: string, score: float, margin: float, second: ?string, kind: ?string, corrections: int}|null */
    private static ?array $last = null;

    public function __construct(
        private readonly KnowledgeIndex $index,
        private readonly AiPuzzleSafetyGuard $puzzleGuard,
        private readonly AiScopeGuard $scopeGuard,
        private readonly AiOutputGuard $output,
        private readonly LiveResolver $live,
        private readonly AnswerComposer $composer,
        private readonly Analyzer $analyzer,
        private readonly IntentClassifier $classifier,
        private readonly SectionPrior $prior,
        private readonly ContextualClassifier $contextual,
        private readonly EntityTools $entities,
        private readonly ClauseSplitter $splitter,
    ) {}

    /** آخر قرار اتخذه المحرك (للتقييم والاختبار فقط؛ لا يحوي نص السؤال). @return array<string, mixed>|null */
    public static function lastDecision(): ?array
    {
        return self::$last;
    }

    /** تصفير آخر قرار (للاختبار فقط). */
    public static function forgetDecision(): void
    {
        self::$last = null;
    }

    public function answer(AssistantTurn $turn, string $text, bool $member): ?AssistantAnswer
    {
        self::$last = null;

        // TATAWWUR-FINAL: طبقة الفهم المحسَّنة خلف علم مستقل مغلق افتراضيًا. مغلقًا ⇒ يبقى المسار التالي (TI-4) كما كان بلا أي فرق.
        if (TatawwurSettings::localSemanticEnabled()) {
            return $this->answerEnhanced($turn, $text, $member);
        }

        $previous = $turn->previousUserMessage();
        $a = $this->analyzer->analyze($text, $previous);
        $prior = $this->prior->scores($a, $member);
        $c = $this->classifier->classify($a, $prior);

        // TI-Q1: محاولة ثانية بمصحّح إملائي أعمّ (مسافة تحريرية 1) فقط عند وجود كلمات مجهولة، وتُقبل بشرط زيادة الدليل، فلا تُفسد إجابة سليمة.
        if ($a->residual !== [] && in_array($c->status, ['none', 'close', 'ok'], true)) {
            $a2 = $this->analyzer->analyze($text, $previous, true);

            if ($a2->corrections !== $a->corrections) {
                $prior2 = $this->prior->scores($a2, $member);
                $c2 = $this->classifier->classify($a2, $prior2);

                if ($this->preferRetry($c, $c2)) {
                    $a = $a2;
                    $c = $c2;
                }
            }
        }

        $this->remember($c, $a);

        // فحص أمني لاحق للتحليل اللغوي: لا يجوز أن يتحول سؤال إلى مطلب محجوب بعد التصحيح/التطبيع.
        if ($a->corrections !== []) {
            $corrected = $a->correctedText();

            foreach ([$this->puzzleGuard->inspect($corrected, $previous), $this->scopeGuard->inspect($corrected)] as $verdict) {
                if (! $verdict->isAllowed() && $verdict->action !== GuardVerdict::CANNED) {
                    self::$last['status'] = 'blocked_after_correction';

                    return $this->guarded($verdict);
                }
            }
        }

        return match ($c->status) {
            'blocked' => $this->guarded($this->verdictFor((string) $c->intent)),
            'oos' => $this->guarded(new GuardVerdict(GuardVerdict::REFUSE, 'out_of_scope', 'out_of_scope')),
            'no_figure' => $this->abstain(),
            'ambiguous' => $this->clarify(),
            'ok', 'close' => $this->byKind($turn, $c, $a, $member, $text),
            default => null,
        };
    }

    // ───────────── TATAWWUR-FINAL: المسار المحسَّن (خلف TATAWWUR_AI_LOCAL_SEMANTIC_ENABLED، مغلق افتراضيًا) ─────────────

    private function answerEnhanced(AssistantTurn $turn, string $text, bool $member): ?AssistantAnswer
    {
        $previous = $turn->previousUserMessage();

        // تقوية المسار المحسَّن: تمويه التباعد («a n s w e r») يُفحص بالمحارس نفسها، و«الكلمة السرية للغز» بلفظ آخر لحلّه تُحجب.
        $spaced = EnhancedPolicy::despaced($text);

        if ($spaced !== null) {
            foreach ([$this->puzzleGuard->inspect($spaced, $previous), $this->scopeGuard->inspect($spaced)] as $verdict) {
                if (! $verdict->isAllowed() && $verdict->action !== GuardVerdict::CANNED) {
                    self::$last = ['intent' => null, 'status' => 'blocked_despaced', 'context' => false, 'probability' => null];

                    return $this->guarded($verdict);
                }
            }
        }

        if (EnhancedPolicy::secretWordOfPuzzle($text)) {
            self::$last = ['intent' => 'blocked.puzzle_secret', 'status' => 'blocked_secret_word', 'context' => false, 'probability' => null];

            return $this->guarded($this->verdictFor('blocked.puzzle_secret'));
        }

        [$result, $a, $usedContext] = $this->contextual->classify($text, $previous, $member);
        $c = $result->classification;
        $this->remember($c, $a);
        self::$last['context'] = $usedContext;
        self::$last['probability'] = $c->intent !== null ? round($result->probability($c->intent), 3) : null;

        // فحص أمني لاحق للتحليل اللغوي (كالمسار القديم): لا يجوز أن يتحول سؤال إلى مطلب محجوب بعد التصحيح/التطبيع.
        if ($a->corrections !== []) {
            $corrected = $a->correctedText();

            foreach ([$this->puzzleGuard->inspect($corrected, $previous), $this->scopeGuard->inspect($corrected)] as $verdict) {
                if (! $verdict->isAllowed() && $verdict->action !== GuardVerdict::CANNED) {
                    self::$last['status'] = 'blocked_after_correction';

                    return $this->guarded($verdict);
                }
            }
        }

        $context = ToolContext::forUser($turn->user, $member);

        // طلب صريح لكيان محدد (عنوان مقتبس/رابط/معرّف) ⇒ أدوات القراءة الست عبر موجّه الكيانات.
        if (! in_array($c->status, ['blocked', 'oos'], true)) {
            $entity = $this->entities->answer($text, $context);

            if ($entity !== null) {
                self::$last['status'] = 'entity';

                return $this->finalize($entity);
            }
        }

        if (in_array($c->status, ['ok', 'close', 'oos', 'no_figure', 'ambiguous_general', 'none'], true) && ($compound = $this->compound($text, $member, $context)) !== null) {
            return $compound;
        }

        return match ($c->status) {
            'blocked' => $this->guarded($this->verdictFor((string) $c->intent)),
            'oos' => $this->outOfScope(),
            'no_figure' => $this->undocumented($text),
            'ambiguous' => $this->clarify(),
            'ambiguous_general' => $this->clarifyGeneral($c, $member),
            'ok', 'close' => $this->enhancedByKind($turn, $result, $a, $member, $text, $context) ?? $this->knowledgeGap($c, $member),
            default => $this->knowledgeGap($c, $member),
        };
    }

    /** فقرة قصيرة بلا موضوع معجمي وفيها فعل بضمير مفعول متصل («أطلبه»، «أمنعها»): تعود على ما قبلها فلا تُحكَم وحدها. */
    private function anaphoric(string $clause, Analysis $a): bool
    {
        $folded = trim((string) preg_replace('/\s+/u', ' ', TextFold::fold($clause)));
        $tokens = explode(' ', $folded);

        if ($a->topicCount() > 0 || count($tokens) > 4) {
            return false;
        }

        foreach ($tokens as $t) {
            if (preg_match('/^(?:[انيت]|س)\p{L}{2,}(?:ها|هم|ه)$/u', $t) === 1) {
                return true;
            }
        }

        return false;
    }

    private function enhancedByKind(AssistantTurn $turn, HybridResult $result, Analysis $a, bool $member, string $text, ToolContext $context): ?AssistantAnswer
    {
        $c = $result->classification;
        $def = IntentCatalog::get((string) $c->intent);

        if ($def !== null && ($def['k'] ?? '') === 'know' && $this->wantsFullArticle($text) && ($full = $this->entities->guideArticle((string) ($def['a'] ?? ''), $context)) !== null) {
            self::$last['status'] = 'guide_article';

            return $this->finalize($full);
        }

        return $this->byKind($turn, $c, $a, $member, $text);
    }

    private function wantsFullArticle(string $text): bool
    {
        $folded = trim((string) preg_replace('/\s+/u', ' ', TextFold::fold($text)));

        return preg_match('/(مقال|الدليل|الشرح|الصفحه).*(كامل|كاملا|بالتفصيل|التفاصيل الكامله)|(كامل|كاملا|بالتفصيل).*(مقال|الدليل|الشرح)/u', $folded) === 1;
    }

    /**
     * سؤال مركّب: فقرتان إلى ثلاث، تُصنَّف كلٌّ على حدة. المعروفة تُجاب، وغير الموثّقة/خارج النطاق يُصرَّح بها بصدق في موضعها،
     * ولا بدّ من فقرة معروفة واحدة على الأقل (وإلا يبقى التصنيف الكلي). فقرة غير محسومة ⇒ null. فقرة محجوبة ⇒ رفض الرسالة كلها.
     */
    private function compound(string $text, bool $member, ToolContext $context): ?AssistantAnswer
    {
        $clauses = $this->splitter->split($text);

        if (count($clauses) < 2) {
            return null;
        }

        $items = [];
        $intents = [];

        foreach ($clauses as $i => $clause) {
            [$r, $ca] = $this->contextual->classify($clause, null, $member);
            $cc = $r->classification;

            switch ($cc->status) {
                case 'blocked':
                    return $this->guarded($this->verdictFor((string) $cc->intent));
                case 'ok':
                case 'close':
                    if ($cc->intent === null || ($r->probability($cc->intent) < EnhancedPolicy::CLAUSE_MIN_P && $cc->score < IntentClassifier::MIN_SCORE + 2.0)) {
                        return null;
                    }

                    if (! isset($intents[$cc->intent])) {
                        $intents[$cc->intent] = true;
                        $items[] = ['intent', $cc->intent, $ca, $clause];
                    }

                    break;
                case 'no_figure':
                    $items[] = ['unknown', EnhancedReplies::topicOf(trim((string) preg_replace('/\s+/u', ' ', TextFold::fold($clause))))];

                    break;
                case 'oos':
                    // فقرة ناقصة تعود على سابقتها («… وكيف أطلبه؟»): لا يُجزَم بأنها خارج النطاق؛ يبقى التصنيف الكلي للرسالة.
                    if ($i > 0 && $this->anaphoric($clause, $ca)) {
                        return null;
                    }

                    $items[] = ['oos'];

                    break;
                default:
                    return null;
            }
        }

        $answerable = count($intents);

        if ($answerable === 0 || ($answerable < 2 && count($items) === $answerable)) {
            return null;
        }

        $labels = ['أولًا', 'ثانيًا', 'ثالثًا'];
        $body = [];
        $sources = [];
        $calls = 0;
        $area = null;
        $first = (string) array_key_first($intents);
        $seen = ['oos' => false];

        foreach (array_slice($items, 0, EnhancedPolicy::MAX_COMPOUND) as $item) {
            $part = null;

            if ($item[0] === 'unknown') {
                $part = EnhancedReplies::undocumented($item[1]);
            } elseif ($item[0] === 'oos') {
                $part = $seen['oos'] ? null : EnhancedReplies::OUT_OF_SCOPE_PART;
                $seen['oos'] = true;
            } else {
                [, $intent, $ca, $clause] = $item;
                $def = IntentCatalog::get($intent);

                if ($def === null) {
                    return null;
                }

                if (($def['k'] ?? '') === 'know') {
                    $block = $this->block($def, $ca, $member);

                    if ($block === null) {
                        return null;
                    }

                    $part = $block['text'];
                    $sources = array_merge($sources, $block['sources']);
                    $area ??= $block['area'] !== '' ? $block['area'] : null;
                } elseif (($def['k'] ?? '') === 'my' && ! $context->hasMember()) {
                    $part = CannedReplies::NEEDS_VERIFIED;
                } else {
                    $live = $this->live->resolve($intent, $def, $ca, $context, $clause);

                    if ($live === null) {
                        return null;
                    }

                    $part = $live->text;
                    $sources = array_merge($sources, $live->sources);
                    $calls += $live->toolCalls;
                    $area ??= $live->area;
                }
            }

            if ($part !== null) {
                $body[] = $labels[count($body)].': '.$part;
            }
        }

        self::$last['intent'] = $first;
        self::$last['status'] = 'ok';
        self::$last['kind'] = IntentCatalog::get($first)['k'] ?? null;
        self::$last['compound'] = array_keys($intents);

        return $this->finalize(new AssistantAnswer(implode("\n\n", $body), $this->uniqueSources($sources), 'answered', self::REASON, $area, true, false, $calls));
    }

    private function outOfScope(): AssistantAnswer
    {
        return new AssistantAnswer(EnhancedReplies::OUT_OF_SCOPE, [], 'out_of_scope', 'out_of_scope');
    }

    private function undocumented(string $text): AssistantAnswer
    {
        $topic = EnhancedReplies::topicOf(trim((string) preg_replace('/\s+/u', ' ', TextFold::fold($text))));
        self::$last['status'] = 'no_figure';

        return new AssistantAnswer(EnhancedReplies::undocumented($topic), $this->helpSources(), 'abstained', 'missing_knowledge');
    }

    private function clarifyGeneral(Classification $c, bool $member): AssistantAnswer
    {
        self::$last['status'] = 'ambiguous';
        $options = [];

        foreach ($c->ranked as [$name]) {
            $def = IntentCatalog::get($name);

            if ($def !== null && ($def['k'] ?? '') === 'know') {
                $title = $this->titleOf((string) $def['a'], $member);

                if ($title !== null) {
                    $options[$title] = true;
                }
            }
        }

        return new AssistantAnswer(EnhancedReplies::clarify(array_slice(array_keys($options), 0, 3)), $this->helpSources(), 'answered', 'clarify', null, false);
    }

    private function knowledgeGap(Classification $c, bool $member): AssistantAnswer
    {
        self::$last['status'] = 'knowledge_gap';
        $sources = [];

        foreach (array_slice($c->ranked, 0, 2) as [$name]) {
            $def = IntentCatalog::get($name);
            $entry = $def !== null && ($def['k'] ?? '') === 'know' ? $this->firstEntry((string) $def['a'], $member) : null;
            $clean = $entry === null ? null : AiLinkPolicy::source((string) ($entry['label'] ?: $entry['title']), $entry['url'] ?? null);

            if ($clean !== null) {
                $sources[$clean['url']] = $clean;
            }
        }

        return new AssistantAnswer(EnhancedReplies::KNOWLEDGE_GAP, $sources === [] ? $this->helpSources() : array_values($sources), 'abstained', 'missing_knowledge');
    }

    /** @return array<string, mixed>|null */
    private function firstEntry(string $slug, bool $member): ?array
    {
        foreach ($this->index->entries($member) as $entry) {
            if ($entry['slug'] === $slug) {
                return $entry;
            }
        }

        return null;
    }

    private function titleOf(string $slug, bool $member): ?string
    {
        $entry = $this->firstEntry($slug, $member);

        return $entry === null ? null : trim((string) $entry['title']);
    }

    /** هل تُفضَّل نتيجة المحاولة الثانية (بعد التصحيح الأعمّ)؟ فقط عند دليل أقوى بوضوح؛ وإلا تبقى نتيجة الأولى. */
    private function preferRetry(Classification $first, Classification $second): bool
    {
        if (! in_array($second->status, ['ok', 'close'], true) || $second->intent === null) {
            return false;
        }

        if ($first->status === 'none' || $first->intent === null) {
            return $second->status === 'ok' || $second->score >= IntentClassifier::MIN_SCORE + 2.0;
        }

        if ($first->intent === $second->intent) {
            return true;
        }

        return $second->status === 'ok' && $second->score >= $first->score + 3.0;
    }

    private function byKind(AssistantTurn $turn, Classification $c, Analysis $a, bool $member, string $text): ?AssistantAnswer
    {
        $def = IntentCatalog::get((string) $c->intent);

        if ($def === null) {
            return null;
        }

        if (($def['k'] ?? '') === 'know') {
            return $this->knowledge($def, $c, $a, $member);
        }

        $context = ToolContext::forUser($turn->user, $member);

        if (($def['k'] ?? '') === 'my' && ! $context->hasMember()) {
            self::$last['status'] = 'needs_member';

            return new AssistantAnswer(CannedReplies::NEEDS_VERIFIED, $this->helpSources(), 'answered', 'needs_verified', null, false);
        }

        $answer = $this->live->resolve((string) $c->intent, $def, $a, $context, $text);

        return $answer === null ? null : $this->finalize($answer);
    }

    /** @param array<string, mixed> $def */
    private function knowledge(array $def, Classification $c, Analysis $a, bool $member): ?AssistantAnswer
    {
        $blocks = [$this->block($def, $a, $member)];

        if ($c->status === 'close' && $c->second !== null) {
            $second = IntentCatalog::get($c->second);

            if ($second !== null && ($second['k'] ?? '') === 'know') {
                $blocks[] = $this->block($second, $a, $member);
            }
        }

        $blocks = array_values(array_filter($blocks));

        if ($blocks === []) {
            self::$last['status'] = 'unpublished';

            return null;
        }

        $text = $blocks[0]['text'];
        $sources = $blocks[0]['sources'];

        if (isset($blocks[1]) && $blocks[1]['text'] !== $text) {
            $text .= "\n\nوقد يهمّك أيضًا:\n".$blocks[1]['text'];
            $sources = array_merge($sources, $blocks[1]['sources']);
        }

        $safe = $this->output->safe($text);

        if ($safe === null) {
            self::$last['status'] = 'output_guard';

            return $this->abstain();
        }

        $answer = new AssistantAnswer($safe, $this->uniqueSources($sources), 'answered', self::REASON, $blocks[0]['area'] !== '' ? $blocks[0]['area'] : null, true);

        return $answer;
    }

    /**
     * @param  array<string, mixed>  $def
     * @return array{text: string, sources: list<array{label: string, url: string}>, area: string, section: string}|null
     */
    private function block(array $def, Analysis $a, bool $member): ?array
    {
        $entries = array_values(array_filter($this->index->entries($member), fn (array $e) => $e['slug'] === ($def['a'] ?? '')));

        if ($entries === []) {
            return null;
        }

        return $this->composer->compose($entries, $def['s'], $a);
    }

    private function finalize(AssistantAnswer $answer): AssistantAnswer
    {
        $safe = $this->output->safe($answer->text);

        if ($safe === null) {
            self::$last['status'] = 'output_guard';

            return $this->abstain();
        }

        return new AssistantAnswer($safe, $answer->sources, $answer->outcome, $answer->reason, $answer->area, $answer->grounded, false, $answer->toolCalls);
    }

    private function abstain(): AssistantAnswer
    {
        self::$last['status'] = self::$last['status'] === 'no_figure' ? 'no_figure' : (self::$last['status'] ?? 'abstained');

        return new AssistantAnswer(CannedReplies::ABSTAIN, $this->helpSources(), 'abstained', 'missing_knowledge');
    }

    private function clarify(): AssistantAnswer
    {
        return new AssistantAnswer(self::CLARIFY_POINTS, $this->helpSources(), 'answered', 'clarify', null, false);
    }

    /** نفس تحويل E45 لقرار الحارس إلى ردّ (يطابق AssistantOrchestrator::guarded حرفيًا). */
    private function guarded(GuardVerdict $verdict): AssistantAnswer
    {
        $reason = $verdict->reason;
        $outcome = match (true) {
            $verdict->action === GuardVerdict::CANNED && $verdict->code === 'empty' => 'refused',
            $verdict->action === GuardVerdict::CANNED => 'answered',
            $reason === 'out_of_scope' && $verdict->code !== 'injection' => 'out_of_scope',
            default => 'refused',
        };

        return new AssistantAnswer(CannedReplies::forVerdict($verdict), [], $outcome, $reason ?? ($verdict->code === 'empty' ? 'empty_input' : null));
    }

    private function verdictFor(string $blocked): GuardVerdict
    {
        return match ($blocked) {
            'blocked.puzzle_secret' => new GuardVerdict(GuardVerdict::REFUSE, 'correct_answer', AiPuzzleSafetyGuard::REASON),
            'blocked.hint_bypass' => new GuardVerdict(GuardVerdict::REDIRECT, 'hint_redirect', AiPuzzleSafetyGuard::REASON),
            'blocked.injection' => new GuardVerdict(GuardVerdict::REFUSE, 'injection', 'out_of_scope'),
            'blocked.action' => new GuardVerdict(GuardVerdict::REFUSE, 'action', 'action_refusal'),
            default => new GuardVerdict(GuardVerdict::REFUSE, 'privacy', 'privacy_refusal'),     // other_user + secrets
        };
    }

    /** @return list<array{label: string, url: string}> */
    private function helpSources(): array
    {
        $help = Presenter::link('دليل المنصة', Presenter::path('help.index'));

        return $help === null ? [] : [$help];
    }

    /**
     * @param  list<array{label: string, url: string}>  $sources
     * @return list<array{label: string, url: string}>
     */
    private function uniqueSources(array $sources): array
    {
        $out = [];

        foreach ($sources as $source) {
            $clean = AiLinkPolicy::source((string) $source['label'], $source['url']);

            if ($clean !== null) {
                $out[$clean['url']] ??= $clean;
            }
        }

        return array_slice(array_values($out), 0, 4);
    }

    private function remember(Classification $c, Analysis $a): void
    {
        self::$last = [
            'intent' => $c->intent,
            'status' => $c->status,
            'score' => $c->score,
            'margin' => $c->margin,
            'second' => $c->second,
            'kind' => $c->kind(),
            'corrections' => count($a->corrections),
        ];
    }
}
