<?php

use App\Filament\Resources\UserCollectionReportResource\Pages\ListUserCollectionReports;
use App\Filament\Resources\UserCollectionResource\Pages\ListUserCollections;
use App\Models\User;
use App\Models\UserCollection;
use App\Services\Collections\CollectionOrderService;
use App\Services\Collections\CollectionReportService;
use App\Services\Collections\CollectionSaveService;
use App\Services\Editorial\EditorialShelfResolver;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

require_once __DIR__.'/_support.php';
require_once __DIR__.'/../DiscoveryE27/_support.php';

/*
| E35 AM12 (98-105): الأداء. القاعدة العامة: عدد الاستعلامات **لا يتبع حجم البيانات** (مقارنة حجمين على الصفحة/الخدمة نفسها) وله سقف مطلق،
| والصفحات مرقَّمة. يُعدّ كل شيء بـ e27_queries (يستثني device_sightings). تسخين طلب واحد قبل القياس لاستبعاد كلفة التمهيد الأولى.
*/

beforeEach(function () {
    e35_seed();
    Cache::flush();
});

/** @return list<UserCollection> مجموعات عامة لمالكين مختلفين، كلٌّ منها بالأعمال نفسها (أرخص من أعمال جديدة لكل مجموعة). */
function e35_many_public(int $n, array $works, string $prefix = 'مجموعة'): array
{
    $out = [];

    for ($i = 1; $i <= $n; $i++) {
        $out[] = e35_collection(e16User(), $works, 'public', $prefix.' '.$i);
    }

    return $out;
}

test('E35-098: the public collection page has a bounded query count (5 vs 20 items) and paginates its items', function () {
    $owner = e16User();
    $small = e35_collection($owner, e35_works(5), 'public', 'مجموعة صغيرة');
    $large = e35_collection($owner, e35_works(20), 'public', 'مجموعة كبيرة');
    $perPage = (int) config('collections.public_page.items_per_page');

    foreach ([null, e16User()] as $viewer) {
        $viewer ? $this->actingAs($viewer) : auth()->logout();
        $this->get(route('collections.public.show', $small->public_id))->assertOk();   // تسخين

        $a = e27_queries(fn () => $this->get(route('collections.public.show', $small->public_id))->assertOk());
        $page = null;
        $b = e27_queries(function () use (&$page, $large) {
            $page = $this->get(route('collections.public.show', $large->public_id))->assertOk();
        });

        expect($b)->toBeLessThanOrEqual($a + 1)->and($b)->toBeLessThanOrEqual(30)
            ->and($page->viewData('works')->total())->toBe(20)->and(count($page->viewData('works')->items()))->toBe($perPage)->and($perPage)->toBeLessThan(20);
    }
});

test('E35-099: the owner collection list has no N+1 (2 vs 12 collections)', function () {
    $owner = e16User();
    $works = e35_works(3);
    $this->actingAs($owner);

    foreach (['private', 'unlisted'] as $i => $visibility) {
        e35_collection($owner, $works, $visibility, 'مجموعة '.$i);
    }

    $this->get(route('collections.index'))->assertOk();   // تسخين
    $few = e27_queries(fn () => $this->get(route('collections.index'))->assertOk());

    for ($i = 2; $i < 12; $i++) {
        e35_collection($owner, $works, ['private', 'unlisted', 'public'][$i % 3], 'مجموعة '.$i);
    }

    $many = e27_queries(fn () => $this->get(route('collections.index'))->assertOk());
    $this->get(route('collections.index'))->assertOk()->assertSee('مجموعة 11')->assertSee('مجموعة 0');
    expect($many)->toBeLessThanOrEqual($few + 1)->and($many)->toBeLessThanOrEqual(20);
});

test('E35-100: the saved collections list has no N+1 (2 vs 10 saved from distinct owners)', function () {
    $works = e35_works(3);
    $saver = e16User();
    $collections = e35_many_public(10, $works, 'محفوظة');
    $saves = app(CollectionSaveService::class);

    foreach (array_slice($collections, 0, 2) as $c) {
        $saves->save($saver, $c->public_id);
    }

    $this->actingAs($saver)->get(route('collections.saved'))->assertOk();   // تسخين
    $few = e27_queries(fn () => $this->get(route('collections.saved'))->assertOk());

    foreach (array_slice($collections, 2) as $c) {
        $saves->save($saver, $c->public_id);
    }

    $many = e27_queries(fn () => $this->get(route('collections.saved'))->assertOk());
    $this->get(route('collections.saved'))->assertOk()->assertSee('محفوظة 10')->assertSee('محفوظة 1');
    expect($many)->toBeLessThanOrEqual($few + 1)->and($many)->toBeLessThanOrEqual(20);
});

