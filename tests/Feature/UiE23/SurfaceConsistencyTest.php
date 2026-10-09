<?php

require_once __DIR__.'/UiE23Helpers.php';

use App\Models\Campaign;
use App\Models\Season;
use Illuminate\Support\Facades\Blade;

beforeEach(fn () => $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class));

dataset('E23 public surfaces', ['/puzzles', '/campaigns', '/seasons', '/leaderboard', '/store', '/competitions', '/team-championships', '/terms', '/privacy', '/challenges']);
dataset('E23 player surfaces', ['/friends/search', '/friends/challenges', '/notifications/preferences', '/redemption/create', '/quests', '/teams/create', '/redemption']);

/** الأسطح المهاجَرة بـE23: لا ألوان hex ثابتة ولا خلفيات بيضاء معتمة ولا فئات ink القديمة (تكسر الثيم الفاتح). */
function e23MigratedViews(): array
{
    return ['puzzles/index', 'challenges/index', 'campaigns/index', 'seasons/index', 'leaderboard/index', 'friends/search', 'friends/challenges/index', 'friends/challenges/create',
        'notifications/preferences', 'redemption/create', 'redemption/index', 'players/competitive', 'teams/create', 'teams/challenges/create', 'teams/manage', 'teams/show',
        'quests/show', 'pages/privacy', 'pages/terms', 'competitions/_card', 'competitions/show', 'team-championships/index', 'store/index', 'chat/room', 'messages/_sidebar',
        'components/status-badge', 'components/campaign-state-badge', 'components/challenge-status-badge', 'components/difficulty-badge', 'components/primary-button',
        'components/secondary-button', 'components/danger-button', 'components/team-challenge-row'];
}

test('SC1: every public list and legal page has exactly one h1, inside main, from the shared page header', function (string $url) {
    $x = uiXpath($this->get($url)->assertOk()->getContent());

    expect($x->query('//h1')->length)->toBe(1)->and($x->query('//main//h1')->length)->toBe(1);
})->with('E23 public surfaces');

test('SC2: every migrated player page has exactly one h1 inside main and no emoji-only headings', function (string $url) {
    $html = $this->actingAs(e16User())->get($url)->assertOk()->getContent();
    $x = uiXpath($html);
    $h1 = $x->query('//main//h1');

    expect($x->query('//h1')->length)->toBe(1)->and($h1->length)->toBe(1)
        ->and(trim($h1->item(0)->textContent))->not->toBe('')->and(preg_match('/\p{So}/u', $h1->item(0)->textContent))->toBe(0);
})->with('E23 player surfaces');

test('SC3: the auth pages share the themed guest shell - tokens, theme script, fonts - and no celebratory emoji', function (string $url) {
    $html = $this->get($url)->assertOk()->getContent();

    expect($html)->toContain('--color-primary')->toContain('--font-body')->toContain('ahjiyat-theme')->not->toContain('🎉')
        ->and(uiXpath($html)->query('//h1')->length)->toBe(1)
        ->and(e23A11yProblems($html))->toBe([]);
})->with(['/login', '/register', '/forgot-password']);

test('SC4: the status badge never relies on colour alone - text is always present, the icon is optional, and every tone is token based', function () {
    $html = Blade::render('<x-status-badge tone="warning" icon="lock">قريبًا</x-status-badge><x-status-badge tone="success">مباشر</x-status-badge><x-status-badge tone="danger" small>مرفوض</x-status-badge><x-status-badge tone="primary" dashed>متاح</x-status-badge><x-status-badge>انتهى</x-status-badge>');

    expect(e23VisibleText($html))->toBe('قريبًا مباشر مرفوض متاح انتهى')
        ->and(substr_count($html, '<svg'))->toBe(1)
        ->and($html)->toContain('text-gold')->toContain('text-emerald')->toContain('text-rose')->toContain('text-amethyst')->toContain('text-slate-300')->toContain('border-dashed')
        ->and($html)->not->toMatch('/#[0-9a-fA-F]{3,8}\b/');
});

test('SC5: an unknown badge tone falls back to the neutral tone instead of rendering unstyled', function () {
    $html = Blade::render('<x-status-badge tone="nonsense">نص</x-status-badge>');

    expect($html)->toContain('text-slate-300')->toContain('rounded-full');
});

