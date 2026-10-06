<?php

require_once __DIR__.'/TeamTestHelpers.php';

use App\Models\CompetitiveEvent;
use App\Models\CompetitiveEventParticipant;
use App\Models\CompetitiveEventTeamResult;
use App\Models\CompetitiveRewardGrant;
use App\Models\Team;
use App\Models\User;
use Carbon\Carbon;

beforeEach(fn () => e17Freeze());
afterEach(fn () => Carbon::setTestNow());

function e19Snap(CompetitiveEvent $event, User $user): ?int
{
    return CompetitiveEventParticipant::where('competitive_event_id', $event->id)->where('user_id', $user->id)->value('team_id_snapshot');
}

/** حدث معتمَد بلا لعب (للتحكم المباشر بالنتائج). */
function e19DoneEvent(array $attrs = []): CompetitiveEvent
{
    return e19Complete(e17Event($attrs + ['starts_at' => now()->subDays(10), 'ends_at' => now()->subDays(5)]));
}

test('46/47: registration stores the right team snapshot - the player team at that moment, null without a team or with an inactive team', function () {
    $team = e19Team();
    $member = e19Member($team);
    $solo = e16User();
    $inactive = e19Team();
    $inactiveMember = e19Member($inactive);
    e19Teams()->deactivate($inactive->owner, $inactive);
    $event = e18Event();
    e17Forward(2 * 3600_000);

    foreach ([$member, $solo, $inactiveMember] as $u) {
        e17Events()->register($u, $event);
    }

    expect(e19Snap($event, $member))->toBe($team->id)->and(e19Snap($event, $solo))->toBeNull()->and(e19Snap($event, $inactiveMember))->toBeNull();
});

test('48/49/D6/D7: changing or leaving the team after registration never moves the snapshot - the historical result stays with the original team', function () {
    $teamA = e19Team();
    $teamB = e19Team();
    $player = e19Member($teamA);
    $leaver = e19Member($teamA);
    $event = e18Event();
    e17Forward(2 * 3600_000);
    e17Events()->register($player, $event);
    e17Events()->register($leaver, $event);

    e19Members()->leave($player);
    e19Members()->joinOpen($player, $teamB);                 // انتقل لفريق آخر بعد التسجيل
    e19Members()->leave($leaver);                            // وآخر غادر بلا فريق

    expect(e19Snap($event, $player))->toBe($teamA->id)->and(e19Snap($event, $leaver))->toBe($teamA->id)->and(e19Role($teamB, $player))->toBe('member');

    // وبعد اللعب والاعتماد: النقاط لفريق A (لقطة التسجيل) لا لـB ولا لبلا فريق.
    e17Events()->start($player, $event);
    e17Events()->start($leaver, $event);
    e17Forward(10_000);
    e17Events()->submit($player, $event, ['answer' => E17_ANSWER]);
    e17Events()->submit($leaver, $event, ['answer' => E17_ANSWER]);
    e17Forward(5 * 3600_000);
    app(\App\Services\Competitive\CompetitiveEventFinalizer::class)->finalize($event->refresh());

    $rows = CompetitiveEventTeamResult::where('competitive_event_id', $event->id)->get();
    expect($rows)->toHaveCount(1)->and($rows->first()->team_id)->toBe($teamA->id)->and($rows->first()->counted_members)->toBe(2);
});

test('50/D5: a participant registered before E19 keeps a null snapshot - no guessed association, no backfill - and enters no team', function () {
    $team = e19Team();
    $legacy = e19Member($team);                              // اليوم عضو بفريق، لكنه سجّل قبل E19
    $event = e19DoneEvent();
    CompetitiveEventParticipant::create(['competitive_event_id' => $event->id, 'user_id' => $legacy->id, 'status' => 'completed', 'registered_at' => now()->subDays(11)]);
    $session = \App\Models\GameSession::create(['user_id' => $legacy->id, 'puzzle_id' => $event->puzzle_id, 'context_type' => 'competitive_event', 'context_id' => $event->id, 'status' => 'completed', 'started_at' => now(), 'completed_at' => now(), 'server_state' => ['competitive' => true]]);
    \App\Models\CompetitiveEventResult::create(['competitive_event_id' => $event->id, 'user_id' => $legacy->id, 'game_session_id' => $session->id, 'is_correct' => true, 'score' => 1900, 'duration_ms' => 9000, 'completed_at' => now()->subDays(6), 'final_rank' => 1]);

    $stored = e19Ranking()->finalize($event);

    expect(e19Snap($event, $legacy))->toBeNull()->and($stored)->toBe(0)->and(CompetitiveEventTeamResult::count())->toBe(0)
        ->and($event->refresh()->team_rankings_finalized_at)->not->toBeNull()->and(e19Ranking()->hasTeamData($event))->toBeFalse();
});

