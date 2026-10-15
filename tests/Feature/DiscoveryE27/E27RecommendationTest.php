<?php

require_once __DIR__.'/_support.php';

use App\Models\Puzzle;
use App\Models\PuzzleAttempt;
use App\Models\PuzzleCategory;
use App\Models\Team;
use App\Models\User;
use App\Services\Discovery\RecommendationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
| E27-D - التوصيات الشخصية. بخصوصية أولًا: من تاريخ المستخدم نفسه + مجاميع عامة فقط؛ حتمية؛ بلا كاش لكل مستخدم؛ بلا كتابة؛ بلا أثر على الاقتصاد.
*/

beforeEach(fn () => $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class));

test('R1: a player with no history gets the trending puzzle first, then newer ones - no exploration slots, no history', function () {
    $category = e27_category('عام');
    $puzzles = e27_pool($category, 4, 'P');
    foreach ([e27_user(), e27_user()] as $other) {
        e27_attempt($other, $puzzles[2], false, now()->subHour());
    }
    $user = e27_user();

    $reco = e27_reco($user);
    $titles = array_map(fn ($r) => $r['puzzle']->title, $reco['puzzles']);

    expect($reco['has_history'])->toBeFalse()->and($titles[0])->toBe('P-3')->and($titles)->toHaveCount(4)->and($titles)->toEqualCanonicalizing(['P-1', 'P-2', 'P-3', 'P-4'])
        ->and($reco['puzzles'][0]['reason'])->toBe('رائجة هذا الأسبوع')
        ->and(collect($reco['puzzles'])->pluck('reason')->all())->not->toContain('تصنيف جديد لك');
    expect(e27_reco_titles($user))->toBe($titles);   // حتمي
});

test('R2: a player who solved puzzles of a category is mostly served that category, with a quarter of the slots for categories not yet tried', function () {
    $a = e27_category('منطق');
    $b = e27_category('تاريخ');
    $user = e27_user();
    [$solvedOne, $solvedTwo] = array_slice(e27_pool($a, 2, 'SOLVED'), 0, 2);
    e27_solve($user, $solvedOne);
    e27_solve($user, $solvedTwo);
    e27_pool($a, 8, 'A');
    e27_pool($b, 8, 'B');

    $reco = e27_reco($user);
    $titles = array_map(fn ($r) => $r['puzzle']->title, $reco['puzzles']);
    $reasons = array_map(fn ($r) => $r['reason'], $reco['puzzles']);

    expect($titles)->toHaveCount(8)
        ->and(count(array_filter($titles, fn ($t) => str_starts_with($t, 'A-'))))->toBe(6)
        ->and(count(array_filter($titles, fn ($t) => str_starts_with($t, 'B-'))))->toBe(2)
        ->and(array_slice($reasons, 0, 6))->each->toBe('لأنك تلعب «منطق»')
        ->and(array_slice($reasons, 6))->each->toBe('تصنيف جديد لك');
    expect($reco['has_history'])->toBeTrue();
});

test('R3: solved, exhausted, inactive and in-progress puzzles are never recommended - in-progress ones appear under continue instead', function () {
    $a = e27_category('ألغاز');
    $user = e27_user();
    $solved = e27_pool($a, 1, 'SOLVED')[0];
    $exhausted = e27_pool($a, 1, 'EXHAUSTED')[0];
    $continuing = e27_pool($a, 1, 'CONTINUE')[0];
    e27_pool($a, 1, 'INACTIVE', 'easy', ['is_active' => false]);
    e27_pool($a, 7, 'FREE');

    e27_solve($user, $solved);
    foreach ([1, 2, 3] as $n) {
        e27_attempt($user, $exhausted, false, now()->subDay(), $n);   // max_attempts = 3
    }
    e27_attempt($user, $continuing, false, now()->subHour(), 1);

    $reco = e27_reco($user);
    $titles = array_map(fn ($r) => $r['puzzle']->title, $reco['puzzles']);

    expect($titles)->not->toContain('SOLVED-1')->not->toContain('EXHAUSTED-1')->not->toContain('CONTINUE-1')->not->toContain('INACTIVE-1')->toHaveCount(7)
        ->and($reco['continue_puzzles']->pluck('title')->all())->toBe(['CONTINUE-1']);
});

