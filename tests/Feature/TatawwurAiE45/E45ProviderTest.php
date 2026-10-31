<?php

use App\Models\AiRequestMetric;
use App\Services\AiAssistant\Dto\AiRequest;
use App\Services\AiAssistant\Providers\AiProviderException;
use App\Services\AiAssistant\Providers\AiProviderRegistry;
use App\Services\AiAssistant\Providers\FakeAiProvider;
use App\Services\AiAssistant\Providers\OpenAiResponsesProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/_support.php';

/*
| E45 - محوّل OpenAI (Responses API) بلا شبكة: Http::fake فقط. الشكل الصادر (store=false، النموذج من الإعداد، الترويسات)، القائمة البيضاء للمضيف، تحويل الأخطاء لفئات مغلقة،
| تحليل الردود والبث، سرية المفتاح، واختيار المزوّد (fake مرفوض خارج local/testing). أي طلب غير مُصرَّح به يفشل الاختبار (preventStrayRequests).
*/

const E45P_KEY = 'sk-test-0123456789abcdefghijklmnopqrstuvwxyz';

function e45p_config(array $over = []): void
{
    config(array_merge([
        'tatawwur_ai.provider' => 'openai',
        'tatawwur_ai.model' => 'test-model-1',
        'tatawwur_ai.providers.openai.api_key' => E45P_KEY,
        'tatawwur_ai.providers.openai.base_url' => 'https://api.openai.com/v1',
        'tatawwur_ai.providers.openai.allowed_hosts' => ['api.openai.com'],
        'tatawwur_ai.limits.retries' => 0,
    ], $over));
}

function e45p_request(array $over = []): AiRequest
{
    return new AiRequest(
        $over['id'] ?? 'REQ-1',
        $over['instructions'] ?? 'تعليمات اختبارية',
        $over['input'] ?? [['type' => 'message', 'role' => 'user', 'text' => 'ما هي الجواهر؟']],
        $over['tools'] ?? [],
        $over['model'] ?? 'test-model-1',
        $over['max'] ?? 300,
    );
}

function e45p_ok(string $text = 'ردّ اختباري', array $extra = []): array
{
    return $extra + ['id' => 'resp_1', 'status' => 'completed', 'model' => 'test-model-1', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => $text]]]], 'usage' => ['input_tokens' => 11, 'output_tokens' => 7]];
}

function e45p_category(callable $fn): ?string
{
    try {
        $fn();
    } catch (AiProviderException $e) {
        return $e->category;
    }

    return null;
}

beforeEach(function () {
    Http::preventStrayRequests();
    e45p_config();
});

// ───────────────────────────── الإعداد والقائمة البيضاء ─────────────────────────────

test('E45-P01: without key, model or a valid base URL the provider is not configured and never makes a request', function () {
    foreach ([['tatawwur_ai.providers.openai.api_key' => ''], ['tatawwur_ai.providers.openai.api_key' => null], ['tatawwur_ai.model' => ''], ['tatawwur_ai.model' => 'bad model!']] as $over) {
        e45p_config($over);
        $provider = new OpenAiResponsesProvider;

        expect($provider->isConfigured())->toBeFalse()->and(e45p_category(fn () => $provider->generate(e45p_request())))->toBe(AiProviderException::NOT_CONFIGURED);
    }

    Http::assertNothingSent();
});

test('E45-P02: the base URL must be https on an allow-listed host with no credentials, port, query or fragment - otherwise nothing is sent', function () {
    $bad = [
        'http://api.openai.com/v1', 'https://evil.example/v1', 'https://api.openai.com.evil.example/v1', 'https://user:pw@api.openai.com/v1',
        'https://api.openai.com:8443/v1', 'https://api.openai.com/v1?x=1', 'https://api.openai.com/v1#f', 'ftp://api.openai.com/v1', '//api.openai.com/v1', '', 'not a url',
    ];

    foreach ($bad as $url) {
        e45p_config(['tatawwur_ai.providers.openai.base_url' => $url]);
        $provider = new OpenAiResponsesProvider;

        expect($provider->isConfigured())->toBeFalse($url)->and(e45p_category(fn () => $provider->generate(e45p_request())))->toBe(AiProviderException::NOT_CONFIGURED);
    }

    Http::assertNothingSent();
});

