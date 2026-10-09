<?php

require_once __DIR__.'/UiE23Helpers.php';
require_once __DIR__.'/../Chat/ChatTestHelpers.php';

use App\Models\ChatMessage;

beforeEach(fn () => $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class));

/** @return array{0: \App\Models\User, 1: \App\Models\User} صديقان وغرفة مباشرة بينهما فيها رسالة. */
function e23DirectRoom($test): array
{
    [$a, $b] = chatFriends();
    chatDm($test, $b, $a, 'أهلًا من صديقك');

    return [$a, $b];
}

test('CH1: the global room is one card - shell, identity header (h1), log, composer - with the community badge', function () {
    $html = $this->actingAs(e16User())->get(route('community.chat'))->assertOk()->getContent();
    $x = uiXpath($html);

    expect($x->query('//div[contains(@class,"chat-shell")]')->length)->toBe(1)
        ->and($x->query('//section[@aria-label="غرفة الدردشة"]//h1')->item(0)->textContent)->toContain('الدردشة العامة')
        ->and($x->query('//div[@role="log"][@aria-label="رسائل المحادثة"]')->length)->toBe(1)
        ->and($x->query('//*[contains(@class,"chat-composer")]')->length)->toBe(1)
        ->and(e23VisibleText($html))->toContain('مجتمع');
});

test('CH2: desktop shows the conversations list beside the room, with the current room marked and the list named', function () {
    $x = uiXpath($this->actingAs(e16User())->get(route('community.chat'))->getContent());

    expect($x->query('//aside[@aria-label="قائمة المحادثات"]')->length)->toBe(1)
        ->and($x->query('//aside//nav[@aria-label="المحادثات"]//a[@aria-current="page"]')->length)->toBe(1)
        ->and($x->query('//aside//a[@aria-current="page"]')->item(0)->getAttribute('href'))->toBe(route('community.chat'));
});

test('CH3: on mobile the room has a back control to the conversations list - hidden on desktop, named for assistive tech', function () {
    $x = uiXpath($this->actingAs(e16User())->get(route('community.chat'))->getContent());
    $back = $x->query('//section//a[@aria-label="كل المحادثات"]')->item(0);

    expect($back)->not->toBeNull()->and($back->getAttribute('href'))->toBe(route('messages.index'))
        ->and(preg_split('/\s+/', $back->getAttribute('class')))->toContain('lg:hidden')->toContain('chat-icon-btn');
});

test('CH4: a team room names the team, links to it, and shows the member count with a team badge', function () {
    [$team, , , $member] = chatTeam();
    $html = $this->actingAs($member)->get(route('teams.chat', $team))->assertOk()->getContent();
    $x = uiXpath($html);
    $link = $x->query('//section//header//a[@title="صفحة الفريق"]')->item(0);

    expect($link)->not->toBeNull()->and($link->getAttribute('href'))->toBe(route('teams.show', $team))
        ->and($x->query('//section//h1')->item(0)->textContent)->toContain($team->name)
        ->and(e23VisibleText($html))->toContain('عضو')->toContain('فريق');
});

test('CH5: a direct room names the friend, links to the profile and says it is private - no fake presence indicator', function () {
    [$a, $b] = e23DirectRoom($this);
    $html = $this->actingAs($a)->get(route('messages.direct', $b))->assertOk()->getContent();
    $x = uiXpath($html);

    expect($x->query('//section//header//a[@title="الملف الشخصي"]/@href')->item(0)->nodeValue)->toBe(route('players.show', $b))
        ->and($x->query('//section//h1')->item(0)->textContent)->toContain($b->name)
        ->and(e23VisibleText($html))->toContain('محادثة خاصة بينكما فقط')
        ->and($html)->not->toContain('متصل الآن')->not->toContain('online');
});

test('CH6: a friend who can send gets the live composer - send capability comes from the server config, not from the view', function () {
    [$a, $b] = e23DirectRoom($this);
    $response = $this->actingAs($a)->get(route('messages.direct', $b))->assertOk();
    $config = $response->viewData('config');
    $x = uiXpath($response->getContent());

    expect($config['caps'])->toMatchArray(['send' => true, 'blocker' => null])
        ->and($x->query('//textarea[@id="chat-body"]')->length)->toBe(1)
        ->and($x->query('//label[@for="chat-body"]')->item(0)->textContent)->toBe('رسالتك')
        ->and($x->query('//button[@aria-label="إرسال الرسالة"]')->length)->toBe(1);
});

