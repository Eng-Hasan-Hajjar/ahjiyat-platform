<?php

namespace App\Services\AiAssistant;

use App\Services\AiAssistant\Conversation\AiBudget;
use App\Services\AiAssistant\Conversation\AiMetrics;
use App\Services\AiAssistant\Conversation\AssistantAnswer;
use App\Services\AiAssistant\Conversation\AssistantTurn;
use App\Services\AiAssistant\Dto\AiRequest;
use App\Services\AiAssistant\Dto\AiResponse;
use App\Services\AiAssistant\Dto\AiToolResult;
use App\Services\AiAssistant\Dto\AiUsage;
use App\Services\AiAssistant\Knowledge\AiKnowledgeRetriever;
use App\Services\AiAssistant\Knowledge\KnowledgeNormalizer;
use App\Services\AiAssistant\Providers\AiProvider;
use App\Services\AiAssistant\Providers\AiProviderException;
use App\Services\AiAssistant\Providers\AiProviderRegistry;
use App\Services\AiAssistant\Safety\AiLinkPolicy;
use App\Services\AiAssistant\Safety\AiOutputGuard;
use App\Services\AiAssistant\Safety\AiPuzzleSafetyGuard;
use App\Services\AiAssistant\Safety\AiScopeGuard;
use App\Services\AiAssistant\Safety\CannedReplies;
use App\Services\AiAssistant\Safety\GuardVerdict;
use App\Services\AiAssistant\Safety\StreamGate;
use App\Services\AiAssistant\Safety\SystemPrompt;
use App\Services\AiAssistant\Tools\Presenter;
use App\Services\AiAssistant\Tools\ToolContext;
use App\Services\AiAssistant\Tools\ToolRegistry;
use Illuminate\Support\Str;
use Throwable;

/**
 * E45 - منسّق «تطوّر AI»: خط الإجابة (المادة 39) كاملًا في مكان واحد، حتميًا حيث يمكن.
 *
 *   تنظيف المدخل ← حارس أسرار الأحجيات ← حارس النطاق/الخصوصية/التنفيذ ← استرجاع المعرفة (محلي)
 *   ← [مزوّد معطّل؟ ردّ محلي] ← [ميزانية يومية؟ مشغول] ← [لا دليل ولا مفردة منصة؟ امتناع حتمي]
 *   ← استدعاء المزوّد بأدوات القراءة المسموحة (حلقة محدودة ومهلة إجمالية) ← مرشّح مخرجات ← فرض «لا دليل = امتناع» ← مصادر داخلية ← مقاييس بلا محتوى.
 *
 * ضمانات: لا 500 من فشل مزوّد (ردّ احتياطي محلي)، لا إجابة بلا دليل (تُستبدل بجملة الامتناع الحرفية)، لا نص خام من النموذج يصل المستخدم قبل التنقية،
 * ولا كتابة اقتصادية أو ترتيبية في أي مسار (الأدوات للقراءة فقط).
 */
final class AssistantOrchestrator
{
    /** مهلة إجمالية لكل الجولات (ثوانٍ) كي لا تتراكم مهل المزوّد. */
    private const TOTAL_SECONDS = 45;

    public const BLOCKED_TEXT = 'لا أستطيع عرض هذه الإجابة. جرّب صياغة أخرى أو استخدم دليل المنصة.';

    public function __construct(
        private readonly AiKnowledgeRetriever $retriever,
        private readonly ToolRegistry $tools,
        private readonly AiProviderRegistry $providers,
        private readonly AiPuzzleSafetyGuard $puzzleGuard,
        private readonly AiScopeGuard $scopeGuard,
        private readonly AiOutputGuard $output,
        private readonly AiBudget $budget,
        private readonly AiMetrics $metrics,
    ) {}

    /**
     * @param  (callable(string, array<string, mixed>): void)|null  $emit  أحداث البث: delta{text} | reset | replace{text}
     */
    public function answer(AssistantTurn $turn, ?callable $emit = null): AssistantAnswer
    {
        $started = hrtime(true);

        try {
            $answer = $this->run($turn, $emit);
        } catch (Throwable $e) {
            report($e);
            $answer = new AssistantAnswer(CannedReplies::FALLBACK_EMPTY, $this->helpSources(), 'error', 'internal_error', null, false, false, 0, new AiUsage, 0, null, null, 'internal');
            $this->replace($emit, $answer->text);
        }

        $answer = $answer->withLatency((int) ((hrtime(true) - $started) / 1_000_000));
        $this->metrics->record($answer, $turn->audience);

        return $answer;
    }

