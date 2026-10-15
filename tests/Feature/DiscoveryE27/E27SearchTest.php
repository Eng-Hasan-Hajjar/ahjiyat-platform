<?php

require_once __DIR__.'/_support.php';

use App\Models\CompetitiveEvent;
use App\Models\Puzzle;
use App\Models\Team;
use App\Models\TeamChampionship;
use App\Models\User;

beforeEach(fn () => $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class));

test('1: the suggest endpoint answers a guest with grouped puzzles, relative URLs only and no internal ids', function () {
    $puzzle = e27_puzzle('لغز الذكاء الكبير');
    $json = e27_suggest($this, 'لغز');

    expect($json['state'])->toBe('ok')->and(e27_titles($json))->toBe(['لغز الذكاء الكبير']);
    $item = $json['groups'][0]['items'][0];
    expect($item['url'])->toBe('/puzzles/'.$puzzle->id)->and(array_keys($item))->toEqualCanonicalizing(['title', 'subtitle', 'badge', 'icon', 'url']);
    expect(json_encode($json))->not->toContain('http://')->not->toContain('"id"');
});

test('2: puzzle search never reads or exposes answers, hints, choices or solution data', function () {
    $secret = 'جواب-سري-فريد';
    $puzzle = e27_puzzle('أحجية عادية', ['answer_raw' => $secret, 'hint' => 'تلميح-سري-فريد']);

    expect(e27_titles(e27_suggest($this, 'جواب-سري')))->toBe([])
        ->and(e27_titles(e27_suggest($this, 'تلميح-سري')))->toBe([])
        ->and(e27_titles(e27_suggest($this, 'أحجية')))->toBe(['أحجية عادية']);

    $body = $this->getJson(route('search.suggest', ['q' => 'أحجية']))->getContent();
    expect($body)->not->toContain($secret)->not->toContain('تلميح-سري-فريد')->not->toContain((string) $puzzle->answer_hash)
        ->not->toContain(trim(json_encode($secret), '"'))->not->toContain(trim(json_encode('تلميح-سري-فريد'), '"'));   // JSON يهرّب العربية: نفحص الصيغة المهرَّبة أيضًا
});

test('3: inactive puzzles never appear, for guests, players or administrators alike', function () {
    e27_puzzle('أحجية مخفية', ['is_active' => false]);
    e27_puzzle('أحجية ظاهرة');
    $admin = e27_user();
    $admin->assignRole('administrator');

    foreach ([null, e27_user(), $admin] as $viewer) {
        expect(e27_titles(e27_suggest($this, 'أحجية', $viewer)))->toBe(['أحجية ظاهرة']);
    }
});

test('4: campaigns follow the public list rule - active and inside their window only', function () {
    e27_campaign('حملة مباشرة');
    e27_campaign('حملة مسودة', ['is_active' => false]);
    e27_campaign('حملة قادمة', ['starts_at' => now()->addDay()]);
    e27_campaign('حملة منتهية', ['ends_at' => now()->subDay()]);
    e27_campaign('حملة بنافذة', ['starts_at' => now()->subDay(), 'ends_at' => now()->addDay()]);

    expect(e27_group(e27_suggest($this, 'حملة'), 'campaigns'))->toEqualCanonicalizing(['حملة مباشرة', 'حملة بنافذة']);
});

test('5: seasons follow is_published and are titled from their campaign', function () {
    e27_season('موسم الربيع');
    e27_season('موسم مسودة', ['is_published' => false]);

    $json = e27_suggest($this, 'موسم');

    $group = collect($json['groups'])->firstWhere('type', 'seasons');
    expect(e27_group($json, 'seasons'))->toBe(['موسم الربيع'])->and($group['items'][0]['url'])->toStartWith('/seasons/')
        ->and(e27_group($json, 'campaigns'))->toBe(['موسم مسودة']);   // حملة الموسم المنشور تظهر كموسم فقط؛ حملة موسم غير منشور حملة عادية نشطة كما بقائمة /campaigns
});

