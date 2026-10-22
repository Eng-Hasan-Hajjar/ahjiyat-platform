<?php

use App\Models\CommunityReputationSnapshot;
use App\Models\CreatorProfile;
use App\Models\CreatorPuzzle;
use App\Models\User;
use App\Services\Creators\CreatorApplicationService;
use App\Services\Progression\AchievementService;
use App\Services\Reputation\CommunityReputationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/_support.php';

/*
| E37 AV2 (15-20) اللقطة + AV3 (21-27) ترتيب أفضل المبدعين + AV4 (28-32) الصاعدون.
| اللقطة مشتقّة قابلة لإعادة البناء، والترتيبات العلنية تعيد فحص الظهور حيًّا بعد الكاش، والصاعدون نافذة حتمية بجودة دنيا.
*/

beforeEach(fn () => e37_seed());

/* ===================== AV2. SNAPSHOT ===================== */

test('E37-015: a snapshot is created by recalculation (command by slug, dry-run writes nothing)', function () {
    [$creator] = e37_eligible_creator();
    $slug = e37_profile($creator)->slug;

    expect(Artisan::call('reputation:recalculate', ['--creator' => $slug, '--dry-run' => true]))->toBe(0)->and(CommunityReputationSnapshot::query()->count())->toBe(0);

    expect(Artisan::call('reputation:recalculate', ['--creator' => $slug]))->toBe(0)
        ->and(CommunityReputationSnapshot::query()->where('user_id', $creator->id)->count())->toBe(1)
        ->and((bool) e37_snap($creator)->creator_eligible)->toBeTrue();
});

test('E37-016: snapshots are rebuildable - delete them all and --all restores identical scores', function () {
    [$a] = e37_eligible_creator([5, 5, 5, 5, 5, 5]);
    [$b] = e37_eligible_creator([4, 4, 3, 4, 4, 3]);
    e37_refresh($a, $b);
    $before = CommunityReputationSnapshot::query()->orderBy('user_id')->get(['user_id', 'creator_score', 'creator_rating_score', 'creator_eligible', 'ratings_count', 'works_count'])->toArray();

    CommunityReputationSnapshot::query()->delete();
    expect(Artisan::call('reputation:recalculate', ['--all' => true]))->toBe(0);

    $after = CommunityReputationSnapshot::query()->whereIn('user_id', [$a->id, $b->id])->orderBy('user_id')->get(['user_id', 'creator_score', 'creator_rating_score', 'creator_eligible', 'ratings_count', 'works_count'])->toArray();

    expect(array_values(array_filter($before, fn ($r) => in_array($r['user_id'], [$a->id, $b->id], true))))->toEqual($after);
});

test('E37-017: the snapshot matches the authority rows exactly (ratings, favorites, players, followers, series-free)', function () {
    [$creator, $works, $raters] = e37_eligible_creator([5, 4, 5, 3, 5, 4]);
    $fans = e37_users(4);

    foreach ($fans as $i => $fan) {
        e37_fav($works[0], $fan);
        e37_play($works[0], $fan);
        e37_follow($creator, $fan);
    }

    e37_play($works[1], $fans[0]); // نفس اللاعب على عمل آخر: لاعب مميَّز واحد
    e37_fav($works[0], $creator);   // ذاتي
    e37_play($works[0], $creator);  // ذاتي
    e37_follow($creator, $creator); // ذاتي
    e37_refresh($creator);
    $s = e37_snap($creator);

    $wid = collect($works)->pluck('id');
    $rows = DB::table('community_puzzle_ratings')->whereIn('creator_puzzle_id', $wid)->where('user_id', '!=', $creator->id);

    expect((int) $s->ratings_count)->toBe((int) (clone $rows)->count())->and((int) $s->ratings_sum)->toBe((int) (clone $rows)->sum('rating'))
        ->and((int) $s->favorites_count)->toBe(4)->and((int) $s->players_count)->toBe(4)->and((int) $s->followers_count)->toBe(4)
        ->and((int) $s->works_count)->toBe(2)->and((int) $s->raters_count)->toBe(6);
});