test('51/D3: the team ranking reads the registration snapshot, never the current membership - a player who moved on still counts for the old team', function () {
    $old = e19Team(null, ['name' => 'الفريق القديم']);
    $new = e19Team(null, ['name' => 'الفريق الجديد']);
    $event = e19DoneEvent();
    $mover = e16User();
    e19Result($event, $mover, $old, 1900, 9000);            // سُجِّل بالقديم
    e19Members()->addMember($new, $mover);                   // ثم صار بالجديد

    $rows = e19Ranking()->compute($event);

    expect($rows)->toHaveCount(1)->and($rows[0]['team_id'])->toBe($old->id);
    $code = preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', file_get_contents(app_path('Services/Teams/TeamCompetitiveRankingService.php')));
    expect($code)->not->toContain('TeamMembership')->and($code)->not->toContain('membershipOf')->and($code)->not->toContain('teamIdFor')->and($code)->toContain('team_id_snapshot');
});

test('52/53: only correct results of finalized events enter - wrong answers never count, an unfinished event stores nothing, and the live view is labelled provisional', function () {
    $t1 = e19Team();
    $t2 = e19Team();
    $live = e17Event(['starts_at' => now()->subHour(), 'ends_at' => now()->addHours(3)]);
    $u1 = e16User();
    $u2 = e16User();
    e19Result($live, $u1, $t1, 1800, 12000);
    e19Result($live, $u2, $t2, 0, 5000, correct: false);       // خاطئة: لا تدخل

    expect(e19Ranking()->finalize($live))->toBe(0)->and(CompetitiveEventTeamResult::count())->toBe(0)->and($live->refresh()->team_rankings_finalized_at)->toBeNull();
    $provisional = e19Ranking()->standings($live);
    expect($provisional['final'])->toBeFalse()->and($provisional['rows'])->toHaveCount(1)->and($provisional['rows']->first()->team->id)->toBe($t1->id);

    e19Complete($live);
    expect(e19Ranking()->finalize($live->refresh()))->toBe(1);
    $final = e19Ranking()->standings($live->refresh());
    expect($final['final'])->toBeTrue()->and($final['rows'])->toHaveCount(1);
});

test('54/D11: the ranking is deterministic and the stored result equals the computed one, and finalizing twice changes nothing', function () {
    $event = e19DoneEvent();
    $teams = [e19Team(), e19Team(), e19Team()];
    foreach ($teams as $i => $team) {
        foreach (range(1, 2) as $k) {
            e19Result($event, e16User(), $team, 1800 - $i * 100 - $k * 10, 10000 + $i * 500 + $k);
        }
    }
    $a = e19Ranking()->compute($event);
    $b = e19Ranking()->compute($event);

    expect($a)->toBe($b)->and(array_column($a, 'team_id'))->toBe([$teams[0]->id, $teams[1]->id, $teams[2]->id]);

    $first = e19Ranking()->finalize($event);
    $second = e19Ranking()->finalize($event->refresh());
    $stored = CompetitiveEventTeamResult::orderBy('rank')->get(['team_id', 'score', 'counted_members', 'total_duration_ms', 'rank'])->map(fn ($r) => [$r->team_id, $r->score, $r->counted_members, $r->total_duration_ms, $r->rank])->all();

    expect($first)->toBe(3)->and($second)->toBe(0)->and($stored)->toBe(array_map(fn ($r) => [$r['team_id'], $r['score'], $r['counted'], $r['duration'], $r['rank']], $a))
        ->and(CompetitiveEventTeamResult::count())->toBe(3);
});