test('CH7: a muted player still reads the room but the composer is replaced by an explained, non-interactive block', function () {
    $mod = chatModerator();
    $u = e16User();
    chatMutes()->mute($mod, $u, '1h', 'سبب');

    $response = $this->actingAs($u)->get(route('community.chat'))->assertOk();
    $html = $response->getContent();
    $x = uiXpath($html);

    expect($response->viewData('config')['caps'])->toMatchArray(['send' => false, 'blocker' => 'muted'])
        ->and($x->query('//*[contains(@class,"chat-blocked")][@role="status"]')->length)->toBe(1)
        ->and(e23Source('js/chat.js'))->toContain("muted: ['أنت مكتوم مؤقتًا', 'mute']")
        ->and($html)->toContain('الإرسال غير متاح')
        ->and($x->query('//textarea[@disabled and @tabindex="-1"]')->length)->toBe(1);
});

test('CH8: an inactive team room is read-only with its history intact, and says why', function () {
    [$team, $owner, , $member] = chatTeam();
    chatMessages()->send($member, chatThreads()->forTeam($team), 'قبل التعطيل');
    $team->forceFill(['is_active' => false])->save();

    $response = $this->actingAs($member)->get(route('teams.chat', $team))->assertOk();
    $config = $response->viewData('config');

    expect($config['caps'])->toMatchArray(['send' => false, 'blocker' => 'team_inactive'])
        ->and(collect($config['messages'])->pluck('body')->all())->toBe(['قبل التعطيل'])
        ->and(e23Source('js/chat.js'))->toContain("team_inactive: ['الدردشة للقراءة فقط', 'lock']");
});

test('CH9: after a block or an ended friendship the direct room keeps its history and shows the blocker message instead of the composer', function () {
    [$a, $b] = e23DirectRoom($this);
    chatBlocks()->block($a, $b);

    $response = $this->actingAs($a)->get(route('messages.direct', $b))->assertOk();
    $config = $response->viewData('config');

    expect($config['caps'])->toMatchArray(['send' => false, 'blocker' => 'blocked'])
        ->and($config['caps']['blocker_message'])->toBe('لا يمكنك إرسال رسائل بسبب الحظر.')
        ->and(collect($config['messages'])->pluck('body')->all())->toBe(['أهلًا من صديقك'])
        ->and($response->getContent())->toContain('chat-blocked');
});

test('CH10: every blocker code the server can send has a title and an icon in the room, and unknown codes fall back safely', function () {
    $js = e23Source('js/chat.js');

    foreach (['blocked', 'not_friends', 'muted', 'team_inactive', 'closed', 'unverified', 'frozen'] as $code) {
        expect($js)->toContain($code.": ['");
    }

    expect($js)->toContain("?? ['الإرسال غير متاح', 'lock']");
});

test('CH11: the empty room invites the first message with copy per room type', function () {
    $html = $this->actingAs(e16User())->get(route('community.chat'))->getContent();
    $js = e23Source('js/chat.js');

    expect($html)->toContain('ابدأ المحادثة')->toContain('emptyCopy()')
        ->and($js)->toContain('كن أول من يكتب في الدردشة العامة')->toContain('اكتب لزملاء فريقك')->toContain('قل مرحبًا لـ');
});

test('CH12: deleted and hidden messages are not told apart by colour alone - icon plus text, a dashed border for deleted, a warning tint for hidden', function () {
    $room = e23Source('views/chat/room.blade.php');
    $css = e23Css('chat.css');

    expect($room)->toContain('تم حذف هذه الرسالة')->toContain('أُخفيت هذه الرسالة بواسطة المشرفين')->toContain('name="trash"')->toContain('name="eye-slash"')
        ->toContain('النص الأصلي (يراه المشرفون فقط)')
        ->and(e23Norm(e23Rule($css, '.chat-bubble--deleted')))->toContain('border-style: dashed')
        ->and(e23Norm(e23Rule($css, '.chat-bubble--hidden')))->toContain('var(--color-warning)');
});

