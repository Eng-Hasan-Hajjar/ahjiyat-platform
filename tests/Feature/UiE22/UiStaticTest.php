<?php

require_once __DIR__.'/UiTestHelpers.php';

use App\Models\ChatThread;
use App\Services\Chat\ChatThreadService;

beforeEach(fn () => $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class));

test('S1: no named route from before the redesign was lost', function () {
    $baseline = json_decode(file_get_contents(__DIR__.'/route-baseline.json'), true)['names'];
    $current = collect(app('router')->getRoutes()->getRoutes())->map->getName()->filter()->all();

    expect($baseline)->toHaveCount(139)->and(array_values(array_diff($baseline, $current)))->toBe([]);
});

test('S2: the navigation builder keeps every legacy destination - in the header, the more menu or the account menu', function () {
    $all = collect(\App\Support\NavigationMenu::build(\App\Models\User::factory()->create(), array_fill_keys(['show_puzzles_link', 'show_seasons_link', 'show_challenges_link', 'show_leaderboard_link'], true)))
        ->flatMap(fn ($section) => isset($section[0]['items']) ? collect($section)->flatMap->items : collect($section))->pluck('route')->filter()->unique()->all();

    foreach (['puzzles.index', 'seasons.index', 'challenges.index', 'competitions.index', 'teams.index', 'leaderboard.index', 'store.index', 'wallet.index', 'redemption.index', 'inventory.index', 'friends.index', 'messages.index', 'notifications.index', 'profile.edit'] as $route) {
        expect($all)->toContain($route);
    }
});

test('S3: the chrome components use SVG icons, never emoji', function () {
    foreach (['layouts/partials/header.blade.php', 'layouts/partials/bottom-nav.blade.php', 'layouts/partials/flash.blade.php', 'components/nav-icon.blade.php', 'components/menu.blade.php', 'components/menu-link.blade.php',
        'components/stat-card.blade.php', 'components/page-header.blade.php', 'components/section-header.blade.php', 'components/empty-state.blade.php', 'components/profile-hero.blade.php', 'components/ui-icon.blade.php'] as $file) {
        expect(preg_match('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{26FF}\x{2B50}\x{2705}\x{274C}]/u', uiView($file)))->toBe(0, $file.' has emoji');
    }
});

test('S4: interactive chrome has a keyboard focus ring and respects reduced motion', function () {
    foreach (['components/nav-icon.blade.php', 'components/menu.blade.php', 'components/menu-link.blade.php', 'layouts/partials/bottom-nav.blade.php', 'layouts/partials/header.blade.php'] as $file) {
        expect(uiView($file))->toContain('focus-visible:')->and(uiView($file))->toContain('motion-reduce:');
    }
});

test('S5: every statically named icon used by a view or the navigation builder exists in the icon set', function () {
    preg_match_all("/'([\w-]+)' => \[\s*'/", uiView('components/ui-icon.blade.php'), $set);
    $known = $set[1];
    $used = [];

    $files = array_merge(glob(resource_path('views/**/*.blade.php')), glob(resource_path('views/*/*/*.blade.php')), glob(resource_path('views/*/*.blade.php')), [app_path('Support/NavigationMenu.php')]);

    foreach (array_unique($files) as $file) {
        $code = file_get_contents($file);
        preg_match_all('/<x-ui-icon\s+name="([\w-]+)"/', $code, $a);
        preg_match_all("/(?:'icon'\s*=>|\\\$item\(\s*'[\w-]+',\s*'[\w.-]+',)\s*'([\w-]+)'/", $code, $b);
        preg_match_all('/<x-(?:stat-card|empty-state|page-header|menu-link|nav-icon)\b[^>]*?\bicon="([\w-]+)"/s', $code, $c);
        $used = array_merge($used, $a[1], $b[1], $c[1]);
    }

    $used = array_unique($used);
    expect(count($known))->toBeGreaterThan(30)->and(array_values(array_diff($used, $known)))->toBe([]);
});

test('S6: the chat sidebar renders on the room page and never creates a chat room just by viewing', function () {
    [$a, $b] = chatFriends();
    chatDm($this, $b, $a, 'مرحبا');
    ChatThread::where('type', ChatThread::TYPE_GLOBAL)->delete();
    $before = ChatThread::count();

    $x = uiXpath($this->actingAs($a)->get(route('messages.direct', $b))->assertOk()->getContent());

    expect(ChatThread::count())->toBe($before)->and(ChatThread::where('type', ChatThread::TYPE_GLOBAL)->count())->toBe(0)
        ->and(uiAttrs($x, '//aside[@aria-label="قائمة المحادثات"]//nav[@aria-label="المحادثات"]//a[@aria-current="page"]', 'href'))->toBe([route('messages.direct', $b)])
        ->and(uiAttrs($x, '//header//a[@aria-label="كل المحادثات"]', 'href'))->toBe([route('messages.index')]);
});

test('S7: flash messages are unified - icon, role and the same text for success, error and hint', function () {
    $user = e16User();
    $x = uiXpath($this->actingAs($user)->withSession(['success' => 'تم الحفظ بنجاح', 'error' => 'حدث خطأ ما', 'hint' => 'جرّب ثانية'])->get(route('home'))->getContent());

    expect(uiAttrs($x, '//main//div[@role="status"]', 'role'))->toHaveCount(2)->and(uiAttrs($x, '//main//div[@role="alert"]', 'role'))->toHaveCount(1)
        ->and($x->query('//main//div[@role="status" or @role="alert"]//svg[@aria-hidden="true"]')->length)->toBe(3);
});

test('S8: the admin panel boundary is unchanged - guests are sent to login and plain players are refused', function () {
    $this->get('/admin')->assertRedirect();
    $this->actingAs(e16User())->get('/admin')->assertForbidden();
});

test('S9: the theme mechanism is intact - the inline script, the storage key and an accessible switcher', function () {
    $html = $this->get(route('home'))->assertOk()->getContent();

    expect($html)->toContain('ahjiyat-theme')->toContain('data-theme');
    expect(uiView('components/theme-switcher.blade.php'))->toContain('title=')->toContain('aria-hidden="true"');
});

test('S10: light theme keeps contrast overrides for the accent text colors', function () {
    $css = file_get_contents(resource_path('css/app.css'));

    foreach (['.text-gold', '.text-emerald', '.text-rose'] as $class) {
        expect($css)->toContain(':root[data-theme="light"] '.$class.' {');
    }
});
