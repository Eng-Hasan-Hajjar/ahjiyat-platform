<?php

require_once __DIR__.'/_support.php';

use App\Models\Puzzle;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/*
| E27-E - الأمان: تحديد المعدّل (ضيف/مسجَّل، مفتاحه المستخدم لا IP فقط)، حقن SQL، التعداد، البيانات الحساسة، المخرجات، وتدقيق ساكن للشيفرة الجديدة.
| الاستعلامات الخبيثة هنا مدخلات اختبار محلية على قاعدة اختبار؛ لا شبكة ولا هدف حقيقي.
*/

beforeEach(fn () => $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class));

if (! function_exists('e27_limits')) {
    /** @return list<\Illuminate\Cache\RateLimiting\Limit> */
    function e27_limits(string $name, ?User $user = null, string $ip = '203.0.113.7'): array
    {
        $request = Request::create('/search', 'GET', [], [], [], ['REMOTE_ADDR' => $ip]);
        $request->setUserResolver(fn () => $user);
        $limit = (RateLimiter::limiter($name))($request);

        return is_array($limit) ? array_values($limit) : [$limit];
    }

    /** @return list<string> ملفات الشيفرة الجديدة التي تُدقَّق ساكنًا (بلا الاختبارات). */
    function e27_code_files(): array
    {
        return array_merge(
            e26_php_files(['app/Services/Search', 'app/Services/Discovery']),
            [base_path('app/Http/Controllers/SearchController.php'), base_path('app/Http/Controllers/DiscoverController.php'), base_path('app/Http/Requests/SearchRequest.php')],
        );
    }
}

test('S1: the limiters are keyed per user for players and per IP for guests, with a per-minute cap and a short burst cap', function () {
    $a = e27_user();
    $b = e27_user();

    [$guest] = e27_limits('global-search');
    [$userA] = e27_limits('global-search', $a);
    [$userB] = e27_limits('global-search', $b);
    expect([$guest->maxAttempts, $guest->decaySeconds])->toBe([20, 60])
        ->and([$userA->maxAttempts, $userA->decaySeconds])->toBe([60, 60])
        ->and($userA->key)->not->toBe($userB->key)->and($userA->key)->not->toBe($guest->key)
        ->and($guest->key)->not->toBe(e27_limits('global-search', null, '203.0.113.8')[0]->key);

    [$gMinute, $gBurst] = e27_limits('search-suggest');
    [$uMinute, $uBurst] = e27_limits('search-suggest', $a);
    expect([$gMinute->maxAttempts, $gMinute->decaySeconds, $gBurst->maxAttempts, $gBurst->decaySeconds])->toBe([40, 60, 10, 2])
        ->and([$uMinute->maxAttempts, $uMinute->decaySeconds, $uBurst->maxAttempts, $uBurst->decaySeconds])->toBe([120, 60, 16, 2])
        ->and($uMinute->key)->not->toBe($uBurst->key);

    [$discoverGuest] = e27_limits('discover');
    [$discoverUser] = e27_limits('discover', $a);
    expect([$discoverGuest->maxAttempts, $discoverGuest->decaySeconds])->toBe([90, 60])->and($discoverUser->key)->not->toBe($discoverGuest->key);
});

test('S2: routes are GET-only, carry their limiter, and expose no write verb for search or discovery', function () {
    $expected = ['search.index' => 'global-search', 'search.suggest' => 'search-suggest', 'discover.index' => 'discover'];

    foreach ($expected as $name => $limiter) {
        $route = Route::getRoutes()->getByName($name);

        expect($route)->not->toBeNull()->and($route->methods())->toBe(['GET', 'HEAD'])->and($route->gatherMiddleware())->toContain('throttle:'.$limiter);
    }

    $bad = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => preg_match('#^(search|discover)(/|$)#', $r->uri()) && array_diff($r->methods(), ['GET', 'HEAD']) !== []);
    expect($bad->count())->toBe(0);
});

test('S3: a guest hammering the search page is stopped at the 21st request while a signed-in player on the same address is unaffected', function () {
    $statuses = e26_hammer($this, 'get', route('search.index', ['q' => 'نص']), 21);

    expect(e26_first_429($statuses))->toBe(21);
    $this->actingAs(e27_user())->get(route('search.index', ['q' => 'نص']))->assertOk();
});

