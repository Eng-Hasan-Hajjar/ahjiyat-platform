<?php

use App\Models\CommunityFeaturedCreator;
use App\Models\CommunityReputationSnapshot;
use App\Services\Reputation\CommunityReputationService;
use App\Services\Reputation\FeaturedCreatorService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/_support.php';

/*
| E37 AV14 (92-98) - حدود السلامة في القاعدة نفسها. سائق-محايدة: SQLite افتراضيًا وMySQL 8 عبر phpunit.mysql.xml.dist (مهمة mysql-parity في CI).
| تثبت أن فرادة اللقطة والمبدع المختار وسياسة المفاتيح الأجنبية ودقّة الأعداد الصحيحة وفهارس الترتيب والنص العربي لا تعتمد على كود التطبيق وحده.
*/

beforeEach(fn () => e37_seed());

function e37_violates(callable $call): bool
{
    try {
        DB::transaction($call);
    } catch (QueryException) {
        return true;
    }

    return false;
}

test('E37-092: a snapshot is unique per user and a featured row is unique per creator profile - enforced by the database, not only the service', function () {
    [$creator] = e37_eligible_creator();
    e37_refresh($creator);
    $profile = e37_profile($creator);

    expect(e37_violates(fn () => DB::table('community_reputation_snapshots')->insert(['user_id' => $creator->id, 'created_at' => now(), 'updated_at' => now()])))->toBeTrue()
        ->and(CommunityReputationSnapshot::query()->where('user_id', $creator->id)->count())->toBe(1);

    app(FeaturedCreatorService::class)->feature($profile, e37_admin());

    expect(e37_violates(fn () => DB::table('community_featured_creators')->insert(['creator_profile_id' => $profile->id, 'created_at' => now(), 'updated_at' => now()])))->toBeTrue()
        ->and(CommunityFeaturedCreator::query()->where('creator_profile_id', $profile->id)->count())->toBe(1);
});

test('E37-093: concurrent/repeated recalculation is safe - the atomic UPSERT leaves exactly one row with the latest values, even from a stale read', function () {
    [$creator, $works] = e37_eligible_creator();
    $svc = app(CommunityReputationService::class);
    $persist = (new ReflectionClass($svc))->getMethod('persist');
    $persist->setAccessible(true);

    // «عمليتان» تحسبان من قراءتين مختلفتين ثم تكتبان بالتتابع (الثانية تقرأ قديمًا): صف واحد والأخيرة تفوز دون خطأ تكرار.
    $first = $svc->refreshUsers([$creator->id], true)['rows'][$creator->id];
    e37_rate($works[0], e16User(), 5);
    $second = $svc->refreshUsers([$creator->id], true)['rows'][$creator->id];

    $persist->invoke($svc, [$second]);
    $persist->invoke($svc, [$first]); // كتابة متأخرة بقيم أقدم: لا تكرار ولا استثناء
    $persist->invoke($svc, [$second]);

    expect(CommunityReputationSnapshot::query()->where('user_id', $creator->id)->count())->toBe(1)->and((int) e37_snap($creator)->ratings_count)->toBe((int) $second['ratings_count']);

    // إعادة حساب عادية تصحّح أي قيمة متأخرة.
    $persist->invoke($svc, [$first]);
    e37_refresh($creator);

    expect((int) e37_snap($creator)->ratings_count)->toBe((int) $second['ratings_count'])->and(CommunityReputationSnapshot::query()->where('user_id', $creator->id)->count())->toBe(1);
});

test('E37-094: scores and aggregates are exact integers (SUM/COUNT cast safely) and out-of-range scores are rejected where the engine supports CHECK', function () {
    [$creator, $works] = e37_creator(2);
    $raters = e37_users(41);
    $expected = 0;

    foreach ($raters as $i => $rater) {
        $rating = ($i % 5) + 1;

        foreach ($works as $work) {
            e37_rate($work, $rater, $rating);
            $expected += $rating;
        }
    }

    e37_refresh($creator);
    $s = e37_snap($creator);

    expect((int) $s->ratings_sum)->toBe($expected)->and((int) $s->ratings_count)->toBe(82)->and((int) $s->raters_count)->toBe(41)
        ->and(is_int($s->creator_score))->toBeTrue()->and($s->creator_score)->toBeGreaterThanOrEqual(0)->and($s->creator_score)->toBeLessThanOrEqual(10000);

    foreach (['creator_score', 'creator_rating_score', 'rising_score', 'curator_score', 'helpfulness_score'] as $column) {
        DB::table('community_reputation_snapshots')->where('user_id', $creator->id)->update([$column => 10000]); // الحدّ نفسه مقبول
    }

    expect((int) e37_snap($creator)->curator_score)->toBe(10000);

    if (in_array(DB::getDriverName(), ['mysql', 'mariadb', 'pgsql'], true)) {
        expect(e37_violates(fn () => DB::table('community_reputation_snapshots')->where('user_id', $creator->id)->update(['creator_score' => 10001])))->toBeTrue();
    }
});

