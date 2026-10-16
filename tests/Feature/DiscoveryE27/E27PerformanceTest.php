<?php

require_once __DIR__.'/_support.php';

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| E27-E - الأداء. المبدأ: عدد الاستعلامات لا يتبع حجم البيانات (لا N+1)، السقوف مقيسة (لا مخمَّنة)، الرائج مخزَّن ولا يمسح أسخن جدول،
| والبحث بالبادئة قابل لاستعمال الفهرس. SQLite لا يقيس زمن MySQL؛ لذا الاختبارات هنا بنيوية (عدد، خطة، شكل SQL) لا زمنية.
*/

beforeEach(fn () => $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class));

if (! function_exists('e27_grow')) {
    /** يضيف $count عنصرًا من كل نوع قابل للبحث، كلها تطابق «نمط». */
    function e27_grow(int $from, int $to): void
    {
        $category = \App\Models\PuzzleCategory::query()->first() ?? e27_category('نمط');

        for ($i = $from + 1; $i <= $to; $i++) {
            e27_puzzle('نمط أحجية '.$i, ['puzzle_category_id' => $category->id]);
            e27_campaign('نمط حملة '.$i);
            e27_season('نمط موسم '.$i);
            e27_user(['name' => 'نمط لاعب '.$i]);
            e19Team(null, ['name' => 'نمط فريق '.$i]);
            e17Event(['title' => 'نمط منافسة '.$i]);
        }
    }

    /** @return array<string, callable> نقاط الدخول المقيسة. */
    function e27_endpoints($test, $viewer): array
    {
        return [
            'suggest guest' => fn () => $test->getJson(route('search.suggest', ['q' => 'نمط']))->assertOk(),
            'suggest player' => fn () => $test->actingAs($viewer)->getJson(route('search.suggest', ['q' => 'نمط']))->assertOk(),
            'search all (player)' => fn () => $test->actingAs($viewer)->get(route('search.index', ['q' => 'نمط']))->assertOk(),
            'search all (guest)' => fn () => $test->get(route('search.index', ['q' => 'نمط']))->assertOk(),
            'search players page' => fn () => $test->actingAs($viewer)->get(route('search.index', ['q' => 'نمط', 'type' => 'players']))->assertOk(),
            'search puzzles page' => fn () => $test->actingAs($viewer)->get(route('search.index', ['q' => 'نمط', 'type' => 'puzzles']))->assertOk(),
            'discover guest' => fn () => $test->get(route('discover.index'))->assertOk(),
            'discover player' => fn () => $test->actingAs($viewer)->get(route('discover.index'))->assertOk(),
        ];
    }
}

test('P1: query counts do not grow with the data - every endpoint runs the same number of queries on 3 rows per table as on 40 (no N+1)', function () {
    $viewer = e27_user();
    e27_grow(0, 3);
    $endpoints = e27_endpoints($this, $viewer);
    foreach ($endpoints as $run) {
        $run();   // تسخين الكاش (الرائج، مجسّ الحجم)
    }
    $small = array_map(fn ($run) => e27_queries($run), $endpoints);

    e27_grow(3, 40);
    $large = array_map(fn ($run) => e27_queries($run), $endpoints);

    expect($large)->toBe($small);
});