test('55/D12: ties break in a fixed order - higher score, then lower total duration, then more counted members, then the smaller team id', function () {
    $event = e19DoneEvent();
    [$slow, $fast, $threeX, $twoX, $idLow, $idHigh] = [e19Team(), e19Team(), e19Team(), e19Team(), e19Team(), e19Team()];

    // نفس الدرجة 2000: أقل مدة تفوز.
    e19Result($event, e16User(), $slow, 2000, 9000);
    e19Result($event, e16User(), $fast, 2000, 4000);
    // نفس الدرجة 1500 ونفس المدة 6000: الأكثر أعضاء محتسَبين يفوز (3 × 500، 6 مدة مقسَّمة) ضد (2 × 750).
    foreach ([2000, 2000, 2000] as $d) {
        e19Result($event, e16User(), $threeX, 500, $d);
    }
    foreach ([3000, 3000] as $d) {
        e19Result($event, e16User(), $twoX, 750, $d);
    }
    // تطابق تام: معرّف الفريق الأصغر يفوز (حتى لو أُنشئ لاحقًا بالبيانات).
    e19Result($event, e16User(), $idHigh, 100, 100);
    e19Result($event, e16User(), $idLow, 100, 100);

    $order = array_column(e19Ranking()->compute($event), 'team_id');

    expect($order)->toBe([$fast->id, $slow->id, $threeX->id, $twoX->id, min($idLow->id, $idHigh->id), max($idLow->id, $idHigh->id)]);
    expect(array_column(e19Ranking()->compute($event), 'rank'))->toBe([1, 2, 3, 4, 5, 6]);
});

test('56/D9: team size gives no advantage - only the best 3 correct results count, a 10-member team scores exactly its top 3', function () {
    $event = e19DoneEvent();
    $big = e19Team();
    $small = e19Team();
    $weakBig = e19Team();

    foreach ([1900, 1850, 1800, 1700, 1700, 1650, 1600, 1500, 1400, 1300] as $i => $score) {
        e19Result($event, e16User(), $big, $score, 8000 + $i * 100);
    }
    foreach ([1900, 1850, 1800] as $i => $score) {
        e19Result($event, e16User(), $small, $score, 8000 + $i * 100);
    }
    foreach (range(1, 10) as $i) {
        e19Result($event, e16User(), $weakBig, 1000, 20000);          // عشرة أعضاء ضعفاء
    }

    $rows = collect(e19Ranking()->compute($event))->keyBy('team_id');

    expect($rows[$big->id]['score'])->toBe(5550)->and($rows[$big->id]['counted'])->toBe(3)->and($rows[$small->id]['score'])->toBe(5550)->and($rows[$small->id]['counted'])->toBe(3)
        ->and($rows[$weakBig->id]['score'])->toBe(3000)->and($rows[$weakBig->id]['counted'])->toBe(3)                     // 10 أعضاء لا يعطون 10000
        ->and($rows[$big->id]['rank'])->toBe(1)->and($rows[$small->id]['rank'])->toBe(2)->and($rows[$weakBig->id]['rank'])->toBe(3);   // تعادل تام: المعرّف الأصغر
    expect(config('teams.ranking_top_n'))->toBe(3);
});

test('D4/A10: a team deactivated before finalization does not enter the ranking, and a later deactivation never rewrites a stored ranking', function () {
    $active = e19Team();
    $dead = e19Team();
    $later = e19Team();
    $event = e19DoneEvent();
    e19Result($event, e16User(), $active, 1800, 9000);
    e19Result($event, e16User(), $dead, 1900, 8000);
    e19Result($event, e16User(), $later, 1700, 9500);
    e19Teams()->deactivate($dead->owner, $dead);

    e19Ranking()->finalize($event);
    expect(CompetitiveEventTeamResult::pluck('team_id')->sort()->values()->all())->toBe(collect([$active->id, $later->id])->sort()->values()->all());

    e19Teams()->deactivate($later->owner, $later);          // لاحقًا: الترتيب المخزَّن ثابت
    expect(CompetitiveEventTeamResult::where('team_id', $later->id)->count())->toBe(1)->and(e19Ranking()->standings($event->refresh())['rows'])->toHaveCount(2);
});

test('57/58/D1/D17/D18: the team layer never alters player scores, player ranks or the individual E18 rewards - and creates no economy', function () {
    $team = e19Team();
    $mate = e19Member($team);
    $solo = e16User();
    $event = e18Event();
    e18Rule($event, ['min_rank' => 1, 'max_rank' => 1, 'reward_type' => 'xp', 'amount' => 50]);
    e18Rule($event, ['kind' => 'participation', 'min_rank' => null, 'max_rank' => null, 'reward_type' => 'xp', 'amount' => 5]);
    $teamPlayer = $team->owner;

    e18Run($event, [[$teamPlayer, E17_ANSWER, 10_000], [$mate, E17_ANSWER, 10_000], [$solo, E17_ANSWER, 10_000]]);

    $scores = \App\Models\CompetitiveEventResult::orderBy('final_rank')->pluck('score')->unique();
    expect($scores)->toHaveCount(1)                                                   // الفريق لا يضرب نقطة لاعب: نفس المدة = نفس الدرجة
        ->and(\App\Models\CompetitiveEventResult::orderBy('final_rank')->pluck('final_rank')->all())->toBe([1, 2, 3])
        ->and(CompetitiveRewardGrant::count())->toBe(3)                                // جوائز الأفراد كما هي (مركز + مشاركة)
        ->and(e18Xp(\App\Models\CompetitiveEventResult::where('final_rank', 1)->first()->user))->toBe(50)
        ->and(CompetitiveEventTeamResult::count())->toBe(1);

    $before = [e17Snapshot(), CompetitiveRewardGrant::count()];
    e19Ranking()->finalize($event->refresh());                                         // إعادة: لا أثر
    expect([e17Snapshot(), CompetitiveRewardGrant::count()])->toBe($before)
        ->and(\Illuminate\Support\Facades\Schema::getColumnListing('competitive_event_team_results'))->not->toContain('reward', 'xp', 'currency_id', 'amount');
});