    private function run(AssistantTurn $turn, ?callable $emit): AssistantAnswer
    {
        $member = $turn->audience === TatawwurSettings::AUDIENCE_MEMBER;
        $text = mb_substr(trim(KnowledgeNormalizer::normalize($turn->message)), 0, TatawwurSettings::maxInputChars());
        $previous = $turn->previousUserMessage();

        foreach ([$this->puzzleGuard->inspect($text, $previous), $this->scopeGuard->inspect($text)] as $verdict) {
            if (! $verdict->isAllowed()) {
                return $this->guarded($verdict);
            }
        }

        $chunks = $this->retriever->retrieve($text, $member, null, $previous);
        $provider = $this->providers->resolve();

        if ($provider === null || ! TatawwurSettings::providerReady()) {
            return $this->local($chunks);
        }

        if ($this->budget->exceeded()) {
            return new AssistantAnswer(CannedReplies::BUSY, $this->helpSources($chunks), 'limited', 'budget', $this->areaOf($chunks, []), false, false, 0, new AiUsage, 0, $provider->key(), $this->model($provider));
        }

        if ($chunks === [] && ! $this->scopeGuard->mentionsPlatform($text.' '.(string) $previous)) {
            return new AssistantAnswer(CannedReplies::ABSTAIN, $this->helpSources(), 'abstained', 'missing_knowledge', null, false, false, 0, new AiUsage, 0, $provider->key(), $this->model($provider));
        }

        return $this->withProvider($turn, $text, $member, $chunks, $provider, $emit);
    }

    /**
     * @param  list<array<string, mixed>>  $chunks
     * @param  (callable(string, array<string, mixed>): void)|null  $emit
     */
    private function withProvider(AssistantTurn $turn, string $text, bool $member, array $chunks, AiProvider $provider, ?callable $emit): AssistantAnswer
    {
        $context = ToolContext::forUser($turn->user, $member);
        $maxCalls = TatawwurSettings::maxToolCalls();
        $definitions = $maxCalls > 0 ? $this->tools->definitionsFor($context) : [];
        $model = $this->model($provider);

        $instructions = SystemPrompt::build(
            ['audience' => $member ? 'member' : 'guest', 'tools' => $definitions !== [], 'date' => now()->toDateString()],
            array_map(fn (array $c, int $i) => ['ref' => 'K'.($i + 1), 'title' => (string) $c['title'], 'section' => $c['section'] ?? null, 'body' => (string) $c['text']], $chunks, array_keys($chunks)),
        );

        $input = [];

        foreach ($turn->history as $item) {
            $input[] = ['type' => 'message', 'role' => $item['role'] === 'assistant' ? 'assistant' : 'user', 'text' => mb_substr((string) $item['text'], 0, 1500)];
        }

        $input[] = ['type' => 'message', 'role' => 'user', 'text' => $text];

        $request = new AiRequest((string) Str::ulid(), $instructions, $input, $definitions, $model, TatawwurSettings::maxOutputTokens());
        $streaming = $turn->stream && $emit !== null && TatawwurSettings::streamingEnabled() && $provider->supportsStreaming();

        $usage = new AiUsage;
        $executed = 0;
        $toolSources = [];
        $toolAreas = [];
        $grounded = $chunks !== [];
        $toolGrounded = false;
        $gate = null;
        $final = null;
        $deadline = microtime(true) + self::TOTAL_SECONDS;

        try {
            for ($round = 0; $round <= $maxCalls + 1; $round++) {
                if (microtime(true) > $deadline) {
                    throw new AiProviderException(AiProviderException::TIMEOUT);
                }

                $allowTools = $definitions !== [] && $executed < $maxCalls;
                $current = $request->withInput($input, $allowTools);
                $gate = $streaming ? new StreamGate($this->output) : null;
                $forwarded = false;
                $liveGrounded = $grounded;   // ثابت طوال الجولة: لا يخرج للعميل نص غير مؤسَّس على دليل قبل التحقق.

                $response = $gate !== null
                    ? $provider->stream($current, function (string $delta) use ($gate, $emit, $liveGrounded, &$forwarded): void {
                        $out = $gate->push($delta);

                        if ($out !== '' && $liveGrounded) {
                            $forwarded = true;
                            $emit('delta', ['text' => $out]);
                        }
                    })
                    : $provider->generate($current);

                $usage = $usage->plus($response->usage);

                if (! $response->wantsTools() || ! $allowTools) {
                    $final = $response;

                    break;
                }

                if ($forwarded) {
                    $emit('reset', []);
                }

                $input[] = ['type' => 'provider_items', 'items' => $response->providerItems];

                foreach ($response->toolCalls as $call) {
                    if ($executed >= $maxCalls) {
                        $result = AiToolResult::fail($call->name, 'limit', 'تم بلوغ حد استدعاءات الأدوات.');
                    } else {
                        $result = $this->tools->execute($call, $context);
                        $executed++;
                    }

                    if ($result->ok) {
                        $grounded = true;
                        $toolGrounded = true;
                        array_push($toolSources, ...$result->sources);
                        $area = $this->tools->get($call->name)?->area();

                        if ($area !== null) {
                            $toolAreas[] = $area;
                        }
                    }

                    $input[] = ['type' => 'tool_result', 'call_id' => $call->id, 'name' => $call->name, 'output' => $result->forModel()];
                }
            }
        } catch (AiProviderException $e) {
            return $this->providerFailure($e->category, $chunks, $usage, $executed, $provider, $model, $streaming, $emit);
        }

        if ($final === null) {
            return $this->providerFailure(AiProviderException::INVALID_RESPONSE, $chunks, $usage, $executed, $provider, $model, $streaming, $emit);
        }

        $unverifiedPersonal = ! $toolGrounded && $this->scopeGuard->isPersonalQuestion($text);

        return $this->finish($final, $gate, $chunks, $toolSources, $toolAreas, $grounded, $usage, $executed, $provider, $model, $streaming, $emit, $unverifiedPersonal ? ['member' => $member, 'question' => $text] : null);
    }