test('E45-P03: the outbound request shape - POST /responses, store=false, model from config, bearer key only to the configured host, bounded output', function () {
    Http::fake(['https://api.openai.com/v1/responses' => Http::response(e45p_ok())]);

    $res = (new OpenAiResponsesProvider)->generate(e45p_request(['tools' => [['name' => 'get_current_season', 'description' => 'وصف الأداة', 'parameters' => ['type' => 'object', 'properties' => new stdClass, 'required' => [], 'additionalProperties' => false]]]]));

    expect($res->text)->toBe('ردّ اختباري')->and($res->usage->inputTokens)->toBe(11)->and($res->usage->outputTokens)->toBe(7);

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request) {
        $body = $request->data();

        return $request->method() === 'POST' && $request->url() === 'https://api.openai.com/v1/responses'
            && $request->hasHeader('Authorization', 'Bearer '.E45P_KEY) && $request->hasHeader('X-Client-Request-Id', 'REQ-1')
            && $body['store'] === false && $body['stream'] === false && $body['model'] === 'test-model-1' && $body['max_output_tokens'] === 300
            && $body['instructions'] === 'تعليمات اختبارية' && $body['input'] === [['role' => 'user', 'content' => 'ما هي الجواهر؟']]
            && $body['tool_choice'] === 'auto' && $body['tools'][0]['type'] === 'function' && $body['tools'][0]['name'] === 'get_current_season'
            && ! array_key_exists('previous_response_id', $body) && ! array_key_exists('conversation', $body) && ! array_key_exists('user', $body);
    });
});

test('E45-P04: without tools the payload carries neither tools nor tool_choice, and tool results and prior calls are mapped to the Responses input items', function () {
    Http::fake(['*' => Http::response(e45p_ok())]);

    (new OpenAiResponsesProvider)->generate(e45p_request(['input' => [
        ['type' => 'message', 'role' => 'user', 'text' => 'سؤال'],
        ['type' => 'provider_items', 'items' => [['type' => 'function_call', 'call_id' => 'c1', 'name' => 'get_current_season', 'arguments' => '{}']]],
        ['type' => 'tool_result', 'call_id' => 'c1', 'name' => 'get_current_season', 'output' => '{"ok":true}'],
    ]]));

    Http::assertSent(function (Request $request) {
        $b = $request->data();

        return ! array_key_exists('tools', $b) && ! array_key_exists('tool_choice', $b)
            && $b['input'][1] === ['type' => 'function_call', 'call_id' => 'c1', 'name' => 'get_current_season', 'arguments' => '{}']
            && $b['input'][2] === ['type' => 'function_call_output', 'call_id' => 'c1', 'output' => '{"ok":true}'];
    });
});

test('E45-P05: no model name is hard-coded - the model in the request is exactly the one passed from the environment config', function () {
    Http::fake(['*' => Http::response(e45p_ok())]);
    e45p_config(['tatawwur_ai.model' => 'some-future-model-9']);

    (new OpenAiResponsesProvider)->generate(e45p_request(['model' => 'some-future-model-9']));

    Http::assertSent(fn (Request $r) => $r->data()['model'] === 'some-future-model-9');
    expect(file_get_contents(base_path('app/Services/AiAssistant/Providers/OpenAiResponsesProvider.php')))->not->toMatch('/gpt-\d|o\d-mini|gpt-4|gpt-5/i');
});

// ───────────────────────────── تحليل الردود ─────────────────────────────