test('S4: the suggest burst limits stop rapid typing floods - guests at the 11th request in two seconds, players at the 17th - with Retry-After', function () {
    $guest = e26_hammer($this, 'get', route('search.suggest', ['q' => 'نص']), 12, [], true);
    expect(e26_first_429($guest))->toBe(11);
    $this->getJson(route('search.suggest', ['q' => 'نص']))->assertStatus(429)->assertHeader('Retry-After');

    $player = e27_user();
    $this->actingAs($player);
    $statuses = e26_hammer($this, 'get', route('search.suggest', ['q' => 'نص']), 18, [], true);
    expect(e26_first_429($statuses))->toBe(17);

    // لاعب آخر من العنوان نفسه لا يتأثر (المفتاح هو المستخدم لا العنوان).
    $this->actingAs(e27_user())->getJson(route('search.suggest', ['q' => 'نص']))->assertOk();
});

test('S5: injection-shaped input is inert on every search entry point - no SQL error, no leaked internals, no table touched', function () {
    $this->withoutMiddleware(ThrottleRequests::class);
    e27_puzzle('Ordinary Puzzle');
    e27_user(['name' => 'Ordinary Person']);
    e19Team(null, ['name' => 'Ordinary Team']);
    $viewer = e27_user();
    $counts = fn () => [Puzzle::count(), User::count(), DB::table('teams')->count(), DB::table('puzzle_attempts')->count()];
    $before = $counts();
    $payloads = ["' OR '1'='1", "'; DROP TABLE puzzles; --", '" OR ""="', "1) UNION SELECT email,password FROM users --", "%' ESCAPE '!' OR 1=1 --", '%', '_', '___', '\\', '!%', "\0", "Robert'); DROP TABLE users;--", '١٢٣٪', '${7*7}', '{{7*7}}', '<?php phpinfo(); ?>'];

    foreach ($payloads as $payload) {
        foreach ([[route('search.index'), ['q' => $payload]], [route('search.index'), ['q' => $payload, 'type' => 'players']], [route('search.index'), ['q' => 'نص', 'type' => $payload]], [route('search.suggest'), ['q' => $payload]]] as [$url, $query]) {
            foreach ([$this, $this->actingAs($viewer)] as $caller) {
                $response = $caller->get($url.'?'.http_build_query($query), ['Accept' => str_contains($url, 'suggest') ? 'application/json' : 'text/html']);
                $body = $response->getContent();

                expect($response->status())->toBeLessThan(500)->and($body)->not->toContain('SQLSTATE')->not->toContain('Illuminate\\')->not->toContain('Stack trace');
            }
        }
    }

    expect($counts())->toBe($before);
    expect(e27_titles(e27_suggest($this, '%')))->toBe([])->and(e27_titles(e27_suggest($this, '___')))->toBe([])->and(e27_titles(e27_suggest($this, "' OR '1'='1")))->toBe([]);
});

test('S6: names cannot be enumerated - wildcards and short probes find no players, and a hidden player looks exactly like a missing one', function () {
    $this->withoutMiddleware(ThrottleRequests::class);
    e27_user(['name' => 'Samir Public']);
    e27_user(['name' => 'Samir Hidden', 'profile_visibility' => User::VISIBILITY_PRIVATE]);
    $viewer = e27_user(['name' => 'Searcher']);

    foreach (['%', '%%%', '___', '_', 'S', 'Sa', '%am'] as $probe) {
        expect(e27_group(e27_suggest($this, $probe), 'players'))->toBe([], "guest probe {$probe}");
    }
    expect(e27_group(e27_suggest($this, 'Sa', $viewer), 'players'))->toBe(['Samir Public'])->and(e27_group(e27_suggest($this, '%', $viewer), 'players'))->toBe([]);

    $hidden = e27_suggest($this, 'Samir Hidden', $viewer);
    $missing = e27_suggest($this, 'Zzzzz Nobody', $viewer);
    expect(array_diff_key($hidden, ['query' => 1, 'all_url' => 1]))->toBe(array_diff_key($missing, ['query' => 1, 'all_url' => 1]));
});

test('S7: no secret ever reaches a response - emails, password hashes, tokens, answers, hints and answer hashes stay out of JSON and HTML', function () {
    $this->withoutMiddleware(ThrottleRequests::class);
    $secretUser = e27_user(['name' => 'سلمان الظاهر', 'email' => 'salman-secret@example.test']);
    $puzzle = e27_puzzle('أحجية سلمان', ['answer_raw' => 'إجابة-سرية-فريدة', 'hint' => 'تلميح-سري-فريد']);
    $viewer = e27_user();
    $needles = ['salman-secret', 'example.test', (string) $secretUser->password, (string) $secretUser->remember_token, 'إجابة-سرية-فريدة', 'تلميح-سري-فريد', (string) $puzzle->answer_hash, 'two_factor', 'api_token'];

    $bodies = [
        $this->getJson(route('search.suggest', ['q' => 'سلمان']))->getContent(),
        $this->actingAs($viewer)->getJson(route('search.suggest', ['q' => 'سلمان']))->getContent(),
        $this->actingAs($viewer)->get(route('search.index', ['q' => 'سلمان']))->getContent(),
        $this->actingAs($viewer)->get(route('search.index', ['q' => 'سلمان', 'type' => 'players']))->getContent(),
        $this->actingAs($viewer)->get(route('discover.index'))->getContent(),
        $this->get(route('discover.index'))->getContent(),
    ];

    foreach ($bodies as $body) {
        foreach (array_filter($needles) as $needle) {
            // JSON يهرّب الحروف غير اللاتينية (\uXXXX): نفحص الصيغتين كي لا يكون الفحص فارغًا للأسرار العربية.
            expect($body)->not->toContain($needle)->not->toContain(trim(json_encode($needle), '"'));
        }
    }
    expect(e27_titles(json_decode($bodies[0], true)))->toContain('أحجية سلمان')->toContain('سلمان الظاهر');
});

