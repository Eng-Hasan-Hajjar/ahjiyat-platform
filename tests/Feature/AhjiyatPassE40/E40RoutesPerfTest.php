<?php

use App\Filament\Pages\AhjiyatPassPurchasesPage;
use App\Models\DigitalPurchase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

require_once __DIR__.'/_support.php';

/*
| E40 / AX16 المسارات، AX17 الأداء.
*/

beforeEach(fn () => e40_seed());

/** @return \Illuminate\Support\Collection<int, RoutingRoute> */
function e40_pass_routes()
{
    return collect(Route::getRoutes()->getRoutes())->filter(fn (RoutingRoute $r) => str_starts_with($r->uri(), 'pass') && str_starts_with((string) $r->getName(), 'pass.'));
}

function e40_queries(callable $fn): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $fn();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

// ───────────────────────────── AX16: المسارات ─────────────────────────────

test('AX16-101: every public Pass GET is read-only — it never grants, claims, purchases or moves value', function () {
    $b = e40_build();
    $user = e40_player();
    e40_reach($user, $b, 250);
    $before = e40_counts();
    $economy = e40_economy($user);

    $urls = [route('pass.index'), route('pass.index'), route('pass.premium'), route('pass.archive')];
    $order = e40_premium()->request($user, $b['pass']);
    $before['purchases']++;
    $before['events'] = DB::table('digital_purchase_events')->count();

    foreach ($urls as $url) {
        $status = $this->actingAs($user)->get($url)->status();
        expect(in_array($status, [200, 302], true))->toBeTrue($url.' => '.$status);
    }

    // لمن لديه طلب مفتوح تُحوِّل صفحة المميز إلى طلبه (لا تنشئ طلبًا ثانيًا).
    $this->actingAs($user)->get(route('pass.premium'))->assertRedirect(route('pass.orders.show', $order->public_id));
    expect($this->actingAs($user)->get(route('pass.orders.show', $order->public_id))->status())->toBe(200);
    $this->actingAs($user)->get(route('pass.archive.show', $b['pass']->public_id))->assertNotFound();

    $after = e40_counts();
    expect($after['grants'])->toBe($before['grants'])->and($after['claims'])->toBe($before['claims'])->and($after['purchases'])->toBe($before['purchases'])
        ->and($after['events'])->toBe($before['events'])->and($after['progress'])->toBe(1)
        ->and(e40_economy($user))->toBe($economy);
});

test('AX16-101b: GET /pass is idempotent — repeated reads change nothing, lazily creating one progress row at most', function () {
    $b = e40_build();
    $user = e40_player();

    foreach (range(1, 4) as $_) {
        $this->actingAs($user)->get(route('pass.index'))->assertSuccessful();
    }

    expect(e40_counts()['progress'])->toBe(1)->and(e40_counts()['claims'])->toBe(0)->and(e40_row($user, $b['pass'])->pass_points)->toBe(0);
});

test('AX16-102: every state change is a POST — no GET/PUT/PATCH/DELETE route mutates the pass', function () {
    $mutating = ['pass.collect', 'pass.collect_all', 'pass.orders.store', 'pass.orders.submit', 'pass.orders.cancel'];
    $routes = e40_pass_routes();

    foreach ($mutating as $name) {
        $route = $routes->first(fn ($r) => $r->getName() === $name);
        expect($route)->not->toBeNull()->and($route->methods())->toBe(['POST']);
    }

    foreach ($routes as $route) {
        expect(array_diff($route->methods(), ['GET', 'HEAD', 'POST']))->toBe([]);

        if (in_array('GET', $route->methods(), true)) {
            expect($mutating)->not->toContain($route->getName());
        }
    }

    $this->actingAs(e40_player())->get('/pass/collect-all')->assertStatus(405);
    $this->actingAs(e40_player())->get('/pass/orders')->assertStatus(405);
});

