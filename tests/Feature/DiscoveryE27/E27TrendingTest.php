<?php

require_once __DIR__.'/_support.php';

use App\Models\CompetitiveEvent;
use App\Models\Puzzle;
use App\Models\PuzzleAttempt;
use App\Models\Team;
use App\Services\Discovery\TrendingService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
| E27-C - الرائج. إشارات عامة مجمَّعة فقط: لاعبون مميَّزون لا محاولات، نافذة زمنية، أقدمية، حلّالون، حدّ أدنى، استبعاد غير الفعّال/المجمَّد/غير الموثَّق/السياقات.
| الكاش يحمل المعرّفات والدرجات فقط. لا كتابة ولا أثر على أي اقتصاد.
*/

beforeEach(fn () => $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class));

test('T1: trending counts distinct players, not attempts - one player hammering a puzzle never outranks a broad audience', function () {
    $hammered = e27_puzzle('أحجية مكررة');
    $broad = e27_puzzle('أحجية واسعة');
    $one = e27_user();

    for ($i = 1; $i <= 50; $i++) {
        e27_attempt($one, $hammered, false, now()->subHour(), $i);
    }
    e27_play($broad, 3);

    $scores = e27_trending()->puzzleScores();

    expect(array_keys($scores))->toBe([$broad->id])->and($scores[$broad->id]['players'])->toBe(3);
});

test('T2: the minimum number of distinct players is configurable and applies to the whole list', function () {
    $single = e27_puzzle('لاعب واحد');
    $broad = e27_puzzle('جمهور واسع');
    e27_attempt(e27_user(), $single, false, now()->subHour());
    e27_play($broad, 3);

    expect(array_keys(e27_trending()->puzzleScores()))->toBe([$broad->id]);

    config(['search.trending.min_unique_players' => 1]);
    e27_cold();

    expect(array_keys(e27_trending()->puzzleScores()))->toBe([$broad->id, $single->id]);
});

test('T3: only the configured window counts - attempts older than the window never contribute', function () {
    $old = e27_puzzle('قديمة');
    $mid = e27_puzzle('وسطى');
    e27_play($old, 2, now()->subDays(8));     // أقدم أولًا: المعرّف يزداد مع الزمن كما بالإنتاج
    e27_play($mid, 2, now()->subDays(5));

    expect(e27_trending()->puzzleScores())->toHaveKey($mid->id)->not->toHaveKey($old->id);

    config(['search.trending.window_days' => 3]);
    e27_cold();
    expect(e27_trending()->puzzleScores())->toBe([]);

    config(['search.trending.window_days' => 30]);
    e27_cold();
    expect(array_keys(e27_trending()->puzzleScores()))->toEqualCanonicalizing([$old->id, $mid->id]);
});

test('T4: score = 4*(players 24h) + 2*(players 3d) + 1*(players window) + 2*solvers, so recent activity outranks old activity', function () {
    $week = e27_puzzle('قبل خمسة أيام');
    $mid = e27_puzzle('قبل يومين');
    $fresh = e27_puzzle('قبل ساعتين');
    e27_play($week, 2, now()->subDays(5));
    e27_play($mid, 2, now()->subDays(2));
    e27_play($fresh, 2, now()->subHours(2));

    $scores = e27_trending()->puzzleScores();

    expect(array_keys($scores))->toBe([$fresh->id, $mid->id, $week->id])
        ->and($scores[$fresh->id]['score'])->toBe(4 * 2 + 2 * 2 + 1 * 2)
        ->and($scores[$mid->id]['score'])->toBe(2 * 2 + 1 * 2)
        ->and($scores[$week->id]['score'])->toBe(1 * 2);
});

test('T5: players who solved the puzzle add the solver weight', function () {
    $plain = e27_puzzle('بلا حلول');
    $solved = e27_puzzle('بحلّالين');
    e27_play($plain, 2);
    e27_play($solved, 2, null, 1);

    $scores = e27_trending()->puzzleScores();

    expect($scores[$solved->id]['score'])->toBe($scores[$plain->id]['score'] + 2)->and(array_key_first($scores))->toBe($solved->id);
});

