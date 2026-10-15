<?php

require_once __DIR__.'/_support.php';

use App\Models\Team;
use Illuminate\Support\Facades\DB;

/*
| E27-B - مركز الاكتشاف /discover: أقسام محدودة من الأنظمة القائمة بنفس شروط ظهورها العامة؛ للضيف عام، وللمسجَّل «تابع» و«مقترحة لك». قراءة فقط.
*/

beforeEach(fn () => $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class));

if (! function_exists('e27_sections')) {
    /** @return list<string> مفاتيح الأقسام بترتيب ظهورها. */
    function e27_sections(string $html): array
    {
        return uiAttrs(uiXpath($html), '//section[@data-discover-section]', 'data-discover-section');
    }

    function e27_section_cards(string $html, string $key): int
    {
        return uiXpath($html)->query('//section[@data-discover-section="'.$key.'"]//a[h3]')->length;
    }
}

test('D1: a guest sees the public hub - trending, new, campaigns - with no personal sections, a search form and category chips', function () {
    $category = e27_category('أحاجي');
    e27_pool($category, 3, 'NEW');
    e27_campaign('حملة متاحة');
    e27_play(e27_pool($category, 1, 'HOT')[0], 2);

    $response = $this->get(route('discover.index'))->assertOk();
    $html = $response->getContent();
    $x = uiXpath($html);

    expect(e27_sections($html))->toContain('trending')->toContain('new')->toContain('campaigns')->not->toContain('continue')->not->toContain('for-you')
        ->and(e27_a11y_problems($html))->toBe([])
        ->and($x->query('//form[@role="search" and @method="GET" or @role="search" and @method="get"]')->length)->toBe(1)
        ->and($x->query('//nav[@aria-label="تصنيفات الأحجيات"]//a')->length)->toBe(1);
    $response->assertSee('سجّل دخولك لتحصل على مقترحات تناسبك');
});

test('D2: a signed-in player also gets continue and recommended sections, and the page is accessible', function () {
    $category = e27_category('منطق');
    $user = e27_user();
    e27_solve($user, e27_pool($category, 1, 'SOLVED')[0]);
    e27_attempt($user, e27_pool($category, 1, 'TRYING')[0], false, now()->subHour());
    e27_pool($category, 6, 'MORE');

    $html = $this->actingAs($user)->get(route('discover.index'))->assertOk()->assertDontSee('سجّل دخولك لتحصل على مقترحات تناسبك')->getContent();
    $sections = e27_sections($html);

    expect($sections)->toContain('continue')->toContain('for-you')
        ->and(array_search('continue', $sections))->toBeLessThan(array_search('for-you', $sections))
        ->and(e27_a11y_problems($html))->toBe([])
        ->and(uiXpath($html)->query('//section[@data-discover-section="continue"]')->item(0)->textContent)->toContain('TRYING-1')->toContain('محاولاتك لم تنتهِ');
});

