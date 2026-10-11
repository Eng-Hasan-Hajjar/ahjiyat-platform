<?php

require_once __DIR__.'/../Social/SocialTestHelpers.php';

use App\Models\Puzzle;
use App\Models\PuzzleAttempt;
use App\Models\User;
use App\Services\LeaderboardService;
use App\Support\CachedSnapshot;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * E24 - لوحة الصدارة: نفس المعيار (عدد المحاولات الصحيحة) بتجميع أولًا ثم ضمّ users للخمسين، مع Top-N مخزَّن لمدة قصيرة بلا تسريب بين المشاهدين.
 */
function e24_solve(User $user, int $times, bool $correct = true): void
{
    foreach (range(1, $times) as $_) {
        PuzzleAttempt::create(['user_id' => $user->id, 'puzzle_id' => Puzzle::factory()->create()->id, 'attempt_number' => 1, 'is_correct' => $correct]);
    }
}

function e24_attempt_queries($test, callable $request): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $request();
    $n = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'puzzle_attempts'))->count();
    DB::disableQueryLog();

    return $n;
}

test('E24: ranking is by correct-attempt count desc, wrong attempts never count, ties are ordered by user id, only 50 are returned', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    $c = User::factory()->create();
    $d = User::factory()->create();
    e24_solve($a, 3);
    e24_solve($b, 2);
    e24_solve($c, 2);
    e24_solve($d, 5, correct: false); // محاولات خاطئة فقط => خارج اللوحة

    $rows = app(LeaderboardService::class)->topSolvers();

    expect($rows->pluck('id')->all())->toBe([$a->id, $b->id, $c->id])
        ->and($rows->pluck('solved_count')->map(fn ($v) => (int) $v)->all())->toBe([3, 2, 2]);
});

test('E24: at most 50 rows even with more solvers', function () {
    foreach (range(1, 55) as $_) {
        e24_solve(User::factory()->create(), 1);
    }

    expect(app(LeaderboardService::class)->topSolvers())->toHaveCount(50);
});

test('E24: the global board is served from cache within the TTL and refreshed after it', function () {
    $a = User::factory()->create();
    e24_solve($a, 1);

    $first = e24_attempt_queries($this, fn () => $this->get(route('leaderboard.index'))->assertOk());
    $second = e24_attempt_queries($this, fn () => $this->get(route('leaderboard.index'))->assertOk());

    expect($first)->toBeGreaterThan(0)->and($second)->toBe(0);

    e24_solve($a, 2);
    $this->travel(61)->seconds();

    $this->get(route('leaderboard.index'))->assertOk()->assertSee('3 أحجية محلولة');
});

test('E24: a zero TTL disables caching (live board on every request)', function () {
    config(['performance.cache.leaderboard_ttl' => 0]);
    e24_solve(User::factory()->create(), 1);

    $this->get(route('leaderboard.index'))->assertOk();
    $second = e24_attempt_queries($this, fn () => $this->get(route('leaderboard.index'))->assertOk());

    expect($second)->toBeGreaterThan(0);
});

test('E24: cache isolation - viewer-specific data is computed live, never taken from another viewer\'s cached copy', function () {
    $owner = User::factory()->create(['profile_visibility' => User::VISIBILITY_PRIVATE, 'name' => 'صاحب خاص']);
    e24_solve($owner, 2);
    $stranger = User::factory()->create();

    // الزائر أولًا يملأ الكاش: لا رابط للملف الخاص
    $this->get(route('leaderboard.index'))->assertOk()->assertSee('صاحب خاص')->assertDontSee(route('players.show', $owner), false);
    // الغريب المسجَّل: لا رابط أيضًا
    $this->actingAs($stranger)->get(route('leaderboard.index'))->assertOk()->assertDontSee(route('players.show', $owner), false);
    // صاحب الملف نفسه: يرى رابطه رغم أن النسخة المخزَّنة جُمعت لغيره
    $this->actingAs($owner)->get(route('leaderboard.index'))->assertOk()->assertSee(route('players.show', $owner), false);
});

