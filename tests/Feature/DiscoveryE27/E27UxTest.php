<?php

require_once __DIR__.'/_support.php';

/*
| E27-E - تجربة البحث: زر الرأس، التراكب (combobox)، لوحة المفاتيح، RTL، الجوال، الوصولية، بدون JavaScript. فحص بنيوي لـDOM/XPath + تدقيق ساكن للمصدر
| + تشغيل safePath فعليًا بـNode (إن وُجد) لأن منطق الروابط الآمنة هو ما يهم أمنيًا في الواجهة.
*/

beforeEach(fn () => $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class));

if (! function_exists('e27_ux_views')) {
    /** @return array<string, string> مسار ← مصدر كل عروض E27. */
    function e27_ux_views(): array
    {
        $paths = ['search/index.blade.php', 'search/_result.blade.php', 'discover/index.blade.php', 'discover/_card.blade.php', 'layouts/partials/search-overlay.blade.php'];

        return array_combine($paths, array_map(fn (string $p) => uiView($p), $paths));
    }

    /** كود JavaScript بلا تعليقات (التعليق قد يذكر الممنوع بالشرح). */
    function e27_js_code(string $relative): string
    {
        return preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', (string) file_get_contents(resource_path($relative)));
    }
}

test('U1: the header search trigger is a real link to /search for guests and players, named, and outside the primary navigation', function () {
    foreach ([$this, $this->actingAs(e27_user())] as $caller) {
        $x = uiXpath($caller->get('/')->assertOk()->getContent());
        $trigger = $x->query('//a[@data-search-trigger]');

        expect($trigger->length)->toBe(1)
            ->and($trigger->item(0)->getAttribute('href'))->toBe(route('search.index'))
            ->and($trigger->item(0)->getAttribute('aria-label'))->toBe('بحث')
            ->and($trigger->item(0)->getAttribute('aria-haspopup'))->toBe('dialog')
            ->and($x->query('//header//a[@data-search-trigger]')->length)->toBe(1)
            ->and($x->query('//nav[@aria-label="التنقل الرئيسي"]//a[@data-search-trigger]')->length)->toBe(0);
    }
});

test('U2: the primary navigation keeps its icons - six for verified players, four for unverified ones - and guests keep their text links; search joined none of them', function () {
    expect(uiPrimaryLabels($this->actingAs(e27_user())->get('/')->assertOk()->getContent()))->toBe(['الرئيسية', 'الأحجيات', 'المنافسات', 'الفرق', 'الأصدقاء', 'الرسائل'])
        ->and(uiPrimaryLabels($this->actingAs(e27_user(['email_verified_at' => null]))->get('/')->assertOk()->getContent()))->toBe(['الرئيسية', 'الأحجيات', 'المنافسات', 'الفرق']);

    $guestLinks = uiAttrs(uiXpath($this->get('/')->assertOk()->getContent()), '//nav[@aria-label="التنقل الرئيسي"]//a', 'href');
    expect($guestLinks)->toContain(route('puzzles.index'))->toContain(route('competitions.index'))->toContain(route('teams.index'))->not->toContain(route('search.index'));
});

test('U3: the overlay is a labelled dialog holding a combobox wired to a listbox, with a live status region and no form', function () {
    foreach ([$this, $this->actingAs(e27_user())] as $caller) {
        $html = $caller->get('/')->assertOk()->getContent();
        $x = uiXpath($html);
        $overlay = $x->query('//div[@x-data="globalSearch"]')->item(0);

        expect($overlay)->not->toBeNull()
            ->and($x->query('//div[@x-data="globalSearch"]')->length)->toBe(1)
            ->and($x->query('.//*[@role="dialog" and @aria-modal="true" and @aria-label]', $overlay)->length)->toBe(1)
            ->and($x->query('.//form', $overlay)->length)->toBe(0)
            ->and($x->query('.//*[@role="search"]', $overlay)->length)->toBe(1)
            ->and($x->query('.//*[@role="status" and @aria-live="polite"]', $overlay)->length)->toBe(1);

        $combobox = $x->query('.//input[@role="combobox"]', $overlay);
        expect($combobox->length)->toBe(1)
            ->and($combobox->item(0)->getAttribute('aria-autocomplete'))->toBe('list')
            ->and($combobox->item(0)->getAttribute('aria-label'))->not->toBe('')
            ->and($combobox->item(0)->getAttribute('maxlength'))->toBe('64')
            ->and($combobox->item(0)->getAttribute('autocomplete'))->toBe('off');
        $listbox = $x->query('.//*[@role="listbox"]', $overlay);
        expect($listbox->length)->toBe(1)->and($listbox->item(0)->getAttribute('id'))->toBe($combobox->item(0)->getAttribute('aria-controls'));
        expect(uiUnnamedControls($x, '//div[@x-data="globalSearch"]'))->toBe([]);
    }
});

