<?php

require_once __DIR__.'/UiE23Helpers.php';

use App\Services\PlatformSettingsService;

beforeEach(fn () => $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class));

/** الرأس بوسومه كما يُرسَل للمتصفح (الحالة الأولية قبل أي تمرير). */
function e23HeaderNode(string $html): DOMElement
{
    $node = uiXpath($html)->query('//header[@data-app-header]')->item(0);
    expect($node)->not->toBeNull();

    return $node;
}

test('HS1: the guest header is the scroll-aware shell - data-app-header, scrolled=false at load, sticky top-0 z-40, its own .app-header surface', function () {
    $header = e23HeaderNode($this->get(route('home'))->assertOk()->getContent());
    $classes = preg_split('/\s+/', $header->getAttribute('class'));

    expect($header->getAttribute('data-scrolled'))->toBe('false')
        ->and($classes)->toContain('app-header')->toContain('sticky')->toContain('top-0')->toContain('z-40')
        ->and($classes)->not->toContain('glass');
});

test('HS2: the player header carries exactly the same scroll-aware shell as the guest header', function () {
    $header = e23HeaderNode($this->actingAs(e16User())->get(route('home'))->assertOk()->getContent());
    $classes = preg_split('/\s+/', $header->getAttribute('class'));

    expect($header->getAttribute('data-scrolled'))->toBe('false')
        ->and($classes)->toContain('app-header')->toContain('sticky')->toContain('top-0')->toContain('z-40')->not->toContain('glass');
});

test('HS3: the scroll state is a single cheap passive listener with a 12px threshold, initialised from the current scroll position', function () {
    $html = $this->get(route('home'))->getContent();

    expect($html)->toContain('x-data="{ guestOpen: false, scrolled: false, threshold: 12 }"')
        ->toContain('x-init="scrolled = window.scrollY > threshold"')
        ->toContain('@scroll.window.passive="scrolled = window.scrollY > threshold"')
        ->toContain(':data-scrolled="scrolled ? \'true\' : \'false\'"')
        ->and(substr_count($html, '@scroll.window'))->toBe(1); // مستمع تمرير واحد على النافذة للرأس
});

test('HS4: the header is never hidden on scroll - no hide/translate/show-on-scroll-up behaviour exists in the shell', function () {
    $source = e23Source('views/layouts/partials/header.blade.php');

    expect($source)->not->toContain('-translate-y-full')->not->toContain('scrollDirection')->not->toContain('lastScroll')->not->toContain('x-show="visible"')
        ->and(e23Css())->not->toMatch('/\.app-header[^{]*\{[^}]*translate/');
});

test('HS5: the two header states are driven by tokens - translucent at the top, near-opaque with border, shadow and stronger blur once scrolled', function () {
    $css = e23Css();
    $top = e23Rule($css, '.app-header');
    $scrolled = e23Rule($css, '.app-header--scrolled');

    expect(e23Norm($top))->toContain('height: var(--app-header-h)')->toContain('background-color: var(--header-bg-top)')->toContain('border-bottom: 1px solid var(--header-border-top)')
        ->toContain('backdrop-filter: blur(var(--header-blur-top))')->toContain('transition:')
        ->and(e23Norm($scrolled))->toContain('background-color: var(--header-bg-scrolled)')->toContain('border-bottom-color: var(--header-border-scrolled)')
        ->toContain('box-shadow: var(--header-shadow-scrolled)')->toContain('backdrop-filter: blur(var(--header-blur-scrolled))');

    foreach (['--header-bg-top', '--header-bg-scrolled', '--header-border-top', '--header-border-scrolled', '--header-shadow-scrolled', '--header-blur-top', '--header-blur-scrolled'] as $token) {
        expect($css)->toContain($token.':');
    }
});

test('HS6: the scrolled background is more opaque than the top one - both are mixes of the theme background token, never a fixed colour', function () {
    $css = e23Css();

    preg_match('/--header-bg-top:\s*color-mix\(in srgb, var\(--color-bg\) (\d+)%/', $css, $top);
    preg_match('/--header-bg-scrolled:\s*color-mix\(in srgb, var\(--color-bg\) (\d+)%/', $css, $scrolled);

    expect($top)->not->toBeEmpty()->and($scrolled)->not->toBeEmpty()
        ->and((int) $scrolled[1])->toBeGreaterThan((int) $top[1])->and((int) $scrolled[1])->toBeGreaterThanOrEqual(96)
        ->and(e23Rule($css, '.app-header').e23Rule($css, '.app-header--scrolled'))->not->toMatch('/#[0-9a-fA-F]{3,8}\b/');
});

test('HS7: the header blur uses the unprefixed property only - the minifier keeps just the -webkit- twin when both are written, which would drop the blur in Chromium', function () {
    $css = e23Css();

    expect(e23Rule($css, '.app-header'))->toContain('backdrop-filter:')->not->toContain('-webkit-backdrop-filter')
        ->and(e23Rule($css, '.app-header--scrolled'))->toContain('backdrop-filter:')->not->toContain('-webkit-backdrop-filter');
});

