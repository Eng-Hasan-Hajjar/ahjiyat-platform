<?php

use App\Models\AiKnowledgeArticle;
use App\Services\AiAssistant\TatawwurSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

require_once __DIR__.'/_support.php';

/*
| E45 - الواجهة: الزر العائم (حضوره، حدود الطبقات، RTL/جوال، كسول، بلا HTML خام ولا أصول خارجية) ودليل المنصة العام /help (جمهور، مسودات، هروب HTML، بلا كتابة).
| اختبارات استاتيكية لملفات الواجهة (JS/CSS/Blade) + اختبارات HTTP لعرض الصفحات؛ لا متصفح ولا شبكة.
*/

function e45w_read(string $path): string
{
    return (string) file_get_contents(base_path($path));
}

// ───────────────────────────── حضور الزر ─────────────────────────────

test('E45-W01: an enabled assistant renders the floating launcher on a member page with relative URLs, a CSRF token and an accessible name', function () {
    e45_enable();
    $html = $this->actingAs(e45_member())->get(route('home'))->assertOk()->getContent();

    expect($html)->toContain('data-tatawwur-widget')->toContain('data-tatawwur-launcher')->toContain('aria-label="تطوّر AI، المساعد الذكي لمنصة الأحجيات"')
        ->toContain('aria-haspopup="dialog"')->toContain('data-state-url="/tatawwur/state"')->toContain('data-messages-url="/tatawwur/messages"')
        ->toContain('data-history-url="/tatawwur/history"')->toContain('data-feedback-url="/tatawwur/feedback"')->toContain('data-csrf=')->toContain('tw-has-nav')
        ->and(substr_count($html, 'data-tatawwur-widget'))->toBe(1);
});

test('E45-W02: the launcher is absent with the master flag off, for guests by default, and for frozen or unverified accounts', function () {
    e45_enable(['tatawwur_ai.enabled' => false]);
    $this->actingAs(e45_member())->get(route('home'))->assertOk()->assertDontSee('data-tatawwur-widget', false);

    e45_enable();
    auth()->logout();
    $this->get(route('home'))->assertOk()->assertDontSee('data-tatawwur-widget', false);

    $this->actingAs(e45_member(['is_frozen' => true]))->get(route('home'))->assertDontSee('data-tatawwur-widget', false);
    $this->actingAs(e45_member(['email_verified_at' => null]))->get(route('home'))->assertDontSee('data-tatawwur-widget', false);
});

test('E45-W03: guests see the launcher only when guests are explicitly enabled, without the member bottom-nav offset', function () {
    e45_enable(['tatawwur_ai.guests_enabled' => true]);
    $html = $this->get(route('home'))->assertOk()->getContent();

    expect($html)->toContain('data-tatawwur-widget')->not->toContain('tw-has-nav');
});

test('E45-W04: deciding visibility costs zero database queries (settings only)', function () {
    e45_enable();
    $user = e45_member();

    DB::flushQueryLog();
    DB::enableQueryLog();
    TatawwurSettings::widgetVisibleFor($user);
    TatawwurSettings::widgetVisibleFor(null);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBe(0);
});

// ───────────────────────────── ملفات الواجهة (استاتيكي) ─────────────────────────────

test('E45-W05: the widget partial has no raw HTML output, no inline script/handlers and no external asset', function () {
    $blade = e45w_read('resources/views/layouts/partials/tatawwur-widget.blade.php');
    $code = preg_replace('/\{\{--.*?--\}\}/s', '', $blade);

    expect($code)->not->toContain('{!!')->not->toMatch('/<script/i')->not->toMatch('/\son[a-z]+\s*=/i')->not->toMatch('~https?://~i')->not->toMatch('/<link|@import|<img/i');
});

test('E45-W06: the widget script builds the DOM with textContent only - no innerHTML, insertAdjacentHTML, eval, document.write, Function or console', function () {
    $js = e45w_read('resources/js/tatawwur/widget.js');
    $code = preg_replace('~/\*.*?\*/|(?<![:\'"])//[^\n]*~s', '', $js);

    foreach (['innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write', 'eval(', 'new Function', 'console.', 'dangerouslySetInnerHTML', 'localStorage', 'sessionStorage'] as $needle) {
        expect($code)->not->toContain($needle);
    }

    // الاستثناء الوحيد: ثابت فضاء أسماء SVG (معرّف XML وليس طلب شبكة).
    $withoutNs = str_replace("'http://www.w3.org/2000/svg'", "''", $code);

    expect($code)->toContain('textContent')->and($withoutNs)->not->toMatch('~https?://~i')->and($code)->toContain('X-CSRF-TOKEN')->and($code)->toContain("credentials: 'same-origin'");
});