test('E45-P06: parsing - text, usage, tool calls with decoded arguments, incomplete => length, and invalid JSON arguments become an empty array', function () {
    Http::fake(['*' => Http::sequence()
        ->push(e45p_ok('نص أول'))
        ->push(['status' => 'incomplete', 'model' => 'm', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'نص مقطوع']]]], 'usage' => ['input_tokens' => 1, 'output_tokens' => 2]])
        ->push(['status' => 'completed', 'output' => [['type' => 'function_call', 'call_id' => 'call_9', 'name' => 'search_platform', 'arguments' => '{"query":"جواهر"}'], ['type' => 'function_call', 'call_id' => 'call_10', 'name' => 'get_current_season', 'arguments' => '{not json']]]),
    ]);

    $provider = new OpenAiResponsesProvider;

    $first = $provider->generate(e45p_request());
    $cut = $provider->generate(e45p_request());
    $tools = $provider->generate(e45p_request());

    expect($first->text)->toBe('نص أول')->and($first->wantsTools())->toBeFalse()
        ->and($cut->finishReason)->toBe('length')->and($cut->text)->toBe('نص مقطوع')
        ->and($tools->wantsTools())->toBeTrue()->and($tools->toolCalls)->toHaveCount(2)
        ->and($tools->toolCalls[0]->name)->toBe('search_platform')->and($tools->toolCalls[0]->arguments)->toBe(['query' => 'جواهر'])->and($tools->toolCalls[1]->arguments)->toBe([])
        ->and($tools->providerItems[0]['call_id'])->toBe('call_9');
});

test('E45-P07: unusable bodies map to a closed category - failed status, error object, empty output, non-JSON', function () {
    Http::fake(['*' => Http::sequence()
        ->push(['status' => 'failed', 'output' => []])
        ->push(['error' => ['message' => 'secret detail from provider'], 'output' => []])
        ->push(['status' => 'completed', 'output' => []])
        ->push('not json at all', 200),
    ]);

    $provider = new OpenAiResponsesProvider;

    expect(e45p_category(fn () => $provider->generate(e45p_request())))->toBe(AiProviderException::SERVER)
        ->and(e45p_category(fn () => $provider->generate(e45p_request())))->toBe(AiProviderException::SERVER)
        ->and(e45p_category(fn () => $provider->generate(e45p_request())))->toBe(AiProviderException::INVALID_RESPONSE)
        ->and(e45p_category(fn () => $provider->generate(e45p_request())))->toBe(AiProviderException::INVALID_RESPONSE);
});

// ───────────────────────────── الأخطاء وسرية المفتاح ─────────────────────────────

test('E45-P08: HTTP statuses map to closed categories and the provider body, headers and key never reach the exception', function () {
    $map = [401 => 'auth', 403 => 'auth', 429 => 'rate_limited', 500 => 'server', 502 => 'server', 503 => 'server', 400 => 'invalid_response', 404 => 'invalid_response', 422 => 'invalid_response'];
    $sequence = Http::sequence();

    foreach (array_keys($map) as $status) {
        $sequence->push(['error' => ['message' => 'LEAK '.E45P_KEY.' internal detail']], $status, ['X-Secret' => 'hdr']);
    }

    Http::fake(['*' => $sequence]);

    foreach ($map as $status => $category) {
        $caught = null;

        try {
            (new OpenAiResponsesProvider)->generate(e45p_request());
        } catch (AiProviderException $e) {
            $caught = $e;
        }

        expect($caught?->category)->toBe($category, 'status '.$status)->and($caught->getMessage())->not->toContain('LEAK')->not->toContain(E45P_KEY)->not->toContain('internal detail');
    }
});