test('U4: keyboard support is wired - arrows, Enter, Escape, a focus trap, and the "/" and Ctrl/Cmd+K shortcuts', function () {
    $overlay = uiView('layouts/partials/search-overlay.blade.php');
    $js = file_get_contents(resource_path('js/search.js'));

    expect($overlay)->toContain('@keydown.arrow-down.prevent="move(1)"')->toContain('@keydown.arrow-up.prevent="move(-1)"')->toContain('@keydown.enter="enter($event)"')
        ->toContain('@keydown.escape.window="close()"')->toContain('@keydown.tab="trap($event)"')->toContain('@keydown.window="shortcut($event)"')
        ->toContain(':aria-activedescendant="activeId"')->toContain(':aria-selected=');
    expect($js)->toContain("event.key === '/'")->toContain("event.key.toLowerCase() === 'k'")->toContain('isEditable(event.target)')
        ->toContain('opener.focus')->toContain('shiftKey');   // Esc يعيد التركيز لمن فتح التراكب؛ Shift+Tab يدور داخل الحوار
});

test('U5: the overlay script never builds HTML or touches storage - text goes through x-text, links through safePath', function () {
    $js = e27_js_code('js/search.js');
    $views = e27_ux_views();

    expect(preg_match('/innerHTML|outerHTML|insertAdjacentHTML|document\.write|\beval\s*\(|new\s+Function|localStorage|sessionStorage|indexedDB|console\.|\.cookie|postMessage|importScripts/', $js))->toBe(0);
    foreach ($views as $path => $source) {
        expect(preg_match('/x-html|{!!|\bv-html\b|javascript:|<script|on(click|error|load)\s*=/i', $source))->toBe(0, $path);
    }
    expect($views['layouts/partials/search-overlay.blade.php'])->toContain('x-text="item.title"')->toContain(':href="item.url"');
});

test('U6: requests are debounced, cancelled when superseded, stale answers are ignored and a 429 pauses typing instead of hammering', function () {
    $js = file_get_contents(resource_path('js/search.js'));

    expect($js)->toContain('const DEBOUNCE_MS = 220;')->toContain('new AbortController()')->toContain('this.controller.abort()')->toContain('if (term !== this.q.trim()) return;')
        ->toContain('response.status === 429')->toContain('this.blockedUntil = Date.now() + BACKOFF_MS;')->toContain("error.name === 'AbortError'")
        ->toContain('this.composing')   // لا طلبات أثناء تركيب الكتابة (IME)
        ->toContain("credentials: 'same-origin'");
});

