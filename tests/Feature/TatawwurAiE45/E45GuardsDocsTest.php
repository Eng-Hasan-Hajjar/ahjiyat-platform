<?php

use App\Services\AiAssistant\Tools\ToolRegistry;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

require_once __DIR__.'/_support.php';

/*
|--------------------------------------------------------------------------
| E45 - حراس ثابتة ووثائق «تطوّر AI»: عقد البيئة، حدود المزوّد الوحيد، أدوات القراءة فقط، سرّية الأحجيات، عدم تمدد التبعيات، سلامة الوثائق (روابط/عناوين/مطابقة السجل)،
| الصلاحيات والمخطط والجدولة وخطوة CI. تقرأ ملفات المستودع فقط؛ لا شبكة ولا مزوّد.
|--------------------------------------------------------------------------
*/

const E45G_DOCS = ['docs/tatawwur-ai-e45.md', 'docs/tatawwur-ai-operations-runbook.md'];

function e45g_file(string $relative): string
{
    return (string) file_get_contents(base_path($relative));
}

/** @return list<string> ملفات PHP/JS/Blade لمسار نسبي. */
function e45g_files(string $relative, array $extensions = ['php']): array
{
    $path = base_path($relative);

    if (is_file($path)) {
        return [$relative];
    }

    $out = [];

    foreach (File::allFiles($path) as $file) {
        if (in_array(strtolower($file->getExtension()), $extensions, true)) {
            $out[] = str_replace('\\', '/', $relative.'/'.$file->getRelativePathname());
        }
    }

    sort($out);

    return $out;
}

/** كل ملفات الكود الخاصة بالميزة (خدمات + متحكمات + أوامر + فلامنت + سياسة + وسيط). */
function e45g_feature_code(): array
{
    return array_merge(
        e45g_files('app/Services/AiAssistant'),
        e45g_files('app/Http/Controllers/TatawwurAi'),
        e45g_files('app/Http/Controllers/Help'),
        e45g_files('app/Console/Commands/Ai'),
        e45g_files('app/Http/Middleware/EnsureTatawwurAiEnabled.php'),
        e45g_files('app/Policies/AiKnowledgeArticlePolicy.php'),
        e45g_files('app/Filament/Resources/AiKnowledgeArticleResource'),
        e45g_files('app/Filament/Resources/AiKnowledgeArticleResource.php'),
        e45g_files('app/Filament/Pages/TatawwurAiPage.php'),
    );
}

function e45g_slug(string $heading): string
{
    $heading = mb_strtolower(trim(str_replace('`', '', $heading)));
    $out = '';

    foreach (mb_str_split($heading) as $char) {
        if (preg_match('/[\p{L}\p{N}\p{M}_\- ]/u', $char) === 1) {
            $out .= $char;
        }
    }

    return str_replace(' ', '-', $out);
}

function e45g_anchors(string $markdown): array
{
    $seen = [];
    $anchors = [];
    $inFence = false;

    foreach (preg_split('/\r\n|\r|\n/', $markdown) as $line) {
        if (str_starts_with(ltrim($line), '```')) {
            $inFence = ! $inFence;

            continue;
        }

        if (! $inFence && preg_match('/^#{1,6}\s+(.*)$/u', $line, $m) === 1) {
            $slug = e45g_slug($m[1]);
            $n = $seen[$slug] ?? 0;
            $seen[$slug] = $n + 1;
            $anchors[] = $n === 0 ? $slug : "{$slug}-{$n}";
        }
    }

    return $anchors;
}

// ───────────────────────────── البيئة والتبعيات ─────────────────────────────

