<?php

namespace App\Services\AiAssistant\Local;

use App\Services\AiAssistant\Conversation\AssistantAnswer;
use App\Services\AiAssistant\Conversation\AssistantTurn;
use App\Services\AiAssistant\Knowledge\KnowledgeIndex;
use App\Services\AiAssistant\Local\Language\Analysis;
use App\Services\AiAssistant\Local\Language\Analyzer;
use App\Services\AiAssistant\Safety\AiLinkPolicy;
use App\Services\AiAssistant\Safety\AiOutputGuard;
use App\Services\AiAssistant\Safety\AiPuzzleSafetyGuard;
use App\Services\AiAssistant\Safety\AiScopeGuard;
use App\Services\AiAssistant\Safety\CannedReplies;
use App\Services\AiAssistant\Safety\GuardVerdict;
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

        $previous = $turn->previousUserMessage();
        $a = $this->analyzer->analyze($text, $previous);
        $c = $this->classifier->classify($a, $this->prior->scores($a, $member));

        // TI-Q1: محاولة ثانية بمصحّح إملائي أعمّ (مسافة تحريرية 1) فقط عند وجود كلمات مجهولة، وتُقبل بشرط زيادة الدليل، فلا تُفسد إجابة سليمة.
        if ($a->residual !== [] && in_array($c->status, ['none', 'close', 'ok'], true)) {
            $a2 = $this->analyzer->analyze($text, $previous, true);

            if ($a2->corrections !== $a->corrections) {
                $c2 = $this->classifier->classify($a2, $this->prior->scores($a2, $member));

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