test('6: competitions and team championships show only published or completed ones', function () {
    e27_user();
    e17Event(['title' => 'منافسة السطح']);
    e17Event(['title' => 'منافسة المسودة'], CompetitiveEvent::STATUS_DRAFT);
    e17Event(['title' => 'منافسة الملغاة'], CompetitiveEvent::STATUS_CANCELLED);
    e17Event(['title' => 'منافسة مكتملة'], CompetitiveEvent::STATUS_COMPLETED);

    foreach (['بطولة منشورة' => 'published', 'بطولة مسودة' => 'draft', 'بطولة ملغاة' => 'cancelled'] as $title => $status) {
        TeamChampionship::query()->forceCreate(['title' => $title, 'slug' => 'c-'.md5($title), 'status' => $status, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay()]);
    }

    expect(e27_group(e27_suggest($this, 'منافسة'), 'competitions'))->toEqualCanonicalizing(['منافسة السطح', 'منافسة مكتملة'])
        ->and(e27_group(e27_suggest($this, 'بطولة'), 'championships'))->toBe(['بطولة منشورة']);
});

test('7: only public active teams are discoverable - private, inactive and unrelated ones are not', function () {
    e19Team(null, ['name' => 'فريق النجوم']);
    $private = e19Team(null, ['name' => 'فريق سري']);
    $private->forceFill(['visibility' => Team::VISIBILITY_PRIVATE])->save();
    $off = e19Team(null, ['name' => 'فريق متوقف']);
    $off->forceFill(['is_active' => false])->save();

    $json = e27_suggest($this, 'فريق');
    expect(e27_group($json, 'teams'))->toBe(['فريق النجوم'])->and(json_encode($json))->not->toContain('owner')->not->toContain('email');
});

test('8: player search shows only people the searcher may see - public for guests, public and members for verified players, never private', function () {
    e27_user(['name' => 'سامر العام', 'profile_visibility' => User::VISIBILITY_PUBLIC]);
    e27_user(['name' => 'سامر الأعضاء', 'profile_visibility' => User::VISIBILITY_MEMBERS]);
    e27_user(['name' => 'سامر الخاص', 'profile_visibility' => User::VISIBILITY_PRIVATE]);

    expect(e27_group(e27_suggest($this, 'سامر'), 'players'))->toBe(['سامر العام'])
        ->and(e27_group(e27_suggest($this, 'سامر', e27_user(['name' => 'باحث'])), 'players'))->toEqualCanonicalizing(['سامر العام', 'سامر الأعضاء']);
});

test('9: player search excludes the searcher, frozen and unverified accounts', function () {
    $me = e27_user(['name' => 'ليلى أنا']);
    e27_user(['name' => 'ليلى مجمدة', 'is_frozen' => true]);
    e27_user(['name' => 'ليلى غير موثقة', 'email_verified_at' => null]);
    e27_user(['name' => 'ليلى سليمة']);

    expect(e27_group(e27_suggest($this, 'ليلى', $me), 'players'))->toBe(['ليلى سليمة']);
});

test('10: blocking hides players in both directions and never leaks that a block exists', function () {
    $me = e27_user(['name' => 'باحث']);
    $iBlocked = e27_user(['name' => 'منى محظورة']);
    $blockedMe = e27_user(['name' => 'منى حاظرة']);
    e27_user(['name' => 'منى عادية']);
    app(\App\Services\Social\BlockService::class)->block($me, $iBlocked);
    app(\App\Services\Social\BlockService::class)->block($blockedMe, $me);

    $json = e27_suggest($this, 'منى', $me);
    expect(e27_group($json, 'players'))->toBe(['منى عادية'])->and(json_encode($json))->not->toContain('block');
});

test('11: player results carry only the display name and a ULID profile link - no email, phone, numeric id or wallet', function () {
    $other = e27_user(['name' => 'ريم حسابها', 'email' => 'secret-rim@example.test']);
    $json = e27_suggest($this, 'ريم', e27_user(['name' => 'باحث']));

    $item = $json['groups'][0]['items'][0];
    expect($item['url'])->toBe('/players/'.$other->public_id);
    expect(json_encode($json))->not->toContain('secret-rim')->not->toContain('example.test')->not->toContain('"id"')->not->toContain('wallet');
    $html = $this->actingAs(e27_user())->get(route('search.index', ['q' => 'ريم']))->assertOk()->getContent();
    expect($html)->not->toContain('secret-rim')->and($html)->toContain('/players/'.$other->public_id);
});

test('12: a guest needs three letters to search players while other types answer from two', function () {
    e27_puzzle('نمر الغابة');
    e27_user(['name' => 'نمر اللاعب']);

    $two = e27_suggest($this, 'نم');
    expect(e27_types($two))->toBe(['puzzles'])->and($two['note'])->toContain('3');
    expect(e27_types(e27_suggest($this, 'نمر')))->toBe(['puzzles', 'players']);
    expect(e27_types(e27_suggest($this, 'نم', e27_user())))->toBe(['puzzles', 'players']);
});