test('E37-018: a stale snapshot recalculates on the scheduled pass; a fresh one is left alone', function () {
    [$creator, $works] = e37_eligible_creator();
    e37_refresh($creator);
    $extra = e37_users(2);
    e37_rate($works[0], $extra[0], 5);
    e37_rate($works[1], $extra[1], 5);

    Artisan::call('reputation:recalculate', ['--stale' => true]);
    expect((int) e37_snap($creator)->ratings_count)->toBe(12); // طازجة: لم تُمسّ

    DB::table('community_reputation_snapshots')->where('user_id', $creator->id)->update(['calculated_at' => now()->subHours(5)]);
    Artisan::call('reputation:recalculate', ['--stale' => true]);

    expect((int) e37_snap($creator)->ratings_count)->toBe(14)->and(e37_snap($creator)->calculated_at->diffInMinutes(now()))->toBeLessThan(2);

    // صيغة أقدم تُعاد حسابها أيضًا.
    DB::table('community_reputation_snapshots')->where('user_id', $creator->id)->update(['formula_version' => 0, 'ratings_count' => 1]);
    Artisan::call('reputation:recalculate', ['--stale' => true]);

    expect((int) e37_snap($creator)->ratings_count)->toBe(14)->and((int) e37_snap($creator)->formula_version)->toBe(1);
});

test('E37-019: deleting snapshots changes no domain data (ratings, favorites, follows, creator status, achievements-free)', function () {
    [$creator, $works, $raters] = e37_eligible_creator();
    e37_fav($works[0], $raters[0]);
    e37_follow($creator, $raters[1]);
    e37_refresh($creator);

    $domain = fn () => [
        DB::table('community_puzzle_ratings')->count(), DB::table('community_puzzle_favorites')->count(), DB::table('creator_follows')->count(),
        DB::table('creator_puzzles')->where('is_visible', true)->count(), e37_profile($creator)->status, (int) e37_profile($creator)->followers_count,
        DB::table('wallets')->sum('available_balance'), DB::table('users')->count(),
    ];

    $before = $domain();
    CommunityReputationSnapshot::query()->delete();

    expect($domain())->toEqual($before);
});

test('E37-020: reputation failure cannot roll back a rating, favorite, follow, comment or save (no hooks: structural isolation); an achievements failure keeps the snapshot', function () {
    // (أ) لا خطّافات: حتى لو تعطّلت خدمة السمعة كليًا تنجح التقييمات والمفضلة والمتابعة بخدماتها الرسمية.
    app()->bind(CommunityReputationService::class, fn () => throw new RuntimeException('reputation is down'));
    [$creator, $works] = e37_creator(1);
    $player = e29_player_who_played($works[0]);

    expect(e29_rate($player, $works[0], 5)['count'] ?? null)->not->toBeNull();
    e29_favorite($player, $works[0]);
    e29_follow($player, $creator);

    expect(DB::table('community_puzzle_ratings')->where('user_id', $player->id)->exists())->toBeTrue()
        ->and(DB::table('community_puzzle_favorites')->where('user_id', $player->id)->exists())->toBeTrue()
        ->and(DB::table('creator_follows')->where('user_id', $player->id)->exists())->toBeTrue();

    // (ب) فشل الاعتراف (الإنجازات) لا يُسقط اللقطة المكتوبة.
    app()->forgetInstance(CommunityReputationService::class);
    app()->offsetUnset(CommunityReputationService::class);
    app()->bind(AchievementService::class, fn () => throw new RuntimeException('achievements down'));

    [$c2] = e37_eligible_creator();
    e37_refresh($c2);

    expect((bool) e37_snap($c2)->creator_eligible)->toBeTrue();
});

/* ===================== AV3. CREATOR RANKINGS ===================== */

test('E37-021: Top Creators are sorted deterministically by quality (score desc)', function () {
    [$best] = e37_eligible_creator([5, 5, 5, 5, 5, 5]);
    [$mid] = e37_eligible_creator([4, 4, 5, 4, 4, 4]);
    [$low] = e37_eligible_creator([4, 3, 4, 4, 3, 4]);
    e37_refresh($low, $best, $mid);

    $names = e37_names(e37_rankings()->creators());
    $rank = e37_rankings()->creators();

    expect($names)->toBe([$best->name, $mid->name, $low->name])->and(array_column($rank, 'rank'))->toBe([1, 2, 3])
        ->and(e37_names(e37_rankings()->creators()))->toBe($names);
});