test('E45-G01: the feature adds no Composer or npm dependency and no AI SDK is installed', function () {
    $banned = '/openai|anthropic|langchain|prism-php|neuron-ai|laravel\/ai|google\/generative|gemini|mistral|cohere|llama|ollama|transformers|tiktoken|marked|dompurify|highlight\.js|markdown-it|socket\.io|sse\.js|eventsource/i';

    $composer = json_decode(e45g_file('composer.json'), true);
    $packages = array_merge(array_keys($composer['require'] ?? []), array_keys($composer['require-dev'] ?? []));

    $package = json_decode(e45g_file('package.json'), true);
    $node = array_merge(array_keys($package['dependencies'] ?? []), array_keys($package['devDependencies'] ?? []));

    foreach (array_merge($packages, $node) as $name) {
        expect(preg_match($banned, $name))->toBe(0, "unexpected dependency {$name}");
    }

    $lock = json_decode(e45g_file('composer.lock'), true);

    foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $locked) {
        expect(preg_match($banned, (string) $locked['name']))->toBe(0, "locked package {$locked['name']}");
    }
});

test('E45-G02: .env.example ships every flag off, no model, no key, and nothing resembling a secret value', function () {
    $env = e45g_file('.env.example');

    foreach (['TATAWWUR_AI_ENABLED', 'TATAWWUR_AI_PROVIDER_ENABLED', 'TATAWWUR_AI_GUESTS_ENABLED', 'TATAWWUR_AI_STREAMING_ENABLED'] as $flag) {
        expect(preg_match('/^'.$flag.'=false\s*(?:#.*)?$/m', $env))->toBe(1, "{$flag} must be false");
    }

    expect(preg_match('/^OPENAI_API_KEY=\s*(?:#.*)?$/m', $env))->toBe(1)
        ->and(preg_match('/^AI_MODEL=\s*(?:#.*)?$/m', $env))->toBe(1)
        ->and(preg_match('/^AI_PROVIDER=openai\s*(?:#.*)?$/m', $env))->toBe(1)
        ->and(preg_match('/sk-[A-Za-z0-9_\-]{12,}/', $env))->toBe(0);

    // لا قيمة فعلية لمفتاح/سر داخل كتلة E45 (التعليقات المعطّلة مسموحة).
    // يشمل الأسطر المعلَّقة (حارس E25-18 يعدّها): أي مفتاح اسمه يحوي TOKEN/KEY/SECRET/PASSWORD يبقى فارغًا، والافتراضي في التعليق فقط.
    preg_match_all('/^\s*#?\s*(TATAWWUR_AI_[A-Z_]*(?:TOKEN|SECRET|PASSWORD|KEY)[A-Z_]*|OPENAI_[A-Z_]*)=(.*)$/m', $env, $m, PREG_SET_ORDER);
    expect(count($m))->toBeGreaterThanOrEqual(3);

    foreach ($m as [, $name, $value]) {
        expect(trim(explode('#', $value)[0]))->toBe('', "{$name} must be empty in .env.example");
    }
});

test('E45-G03: the config defaults are closed - flags default false, the model has no default, and no model name is hard-coded anywhere in code or config', function () {
    $config = e45g_file('config/tatawwur_ai.php');

    foreach (['TATAWWUR_AI_ENABLED', 'TATAWWUR_AI_PROVIDER_ENABLED', 'TATAWWUR_AI_GUESTS_ENABLED', 'TATAWWUR_AI_STREAMING_ENABLED'] as $flag) {
        expect($config)->toContain("env('{$flag}', false)");
    }

    expect($config)->toContain("'model' => env('AI_MODEL')")
        ->and($config)->not->toMatch("/env\\('AI_MODEL'\\s*,/")
        ->and($config)->not->toMatch("/env\\('OPENAI_API_KEY'\\s*,/");

    $names = '/\b(?:gpt-[0-9a-z.\-]+|o[134]-(?:mini|preview|pro)?|chatgpt-[a-z0-9\-]+|claude-(?:opus|sonnet|haiku|fable|mythos)[a-z0-9.\-]*|gemini-[0-9a-z.\-]+|text-embedding-[a-z0-9\-]+)\b/i';

    foreach (array_merge(e45g_feature_code(), ['config/tatawwur_ai.php', '.env.example'], e45g_files('resources/js/tatawwur', ['js'])) as $file) {
        expect(preg_match($names, e45g_file($file)))->toBe(0, "{$file} hard-codes a model name");
    }
});