test('E45-P09: connection failures map to network or timeout and are retried at most once (when retries are on)', function () {
    $calls = 0;
    $message = 'cURL error 28: Operation timed out after 25000 milliseconds';
    Http::fake(function () use (&$calls, &$message) {
        $calls++;

        throw new ConnectionException($message);
    });

    expect(e45p_category(fn () => (new OpenAiResponsesProvider)->generate(e45p_request())))->toBe(AiProviderException::TIMEOUT)->and($calls)->toBe(1);

    $message = 'cURL error 6: Could not resolve host';
    $calls = 0;
    expect(e45p_category(fn () => (new OpenAiResponsesProvider)->generate(e45p_request())))->toBe(AiProviderException::NETWORK)->and($calls)->toBe(1);

    e45p_config(['tatawwur_ai.limits.retries' => 1]);
    $calls = 0;
    e45p_category(fn () => (new OpenAiResponsesProvider)->generate(e45p_request()));

    expect($calls)->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(2);
});

test('E45-P10: an HTTP error is never retried (a paid generation is not repeated)', function () {
    e45p_config(['tatawwur_ai.limits.retries' => 2]);
    $calls = 0;
    Http::fake(function () use (&$calls) {
        $calls++;

        return Http::response(['error' => 'x'], 500);
    });

    e45p_category(fn () => (new OpenAiResponsesProvider)->generate(e45p_request()));

    expect($calls)->toBe(1);
});

// ───────────────────────────── البث ─────────────────────────────

function e45p_sse(array $events): string
{
    return implode('', array_map(fn ($e) => 'event: '.$e['type']."\ndata: ".json_encode($e, JSON_UNESCAPED_UNICODE)."\n\n", $events));
}

test('E45-P11: streaming forwards text deltas in order and returns the completed response; store stays false and stream is true', function () {
    $events = [
        ['type' => 'response.created', 'response' => ['id' => 'r']],
        ['type' => 'response.output_text.delta', 'delta' => 'مرحبا '],
        ['type' => 'response.output_text.delta', 'delta' => 'بك'],
        ['type' => 'response.completed', 'response' => e45p_ok('مرحبا بك')],
    ];
    Http::fake(['*' => Http::response(e45p_sse($events), 200, ['Content-Type' => 'text/event-stream'])]);

    $deltas = [];
    $res = (new OpenAiResponsesProvider)->stream(e45p_request(), function (string $d) use (&$deltas): void {
        $deltas[] = $d;
    });

    expect($deltas)->toBe(['مرحبا ', 'بك'])->and($res->text)->toBe('مرحبا بك');
    Http::assertSent(fn (Request $r) => $r->data()['stream'] === true && $r->data()['store'] === false);
});

test('E45-P12: a streamed failure event or a stream that never completes is a closed-category error', function () {
    Http::fake(['*' => Http::sequence()
        ->push(e45p_sse([['type' => 'response.output_text.delta', 'delta' => 'جزء'], ['type' => 'response.failed', 'response' => []]]), 200)
        ->push(e45p_sse([['type' => 'response.output_text.delta', 'delta' => 'جزء بلا إكمال']]), 200),
    ]);
    $provider = new OpenAiResponsesProvider;

    expect(e45p_category(fn () => $provider->stream(e45p_request(), fn ($d) => null)))->toBe(AiProviderException::SERVER)
        ->and(e45p_category(fn () => $provider->stream(e45p_request(), fn ($d) => null)))->toBe(AiProviderException::INVALID_RESPONSE);
});

// ───────────────────────────── اختيار المزوّد ─────────────────────────────

test('E45-P13: the registry resolves openai only by configuration, fake only in local/testing, and unknown names to no provider', function () {
    $registry = app(AiProviderRegistry::class);

    config(['tatawwur_ai.provider' => 'openai']);
    expect($registry->resolve())->toBeInstanceOf(OpenAiResponsesProvider::class);

    config(['tatawwur_ai.provider' => 'fake']);
    expect($registry->resolve())->toBeInstanceOf(FakeAiProvider::class);

    foreach (['anthropic', 'gemini', '', 'OPENAI_EVIL', '../x'] as $name) {
        config(['tatawwur_ai.provider' => $name]);
        expect($registry->resolve())->toBeNull();
    }

    config(['tatawwur_ai.provider' => 'fake']);
    app()->detectEnvironment(fn () => 'production');
    expect($registry->resolve())->toBeNull();
    app()->detectEnvironment(fn () => 'testing');
});