    /**
     * @param  list<array<string, mixed>>  $chunks
     * @param  list<array{label: string, url: string}>  $toolSources
     * @param  list<string>  $toolAreas
     * @param  (callable(string, array<string, mixed>): void)|null  $emit
     * @param  array{member: bool, question: string}|null  $personal  سؤال شخصي بلا نتيجة أداة «حسابي»: أي رقم في الجواب لا يسنده السؤال ولا المقاطع المسترجعة يُمنع.
     */
    private function finish(AiResponse $final, ?StreamGate $gate, array $chunks, array $toolSources, array $toolAreas, bool $grounded, AiUsage $usage, int $executed, AiProvider $provider, string $model, bool $streaming, ?callable $emit, ?array $personal = null): AssistantAnswer
    {
        if (trim($final->text) === '') {
            return $this->providerFailure(AiProviderException::INVALID_RESPONSE, $chunks, $usage, $executed, $provider, $model, $streaming, $emit);
        }

        $clean = $this->output->safe($final->text);
        $area = $this->areaOf($chunks, $toolAreas);
        $base = fn (string $text, array $sources, string $outcome, ?string $reason, bool $isGrounded) => new AssistantAnswer(
            $text, $sources, $outcome, $reason, $area, $isGrounded, $streaming, $executed, $usage, 0, $provider->key(), $model,
        );

        if ($clean === null || ($gate !== null && $gate->blocked())) {
            $this->replace($emit, self::BLOCKED_TEXT);

            return $base(self::BLOCKED_TEXT, $this->helpSources($chunks), 'refused', 'output_blocked', false);
        }

        if (! $grounded) {
            $this->replace($emit, CannedReplies::ABSTAIN);

            return $base(CannedReplies::ABSTAIN, $this->helpSources(), 'abstained', 'missing_knowledge', false);
        }

        if ($personal !== null && $this->hasUnsupportedFigure($clean, $personal['question'], $chunks)) {
            $text = $personal['member'] ? CannedReplies::ABSTAIN : CannedReplies::NEEDS_VERIFIED;
            $this->replace($emit, $text);

            return $base($text, $this->helpSources($chunks), 'abstained', $personal['member'] ? 'missing_knowledge' : 'needs_account', false);
        }

        $abstained = str_contains($clean, CannedReplies::ABSTAIN);
        $sources = $abstained ? $this->helpSources() : $this->sourcesFor($chunks, $toolSources);

        if ($gate !== null) {
            $rest = $gate->finish();
            $streamedText = $gate->released();

            if ($gate->blocked() || $streamedText !== $clean) {
                $this->replace($emit, $clean);
            } elseif ($rest !== '') {
                $emit('delta', ['text' => $rest]);
            }
        }

        return $base($clean, $sources, $abstained ? 'abstained' : 'answered', $abstained ? 'missing_knowledge' : null, ! $abstained);
    }

    /**
     * منع «رقم مخترَع» في جواب سؤال شخصي لم تُجلب بياناته بأداة: كل رقم في الجواب يجب أن يرد في السؤال أو في المقاطع المسترجعة، وإلا فهو غير مسنود.
     * (أرقام فقط؛ أسماء الأعداد بالحروف خارج هذا الفحص وتبقى ضمن مخاطر الهلوسة المتبقية الموثقة.)
     *
     * @param  list<array<string, mixed>>  $chunks
     */
    private function hasUnsupportedFigure(string $answer, string $question, array $chunks): bool
    {
        $numbers = fn (string $t): array => preg_match_all('/\d+/u', \App\Services\Search\TextFold::fold($t), $m) > 0 ? array_unique($m[0]) : [];
        $found = $numbers($answer);

        if ($found === []) {
            return false;
        }

        $allowed = array_merge($numbers($question), ...array_map(fn (array $c) => $numbers((string) ($c['text'] ?? '')), $chunks));

        return array_diff($found, $allowed) !== [];
    }