test('E45-G03b: a blank token tunable falls back to its default instead of becoming a tiny floor that would block the assistant', function () {
    foreach (['TATAWWUR_AI_MAX_OUTPUT_TOKENS' => ['limits.max_output_tokens', 900], 'TATAWWUR_AI_GLOBAL_DAILY_TOKEN_LIMIT' => ['limits.global_daily_token_limit', 2000000]] as $env => [$key, $default]) {
        putenv("{$env}=");
        $_ENV[$env] = '';
        $_SERVER[$env] = '';

        $config = require base_path('config/tatawwur_ai.php');
        $value = $config['limits'][explode('.', $key)[1]];

        putenv($env);
        unset($_ENV[$env], $_SERVER[$env]);

        expect($value)->toBe($default, "{$env} blank must mean the default");
    }
});

test('E45-G04: every env key read by config/tatawwur_ai.php is documented in the environment contract', function () {
    preg_match_all("/env\\('([A-Z0-9_]+)'/", e45g_file('config/tatawwur_ai.php'), $m);
    $doc = e45g_file('docs/environment-variables.md');
    $example = e45g_file('.env.example');

    expect(count($m[1]))->toBeGreaterThanOrEqual(20);

    foreach (array_unique($m[1]) as $key) {
        // وثيقة البيئة تختصر المتغيرات المتجاورة بصيغة «`_SUFFIX`» بعد أول مذكور بكامل بادئته؛ كلاهما مقبول.
        $short = '`'.substr($key, strlen('TATAWWUR_AI')).'`';

        expect(str_contains($doc, $key) || (str_starts_with($key, 'TATAWWUR_AI_') && str_contains($doc, $short)))->toBeTrue("{$key} is not documented");
        expect($example)->toContain($key);
    }
});

// ───────────────────────────── المزوّد الوحيد والشبكة ─────────────────────────────

test('E45-G05: only the single reviewed provider class performs outbound HTTP, forces store=false, and E26 W7 allowlists exactly it', function () {
    $net = '/Http::|Illuminate\\\\Support\\\\Facades\\\\Http|PendingRequest|curl_(?:init|exec)|file_get_contents\\(\s*[\'"]https?|fsockopen|stream_socket_client|GuzzleHttp|SoapClient|new\s+\\\\?Socket|Mail::|Notification::send/';
    $holders = [];

    foreach (e45g_feature_code() as $file) {
        if (preg_match($net, e45g_file($file)) === 1) {
            $holders[] = $file;
        }
    }

    expect($holders)->toBe(['app/Services/AiAssistant/Providers/OpenAiResponsesProvider.php']);

    $provider = e45g_file('app/Services/AiAssistant/Providers/OpenAiResponsesProvider.php');
    expect($provider)->toContain("'store' => false")
        ->and($provider)->not->toContain('previous_response_id')
        ->and($provider)->not->toMatch('/Authorization.*(?:Log|logger|report)/s');

    // لا ملف خارج المزوّد (ولا خارج القائمة البيضاء القديمة) يستعمل Http في كود التطبيق بسبب هذه الميزة.
    $w7 = e45g_file('tests/Feature/SecurityE26/E26StaticAuditTest.php');
    expect($w7)->toContain('app/Services/AiAssistant/Providers/OpenAiResponsesProvider.php');
});