test('13: empty, one-letter and whitespace-only input answer empty or too_short without touching the database', function () {
    e27_puzzle('أحجية');
    $queries = e27_queries(function () {
        foreach (['' => 'empty', ' ' => 'empty', 'أ' => 'too_short', "\t\n" => 'empty', '%' => 'too_short'] as $q => $state) {
            expect($this->getJson(route('search.suggest', ['q' => $q]))->assertOk()->json('state'))->toBe($state);
        }
    });

    expect($queries)->toBe(0);
});

test('14: Arabic variants find each other - hamza, ta marbuta, alef maksura, tatweel and diacritics are ignored', function (string $typed) {
    e27_puzzle('أُحْجِيَّةُ المدينة');

    expect(e27_group(e27_suggest($this, $typed), 'puzzles'))->toBe(['أُحْجِيَّةُ المدينة']);
})->with(['احجيه', 'أحجية', 'احجية', 'ـأحـجـيـة', 'احجيه المدينه', 'مدينه', 'الاحجية', 'المدينة']);

test('15: Arabic-Indic and Persian digits match ASCII digits', function () {
    e27_puzzle('لغز رقم 12');

    foreach (['رقم ١٢', 'رقم ۱۲', 'رقم 12'] as $q) {
        expect(e27_group(e27_suggest($this, $q), 'puzzles'))->toBe(['لغز رقم 12'], $q);
    }
});

test('16: Latin text matches case-insensitively and Turkish dotted/dotless i fold together', function () {
    e27_puzzle('Istanbul Riddle');

    foreach (['istanbul', 'ISTANBUL', 'İstanbul', 'ıstanbul', 'riddle'] as $q) {
        expect(e27_group(e27_suggest($this, $q), 'puzzles'))->toBe(['Istanbul Riddle'], $q);
    }
});

test('17: zero-width and control characters inside the term are removed before matching', function () {
    e27_puzzle('لغز البحر');

    foreach (["لغز\u{200B}البحر", "لغز\u{200F} البحر", "لغز\x00البحر", "\u{FEFF}لغز البحر"] as $q) {
        expect(e27_group(e27_suggest($this, $q), 'puzzles'))->toBe(['لغز البحر']);
    }
});

test('18: LIKE wildcards in the term are literal - % and _ never match everything', function () {
    e27_puzzle('لغز عادي');
    e27_puzzle('نسبة 100% صحيحة');
    e27_puzzle('اسم_مخصوص');

    expect(e27_titles(e27_suggest($this, '%%')))->toBe([])
        ->and(e27_titles(e27_suggest($this, '__')))->toBe([])
        ->and(e27_group(e27_suggest($this, '100%'), 'puzzles'))->toBe(['نسبة 100% صحيحة'])
        ->and(e27_group(e27_suggest($this, 'اسم_م'), 'puzzles'))->toBe(['اسم_مخصوص']);
});

test('19: relevance orders exact first, then prefix, then word prefix, then partial - ties break by newest', function () {
    e27_puzzle('الحوت الأزرق في البحر');   // partial (داخل «البحر»)
    e27_puzzle('بحرا');                       // prefix
    e27_puzzle('بحر');                        // exact
    e27_puzzle('سبحر عميق');                  // partial
    e27_puzzle('بحر الظلمات');                // prefix, newer than 'بحرا'
    e27_puzzle('عالم بحر الحكايات');          // word prefix

    $titles = app(\App\Services\Search\GlobalSearchService::class)->ofType('puzzles', 'بحر', null, 1)['results']->pluck('title')->all();
    $tiers = app(\App\Services\Search\GlobalSearchService::class)->ofType('puzzles', 'بحر', null, 1)['results']->pluck('tier')->all();

    expect($titles)->toBe(['بحر', 'بحر الظلمات', 'بحرا', 'عالم بحر الحكايات', 'سبحر عميق', 'الحوت الأزرق في البحر'])->and($tiers)->toBe([0, 1, 1, 2, 3, 3]);
});

test('20: tiers are exposed to the page ordering - a group holding an exact match comes before groups holding only partial ones', function () {
    e27_puzzle('لغز فريق النجوم');           // puzzles: partial (word)
    e19Team(null, ['name' => 'فريق']);       // teams: exact

    $json = e27_suggest($this, 'فريق');
    expect(e27_types($json))->toBe(['teams', 'puzzles']);
});