test('P2: measured query ceilings hold on a warm cache - search and discovery add a bounded, small number of queries over a plain page', function () {
    $viewer = e27_user();
    e27_grow(0, 12);
    $endpoints = e27_endpoints($this, $viewer);
    foreach ($endpoints as $run) {
        $run();
    }
    $baseGuest = e27_queries(fn () => $this->get('/terms')->assertOk());
    $basePlayer = e27_queries(fn () => $this->actingAs($viewer)->get('/terms')->assertOk());
    $count = fn (string $name) => e27_queries($endpoints[$name]);

    // JSON بلا إطار صفحة: مصدر لكل نوع (8 منذ E28: +1 لأحجيات المجتمع) + تحميل التصنيف + الحظر للمسجَّل. مقيس 11 (ضيف) و11 (لاعب).
    expect($count('suggest guest'))->toBeLessThanOrEqual(11)->and($count('suggest player'))->toBeLessThanOrEqual(12);
    // صفحات كاملة: فوق قاعدة الصفحة العادية بنفس المشاهد (مقيس: ضيف +7، لاعب +7 أو أقل).
    expect($count('search all (guest)') - $baseGuest)->toBeLessThanOrEqual(12)
        ->and($count('search all (player)') - $basePlayer)->toBeLessThanOrEqual(12)
        ->and($count('search players page') - $basePlayer)->toBeLessThanOrEqual(5)
        ->and($count('search puzzles page') - $basePlayer)->toBeLessThanOrEqual(5);
    // الاكتشاف: قسم بكل استعلام محدود (مقيس 22).
    expect($count('discover guest'))->toBeLessThanOrEqual(26)->and($count('discover player'))->toBeLessThanOrEqual(28);
});

test('P3: a cold cache costs a few extra queries once - then nothing until it expires', function () {
    $viewer = e27_user();
    e27_grow(0, 5);
    $endpoints = e27_endpoints($this, $viewer);

    e27_cold();
    $cold = e27_queries($endpoints['suggest guest']);
    $warm = e27_queries($endpoints['suggest guest']);
    expect($cold)->toBeLessThanOrEqual(18)->and($warm)->toBeLessThan($cold)->and($cold - $warm)->toBeLessThanOrEqual(8);   // مجسّ الحجم لكل نوع مرة واحدة

    e27_cold();
    expect(e27_queries($endpoints['discover guest']))->toBeLessThanOrEqual(30);
});

test('P4: the cold trending computation is logarithmic in the number of attempts and the warm one is free', function () {
    $puzzle = e27_puzzle('أحجية القياس');
    $users = array_map(fn () => e27_user(), range(1, 20));
    $rows = 400;
    foreach (range(1, $rows) as $n) {
        e27_attempt($users[$n % 20], $puzzle, $n % 7 === 0, $n <= 200 ? now()->subDays(12) : now()->subHours(3), $n);
    }
    e27_cold();

    $cold = e27_queries(fn () => e27_trending()->puzzleScores());
    $warm = e27_queries(fn () => e27_trending()->puzzleScores());

    // min + max + قيمة الأخير + الاستعلام الرئيسي + خطوات البحث الثنائي (≤ log2(صفوف)) + هامش خطوة.
    expect($cold)->toBeLessThanOrEqual(5 + (int) ceil(log($rows, 2)))->and($warm)->toBe(0);
    expect(e27_trending()->puzzleScores()[$puzzle->id]['players'])->toBe(20);   // النتيجة صحيحة: الصفوف القديمة (قبل 12 يومًا) لم تُحتسب
});

test('P5: the trending query is a bounded range on the primary key - never a full scan of the attempts table', function () {
    $puzzle = e27_puzzle('أحجية الخطة');
    $users = array_map(fn () => e27_user(), range(1, 10));
    foreach (range(1, 120) as $n) {
        e27_attempt($users[$n % 10], $puzzle, false, $n <= 60 ? now()->subDays(12) : now()->subHours(3), $n);
    }
    e27_cold();

    DB::flushQueryLog();
    DB::enableQueryLog();
    e27_trending()->puzzleScores();
    $log = DB::getQueryLog();
    DB::disableQueryLog();

    $main = collect($log)->first(fn ($q) => str_contains($q['query'], 'COUNT(DISTINCT'));
    expect($main)->not->toBeNull();
    // شكل الاستعلام: مدى على المعرّف + حد زمني + محاولات مستقلة فقط.
    expect($main['query'])->toContain('"a"."id" >= ?')->toContain('"a"."created_at" >= ?')->toContain('"a"."context_type" is null');

    $plan = collect(DB::select('EXPLAIN QUERY PLAN '.$main['query'], $main['bindings']))->pluck('detail')->all();
    $attempts = array_values(array_filter($plan, fn ($line) => str_contains($line, ' a ') || str_starts_with($line, 'SCAN a') || str_starts_with($line, 'SEARCH a')));

    expect($attempts)->not->toBe([]);
    foreach ($attempts as $line) {
        expect($line)->toStartWith('SEARCH a')->not->toContain('SCAN');   // SEARCH بفهرس/مفتاح مع شرط rowid>? = مدى، لا مسح كامل
        expect($line)->toContain('rowid>?');
    }
});

