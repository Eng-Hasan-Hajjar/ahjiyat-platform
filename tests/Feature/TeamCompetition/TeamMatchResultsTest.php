<?php

require_once __DIR__.'/TeamCompetitionTestHelpers.php';

use App\Models\CompetitiveRewardGrant;
use App\Models\GameSession;
use App\Models\TeamChallenge;
use App\Models\TeamChallengeParticipant;
use App\Models\TeamChallengeResult;
use App\Services\Teams\TeamScoreCalculator;
use Carbon\Carbon;

beforeEach(fn () => e17Freeze());
afterEach(fn () => Carbon::setTestNow());

function e20Result(TeamChallenge $c, $team): ?TeamChallengeResult
{
    return TeamChallengeResult::where('team_challenge_id', $c->id)->where('team_id', $team->id)->first();
}

test('30: the player score is computed by the server from the measured duration and correctness - the stored seat score equals the engine outcome and a forged score is ignored', function () {
    [$a, $b, $am, $bm] = e20Pair();
    $challenge = e20Accepted($a, $b, [$am[0]], [$bm[0]]);

    e20Play()->start($am[0], $challenge);
    e17Forward(12_000);
    $outcome = e20Play()->submit($am[0], $challenge->refresh(), ['answer' => E17_ANSWER, 'score' => 99999, 'is_correct' => false, 'duration_ms' => 1]);
    $seat = TeamChallengeParticipant::where('user_id', $am[0]->id)->first();

    $expected = app(\App\Services\Competitive\CompetitiveScoringService::class)->score(true, 12_000, 600_000);
    expect($seat->score)->toBe($outcome->score)->and($seat->score)->toBe($expected)->and($seat->duration_ms)->toBe(12_000)->and($seat->is_correct)->toBeTrue();

    $wrong = e20Submit($bm[0], $challenge, 'إجابة خاطئة', 5_000);
    expect($wrong->correct)->toBeFalse()->and(TeamChallengeParticipant::where('user_id', $bm[0]->id)->value('score'))->toBe(0);
});

test('31/C6/37: both teams are scored by the same formula - identical performances give a real draw with no winner and rank 1 for both', function () {
    [$a, $b, $am, $bm] = e20Pair();
    $challenge = e20Accepted($a, $b, [$am[0]], [$bm[0]]);
    e20Submit($am[0], $challenge, E17_ANSWER, 15_000);
    e20Submit($bm[0], $challenge, E17_ANSWER, 15_000);
    $challenge->refresh();

    expect($challenge->status)->toBe('completed')->and($challenge->is_draw)->toBeTrue()->and($challenge->winner_team_id)->toBeNull()->and($challenge->completed_at)->not->toBeNull()
        ->and(e20Result($challenge, $a)->score)->toBe(e20Result($challenge, $b)->score)->and(e20Result($challenge, $a)->rank)->toBe(1)->and(e20Result($challenge, $b)->rank)->toBe(1);
});

test('32/B6/C7: the same top-N applies to both teams whatever the roster sizes - a 5-player roster counts only its best 3, exactly like the 3-player one', function () {
    [$a, $b, $am, $bm] = e20Pair(5, 3);
    $challenge = e20Accepted($a, $b, $am, $bm);
    $times = [8_000, 9_000, 10_000, 20_000, 30_000];

    foreach ($am as $i => $u) {
        e20Submit($u, $challenge, E17_ANSWER, $times[$i]);
    }
    foreach ($bm as $i => $u) {
        e20Submit($u, $challenge, E17_ANSWER, $times[$i]);
    }

    $seatScores = fn ($team) => TeamChallengeParticipant::where('team_challenge_id', $challenge->id)->where('team_id', $team->id)->orderByDesc('score')->pluck('score')->all();
    expect(config('teams.ranking_top_n'))->toBe(3)->and(e20Result($challenge, $a)->eligible_results_count)->toBe(3)->and(e20Result($challenge, $b)->eligible_results_count)->toBe(3)
        ->and(e20Result($challenge, $a)->score)->toBe(array_sum(array_slice($seatScores($a), 0, 3)))->and(e20Result($challenge, $b)->score)->toBe(array_sum($seatScores($b)))
        ->and(e20Result($challenge, $a)->score)->toBe(e20Result($challenge, $b)->score);                      // نفس أفضل ثلاثة: لا أفضلية لحجم الروستر
});

