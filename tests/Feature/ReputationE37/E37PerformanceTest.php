<?php

use App\Models\User;
use App\Services\Reputation\CommunityReputationService;
use App\Services\Reputation\FeaturedCreatorService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/_support.php';

/*
| E37 AV13 (85-91) الأداء: قراءات الترتيب والصفحات **محدودة الاستعلامات** ولا تنمو خطيًا بعدد المرشحين؛ وإعادة الحساب على دفعات (chunk).
| المقارنة نسبية (10 ← 100 مرشحًا) فلا تتأثر بعدد استعلامات البنية الثابتة؛ مع سقف مطلق مُوثَّق.
*/

beforeEach(fn () => e37_seed());

/** منسّق وهمي رخيص: مستخدم + مجموعتان عامتان على أعمال ظاهرة مشتركة + لقطة مؤهَّلة. @param list<int> $workIds */
function e37_bulk_curator(int $score, array $workIds, int $collections = 2): User
{
    $user = e16User();

    for ($c = 0; $c < $collections; $c++) {
        $id = DB::table('user_collections')->insertGetId([
            'public_id' => (string) Str::ulid(), 'user_id' => $user->id, 'title' => 'مجموعة '.$c, 'visibility' => 'public', 'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ($workIds as $w) {
            DB::table('user_collection_items')->insert(['user_collection_id' => $id, 'creator_puzzle_id' => $w, 'created_at' => now()]);
        }
    }

    e37_snapshot_row($user, [
        'curator_score' => $score, 'curator_eligible' => true, 'curator_collections_count' => $collections, 'curator_savers_count' => 8, 'curator_saves_count' => 8, 'curator_items_count' => count($workIds),
    ]);

    return $user;
}

/** ثلاثة أعمال ظاهرة مشتركة تُستعمل كعناصر لمجموعات المنسّقين الوهميين. @return list<int> */
function e37_shared_works(): array
{
    return array_map(fn ($w) => (int) $w->id, e35_works(3));
}

/** @return array{0: int, 1: int} [عدد الاستعلامات عند $small مرشحًا، عند $large] بعد إضافة الفارق بـ$make. */
function e37_scaling(callable $make, callable $measure, int $small = 10, int $large = 100): array
{
    for ($i = 0; $i < $small; $i++) {
        $make($i);
    }

    $measure(); // تسخين: لا كاش ملوَّث ولا ترجمات أولى في القياس
    $a = e27_queries($measure);

    for ($i = $small; $i < $large; $i++) {
        $make($i);
    }

    $b = e27_queries($measure);

    return [$a, $b];
}

test('E37-085: the Top Creators read is bounded - 10 vs 100 candidates cost the same queries (absolute budget <= 6)', function () {
    [$a, $b] = e37_scaling(fn ($i) => e37_bulk_creator(9000 - $i), fn () => e37_rankings()->creators());

    expect($b)->toBeLessThanOrEqual($a + 1)->and($b)->toBeLessThanOrEqual(6)->and(count(e37_rankings()->creators()))->toBe(20);
});

test('E37-086: the Rising read is bounded - 10 vs 100 candidates cost the same queries (<= 6)', function () {
    [$a, $b] = e37_scaling(fn ($i) => e37_bulk_creator(1000, 2, ['rising_eligible' => true, 'rising_score' => 9000 - $i, 'recent_ratings_count' => 5, 'recent_ratings_sum' => 24]), fn () => e37_rankings()->rising());

    expect($b)->toBeLessThanOrEqual($a + 1)->and($b)->toBeLessThanOrEqual(6)->and(count(e37_rankings()->rising()))->toBeLessThanOrEqual(20)->and(count(e37_rankings()->rising()))->toBeGreaterThan(0);
});

test('E37-087: the Top Curators read is bounded - 10 vs 100 candidates cost the same queries (<= 8)', function () {
    $works = e37_shared_works();
    [$a, $b] = e37_scaling(fn ($i) => e37_bulk_curator(9000 - $i, $works), fn () => e37_rankings()->curators());

    expect($b)->toBeLessThanOrEqual($a + 1)->and($b)->toBeLessThanOrEqual(8)->and(count(e37_rankings()->curators()))->toBe(20);
});

test('E37-088: the public creator profile adds a constant number of queries for featured + recognition (no N+1 on badges)', function () {
    e37_recognition_seed();
    config(['reputation.creator.rating.prior_weight' => 0]);
    [$plain] = e37_creator(2);
    [$rich, $works] = e37_eligible_creator([5, 5, 5, 5, 5, 5]);

    $col = e35_collection($rich, e35_works(3), 'public', 'مجموعة');
    $col2 = e35_collection($rich, e35_works(3), 'public', 'مجموعة 2');

    foreach (e37_users(10) as $saver) {
        foreach ([$col, $col2] as $c) {
            DB::table('user_collection_saves')->insert(['user_id' => $saver->id, 'user_collection_id' => $c->id, 'created_at' => now()]);
        }
    }

    e37_refresh($rich);
    app(FeaturedCreatorService::class)->feature(e37_profile($rich), e37_admin());
    expect(count(e37_unlocked($rich)))->toBeGreaterThanOrEqual(3);

    $plainSlug = e37_profile($plain)->slug;
    $richSlug = e37_profile($rich)->slug;
    $this->get(route('creators.show', $plainSlug)); // تسخين
    $qPlain = e27_queries(fn () => $this->get(route('creators.show', $plainSlug))->assertOk());
    $qRich = e27_queries(fn () => $this->get(route('creators.show', $richSlug))->assertOk());

    expect($qRich - $qPlain)->toBeLessThanOrEqual(4)->and($qRich)->toBeLessThanOrEqual(40);
});

test('E37-089: the reputation sections add a bounded cost to Discover regardless of candidates (10 vs 100)', function () {
    $off = null;
    $measure = fn () => $this->get(route('discover.index'))->assertOk();

    config(['reputation.enabled' => false]);
    $measure();
    $off = e27_queries($measure);
    config(['reputation.enabled' => true]);

    [$a, $b] = e37_scaling(fn ($i) => e37_bulk_creator(1000, 2, ['rising_eligible' => true, 'rising_score' => 9000 - $i, 'recent_ratings_count' => 5, 'recent_ratings_sum' => 24]), $measure);

    expect($b)->toBeLessThanOrEqual($a + 1)->and($b - $off)->toBeLessThanOrEqual(8);
});

test('E37-090: the whole rankings page does not grow with candidates (10 vs 100 across creators and curators) and stays under budget', function () {
    $works = e37_shared_works();
    $measure = fn () => $this->get(route('community.rankings'))->assertOk();
    [$a, $b] = e37_scaling(function ($i) use ($works) {
        e37_bulk_creator(9000 - $i, 2, ['rising_eligible' => true, 'rising_score' => 9000 - $i, 'recent_ratings_count' => 5, 'recent_ratings_sum' => 24]);
        e37_bulk_curator(9000 - $i, $works);
    }, $measure, 10, 60);

    expect($b)->toBeLessThanOrEqual($a + 2)->and($b)->toBeLessThanOrEqual(45);
});

test('E37-091: recalculation is chunked - queries scale with the number of chunks, not users, and the stale batch is bounded', function () {
    $users = e37_users(35);
    $service = app(CommunityReputationService::class);
    $ids = array_map(fn (User $u) => (int) $u->id, $users);

    config(['reputation.refresh.chunk' => 10]);
    $sql = e37_sql(fn () => $service->refreshUsers($ids));
    // محايد للسائق: SQLite يقتبس بـ"..." وMySQL بـ`...`.
    $lookups = count(array_filter($sql, fn (string $q) => str_contains(str_replace(['"', '`'], '', $q), 'from community_reputation_snapshots') && str_contains(str_replace(['"', '`'], '', $q), 'user_id in')));

    expect($lookups)->toBe(4); // ceil(35/10)

    config(['reputation.refresh.chunk' => 100]);
    $one = e27_queries(fn () => $service->refreshUsers(array_slice($ids, 0, 10)));
    $many = e27_queries(fn () => $service->refreshUsers($ids));

    expect($many)->toBeLessThanOrEqual($one + 4); // 35 مستخدمًا بدفعة واحدة تقريبًا بكلفة 10 (استعلامات مجمَّعة لا لكل مستخدم)

    DB::table('community_reputation_snapshots')->update(['calculated_at' => now()->subDays(2)]);
    $result = $service->refreshStale(5);

    expect($result['processed'])->toBeLessThanOrEqual(5);

    $this->artisan('reputation:recalculate', ['--all' => true, '--chunk' => 10])->assertExitCode(0);
});
