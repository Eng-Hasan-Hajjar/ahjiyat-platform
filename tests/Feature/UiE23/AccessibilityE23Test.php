<?php

require_once __DIR__.'/UiE23Helpers.php';
require_once __DIR__.'/../Chat/ChatTestHelpers.php';

beforeEach(fn () => $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class));

test('AX1: every chat room passes the page-level accessibility baseline - language, landmark, skip link first, one h1, named controls, labelled fields', function () {
    [$a, $b] = chatFriends();
    chatDm($this, $b, $a, 'مرحبا');
    [$team, , , $member] = chatTeam();

    foreach ([
        [$a, route('community.chat')],
        [$a, route('messages.direct', $b)],
        [$member, route('teams.chat', $team)],
        [$a, route('messages.index')],
    ] as [$user, $url]) {
        expect(e23A11yProblems($this->actingAs($user)->get($url)->assertOk()->getContent()))->toBe([], $url);
    }
});

test('AX2: the chat log is a polite live region with a name, focusable for keyboard scrolling; errors are alerts and notices are statuses', function () {
    $x = uiXpath($this->actingAs(e16User())->get(route('community.chat'))->getContent());
    $log = $x->query('//div[@role="log"]')->item(0);

    expect($log->getAttribute('aria-live'))->toBe('polite')->and($log->getAttribute('aria-label'))->toBe('رسائل المحادثة')->and($log->getAttribute('tabindex'))->toBe('0')
        ->and($x->query('//*[@role="alert"]')->length)->toBe(1)->and($x->query('//section//*[@role="status"]')->length)->toBeGreaterThan(0);
});

test('AX3: the page has exactly one h1 in every chat room - the conversations list contributes none', function () {
    [$a, $b] = chatFriends();
    chatDm($this, $b, $a, 'مرحبا');

    foreach ([route('community.chat'), route('messages.direct', $b)] as $url) {
        expect(uiXpath($this->actingAs($a)->get($url)->getContent())->query('//h1')->length)->toBe(1);
    }
});

test('AX4: every icon-only control in the header is named, and the account, notification and theme controls are all reachable by name', function () {
    $x = uiXpath($this->actingAs(e16User())->get(route('home'))->getContent());

    expect(uiUnnamedControls($x, '//header[@data-app-header]'))->toBe([])
        ->and($x->query('//header[@data-app-header]//button[@title="تبديل المظهر"]')->length)->toBe(1);
});

test('AX5: the guest mobile menu button announces its state and what it controls, and the panel it controls exists', function () {
    $x = uiXpath($this->get(route('home'))->getContent());
    $button = $x->query('//header//button[@aria-controls="guest-menu"]')->item(0);

    expect($button)->not->toBeNull()->and($button->getAttribute('aria-label'))->toBe('فتح القائمة')->and($button->getAttribute('aria-expanded'))->toBe('false')
        ->and($x->query('//*[@id="guest-menu"]')->length)->toBe(1);
});

test('AX6: focus is always visible - chat controls, header, buttons and badges-as-links have a focus-visible style', function () {
    $chat = e23Css('chat.css');
    $app = e23Css();

    foreach (['.chat-icon-btn:focus-visible', '.chat-act:focus-visible', '.chat-send:focus-visible', '.chat-new:focus-visible', '.chat-menu button:focus-visible', '.chat-input:focus'] as $selector) {
        expect($chat)->toContain($selector);
    }

    foreach (['.btn-secondary:focus-visible', '.btn-danger:focus-visible', '.btn-icon:focus-visible'] as $selector) {
        expect($app)->toContain($selector);
    }

    expect(e23Source('views/chat/room.blade.php'))->toContain('focus-visible:ring-2');
});

test('AX7: every interactive element in the chat template has a visible text, an aria-label or a title', function () {
    $room = e23Source('views/chat/room.blade.php');

    preg_match_all('/<(button|a)\b([^>]*)>(.*?)<\/\1>/su', $room, $matches, PREG_SET_ORDER);
    $unnamed = [];

    foreach ($matches as $m) {
        $named = str_contains($m[2], 'aria-label=') || str_contains($m[2], 'title=') || trim(strip_tags(preg_replace('/<x-ui-icon[^>]*>/u', '', $m[3]))) !== '';

        if (! $named) {
            $unnamed[] = e23Norm(mb_substr($m[0], 0, 80));
        }
    }

    expect($matches)->not->toBeEmpty()->and($unnamed)->toBe([]);
});

test('AX8: reduced motion is respected by every new animated surface - header, buttons, chat actions, composer and spinner', function () {
    $app = e23Css();
    $chat = e23Css('chat.css');

    expect(preg_match('/@media \(prefers-reduced-motion: reduce\)\s*\{[^@]*?\.app-header\s*\{\s*transition:\s*none/su', $app))->toBe(1)
        ->and($app)->toContain('.btn-secondary')->and(preg_match('/@media \(prefers-reduced-motion: reduce\)[^@]*\.btn-secondary[^@]*transition:\s*none/su', $app))->toBe(1)
        ->and(preg_match('/@media \(prefers-reduced-motion: reduce\)\s*\{[^@]*\.chat-act[^@]*transition:\s*none/su', $chat))->toBe(1)
        ->and($chat)->toContain('animation-duration: 1.6s');
});

test('AX9: touch targets - 44px send button, 44px action hit area, 40px+ icon buttons, 40px menu items', function () {
    $chat = e23Css('chat.css');

    expect(e23Norm(e23Rule($chat, '.chat-send')))->toContain('width: 2.75rem')
        ->and(e23Norm(e23Rule($chat, '.chat-act::after')))->toContain('inset: -.5rem')
        ->and(e23Norm(e23Rule($chat, '.chat-act')))->toContain('width: 1.75rem')
        ->and(e23Norm(e23Rule($chat, '.chat-icon-btn')))->toContain('width: 2.5rem')->toContain('height: 2.5rem')
        ->and(e23Norm(e23Rule($chat, '.chat-menu button')))->toContain('min-height: 2.5rem');
});

test('AX10: hover-only affordances never hide controls from keyboard or touch - the action button reveals on focus and stays visible on coarse pointers', function () {
    $chat = e23Css('chat.css');

    expect(preg_match('/@media \(hover: hover\) and \(pointer: fine\)\s*\{[^@]*\.chat-act\s*\{\s*opacity:\s*0/su', $chat))->toBe(1)
        ->and($chat)->toContain('.chat-row:focus-within .chat-act')->toContain('.chat-act:focus-visible')->toContain('.chat-act[aria-expanded="true"]');
});

test('AX11: the legal contents navigation and the conversations navigation are named landmarks', function () {
    expect(uiXpath($this->get(route('pages.terms'))->getContent())->query('//nav[@aria-label="محتويات الصفحة"]')->length)->toBe(1)
        ->and(uiXpath($this->actingAs(e16User())->get(route('community.chat'))->getContent())->query('//nav[@aria-label="المحادثات"]')->length)->toBe(1);
});

test('AX12: colour is never the only signal in the room - blocked states carry an icon and text, unread has text, edited has text', function () {
    $room = e23Source('views/chat/room.blade.php');

    expect($room)->toContain('blockerView()[0]')->toContain('blockerView()[1]')->toContain('معدّلة')
        ->and(e23Source('views/messages/_sidebar.blade.php'))->toContain('sr-only');
});