test('P6: beyond the partial-match limit the filter is a bare-column prefix LIKE - nothing wraps the column, so a name/title index can serve it', function () {
    config(['search.partial_match_max_id' => 0]);
    e27_grow(0, 1);   // صف في كل جدول مصدر: الجدول الفارغ رخيص المسح فيبقى بالوضع الكامل عمدًا، والمقصود هنا الوضع الكبير
    e27_cold();

    DB::flushQueryLog();
    DB::enableQueryLog();
    e27_suggest($this, 'نمط', e27_user());
    $log = DB::getQueryLog();
    DB::disableQueryLog();

    $tiered = collect($log)->filter(fn ($q) => str_contains($q['query'], 'search_tier') && ! str_contains($q['query'], 'from "team_championships"') && ! str_contains($q['query'], 'from "creator_puzzles"'));   // لا بطولات ولا أحجيات مجتمع بالبيانات: جداول فارغة (أحجيات المجتمع بوضعها الكبير مفحوصة في CreatorE28)
    expect($tiered->count())->toBe(6);
    foreach ($tiered as $q) {
        $where = substr($q['query'], (int) strpos($q['query'], ' from "'));

        expect($where)->not->toContain('REPLACE(')->not->toContain('LOWER(')->not->toContain('like ?  ')->and($where)->toMatch('/like \? escape \'!\'/');
        foreach ($q['bindings'] as $binding) {
            if (is_string($binding) && str_contains($binding, '%')) {
                expect($binding)->not->toStartWith('%');   // لا «%x» في الوضع الكبير: كل نمط بادئة
            }
        }
    }

    $puzzles = $tiered->first(fn ($q) => str_contains($q['query'], 'from "puzzles"'));
    $plan = collect(DB::select('EXPLAIN QUERY PLAN '.$puzzles['query'], $puzzles['bindings']))->pluck('detail')->implode(' | ');
    expect($plan)->toContain('puzzles_active_title_search_index');   // SQLite لا يحوّل LIKE إلى مدى (غير حساس للحالة)؛ MySQL بترتيب utf8mb4 يفعل — يُتحقق منه بـEXPLAIN محليًا (docs)
});

test('P7: the two search indexes exist, are reversible, and nothing was added to the hot attempts table or the small tables', function () {
    expect(Schema::hasIndex('users', 'users_name_search_index'))->toBeTrue()->and(Schema::hasIndex('puzzles', 'puzzles_active_title_search_index'))->toBeTrue();

    foreach (Schema::getIndexes('puzzle_attempts') as $index) {
        expect($index['columns'])->not->toContain('created_at');   // قرار E24/E27: لا فهرس زمني على أسخن جدول
    }
    foreach (['campaigns', 'seasons', 'competitive_events', 'team_championships', 'teams'] as $table) {
        foreach (Schema::getIndexes($table) as $index) {
            expect($index['name'])->not->toContain('search');
        }
    }

    $migration = require base_path('database/migrations/2026_10_19_000001_add_e27_search_indexes.php');
    $migration->down();
    expect(Schema::hasIndex('users', 'users_name_search_index'))->toBeFalse()->and(Schema::hasIndex('puzzles', 'puzzles_active_title_search_index'))->toBeFalse();
    $migration->up();
    $migration->up();   // آمن لإعادة التشغيل
    expect(Schema::hasIndex('users', 'users_name_search_index'))->toBeTrue()->and(Schema::hasIndex('puzzles', 'puzzles_active_title_search_index'))->toBeTrue();

    $source = file_get_contents(base_path('database/migrations/2026_10_19_000001_add_e27_search_indexes.php'));
    expect(substr_count($source, '->index('))->toBe(2)->and($source)->not->toContain('Schema::create(')->not->toContain('DB::')->not->toContain('->fullText(');
});