test('the hourly safety net finalizes completed events missing their team ranking - idempotently, marking even events with no teams', function () {
    $team = e19Team();
    $withTeams = e19DoneEvent(['title' => 'with teams']);
    e19Result($withTeams, e16User(), $team, 1700, 9000);
    $noTeams = e19DoneEvent(['title' => 'no teams']);

    $this->artisan('teams:process-lifecycle')->assertExitCode(0);
    $this->artisan('teams:process-lifecycle')->assertExitCode(0);

    expect($withTeams->refresh()->team_rankings_finalized_at)->not->toBeNull()->and($noTeams->refresh()->team_rankings_finalized_at)->not->toBeNull()
        ->and(CompetitiveEventTeamResult::count())->toBe(1);
});

test('D13: the Teams tab appears on the event page only when team data exists - provisional before the end, final after finalization', function () {
    $event = e17Event(['starts_at' => now()->subHour(), 'ends_at' => now()->addHours(3)]);
    $this->get(route('competitions.show', $event))->assertOk()->assertDontSee('aria-label="عرض الترتيب"', false);

    $team = e19Team(null, ['name' => 'فريق اللوحة']);
    e19Result($event, e16User(), $team, 1800, 9000);
    $this->get(route('competitions.show', $event))->assertOk()->assertSee('aria-label="عرض الترتيب"', false);
    $this->get(route('competitions.show', [$event, 'tab' => 'teams']))->assertOk()->assertSee('ترتيب الفرق المؤقت (غير نهائي)')->assertSee('فريق اللوحة');

    DB::table('competitive_events')->where('id', $event->id)->update(['starts_at' => now()->subDays(3), 'ends_at' => now()->subDay()]);
    e19Complete($event->refresh());
    e19Ranking()->finalize($event->refresh());
    $this->get(route('competitions.show', [$event, 'tab' => 'teams']))->assertOk()->assertSee('النتائج النهائية للفرق')->assertDontSee('غير نهائي');
});

test('D14/D15/D16: team stats, the global team table and the hall of fame team winner all derive from the stored final rankings', function () {
    [$gold, $silver] = [e19Team(null, ['name' => 'فريق الذهب']), e19Team(null, ['name' => 'فريق الفضة'])];
    foreach (['حدث 1', 'حدث 2', 'حدث 3'] as $i => $title) {
        $event = e19DoneEvent(['title' => $title, 'ends_at' => now()->subDays(5 - $i)]);
        e19Result($event, e16User(), $gold, $i < 2 ? 1900 : 1500, 9000);
        e19Result($event, e16User(), $silver, $i < 2 ? 1700 : 1800, 9000);
        e19Ranking()->finalize($event);
    }

    expect(e19Ranking()->stats($gold))->toBe(['events' => 3, 'wins' => 2, 'top3' => 3, 'best_rank' => 1, 'avg_rank' => 1.3])
        ->and(e19Ranking()->stats($silver))->toBe(['events' => 3, 'wins' => 1, 'top3' => 3, 'best_rank' => 1, 'avg_rank' => 1.7]);

    $table = e19Ranking()->medalTable();
    expect($table->getCollection()->map(fn ($r) => $r->team->name)->all())->toBe(['فريق الذهب', 'فريق الفضة']);
    $this->get(route('teams.leaderboard'))->assertOk()->assertSeeInOrder(['فريق الذهب', 'فريق الفضة']);
    $this->get(route('teams.show', $gold))->assertOk()->assertSee('أداء الفريق بالمنافسات');
    $this->get(route('competitions.hall-of-fame'))->assertOk()->assertSee('الفريق الفائز')->assertSee('فريق الذهب');
});
