<?php

namespace App\Services\AiAssistant\Providers;

use App\Services\AiAssistant\Dto\AiRequest;
use App\Services\AiAssistant\Dto\AiResponse;
use App\Services\AiAssistant\Dto\AiToolCall;
use App\Services\AiAssistant\Dto\AiUsage;
use App\Services\AiAssistant\TatawwurSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * E45 - محوّل OpenAI **Responses API** (لا Assistants API). **هذا الملف الوحيد في التطبيق الذي يُجري اتصالًا صادرًا بمزوّد الذكاء** (موقع HTTP صادر مراجَع ومحدود النطاق في حارس E26 W7).
 *
 * ضمانات الموقع الصادر:
 *  - العنوان من config/tatawwur_ai.php فقط (ثابت بالكود) ويُتحقَّق من مضيفه ضد قائمة بيضاء HTTPS قبل أي اتصال؛ لا يُقبل أي عنوان من طلب أو مدخل مستخدم، ولا يجلب أي رابط عشوائي.
 *  - الوجهة الوحيدة: POST {base}/responses. مفتاح المزوّد من البيئة حصرًا ويُرسَل للمضيف المهيّأ فقط (ترويسة Authorization)، ولا يُسجَّل ولا يُخزَّن ولا يظهر في أي استثناء.
 *  - store=false دائمًا: لا حالة محادثة عند المزوّد؛ أحجيات هي سلطة الحالة (تُعاد الأدوار السابقة كل طلب).
 *  - مهلة اتصال وطلب صارمتان؛ إعادة محاولة واحدة كحد أقصى لأخطاء الاتصال فقط (لا إعادة لتوليد مكلف بعد استلام ردّ)، والبث بلا إعادة.
 *  - الأخطاء تُحوَّل لفئات مغلقة (AiProviderException) بلا نص من المزوّد ولا ترويسات.
 * لا اسم نموذج مكتوب هنا: AI_MODEL من البيئة فقط.
 */
final class OpenAiResponsesProvider implements AiProvider
{
    public function key(): string
    {
        return 'openai';
    }

    public function isConfigured(): bool
    {
        return $this->apiKey() !== '' && TatawwurSettings::model() !== null && $this->baseUrl() !== null;
    }

    public function supportsStreaming(): bool
    {
        return true;
    }

    public function generate(AiRequest $request): AiResponse
    {
        $response = $this->send($request, false);
        $json = $response->json();

        if (! is_array($json)) {
            throw new AiProviderException(AiProviderException::INVALID_RESPONSE);
        }

        return $this->parse($json);
    }

    public function stream(AiRequest $request, callable $onText): AiResponse
    {
        $response = $this->send($request, true);
        $body = $response->toPsrResponse()->getBody();
        $deadline = microtime(true) + TatawwurSettings::requestTimeout();
        $buffer = '';
        $final = null;
        $idle = 0;

        while (! $body->eof()) {
            if (microtime(true) > $deadline) {
                throw new AiProviderException(AiProviderException::TIMEOUT);
            }

            $chunk = $body->read(2048);

            if ($chunk === '') {
                if (++$idle > 2000) {
                    throw new AiProviderException(AiProviderException::TIMEOUT);
                }

                continue;
            }

            $idle = 0;
            $buffer .= str_replace("\r\n", "\n", $chunk);

            while (($pos = strpos($buffer, "\n\n")) !== false) {
                $block = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 2);
                $final = $this->handleEvent($block, $onText) ?? $final;
            }
        }

        if (trim($buffer) !== '') {
            $final = $this->handleEvent($buffer, $onText) ?? $final;
        }

        if ($final === null) {
            throw new AiProviderException(AiProviderException::INVALID_RESPONSE);
        }

