<?php

use App\Models\CreatorPuzzle;
use App\Models\PuzzleCategory;
use App\Services\Creators\CommunityDiscovery;
use App\Services\Creators\CreatorApplicationService;
use App\Services\Discovery\DiscoveryService;
use App\Services\Engagement\CommunityQualityService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/_support.php';

beforeEach(fn () => e29_seed());

function e29_top(): array
{
    return app(CommunityQualityService::class)->computeTopRated();
}

function e29_titles(array $cards): array
{
    return array_map(fn ($c) => $c->title, $cards);
}

test('AA7-75: a single 5-star rating never dominates a well-rated work (shrinkage + minimum sample)', function () {
    $one = e29_work(null, ['title' => 'تقييم واحد خمس نجوم']);
    e29_ratings($one, [5]);
    $many = e29_work(null, ['title' => 'تقييمات كثيرة موثوقة']);
    e29_ratings($many, [5, 4, 5, 4, 4, 5, 4, 5, 4, 4]);

    expect(array_keys(e29_top()))->toBe([$many->id]);

    config(['engagement.top_rated.min_ratings' => 1]);
    expect(array_keys(e29_top()))->toBe([$many->id, $one->id]);
});

test('AA7-76: the minimum sample is enforced', function () {
    $work = e29_work();
    e29_ratings($work, [5, 5, 5, 5]);
    expect(e29_top())->toBe([]);

    e29_ratings($work, [5]);
    expect(array_keys(e29_top()))->toBe([$work->id]);
});

test('AA7-77: the weighted score is deterministic and matches the documented formula', function () {
    $q = app(CommunityQualityService::class);

    expect($q->score(5, 25))->toBe(4.25)->and($q->score(5, 25))->toBe($q->score(5, 25))
        ->and(round($q->score(500, 2400), 2))->toBe(4.79)->and($q->score(0, 0))->toBe(3.5);
    expect($q->score(500, 2400))->toBeGreaterThan($q->score(5, 25));   // الكثرة الموثوقة تتفوق على عيّنة صغيرة كاملة
});

test('AA7-78: a hidden work is excluded from the ranking', function () {
    $work = e29_work();
    e29_ratings($work, [5, 5, 5, 5, 5]);
    expect(array_keys(e29_top()))->toBe([$work->id]);

    e29_hide($work);

    expect(e29_top())->toBe([]);
});

test('AA7-79: a suspended creator\'s works are excluded', function () {
    $creator = e28_creator();
    $work = e29_work($creator);
    e29_ratings($work, [5, 5, 5, 5, 5]);

    app(CreatorApplicationService::class)->suspend(e29_profile($creator), e28_reviewer('administrator'), 'تعليق تجريبي للاختبار فقط.');

    expect(e29_top())->toBe([]);
});

test('AA7-80: the cache stores only safe identifiers and scores', function () {
    $work = e29_work(null, ['title' => 'عنوان لا يجب تخزينه بالكاش']);
    e29_ratings($work, [5, 4, 5, 4, 5]);
    e27_cold();

    $stored = app(CommunityQualityService::class)->topRated();
    $cached = Cache::get('discover.community.top_rated');

    expect($cached)->not->toBeNull()->and($cached)->toBe($stored)->and(array_keys($cached))->toBe([$work->id]);

    foreach ($cached as $id => $row) {
        expect(is_int($id))->toBeTrue()->and(array_keys($row))->toBe(['score', 'count', 'average']);
    }

    $json = json_encode($cached);
    expect($json)->not->toContain('عنوان لا يجب')->and($json)->not->toContain('@');
});

test('AA7-81: visibility is re-checked on every read even while the cache still holds the id', function () {
    $work = e29_work(null, ['title' => 'يختفي رغم الكاش']);
    e29_ratings($work, [5, 5, 5, 5, 5]);
    e27_cold();
    $viewer = e16User();

    expect(e29_titles(app(CommunityDiscovery::class)->sections($viewer)['top_rated']))->toBe(['يختفي رغم الكاش']);

    e29_hide($work);   // لا نمسح الكاش

    expect(array_keys(Cache::get('discover.community.top_rated')))->toBe([$work->id])
        ->and(app(CommunityDiscovery::class)->sections($viewer)['top_rated'])->toBe([]);
});

test('AA7-82: the Top Rated surface has zero reward, XP or progression effect', function () {
    $work = e29_work();
    e29_ratings($work, [5, 5, 5, 5, 5]);
    $viewer = e16User();
    $this->actingAs($viewer)->get(route('discover.index'))->assertOk()->assertSee('الأعلى تقييمًا من المجتمع');   // تسخين + يثبّت صف التقدّم الكسول
    $before = e28_world($viewer);

    $this->actingAs($viewer)->get(route('discover.index'))->assertOk();

    expect(e28_world($viewer))->toBe($before);
});

test('AA7-83: the top-rated card states the rating basis honestly (average and sample size)', function () {
    $work = e29_work(null, ['title' => 'عنوان بتقييم صادق']);
    e29_ratings($work, [5, 4, 5, 4, 5]);

    $html = $this->get(route('discover.index'))->assertOk()->getContent();

    expect(e29_section($html, 'الأعلى تقييمًا من المجتمع'))->toContain('عنوان بتقييم صادق')->toContain('4.6 من 5')->toContain('5 تقييم');
});

// ───────────── AA8: الاكتشاف ─────────────