test('SC6: campaign, challenge and difficulty badges are one family - they delegate to the shared status badge and keep their wording', function () {
    $campaign = Blade::render('<x-campaign-state-badge state="locked" />');

    expect(e23VisibleText($campaign))->toBe('مقفلة')->and($campaign)->toContain('<svg')->toContain('rounded-full');

    foreach (['campaign-state-badge', 'challenge-status-badge', 'difficulty-badge'] as $component) {
        expect(e23Source('views/components/'.$component.'.blade.php'))->toContain('<x-status-badge');
    }

    expect(e23VisibleText(Blade::render('<x-campaign-state-badge state="completed" />')))->toBe('مكتملة');
});

test('SC7: the button hierarchy is defined once in the theme - primary gem, secondary, danger - with focus ring, disabled state and reduced motion', function () {
    $css = e23Css();

    foreach (['.btn-secondary', '.btn-danger', '.btn-icon'] as $selector) {
        expect(e23Rule($css, $selector))->not->toBe('');
    }

    expect($css)->toContain('.btn-secondary:focus-visible')->toContain('.btn-danger:focus-visible')->toContain('.btn-gem:disabled')->toContain('.btn-secondary:disabled')
        ->and(Blade::render('<x-primary-button>أ</x-primary-button>'))->toContain('btn-gem')
        ->and(Blade::render('<x-secondary-button>ب</x-secondary-button>'))->toContain('btn-secondary')->toContain('type="button"')
        ->and(Blade::render('<x-danger-button>ج</x-danger-button>'))->toContain('btn-danger');
});

test('SC8: the campaigns index shows state badges and keeps campaign cards as real links', function () {
    Campaign::factory()->create(['is_active' => true, 'title' => 'حملة السطح']);

    $html = $this->get(route('campaigns.index'))->assertOk()->getContent();

    expect(e23VisibleText($html))->toContain('حملة السطح')->and(uiXpath($html)->query('//main//a[contains(@href,"/campaigns/")]')->length)->toBeGreaterThan(0);
});

test('SC9: the seasons index renders without the old per-view colour map and keeps its read-only listing', function () {
    $campaign = Campaign::factory()->create(['is_active' => true, 'title' => 'موسم السطح']);
    Season::factory()->create(['campaign_id' => $campaign->id, 'is_published' => true]);

    $html = $this->get(route('seasons.index'))->assertOk()->getContent();

    expect(e23VisibleText($html))->toContain('موسم السطح')->and(e23Source('views/seasons/index.blade.php'))->not->toContain('$stateColor');
});

test('SC10: the team page names its status with a badge and an icon, and the challenge call-to-action uses a drawn icon, not an emoji', function () {
    $team = e19Team(null, ['name' => 'فريق الأسطح']);
    $owner = $team->owner;

    $active = $this->actingAs(e16User())->get(route('teams.show', $team))->assertOk()->getContent();
    $team->forceFill(['is_active' => false])->save();
    $inactive = $this->actingAs($owner)->get(route('teams.show', $team))->assertOk()->getContent();

    expect(e23VisibleText($inactive))->toContain('غير مفعَّل')->and(e23VisibleText($active))->not->toContain('غير مفعَّل')
        ->and(uiXpath($active)->query('//h1')->length)->toBe(1)
        ->and(e23Source('views/teams/show.blade.php'))->not->toContain('⚔️');
});

test('SC11: the competition page keeps one h1 with its title, a status badge, and a named tab bar that marks the active tab', function () {
    $event = e17Event(['title' => 'منافسة السطح']);
    $html = $this->get(route('competitions.show', $event))->assertOk()->getContent();
    $x = uiXpath($html);
    $source = e23Source('views/competitions/show.blade.php');

    expect($x->query('//h1')->length)->toBe(1)->and($x->query('//main//h1')->item(0)->textContent)->toContain('منافسة السطح')
        ->and(e23VisibleText($html))->toContain('منافسة السطح')
        ->and($source)->toContain('<x-status-badge')->toContain('aria-label="عرض الترتيب"')->toContain("@if (\$tab === 'players') aria-current=\"page\" @endif");
});

test('SC12: notification preferences expose every control with a label and one clear save action', function () {
    $x = uiXpath($this->actingAs(e16User())->get(route('notifications.preferences'))->assertOk()->getContent());

    expect($x->query('//h1')->length)->toBe(1)->and($x->query('//main//form//button[@type="submit"] | //main//form//input[@type="submit"]')->length)->toBeGreaterThan(0)
        ->and(e23A11yProblems($this->actingAs(e16User())->get(route('notifications.preferences'))->getContent()))->toBe([]);
});