test('E45-P14: provider disabled or not configured means the readiness check is false and the assistant uses local retrieval', function () {
    config(['tatawwur_ai.enabled' => true, 'tatawwur_ai.provider_enabled' => false]);
    expect(App\Services\AiAssistant\TatawwurSettings::providerReady())->toBeFalse();

    config(['tatawwur_ai.provider_enabled' => true, 'tatawwur_ai.provider' => 'openai', 'tatawwur_ai.providers.openai.api_key' => '']);
    expect(App\Services\AiAssistant\TatawwurSettings::providerReady())->toBeFalse();

    e45p_config();
    expect(App\Services\AiAssistant\TatawwurSettings::providerReady())->toBeTrue();
});

// ───────────────────────────── المسار الكامل بالمزوّد الحقيقي (مزيَّف الشبكة) ─────────────────────────────

test('E45-P15: end to end through HTTP with the OpenAI adapter on a faked network - answered, metrics recorded, and the key is nowhere in the response or the metrics', function () {
    e45_enable(['tatawwur_ai.provider' => 'openai', 'tatawwur_ai.model' => 'test-model-1', 'tatawwur_ai.providers.openai.api_key' => E45P_KEY]);
    e45_kb();
    Http::fake(['https://api.openai.com/v1/responses' => Http::response(e45p_ok('الجواهر نقاط مكافآت داخلية وليست نقودًا.'))]);

    $res = e45_ask($this->actingAs(e45_member()), 'ما هي الجواهر وكيف تعمل؟')->assertOk();
    $json = $res->json();

    expect($json['outcome'])->toBe('answered')->and($json['text'])->toContain('الجواهر');
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $r) => $r->data()['store'] === false && str_contains(json_encode($r->data(), JSON_UNESCAPED_UNICODE), 'KNOWLEDGE EXCERPTS'));

    $metric = AiRequestMetric::query()->firstOrFail();
    expect($metric->provider)->toBe('openai')->and($metric->model)->toBe('test-model-1')->and($metric->input_tokens)->toBe(11)
        ->and($res->getContent())->not->toContain(E45P_KEY)->and(json_encode($metric->toArray()))->not->toContain(E45P_KEY);
});

test('E45-P16: end to end - a provider 500 degrades to the local fallback with sources, and the key never leaks into the response', function () {
    e45_enable(['tatawwur_ai.provider' => 'openai', 'tatawwur_ai.model' => 'test-model-1', 'tatawwur_ai.providers.openai.api_key' => E45P_KEY]);
    e45_kb();
    Http::fake(['*' => Http::response(['error' => ['message' => 'upstream '.E45P_KEY]], 500)]);

    $res = e45_ask($this->actingAs(e45_member()), 'ما هي الجواهر وكيف تعمل؟')->assertOk();

    expect($res->json('outcome'))->toBe('fallback')->and($res->json('sources'))->not->toBe([])->and($res->getContent())->not->toContain(E45P_KEY)->not->toContain('upstream');
});

test('E45-P17: the only file that makes outbound HTTP calls for the assistant is the OpenAI provider class', function () {
    $offenders = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app/Services/AiAssistant'))) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php' && preg_match('/\bHttp::|curl_init|GuzzleHttp|file_get_contents\(\s*[\'"]https?:|fsockopen/', (string) file_get_contents($file->getPathname())) === 1) {
            // ويندوز: base_path() والمسار الكامل بفواصل عكسية؛ نوحّد الفواصل قبل القصّ (CI على لينكس وجهاز التطوير على ويندوز).
            $offenders[] = str_replace(str_replace('\\', '/', base_path()).'/', '', str_replace('\\', '/', $file->getPathname()));
        }
    }

    expect($offenders)->toBe(['app/Services/AiAssistant/Providers/OpenAiResponsesProvider.php']);
});