        return $this->parse($final);
    }

    /** يعالج كتلة حدث SSE واحدة. يعيد كائن الردّ النهائي عند response.completed/incomplete، وإلا null. @param callable(string): void $onText */
    private function handleEvent(string $block, callable $onText): ?array
    {
        $data = '';

        foreach (explode("\n", $block) as $line) {
            if (str_starts_with($line, 'data:')) {
                $data .= ltrim(substr($line, 5));
            }
        }

        if ($data === '' || $data === '[DONE]') {
            return null;
        }

        $event = json_decode($data, true);

        if (! is_array($event)) {
            return null;
        }

        switch ($event['type'] ?? '') {
            case 'response.output_text.delta':
                $delta = $event['delta'] ?? '';

                if (is_string($delta) && $delta !== '') {
                    $onText($delta);
                }

                return null;
            case 'response.completed':
            case 'response.incomplete':
                return is_array($event['response'] ?? null) ? $event['response'] : null;
            case 'response.failed':
            case 'error':
                throw new AiProviderException(AiProviderException::SERVER);
            default:
                return null;
        }
    }

    private function send(AiRequest $request, bool $stream): Response
    {
        $base = $this->baseUrl();

        if ($this->apiKey() === '' || TatawwurSettings::model() === null || $base === null) {
            throw new AiProviderException(AiProviderException::NOT_CONFIGURED);
        }

        $pending = Http::withToken($this->apiKey())
            ->acceptJson()
            ->asJson()
            ->withHeaders(['X-Client-Request-Id' => $request->requestId])
            ->connectTimeout(TatawwurSettings::connectTimeout())
            ->timeout(TatawwurSettings::requestTimeout());

        if ($stream) {
            $pending = $pending->withOptions(['stream' => true]);
        } elseif (TatawwurSettings::retries() > 0) {
            $pending = $pending->retry(TatawwurSettings::retries() + 1, 400, fn ($e) => $e instanceof ConnectionException, throw: false);
        }

        try {
            $response = $pending->post($base.'/responses', $this->payload($request, $stream));
        } catch (ConnectionException $e) {
            throw new AiProviderException(str_contains(strtolower($e->getMessage()), 'timed out') || str_contains($e->getMessage(), 'cURL error 28') ? AiProviderException::TIMEOUT : AiProviderException::NETWORK);
        } catch (AiProviderException $e) {
            throw $e;
        } catch (\Throwable) {
            throw new AiProviderException(AiProviderException::NETWORK);
        }

        if ($response->failed()) {
            throw new AiProviderException(match (true) {
                $response->status() === 401 || $response->status() === 403 => AiProviderException::AUTH,
                $response->status() === 429 => AiProviderException::RATE_LIMITED,
                $response->status() >= 500 => AiProviderException::SERVER,
                default => AiProviderException::INVALID_RESPONSE,
            });
        }

        return $response;
    }

    /** @return array<string, mixed> */
    private function payload(AiRequest $request, bool $stream): array
    {
        $input = [];

        foreach ($request->input as $item) {
            switch ($item['type'] ?? '') {
                case 'message':
                    $input[] = ['role' => (string) $item['role'], 'content' => (string) $item['text']];
                    break;
                case 'provider_items':
                    foreach ((array) $item['items'] as $raw) {
                        $input[] = $raw;
                    }
                    break;
                case 'tool_result':
                    $input[] = ['type' => 'function_call_output', 'call_id' => (string) $item['call_id'], 'output' => (string) $item['output']];
                    break;
            }
        }

        $payload = [
            'model' => $request->model,
            'instructions' => $request->instructions,
            'input' => $input,
            'max_output_tokens' => $request->maxOutputTokens,
            'store' => false,
            'stream' => $stream,
        ];

        if ($request->tools !== []) {
            $payload['tools'] = array_map(static function (array $tool): array {
                $parameters = $tool['parameters'];
                $parameters['type'] = 'object';
                $parameters['properties'] = (object) ($parameters['properties'] ?? []);

                return ['type' => 'function', 'name' => $tool['name'], 'description' => $tool['description'], 'parameters' => $parameters, 'strict' => false];
            }, $request->tools);
            $payload['tool_choice'] = 'auto';
        }

        return $payload;
    }

    /** @param array<string, mixed> $json */
    private function parse(array $json): AiResponse
    {
        if (($json['status'] ?? 'completed') === 'failed' || ! empty($json['error'])) {
            throw new AiProviderException(AiProviderException::SERVER);
        }

        $text = '';
        $calls = [];
        $items = [];

        foreach ((array) ($json['output'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }

            if (($item['type'] ?? '') === 'message') {
                foreach ((array) ($item['content'] ?? []) as $part) {
                    if (is_array($part) && ($part['type'] ?? '') === 'output_text') {
                        $text .= (string) ($part['text'] ?? '');
                    }
                }
            } elseif (($item['type'] ?? '') === 'function_call') {
                $raw = (string) ($item['arguments'] ?? '{}');
                $args = json_decode($raw, true);
                $callId = (string) ($item['call_id'] ?? '');
                $name = (string) ($item['name'] ?? '');

                $calls[] = new AiToolCall($callId, $name, is_array($args) ? $args : []);
                // عنصر دعوة أدوات أدنى بلا معرّف مخزَّن (store=false): يُعاد كما هو في الدور التالي.
                $items[] = ['type' => 'function_call', 'call_id' => $callId, 'name' => $name, 'arguments' => $raw];
            }
        }

        if (trim($text) === '' && $calls === []) {
            throw new AiProviderException(AiProviderException::INVALID_RESPONSE);
        }

        $usage = new AiUsage(max(0, (int) ($json['usage']['input_tokens'] ?? 0)), max(0, (int) ($json['usage']['output_tokens'] ?? 0)));

        return new AiResponse($text, $calls, $usage, $items, ($json['status'] ?? 'completed') === 'incomplete' ? 'length' : ($calls !== [] ? 'tool_calls' : 'stop'), (string) ($json['model'] ?? ''));
    }

    private function apiKey(): string
    {
        return trim((string) config('tatawwur_ai.providers.openai.api_key', ''));
    }

    /** عنوان الأساس بعد التحقق من مخطط https ومضيف ضمن القائمة البيضاء، أو null. بلا شرطة أخيرة. */
    private function baseUrl(): ?string
    {
        $url = rtrim((string) config('tatawwur_ai.providers.openai.base_url', ''), '/');
        $parts = parse_url($url);

        if (! is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        $allowed = array_map('strtolower', (array) config('tatawwur_ai.providers.openai.allowed_hosts', []));

        if ($host === '' || ! in_array($host, $allowed, true) || isset($parts['port'])) {
            return null;
        }

        return $url;
    }
}