test('AX16-103: every POST route goes through the CSRF-protected web group, and none is exempted', function () {
    $csrf = fn ($route) => collect(app('router')->gatherRouteMiddleware($route))->contains(fn ($m) => is_string($m) && str_contains($m, 'CsrfToken'));

    foreach (e40_pass_routes()->filter(fn ($r) => in_array('POST', $r->methods(), true)) as $route) {
        expect($csrf($route))->toBeTrue($route->getName().' lacks CSRF');
    }

    $token = file_exists(base_path('app/Http/Middleware/VerifyCsrfToken.php')) ? file_get_contents(base_path('app/Http/Middleware/VerifyCsrfToken.php')) : '';
    $bootstrap = file_get_contents(base_path('bootstrap/app.php'));

    expect($token.$bootstrap)->not->toMatch('/validateCsrfTokens\s*\(\s*except[^)]*pass/i')->not->toMatch('/\$except[^;]*pass/i');
});

test('AX16-103b: the access gate — guests, unverified and frozen accounts reach nothing; the kill switch hides the player pages', function () {
    $b = e40_build();
    $pass = $b['pass'];
    $order = e40_premium()->request($owner = e40_player(), $pass);
    $gets = [route('pass.index'), route('pass.premium'), route('pass.archive'), route('pass.orders.show', $order->public_id)];

    foreach ($gets as $url) {
        $this->get($url)->assertRedirect(route('login'));
    }

    $this->post(route('pass.collect_all'))->assertRedirect(route('login'));

    $unverified = e16User(['email_verified_at' => null]);
    $this->actingAs($unverified)->get(route('pass.index'))->assertRedirect(route('verification.notice'));

    $frozen = e40_player();
    $frozen->forceFill(['is_frozen' => true])->save();
    $this->actingAs($frozen->fresh())->get(route('pass.index'))->assertRedirect(route('account.safety.index'));

    config(['ahjiyat_pass.enabled' => false]);
    foreach ($gets as $url) {
        $this->actingAs($owner)->get($url)->assertNotFound();
    }
});

test('AX16-104: Pass routes obey the E18 reward-security naming guard (the original guard test stays green and unmodified)', function () {
    foreach (e40_pass_routes() as $route) {
        expect(strtolower($route->uri().'|'.$route->getName()))->not->toMatch('/(reward|grant|claim|payout|prize)/');

        foreach ($route->parameterNames() as $parameter) {
            expect(strtolower($parameter))->not->toMatch('/(reward|grant|claim|payout|prize)/');
        }
    }

    $guard = file_get_contents(base_path('tests/Feature/CompetitiveRewards/RewardSecurityEconomyTest.php'));
    expect($guard)->toContain('(reward|grant|claim|payout|prize)');
});

test('AX16-105: no economic player route exists — no gifting, trading, selling, renewal, withdrawal, cash, loot or spend ranking', function () {
    $routes = e40_pass_routes();
    expect($routes->count())->toBeGreaterThanOrEqual(9);

    foreach ($routes as $route) {
        expect(strtolower($route->uri().'|'.$route->getName()))->not->toMatch('/(gift|trade|transfer|sell|resell|renew|subscri|withdraw|cash|loot|crate|box|spend|leader|rank|wallet|gem|coin|bundle|skip|boost|buy-tier|unlock)/');

        // لا مسار يقبل سعرًا أو عملة أو شريحة أو أي قرار قيمة من العنوان.
        foreach ($route->parameterNames() as $parameter) {
            expect($parameter)->toBeIn(['slot', 'order', 'pass']);
        }
    }

    // لا مسار GET واحد يغيّر محفظة/مقتنيات/استحقاقات (لا يظهر أي مصدر قيمة في المتحكّمات للقراءة).
    $b = e40_build(['price' => 500, 'currency' => 'USD']);
    $user = e40_player();
    $this->actingAs($user)->post(route('pass.orders.store'), ['price' => 1, 'premium_price_minor' => 1, 'currency' => 'EUR', 'amount' => 1, 'tier' => 99, 'status' => 'verified', 'user_id' => 999])->assertSessionHasNoErrors();
    $order = DigitalPurchase::query()->where('user_id', $user->id)->first();

    expect($order)->not->toBeNull()->and($order->amount_minor)->toBe(500)->and($order->currency)->toBe('USD')->and($order->status)->not->toBe('verified')
        ->and(e40_row($user, $b['pass'])?->premium_unlocked_at)->toBeNull();
});

