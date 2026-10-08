<?php

require_once __DIR__.'/UiTestHelpers.php';

use App\Models\User;
use App\Services\Chat\ChatUnreadService;
use App\Services\Dashboard\PlayerDashboardService;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class));

test('D1: the guest home stays a public page without the player dashboard', function () {
    $response = $this->get(route('home'))->assertOk()->assertDontSee('ما يهمني الآن')->assertDontSee('مرحبًا بعودتك');

    expect($response->viewData('dashboard'))->toBeNull()->and(uiAttrs(uiXpath($response->getContent()), '//header//a', 'href'))->toContain(route('login'));
});

test('D2: the player home is a dashboard - greeting, six status cards in order, and the what-matters-now section', function () {
    $user = e16User(['name' => 'سلمى الخطيب']);
    $html = $this->actingAs($user)->get(route('home'))->assertOk()->assertSee('سلمى الخطيب')->assertSee('مرحبًا بعودتك')->assertSee('ما يهمني الآن')->getContent();
    $x = uiXpath($html);
    $labels = array_map(fn ($n) => trim($n->textContent), iterator_to_array($x->query('//section[@aria-label="حالتي السريعة"]//a//span[contains(@class,"text-xs")]')));

    expect(array_map(fn ($l) => preg_replace('/\s+/u', ' ', $l), $labels))->toContain('المستوى')->toContain('مهام اليوم')->toContain('سلسلة الأيام')->toContain('رصيد الجواهر')->toContain('الفرق')->toContain('رسائل غير مقروءة');
});

test('D3: a player with no team, no event and no season gets an honest empty state and a single clear action', function () {
    $x = uiXpath($this->actingAs(e16User())->get(route('home'))->assertOk()->assertSee('ابدأ رحلتك')->assertSee('انضم لفريق')->getContent());

    expect(uiAttrs($x, '//*[@aria-labelledby="focus-title"]//a', 'href'))->toBe([route('puzzles.index')]);
});

test('D4: an unverified player is pointed to verification instead of messages', function () {
    $this->actingAs(e16User(['email_verified_at' => null]))->get(route('home'))->assertOk()->assertSee('وثّق بريدك')->assertDontSee('رسائل غير مقروءة');
});

test('D5: the unread card equals the chat unread total and never shows a private message text', function () {
    [$a, $b] = chatFriends();
    chatDm($this, $b, $a, 'نص سري لا يظهر في اللوحة');
    chatDm($this, $b, $a, 'ثانية');

    $x = uiXpath($this->actingAs($a)->get(route('home'))->assertOk()->assertDontSee('نص سري لا يظهر في اللوحة')->getContent());
    $value = trim($x->query('//section[@aria-label="حالتي السريعة"]//a[contains(@href, "messages")]//span[contains(@class,"font-display")]')->item(0)->textContent);

    expect($value)->toBe((string) app(ChatUnreadService::class)->total($a))->and($value)->toBe('2');
});

test('D6/D7/D8: with the demo data the dashboard lists urgent cards first, costs a bounded number of queries, and writes nothing to domain tables', function () {
    $this->seed(\Database\Seeders\DemoQaSeeder::class);
    $yousef = User::where('email', 'yousef@ahjiyat.test')->firstOrFail();
    $yousef->load('wallet');

    DB::flushQueryLog();
    DB::enableQueryLog();
    $data = app(PlayerDashboardService::class)->forUser($yousef);
    $serviceQueries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect(array_column($data['urgent'], 'type'))->toBe(['competition', 'team_play', 'team_incoming'])
        ->and(array_column($data['info'], 'type'))->toBe(['championship'])
        ->and($serviceQueries)->toBeLessThanOrEqual(20)->and($data['wallet'])->toBeGreaterThan(0);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $html = $this->actingAs($yousef)->get(route('home'))->assertOk()->getContent();
    $writes = collect(DB::getQueryLog())->pluck('query')->filter(fn ($q) => preg_match('/^\s*(insert into|update|delete from)\s+"?(\w+)"?/i', $q, $m) === 1 && ! in_array($m[2], ['device_sightings', 'sessions', 'fraud_flags', 'cache'], true))->values()->all();
    DB::disableQueryLog();

    $x = uiXpath($html);
    $cards = uiAttrs($x, '//*[@aria-labelledby="focus-title"]//*[@data-card]', 'data-card');

    expect($cards)->toContain('competition')->toContain('team_play')->toContain('team_incoming')->toContain('continue')
        ->and(array_search('competition', $cards))->toBeLessThan(array_search('continue', $cards))
        ->and($writes)->toBe([]);
});