test('E45-G06: the browser never talks to the provider - the widget script and views carry no provider host, key or absolute URL', function () {
    $scripts = e45g_files('resources/js/tatawwur', ['js']);
    expect($scripts)->not->toBeEmpty();

    foreach (array_merge($scripts, ['resources/views/layouts/partials/tatawwur-widget.blade.php'], e45g_files('resources/views/help', ['php'])) as $file) {
        $content = e45g_file($file);
        $content = str_replace('http://www.w3.org/2000/svg', '', $content);

        expect(preg_match('#https?://#i', $content))->toBe(0, "{$file} has an absolute URL")
            ->and(preg_match('/openai|api[_-]?key|authorization|bearer/i', $content))->toBe(0, "{$file} mentions provider credentials");
    }
});

// ───────────────────────────── القراءة فقط وسرّية الأحجيات ─────────────────────────────

test('E45-G07: the tool registry is exactly the 23 documented read-only tools and no tool source contains a write, event, queue or mail call', function () {
    $registry = app(ToolRegistry::class);
    $names = $registry->names();

    expect($names)->toHaveCount(23);

    foreach ($registry->catalog() as $tool) {
        foreach (array_keys($tool->properties()) as $parameter) {
            expect(in_array($parameter, ToolRegistry::FORBIDDEN_PARAMETERS, true))->toBeFalse("{$tool->name()}.{$parameter}");
        }

        expect(in_array($tool->name(), ToolRegistry::FORBIDDEN_TOOLS, true))->toBeFalse();
    }

    $write = '/->(?:save|saveQuietly|update|updateOrCreate|delete|forceDelete|insert|insertGetId|upsert|increment|decrement|touch|attach|detach|sync|create|firstOrCreate|firstOrNew|restore)\s*\(|::(?:create|forceCreate|firstOrCreate|updateOrCreate|insert|upsert|destroy|truncate|unguard)\s*\(|DB::(?:insert|update|delete|statement|unprepared)|\bevent\s*\(|::dispatch|dispatch(?:Sync|Now)?\s*\(|Mail::|Notification::|Cache::(?:put|forever|increment|add)\(|\bsession\s*\(\s*\[|->put\s*\(/';
    $files = array_merge(e45g_files('app/Services/AiAssistant/Tools'));

    foreach ($files as $file) {
        $code = preg_replace('#/\*.*?\*/|^\s*//.*$|^\s*\*.*$#ms', '', e45g_file($file));
        expect(preg_match($write, (string) $code, $hit))->toBe(0, "{$file} writes: ".($hit[0] ?? ''));
    }

    // الخدمات التي تكتب عند القراءة ممنوعة صراحةً في الأدوات.
    foreach ($files as $file) {
        expect(e45g_file($file))->not->toMatch('/LevelService|CurrencyWalletService|PassPageData::(?:build|forUser)|progressionFor|balanceFor/');
    }
});

test('E45-G08: puzzle secret field names exist only in the guards that block them - never in tools, presenters, controllers, views or the widget', function () {
    $secret = '/answer_raw|answer_hash|answer_normalized|solution_data|game_config|validation_secret|hidden_choices|correct_option|secret_answer/i';
    $allowed = [
        'app/Services/AiAssistant/Safety/AiPuzzleSafetyGuard.php',
        'app/Services/AiAssistant/Knowledge/KnowledgeNormalizer.php',
        'app/Services/AiAssistant/Safety/SystemPrompt.php',
    ];

    foreach (array_merge(e45g_feature_code(), e45g_files('resources/js/tatawwur', ['js']), ['resources/views/layouts/partials/tatawwur-widget.blade.php'], e45g_files('resources/views/help', ['php'])) as $file) {
        if (in_array($file, $allowed, true)) {
            continue;
        }

        expect(preg_match($secret, e45g_file($file)))->toBe(0, "{$file} references a secret puzzle field");
    }

    // أي أداة لا تستعلم جداول الأحجيات الخام ولا الدردشة الخاصة ولا ملفات الإشراف ولا إثباتات الدفع.
    foreach (e45g_files('app/Services/AiAssistant/Tools') as $file) {
        $code = e45g_file($file);

        // أسماء الجداول/النماذج الخاصة (قائمة الأسماء الممنوعة في السجل نفسه مستثناة: هي المانع لا المستهلك).
        if ($file !== 'app/Services/AiAssistant/Tools/ToolRegistry.php') {
            expect($code)->not->toMatch('/chat_messages|ChatMessage|ModerationCase|moderation_|payment_proof|PaymentProof|private_message|DB::select|DB::statement|DB::unprepared|DB::raw/');
        }

        // SQL خام مسموح فقط كتعبير ثابت (عدّ/ترتيب)؛ أي متغير أو دمج نصي داخل الأقواس ممنوع (لا مدخل غير موثوق في SQL أبدًا).
        expect($code)->not->toMatch('/(?:whereRaw|selectRaw|orderByRaw|havingRaw|groupByRaw)\s*\([^)]*(?:\$|\{|[\'"]\s*\.|\.\s*[\'"])/');
    }
});

