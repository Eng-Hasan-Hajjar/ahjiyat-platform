<?php

use App\Filament\Resources\CreatorSeriesRevisionResource\Pages\ListCreatorSeriesRevisions;
use App\Models\CreatorSeries;
use App\Models\CreatorSeriesRevision;
use App\Services\CreatorSeries\CreatorSeriesAnalytics;
use App\Services\CreatorSeries\CreatorSeriesVisibilityService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

require_once __DIR__.'/_support.php';

/*
| E30 (B) - الأداء. المبدأ كـE27/E28: عدد الاستعلامات لا يتبع حجم البيانات (لا N+1) والسقوف مقيسة، والفهارس تُستعمل فعلًا. SQLite لا يقيس زمن MySQL؛ فالاختبارات بنيوية.
*/

beforeEach(function () {
    e30s_seed();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

test('AM15-140: the public series page runs a small bounded number of queries (guest and signed-in)', function () {
    $creator = e28_creator();
    $series = e30s_published($creator, e30s_works($creator, 5));
    $player = e16User();
    $this->actingAs($player)->get(route('series.show', $series->public_id))->assertOk();   // تسخين الصلاحيات/الإعدادات
    auth()->logout();
    $this->flushSession();

    $guest = e27_queries(fn () => $this->get(route('series.show', $series->public_id))->assertOk());
    $this->actingAs($player);
    $signedIn = e27_queries(fn () => $this->get(route('series.show', $series->public_id))->assertOk());

    expect($guest)->toBeLessThanOrEqual(10)->and($signedIn)->toBeLessThanOrEqual(14);
});

test('AM15-141: 5 vs 25 items do not explode the query count (series page, studio, creator profile, search, discovery)', function () {
    $small = e28_creator();
    $large = e28_creator();
    $smallSeries = e30s_published($small, e30s_works($small, 5), ['title' => 'سلسلة خمسة عناصر']);
    $largeSeries = e30s_published($large, e30s_works($large, 25), ['title' => 'سلسلة خمسة وعشرين']);
    $player = e16User();

    // تسخين كل المسارات مرة (كاش الصلاحيات والإعدادات) ثم القياس البارد للكاش المعني
    foreach ([$smallSeries, $largeSeries] as $s) {
        $this->actingAs($player)->get(route('series.show', $s->public_id))->assertOk();
    }
    auth()->logout();

    $measure = function (callable $request) use ($player) {
        Cache::flush();
        $this->actingAs($player);
        $count = e27_queries($request);
        auth()->logout();

        return $count;
    };

    $surfaces = [
        'series page' => fn ($s) => fn () => $this->get(route('series.show', $s->public_id))->assertOk(),
        'puzzle page with series context' => fn ($s) => fn () => $this->get(route('community.puzzles.show', ['work' => CreatorSeries::query()->find($s->id)->publishedRevision->items()->first()->work()->value('public_id'), 'series' => $s->public_id]))->assertOk(),
    ];

    foreach ($surfaces as $name => $factory) {
        expect($measure($factory($largeSeries)))->toBe($measure($factory($smallSeries)), $name.' query count depends on item count');
    }

    // استوديو المبدع (قائمة + تعديل) بعناصر 5 مقابل 25. كل مسار يُسخَّن مرة لكل مبدع ثم يُقاس القياس الثاني (كاش الصلاحيات/الإعدادات خارج المقارنة).
    $studio = function ($creator, $series) {
        $request = function () use ($creator, $series) {
            $this->actingAs($creator);
            $count = e27_queries(function () use ($series) {
                $this->get(route('creator.series.index'))->assertOk();
                $this->get(route('creator.series.edit', $series->public_id))->assertOk();
            });
            auth()->logout();

            return $count;
        };
        $request();

        return $request();
    };
    $smallStudio = $studio($small, $smallSeries);
    $largeStudio = $studio($large, $largeSeries);
    expect($largeStudio)->toBe($smallStudio, 'studio query count depends on item/work count');

    // البحث والاكتشاف وملف المبدع: لا يتبعون عدد العناصر
    foreach ([
        'search' => fn () => $this->get(route('search.index', ['q' => 'سلسلة', 'type' => 'creator_series']))->assertOk(),
        'discover' => fn () => $this->get(route('discover.index'))->assertOk(),
        'creator profile' => fn () => $this->get(route('creators.show', $large->creatorProfile()->value('slug')))->assertOk(),
    ] as $name => $request) {
        expect($measure($request))->toBeLessThanOrEqual(40, $name.' is not bounded');
    }
});

test('AM15-141b: progress is a single bounded query regardless of the number of items', function () {
    $creator = e28_creator();
    $works5 = e30s_works($creator, 5);
    $works25 = e30s_works($creator, 25);
    $player = e16User();

    $svc = app(\App\Services\CreatorSeries\CreatorSeriesProgressService::class);
    $items = fn (array $works) => collect($works)->map(fn ($w) => (object) ['work_id' => $w->id, 'public_id' => $w->public_id]);

    expect(e27_queries(fn () => $svc->forUser($player, $items($works5))))->toBe(1)
        ->and(e27_queries(fn () => $svc->forUser($player, $items($works25))))->toBe(1)
        ->and(e27_queries(fn () => $svc->forUser(null, $items($works25))))->toBe(0);
});

test('AM15-142: the moderation queue is paginated and its query count does not grow with the number of pending series', function () {
    config(['creator_series.moderation_page_size' => 10]);
    $creator = e28_creator();
    $works = e30s_works($creator, 3);
    config(['creator_series.limits.max_pending' => 100, 'creator_series.limits.max_per_creator' => 100]);

    for ($i = 0; $i < 12; $i++) {
        e30s_pending($creator, $works);
    }

    $admin = e28_reviewer('administrator');
    $this->actingAs($admin);

    $page = Livewire::test(ListCreatorSeriesRevisions::class);
    $records = $page->instance()->getTableRecords();

    expect($records->count())->toBe(10)->and($records->total())->toBe(12)->and($records->hasPages())->toBeTrue();
    $page->assertSuccessful();

    $queries12 = e27_queries(fn () => Livewire::test(ListCreatorSeriesRevisions::class)->assertSuccessful());

    for ($i = 0; $i < 12; $i++) {
        e30s_pending($creator, $works);
    }

    $queries24 = e27_queries(fn () => Livewire::test(ListCreatorSeriesRevisions::class)->assertSuccessful());
    expect($queries24)->toBeLessThanOrEqual($queries12 + 1);
});

test('AM15: hot series queries use their indexes (public id, creator listing, queue, items by revision)', function () {
    if (\Illuminate\Support\Facades\DB::getDriverName() !== 'sqlite') {
        test()->markTestSkipped('E31: يعتمد على EXPLAIN QUERY PLAN الخاص بـSQLite - خطط MySQL تُفحص بـEXPLAIN محليًا (docs/mysql-production-e31.md).');
    }

    $creator = e28_creator();
    $series = e30s_published($creator);

    $plan = fn (string $sql, array $bindings = []) => collect(DB::select('EXPLAIN QUERY PLAN '.$sql, $bindings))->pluck('detail')->implode(' | ');

    expect($plan('select id from creator_series where public_id = ?', [$series->public_id]))->toContain('creator_series_public_id_unique')
        ->and($plan('select id from creator_series where slug = ?', [$series->slug]))->toContain('creator_series_slug_unique')
        ->and($plan('select id from creator_series where creator_profile_id = ? order by id', [$series->creator_profile_id]))->toContain('creator_series_owner_index')
        ->and($plan('select id from creator_series_revisions where status = ? order by submitted_at', ['pending_review']))->toContain('creator_series_revisions_queue_index')
        ->and($plan('select creator_puzzle_id from creator_series_items where creator_series_revision_id = ? order by position', [1]))->toContain('creator_series_items_position_unique')
        ->and($plan('select id from creator_series_items where creator_puzzle_id = ?', [1]))->toContain('creator_series_items_work_index');
});

test('AM15: the visibility rule is one query with indexed lookups (no scan of items by series)', function () {
    if (\Illuminate\Support\Facades\DB::getDriverName() !== 'sqlite') {
        test()->markTestSkipped('E31: يعتمد على EXPLAIN QUERY PLAN الخاص بـSQLite - خطط MySQL تُفحص بـEXPLAIN محليًا (docs/mysql-production-e31.md).');
    }

    $creator = e28_creator();
    $series = e30s_published($creator);

    $query = app(CreatorSeriesVisibilityService::class)->visibleQuery()->where('creator_series.public_id', $series->public_id);
    $sql = $query->toSql();
    $plan = collect(DB::select('EXPLAIN QUERY PLAN '.$sql, $query->getBindings()))->pluck('detail')->implode(' | ');

    expect($plan)->toContain('creator_series_public_id_unique')
        ->and($plan)->toMatch('/SEARCH csi USING (COVERING )?INDEX creator_series_items_(work|position)_unique \(creator_series_revision_id=\?\)/')   // عناصر النسخة بفهرس لا بمسح كامل
        ->and($plan)->not->toContain('SCAN csi')
        ->and(substr_count(strtolower($sql), 'select'))->toBeGreaterThan(1);   // استعلام واحد بشروط فرعية، لا حلقة PHP
});

test('AM15: creator analytics are aggregated in SQL, cached with a TTL, carry no identities and never track views', function () {
    $creator = e28_creator();
    $works = e30s_works($creator, 3);
    $series = e30s_published($creator, $works);
    $profile = $creator->creatorProfile()->first();
    $p1 = e16User();
    $p2 = e16User();
    $p3 = e16User();

    foreach ($works as $w) {
        e30s_solve($p1, $w);          // أكمل
    }
    e30s_solve($p2, $works[0]);       // بدأ فقط
    e30s_wrong($p3, $works[1]);

    $stats = app(CreatorSeriesAnalytics::class)->forCreator($profile);
    expect($stats[$series->id])->toBe(['players' => 3, 'starts' => 5, 'completions' => 1, 'completion_rate' => 33]);
    expect(app(CreatorSeriesAnalytics::class)->totals($stats))->toBe(['players' => 3, 'starts' => 5, 'completions' => 1, 'completion_rate' => 33]);

    // مخزَّنة: لاعب جديد لا يظهر قبل انتهاء الـTTL، ثم يظهر بعد مسح الكاش
    e30s_solve(e16User(), $works[2]);
    expect(app(CreatorSeriesAnalytics::class)->forCreator($profile)[$series->id]['players'])->toBe(3);
    Cache::flush();
    expect(app(CreatorSeriesAnalytics::class)->forCreator($profile)[$series->id]['players'])->toBe(4);

    // لا هويات ولا مشاهدات: لا أعمدة/جداول views ولا أسماء لاعبين في المخرجات
    expect(json_encode($stats))->not->toContain($p1->email)->not->toContain($p1->name)
        ->and(\Illuminate\Support\Facades\Schema::hasColumn('creator_series', 'views_count'))->toBeFalse();
    $this->actingAs($creator)->get(route('creator.series.index'))->assertOk()->assertSee('نسبة الإكمال');
});

test('AM15: discovery caches ids only (never titles or names) and the cache is never an authority', function () {
    $creator = e28_creator();
    $series = e30s_published($creator, null, ['title' => 'عنوان لا يجوز تخزينه بالكاش']);
    $this->get(route('discover.index'))->assertOk()->assertSee('عنوان لا يجوز تخزينه بالكاش');

    $cached = Cache::get('discover.series.newest');
    expect($cached)->toBe([$series->id])->and(json_encode($cached))->not->toContain('عنوان');

    // معرّف مخزَّن لسلسلة لم تعد ظاهرة لا يعيد إظهارها
    app(\App\Services\CreatorSeries\CreatorSeriesService::class)->archive($creator, $series->public_id);
    $this->get(route('discover.index'))->assertOk()->assertDontSee('عنوان لا يجوز تخزينه بالكاش');
});

test('AM15: beyond the partial-match limit the series search filter is a bare-column prefix LIKE on the published title and reads nothing but the published snapshot', function () {
    config(['search.partial_match_max_id' => 0]);
    $creator = e28_creator();
    e30s_published($creator, null, ['title' => 'نمط سلسلة واحد']);
    e27_cold();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $titles = e27_titles(e27_suggest($this, 'نمط', e16User()));
    $log = DB::getQueryLog();
    DB::disableQueryLog();

    $query = collect($log)->first(fn ($q) => str_contains($q['query'], 'search_tier') && str_contains(str_replace('`', '"', $q['query']), 'from "creator_series"'));
    expect($titles)->toContain('نمط سلسلة واحد')->and($query)->not->toBeNull();

    $sql = str_replace('`', '"', $query['query']);   // اقتباس المعرّفات يختلف بين المحرّكين (`x` على MySQL و"x" على SQLite)
    $where = substr($sql, (int) strpos($sql, ' from "'));
    expect($where)->not->toContain('REPLACE(')->not->toContain('LOWER(')->toMatch('/like \? escape \'!\'/')
        ->and(str_contains($sql, 'creator_series_revisions'))->toBeFalse()   // لا ضمّ للنسخ
        ->and(str_contains($sql, 'description'))->toBeFalse();

    foreach ($query['bindings'] as $binding) {
        if (is_string($binding) && str_contains($binding, '%')) {
            expect($binding)->not->toStartWith('%');
        }
    }
});