/** @return array{list<CreatorPuzzle>, PuzzleCategory, PuzzleCategory} اثنا عشر عملًا لمبدعين مختلفين؛ الأحدث الأعلى رقمًا. */
function e29_world_of_twelve(): array
{
    $x = e28_category(['name' => 'تصنيف س']);
    $y = e28_category(['name' => 'تصنيف ص']);
    $works = [];

    foreach (range(1, 12) as $i) {
        $cat = in_array($i, [3, 9], true) ? $y : $x;
        $works[$i] = e29_work(null, ['title' => "عمل رقم {$i}", 'puzzle_category_id' => $cat->id]);
        DB::table('creator_puzzles')->where('id', $works[$i]->id)->update(['published_at' => now()->subHours(40 - $i)]);
    }

    DB::table('creator_puzzles')->where('id', $works[1]->id)->update(['plays_count' => 5]);

    return [$works, $x, $y];
}

test('AA8-83: a favorite signal never leaks another user\'s data and only reorders the viewer\'s own candidates', function () {
    [$works] = e29_world_of_twelve();
    e27_cold();
    $a = e16User(['name' => 'مفضِّل خاص جدًا']);
    $b = e16User();
    $c = e16User();

    $baseline = e29_titles(app(CommunityDiscovery::class)->sections($b)['for_you']);
    expect($baseline[0] ?? null)->toBe('عمل رقم 1');   // بلا إشارة: الأكثر لعبًا أولًا

    e29_favorite($a, $works[9]);   // مفضلة مستخدم آخر لا تغيّر شيئًا لـ b
    expect(e29_titles(app(CommunityDiscovery::class)->sections($b)['for_you']))->toBe($baseline);

    e29_favorite($c, $works[9]);   // مفضلة المشاهد نفسه (تصنيف ص) تعيد ترتيب مرشَّحيه: العمل 3 (تصنيف ص) يتقدّم
    $reordered = e29_titles(app(CommunityDiscovery::class)->sections($c)['for_you']);
    expect($reordered[0] ?? null)->toBe('عمل رقم 3')->and($reordered)->toContain('عمل رقم 1');

    $html = $this->actingAs($b)->get(route('discover.index'))->assertOk()->getContent();
    expect($html)->not->toContain('مفضِّل خاص جدًا');
});

test('AA8-84: content of a followed creator is eligible for the follower only', function () {
    $creator = e28_creator();
    $work = e29_work($creator, ['title' => 'من مبدع أتابعه']);
    $follower = e16User();
    $stranger = e16User();
    e29_follow($follower, $creator);
    e27_cold();

    expect(e29_titles(app(CommunityDiscovery::class)->sections($follower)['following']))->toBe(['من مبدع أتابعه'])
        ->and(app(CommunityDiscovery::class)->sections($stranger)['following'])->toBe([])
        ->and(app(CommunityDiscovery::class)->sections(null)['following'])->toBe([]);

    $hub = app(DiscoveryService::class)->hub($follower);
    expect(collect($hub['sections'])->pluck('key')->all())->toContain('community-following');
    expect(collect(app(DiscoveryService::class)->hub($stranger)['sections'])->pluck('key')->all())->not->toContain('community-following');
});

test('AA8-85: creator diversity holds: a prolific top-rated creator cannot monopolise the section', function () {
    config(['engagement.top_rated.min_ratings' => 1]);
    $prolific = e28_creator();

    foreach (range(1, 5) as $i) {
        $w = e29_work($prolific, ['title' => "غزير {$i}"]);
        e29_ratings($w, [5]);
    }

    $other = e29_work(null, ['title' => 'مبدع آخر']);
    e29_ratings($other, [4]);
    e27_cold();

    $titles = e29_titles(app(CommunityDiscovery::class)->sections(e16User())['top_rated']);
    $fromProlific = count(array_filter($titles, fn ($t) => str_starts_with($t, 'غزير')));

    expect($fromProlific)->toBe((int) config('creators.discovery.per_creator_cap'))->and($titles)->toContain('مبدع آخر');
});

test('AA8-86: private semantics unaffected: private collections never surface in search or discovery', function () {
    $owner = e16User();
    $work = e29_work();
    $c = e29_collection($owner, 'قافلة الخفاء الكبرى');
    app(\App\Services\Engagement\CollectionService::class)->add($owner, $c->public_id, $work->fresh());

    foreach ([$owner, e16User()] as $viewer) {
        // صفحة البحث تردّد كلمة البحث نفسها؛ نبحث بكلمة واحدة من العنوان ونتحقق أن العنوان الكامل لا يظهر نتيجةً أبدًا.
        $this->actingAs($viewer)->get(route('search.index', ['q' => 'الخفاء']))->assertOk()->assertDontSee('قافلة الخفاء الكبرى');
        $this->actingAs($viewer)->get(route('discover.index'))->assertOk()->assertDontSee('قافلة الخفاء الكبرى');
    }
});

test('AA8-87: the official discovery sections are unaffected by any community engagement', function () {
    $puzzle = \App\Models\Puzzle::factory()->create();
    $official = fn () => collect(app(DiscoveryService::class)->hub(null)['sections'])->reject(fn ($s) => str_starts_with($s['key'], 'community-'))->values()->all();
    e27_cold();
    $before = $official();

    $work = e29_work();
    e29_ratings($work, [5, 5, 5, 5, 5]);
    e29_favorite(e16User(), $work);
    e29_follow(e16User(), e28_creator());
    e27_cold();

    expect($official())->toEqual($before);
});