test('D3: nothing hidden elsewhere appears on the hub - inactive, draft, expired, future, private and stopped items', function (bool $signedIn) {
    $category = e27_category('رؤية');
    e27_pool($category, 1, 'VISIBLE-PUZZLE');
    e27_pool($category, 1, 'HIDDEN-PUZZLE', 'easy', ['is_active' => false]);
    e27_campaign('VISIBLE-CAMPAIGN');
    e27_campaign('HIDDEN-INACTIVE-CAMPAIGN', ['is_active' => false]);
    e27_campaign('HIDDEN-EXPIRED-CAMPAIGN', ['ends_at' => now()->subDay()]);
    e27_campaign('HIDDEN-FUTURE-CAMPAIGN', ['starts_at' => now()->addDay()]);
    e27_season('VISIBLE-SEASON');
    e27_season('HIDDEN-DRAFT-SEASON', ['is_published' => false], ['is_active' => false]);
    $private = e19Team(null, ['name' => 'HIDDEN-PRIVATE-TEAM']);
    $private->forceFill(['visibility' => Team::VISIBILITY_PRIVATE])->save();
    $stopped = e19Team(null, ['name' => 'HIDDEN-STOPPED-TEAM']);
    $stopped->forceFill(['is_active' => false])->save();
    $event = e17Event(['title' => 'HIDDEN-DRAFT-EVENT'], \App\Models\CompetitiveEvent::STATUS_DRAFT);
    $visibleEvent = e17Event(['title' => 'VISIBLE-EVENT']);
    DB::table('competitive_events')->where('id', $visibleEvent->id)->update(['participants_count' => 4]);

    $html = ($signedIn ? $this->actingAs(e27_user()) : $this)->get(route('discover.index'))->assertOk()->getContent();

    expect($html)->toContain('VISIBLE-PUZZLE-1')->toContain('VISIBLE-CAMPAIGN')->toContain('VISIBLE-SEASON')->toContain('VISIBLE-EVENT')
        ->not->toContain('HIDDEN-');
})->with([[false], [true]]);

test('D4: every section is capped by configuration - a large catalogue never produces a long page', function () {
    $category = e27_category('كثير');
    e27_pool($category, 20, 'P');
    foreach (range(1, 10) as $i) {
        e27_campaign('C'.$i);
    }
    foreach (range(1, 7) as $i) {
        e27_season('S'.$i);
    }

    $html = $this->get(route('discover.index'))->assertOk()->getContent();

    expect(e27_section_cards($html, 'new'))->toBe(8)
        ->and(e27_section_cards($html, 'campaigns'))->toBeLessThanOrEqual(6)
        ->and(e27_section_cards($html, 'seasons'))->toBe(4);
});

test('D5: with nothing published the hub still answers with one heading and a way forward', function () {
    $html = $this->get(route('discover.index'))->assertOk()->getContent();

    expect(e27_sections($html))->toBe([])->and(e27_a11y_problems($html))->toBe([])->and($html)->toContain('لا محتوى للاكتشاف بعد')->toContain(route('puzzles.index'));
});

test('D6: the daily puzzle section shows today\'s puzzle only', function () {
    $category = e27_category('يومي');
    e27_pool($category, 1, 'TODAY', 'easy', ['is_daily_puzzle' => true, 'daily_puzzle_date' => today()]);
    e27_pool($category, 1, 'YESTERDAY', 'easy', ['is_daily_puzzle' => true, 'daily_puzzle_date' => today()->subDay()]);

    $section = uiXpath($this->get(route('discover.index'))->assertOk()->getContent())->query('//section[@data-discover-section="daily"]');

    expect($section->length)->toBe(1)->and($section->item(0)->textContent)->toContain('TODAY-1')->not->toContain('YESTERDAY-1');
});

test('D7: a campaign that backs a published season is shown once, as a season', function () {
    e27_season('موسم الحملة');
    e27_campaign('حملة مستقلة');

    $html = $this->get(route('discover.index'))->assertOk()->getContent();
    $x = uiXpath($html);

    expect($x->query('//section[@data-discover-section="seasons"]//h3[contains(., "موسم الحملة")]')->length)->toBe(1)
        ->and($x->query('//section[@data-discover-section="campaigns"]//h3[contains(., "موسم الحملة")]')->length)->toBe(0)
        ->and($x->query('//section[@data-discover-section="campaigns"]//h3[contains(., "حملة مستقلة")]')->length)->toBe(1);
});

test('D8: the hub contains no player entries at all - no profile links, names or avatars of other people', function () {
    $category = e27_category('لاعبون');
    $puzzle = e27_pool($category, 1, 'HOT')[0];
    foreach (e27_play($puzzle, 3) as $i => $player) {
        $player->forceFill(['name' => 'لاعب-خاص-'.$i])->save();
    }

    foreach ([$this, $this->actingAs(e27_user())] as $caller) {
        $html = $caller->get(route('discover.index'))->assertOk()->getContent();

        expect(uiXpath($html)->query('//main//a[starts-with(@href, "/players/") or contains(@href, "/players/")]')->length)->toBe(0)
            ->and($html)->not->toContain('لاعب-خاص');
    }
});