test('33/34/C11: finalization is deterministic and idempotent - a second run changes nothing, two result rows exist, and the early run equals a late one', function () {
    [$a, $b, $am, $bm] = e20Pair();
    $challenge = e20Accepted($a, $b, [$am[0], $am[1]], [$bm[0], $bm[1]]);
    e20Submit($am[0], $challenge, E17_ANSWER, 9_000);
    e20Submit($am[1], $challenge, E17_ANSWER, 11_000);
    e20Submit($bm[0], $challenge, E17_ANSWER, 30_000);
    expect($challenge->refresh()->status)->toBe('accepted')->and(e20Finalizer()->finalize($challenge))->toBeFalse();           // لم يكتمل ولم تنتهِ المهلة

    e20Submit($bm[1], $challenge, E17_ANSWER, 31_000);                                                                           // اكتمل: اعتماد مبكر تلقائي
    $first = TeamChallengeResult::orderBy('id')->get(['team_id', 'score', 'eligible_results_count', 'total_duration_ms', 'rank'])->toArray();
    $state = fn () => TeamChallenge::find($challenge->id)->only(['status', 'winner_team_id', 'is_draw']) + ['completed_at' => TeamChallenge::find($challenge->id)->completed_at->toDateTimeString()];
    $snapshot = $state();

    expect(e20Finalizer()->finalize($challenge->refresh()))->toBeFalse()->and(e20Finalizer()->finalize($challenge->refresh()))->toBeFalse();
    expect(TeamChallengeResult::count())->toBe(2)->and(TeamChallengeResult::orderBy('id')->get(['team_id', 'score', 'eligible_results_count', 'total_duration_ms', 'rank'])->toArray())->toBe($first)
        ->and($state())->toBe($snapshot);
});

test('35/36: the faster roster wins and the other loses - winner and loser derived server-side with ranks 1 and 2', function () {
    [$a, $b, $am, $bm] = e20Pair();
    $challenge = e20Accepted($a, $b, [$am[0]], [$bm[0]]);
    e20Submit($bm[0], $challenge, E17_ANSWER, 6_000);
    e20Submit($am[0], $challenge, E17_ANSWER, 40_000);
    $challenge->refresh();

    expect($challenge->winner_team_id)->toBe($b->id)->and($challenge->is_draw)->toBeFalse()->and(e20Result($challenge, $b)->rank)->toBe(1)->and(e20Result($challenge, $a)->rank)->toBe(2)
        ->and(e20Result($challenge, $b)->score)->toBeGreaterThan(e20Result($challenge, $a)->score);
});

test('38/C14: equal scores break by lower total duration - never randomly - and the calculator breaks by more counted members, then draws', function () {
    [$a, $b, $am, $bm] = e20Pair();
    $challenge = e20Accepted($a, $b, [$am[0]], [$bm[0]]);
    e20Submit($am[0], $challenge, E17_ANSWER, 10_100);     // الدرجة الصحيحة نفسها (قسمة صحيحة) والمدة أطول
    e20Submit($bm[0], $challenge, E17_ANSWER, 10_000);
    $challenge->refresh();

    expect(e20Result($challenge, $a)->score)->toBe(e20Result($challenge, $b)->score)->and(e20Result($challenge, $a)->total_duration_ms)->toBeGreaterThan(e20Result($challenge, $b)->total_duration_ms)
        ->and($challenge->winner_team_id)->toBe($b->id)->and($challenge->is_draw)->toBeFalse();

    $calc = app(TeamScoreCalculator::class);
    $x = ['team_id' => 5, 'score' => 2000, 'counted' => 2, 'duration' => 4000, 'rank' => 0];
    $y = ['team_id' => 9, 'score' => 2000, 'counted' => 1, 'duration' => 4000, 'rank' => 0];
    expect($calc->headToHead($x, $y))->toBeLessThan(0)->and($calc->headToHead($y, $x))->toBeGreaterThan(0)                  // أعضاء أكثر يفوز
        ->and($calc->headToHead($x, $x + []))->toBe(0)                                                                         // تطابق تام: تعادل حقيقي
        ->and($calc->headToHead($x, ['team_id' => 1] + $x))->toBe(0);                                                          // معرّف الفريق لا يحسم مباراة ثنائية
});

