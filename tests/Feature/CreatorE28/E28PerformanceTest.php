<?php

use App\Filament\Resources\CreatorPuzzleRevisionResource\Pages\ListCreatorPuzzleRevisions;
use App\Models\CreatorPuzzleRevision;
use App\Models\User;
use App\Services\Creators\CreatorPuzzleService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

require_once __DIR__.'/_support.php';

/*
| E28-Y10 - الأداء. المبدأ كـE27: عدد الاستعلامات لا يتبع حجم البيانات (لا N+1) والسقوف مقيسة. SQLite لا يقيس زمن MySQL؛ لذا الاختبارات بنيوية (عدد وشكل) لا زمنية.
*/

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

if (! function_exists('e28_publish_many')) {
    /** ينشر $n عملًا للمبدع (كلها بعنوان يبدأ بـ«لغز»)، ويضيف لاعبين على أول ثلاثة منها لتحريك التحليلات. */
    function e28_publish_many(User $creator, int $n, string $prefix = 'لغز'): void
    {
        for ($i = 1; $i <= $n; $i++) {
            e28_published($creator, 'text', ['title' => $prefix.' '.$i]);
        }
    }
}

test('Y10-80 (80): the creator dashboard runs a bounded, size-independent number of queries (3 vs 25 works)', function () {
    $warm = e28_creator();
    $this->actingAs($warm)->get(route('creator.dashboard'))->assertOk();   // تسخين كاش الصلاحيات والإعدادات (لا يخصّ المقيسَين)
    auth()->logout();

    $small = e28_creator();
    e28_publish_many($small, 3);
    $large = e28_creator();
    e28_publish_many($large, 25);

    $queries = [];

    foreach (['small' => $small, 'large' => $large] as $key => $creator) {
        $this->actingAs($creator);
        $queries[$key] = e27_queries(fn () => $this->get(route('creator.dashboard'))->assertOk());
        auth()->logout();
    }

    expect($queries['large'])->toBe($queries['small'])
        ->and($queries['large'])->toBeLessThanOrEqual(20);

    // قائمة الأعمال مرقَّمة (لا تحمّل كل الأعمال) وعدد استعلاماتها ثابت أيضًا
    $this->actingAs($large);
    $list = e27_queries(fn () => $this->get(route('creator.puzzles.index'))->assertOk());
    auth()->logout();
    $this->actingAs($small);
    $listSmall = e27_queries(fn () => $this->get(route('creator.puzzles.index'))->assertOk());

    expect($list)->toBe($listSmall);
});

test('Y10-81 (81): the public creator page has no N+1 (3 vs 25 works) and paginates the works', function () {
    $warm = e28_creator();
    e28_publish_many($warm, 1);
    $this->get(route('creators.show', $warm->creatorProfile()->value('slug')))->assertOk();

    $small = e28_creator();
    e28_publish_many($small, 3);
    $large = e28_creator();
    e28_publish_many($large, 25);

    $count = fn ($creator) => e27_queries(fn () => $this->get(route('creators.show', $creator->creatorProfile()->value('slug')))->assertOk());
    $a = $count($small);
    $b = $count($large);

    expect($b)->toBe($a)->and($b)->toBeLessThanOrEqual(12);

    $html = $this->get(route('creators.show', $large->creatorProfile()->value('slug')))->getContent();
    expect(substr_count($html, 'من المجتمع'))->toBeLessThanOrEqual((int) config('creators.page_size') + 3);   // صفحة واحدة فقط من البطاقات (+ وسوم الصفحة)

    // والقائمة العامة لأحجيات المجتمع كذلك
    $list = fn () => e27_queries(fn () => $this->get(route('community.puzzles.index'))->assertOk());
    $withLarge = $list();
    expect($withLarge)->toBeLessThanOrEqual(12);
});