test('E37-095: the ranking indexes and unique keys exist on the real schema', function () {
    $indexes = collect(Schema::getIndexes('community_reputation_snapshots'));
    $byName = fn (string $name) => $indexes->firstWhere('name', $name);

    expect($byName('reputation_snapshots_user_unique'))->not->toBeNull()->and($byName('reputation_snapshots_user_unique')['unique'])->toBeTrue()
        ->and($byName('reputation_creator_rank_index')['columns'])->toBe(['creator_eligible', 'creator_score', 'ratings_count'])
        ->and($byName('reputation_rising_rank_index')['columns'])->toBe(['rising_eligible', 'rising_score', 'recent_ratings_count'])
        ->and($byName('reputation_curator_rank_index')['columns'])->toBe(['curator_eligible', 'curator_score', 'curator_savers_count'])
        ->and($byName('reputation_helpful_rank_index')['columns'])->toBe(['helpful_eligible', 'helpfulness_score', 'helpful_likers_count'])
        ->and($byName('reputation_calculated_index')['columns'])->toBe(['calculated_at']);

    $featured = collect(Schema::getIndexes('community_featured_creators'));

    expect($featured->contains(fn ($i) => $i['unique'] && $i['columns'] === ['creator_profile_id']))->toBeTrue()
        ->and($featured->contains(fn ($i) => $i['name'] === 'featured_creators_window_index'))->toBeTrue();
});

test('E37-096: foreign-key policy - deleting an account removes its snapshot only; deleting a creator profile removes its featured row; deleting the editor keeps the featuring', function () {
    [$a] = e37_eligible_creator();
    [$b] = e37_eligible_creator();
    e37_refresh($a, $b);
    $editor = e37_admin();
    $profileA = e37_profile($a);
    app(FeaturedCreatorService::class)->feature($profileA, $editor);

    // حذف المحرِّر: featured_by يصير NULL والإبراز يبقى.
    $editor->delete();

    expect(CommunityFeaturedCreator::query()->where('creator_profile_id', $profileA->id)->first()->featured_by)->toBeNull()
        ->and(CommunityFeaturedCreator::query()->count())->toBe(1);

    // حذف ملف المبدع: صف الإبراز يتتابع.
    DB::table('creator_profiles')->where('id', $profileA->id)->delete();

    expect(CommunityFeaturedCreator::query()->count())->toBe(0)->and(CommunityReputationSnapshot::query()->where('user_id', $a->id)->count())->toBe(1);

    // حذف الحساب: لقطته فقط تُحذف.
    $a->delete();

    expect(CommunityReputationSnapshot::query()->where('user_id', $a->id)->count())->toBe(0)->and(CommunityReputationSnapshot::query()->where('user_id', $b->id)->count())->toBe(1);
});

test('E37-097: Arabic text round-trips through the snapshot-adjacent tables intact (160-char note, diacritics, mixed scripts)', function () {
    [$creator] = e37_creator(1);
    $profile = e37_profile($creator);
    $note = str_repeat('مُبدِعٌ رائع ', 12); // ≈ 156 حرفًا بالتشكيل
    $note = mb_substr($note, 0, 160);

    app(FeaturedCreatorService::class)->feature($profile, e37_admin(), $note);
    $stored = DB::table('community_featured_creators')->where('creator_profile_id', $profile->id)->value('note');

    expect($stored)->toBe(trim(strip_tags($note)))->and(mb_strlen($stored))->toBeLessThanOrEqual(160);

    $mixed = 'ألغاز Puzzle ٣٤٥ «اختبار» ‎ZWNJ‌ZWJ‍';
    DB::table('community_featured_creators')->where('creator_profile_id', $profile->id)->update(['note' => mb_substr($mixed, 0, 160)]);

    expect(DB::table('community_featured_creators')->where('creator_profile_id', $profile->id)->value('note'))->toBe(mb_substr($mixed, 0, 160));
});

test('E37-098: the ranking order is deterministic in the database itself (score desc, evidence desc, user id asc) and timestamps round-trip', function () {
    $x = e37_bulk_creator(7000, 2, ['ratings_count' => 10]);
    $y = e37_bulk_creator(7000, 2, ['ratings_count' => 30]);
    $z = e37_bulk_creator(7000, 2, ['ratings_count' => 10]);
    $top = e37_bulk_creator(9000);

    $order = DB::table('community_reputation_snapshots')->where('creator_eligible', true)->orderByDesc('creator_score')->orderByDesc('ratings_count')->orderBy('user_id')->pluck('user_id')->map(fn ($v) => (int) $v)->all();

    expect($order)->toBe([$top->id, $y->id, $x->id, $z->id]);

    $stamp = now()->startOfSecond();
    DB::table('community_reputation_snapshots')->where('user_id', $x->id)->update(['calculated_at' => $stamp, 'first_published_at' => $stamp->copy()->subDays(3)]);
    $row = e37_snap($x);

    expect($row->calculated_at->equalTo($stamp))->toBeTrue()->and($row->first_published_at->equalTo($stamp->copy()->subDays(3)))->toBeTrue()->and((int) $row->formula_version)->toBe(1);
});
