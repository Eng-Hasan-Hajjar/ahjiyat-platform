<?php

use App\Models\Season;
use App\Models\SeasonSupportPointEntry;
use App\Models\User;
use App\Services\SeasonalRecognition\SeasonSupporterLeaderboardService;
use App\Services\SeasonalRecognition\SeasonSupporterReconciler;
use App\Services\SeasonalRecognition\SeasonWriterLeaderboardService;
use App\Services\SeasonalRecognition\SeasonWriterReconciler;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/_support.php';

/*
| E44 / الأداء: ميزانيات الاستعلامات **لا تتبع حجم البيانات** (لا N+1) — الدوري بكاتبين مقابل ستة، واللوحة بخمسة داعمين مقابل 105 (السقف العلني 100) —
| صفحة الإدارة تُحمّل بيانات التبويب الحالي فقط، والبحث الساخن يمرّ بفهارسه المسمّاة (خطة SQLite). المقارنات نسبية (متساوية) مع سقوف مطلقة سخيّة تكشف انفجار الاستعلامات.
*/

beforeEach(fn () => e44_seed());
afterEach(fn () => Carbon::setTestNow());

/** نصوص استعلامات SQL داخل الإغلاق. @return list<string> */
function e44_pf_sql(Closure $fn): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $fn();
    $log = DB::getQueryLog();
    DB::disableQueryLog();

    return array_column($log, 'query');
}

/** يمنح $count لاعبين جدد نقاط دعم عبر دفتر النقاط الرسمي (مصادر معرّفاتها فريدة): اللاعب i يأخذ (offset+i+1)*10 نقطة علنية. */
function e44_pf_grants(Season $season, int $count, int $offset = 0): void
{
    $users = User::factory()->count($count)->create(['profile_visibility' => User::VISIBILITY_PUBLIC]);

    foreach ($users as $i => $user) {
        e44_points()->grant((int) $season->id, (int) $user->id, SeasonSupportPointEntry::SOURCE_STORE_PURCHASE, 900000 + $offset + $i, ($offset + $i + 1) * 10, true);
    }
}

test('E44-PF-01: the writer boards, totals and reconciliation cost the same number of queries for 2 writers and for 6 (no N+1)', function () {
    $small = e44_league(2);
    $large = e44_league(6);
    e44_cast($small['writers'], [3, 2]);
    e44_cast($large['writers'], [6, 5, 4, 3, 2, 1]);

    $board = app(SeasonWriterLeaderboardService::class);
    $measure = function (Season $season) use ($board): array {
        Cache::flush();

        return [
            'public' => e44_queries(fn () => $board->publicBoard($season)),
            'admin' => e44_queries(fn () => $board->adminBoard($season)),
            'totals' => e44_queries(fn () => $board->totals($season)),
            'reconcile' => e44_queries(fn () => app(SeasonWriterReconciler::class)->inspect($season)),
        ];
    };

    $measure($small['season']); // تسخين (إعدادات/كاش)
    $a = $measure($small['season']);
    $b = $measure($large['season']);

    expect($b)->toBe($a)
        ->and($b['public'])->toBeLessThanOrEqual(10)->and($b['admin'])->toBeLessThanOrEqual(10)->and($b['totals'])->toBeLessThanOrEqual(6)->and($b['reconcile'])->toBeLessThanOrEqual(30);
});

test('E44-PF-02: one star allocation costs the same bounded number of queries whatever the roster size — first allocation and re-allocation alike', function () {
    $small = e44_league(2);
    $large = e44_league(6);

    $measure = function (array $league): array {
        $writer = $league['writers'][0];
        e44_voting()->allocate(e44_player_of($writer), $writer['entry']->fresh(), 1); // تسخين (صلاحيات/إعدادات)

        $voter = e44_player_of($writer);
        $entry = $writer['entry']->fresh();

        $first = e44_queries(fn () => e44_voting()->allocate($voter, $entry, 4));
        $again = e44_queries(fn () => e44_voting()->allocate($voter, $entry, 6));

        return ['first' => $first, 'again' => $again];
    };

    $a = $measure($small);
    $b = $measure($large);

    expect($b)->toBe($a)->and($b['first'])->toBeLessThanOrEqual(60)->and($b['again'])->toBeLessThanOrEqual(60);
});

test('E44-PF-03: the public supporters board holds the top 100 of 105 supporters in strict points order, at the same query cost as with 5', function () {
    $w = e44_supporters_world();
    $season = $w['season'];
    $service = app(SeasonSupporterLeaderboardService::class);

    e44_pf_grants($season, 5);
    Cache::flush();
    $few = e44_queries(fn () => $service->publicBoard($season));

    e44_pf_grants($season, 100, 5); // الإجمالي 105
    Cache::flush();
    $many = e44_queries(fn () => $service->publicBoard($season));
    $board = $service->publicBoard($season);
    $points = collect($board['rows'])->pluck('points')->all();

    expect($many)->toBe($few)->and($many)->toBeLessThanOrEqual(8)
        ->and(count($board['rows']))->toBe(100)->and($board['supporters_count'])->toBe(100)
        ->and($points[0])->toBe(1050)->and($points[99])->toBe(60)
        ->and($points)->toBe(collect($points)->sortDesc()->values()->all());

    // الشريحة العلنية نفسها: 100 صف بالضبط مهما زاد العدد.
    $this->get("/seasons/{$season->slug}/supporters/standings")->assertOk()->assertSee('data-rank="100"', false)->assertDontSee('data-rank="101"', false);
});