test('AX16-105b: the rate limiters used by the Pass routes are all defined', function () {
    $needed = ['pass-read', 'pass-collect', 'pass-order', 'pass-submit', 'pass-proof'];

    foreach ($needed as $name) {
        expect(RateLimiter::limiter($name))->not->toBeNull($name);
    }

    $used = e40_pass_routes()->flatMap(fn ($r) => collect($r->gatherMiddleware())->filter(fn ($m) => is_string($m) && str_starts_with($m, 'throttle:'))->map(fn ($m) => substr($m, 9)));

    expect($used->unique()->values()->sort()->values()->all())->toBe(collect($needed)->sort()->values()->all());
});

test('AX16-105c: order-create throttling holds — the 11th order attempt in a minute is rate-limited', function () {
    $b = e40_build();
    $user = e40_player();
    RateLimiter::clear('pso:'.$user->id);
    $last = null;

    foreach (range(1, 12) as $_) {
        $last = $this->actingAs($user)->post(route('pass.orders.store'))->status();
    }

    expect($last)->toBe(429);
});

// ───────────────────────────── AX17: الأداء ─────────────────────────────

test('AX17-106: the Pass page runs a bounded number of queries', function () {
    $b = e40_build(['tiers' => 5]);
    $user = e40_player();
    e40_reach($user, $b, 250);
    Cache::flush();

    $cold = e40_queries(fn () => $this->actingAs($user)->get(route('pass.index'))->assertSuccessful());
    $warm = e40_queries(fn () => $this->actingAs($user)->get(route('pass.index'))->assertSuccessful());

    expect($cold)->toBeLessThanOrEqual(45)->and($warm)->toBeLessThanOrEqual(30);
});

test('AX17-107: the number of tiers does not add queries (no N+1 per tier)', function () {
    $small = e40_build(['tiers' => 4, 'step' => 100, 'key' => 'small-pass', 'free_tiers' => [1], 'premium_tiers' => [2]]);
    $one = e40_player();
    Cache::flush();
    $few = e40_queries(fn () => $this->actingAs($one)->get(route('pass.index'))->assertSuccessful());

    e40_life()->end($small['admin'], $small['pass'], 'تجربة التوسّع');
    $big = e40_build(['tiers' => 45, 'step' => 20, 'key' => 'big-pass', 'starts' => now()->subHour(), 'free_tiers' => [1], 'premium_tiers' => [2], 'missions' => [['difficulty' => 'easy', 'points' => 100, 'max' => 14]]]);
    $two = e40_player();
    Cache::flush();
    $many = e40_queries(fn () => $this->actingAs($two)->get(route('pass.index'))->assertSuccessful());

    expect(DB::table('ahjiyat_pass_tiers')->where('ahjiyat_pass_id', $big['pass']->id)->count())->toBe(45)->and($many)->toBe($few);
});

test('AX17-108: the number of rewards does not add queries (no N+1 per reward or per claim)', function () {
    $bare = e40_build(['tiers' => 12, 'step' => 20, 'key' => 'bare-pass', 'free_tiers' => [1], 'premium_tiers' => [2]]);
    $one = e40_player();
    Cache::flush();
    $few = e40_queries(fn () => $this->actingAs($one)->get(route('pass.index'))->assertSuccessful());

    e40_life()->end($bare['admin'], $bare['pass'], 'تجربة المكافآت');
    $rich = e40_build(['tiers' => 12, 'step' => 20, 'key' => 'rich-pass', 'starts' => now()->subHour(), 'free_tiers' => range(1, 12), 'premium_tiers' => range(1, 12), 'missions' => [['difficulty' => 'easy', 'points' => 100, 'max' => 14]]]);
    $two = e40_player();
    e40_reach($two, $rich, 120);
    e40_claims()->collectAll($two, $rich['pass']);
    $three = e40_player();
    Cache::flush();
    $many = e40_queries(fn () => $this->actingAs($three)->get(route('pass.index'))->assertSuccessful());
    Cache::flush();
    $manyClaimed = e40_queries(fn () => $this->actingAs($two)->get(route('pass.index'))->assertSuccessful());

    expect(DB::table('ahjiyat_pass_tier_rewards')->where('ahjiyat_pass_id', $rich['pass']->id)->count())->toBe(24)
        ->and($many)->toBe($few)->and($manyClaimed)->toBeLessThanOrEqual($few + 3);
});