test('E37-022: ties are deterministic - more evidence first, then lowest user id; never random', function () {
    $x = e37_bulk_creator(7000, 2, ['ratings_count' => 10]);
    $y = e37_bulk_creator(7000, 2, ['ratings_count' => 30]);
    $z = e37_bulk_creator(7000, 2, ['ratings_count' => 10]);

    $order = e37_names(e37_rankings()->creators());

    expect($order)->toBe([$y->name, $x->name, $z->name]); // 30 ثم 10 (أصغر id) ثم 10 (أكبر id)

    for ($i = 0; $i < 3; $i++) {
        expect(e37_names(e37_rankings()->creators()))->toBe($order);
    }
});

test('E37-023: an ineligible creator is absent however high the stored score', function () {
    $ok = e37_bulk_creator(4000);
    $thin = e37_bulk_creator(9900, 2, ['creator_eligible' => false, 'ratings_count' => 2]);
    $oneWork = e37_bulk_creator(9800, 1);

    expect(e37_names(e37_rankings()->creators()))->toBe([$ok->name])->and(in_array($thin->name, e37_names(e37_rankings()->creators()), true))->toBeFalse()
        ->and(in_array($oneWork->name, e37_names(e37_rankings()->creators()), true))->toBeFalse();
});

test('E37-024: a creator suspended or frozen after the ranking was cached disappears immediately (live recheck, no stale resurrection)', function () {
    config(['reputation.rankings.cache_seconds' => 600]);
    Cache::flush();
    $a = e37_bulk_creator(8000);
    $b = e37_bulk_creator(6000);
    $c = e37_bulk_creator(5000);

    expect(e37_names(e37_rankings()->creators()))->toBe([$a->name, $b->name, $c->name]);

    DB::table('creator_profiles')->where('user_id', $a->id)->update(['status' => CreatorProfile::STATUS_SUSPENDED]);
    $b->forceFill(['is_frozen' => true])->save();

    expect(Cache::has('reputation.pool.creators.v1'))->toBeTrue()->and(e37_names(e37_rankings()->creators()))->toBe([$c->name]);

    // الكاش لا يحمل أسماء ولا درجات: معرّفات فقط.
    expect(collect(Cache::get('reputation.pool.creators.v1'))->every(fn ($v) => is_int($v)))->toBeTrue();
});

test('E37-025: hiding works below the minimum drops the creator from the ranking at once; restoring returns them', function () {
    [$creator, $works] = e37_eligible_creator();
    e37_refresh($creator);

    expect(e37_names(e37_rankings()->creators()))->toBe([$creator->name]);

    e29_hide($works[0]); // يبقى عمل ظاهر واحد < الحد الأدنى (2) ← يخرج فورًا حتى قبل إعادة الحساب
    expect(e37_names(e37_rankings()->creators()))->toBe([]);

    e29_unhide($works[0]);
    expect(e37_names(e37_rankings()->creators()))->toBe([$creator->name]);
});

test('E37-026: the ranking list is bounded (list limit and candidate pool), not paginated into the whole table', function () {
    foreach (range(1, 25) as $i) {
        e37_bulk_creator(1000 + $i);
    }

    expect(count(e37_rankings()->creators()))->toBe(20)->and(count(e37_rankings()->creators(null, 5)))->toBe(5);

    config(['reputation.rankings.pool' => 3]);
    expect(count(e37_rankings()->creators()))->toBe(3);

    // الأعلى درجة أولًا حتى مع الحوض الصغير.
    expect(e37_names(e37_rankings()->creators(null, 1)))->toBe([User::query()->find(DB::table('community_reputation_snapshots')->orderByDesc('creator_score')->value('user_id'))->name]);
});

test('E37-027: the all-time ranking is stable - 20 quiet days barely move the score, and a recent burst alone cannot enter it', function () {
    [$creator] = e37_eligible_creator();
    e37_refresh($creator);
    $before = (int) e37_snap($creator)->creator_score;

    Carbon::setTestNow(now()->addDays(20));
    e37_refresh($creator);
    $after = (int) e37_snap($creator)->creator_score;
    Carbon::setTestNow();

    expect(abs($before - $after))->toBeLessThan(100); // < 1% من السلّم

    // دفقة حديثة بعيّنة صغيرة (مبدع جديد) لا تدخل «الأفضل»: ليست أهلية (عيّنة 4 تقييمات < 8).
    [$fresh, $w] = e37_creator(2);

    foreach (e37_users(4) as $u) {
        e37_rate($w[0], $u, 5);
    }

    e37_refresh($fresh);

    expect(in_array($fresh->name, e37_names(e37_rankings()->creators()), true))->toBeFalse();
});