test('E44-PF-04: supporters totals and reconciliation cost the same number of queries for 5 and for 105 supporters', function () {
    $w = e44_supporters_world();
    $season = $w['season'];
    $board = app(SeasonSupporterLeaderboardService::class);
    $reconciler = app(SeasonSupporterReconciler::class);

    $measure = fn () => ['totals' => e44_queries(fn () => $board->totals($season)), 'reconcile' => e44_queries(fn () => $reconciler->inspect($season))];

    e44_pf_grants($season, 5);
    $measure(); // تسخين
    $few = $measure();

    e44_pf_grants($season, 100, 5);
    $many = $measure();

    expect($many)->toBe($few)->and($many['totals'])->toBeLessThanOrEqual(8)->and($many['reconcile'])->toBeLessThanOrEqual(30)
        ->and($reconciler->inspect($season))->toBe([]); // 105 منحة نظيفة: لا مخالفة
});

test('E44-PF-05: the public writers page costs the same queries for a 2-writer and a 6-writer league (guest, cold cache)', function () {
    $small = e44_league(2);
    $large = e44_league(6);

    $get = function (array $league) {
        Cache::flush();

        return e44_queries(fn () => $this->get("/seasons/{$league['season']->slug}/writers")->assertOk());
    };

    $get($small); // تسخين
    $a = $get($small);
    $b = $get($large);

    expect($b)->toBe($a)->and($b)->toBeLessThanOrEqual(40);
});

test('E44-PF-06: every admin tab costs the same queries for 2 and for 6 writers, and a tab loads only its own data', function () {
    $small = e44_league(2);
    $large = e44_league(6);
    $admin = $small['admin'];

    e44_ap_page($admin, $small['season']); // تسخين (صلاحيات)

    foreach (['writers', 'voting', 'standings', 'awards', 'packages', 'leaderboard', 'queue', 'reconcile'] as $tab) {
        e44_ap_page($admin, $small['season'], $tab); // تسخين الموسمين (كاشات الإعدادات) كي تُقارن حالتان دافئتان
        e44_ap_page($admin, $large['season'], $tab);

        $a = e44_queries(fn () => e44_ap_page($admin, $small['season'], $tab));
        $b = e44_queries(fn () => e44_ap_page($admin, $large['season'], $tab));

        expect($b)->toBe($a, "tab {$tab}")->and($b)->toBeLessThanOrEqual(150);
    }

    $supportTables = fn (array $sql) => collect($sql)->contains(fn ($q) => str_contains($q, 'season_support_point_entries') || str_contains($q, 'season_supporter_cards'));

    expect($supportTables(e44_pf_sql(fn () => e44_ap_page($admin, $large['season'], 'writers'))))->toBeFalse()
        ->and($supportTables(e44_pf_sql(fn () => e44_ap_page($admin, $large['season'], 'voting'))))->toBeFalse()
        ->and($supportTables(e44_pf_sql(fn () => e44_ap_page($admin, $large['season'], 'reconcile'))))->toBeTrue();
});

test('E44-PF-07: the hot lookups use their named unique and covering indexes (SQLite query plans)', function () {
    if (DB::getDriverName() !== 'sqlite') {
        $this->markTestSkipped('خطة الاستعلام مقيسة على SQLite؛ فهارس MySQL يغطيها E44MysqlSchemaTest.');
    }

    $plan = fn (string $sql, array $bindings = []) => collect(DB::select('EXPLAIN QUERY PLAN '.$sql, $bindings))->pluck('detail')->implode(' | ');

    $cases = [
        ['select * from season_writer_star_allocations where writer_entry_id = ? and voter_user_id = ?', [1, 1], 'season_writer_alloc_pair_unique'],
        ['select * from season_writer_star_allocations where season_id = ? and voter_user_id = ?', [1, 1], 'season_writer_alloc_voter_index'],
        ['select * from season_writer_vote_budgets where season_id = ? and user_id = ?', [1, 1], 'season_writer_budgets_voter_unique'],
        ['select * from season_writer_entries where season_id = ? and creator_profile_id = ?', [1, 1], 'season_writer_entries_season_creator_unique'],
        ['select * from season_support_point_entries where source_key = ?', ['store_purchase:1'], 'season_support_entries_source_unique'],
        ['select * from season_support_point_entries where reverses_entry_id = ?', [1], 'season_support_entries_reverses_unique'],
        ['select * from season_supporter_stats where season_id = ? and user_id = ?', [1, 1], 'season_supporter_stats_user_unique'],
        ['select * from season_supporter_cards where sponsorship_payment_id = ?', [1], 'season_supporter_cards_payment_unique'],
        ['select * from season_supporter_packages where season_id = ? and tier_key = ?', [1, 'gold'], 'season_supporter_packages_tier_unique'],
        ['select * from season_recognition_settings where season_id = ?', [1], 'season_recognition_settings_season_unique'],
    ];

    foreach ($cases as [$sql, $bindings, $index]) {
        expect($plan($sql, $bindings))->toContain($index);
    }
});