test('P8: responses are bounded however many rows match - suggest nine items, a page twelve, depth five, groups five', function () {
    e27_grow(0, 40);
    $viewer = e27_user();

    $json = e27_suggest($this, 'نمط', $viewer);
    expect(collect($json['groups'])->sum(fn ($g) => count($g['items'])))->toBeLessThanOrEqual(9)
        ->and(collect($json['groups'])->every(fn ($g) => count($g['items']) <= 3))->toBeTrue()
        ->and(strlen(json_encode($json)))->toBeLessThan(6000);

    $all = uiXpath($this->actingAs($viewer)->get(route('search.index', ['q' => 'نمط']))->assertOk()->getContent());
    foreach ($all->query('//main//section[@aria-label]') as $section) {
        expect($all->query('.//ul/li', $section)->length)->toBeLessThanOrEqual(5);
    }

    $page = uiXpath($this->actingAs($viewer)->get(route('search.index', ['q' => 'نمط', 'type' => 'puzzles']))->assertOk()->getContent());
    expect($page->query('//main//ul/li')->length)->toBe(12)->and($page->query('//a[@rel="next"]')->length)->toBe(1);

    $last = uiXpath($this->actingAs($viewer)->get(route('search.index', ['q' => 'نمط', 'type' => 'puzzles', 'page' => 5]))->assertOk()->getContent());
    expect($last->query('//a[@rel="next"]')->length)->toBe(0)->and($last->query('//main//ul/li')->length)->toBeLessThanOrEqual(12);
});

test('P9: input size is bounded before it reaches the database - a ten-thousand-character term never produces a long binding', function () {
    $viewer = e27_user();
    e27_puzzle('أحجية');

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->actingAs($viewer)->get(route('search.index', ['q' => str_repeat('أحجية ', 2000)]))->assertOk();
    $this->actingAs($viewer)->getJson(route('search.suggest', ['q' => str_repeat('ب', 10000)]))->assertOk();
    $log = DB::getQueryLog();
    DB::disableQueryLog();

    $longest = 0;
    foreach ($log as $q) {
        if (! str_contains($q['query'], 'search_tier')) {
            continue;
        }
        foreach ($q['bindings'] as $binding) {
            if (is_string($binding)) {
                $longest = max($longest, mb_strlen($binding));
            }
        }
    }

    expect($longest)->toBeGreaterThan(0)->and($longest)->toBeLessThanOrEqual(70);   // 64 حرفًا كحد أقصى + محارف النمط
});

test('P10: recommendations cost the same queries for a short history as for a long one, and the history read is capped', function () {
    $category = e27_category('تاريخ طويل');
    $short = e27_user();
    $long = e27_user();
    e27_pool($category, 12, 'POOL');

    foreach (e27_pool($category, 3, 'S') as $i => $puzzle) {
        e27_attempt($short, $puzzle, $i === 0, now()->subDays(2));
    }
    foreach (e27_pool($category, 60, 'L') as $i => $puzzle) {
        e27_attempt($long, $puzzle, $i % 3 === 0, now()->subDays(2));
    }
    e27_trending()->puzzleScores();   // تسخين

    $countShort = e27_queries(fn () => e27_reco($short));
    $countLong = e27_queries(fn () => e27_reco($long));
    expect($countLong)->toBe($countShort)->and($countLong)->toBeLessThanOrEqual(14);

    config(['search.recommendations.history_cap' => 5]);
    DB::flushQueryLog();
    DB::enableQueryLog();
    e27_reco($long);
    $history = collect(DB::getQueryLog())->first(fn ($q) => str_contains($q['query'], 'group by "puzzle_id"'));
    DB::disableQueryLog();

    expect($history['query'])->toContain('limit 5');
});