test('E24: the friends scope is live (not cached) and limited to me and my accepted friends', function () {
    $me = e16User();
    $friend = e16User(['name' => 'صديقي']);
    $other = e16User(['name' => 'غريب']);
    e16Befriend($me, $friend);
    e24_solve($me, 1);
    e24_solve($friend, 2);
    e24_solve($other, 9);

    $ids = app(LeaderboardService::class)->topSolvers([$me->id, $friend->id])->pluck('id')->all();
    expect($ids)->toBe([$friend->id, $me->id]);

    $this->actingAs($me)->get(route('leaderboard.index', ['scope' => 'friends']))->assertOk()->assertSee('صديقي')->assertDontSee('غريب');
    e24_solve($me, 5);
    $this->actingAs($me)->get(route('leaderboard.index', ['scope' => 'friends']))->assertOk()->assertSee('6 أحجية محلولة');
});

test('E24: the board query count is constant whatever the number of solvers', function () {
    config(['performance.cache.leaderboard_ttl' => 0]);
    foreach (range(1, 3) as $_) {
        e24_solve(User::factory()->create(), 1);
    }
    $few = e24_attempt_queries($this, fn () => $this->get(route('leaderboard.index'))->assertOk());

    foreach (range(1, 25) as $_) {
        e24_solve(User::factory()->create(), 1);
    }
    $many = e24_attempt_queries($this, fn () => $this->get(route('leaderboard.index'))->assertOk());

    expect($many)->toBe($few);
});

// ---------------- CachedSnapshot ----------------
test('E24: CachedSnapshot computes once per TTL window and serves the stored value afterwards', function () {
    $calls = 0;
    $compute = function () use (&$calls) {
        $calls++;

        return ['n' => $calls];
    };

    expect(CachedSnapshot::remember('e24.snap', 60, $compute))->toBe(['n' => 1])
        ->and(CachedSnapshot::remember('e24.snap', 60, $compute))->toBe(['n' => 1])
        ->and($calls)->toBe(1);
});

test('E24: CachedSnapshot with TTL 0 never stores', function () {
    $calls = 0;
    CachedSnapshot::remember('e24.snap0', 0, function () use (&$calls) { return ++$calls; });
    CachedSnapshot::remember('e24.snap0', 0, function () use (&$calls) { return ++$calls; });

    expect($calls)->toBe(2)->and(Cache::has('e24.snap0'))->toBeFalse();
});

test('E24: a compute error propagates, is not cached, and does not leave the lock held', function () {
    expect(fn () => CachedSnapshot::remember('e24.snap.err', 60, fn () => throw new RuntimeException('boom')))->toThrow(RuntimeException::class, 'boom');

    // القفل أُفرج عنه والقيمة غير مخزَّنة: المحاولة التالية تنجح فورًا
    expect(CachedSnapshot::remember('e24.snap.err', 60, fn () => 'ok'))->toBe('ok');
});

test('E24: CachedSnapshot fails open when the cache store is unusable', function () {
    config(['cache.default' => 'store-that-does-not-exist']);

    expect(CachedSnapshot::remember('e24.snap.open', 60, fn () => 'direct'))->toBe('direct');
});

test('E24: friendsQuery returns exactly the accepted friends of the user (and nobody else), without scanning users via subqueries', function () {
    $me = e16User();
    $f1 = e16User(['name' => 'ألف']);
    $f2 = e16User(['name' => 'باء']);
    $pending = e16User();
    $stranger = e16User();
    e16Befriend($me, $f1);
    e16Befriend($f2, $me); // الاتجاه المعاكس
    e16Svc()->sendRequest($me, $pending);

    $query = e16Svc()->friendsQuery($me);

    expect($query->pluck('id')->all())->toBe([$f1->id, $f2->id])
        ->and($query->toSql())->not->toContain('friendships')
        ->and(e16Svc()->friendsQuery($stranger)->count())->toBe(0);
});