test('U7: safePath accepts only same-site relative paths - evaluated for real with Node', function () {
    $node = trim((string) shell_exec('command -v node 2>/dev/null'));
    if ($node === '') {
        $this->markTestSkipped('node is not available in this environment');
    }

    $copy = sys_get_temp_dir().'/e27-search-'.bin2hex(random_bytes(4)).'.mjs';
    copy(resource_path('js/search.js'), $copy);

    $cases = ['/puzzles/12', '/search?q=%D8%A3&type=teams', '/players/01J8ZZZZZZZZZZZZZZZZZZZZZZ', '/', '/%2F%2Fevil',
        '//evil.test', '/\\evil', "/\t/evil.com", "/\n/evil.com", "/\r/evil.com", '/ x', "/\u{00A0}x", "/\u{0000}x", 'https://evil.test/x', 'http://evil.test', 'javascript:alert(1)', 'data:text/html,x', 'evil.test', '', ' /x'];
    $script = 'import {safePath} from '.json_encode('file://'.$copy).'; const c = JSON.parse(process.argv[1]); console.log(JSON.stringify(c.map((u) => safePath(u))));'
        .' console.log(JSON.stringify([safePath(null), safePath(undefined), safePath(42), safePath({}), safePath([])]));';
    $out = shell_exec(escapeshellarg($node).' --input-type=module -e '.escapeshellarg($script).' '.escapeshellarg(json_encode($cases)).' 2>&1');
    @unlink($copy);

    $lines = array_values(array_filter(explode("\n", trim((string) $out))));
    expect($lines)->toHaveCount(2, (string) $out);
    $accepted = array_values(array_filter(json_decode($lines[0], true), fn ($v) => $v !== null));

    expect($accepted)->toBe(['/puzzles/12', '/search?q=%D8%A3&type=teams', '/players/01J8ZZZZZZZZZZZZZZZZZZZZZZ', '/', '/%2F%2Fevil'])
        ->and(json_decode($lines[1], true))->toBe([null, null, null, null, null]);
});

test('U8: the server only ever emits single-slash relative paths in suggestions', function () {
    $puzzle = e27_puzzle('أحجية الروابط');
    $team = e19Team(null, ['name' => 'فريق الروابط']);
    $json = e27_suggest($this, 'الروابط');
    $urls = collect($json['groups'])->flatMap(fn ($g) => collect($g['items'])->pluck('url')->push($g['more_url']))->filter()->push($json['all_url'])->all();

    expect($urls)->not->toBe([]);
    foreach ($urls as $url) {
        expect($url)->toMatch('#^/(?![/\\\\])[^\s\\\\]*$#');
    }
    expect(\App\Services\Search\SearchResult::relativePath('http://evil.test//double//slash?q=1'))->toBe('/double//slash?q=1')
        ->and(\App\Services\Search\SearchResult::relativePath('http://h.test'))->toBe('/');
});

test('U9: search and discover pages are accessible for guests and players in every state', function (string $url) {
    e27_puzzle('نتيجة موجودة');
    e19Team(null, ['name' => 'فريق موجود']);

    foreach ([$this, $this->actingAs(e27_user())] as $caller) {
        expect(e27_a11y_problems($caller->get($url)->assertOk()->getContent()))->toBe([], $url);
    }
})->with(['/search', '/search?q=موجود', '/search?q=م', '/search?q=لا-شيء-مطابق', '/search?q=موجود&type=puzzles', '/search?q=موجود&type=teams', '/search?type=players', '/discover']);

test('U10: the result type tabs are a labelled nav and mark exactly the current one', function () {
    e27_puzzle('أحجية التبويب');

    $all = uiXpath($this->get(route('search.index', ['q' => 'التبويب']))->assertOk()->getContent());
    $teams = uiXpath($this->get(route('search.index', ['q' => 'التبويب', 'type' => 'teams']))->assertOk()->getContent());

    expect($all->query('//nav[@aria-label="نوع النتائج"]//a[@aria-current="page"]')->length)->toBe(1)
        ->and(trim($all->query('//nav[@aria-label="نوع النتائج"]//a[@aria-current="page"]')->item(0)->textContent))->toBe('الكل')
        ->and($teams->query('//nav[@aria-label="نوع النتائج"]//a[@aria-current="page"]')->length)->toBe(1)
        ->and(trim($teams->query('//nav[@aria-label="نوع النتائج"]//a[@aria-current="page"]')->item(0)->textContent))->toBe('الفرق');
});

test('U11: the search page is a plain GET form that works without JavaScript - the term, the type and the page all travel in the URL', function () {
    $x = uiXpath($this->get(route('search.index', ['q' => 'نص', 'type' => 'puzzles']))->assertOk()->getContent());
    $form = $x->query('//main//form[@role="search"]');

    expect($form->length)->toBe(1)->and(strtolower($form->item(0)->getAttribute('method')))->toBe('get')->and($form->item(0)->getAttribute('action'))->toBe(route('search.index'))
        ->and($x->query('.//input[@name="q" and @type="search"]', $form->item(0))->length)->toBe(1)
        ->and($x->query('.//input[@name="type" and @value="puzzles"]', $form->item(0))->length)->toBe(1)
        ->and($x->query('.//button[@type="submit"]', $form->item(0))->length)->toBe(1);
});