test('D9: titles are escaped - a markup title is text on the hub, never HTML', function () {
    $category = e27_category('هروب');
    e27_pool($category, 1, '<script>alert(1)</script><img src=x onerror=alert(2)>');
    e27_campaign('<b onmouseover=alert(3)>حملة</b>');

    $html = $this->get(route('discover.index'))->assertOk()->getContent();

    expect($html)->not->toContain('<script>alert(1)')->not->toContain('<img src=x onerror')->not->toContain('<b onmouseover');
});

test('D10: the hub is read-only - no write queries, for guests and players', function () {
    $category = e27_category('قراءة');
    $user = e27_user();
    e27_solve($user, e27_pool($category, 1, 'SOLVED')[0]);
    e27_pool($category, 4, 'MORE');

    $sql = e27_sql(function () use ($user) {
        $this->get(route('discover.index'))->assertOk();
        $this->actingAs($user)->get(route('discover.index'))->assertOk();
    });

    // عدا ما يفعله إطار الصفحة القائم لأي صفحة (E11/E12: صف مستوى اللاعب عند أول عرض للرأس).
    expect(array_values(array_filter($sql, fn ($q) => preg_match('/^\s*(insert|update|delete|replace)\b/i', $q) && ! str_contains($q, '"player_progressions"'))))->toBe([]);
});

test('D11: personal sections never leak between viewers - one player\'s continue list is invisible to a guest and to another player', function () {
    $category = e27_category('عزل');
    $a = e27_user();
    e27_attempt($a, e27_pool($category, 1, 'A-PRIVATE-TRY')[0], false, now()->subHour());
    $campaign = e27_campaign('A-PRIVATE-CAMPAIGN');
    e27_progress($a, $campaign);

    $own = $this->actingAs($a)->get(route('discover.index'))->assertOk()->getContent();
    expect($own)->toContain('محاولاتك لم تنتهِ')->toContain('تقدّمك محفوظ');

    auth()->logout();
    $guest = $this->get(route('discover.index'))->assertOk()->getContent();
    $b = $this->actingAs(e27_user())->get(route('discover.index'))->assertOk()->getContent();

    foreach ([$guest, $b] as $other) {
        expect($other)->not->toContain('محاولاتك لم تنتهِ')->not->toContain('تقدّمك محفوظ')->and(e27_sections($other))->not->toContain('continue');
    }
    expect(e27_sections($guest))->not->toContain('for-you');   // الضيف بلا مقترحات؛ اللاعب الجديد له مقترحات لكن من تاريخه هو (فارغ)
});

test('D12: only active categories are listed as chips, at most twelve, and each links to the existing category page', function () {
    foreach (range(1, 14) as $i) {
        e27_category('تصنيف '.$i);
    }
    $off = e27_category('تصنيف معطّل');
    $off->forceFill(['is_active' => false])->save();

    $x = uiXpath($this->get(route('discover.index'))->assertOk()->getContent());
    $links = uiAttrs($x, '//nav[@aria-label="تصنيفات الأحجيات"]//a', 'href');

    expect($links)->toHaveCount(12)->and(implode(' ', $links))->not->toContain($off->slug);
    foreach ($links as $href) {
        expect($href)->toContain('/puzzles/');
    }
});

test('D13: a frozen or unverified player gets a normal page, not an error', function () {
    $category = e27_category('حالات');
    e27_pool($category, 2, 'P');

    foreach ([e27_user(['is_frozen' => true]), e27_user(['email_verified_at' => null])] as $viewer) {
        expect($this->actingAs($viewer)->get(route('discover.index'))->status())->toBeLessThan(500);
    }
});