test('R4: exploration order is a fixed daily hash of user, date and puzzle - the same all day, recomputed tomorrow, never random', function () {
    $a = e27_category('أ');
    $b = e27_category('ب');
    $user = e27_user();
    e27_solve($user, e27_pool($a, 1, 'SOLVED')[0]);
    e27_pool($a, 8, 'A');
    $explore = e27_pool($b, 6, 'B');

    $expect = fn () => collect($explore)->sortBy(fn (Puzzle $p) => crc32($user->id.'|'.now()->format('Y-m-d').'|'.$p->id))->take(2)->pluck('title')->values()->all();

    expect(array_slice(e27_reco_titles($user), -2))->toBe($expect())->and(e27_reco_titles($user))->toBe(e27_reco_titles($user));

    $this->travel(1)->days();
    expect(array_slice(e27_reco_titles($user), -2))->toBe($expect());
    $this->travelBack();
});

test('R5: difficulty fit - the preferred difficulty first, the next harder one second, the rest last', function () {
    $a = e27_category('تدرج');
    $user = e27_user();
    e27_solve($user, e27_pool($a, 1, 'SOLVED', 'easy')[0]);
    e27_pool($a, 2, 'HARD', 'hard');
    e27_pool($a, 2, 'MEDIUM', 'medium');
    e27_pool($a, 2, 'EASY', 'easy');

    $order = array_map(fn ($r) => $r['puzzle']->difficulty, e27_reco($user)['puzzles']);

    expect($order)->toBe(['easy', 'easy', 'medium', 'medium', 'hard', 'hard']);
});

test('R6: among otherwise equal puzzles, the one many players are trying this week comes first', function () {
    $a = e27_category('شعبية');
    $user = e27_user();
    e27_solve($user, e27_pool($a, 1, 'SOLVED')[0]);
    $pool = e27_pool($a, 4, 'A');
    foreach ([e27_user(), e27_user()] as $other) {
        e27_attempt($other, $pool[1], false, now()->subHour());
    }

    expect(e27_reco_titles($user)[0])->toBe('A-2');
});

test('R7: new content gets a freshness boost - a recent puzzle outranks an old one with the same signals', function () {
    $a = e27_category('حداثة');
    $user = e27_user();
    e27_solve($user, e27_pool($a, 1, 'SOLVED')[0]);
    [$fresh, $stale] = e27_pool($a, 2, 'N');
    DB::table('puzzles')->where('id', $stale->id)->update(['created_at' => now()->subDays(40)]);

    expect(e27_reco_titles($user))->toBe(['N-1', 'N-2']);
    DB::table('puzzles')->where('id', $fresh->id)->update(['created_at' => now()->subDays(60)]);
    DB::table('puzzles')->where('id', $stale->id)->update(['created_at' => now()->subDay()]);
    expect(e27_reco_titles($user))->toBe(['N-2', 'N-1']);
});