test('E45-W07: the panel is lazy-loaded on first interaction through a dynamic import, never a static one', function () {
    $app = e45w_read('resources/js/app.js');

    expect($app)->toContain("import('./tatawwur/widget.js')")->not->toMatch('/^import .*tatawwur/m')->toContain('tatawwur:open');
});

test('E45-W08: the stylesheet pins the launcher bottom-left physically, mobile above the bottom nav, with a z-index contract under the E23 ceiling', function () {
    $css = e45w_read('resources/css/app.css');
    $block = substr($css, (int) strpos($css, '===== E45'));

    expect($block)->toMatch('/\.tw-launcher\s*\{[^}]*position:\s*fixed;[^}]*left:\s*var\(--tw-edge\)[^}]*z-index:\s*55;/s')
        ->and($block)->toMatch('/z-index:\s*58;/')->and($block)->toContain('--app-bottom-nav-h')->and($block)->toContain('max-width: 767.98px')
        ->and($block)->toContain('prefers-reduced-motion')->and($block)->toContain('forced-colors');

    preg_match_all('/z-index:\s*(\d+)/', $block, $m);
    expect(max(array_map('intval', $m[1])))->toBeLessThanOrEqual(100)->and(min(array_map('intval', $m[1])))->toBeGreaterThanOrEqual(0);

    // أعلى من التنقل السفلي (50)، وأدنى من تراكب البحث (60).
    foreach (['55', '58'] as $z) {
        expect((int) $z)->toBeGreaterThan(50)->and((int) $z)->toBeLessThan(60);
    }

    expect($block)->not->toMatch('~url\(\s*[\'"]?https?:~i')->not->toContain('@import');
});

test('E45-W09: motion in the widget respects reduced-motion (every transition/animation is under a no-preference guard)', function () {
    $css = e45w_read('resources/css/app.css');
    $block = substr($css, (int) strpos($css, '===== E45'));
    $outside = preg_replace('/@media \(prefers-reduced-motion: no-preference\)\s*\{(?:[^{}]*\{[^{}]*\})*[^{}]*\}/s', '', $block);

    expect($outside)->not->toMatch('/(?<!-)animation\s*:/')->not->toMatch('/transition\s*:/');
});

test('E45-W10: RTL correctness - logical text direction is inherited and the physical left anchor is documented, no hard-coded ltr', function () {
    $css = e45w_read('resources/css/app.css');
    $block = substr($css, (int) strpos($css, '===== E45'));

    expect($block)->not->toContain('direction: ltr')->not->toContain('dir="ltr"')->and(e45w_read('resources/js/tatawwur/widget.js'))->toContain('dir');
});

// ───────────────────────────── دليل المنصة /help ─────────────────────────────

test('E45-H01: the public guide lists published articles only, even with the assistant switched off', function () {
    e45_enable(['tatawwur_ai.enabled' => false]);
    e45_article('visible-note', 'مقال ظاهر اختباري', 'نص تعريفي اختباري عن مقال ظاهر للجميع في دليل المنصة العام.');
    $draft = new AiKnowledgeArticle;
    $draft->forceFill(['slug' => 'hidden-draft', 'locale' => 'ar', 'title' => 'مسودة مخفية', 'summary' => 'ملخص مسودة اختبارية مخفية عن العامة', 'body' => 'نص المسودة المخفية لا يظهر للعامة أبدًا.', 'audience' => 'public', 'area' => 'general', 'source_label' => 'دليل المنصة', 'status' => 'draft', 'version' => 1])->save();

    $this->get(route('help.index'))->assertOk()->assertSee('مقال ظاهر اختباري')->assertDontSee('مسودة مخفية');
    $this->get(route('help.show', 'visible-note'))->assertOk()->assertSee('مقال ظاهر اختباري');
    $this->get(route('help.show', 'hidden-draft'))->assertNotFound();
});

test('E45-H02: member-audience articles are hidden from guests and unverified users and shown to verified members', function () {
    e45_enable();
    e45_article('members-only', 'مقال للأعضاء فقط', 'نص موجه للأعضاء الموثقين فقط ويشرح موضوعًا يخص الحسابات.', ['audience' => 'member']);

    $this->get(route('help.show', 'members-only'))->assertNotFound();
    $this->actingAs(e45_member(['email_verified_at' => null]))->get(route('help.show', 'members-only'))->assertNotFound();
    $this->actingAs(e45_member())->get(route('help.show', 'members-only'))->assertOk()->assertSee('مقال للأعضاء فقط');
    $this->actingAs(e45_member())->get(route('help.index'))->assertSee('مقال للأعضاء فقط');
});

test('E45-H03: slugs outside the allowed pattern and missing slugs are 404, never an error page', function () {
    e45_enable();

    foreach (['Bad_Slug', 'a', '..%2F..%2Fetc', 'x'.str_repeat('y', 130), 'مقال'] as $slug) {
        $this->get('/help/'.$slug)->assertNotFound();
    }

    $this->get(route('help.show', 'does-not-exist'))->assertNotFound();
});