test('HS8: .glass is left exactly as in E22 - the global glass surface was not made opaque or changed', function () {
    expect(e23Norm(e23Rule(e23Css(), '.glass')))->toBe('background: var(--color-surface); border: 1px solid var(--color-surface-border); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px);');
});

test('HS9: reduced motion removes the header transition', function () {
    $css = e23Css();

    expect(preg_match('/@media \(prefers-reduced-motion: reduce\)\s*\{[^@]*?\.app-header\s*\{\s*transition:\s*none/su', $css))->toBe(1);
});

test('HS10: the geometry tokens exist once - 3.5rem on mobile, 4rem from sm, a bottom-nav token that is zero from md, and scroll-padding below the header', function () {
    $css = e23Css();

    expect($css)->toContain('--app-header-h: 3.5rem;')->toContain('--app-header-h: 4rem;')->toContain('--app-bottom-nav-h: calc(4rem + env(safe-area-inset-bottom, 0px));')
        ->toContain('--app-bottom-nav-h: 0px;')->toContain('--app-announcement-h: 0px;')
        ->and(substr_count($css, '--app-header-h: 3.5rem;'))->toBe(1)
        ->and(e23Norm($css))->toContain('scroll-padding-top: calc(var(--app-header-h) + 1rem)');
});

test('HS11: every sticky element below the header takes its offset from the header token - no hard-coded top-N guesses', function () {
    $offenders = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views'), FilesystemIterator::SKIP_DOTS)) as $file) {
        if (! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        foreach (preg_split('/\R/u', file_get_contents($file->getPathname())) as $i => $line) {
            if (preg_match('/\bsticky\b/', $line) && preg_match('/\btop-(?!0\b)[\d\[]/', $line)) {
                $offenders[] = str_replace(resource_path('views/'), '', $file->getPathname()).':'.($i + 1);
            }
        }
    }

    expect($offenders)->toBe([])
        ->and(e23Norm(e23Rule(e23Css(), '.sticky-under-header')))->toContain('top: calc(var(--app-header-h) + 1rem)')->toContain('z-index: 20');
});

test('HS12: the z-index contract holds - header and sheet overlay 40, bottom nav and sheet 50, skip link 100, nothing above 100 anywhere in the views', function () {
    expect(e23Source('views/layouts/partials/header.blade.php'))->toContain('sticky top-0 z-40')
        ->and(e23Source('views/layouts/partials/bottom-nav.blade.php'))->toContain('fixed bottom-0 inset-x-0 z-50')->toContain('fixed inset-0 z-40')
        ->and(e23Source('views/layouts/app.blade.php'))->toContain('focus:z-[100]');

    $above = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views'), FilesystemIterator::SKIP_DOTS)) as $file) {
        if (! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        preg_match_all('/\bz-\[(\d+)\]|\bz-(\d+)\b/', file_get_contents($file->getPathname()), $m, PREG_SET_ORDER);

        foreach ($m as $hit) {
            if ((int) ($hit[1] !== '' ? $hit[1] : ($hit[2] ?? 0)) > 100) {
                $above[] = basename($file->getPathname()).' '.$hit[0];
            }
        }
    }

    expect($above)->toBe([]);
});

test('HS13: no navigation destination was lost - the player header still exposes the six primary icons, the more menu, bell, theme switch and account menu', function () {
    $html = $this->actingAs(e16User())->get(route('home'))->assertOk()->getContent();
    $x = uiXpath($html);

    expect(uiPrimaryLabels($html))->toBe(['الرئيسية', 'الأحجيات', 'المنافسات', 'الفرق', 'الأصدقاء', 'الرسائل'])
        ->and($x->query('//header//button[@title="المزيد من الأقسام"]')->length)->toBe(1)
        ->and($x->query('//header//*[@data-bell-badge] | //header//button[contains(@aria-label,"الإشعارات")]')->length)->toBeGreaterThan(0)
        ->and($x->query('//header//button[@title="تبديل المظهر"]')->length)->toBe(1)
        ->and($x->query('//header//*[@aria-label="قائمة الحساب"]')->length)->toBeGreaterThan(0);
});

test('HS14: the announcement bar is plain page content ABOVE the sticky header - it scrolls away, the header stays; absent when disabled', function () {
    app(PlatformSettingsService::class)->setMany('announcement', ['enabled' => true, 'text' => 'إعلان للتجربة فقط', 'type' => 'warning']);

    $html = $this->get(route('home'))->assertOk()->getContent();
    $x = uiXpath($html);
    $bar = $x->query('//*[@data-announcement-bar]')->item(0);
    $barClasses = preg_split('/\s+/', $bar->getAttribute('class'));

    expect($bar)->not->toBeNull()
        ->and($barClasses)->not->toContain('sticky')->not->toContain('fixed')
        ->and(strpos($html, 'data-announcement-bar'))->toBeLessThan(strpos($html, 'data-app-header'))
        ->and($bar->getAttribute('x-init'))->toContain('--app-announcement-h')->toContain('ResizeObserver')
        ->and(preg_split('/\s+/', e23HeaderNode($html)->getAttribute('class')))->toContain('sticky')->toContain('top-0');

    app(PlatformSettingsService::class)->setMany('announcement', ['enabled' => false, 'text' => 'إعلان للتجربة فقط']);

    expect(uiXpath($this->get(route('home'))->getContent())->query('//*[@data-announcement-bar]')->length)->toBe(0);
});