test('T6: weights come from configuration, not from code', function () {
    config(['search.trending.recency_weights' => ['d1' => 10, 'd3' => 0, 'd7' => 0], 'search.trending.solver_weight' => 0]);
    $puzzle = e27_puzzle('أوزان مخصصة');
    e27_play($puzzle, 2, null, 2);
    e27_cold();

    expect(e27_trending()->puzzleScores()[$puzzle->id]['score'])->toBe(20);
});

test('T7: inactive puzzles, frozen players, unverified players and context attempts (events, challenges, campaigns) never count', function () {
    $inactive = e27_puzzle('معطّلة', ['is_active' => false]);
    e27_play($inactive, 3);

    $mixed = e27_puzzle('أحجية مختلطة');
    e27_attempt(e27_user(), $mixed, false, now()->subHour());
    e27_attempt(e27_user(['is_frozen' => true]), $mixed, false, now()->subHour());
    e27_attempt(e27_user(['email_verified_at' => null]), $mixed, false, now()->subHour());

    $contextual = e27_puzzle('داخل سياق');
    foreach (['competitive_event', 'friend_challenge', 'campaign_step'] as $context) {
        e27_attempt(e27_user(), $contextual, false, now()->subHour(), 1, $context);
    }

    $real = e27_puzzle('أحجية حقيقية');
    e27_play($real, 2);
    foreach (range(1, 5) as $i) {
        e27_attempt(e27_user(), $real, true, now()->subHour(), 1, 'competitive_event');   // لا تضيف لاعبين ولا حلّالين
    }

    $scores = e27_trending()->puzzleScores();

    expect(array_keys($scores))->toBe([$real->id])->and($scores[$real->id]['players'])->toBe(2)->and($scores[$real->id]['score'])->toBe(14);
});

test('T8: ties are broken by the higher id - a fixed, deterministic rule', function () {
    $first = e27_puzzle('الأولى');
    $second = e27_puzzle('الثانية');
    e27_play($first, 2);
    e27_play($second, 2);

    expect(array_keys(e27_trending()->puzzleScores()))->toBe([$second->id, $first->id]);
    e27_cold();
    expect(array_keys(e27_trending()->puzzleScores()))->toBe([$second->id, $first->id]);
});

test('T9: the cache holds puzzle ids and scores only - no names, emails or viewer data', function () {
    $puzzle = e27_puzzle('أحجية للكاش');
    $players = e27_play($puzzle, 2);
    $players[0]->forceFill(['name' => 'لاعب-سري-للكاش', 'email' => 'cache-secret@example.test'])->save();

    e27_trending()->puzzles();
    $cached = Cache::get('search.trending.puzzles.v1');

    expect($cached)->toBe([$puzzle->id => ['score' => 14, 'players' => 2]]);
    expect(json_encode($cached))->not->toContain('لاعب-سري')->not->toContain('cache-secret')->not->toContain('أحجية للكاش');
});

test('T10: withdrawing a puzzle hides it at once even while the cached ranking still lists it', function () {
    $keep = e27_puzzle('باقية');
    $gone = e27_puzzle('ستُسحب');
    e27_play($keep, 2);
    e27_play($gone, 3);

    expect(e27_trending()->puzzles()->pluck('title')->all())->toBe(['ستُسحب', 'باقية']);

    $gone->forceFill(['is_active' => false])->save();

    expect(e27_trending()->puzzles()->pluck('title')->all())->toBe(['باقية'])
        ->and(array_keys(Cache::get('search.trending.puzzles.v1')))->toContain($gone->id);   // ما زال بالكاش، لكنه لا يُعرض
});