test('R8: nothing is cached per user - history changes show at once and the cache holds only the public trending snapshot', function () {
    $a = e27_category('طازج');
    $user = e27_user();
    $pool = e27_pool($a, 3, 'F');
    e27_solve($user, e27_pool($a, 1, 'SOLVED')[0]);

    expect(e27_reco_titles($user))->toContain('F-1');
    e27_solve($user, $pool[0]);
    expect(e27_reco_titles($user))->not->toContain('F-1');   // لو كان هناك كاش لكل مستخدم لبقيت

    $store = Cache::getStore();
    $storage = new ReflectionProperty($store, 'storage');
    $storage->setAccessible(true);
    $keys = array_keys($storage->getValue($store));

    // مفاتيح البحث/الاكتشاف الوحيدة: لقطات الرائج العامة ومجسّ حجم الجدول. لا مفتاح باسم مستخدم أو توصيات. (مفاتيح أخرى كاقتصاد المنصة ليست من هذه الحزمة.)
    $ours = array_values(array_filter($keys, fn ($key) => preg_match('/search|reco|discover/i', $key)));

    expect($ours)->not->toBe([]);
    foreach ($ours as $key) {
        expect($key)->toMatch('/^search\.(trending\.(puzzles|teams)\.v1|probe\.[a-z_]+)(:lock)?$/', "unexpected cache key: {$key}");
    }
});

test('R9: two players never influence each other - each result depends only on their own history', function () {
    $a = e27_category('عزل أ');
    $b = e27_category('عزل ب');
    [$u1, $u2] = [e27_user(), e27_user()];
    e27_pool($a, 8, 'A');
    e27_pool($b, 8, 'B');
    e27_solve($u1, e27_pool($a, 1, 'S1')[0]);
    e27_solve($u2, e27_pool($b, 1, 'S2')[0]);

    $first1 = e27_reco_titles($u1);
    $second1 = e27_reco_titles($u2);

    expect(array_slice($first1, 0, 3))->each->toStartWith('A-')->and(array_slice($second1, 0, 3))->each->toStartWith('B-');
    expect(e27_reco_titles($u1))->toBe($first1)->and(e27_reco_titles($u2))->toBe($second1);
});

test('R10: friends, teams and chat are not signals - befriending a heavy player of another category changes nothing', function () {
    $a = e27_category('خصوصية أ');
    $b = e27_category('خصوصية ب');
    $user = e27_user();
    $friend = e27_user();
    e27_pool($a, 8, 'A');
    e27_pool($b, 8, 'B');
    e27_solve($user, e27_pool($a, 1, 'SOLVED-A')[0]);
    foreach (e27_pool($b, 5, 'FRIEND-SOLVED') as $puzzle) {
        e27_solve($friend, $puzzle);                  // لاعب واحد: دون حدّ الرائج، فلا يدخل المجاميع العامة
    }

    $before = e27_reco_titles($user);
    e16Befriend($user, $friend);
    $team = e19Team($friend);
    e19Member($team, $user);

    expect(e27_reco_titles($user))->toBe($before);
});

test('R11: the history window is capped by configuration - only the most recent distinct puzzles shape the affinity', function () {
    config(['search.recommendations.history_cap' => 1]);
    $a = e27_category('قديم');
    $b = e27_category('حديث');
    $user = e27_user();
    foreach (e27_pool($a, 3, 'OLD-SOLVED') as $puzzle) {
        e27_solve($user, $puzzle, now()->subDays(3));
    }
    e27_attempt($user, e27_pool($b, 1, 'RECENT-TRY')[0], false, now()->subHour());
    e27_pool($a, 8, 'A');
    e27_pool($b, 8, 'B');

    expect(array_slice(e27_reco_titles($user), 0, 3))->each->toStartWith('B-');
});

test('R12: the exploration share is configurable - zero means no exploration slots', function () {
    config(['search.recommendations.exploration_share' => 0]);
    $a = e27_category('بلا استكشاف أ');
    $b = e27_category('بلا استكشاف ب');
    $user = e27_user();
    e27_solve($user, e27_pool($a, 1, 'SOLVED')[0]);
    e27_pool($a, 8, 'A');
    e27_pool($b, 8, 'B');

    $reco = e27_reco($user);

    expect($reco['puzzles'])->toHaveCount(8)->and(collect($reco['puzzles'])->pluck('reason')->all())->not->toContain('تصنيف جديد لك')
        ->and(collect($reco['puzzles'])->map(fn ($r) => $r['puzzle']->title)->all())->each->toStartWith('A-');
});