    /** @param list<array<string, mixed>> $chunks */
    private function local(array $chunks): AssistantAnswer
    {
        if ($chunks === []) {
            return new AssistantAnswer(CannedReplies::ABSTAIN, $this->helpSources(), 'abstained', 'missing_knowledge');
        }

        $lines = [CannedReplies::DISABLED_PROVIDER_INTRO, ''];

        foreach ($this->uniqueByArticle($chunks, 3) as $chunk) {
            $lines[] = '• '.$chunk['title'].($chunk['section'] ? ' — '.$chunk['section'] : '');
        }

        $top = $chunks[0];
        $lines[] = '';
        $lines[] = Presenter::text((string) $top['text'], 360);

        return new AssistantAnswer(implode("\n", $lines), $this->sourcesFor($chunks, []), 'fallback', 'provider_disabled', $this->areaOf($chunks, []), true);
    }

    /**
     * @param  list<array<string, mixed>>  $chunks
     * @param  (callable(string, array<string, mixed>): void)|null  $emit
     */
    private function providerFailure(string $category, array $chunks, AiUsage $usage, int $executed, AiProvider $provider, string $model, bool $streaming, ?callable $emit): AssistantAnswer
    {
        if ($category === AiProviderException::RATE_LIMITED) {
            $answer = new AssistantAnswer(CannedReplies::BUSY, $this->helpSources($chunks), 'limited', 'rate_limited', $this->areaOf($chunks, []), false, $streaming, $executed, $usage, 0, $provider->key(), $model, $category);
        } else {
            $lines = [];

            if ($chunks === []) {
                $lines[] = CannedReplies::FALLBACK_EMPTY;
            } else {
                $lines[] = CannedReplies::FALLBACK_INTRO;

                foreach ($this->uniqueByArticle($chunks, 3) as $chunk) {
                    $lines[] = '• '.$chunk['title'].($chunk['section'] ? ' — '.$chunk['section'] : '');
                }
            }

            $answer = new AssistantAnswer(implode("\n", $lines), $this->helpSources($chunks), 'fallback', 'provider_failure', $this->areaOf($chunks, []), false, $streaming, $executed, $usage, 0, $provider->key(), $model, $category);
        }

        $this->replace($emit, $answer->text);

        return $answer;
    }

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

    /** @param (callable(string, array<string, mixed>): void)|null $emit */
    private function replace(?callable $emit, string $text): void
    {
        if ($emit !== null) {
            $emit('replace', ['text' => $text]);
        }
    }

    private function model(AiProvider $provider): string
    {
        return TatawwurSettings::model() ?? ($provider->key() === 'fake' ? 'fake-model' : 'unconfigured');
    }

    /**
     * @param  list<array<string, mixed>>  $chunks
     * @param  list<string>  $toolAreas
     */
    private function areaOf(array $chunks, array $toolAreas): ?string
    {
        return ($chunks[0]['area'] ?? null) ?: ($toolAreas[0] ?? null);
    }

    /**
     * @param  list<array<string, mixed>>  $chunks
     * @return list<array<string, mixed>>
     */
    private function uniqueByArticle(array $chunks, int $limit): array
    {
        $seen = [];
        $out = [];

        foreach ($chunks as $chunk) {
            if (isset($seen[$chunk['article_id']])) {
                continue;
            }

            $seen[$chunk['article_id']] = true;
            $out[] = $chunk;

            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $chunks
     * @param  list<array{label: string, url: string}>  $toolSources
     * @return list<array{label: string, url: string}>
     */
    private function sourcesFor(array $chunks, array $toolSources): array
    {
        $out = [];

        foreach ($this->uniqueByArticle($chunks, 2) as $chunk) {
            $source = AiLinkPolicy::source((string) ($chunk['label'] ?: $chunk['title']), $chunk['url'] ?? null);

            if ($source !== null) {
                $out[$source['url']] = $source;
            }
        }

        foreach ($toolSources as $source) {
            $clean = AiLinkPolicy::source((string) $source['label'], $source['url']);

            if ($clean !== null) {
                $out[$clean['url']] ??= $clean;
            }
        }

        return array_slice(array_values($out), 0, 4);
    }

    /**
     * @param  list<array<string, mixed>>  $chunks
     * @return list<array{label: string, url: string}>
     */
    private function helpSources(array $chunks = []): array
    {
        $sources = $this->sourcesFor($chunks, []);
        $help = Presenter::link('دليل المنصة', Presenter::path('help.index'));

        if ($help !== null && ! in_array($help['url'], array_column($sources, 'url'), true)) {
            $sources[] = $help;
        }

        return array_slice($sources, 0, 4);
    }
}