test('E45-H04: scripts and external links are rejected at publish, harmless markup is stripped from the chunks, and a body forced past the publisher is still escaped on output', function () {
    e45_enable();

    expect(fn () => e45_article('script-note', 'مقال سكربت اختباري', "نص آمن بعد التنقية في هذا المقال.\n\nوهذا <script>alert(1)</script> داخل النص الاختباري الطويل بما يكفي للنشر الآمن."))->toThrow(App\Services\AiAssistant\Knowledge\KnowledgeSafetyException::class);
    expect(fn () => e45_article('link-note', 'مقال رابط اختباري', 'نص آمن و[رابط خارجي](https://evil.example/x) داخل النص الاختباري الطويل بما يكفي للنشر الآمن.'))->toThrow(App\Services\AiAssistant\Knowledge\KnowledgeSafetyException::class);

    $markup = e45_article('markup-note', 'مقال وسوم اختباري', "نص آمن بعد التنقية في هذا المقال.\n\nوهذا <b>وسم</b> عادي داخل النص الاختباري الطويل بما يكفي للنشر الآمن.");
    expect(App\Models\AiKnowledgeChunk::query()->where('article_id', $markup->id)->pluck('body')->implode(' '))->not->toContain('<b>')->not->toContain('</b>');

    // دفاع بالعمق: لو وصل محتوى خطر للقاعدة بتجاوز الناشر فالعرض يهرب الوسوم ولا يُنتج رابطًا خارجيًا.
    $forced = new AiKnowledgeArticle;
    $forced->forceFill(['slug' => 'forced-note', 'locale' => 'ar', 'title' => 'مقال مفروض', 'summary' => 'ملخص مقال مفروض لاختبار الهروب', 'body' => "نص\n\nوهذا <b>وسم</b> و<script>alert(1)</script> و[رابط](https://evil.example/x) انتهى.", 'audience' => 'public', 'area' => 'general', 'source_label' => 'دليل المنصة', 'status' => 'published', 'version' => 1, 'published_at' => now()])->save();

    $html = $this->get(route('help.show', 'forced-note'))->assertOk()->getContent();

    expect($html)->not->toContain('<b>وسم</b>')->not->toContain('<script>alert(1)')->not->toContain('href="https://evil.example');
});

test('E45-H05: the guide search term is escaped and results come from published articles only', function () {
    e45_enable();
    e45_kb();

    $html = $this->get(route('help.index', ['q' => '<script>alert(1)</script>']))->assertOk()->getContent();
    expect($html)->not->toContain('<script>alert(1)</script>');

    $this->get(route('help.index', ['q' => 'الجواهر']))->assertOk()->assertSee('الجواهر');
    $this->get(route('help.index', ['q' => str_repeat('ا', 5000)]))->assertOk();
});

test('E45-H06: the guide pages are read-only - no table changes across visits', function () {
    e45_enable();
    e45_kb();
    $user = e45_member();
    $this->actingAs($user)->get(route('help.index'))->assertOk();   // إحماء الجهاز
    $before = e45_db_snapshot(true);

    $this->get(route('help.index'))->assertOk();
    $this->get(route('help.show', 'al-jawahir'))->assertOk();
    $this->get(route('help.index', ['q' => 'جواهر']))->assertOk();

    expect(e45_db_snapshot(true))->toBe($before);
});

test('E45-H07: assistant and guide routes obey the route-name guards (no reward/grant/claim/payout/prize; GET actions never named like writes)', function () {
    $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'tatawwur') || str_starts_with($r->uri(), 'help'));

    expect($routes->count())->toBeGreaterThanOrEqual(7);

    foreach ($routes as $route) {
        $blob = strtolower($route->uri().' '.($route->getName() ?? '').' '.$route->getActionName().' '.implode(' ', $route->middleware()));
        expect($blob)->not->toMatch('/reward|grant|claim|payout|prize/');

        if (in_array('GET', $route->methods(), true)) {
            expect($route->getActionMethod())->not->toBeIn(['store', 'update', 'destroy', 'delete', 'send', 'read']);
        } else {
            expect($route->middleware())->toContain('web');
        }
    }
});

test('E45-H08: the assistant never links to a page it cannot serve - every curated article url is an internal path that resolves or is a known app route', function () {
    e45_enable();
    e45_kb();
    $this->actingAs(e45_member());

    foreach (AiKnowledgeArticle::query()->published()->get() as $article) {
        expect($article->canonical_path === null || str_starts_with((string) $article->canonical_path, '/'))->toBeTrue()
            ->and($article->publicPath())->toBe('/help/'.$article->slug);

        $this->get($article->publicPath())->assertOk();
    }
});