test('R13: the result is bounded by the configured limit and every item says why it was suggested', function () {
    config(['search.recommendations.limit' => 3]);
    $a = e27_category('حدّ');
    $user = e27_user();
    e27_solve($user, e27_pool($a, 1, 'SOLVED')[0]);
    e27_pool($a, 10, 'A');

    $reco = e27_reco($user);

    expect($reco['puzzles'])->toHaveCount(3);
    foreach ($reco['puzzles'] as $row) {
        expect($row['reason'])->toBeString()->not->toBe('');
    }
});

test('R14: recommendations are read-only - no write queries and no effect on XP, currency, quests, streaks, achievements, purchases or attempts', function () {
    $a = e27_category('قراءة');
    $user = e27_user();
    e27_solve($user, e27_pool($a, 1, 'SOLVED')[0]);
    e27_pool($a, 5, 'A');
    $before = [...e16Snapshot(), PuzzleAttempt::count()];

    $sql = e27_sql(fn () => e27_reco($user));

    expect(array_values(array_filter($sql, fn ($q) => preg_match('/^\s*(insert|update|delete|replace)\b/i', $q))))->toBe([])
        ->and([...e16Snapshot(), PuzzleAttempt::count()])->toBe($before);
});

test('R15: campaigns - started and unfinished ones come back as continue, finished and unavailable ones do not, and new ones exclude everything already started', function () {
    $user = e27_user();
    $started = e27_campaign('بدأتها');
    $finished = e27_campaign('أنهيتها');
    $inactive = e27_campaign('معطّلة', ['is_active' => false]);
    $expired = e27_campaign('منتهية', ['ends_at' => now()->subDay()]);
    $newOne = e27_campaign('جديدة 1');
    $newTwo = e27_campaign('جديدة 2');
    foreach ([$started, $inactive, $expired] as $campaign) {
        e27_progress($user, $campaign);
    }
    e27_progress($user, $finished, true);

    $reco = e27_reco($user);

    expect($reco['continue_campaigns']->pluck('title')->all())->toBe(['بدأتها'])
        ->and($reco['campaigns']->pluck('title')->all())->toBe(['جديدة 2', 'جديدة 1']);
});

test('R16: competitions - public unfinished ones the player has not joined; teams - open ones, only for players without a team', function () {
    $user = e27_user();
    $open = e17Event(['title' => 'منافسة مفتوحة']);
    $joined = e17Event(['title' => 'منافسة مسجَّل بها']);
    e17Event(['title' => 'منافسة مسودة'], \App\Models\CompetitiveEvent::STATUS_DRAFT);
    e17Event(['title' => 'منافسة منتهية', 'starts_at' => now()->subDays(2), 'ends_at' => now()->subDay()]);
    \App\Models\CompetitiveEventParticipant::create(['competitive_event_id' => $joined->id, 'user_id' => $user->id, 'team_id_snapshot' => null, 'status' => 'completed', 'registered_at' => now()->subHour()]);

    $visible = e19Team(null, ['name' => 'فريق مفتوح']);
    $invite = e19Team(null, ['name' => 'فريق بدعوة']);
    $invite->forceFill(['join_policy' => Team::JOIN_INVITE_ONLY])->save();
    $private = e19Team(null, ['name' => 'فريق خاص']);
    $private->forceFill(['visibility' => Team::VISIBILITY_PRIVATE])->save();
    $full = e19Team(null, ['name' => 'فريق ممتلئ']);
    $full->forceFill(['max_members' => 1, 'members_count' => 1])->save();

    $reco = e27_reco($user);

    expect($reco['competitions']->pluck('title')->all())->toBe(['منافسة مفتوحة'])
        ->and($reco['teams']->pluck('name')->all())->toBe(['فريق مفتوح']);

    e19Member($visible, $user);
    expect(e27_reco($user)['teams']->all())->toBe([]);
});