test('E35-101: Search over collections has no N+1 (2 vs 10 matches from distinct owners; page and suggest)', function () {
    $works = e35_works(3);
    $first = array_slice(e35_many_public(2, $works, 'ألغازالبحث'), 0, 2);
    $q = ['q' => 'ألغازالبحث'];

    $this->get(route('search.index', $q + ['type' => 'collections']))->assertOk();   // تسخين
    $few = e27_queries(fn () => $this->get(route('search.index', $q + ['type' => 'collections']))->assertOk());
    $fewAll = e27_queries(fn () => $this->get(route('search.index', $q))->assertOk());
    $fewSuggest = e27_queries(fn () => $this->getJson(route('search.suggest', $q))->assertOk());

    e35_many_public(8, $works, 'ألغازالبحث');

    $many = e27_queries(fn () => $this->get(route('search.index', $q + ['type' => 'collections']))->assertOk());
    $manyAll = e27_queries(fn () => $this->get(route('search.index', $q))->assertOk());
    $manySuggest = e27_queries(fn () => $this->getJson(route('search.suggest', $q))->assertOk());

    $this->get(route('search.index', $q + ['type' => 'collections']))->assertOk()->assertSee('ألغازالبحث 8')->assertSee('ألغازالبحث 2');
    expect(count($first))->toBe(2)->and($many)->toBeLessThanOrEqual($few + 1)->and($manyAll)->toBeLessThanOrEqual($fewAll + 1)->and($manySuggest)->toBeLessThanOrEqual($fewSuggest + 1)
        ->and($many)->toBeLessThanOrEqual(25);
});

test('E35-102: Discover has no N+1 for the collections section (2 vs 8 owners; cold and cached)', function () {
    $works = e35_works(3);
    e35_many_public(2, $works, 'اكتشاف');
    $this->get(route('discover.index'))->assertOk();   // تسخين غير مقيس

    Cache::flush();
    $fewCold = e27_queries(fn () => $this->get(route('discover.index'))->assertOk());
    $fewHot = e27_queries(fn () => $this->get(route('discover.index'))->assertOk());

    e35_many_public(6, $works, 'اكتشاف');
    Cache::flush();
    $manyCold = e27_queries(fn () => $this->get(route('discover.index'))->assertOk());
    $manyHot = e27_queries(fn () => $this->get(route('discover.index'))->assertOk());

    $html = $this->get(route('discover.index'))->getContent();
    expect(substr_count(e35_discover_section($html), 'collections.public.show') + substr_count(e35_discover_section($html), '/curated/'))->toBeGreaterThanOrEqual(8)
        ->and($manyCold)->toBeLessThanOrEqual($fewCold + 2)->and($manyHot)->toBeLessThanOrEqual($fewHot + 1);
});

test('E35-103: editorial shelf items are resolved in batches per type (2 vs 8 per type)', function () {
    $works = e35_works(3);
    $resolver = app(EditorialShelfResolver::class);
    $mk = function (int $n) use ($works) {
        $refs = [];

        foreach (e35_many_public($n, $works, 'رف') as $c) {
            $refs[] = ['public_collection', (int) $c->id];
        }

        foreach (e35_works($n) as $w) {
            $refs[] = ['community_puzzle', (int) $w->id];
        }

        return $refs;
    };

    $small = $mk(2);
    $resolver->resolve($small, null);   // تسخين
    $few = e27_queries(fn () => $resolver->resolve($small, null));

    $big = array_merge($small, $mk(6));
    $resolved = [];
    $many = e27_queries(function () use ($resolver, $big, &$resolved) {
        $resolved = $resolver->resolve($big, null);
    });

    expect(count($resolved))->toBe(count($big))->and($many)->toBeLessThanOrEqual($few + 1);

    // الرف المعروض في Home/Discover: عدد عناصره لا يضرب عدد الاستعلامات.
    $admin = e35_admin();
    $svc = app(\App\Services\Editorial\EditorialShelfService::class);
    config(['collections.shelves.cache_seconds' => 0]);
    $shelf = $svc->create($admin, ['internal_key' => 'perf-shelf', 'title' => 'رف الأداء', 'placement' => 'discover']);
    $svc->setItems($admin, $shelf, array_slice($big, 0, 4));
    $svc->activate($admin, $shelf->fresh());
    $shelves = app(\App\Services\Editorial\EditorialShelves::class);
    $shelves->forPlacement('discover', null);
    $fewShelf = e27_queries(fn () => $shelves->forPlacement('discover', null));

    $svc->setItems($admin, $shelf->fresh(), array_slice($big, 0, 16));
    $manyShelf = e27_queries(fn () => $shelves->forPlacement('discover', null));
    expect($manyShelf)->toBeLessThanOrEqual($fewShelf + 1);
});