test('U12: layout is right-to-left safe - the E27 views use logical utilities only and no physical left/right ones', function () {
    foreach (e27_ux_views() as $path => $source) {
        $classes = implode(' ', array_map(fn ($m) => $m, preg_match_all('/class="([^"]*)"/', $source, $all) ? $all[1] : []));

        expect(preg_match('/(?<![\w-])(?:-?m[lr]|p[lr]|border-[lr]|rounded-[lr]|rounded-[tb][lr]|text-(?:left|right)|float-(?:left|right)|left-\d|right-\d|inset-[lr])(?:-|\b)/', $classes))->toBe(0, $path);
    }

    $html = $this->get(route('search.index'))->assertOk()->getContent();
    expect(uiXpath($html)->query('/html[@lang="ar" and @dir="rtl"]')->length)->toBe(1);
});

test('U13: mobile - touch targets are at least 40px, the overlay is full screen on small screens, and the viewport is set', function () {
    $overlay = uiView('layouts/partials/search-overlay.blade.php');
    $header = uiView('layouts/partials/header.blade.php');
    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->toContain('name="viewport"')
        ->and($overlay)->toContain('fixed inset-0')->toContain('h-full w-full')->toContain('sm:h-auto')->toContain('sm:max-h-[75vh]')
        ->and($overlay)->toMatch('/aria-label="إغلاق البحث"[^>]*\n?[^>]*w-10 h-10/s')
        ->and($header)->toMatch('/data-search-trigger\s+class="[^"]*w-10 h-10/s')
        ->and($overlay)->toContain('enterkeyhint="search"')->toContain('type="search"');
});

test('U14: the overlay sits above the bottom navigation and below the global z-index ceiling', function () {
    foreach (e27_ux_views() as $path => $source) {
        preg_match_all('/z-\[(\d+)\]|\bz-(\d+)\b/', $source, $m);
        foreach (array_merge(array_filter($m[1]), array_filter($m[2])) as $z) {
            expect((int) $z)->toBeLessThan(100, $path);
        }
    }
    expect(uiView('layouts/partials/search-overlay.blade.php'))->toContain('z-[60]');
});

test('U15: motion respects the reduced-motion preference - every transition class carries its motion-reduce override', function () {
    foreach (e27_ux_views() as $path => $source) {
        foreach (preg_split('/\R/', $source) as $line) {
            if (preg_match('/class="[^"]*\btransition\b/', $line)) {
                expect($line)->toContain('motion-reduce:transition-none');
            }
        }
    }
});

test('U16: the discover entry is in the explore menu for players, and the overlay offers it to everyone when idle', function () {
    $player = $this->actingAs(e27_user())->get('/')->assertOk()->getContent();
    $guest = $this->get('/')->assertOk()->getContent();

    expect(uiXpath($player)->query('//a[@href="'.route('discover.index').'" and contains(normalize-space(.), "اكتشف")]')->length)->toBeGreaterThanOrEqual(1);
    foreach ([$guest, $player] as $html) {
        expect(uiXpath($html)->query('//div[@x-data="globalSearch"]//a[@href="/discover"]')->length)->toBe(1);
    }
});

test('U17: the overlay that now ships on every page introduces none of the words existing leak audits search for - hint, answer_raw, normalized_answer, answer_hash', function () {
    // انحدار حقيقي: خاصية Alpine باسم «hint» جعلت اختبار تسرّب الإجابات (E20) يفشل لأنه يفتّش كل HTML عن الكلمة.
    foreach ([$this, $this->actingAs(e27_user())] as $caller) {
        foreach (['/', '/puzzles', '/search', '/discover'] as $url) {
            expect($caller->get($url)->assertOk()->getContent())->not->toMatch('/answer_raw|normalized_answer|answer_hash|\bhint\b/i', $url);
        }
    }

    expect(file_get_contents(resource_path('js/search.js')))->not->toMatch('/\bhint\b/');
});