test('SC13: the legal pages use calm cards, a labelled table of contents that sticks under the real header, and every anchor has a target', function (string $url) {
    $html = $this->get($url)->assertOk()->getContent();
    $x = uiXpath($html);
    $targets = uiAttrs($x, '//nav[@aria-label="محتويات الصفحة"]//a', 'href');

    expect($x->query('//section[contains(@class,"legal-card")]')->length)->toBeGreaterThan(3)
        ->and($targets)->not->toBeEmpty()
        ->and($x->query('//nav[@aria-label="محتويات الصفحة"]//*[contains(@class,"sticky-under-header")]')->length)->toBe(1);

    foreach ($targets as $href) {
        expect($x->query('//*[@id="'.ltrim($href, '#').'"]')->length)->toBe(1);
    }
})->with(['/terms', '/privacy']);

test('SC14: the legal card and footer are theme-token surfaces, not new palettes', function () {
    $css = e23Css();

    expect(e23Norm(e23Rule($css, '.legal-card')))->toContain('var(--color-surface)')->toContain('var(--color-surface-border)')->toContain('scroll-margin-top')
        ->and(e23Rule($css, '.app-footer'))->toContain('var(--');
});

test('SC15: migrated views use theme tokens only - no hex colours, no opaque white or ink utilities that break the light theme', function () {
    $offenders = [];

    foreach (e23MigratedViews() as $view) {
        $source = e19Code(resource_path('views/'.$view.'.blade.php'));

        if (preg_match('/(?<![&\w])#[0-9a-fA-F]{6}\b|(?<![&\w])#[0-9a-fA-F]{3}\b(?![\w-])/', preg_replace('/&#\d+;|href="#[^"]*"|\{\{ *route.*?\}\}|#\{\{.*?\}\}/u', '', $source), $m)) {
            $offenders[] = $view.' => '.$m[0];
        }

        if (preg_match('/(^|[\s"\'])(bg-white|text-ink|bg-ink)(?=[\s"\'])/', $source, $m)) {
            $offenders[] = $view.' => '.$m[2];
        }
    }

    expect($offenders)->toBe([]);
});

test('SC16: empty states are the shared component - icon, title, hint - on redemptions, championships and the store', function () {
    $player = e16User();

    $redemptions = e23VisibleText($this->actingAs($player)->get(route('redemption.index'))->assertOk()->getContent());
    $championships = e23VisibleText($this->get(route('team-championships.index'))->assertOk()->getContent());

    expect($redemptions)->toContain('لا توجد طلبات استبدال بعد')->toContain('عندما يصل رصيدك المتاح إلى الحد الأدنى')
        ->and($championships)->toContain('لا بطولات جارية الآن')->toContain('ستظهر البطولات القادمة هنا');

    foreach (['redemption/index', 'team-championships/index', 'store/index'] as $view) {
        expect(e23Source('views/'.$view.'.blade.php'))->toContain('<x-empty-state');
    }
});

test('SC17: the global page shell is unchanged for content - one skip link, main landmark, footer landmark, and the footer is a themed surface', function () {
    $x = uiXpath($this->get(route('puzzles.index'))->getContent());

    expect($x->query('//a[@data-skip-link]')->length)->toBe(1)->and($x->query('//main[@id="main-content"]')->length)->toBe(1)
        ->and($x->query('//footer[contains(@class,"app-footer")]')->length)->toBe(1);
});

test('SC18: the featured season hero never adds a second h1 to the guest home, and it is still the page h1 on its own season page', function () {
    $campaign = Campaign::factory()->create(['is_active' => true, 'title' => 'حملة الموسم المميّز']);
    $season = Season::factory()->create(['campaign_id' => $campaign->id, 'is_published' => true, 'is_featured' => true]);

    $home = uiXpath($this->get(route('home'))->assertOk()->getContent());
    $page = uiXpath($this->get(route('seasons.show', $season))->assertOk()->getContent());

    expect($home->query('//h1')->length)->toBe(1)
        ->and($home->query('//main//h2[contains(., "حملة الموسم المميّز")]')->length)->toBe(1)
        ->and($page->query('//h1')->length)->toBe(1)
        ->and($page->query('//main//h1[contains(., "حملة الموسم المميّز")]')->length)->toBe(1);
});