test('CH13: own messages and other peoples messages are visually different, consecutive messages from one sender connect, and edits are labelled', function () {
    $room = e23Source('views/chat/room.blade.php');
    $css = e23Css('chat.css');

    expect($room)->toContain("m.mine ? 'chat-bubble--mine' : 'chat-bubble--others'")->toContain('chat-bubble--cont')->toContain('isGroupStart(i)')->toContain('معدّلة')->toContain('dayStart(i)')
        ->and(e23Rule($css, '.chat-bubble--mine'))->toContain('var(--color-primary)')
        ->and($css)->toContain('.chat-bubble--others.chat-bubble--cont')->toContain('.chat-bubble--mine.chat-bubble--cont');
});

test('CH14: the sender is identified in shared rooms (avatar initial + name) but not repeated in a direct room, and screen readers always get the sender', function () {
    $room = e23Source('views/chat/room.blade.php');

    expect($room)->toContain('x-text="initial(m.sender)"')->toContain("!m.mine && cfg.type !== 'direct' && isGroupStart(i)")->toContain('class="sr-only"')->toContain("(m.mine ? 'أنت'");
});

test('CH15: message actions live in one small menu per message - haspopup, expanded state, labelled trigger, role=menu with the five actions', function () {
    $html = $this->actingAs(e16User())->get(route('community.chat'))->getContent();
    $room = e23Source('views/chat/room.blade.php');

    expect($html)->toContain('aria-haspopup="menu"')->toContain('aria-label="إجراءات الرسالة"')->toContain('role="menu"')
        ->and(substr_count($room, 'role="menuitem"'))->toBe(5)
        ->and($room)->toContain('تعديل')->toContain('حذف')->toContain('إبلاغ')->toContain('إخفاء')->toContain('استعادة');
});

test('CH16: the action menu is keyboard operable - arrows, Home/End, Escape restores focus, Tab closes - and flips upward near the bottom edge', function () {
    $js = e23Source('js/chat.js');

    expect($js)->toContain('menuKey(')->toContain('ArrowDown')->toContain('ArrowUp')->toContain('Escape')->toContain('focusFirstItem')->toContain('menuUp')
        ->and(e23Source('views/chat/room.blade.php'))->toContain('@keydown.escape.stop="closeMenu(true)"')->toContain('@keydown.tab="closeMenu()"');
});

test('CH17: the composer grows to five lines then scrolls, Enter sends, Shift+Enter breaks the line and IME composition never sends', function () {
    $js = e23Source('js/chat.js');
    $room = e23Source('views/chat/room.blade.php');

    expect($js)->toContain('line * 5')->toContain('event.shiftKey || event.isComposing')->toContain('this.send()')
        ->and($room)->toContain('@input="grow()"')->toContain('@keydown.enter="onEnter($event)"')->toContain(':maxlength="cfg.limits.max_length"')
        ->and(e23Norm(e23Rule(e23Css('chat.css'), '.chat-input')))->toContain('max-height: 9.5rem')->toContain('resize: none');
});

test('CH18: the send button is disabled while empty or sending, shows a spinner while sending and is a 44px+ target with a name', function () {
    $room = e23Source('views/chat/room.blade.php');
    $send = e23Norm(e23Rule(e23Css('chat.css'), '.chat-send'));

    expect($room)->toContain(':disabled="sending || !body.trim()"')->toContain('aria-label="إرسال الرسالة"')->toContain('chat-spinner')
        ->and($send)->toContain('width: 2.75rem')->toContain('height: 2.75rem');
});

test('CH19: the older-messages control and the new-messages jump are real buttons with busy state and counts, not scroll tricks', function () {
    $room = e23Source('views/chat/room.blade.php');

    expect($room)->toContain('تحميل رسائل أقدم')->toContain(':aria-busy="loadingOlder.toString()"')->toContain('بداية المحادثة')->toContain('رسائل جديدة')
        ->toContain('@click="jumpLatest()"')->toContain('unseen > 9 ? \'9+\' : unseen');
});