test('S8: player results are privacy-shaped at the query level - the SELECT list is a short allow-list with no email, password, token or wallet column', function () {
    e27_user(['name' => 'ندى الاختبار']);
    $viewer = e27_user();
    $sql = collect(e27_sql(fn () => $this->actingAs($viewer)->getJson(route('search.suggest', ['q' => 'ندى']))->assertOk()))->filter(fn ($q) => str_contains($q, 'search_tier'));

    expect($sql->count())->toBeGreaterThanOrEqual(7);   // نوع واحد لكل مصدر
    foreach ($sql as $query) {
        preg_match('/^select (.*?), CASE WHEN/s', $query, $m);
        $columns = $m[1] ?? '';

        expect($columns)->not->toBe('')->not->toContain('*')->not->toMatch('/email|password|remember_token|two_factor|api_token|phone|balance|wallet|answer/i');
        // الجدول الرئيسي للاستعلام (أول from) لا أي ذِكر لـusers داخل استعلام فرعي: مصدر سلاسل E30 يفحص حساب المبدع بـEXISTS ولا يُرجع أعمدة users.
        preg_match('/ from "([a-z_]+)"/', $query, $main);
        if (($main[1] ?? null) === 'users') {
            expect($columns)->toBe('"users"."id", "users"."name", "users"."public_id", "users"."profile_visibility"');
        }
    }
});

test('S9: no trace of searches is stored - no new tables, no query-bearing cache keys, no logging in the search code', function () {
    $this->withoutMiddleware(ThrottleRequests::class);
    foreach (['search_queries', 'search_logs', 'search_events', 'search_analytics', 'discovery_events', 'recommendation_cache', 'user_search_history'] as $table) {
        expect(Schema::hasTable($table))->toBeFalse($table);
    }

    $migrations = array_filter(glob(base_path('database/migrations/*.php')), fn ($f) => preg_match('/e27|search/i', basename($f)));
    expect($migrations)->toHaveCount(1);
    foreach ($migrations as $file) {
        expect(file_get_contents($file))->not->toContain('Schema::create(')->not->toContain('->fullText(');
    }

    $sentinel = 'مصطلح-فريد-للبحث-'.bin2hex(random_bytes(3));
    $this->getJson(route('search.suggest', ['q' => $sentinel]))->assertOk();
    $this->get(route('search.index', ['q' => $sentinel]))->assertOk();
    $store = \Illuminate\Support\Facades\Cache::getStore();
    $property = new ReflectionProperty($store, 'storage');
    $property->setAccessible(true);
    expect(json_encode(array_keys($property->getValue($store))))->not->toContain('مصطلح-فريد');

    foreach (e27_code_files() as $file) {
        expect(preg_match('/\bLog::|\blogger\(|\breport\(|\binfo\(|\bwarning\(|\berror_log\(|\bdump\(|\bdd\(|\bvar_dump\(|\bprint_r\(/', e26_php_code($file)))->toBe(0, e26_relative($file));
    }
});

test('S10: query values only travel as bindings - the raw SQL fragments in the search sources are built from validated identifiers and constants', function () {
    $source = e26_php_code(base_path('app/Services/Search/Sources/EloquentSource.php'));

    // الأسطر التي تبني نص SQL لا تذكر مدخل المستخدم؛ القيم تذهب في مصفوفات الربط فقط.
    preg_match_all('/\$(?:rawLike|foldLike|tierSql)\s*=\s*[^;]+;/', $source, $assignments);
    expect($assignments[0])->not->toBe([]);
    foreach ($assignments[0] as $line) {
        expect($line)->not->toContain('$query')->not->toContain('$term')->not->toContain('request(')->not->toContain('$_GET');
    }
    expect($source)->toContain('TextFold::identifier(')->toContain('TextFold::sqlFold(');

    foreach (e27_code_files() as $file) {
        if (str_ends_with($file, 'EloquentSource.php') || str_ends_with($file, 'TextFold.php')) {
            continue;
        }
        // أي استدعاء خام آخر: نص ثابت بلا استيفاء متغيّرات.
        preg_match_all('/(?:whereRaw|orWhereRaw|selectRaw|orderByRaw|havingRaw|groupByRaw|DB::raw)\(\s*(["\'])(.*?)\1/s', e26_php_code($file), $raw, PREG_SET_ORDER);
        foreach ($raw as $match) {
            expect($match[2])->not->toMatch('/\$[a-zA-Z_]|\{\$/', e26_relative($file).': '.$match[2]);
        }
    }

    foreach (['app/Services/Search/TextFold.php'] as $relative) {
        expect(e26_php_code(base_path($relative)))->toContain('/^[a-z_][a-z0-9_]*(\\.[a-z_][a-z0-9_]*)?$/');
    }
});