test('E35-104: owner-side operations do not grow with the item count (5 vs 20 items: show page, reorder, add/remove)', function () {
    $owner = e16User();
    $small = e35_collection($owner, e35_works(5), 'public', 'خمسة');
    $large = e35_collection($owner, e35_works(20), 'public', 'عشرون');
    $this->actingAs($owner);

    $this->get(route('collections.show', $small->public_id))->assertOk();   // تسخين
    $showSmall = e27_queries(fn () => $this->get(route('collections.show', $small->public_id))->assertOk());
    $showLarge = e27_queries(fn () => $this->get(route('collections.show', $large->public_id))->assertOk());

    $order = app(CollectionOrderService::class);
    $reverse = fn (UserCollection $c) => array_reverse(e35_order($c));
    $smallOrder = $reverse($small);
    $largeOrder = $reverse($large);

    $reorderSmall = e27_queries(fn () => $order->reorder($owner, $small->public_id, $smallOrder));
    $reorderLarge = e27_queries(fn () => $order->reorder($owner, $large->public_id, $largeOrder));

    expect($showLarge)->toBeLessThanOrEqual($showSmall + 1)->and($reorderLarge)->toBeLessThanOrEqual($reorderSmall + 1)->and($reorderLarge)->toBeLessThanOrEqual(12)
        ->and(e35_order($large))->toBe($largeOrder);

    // الكتابة: تحديث CASE واحد للمواضع المتغيّرة مهما كثرت العناصر.
    $writes = array_values(array_filter(e27_sql(fn () => $order->reorder($owner, $large->public_id, array_reverse($largeOrder))), fn ($sql) => str_starts_with(strtolower($sql), 'update "user_collection_items"') || str_starts_with(strtolower($sql), 'update user_collection_items') || str_starts_with(strtolower($sql), 'update `user_collection_items`')));
    expect(count($writes))->toBe(1);
});

test('E35-105: the moderation queues are paginated and their query count does not grow with the queue', function () {
    config(['collections.reports.moderation_page_size' => 10]);
    $works = e35_works(3);
    $reports = app(CollectionReportService::class);
    $collections = e35_many_public(14, $works, 'بلاغات');
    $this->actingAs(e35_moderator());

    foreach (array_slice($collections, 0, 5) as $c) {
        $reports->report(e16User(), $c->public_id, 'spam');
    }

    Livewire::test(ListUserCollectionReports::class)->assertSuccessful();   // تسخين
    $small = e27_queries(fn () => Livewire::test(ListUserCollectionReports::class)->assertSuccessful());

    foreach (array_slice($collections, 5) as $c) {
        $reports->report(e16User(), $c->public_id, 'spam');
    }

    $page = null;
    $large = e27_queries(function () use (&$page) {
        $page = Livewire::test(ListUserCollectionReports::class)->assertSuccessful()->instance()->getTableRecords();
    });

    expect($page->total())->toBe(14)->and(count($page->items()))->toBe(10)->and($large)->toBeLessThanOrEqual($small + 1);

    // قائمة المجموعات المشتركة بلوحة الإدارة مرقَّمة بحجم الصفحة نفسه ولا تعرض الخاصة.
    e35_collection(e16User(), $works, 'private', 'خاصة لا تظهر');
    $list = Livewire::test(ListUserCollections::class)->assertSuccessful()->instance()->getTableRecords();
    expect($list->total())->toBe(14)->and(count($list->items()))->toBe(10);
    unset($list);
    expect(User::query()->count())->toBeGreaterThan(14);
});

test('E35-101b: beyond the partial-match limit the collections source filters by a bare-column prefix LIKE (index-friendly) with bound values only', function () {
    if (DB::getDriverName() !== 'sqlite') {
        test()->markTestSkipped('يعتمد على صيغة الاستعلام المسجَّل في SQLite (E27 P6 نفسه)؛ خطط MySQL تُفحص بـEXPLAIN محليًا (docs/mysql-production-e31.md).');
    }

    config(['search.partial_match_max_id' => 0]);
    e35_public(null, 3, 'نمط المجموعة');
    e27_cold();

    DB::flushQueryLog();
    DB::enableQueryLog();
    e27_suggest($this, 'نمط', e27_user());
    $log = DB::getQueryLog();
    DB::disableQueryLog();

    $tiered = collect($log)->filter(fn ($q) => str_contains($q['query'], 'search_tier') && str_contains($q['query'], 'from "user_collections"'));
    expect($tiered->count())->toBe(1);

    foreach ($tiered as $q) {
        $where = substr($q['query'], (int) strpos($q['query'], ' from "'));
        expect($where)->not->toContain('REPLACE(')->not->toContain('LOWER(')->and($where)->toMatch('/like \? escape \'!\'/');
        expect(collect($q['bindings'])->filter(fn ($b) => is_string($b) && str_starts_with($b, '%'))->all())->toBe([]);   // لا أنماط تبدأ بـ% (ستمنع الفهرس)
    }
});