test('CH20: the room fits the viewport from tokens - header, bottom nav and announcement are subtracted, no guessed vh offsets remain', function () {
    $css = e23Norm(e23Css('chat.css'));

    expect($css)->toContain('calc(100dvh - var(--app-header-h) - var(--app-bottom-nav-h) - var(--app-announcement-h) - 3rem)')
        ->toContain('calc(100dvh - var(--app-header-h) - var(--app-bottom-nav-h) - var(--app-announcement-h) - 5rem)')->toContain('min-height: 24rem')
        ->and(e23Source('views/chat/room.blade.php'))->not->toContain('18rem')->not->toContain('100vh-');
});

test('CH21: the composer keeps the safe-area inset and sits inside the shell, so the fixed bottom navigation never covers it', function () {
    $composer = e23Norm(e23Rule(e23Css('chat.css'), '.chat-composer'));

    expect($composer)->toContain('env(safe-area-inset-bottom)')->toContain('flex-shrink: 0')
        ->and(e23Source('views/layouts/partials/bottom-nav.blade.php'))->toContain('h-16')
        ->and(e23Norm(e23Css()))->toContain('--app-bottom-nav-h: calc(4rem + env(safe-area-inset-bottom, 0px))');
});

test('CH22: chat views and script still contain no raw-HTML sinks - message text is only ever rendered through x-text', function () {
    foreach (['views/chat/room.blade.php', 'js/chat.js', 'views/messages/_sidebar.blade.php'] as $file) {
        $source = e19Code(resource_path($file)); // بلا تعليقات: التعليق قد يذكر الممنوع بالشرح

        foreach (['x-html', 'innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write', 'eval(', 'new Function', '{!!'] as $sink) {
            expect($source)->not->toContain($sink);
        }
    }
});

test('CH23: the room config contract is unchanged by E23 - the same keys, so no domain or permission data was added or removed', function () {
    $config = $this->actingAs(e16User())->get(route('community.chat'))->viewData('config');

    expect(array_keys($config))->toBe(['type', 'title', 'subtitle', 'thread', 'channel', 'viewer', 'caps', 'hidden_sender_ids', 'limits', 'categories', 'endpoints', 'messages', 'has_more'])
        ->and(array_keys($config['caps']))->toBe(['send', 'moderate', 'blocker', 'blocker_message']);
});

test('CH24: authorization is exactly as before - guests go to login, outsiders get 404 on a team room, strangers 403 on a direct room', function () {
    [$team] = chatTeam();
    $stranger = e16User();
    $outsider = e16User();

    $this->get(route('community.chat'))->assertRedirect(route('login'));
    $this->actingAs($outsider)->get(route('teams.chat', $team))->assertNotFound();
    $this->actingAs($stranger)->get(route('messages.direct', e16User()))->assertForbidden();
    $this->actingAs($stranger)->postJson(route('community.chat.send'), ['body' => 'مرحبا'])->assertCreated();

    expect(ChatMessage::count())->toBe(1);
});

test('CH25: unread counts in the conversations list are status badges with a screen-reader text, and the current direct room is marked', function () {
    [$viewer, $friend] = e23FriendsWithUnread($this);
    $x = uiXpath($this->actingAs($viewer)->get(route('community.chat'))->assertOk()->getContent());
    $badge = $x->query('//aside//a[@href="'.route('messages.direct', $friend).'"]//span[contains(@class,"rounded-full")][.//span[contains(@class,"sr-only")]]');

    expect($badge->length)->toBe(1)
        ->and(e23Norm($badge->item(0)->textContent))->toBe('1 رسائل غير مقروءة')
        ->and(e23VisibleText($this->actingAs($viewer)->get(route('community.chat'))->getContent()))->toContain('1 رسائل غير مقروءة');

    $open = uiXpath($this->actingAs($viewer)->get(route('messages.direct', $friend))->getContent());

    expect($open->query('//aside//a[@aria-current="page"]')->item(0)->getAttribute('href'))->toBe(route('messages.direct', $friend));
});

test('CH26: the room is the only sticky-free chat surface - no sticky offsets in the chat views', function () {
    expect(e23Source('views/chat/room.blade.php'))->not->toContain('sticky')
        ->and(e23Source('views/messages/_sidebar.blade.php'))->not->toContain('sticky');
});