test('E45-G09: tool output is built through allow-listed presenters - no tool returns a model, a collection of models or toArray()/toJson() of one', function () {
    foreach (e45g_files('app/Services/AiAssistant/Tools/Catalog') as $file) {
        $code = e45g_file($file);

        expect($code)->not->toMatch('/->toArray\(\)|->toJson\(\)|json_encode\(\s*\$(?:model|record|row|user)\b|->attributesToArray|->getAttributes|->makeVisible|\$[a-z]+->all\(\)\s*\)\s*;?\s*\n\s*\}/i')
            ->and($code)->not->toContain('AiToolResult::ok($this->name(), $model');
    }
});

// ───────────────────────────── الصلاحيات والمخطط والجدولة والمسارات ─────────────────────────────

test('E45-G10: the three permissions exist in the registry and the seeder, the policy uses them, and there is no permission or Filament resource that reads conversations', function () {
    $permissions = e45g_file('config/permissions.php');

    foreach (['ai-assistant.view', 'ai-assistant.knowledge.manage', 'ai-assistant.knowledge.publish'] as $permission) {
        expect($permissions)->toContain("'{$permission}'")
            ->and(e45g_file('database/seeders/RolesAndPermissionsSeeder.php'))->toContain("'{$permission}'");
    }

    expect($permissions)->not->toMatch("/'ai-assistant\\.(?:conversations?|messages?|history|transcripts?)/");

    foreach (e45g_files('app/Filament', ['php']) as $file) {
        expect(e45g_file($file))->not->toMatch('/AiConversation|AiMessage\b|ai_messages|ai_conversations/');
    }

    $resource = e45g_file('app/Filament/Resources/AiKnowledgeArticleResource.php');
    expect($resource)->toMatch('/function\s+canDelete[^{]*\{\s*return\s+false/s');
});

test('E45-G11: the migration creates exactly the six ai_ tables, rolls back only them, and touches no existing table', function () {
    $migration = e45g_file('database/migrations/2026_12_21_000001_create_tatawwur_ai_tables.php');

    preg_match_all("/Schema::create\\('([a-z_]+)'/", $migration, $created);
    preg_match_all("/Schema::dropIfExists\\('([a-z_]+)'/", $migration, $dropped);

    $tables = ['ai_knowledge_articles', 'ai_knowledge_chunks', 'ai_conversations', 'ai_messages', 'ai_feedback', 'ai_request_metrics'];

    expect($created[1])->toBe($tables)
        ->and(collect($dropped[1])->sort()->values()->all())->toBe(collect($tables)->sort()->values()->all())
        ->and($migration)->not->toMatch("/Schema::table\\(|DB::statement|->float\\(|->double\\(|->decimal\\(/");

    // هذه الميزة هي الوحيدة التي تضيف هذا الملف: لا ترحيل آخر يمس جداول ai_ قبله أو بعده.
    foreach (File::files(database_path('migrations')) as $file) {
        if ($file->getFilename() === '2026_12_21_000001_create_tatawwur_ai_tables.php') {
            continue;
        }

        expect(file_get_contents($file->getPathname()))->not->toMatch("/Schema::(?:create|table|dropIfExists)\\('ai_/");
    }
});