/* ===================== AV4. RISING ===================== */

test('E37-028: a recent high-quality creator is rising-eligible even as a newcomer with one work', function () {
    [$creator, $works] = e37_creator(1);

    foreach (e37_users(4) as $u) {
        e37_rate($works[0], $u, 5);
    }

    e37_refresh($creator);

    expect((bool) e37_snap($creator)->rising_eligible)->toBeTrue()->and((int) e37_snap($creator)->rising_score)->toBeGreaterThan(0)
        ->and(e37_names(e37_rankings()->rising()))->toBe([$creator->name]);
});

test('E37-029: old activity is weighted appropriately - ratings older than the window do not count toward rising', function () {
    [$old, $w1] = e37_creator(1);
    [$new, $w2] = e37_creator(1);
    $raters = e37_users(5);

    foreach ($raters as $u) {
        e37_rate($w1[0], $u, 5, now()->subDays(60)->toDateTimeString());
        e37_rate($w2[0], $u, 5);
    }

    e37_refresh($old, $new);

    expect((int) e37_snap($old)->recent_ratings_count)->toBe(0)->and((bool) e37_snap($old)->rising_eligible)->toBeFalse()->and((int) e37_snap($old)->ratings_count)->toBe(5)
        ->and((int) e37_snap($new)->recent_ratings_count)->toBe(5)->and((int) e37_snap($new)->rising_score)->toBeGreaterThan((int) e37_snap($old)->rising_score);
});

test('E37-030: publishing lots of content is not enough - volume does not enter the rising formula and no recent ratings means not eligible', function () {
    $few = e37_scores(['works' => 1, 'recent_ratings_count' => 5, 'recent_ratings_sum' => 24, 'recent_favorites' => 3]);
    $spam = e37_scores(['works' => 500, 'recent_ratings_count' => 5, 'recent_ratings_sum' => 24, 'recent_favorites' => 3]);

    expect($spam['rising_score'])->toBe($few['rising_score']);

    [$creator, $works] = e37_creator(6);
    e37_refresh($creator);

    expect((int) e37_snap($creator)->works_count)->toBe(6)->and((bool) e37_snap($creator)->rising_eligible)->toBeFalse()->and((int) e37_snap($creator)->rising_score)->toBe(0);
});

test('E37-031: the minimum quality floor is enforced - recent ratings with a poor average are not rising', function () {
    expect(e37_scores(['recent_ratings_count' => 5, 'recent_ratings_sum' => 15])['rising_eligible'])->toBeFalse()   // متوسط 3.0 ← منكمش 3.25
        ->and(e37_scores(['recent_ratings_count' => 5, 'recent_ratings_sum' => 22])['rising_eligible'])->toBeTrue()  // 4.4 ← 3.97
        ->and(e37_scores(['recent_ratings_count' => 2, 'recent_ratings_sum' => 10])['rising_eligible'])->toBeFalse(); // عيّنة < 3
});

test('E37-032: the window is deterministic - the boundary is the start of day minus window_days, inclusive', function () {
    [$creator, $works] = e37_creator(1);
    [$a, $b, $c] = e37_users(3);
    Carbon::setTestNow('2026-12-15 10:00:00');
    e37_rate($works[0], $a, 5, '2026-11-15 00:00:00');   // على الحدّ: يُحتسب
    e37_rate($works[0], $b, 5, '2026-11-14 23:59:59');   // قبل الحدّ بثانية: لا
    e37_rate($works[0], $c, 5, '2026-12-15 09:00:00');   // اليوم: يُحتسب
    e37_refresh($creator);
    $once = (int) e37_snap($creator)->recent_ratings_count;
    e37_refresh($creator);

    expect($once)->toBe(2)->and((int) e37_snap($creator)->recent_ratings_count)->toBe(2)->and((int) e37_snap($creator)->ratings_count)->toBe(3);
    Carbon::setTestNow();
});
