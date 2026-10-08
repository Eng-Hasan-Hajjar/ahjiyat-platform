<?php

require_once __DIR__.'/UiTestHelpers.php';

use App\Models\User;
use App\Services\PlatformSettingsService;
use App\Support\NavigationMenu;

beforeEach(fn () => $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class));

test('N1: a verified player gets the six primary icon destinations in order, each with an Arabic aria-label', function () {
    $html = $this->actingAs(e16User())->get(route('home'))->assertOk()->getContent();

    expect(uiPrimaryLabels($html))->toBe(['الرئيسية', 'الأحجيات', 'المنافسات', 'الفرق', 'الأصدقاء', 'الرسائل']);
});

test('N2: an unverified player never sees friends or messages, nor the identity customization link', function () {
    $html = $this->actingAs(e16User(['email_verified_at' => null]))->get(route('home'))->assertOk()->getContent();
    $x = uiXpath($html);

    expect(uiPrimaryLabels($html))->toBe(['الرئيسية', 'الأحجيات', 'المنافسات', 'الفرق'])
        ->and(uiAttrs($x, '//div[@role="menu"][@aria-label="قائمة الحساب"]//a', 'href'))->not->toContain(route('profile.customize'))
        ->and(uiAttrs($x, '//header//a', 'href'))->not->toContain(route('messages.index'))->not->toContain(route('friends.index'));
});

test('N3: the guest header is a simple public shell - text links, login and register - without a player shell', function () {
    $html = $this->get(route('home'))->assertOk()->getContent();
    $x = uiXpath($html);
    $links = uiAttrs($x, '//nav[@aria-label="التنقل الرئيسي"]//a', 'href');

    expect($links)->toContain(route('puzzles.index'))->toContain(route('competitions.index'))->toContain(route('teams.index'))
        ->and(uiAttrs($x, '//header//a', 'href'))->toContain(route('login'))->toContain(route('register'))
        ->and($x->query('//nav[@aria-label="التنقل السريع"]')->length)->toBe(0)
        ->and($x->query('//*[@aria-label="قائمة الحساب"]')->length)->toBe(0)
        ->and($x->query('//*[@data-bell-badge]')->length)->toBe(0);
});

test('N4: every destination of the pre-redesign navbar is still reachable from the new navigation', function () {
    $user = e16User();
    $hrefs = uiAttrs(uiXpath($this->actingAs($user)->get(route('home'))->getContent()), '//a', 'href');

    foreach (['puzzles.index', 'seasons.index', 'challenges.index', 'competitions.index', 'teams.index', 'leaderboard.index', 'store.index', 'wallet.index', 'redemption.index',
        'inventory.index', 'friends.index', 'messages.index', 'notifications.index', 'profile.edit', 'profile.customize', 'progress.show', 'quests.show', 'team-championships.index', 'competitions.hall-of-fame'] as $name) {
        expect($hrefs)->toContain(route($name));
    }

    expect($hrefs)->toContain(route('players.show', $user))->toContain(route('profile.edit').'#privacy')
        ->and(uiAttrs(uiXpath($this->actingAs($user)->get(route('home'))->getContent()), '//form[@method="POST"]', 'action'))->toContain(route('logout'));
});

test('N5: the platform navigation toggles are respected in the header, the more menu and the mobile sheet', function () {
    $settings = app(PlatformSettingsService::class);
    $settings->setMany('navigation', ['show_puzzles_link' => false, 'show_seasons_link' => false, 'show_challenges_link' => false, 'show_leaderboard_link' => false]);

    $x = uiXpath($this->actingAs(e16User())->get(route('teams.index'))->assertOk()->getContent());
    $navHrefs = array_merge(uiAttrs($x, '//header//a', 'href'), uiAttrs($x, '//nav[@aria-label="التنقل السريع"]//a', 'href'), uiAttrs($x, '//div[@role="dialog"]//a', 'href'));

    expect($navHrefs)->not->toContain(route('puzzles.index'))->not->toContain(route('seasons.index'))->not->toContain(route('challenges.index'))->not->toContain(route('leaderboard.index'))
        ->and($navHrefs)->toContain(route('competitions.index'))->toContain(route('store.index'));
});

test('N6: chrome controls are accessible - every icon-only link or button has a name, and every svg is decorative', function () {
    $friend = e16User();
    $user = e16User();
    e16Befriend($user, $friend);
    chatDm($this, $friend, $user, 'مرحبا');

    foreach ([route('home'), route('messages.index'), route('profile.edit'), route('store.index'), route('friends.index'), route('players.show', $user)] as $url) {
        $x = uiXpath($this->actingAs($user)->get($url)->assertOk()->getContent());

        expect(uiUnnamedControls($x, '//header'))->toBe([], "header @ {$url}")
            ->and(uiUnnamedControls($x, '//nav[@aria-label="التنقل السريع"]'))->toBe([], "bottom nav @ {$url}")
            ->and($x->query('//header//svg[not(@aria-hidden="true")]')->length)->toBe(0, "svg @ {$url}");
    }
});