test('E45-G12: the routes are exactly the documented set, every state-changing route is throttled, none is named like an economy action', function () {
    $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with((string) $r->getName(), 'tatawwur.') || str_starts_with((string) $r->getName(), 'help.'));

    expect($routes->map(fn ($r) => $r->getName())->sort()->values()->all())->toBe([
        'help.index', 'help.show', 'tatawwur.feedback.store', 'tatawwur.history.conversation.destroy', 'tatawwur.history.destroy', 'tatawwur.messages.store', 'tatawwur.state',
    ]);

    foreach ($routes as $route) {
        $methods = array_diff($route->methods(), ['HEAD']);

        if ($methods !== ['GET']) {
            expect(collect($route->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_starts_with($m, 'throttle:')))->toBeTrue($route->getName().' is not throttled');
        }

        expect(preg_match('/reward|grant|claim|payout|prize/i', $route->uri().' '.$route->getName()))->toBe(0);
    }
});

test('E45-G13: the retention command is scheduled daily and the CI has the MySQL step without secrets or a provider', function () {
    expect(e45g_file('routes/console.php'))->toContain("Schedule::command('ai:conversations:prune')->dailyAt('03:25')");

    $ci = preg_replace('/^\s*#.*$/m', '', e45g_file('.github/workflows/ci.yml'));

    expect($ci)->toContain('tests/Feature/TatawwurAiE45/E45MysqlSchemaTest.php')
        ->and($ci)->not->toMatch('/OPENAI|AI_MODEL|TATAWWUR_AI/')
        ->and($ci)->not->toContain('secrets.');
});

test('E45-G14: no delivery artifact for this task was left at the repository root and no git metadata was created by the feature', function () {
    foreach (File::files(base_path()) as $file) {
        expect(preg_match('/e45|tatawwur|ai-assistant/i', $file->getFilename()))->toBe(0, 'stray '.$file->getFilename().' at the repository root');
    }

    foreach (File::allFiles(base_path('tests/Feature/TatawwurAiE45')) as $file) {
        expect($file->getExtension())->toBe('php');
    }
});

// ───────────────────────────── الوثائق ─────────────────────────────

test('E45-G15: both documents exist, are substantial, and every internal link and anchor resolves', function () {
    $broken = [];

    foreach (E45G_DOCS as $doc) {
        expect(is_file(base_path($doc)))->toBeTrue($doc)->and(strlen(e45g_file($doc)))->toBeGreaterThan(6000, "{$doc} looks thin");

        preg_match_all('/\]\(([^)\s]+)\)/u', e45g_file($doc), $m);

        foreach ($m[1] as $target) {
            if (str_starts_with($target, 'http') || str_starts_with($target, 'mailto:')) {
                continue;
            }

            [$path, $fragment] = array_pad(explode('#', $target, 2), 2, null);
            $file = $path === '' ? base_path($doc) : (string) realpath(dirname(base_path($doc)).'/'.$path);

            if ($file === '' || ! is_file($file)) {
                $broken[] = "{$doc} → {$target} (ملف غير موجود)";

                continue;
            }

            if ($fragment !== null && str_ends_with($file, '.md') && ! in_array(rawurldecode($fragment), e45g_anchors((string) file_get_contents($file)), true)) {
                $broken[] = "{$doc} → {$target} (عنوان غير موجود)";
            }
        }
    }

    expect($broken)->toBe([]);
});

