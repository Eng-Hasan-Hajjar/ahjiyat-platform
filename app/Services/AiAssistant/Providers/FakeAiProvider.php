<?php

namespace App\Services\AiAssistant\Providers;

use App\Services\AiAssistant\Dto\AiRequest;
use App\Services\AiAssistant\Dto\AiResponse;
use App\Services\AiAssistant\Dto\AiToolCall;
use App\Services\AiAssistant\Dto\AiUsage;

/**
 * E45 - مزوّد اختباري حتمي: **بلا شبكة إطلاقًا**. يُستعمل في الاختبارات وCI والتطوير المحلي فقط (مرفوض في الإنتاج: انظر AiProviderRegistry).
 * السيناريوهات تُصفّ بالترتيب وتُستهلك واحدًا لكل استدعاء: إجابة نصية، إجابة معرفة، أداة، أدوات متعددة، فشل مزوّد، مهلة، مخرجات غير آمنة، مقاطع بث.
 * received يحتفظ بكل طلب وصله لتفحص الاختبارات ما أُرسل للنموذج فعلًا (لا حقول سرية ولا حل أحجية).
 */
final class FakeAiProvider implements AiProvider
{
    /** @var list<array<string, mixed>> */
    private array $script = [];

    /** @var list<AiRequest> */
    public array $received = [];

    public bool $configured = true;

    public bool $streaming = true;

    public function key(): string
    {
        return 'fake';
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function supportsStreaming(): bool
    {
        return $this->streaming;
    }

    /** إجابة نصية. $chunks اختيارية لمقاطع البث؛ وإلا يُقسَّم النص تلقائيًا. @param list<string>|null $chunks */
    public function answer(string $text, ?array $chunks = null, ?AiUsage $usage = null): self
    {
        $this->script[] = ['type' => 'text', 'text' => $text, 'chunks' => $chunks, 'usage' => $usage];

        return $this;
    }

    /** طلب أداة واحدة. @param array<string, mixed> $arguments */
    public function callTool(string $name, array $arguments = []): self
    {
        return $this->callTools([[$name, $arguments]]);
    }

    /** عدة أدوات في دور واحد. @param list<array{0: string, 1?: array<string, mixed>}> $calls */
    public function callTools(array $calls): self
    {
        $this->script[] = ['type' => 'tools', 'calls' => $calls];

        return $this;
    }

    /** فشل مزوّد بفئة مغلقة (timeout | network | rate_limited | auth | server | invalid_response). */
    public function fail(string $category = AiProviderException::SERVER): self
    {
        $this->script[] = ['type' => 'fail', 'category' => $category];

        return $this;
    }

    public function timeout(): self
    {
        return $this->fail(AiProviderException::TIMEOUT);
    }

    /** مخرجات «غير آمنة» عمدًا لاختبار مرشّح المخرجات (تعامل كإجابة نصية عادية من المزوّد). */
    public function unsafe(string $text): self
    {
        return $this->answer($text);
    }

    public function remaining(): int
    {
        return count($this->script);
    }

    public function generate(AiRequest $request): AiResponse
    {
        return $this->next($request, null);
    }

    public function stream(AiRequest $request, callable $onText): AiResponse
    {
        return $this->next($request, $onText);
    }

    private function next(AiRequest $request, ?callable $onText): AiResponse
    {
        $this->received[] = $request;

        if (! $this->configured) {
            throw new AiProviderException(AiProviderException::NOT_CONFIGURED);
        }

        $step = array_shift($this->script) ?? ['type' => 'text', 'text' => 'إجابة تجريبية حتمية من المزوّد الاختباري.', 'chunks' => null, 'usage' => null];

        if ($step['type'] === 'fail') {
            throw new AiProviderException((string) $step['category']);
        }

        if ($step['type'] === 'tools') {
            $calls = [];
            $items = [];

            foreach ($step['calls'] as $i => $call) {
                $id = 'fake_call_'.count($this->received).'_'.$i;
                $calls[] = new AiToolCall($id, (string) $call[0], (array) ($call[1] ?? []));
                $items[] = ['type' => 'function_call', 'call_id' => $id, 'name' => (string) $call[0], 'arguments' => (string) json_encode((object) ($call[1] ?? []))];
            }

            return new AiResponse('', $calls, new AiUsage(80, 20), $items, 'tool_calls', 'fake');
        }

        $text = (string) $step['text'];

        if ($onText !== null) {
            $chunks = $step['chunks'] ?? array_map(fn (string $c) => $c, mb_str_split($text, 12));

            foreach ($chunks as $chunk) {
                $onText((string) $chunk);
            }

            $text = implode('', $chunks);
        }

        return new AiResponse($text, [], $step['usage'] ?? new AiUsage(120, 40), [], 'stop', 'fake');
    }
}