test('21: a multi-word term needs every word, in any order', function () {
    e27_puzzle('لغز الذكاء الكبير');
    e27_puzzle('لغز الذكاء فقط');

    expect(e27_group(e27_suggest($this, 'كبير ذكاء'), 'puzzles'))->toBe(['لغز الذكاء الكبير']);
});

test('22: suggestions are capped per type and in total, with a link to the full list when more exist', function () {
    foreach (range(1, 8) as $n) {
        e27_puzzle("لغز رقم {$n}");
    }
    foreach (range(1, 8) as $n) {
        e19Team(null, ['name' => "لغز فريق {$n}"]);
    }
    $json = e27_suggest($this, 'لغز');

    expect(count(e27_titles($json)))->toBeLessThanOrEqual(9)->and(count(e27_group($json, 'puzzles')))->toBeLessThanOrEqual(3)
        ->and(collect($json['groups'])->firstWhere('type', 'puzzles')['more_url'])->toBe('/search?q=%D9%84%D8%BA%D8%B2&type=puzzles');
});

test('23: the full search page works without JavaScript - a plain GET form, grouped results, tabs and one h1', function () {
    e27_puzzle('لغز البحر');
    e19Team(null, ['name' => 'لغز الفريق']);
    $x = uiXpath($this->get(route('search.index', ['q' => 'لغز']))->assertOk()->getContent());

    expect($x->query('//h1')->length)->toBe(1)
        ->and($x->query('//main//form[@method="GET"][@role="search"][@action="'.route('search.index').'"]//input[@name="q"]')->length)->toBe(1)
        ->and($x->query('//main//section[@aria-label="الأحجيات"]//a')->length)->toBe(1)
        ->and($x->query('//main//section[@aria-label="الفرق"]//a')->length)->toBe(1)
        ->and($x->query('//nav[@aria-label="نوع النتائج"]//a[@aria-current="page"]')->item(0)->textContent)->toBe('الكل');
});

test('24: a type tab shows only that type, keeps the term in its links and paginates without a COUNT', function () {
    foreach (range(1, 14) as $n) {
        e27_puzzle("لغز {$n}");
    }
    $page1 = $this->get(route('search.index', ['q' => 'لغز', 'type' => 'puzzles']))->assertOk();
    $page2 = $this->get(route('search.index', ['q' => 'لغز', 'type' => 'puzzles', 'page' => 2]))->assertOk();

    $x1 = uiXpath($page1->getContent());
    expect($x1->query('//main//section//ul/li')->length)->toBe(12)->and($x1->query('//a[@rel="next"]')->length)->toBe(1);
    expect(uiXpath($page2->getContent())->query('//main//section//ul/li')->length)->toBe(2);
    expect(uiXpath($page2->getContent())->query('//a[@rel="next"]')->length)->toBe(0);
});

test('25: result pages stop at the depth cap and say so - guests reach fewer pages than players', function () {
    config(['search.page_size' => 2, 'search.max_page' => 3, 'search.guest_max_page' => 2]);
    foreach (range(1, 12) as $n) {
        e27_puzzle("لغز {$n}");
    }

    $guest = $this->get(route('search.index', ['q' => 'لغز', 'type' => 'puzzles', 'page' => 9]))->assertOk();
    $player = $this->actingAs(e27_user())->get(route('search.index', ['q' => 'لغز', 'type' => 'puzzles', 'page' => 9]))->assertOk();

    expect(uiXpath($guest->getContent())->query('//a[@rel="next"]')->length)->toBe(0)->and($guest->getContent())->toContain('أعلى النتائج صلة');
    expect(uiXpath($player->getContent())->query('//a[@rel="next"]')->length)->toBe(0)->and($player->getContent())->toContain('أعلى النتائج صلة');
    // الصفحة الفعلية المعروضة = السقف، لا الصفحة المطلوبة
    expect(uiXpath($guest->getContent())->query('//main//section//ul/li')->length)->toBe(2);
});