test('Y10-82 (82): the moderation queue is paginated and its query count does not grow with the queue (5 vs 25 pending)', function () {
    $moderator = e28_reviewer('moderator');
    $this->actingAs($moderator);

    $creators = [];

    for ($c = 0; $c < 5; $c++) {
        $creators[] = e28_creator();
    }

    foreach ($creators as $creator) {
        e28_pending($creator, 'text', ['title' => 'طابور '.fake()->unique()->numerify('####')]);
    }

    $warm = Livewire::test(ListCreatorPuzzleRevisions::class)->assertSuccessful();   // تسخين
    $small = e27_queries(fn () => Livewire::test(ListCreatorPuzzleRevisions::class)->assertSuccessful());
    expect($warm->instance()->getTableRecords()->total())->toBe(5);

    foreach ($creators as $creator) {
        for ($i = 0; $i < 4; $i++) {
            e28_pending($creator, 'text', ['title' => 'طابور '.fake()->unique()->numerify('####')]);
        }
    }

    expect(CreatorPuzzleRevision::query()->where('status', 'pending_review')->count())->toBe(25);

    $pageSize = (int) config('creators.moderation_page_size');
    $page = null;
    $large = e27_queries(function () use (&$page) {
        $page = Livewire::test(ListCreatorPuzzleRevisions::class)->assertSuccessful()->instance()->getTableRecords();
    });
    expect($page->total())->toBe(25)->and(count($page->items()))->toBe($pageSize);

    // الطابور مرقَّم: صفحة واحدة بحجم الإعداد، لا كل الـ25؛ وعدد الاستعلامات ثابت لا يتبع الحجم (لا N+1 على العمل/المبدع/التصنيف)
    expect($pageSize)->toBeLessThan(25)->and($large)->toBe($small);

    // محتوى النسخة المشفَّر لا يُحمَّل لقائمة الطابور أصلًا
    $sql = e27_sql(fn () => Livewire::test(ListCreatorPuzzleRevisions::class)->assertSuccessful());
    $revisionSelects = array_filter($sql, fn ($q) => str_contains($q, 'from "creator_puzzle_revisions"') && str_contains($q, 'select'));

    foreach ($revisionSelects as $query) {
        expect(str_contains($query, '"content"'))->toBeFalse('the queue must not load encrypted content');
        expect(str_contains($query, '"creator_puzzle_revisions".*') || str_contains($query, 'select *'))->toBeFalse();
    }
});

test('Y10-83 (83): community search integration has no N+1 - suggest, full search and Discover (3 vs 30 community works)', function () {
    $category = e28_category();
    $viewer = e16User();
    $creators = [e28_creator(), e28_creator(), e28_creator()];

    $grow = function (int $from, int $to) use ($creators, $category) {
        for ($i = $from + 1; $i <= $to; $i++) {
            e28_published($creators[$i % 3], 'text', ['title' => 'نسيج أحجية '.$i, 'puzzle_category_id' => $category->id]);
        }
    };

    $endpoints = [
        'suggest guest' => fn () => $this->getJson(route('search.suggest', ['q' => 'نسيج']))->assertOk(),
        'suggest player' => fn () => $this->actingAs($viewer)->getJson(route('search.suggest', ['q' => 'نسيج']))->assertOk(),
        'search all' => fn () => $this->actingAs($viewer)->get(route('search.index', ['q' => 'نسيج']))->assertOk(),
        'search community tab' => fn () => $this->actingAs($viewer)->get(route('search.index', ['q' => 'نسيج', 'type' => 'community']))->assertOk(),
        'discover guest' => fn () => $this->get(route('discover.index'))->assertOk(),
        'discover player' => fn () => $this->actingAs($viewer)->get(route('discover.index'))->assertOk(),
        'community list' => fn () => $this->get(route('community.puzzles.index'))->assertOk(),
    ];

    $grow(0, 3);
    foreach ($endpoints as $run) {
        $run();   // تسخين الكاش (الرائج، مجسّ الحجم)
    }
    $small = array_map(fn ($run) => e27_queries($run), $endpoints);

    $grow(3, 30);
    $large = array_map(fn ($run) => e27_queries($run), $endpoints);

    expect($large)->toBe($small);

    // المجموعة والبطاقات مقيّدة بالحدود: لا تتبع حجم المجتمع
    $suggest = e27_suggest($this, 'نسيج');
    $community = collect($suggest['groups'])->firstWhere('type', 'community');
    expect(count($community['items']))->toBeLessThanOrEqual((int) config('search.suggest_per_type', 3));
});