test('HS15: only players get the bottom-nav clearance on the body; the guest page has no bottom nav and no extra padding class', function () {
    $guest = uiXpath($this->get(route('home'))->getContent());
    $player = uiXpath($this->actingAs(e16User())->get(route('home'))->getContent());

    expect(preg_split('/\s+/', $guest->query('//body')->item(0)->getAttribute('class')))->not->toContain('has-bottom-nav')
        ->and($guest->query('//nav[@aria-label="التنقل السريع"]')->length)->toBe(0)
        ->and(preg_split('/\s+/', $player->query('//body')->item(0)->getAttribute('class')))->toContain('has-bottom-nav')
        ->and($player->query('//nav[@aria-label="التنقل السريع"]')->length)->toBe(1)
        ->and(e23Norm(e23Css()))->toContain('.has-bottom-nav { padding-bottom: calc(var(--app-bottom-nav-h) + .5rem); }');
});

test('HS16: the guest mobile menu is an absolute sheet under the header - it never changes the header height', function () {
    $source = e23Source('views/layouts/partials/header.blade.php');

    expect($source)->toContain('absolute inset-x-0 top-full')->and(e23Rule(e23Css(), '.app-header'))->toContain('box-sizing: border-box');
});

function e23Classes(DOMNode $node): array
{
    return preg_split('/\s+/', trim($node->getAttribute('class')));
}

test('HS17: the guest header shows the text links only from lg (1024px) and the hamburger below it - six links do not fit a 768px header with the site fonts', function () {
    $x = uiXpath($this->get(route('home'))->assertOk()->getContent());
    $nav = $x->query('//header[@data-app-header]/div/nav[@aria-label="التنقل الرئيسي"]')->item(0);
    $button = $x->query('//header//button[@aria-controls="guest-menu"]')->item(0);
    $panel = $x->query('//*[@id="guest-menu"]')->item(0);
    $spacers = $x->query('//header[@data-app-header]/div/div[contains(concat(" ", normalize-space(@class), " "), " flex-1 ")]');

    expect($nav)->not->toBeNull()->and($button)->not->toBeNull()->and($panel)->not->toBeNull()
        ->and(e23Classes($nav))->toContain('hidden')->toContain('lg:flex')->not->toContain('md:flex')
        ->and(e23Classes($button))->toContain('lg:hidden')->not->toContain('md:hidden')
        ->and(e23Classes($panel))->toContain('lg:hidden')->not->toContain('md:hidden')
        ->and($spacers->length)->toBe(1)->and(e23Classes($spacers->item(0)))->toContain('lg:hidden')->not->toContain('md:hidden')
        ->and($x->query('//header//nav[@aria-label="التنقل الرئيسي"]//a')->length)->toBeGreaterThanOrEqual(2);
});

test('HS18: the guest header keeps the sign-in chip from sm and the registration button from md, so a 768px guest still sees both next to the hamburger', function () {
    $x = uiXpath($this->get(route('home'))->getContent());
    $login = $x->query('//header[@data-app-header]/div/div//a[@href="'.route('login').'"]')->item(0);
    $register = $x->query('//header[@data-app-header]/div/div//a[@href="'.route('register').'"]')->item(0);

    expect($login)->not->toBeNull()->and(e23Classes($login))->toContain('hidden')->toContain('sm:inline-flex')
        ->and($register)->not->toBeNull()->and(e23Classes($register))->toContain('hidden')->toContain('md:inline-flex');
});

test('HS19: no collateral change - the player header keeps its icon navigation from md and its own spacer', function () {
    $x = uiXpath($this->actingAs(e16User())->get(route('home'))->getContent());
    $nav = $x->query('//header[@data-app-header]/div/nav[@aria-label="التنقل الرئيسي"]')->item(0);
    $spacers = $x->query('//header[@data-app-header]/div/div[contains(concat(" ", normalize-space(@class), " "), " flex-1 ")]');

    expect($nav)->not->toBeNull()->and(e23Classes($nav))->toContain('hidden')->toContain('md:flex')->not->toContain('lg:flex')
        ->and($spacers->length)->toBe(1)->and(e23Classes($spacers->item(0)))->toContain('md:hidden')->not->toContain('lg:hidden')
        ->and($x->query('//header//button[@aria-controls="guest-menu"]')->length)->toBe(0);
});