test('N7: exactly one primary item is active, and chat routes mark Messages - not Teams', function () {
    $user = e16User();
    $team = e19Team($user);
    $active = fn (string $url) => uiAttrs(uiXpath($this->actingAs($user)->get($url)->assertOk()->getContent()), '//nav[@aria-label="التنقل الرئيسي"]/a[@aria-current="page"]', 'aria-label');

    expect($active(route('competitions.index')))->toBe(['المنافسات'])
        ->and($active(route('teams.index')))->toBe(['الفرق'])
        ->and($active(route('community.chat')))->toBe(['الرسائل'])
        ->and($active(route('teams.chat', $team)))->toBe(['الرسائل'])
        ->and($active(route('home')))->toBe(['الرئيسية']);
});

test('N8: the header shell is fluid while the content shell is constrained, and pages can widen or narrow the content', function () {
    $header = uiView('layouts/partials/header.blade.php');

    expect(preg_match('/\bmax-w-(?:xs|sm|md|lg|xl|\dxl|screen|prose|\[)/', $header))->toBe(0)->and($header)->toContain('w-full px-4 lg:px-6');

    $user = e16User();
    expect($this->actingAs($user)->get(route('home'))->getContent())->toContain('mx-auto w-full max-w-6xl')
        ->and($this->actingAs($user)->get(route('profile.customize'))->getContent())->toContain('mx-auto w-full max-w-5xl')
        ->and($this->actingAs($user)->get(route('players.show', $user))->getContent())->toContain('mx-auto w-full max-w-4xl');
});

test('N9: the mobile bottom navigation has four destinations plus More for players only, and carries the unread badge', function () {
    [$a, $b] = chatFriends();
    chatDm($this, $b, $a, 'غير مقروءة');

    $x = uiXpath($this->actingAs($a)->get(route('home'))->getContent());
    $items = $x->query('//nav[@aria-label="التنقل السريع"]//li');

    expect($items->length)->toBe(5)
        ->and(uiAttrs($x, '//nav[@aria-label="التنقل السريع"]//button', 'aria-label'))->toBe(['المزيد من الأقسام'])
        ->and($x->query('//nav[@aria-label="التنقل السريع"]//*[@aria-label="رسائل غير مقروءة"]')->length)->toBe(1);
});

test('N10: the account menu links the profile, the identity, the settings and the privacy, with a CSRF-protected logout - and admin only with the permission', function () {
    $user = e16User();
    $x = uiXpath($this->actingAs($user)->get(route('home'))->getContent());

    expect(uiAttrs($x, '//div[@role="menu"][@aria-label="قائمة الحساب"]//a', 'href'))->toBe([route('players.show', $user), route('profile.customize'), route('profile.edit'), route('profile.edit').'#privacy'])
        ->and($x->query('//div[@role="menu"][@aria-label="قائمة الحساب"]//form[@method="POST"][contains(@action, "logout")]//input[@name="_token"]')->length)->toBe(1);

    $admin = e16User();
    $admin->givePermissionTo('admin.access');
    $adminHrefs = uiAttrs(uiXpath($this->actingAs($admin)->get(route('home'))->getContent()), '//div[@role="menu"][@aria-label="قائمة الحساب"]//a', 'href');

    expect($adminHrefs)->toContain(url('/admin'))->and(uiAttrs($x, '//div[@role="menu"][@aria-label="قائمة الحساب"]//a', 'href'))->not->toContain(url('/admin'));
});

test('N11: the navigation builder is presentation-only - guests get public links, players get the account entries, and toggles apply', function () {
    $guest = NavigationMenu::build(null, ['show_puzzles_link' => false, 'show_seasons_link' => true, 'show_challenges_link' => true, 'show_leaderboard_link' => true]);

    expect(collect($guest['guest'])->pluck('key')->all())->toBe(['seasons', 'competitions', 'teams', 'leaderboard', 'store'])->and($guest['account'])->toBe([]);

    $player = NavigationMenu::build(User::factory()->create(), ['show_puzzles_link' => true]);

    expect(collect($player['account'])->pluck('key')->all())->toBe(['profile', 'customize', 'settings', 'privacy'])
        ->and(collect($player['primary'])->pluck('key')->all())->toBe(['home', 'puzzles', 'competitions', 'teams', 'friends', 'messages'])
        ->and(collect($player['bottom'])->pluck('key')->all())->toBe(['home', 'puzzles', 'competitions', 'messages']);
});