test('S11: the search and discovery code is read-only - no create/insert/update/delete/save/increment and no raw writes', function () {
    foreach (e27_code_files() as $file) {
        $code = e26_php_code($file);

        expect(preg_match('/->(?:create|insert|insertGetId|insertOrIgnore|update|updateOrCreate|upsert|delete|forceDelete|save|saveQuietly|increment|decrement|touch|forceFill|fill)\(|::(?:create|insert|upsert)\(|DB::(?:insert|update|delete|statement|unprepared)\(/', $code))->toBe(0, e26_relative($file));
    }
});

test('S12: trending and recommendations are separated from every reward system - no economy, XP, quest, streak, achievement, redemption, odds or score class is referenced', function () {
    foreach (e27_code_files() as $file) {
        $code = e26_php_code($file);

        expect(preg_match('/\b(?:Wallet\w*|Currency\w*|Xp\w*|Redemption\w*|Quest\w*|Streak\w*|Achievement\w*|Purchase\w*|Economy\w*|\w*RewardService|\w*Odds\w*|\w*Qualification\w*|Sponsor\w*|AdPlacement\w*|Payment\w*|Stripe\w*)\b/', $code, $m))->toBe(0, e26_relative($file).' -> '.($m[0] ?? ''));
    }
});

test('S13: puzzle search is title-only - the search layer never mentions answers, hints, choices, solution data, game config or the prompt', function () {
    foreach (e26_php_files(['app/Services/Search']) as $file) {
        expect(preg_match('/answer_hash|answer_raw|answer_normal|\bhint\b|solution_data|choices|game_config|\bprompt\b|image_path/i', e26_php_code($file)))->toBe(0, e26_relative($file));
    }
});

test('S14: no external search engine, vector store or AI dependency was added - composer and npm manifests stay clean', function () {
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true);
    $npm = json_decode((string) file_get_contents(base_path('package.json')), true);
    $deps = array_keys(array_merge($composer['require'] ?? [], $composer['require-dev'] ?? [], $npm['dependencies'] ?? [], $npm['devDependencies'] ?? []));

    foreach ($deps as $dependency) {
        expect($dependency)->not->toMatch('/scout|meilisearch|algolia|typesense|elastic|opensearch|solr|sphinx|tntsearch|fuse\.?js|lunr|flexsearch|minisearch|pgvector|qdrant|pinecone|weaviate|openai|anthropic|langchain|transformers/i');
    }
    expect(config('search.driver'))->toBe('database');
});

test('S15: the search backend is an abstraction - an unknown driver falls back to the database one instead of breaking the site', function () {
    config(['search.driver' => 'some-future-engine']);
    app()->forgetInstance(\App\Services\Search\Contracts\SearchBackend::class);

    expect(app(\App\Services\Search\Contracts\SearchBackend::class))->toBeInstanceOf(\App\Services\Search\DatabaseSearchBackend::class);
});

test('S16: self-play and fake accounts cannot manufacture trending - thirty unverified accounts and one account with a thousand attempts rank nothing', function () {
    $this->withoutMiddleware(ThrottleRequests::class);
    $target = e27_puzzle('أحجية مستهدفة');
    foreach (range(1, 30) as $i) {
        e27_attempt(e27_user(['email_verified_at' => null]), $target, true, now()->subHour());
    }
    $bot = e27_user();
    DB::table('puzzle_attempts')->insert(array_map(fn ($n) => ['user_id' => $bot->id, 'puzzle_id' => $target->id, 'attempt_number' => (($n - 1) % 250) + 1, 'is_correct' => false, 'used_hint' => false, 'context_type' => null, 'created_at' => now()->subHour(), 'updated_at' => now()->subHour()], range(1, 1000)));

    expect(e27_trending()->puzzleScores())->toBe([]);
    $html = $this->get(route('discover.index'))->assertOk()->getContent();
    expect(uiXpath($html)->query('//section[@data-discover-section="trending"]')->length)->toBe(0);
});