test('C8/C20/B20: incomplete rosters get no invented scores - missing players are marked missed, open sessions closed, and the lifecycle finalizes after the deadline', function () {
    [$a, $b, $am, $bm] = e20Pair();
    $challenge = e20Accepted($a, $b, [$am[0], $am[1], $am[2]], [$bm[0], $bm[1]]);
    e20Submit($am[0], $challenge, E17_ANSWER, 9_000);
    e20Play()->start($am[1], $challenge);                                       // بدأ ولم يرسل
    e20Submit($bm[0], $challenge, E17_ANSWER, 50_000);

    expect(e20Finalizer()->finalize($challenge->refresh()))->toBeFalse();       // المهلة لم تنتهِ
    Carbon::setTestNow(now()->addHours(49));
    $this->artisan('teams:process-lifecycle')->assertExitCode(0);
    $this->artisan('teams:process-lifecycle')->assertExitCode(0);               // Idempotent

    $challenge->refresh();
    $statuses = TeamChallengeParticipant::where('team_challenge_id', $challenge->id)->pluck('status', 'user_id');

    expect($challenge->status)->toBe('completed')->and($challenge->winner_team_id)->toBe($a->id)->and(e20Result($challenge, $a)->eligible_results_count)->toBe(1)->and(e20Result($challenge, $b)->eligible_results_count)->toBe(1)
        ->and($statuses[$am[1]->id])->toBe('missed')->and($statuses[$am[2]->id])->toBe('missed')->and($statuses[$bm[1]->id])->toBe('missed')->and($statuses[$am[0]->id])->toBe('played')
        ->and(GameSession::where('user_id', $am[1]->id)->where('context_type', 'team_challenge')->value('status'))->toBe('expired')
        ->and(TeamChallengeResult::count())->toBe(2);
});

test('C21: a match where nobody plays ends as a zero-zero draw, and a team below the minimum valid results scores nothing', function () {
    [$a, $b, $am, $bm] = e20Pair();
    $none = e20Accepted($a, $b, [$am[0]], [$bm[0]]);
    Carbon::setTestNow(now()->addHours(49));
    $this->artisan('teams:process-lifecycle')->assertExitCode(0);
    $none->refresh();

    expect($none->status)->toBe('completed')->and($none->is_draw)->toBeTrue()->and($none->winner_team_id)->toBeNull()->and(e20Result($none, $a)->score)->toBe(0)->and(e20Result($none, $a)->eligible_results_count)->toBe(0);

    Carbon::setTestNow(now()->subHours(49));
    config(['teams.challenges.min_valid_results' => 2]);
    [$c, $d, $cm, $dm] = e20Pair();
    $c2 = e20Accepted($c, $d, [$cm[0], $cm[1]], [$dm[0], $dm[1]], e17Puzzle());
    e20Submit($cm[0], $c2, E17_ANSWER, 5_000);                                  // نتيجة صحيحة واحدة فقط
    e20Submit($dm[0], $c2, E17_ANSWER, 9_000);
    e20Submit($dm[1], $c2, E17_ANSWER, 9_500);
    e20Submit($cm[1], $c2, 'خاطئة', 9_900);
    $c2->refresh();

    expect(e20Result($c2, $c)->score)->toBe(0)->and(e20Result($c2, $c)->eligible_results_count)->toBe(0)->and(e20Result($c2, $d)->eligible_results_count)->toBe(2)->and($c2->winner_team_id)->toBe($d->id);
});