test('T11: the window start is found by binary search on the primary key and always lands on the first in-window attempt', function (int $old, int $recent) {
    $puzzle = e27_puzzle('بحث ثنائي');
    $user = e27_user();
    $ids = [];

    for ($i = 1; $i <= $old; $i++) {
        $ids['old'][] = e27_attempt($user, $puzzle, false, now()->subDays(10), $i);
    }
    for ($i = 1; $i <= $recent; $i++) {
        $ids['recent'][] = e27_attempt($user, $puzzle, false, now()->subHours(2), $old + $i);
    }

    $method = new ReflectionMethod(TrendingService::class, 'firstAttemptIdSince');
    $method->setAccessible(true);
    $found = $method->invoke(e27_trending(), now()->subDays(7));

    expect($found)->toBe($recent === 0 ? null : $ids['recent'][0]);
})->with([
    'nothing at all' => [0, 0],
    'only recent' => [0, 5],
    'only old' => [6, 0],
    'one old, one recent' => [1, 1],
    'many old, one recent' => [57, 1],
    'one old, many recent' => [1, 40],
    'balanced' => [33, 33],
]);

test('T12: trending is read-only - no write queries and no effect on XP, currency, quests, streaks, achievements, purchases or attempts', function () {
    $puzzle = e27_puzzle('قراءة فقط');
    e27_play($puzzle, 3, null, 2);
    $before = [...e16Snapshot(), PuzzleAttempt::count()];

    $sql = e27_sql(function () {
        e27_trending()->puzzles();
        e27_trending()->competitions();
        e27_trending()->teams();
    });

    expect(array_values(array_filter($sql, fn ($q) => preg_match('/^\s*(insert|update|delete|replace)\b/i', $q))))->toBe([])
        ->and([...e16Snapshot(), PuzzleAttempt::count()])->toBe($before);
});

test('T13: trending competitions are public, not yet ended and have enough participants - ordered by participants', function () {
    $a = e17Event(['title' => 'منافسة أ']);
    $b = e17Event(['title' => 'منافسة ب']);
    $draft = e17Event(['title' => 'مسودة'], CompetitiveEvent::STATUS_DRAFT);
    $ended = e17Event(['title' => 'منتهية', 'starts_at' => now()->subDays(2), 'ends_at' => now()->subDay()]);
    $few = e17Event(['title' => 'قليلة']);
    foreach ([[$a, 5], [$b, 9], [$draft, 20], [$ended, 30], [$few, 1]] as [$event, $count]) {
        DB::table('competitive_events')->where('id', $event->id)->update(['participants_count' => $count]);
    }

    expect(e27_trending()->competitions()->pluck('title')->all())->toBe(['منافسة ب', 'منافسة أ']);
});

test('T14: rising teams need enough new members inside the window and must be public and active', function () {
    $rising = e19Team(null, ['name' => 'فريق صاعد']);
    e19Member($rising);
    e19Member($rising);

    $quiet = e19Team(null, ['name' => 'فريق هادئ']);

    $private = e19Team(null, ['name' => 'فريق خاص']);
    e19Member($private);
    e19Member($private);
    $private->forceFill(['visibility' => Team::VISIBILITY_PRIVATE])->save();

    $old = e19Team(null, ['name' => 'فريق قديم']);
    e19Member($old);
    e19Member($old);
    DB::table('team_memberships')->where('team_id', $old->id)->update(['joined_at' => now()->subDays(20)]);

    $stopped = e19Team(null, ['name' => 'فريق متوقف']);
    e19Member($stopped);
    e19Member($stopped);
    $stopped->forceFill(['is_active' => false])->save();

    expect(e27_trending()->teams()->pluck('name')->all())->toBe(['فريق صاعد']);
});

test('T15: the discover page shows trending as a count of players only - never a name, id or email', function () {
    $puzzle = e27_puzzle('أحجية الرائج');
    $players = e27_play($puzzle, 2);
    foreach ($players as $i => $player) {
        $player->forceFill(['name' => 'لاعب-ظاهر-'.$i, 'email' => "leak{$i}@example.test"])->save();
    }

    $html = $this->get(route('discover.index'))->assertOk()->getContent();
    $section = uiXpath($html)->query('//section[@data-discover-section="trending"]');

    expect($section->length)->toBe(1)->and($section->item(0)->textContent)->toContain('أحجية الرائج')->toContain('2 لاعب هذا الأسبوع');
    expect($html)->not->toContain('لاعب-ظاهر')->not->toContain('leak0@')->not->toContain('leak1@');
});