test('26: hostile or malformed parameters never fail - arrays, unknown types, huge pages and invalid UTF-8 are tolerated', function () {
    e27_puzzle('لغز');

    foreach ([['q' => ['a', 'b']], ['q' => 'لغز', 'type' => 'users'], ['q' => 'لغز', 'type' => ['x']], ['q' => 'لغز', 'page' => '999999999'], ['q' => 'لغز', 'page' => '-1'], ['q' => 'لغز', 'page' => ['1']], ['q' => "\xC3\x28"], ['q' => str_repeat('ا', 5000)]] as $params) {
        $this->get(route('search.index', $params))->assertOk();
        $this->getJson(route('search.suggest', $params))->assertOk();
    }
});

test('27: very long terms are truncated to the configured length, not rejected', function () {
    e27_puzzle(str_repeat('ا', 70));
    $json = e27_suggest($this, str_repeat('ا', 300));

    expect(mb_strlen($json['query']))->toBe(64)->and(e27_titles($json))->toBe([str_repeat('ا', 70)]);
});

test('28: with no match the page says so and offers a way forward instead of an empty screen', function () {
    $html = $this->get(route('search.index', ['q' => 'لاشيء-مطابق']))->assertOk()->getContent();

    expect($html)->toContain('لا نتائج لـ')->and($html)->toContain(route('discover.index'));
});

test('29: a term below the minimum explains the minimum and a missing term invites typing', function () {
    expect($this->get(route('search.index', ['q' => 'أ']))->assertOk()->getContent())->toContain('الكلمة قصيرة جدًا')
        ->and($this->get(route('search.index'))->assertOk()->getContent())->toContain('ابدأ بكتابة ما تبحث عنه');
});

test('30: the term is echoed escaped on the page and inside the input', function () {
    $payload = '"><script>alert(1)</script><img src=x onerror=alert(2)>';
    $html = $this->get(route('search.index', ['q' => $payload]))->assertOk()->getContent();

    expect($html)->not->toContain('<script>alert(1)')->not->toContain('<img src=x onerror');
});

test('31: result titles with markup are escaped on the page and kept as plain text in JSON', function () {
    e27_puzzle('<script>alert(1)</script> لغز');
    $html = $this->get(route('search.index', ['q' => 'script']))->assertOk()->getContent();

    expect($html)->not->toContain('<script>alert(1)</script> لغز');
    expect(e27_titles(e27_suggest($this, 'script')))->toBe(['<script>alert(1)</script> لغز']);
});

test('32: the suggest response is private and never cacheable by shared caches', function () {
    $response = $this->getJson(route('search.suggest', ['q' => 'لغز']))->assertOk();

    expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
});

test('33: search is read-only - no write queries run and no table changes', function () {
    e27_puzzle('لغز للقراءة');
    e27_user(['name' => 'لاعب للقراءة']);
    $viewer = e27_user();

    $sql = e27_sql(function () use ($viewer) {
        $this->getJson(route('search.suggest', ['q' => 'للقراءة']))->assertOk();
        $this->get(route('search.index', ['q' => 'للقراءة']))->assertOk();
        $this->actingAs($viewer)->get(route('search.index', ['q' => 'للقراءة', 'type' => 'players']))->assertOk();
    });

    // الكاش array والجلسة array: أي كتابة هنا خلل، عدا ما يفعله إطار الصفحة القائم لأي صفحة (E11/E12: إنشاء صف مستوى اللاعب عند أول عرض للرأس)، وليس من البحث.
    $writes = array_values(array_filter($sql, fn ($q) => preg_match('/^\s*(insert|update|delete|replace)\b/i', $q) && ! str_contains($q, '"player_progressions"')));
    expect($writes)->toBe([]);
});

test('34: a banned or frozen searcher still gets only public results and no private player data', function () {
    $frozen = e27_user(['name' => 'مجمّد', 'is_frozen' => true]);
    e27_user(['name' => 'سلمى الأعضاء', 'profile_visibility' => User::VISIBILITY_MEMBERS]);
    e27_user(['name' => 'سلمى العامة']);

    expect(e27_group(e27_suggest($this, 'سلمى', $frozen), 'players'))->toBe(['سلمى العامة']);
});

test('35: an unverified searcher is treated like a guest for player results', function () {
    $unverified = e27_user(['name' => 'غير موثق', 'email_verified_at' => null]);
    e27_user(['name' => 'هالة الأعضاء', 'profile_visibility' => User::VISIBILITY_MEMBERS]);
    e27_user(['name' => 'هالة العامة']);

    expect(e27_group(e27_suggest($this, 'هالة', $unverified), 'players'))->toBe(['هالة العامة']);
});