test('E45-G16: the design document matches the code - every registered tool, flag, permission, outcome phrase and command is named, with no stale count', function () {
    $doc = e45g_file('docs/tatawwur-ai-e45.md');

    foreach (app(ToolRegistry::class)->names() as $name) {
        expect($doc)->toContain('`'.$name.'`');
    }

    foreach (['TATAWWUR_AI_ENABLED', 'TATAWWUR_AI_PROVIDER_ENABLED', 'TATAWWUR_AI_GUESTS_ENABLED', 'TATAWWUR_AI_STREAMING_ENABLED', 'AI_MODEL', 'ai-assistant.view', 'ai-assistant.knowledge.manage', 'ai-assistant.knowledge.publish',
        'ai:knowledge:seed', 'ai:conversations:prune', 'store=false', 'FakeAiProvider', 'OpenAiResponsesProvider', 'answer_hash', 'لا أملك معلومات موثوقة كافية عن هذا الموضوع داخل دليل المنصة حاليًا.',
        'تطوّر مشغول حاليًا، يمكنك استخدام دليل المنصة.', 'تعذر تشغيل المساعد الذكي حاليًا', '30 يومًا', 'المخاطر المتبقية'] as $needle) {
        expect($doc)->toContain($needle);
    }

    expect(preg_match_all('/^\| `(?:get_|search_)[a-z_]+` \|/m', $doc))->toBe(23)
        ->and($doc)->toContain('13 عامة + 10 «حسابي»');
});

test('E45-G17: the runbook covers rollout order, key rotation, Nginx/FPM streaming, retention, incidents and rollback - with placeholders only for sensitive values', function () {
    $doc = e45g_file('docs/tatawwur-ai-operations-runbook.md');

    foreach (['ترتيب التفعيل المرحلي', 'تدوير المفتاح', 'fastcgi_buffering off', 'output_buffering', 'X-Accel-Buffering', 'ai:conversations:prune', 'ai:knowledge:rebuild', 'ai:knowledge:seed --publish', 'TATAWWUR_AI_ENABLED=false',
        'TATAWWUR_AI_PROVIDER_ENABLED=false', 'TATAWWUR_AI_STREAMING_ENABLED=false', 'APP_KEY', 'مدير الأسرار', 'خطط الحوادث', 'التراجع الفوري', 'قائمة تحقق قبل الإطلاق', 'incident-response.md'] as $needle) {
        expect($doc)->toContain($needle);
    }

    preg_match_all('/^\s*(?:export\s+)?([A-Z][A-Z0-9_]*(?:PASSWORD|SECRET|TOKEN|KEY))=([^\s#`]+)/m', $doc.e45g_file('docs/tatawwur-ai-e45.md').e45g_file('docs/environment-variables.md'), $matches, PREG_SET_ORDER);

    foreach ($matches as [, $name, $value]) {
        if (! str_starts_with($name, 'OPENAI') && ! str_starts_with($name, 'TATAWWUR')) {
            continue;
        }

        expect(preg_match('/^(<[^>]+>|\$\{?[A-Z_]+\}?|\.{3}|…|null|"")$/i', trim($value, '"\'')))->toBe(1, "{$name} has a non-placeholder value");
    }

    expect(preg_match('/sk-[A-Za-z0-9_\-]{12,}/', $doc.e45g_file('docs/tatawwur-ai-e45.md')))->toBe(0);
});

test('E45-G18: the environment contract lists the E45 variables with their closed defaults and links to the design document', function () {
    $doc = e45g_file('docs/environment-variables.md');

    foreach (['TATAWWUR_AI_ENABLED', 'TATAWWUR_AI_PROVIDER_ENABLED', 'TATAWWUR_AI_GUESTS_ENABLED', 'TATAWWUR_AI_STREAMING_ENABLED', 'AI_PROVIDER', 'AI_MODEL', 'OPENAI_API_KEY', 'TATAWWUR_AI_GLOBAL_DAILY_TOKEN_LIMIT', 'TATAWWUR_AI_RETENTION_DAYS'] as $key) {
        expect($doc)->toContain($key);
    }

    expect($doc)->toContain('tatawwur-ai-e45.md')
        ->and(preg_match('/OPENAI_API_KEY[^\n]*SECRET/i', $doc))->toBe(1);
});