test('AX17-108b: the home dashboard widget stays bounded regardless of pass size', function () {
    $b = e40_build(['tiers' => 30, 'step' => 20, 'free_tiers' => range(1, 30), 'premium_tiers' => range(1, 30)]);
    $user = e40_player();
    e40_reach($user, $b, 300);
    Cache::flush();

    $count = e40_queries(fn () => $this->actingAs($user)->get(route('home'))->assertSuccessful());

    expect($count)->toBeLessThanOrEqual(80);
});

test('AX17-109: the admin purchases queue is paginated and its query count does not grow with the number of rows', function () {
    $b = e40_build();
    config(['ahjiyat_pass.admin.purchases_per_page' => 5]);
    $this->actingAs(e40_staff_with(['passes.view-purchases']));

    foreach (range(1, 4) as $i) {
        $u = e40_player();
        e40_premium()->submit($u, e40_premium()->request($u, $b['pass']), 'TRX-PG-'.$i, null);
    }

    $few = e40_queries(fn () => Livewire::test(AhjiyatPassPurchasesPage::class)->set('tab', 'submitted')->assertSuccessful());

    foreach (range(5, 24) as $i) {
        $u = e40_player();
        e40_premium()->submit($u, e40_premium()->request($u, $b['pass']), 'TRX-PG-'.$i, null);
    }

    $page = null;
    $many = e40_queries(function () use (&$page) {
        $page = Livewire::test(AhjiyatPassPurchasesPage::class)->set('tab', 'submitted')->assertSuccessful();
    });

    expect($page->instance()->data()['page']->total())->toBe(24)->and(count($page->instance()->data()['page']->items()))->toBe(5)->and($many)->toBeLessThanOrEqual($few + 2);
});

test('AX17-110: the point-source lookup is indexed — unique source key plus the pass/mission and progress indexes', function () {
    $indexes = fn (string $table) => collect(Schema::getIndexes($table))->map(fn ($i) => ['name' => $i['name'], 'columns' => $i['columns'], 'unique' => $i['unique']]);

    $grants = $indexes('pass_point_grants');
    expect($grants->contains(fn ($i) => $i['unique'] && $i['columns'] === ['source_key']))->toBeTrue()
        ->and($grants->contains(fn ($i) => $i['columns'] === ['user_pass_progress_id', 'created_at']))->toBeTrue()
        ->and($grants->contains(fn ($i) => $i['columns'] === ['ahjiyat_pass_id', 'ahjiyat_pass_mission_id']))->toBeTrue();

    $progress = $indexes('user_pass_progress');
    expect($progress->contains(fn ($i) => $i['unique'] && $i['columns'] === ['user_id', 'ahjiyat_pass_id']))->toBeTrue()
        ->and($progress->contains(fn ($i) => $i['columns'] === ['ahjiyat_pass_id', 'highest_tier']))->toBeTrue();

    $claims = $indexes('user_pass_reward_claims');
    expect($claims->contains(fn ($i) => $i['unique'] && $i['columns'] === ['user_id', 'ahjiyat_pass_tier_reward_id']))->toBeTrue();

    $purchases = $indexes('digital_purchases');
    expect($purchases->contains(fn ($i) => $i['unique'] && $i['columns'] === ['open_key']))->toBeTrue()
        ->and($purchases->contains(fn ($i) => $i['columns'] === ['status', 'created_at']))->toBeTrue();
});

test('AX17-110b: the grant lookup uses the source-key index (no table scan) on the real query plan', function () {
    if (DB::getDriverName() !== 'sqlite') {
        $this->markTestSkipped('خطة التنفيذ تُفحص على SQLite هنا؛ MySQL يُفحص في خطوة CI المخصصة.');
    }

    $plan = collect(DB::select("EXPLAIN QUERY PLAN SELECT id FROM pass_point_grants WHERE source_key = 'pass:1:u:1:uqp:1'"))->map(fn ($r) => strtolower(json_encode($r)))->implode(' ');

    expect($plan)->toContain('pass_point_grants_source_key_unique')->not->toContain('scan pass_point_grants');
});