test('39/C16/C17/B11: the finished match is immutable - renaming a team, changing its owner, deactivating it or emptying its members changes no stored result', function () {
    [$a, $b, $am, $bm] = e20Pair();
    $challenge = e20Accepted($a, $b, [$am[0], $am[1]], [$bm[0]]);
    e20Submit($am[0], $challenge, E17_ANSWER, 9_000);
    e20Submit($am[1], $challenge, E17_ANSWER, 10_000);
    e20Submit($bm[0], $challenge, E17_ANSWER, 30_000);
    $before = [TeamChallengeResult::orderBy('id')->get(['team_id', 'score', 'rank', 'eligible_results_count'])->toArray(), TeamChallenge::find($challenge->id)->only(['winner_team_id', 'is_draw', 'status'])];

    e19Teams()->updateSettings($a->owner, $a, ['name' => 'اسم جديد كليًا']);
    e19Teams()->transferOwnership($a->owner, $a, $am[1]);
    e19Members()->leave($am[0]);
    e19Teams()->deactivate($am[1], $a->refresh());

    expect([TeamChallengeResult::orderBy('id')->get(['team_id', 'score', 'rank', 'eligible_results_count'])->toArray(), TeamChallenge::find($challenge->id)->only(['winner_team_id', 'is_draw', 'status'])])->toBe($before)
        ->and(TeamChallengeParticipant::where('team_challenge_id', $challenge->id)->where('team_id', $a->id)->count())->toBe(2);
    expect(fn () => TeamChallengeResult::create(['team_id' => 1]))->toThrow(\Illuminate\Database\Eloquent\MassAssignmentException::class);
});

test('40/B3: the match result never reads the current membership - a finalizer and a play service free of team_memberships, and a mover keeps representing the locked team', function () {
    foreach (['TeamChallengeFinalizer', 'TeamChallengePlayService'] as $class) {
        $code = e19Code(app_path("Services/Teams/{$class}.php"));
        expect($code)->not->toContain('TeamMembership')->and($code)->not->toContain('team_memberships')->and($code)->not->toContain('membershipOf')->and($code)->not->toContain('teamIdFor');
    }

    [$a, $b, $am, $bm] = e20Pair();
    $c = e19Team(null, ['join_policy' => 'open']);
    $challenge = e20Accepted($a, $b, [$am[0], $am[1]], [$bm[0]]);
    e19Members()->leave($am[1]);
    e19Members()->joinOpen($am[1], $c);
    e20Submit($am[1], $challenge, E17_ANSWER, 7_000);
    e20Submit($am[0], $challenge, E17_ANSWER, 8_000);
    e20Submit($bm[0], $challenge, E17_ANSWER, 90_000);

    expect(e20Result($challenge->refresh(), $a)->eligible_results_count)->toBe(2)->and(TeamChallengeResult::where('team_id', $c->id)->exists())->toBeFalse();
});

test('41/42/43/C19: finalizing a team match touches no E18 reward, XP or wallet - an event with rewards and a team match for the same players stay independent', function () {
    [$a, $b, $am, $bm] = e20Pair();
    $event = e18Event();
    e18Rule($event, ['reward_type' => 'xp', 'amount' => 50]);
    e18Run($event, [[$am[0], E17_ANSWER, 10_000], [$bm[0], E17_ANSWER, 20_000]]);
    $grants = CompetitiveRewardGrant::count();
    $xp = e18Xp($am[0]);
    $economy = e17Snapshot();

    $challenge = e20Accepted($a, $b, [$am[0]], [$bm[0]]);
    e20Submit($am[0], $challenge, E17_ANSWER, 9_000);
    e20Submit($bm[0], $challenge, E17_ANSWER, 19_000);

    expect($challenge->refresh()->status)->toBe('completed')->and(CompetitiveRewardGrant::count())->toBe($grants)->and(e18Xp($am[0]))->toBe($xp)->and(e17Snapshot())->toBe($economy)
        ->and(\App\Models\CompetitiveEventResult::where('user_id', $am[0]->id)->value('final_rank'))->toBe(1);          // ترتيب الحدث الفردي لم يتأثر
});

test('the calculator is the single formula: the E19 team ranking and the E20 finalizer both delegate to TeamScoreCalculator and neither re-implements top-N or ordering', function () {
    foreach (['TeamCompetitiveRankingService', 'TeamChallengeFinalizer'] as $class) {
        $code = e19Code(app_path("Services/Teams/{$class}.php"));
        expect($code)->toContain('TeamScoreCalculator')->and($code)->not->toContain('ranking_top_n')->and($code)->not->toContain('usort');
    }
    expect(e19Code(app_path('Services/Teams/TeamScoreCalculator.php')))->toContain('ranking_top_n');
});
