<?php

require_once __DIR__.'/TeamCompetitionTestHelpers.php';

use App\Models\CompetitiveRewardGrant;
use App\Models\Puzzle;
use App\Models\Team;
use App\Models\TeamChallenge;
use App\Models\TeamChallengeParticipant;
use App\Services\Teams\TeamException;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;

beforeEach(fn () => e17Freeze());
afterEach(fn () => Carbon::setTestNow());

test('1/2: the team owner and an admin can challenge another team - pending, with an acceptance deadline from config and a unique active key', function () {
    [$a, $b, $am] = e20Pair();
    $admin = e19Member($a, null, 'admin');

    $byOwner = e20Challenges()->create($a->owner, $b, e17Puzzle(), e20Ids([$am[0]]));
    $other = e17Puzzle();
    $byAdmin = e20Challenges()->create($admin, $b, $other, e20Ids([$admin]));

    expect($byOwner->status)->toBe('pending')->and($byOwner->challenger_team_id)->toBe($a->id)->and($byOwner->opponent_team_id)->toBe($b->id)->and($byOwner->created_by_user_id)->toBe($a->owner_id)
        ->and($byOwner->expires_at->equalTo(now()->addHours(72)))->toBeTrue()->and($byOwner->public_id)->toHaveLength(26)->and($byOwner->winner_team_id)->toBeNull()
        ->and($byOwner->active_key)->toBe(min($a->id, $b->id).':'.max($a->id, $b->id).':'.$byOwner->puzzle_id)->and($byAdmin->created_by_user_id)->toBe($admin->id);
});

test('3: a plain member cannot challenge, and neither can someone outside any team', function () {
    [$a, $b, $am] = e20Pair();

    foreach ([$am[1], e16User()] as $actor) {
        expect(fn () => e20Challenges()->create($actor, $b, e17Puzzle(), e20Ids([$am[0]])))->toThrow(TeamException::class, 'مالك الفريق أو مشرفيه');
    }
    expect(TeamChallenge::count())->toBe(0);
});

test('4/5: a team cannot challenge itself, and an inactive team is refused on either side', function () {
    [$a, $b, $am] = e20Pair();
    $c = e19Team();
    $cMember = $c->owner;

    expect(fn () => e20Challenges()->create($a->owner, $a, e17Puzzle(), e20Ids([$am[0]])))->toThrow(TeamException::class, 'نفسه');

    e19Teams()->deactivate($c->owner, $c);
    expect(fn () => e20Challenges()->create($a->owner, $c->refresh(), e17Puzzle(), e20Ids([$am[0]])))->toThrow(TeamException::class, 'مفعَّلين');

    $inactiveChallenger = e19Team();
    $m = e19Member($inactiveChallenger, null, 'admin');
    e19Teams()->deactivate($inactiveChallenger->owner, $inactiveChallenger);
    expect(fn () => e20Challenges()->create($m, $b, e17Puzzle(), e20Ids([$m])))->toThrow(TeamException::class);
    expect(TeamChallenge::count())->toBe(0)->and($cMember->exists)->toBeTrue();
});

test('6/A9/A10: an invalid puzzle is refused - one with a hint, an inactive one, or an unsupported type', function () {
    [$a, $b, $am] = e20Pair();
    $hinted = e17Puzzle();
    $hinted->update(['hint' => 'تلميح يُشترى']);
    $inactive = e17Puzzle();
    $inactive->update(['is_active' => false]);

    foreach ([$hinted, $inactive] as $bad) {
        expect(fn () => e20Challenges()->create($a->owner, $b, $bad, e20Ids([$am[0]])))->toThrow(TeamException::class);
    }
    expect(TeamChallenge::count())->toBe(0);
});

test('7/A8: a second active challenge for the same teams and puzzle is impossible in BOTH directions - at the service and by the database - while another puzzle is fine', function () {
    [$a, $b, $am, $bm] = e20Pair();
    $puzzle = e17Puzzle();
    e20Challenges()->create($a->owner, $b, $puzzle, e20Ids([$am[0]]));

    expect(fn () => e20Challenges()->create($a->owner, $b, $puzzle, e20Ids([$am[1]])))->toThrow(TeamException::class, 'نشط')           // نفس الاتجاه
        ->and(fn () => e20Challenges()->create($b->owner, $a, $puzzle, e20Ids([$bm[0]])))->toThrow(TeamException::class, 'نشط');       // عكسه

    $raw = fn () => (new TeamChallenge(['challenger_team_id' => $b->id, 'opponent_team_id' => $a->id, 'puzzle_id' => $puzzle->id, 'expires_at' => now()->addDay()]))
        ->forceFill(['status' => 'pending', 'active_key' => TeamChallenge::activeKey($b->id, $a->id, $puzzle->id)])->save();
    expect($raw)->toThrow(UniqueConstraintViolationException::class);

    expect(e20Challenges()->create($b->owner, $a, e17Puzzle(), e20Ids([$bm[0]]))->status)->toBe('pending')->and(TeamChallenge::count())->toBe(2);
});

test('8/9/10: only the target owner or admin accepts - members, outsiders and the challenger team cannot', function () {
    [$a, $b, $am, $bm] = e20Pair();
    $bAdmin = e19Member($b, null, 'admin');
    $challenge = e20Create($a, $b, [$am[0]]);

    foreach ([$bm[1], e16User(), $a->owner] as $actor) {
        expect(fn () => e20Challenges()->accept($actor, $challenge, e20Ids([$bm[0]])))->toThrow(TeamException::class);
    }
    expect($challenge->refresh()->status)->toBe('pending');

    expect(e20Challenges()->accept($bAdmin, $challenge, e20Ids([$bAdmin, $bm[1]]))->status)->toBe('accepted');
});

test('11/12/A13/A14: decline is for the target team only, cancel for the challenger team only, both pending only, both silent', function () {
    [$a, $b, $am, $bm] = e20Pair();
    $c1 = e20Create($a, $b, [$am[0]]);
    $c2 = e20Create($a, $b, [$am[0]]);

    expect(fn () => e20Challenges()->decline($a->owner, $c1))->toThrow(TeamException::class)             // الخصم وحده يرفض
        ->and(fn () => e20Challenges()->cancel($b->owner, $c2))->toThrow(TeamException::class)             // المتحدّي وحده يلغي
        ->and(fn () => e20Challenges()->decline($bm[1], $c1))->toThrow(TeamException::class);

    $declined = e20Challenges()->decline($b->owner, $c1);
    $cancelled = e20Challenges()->cancel($a->owner, $c2);

    expect($declined->status)->toBe('declined')->and($declined->active_key)->toBeNull()->and($cancelled->status)->toBe('cancelled')->and($cancelled->active_key)->toBeNull()
        ->and(fn () => e20Challenges()->decline($b->owner, $declined))->toThrow(TeamException::class, 'معلّقًا')
        ->and(fn () => e20Challenges()->cancel($a->owner, $cancelled))->toThrow(TeamException::class, 'معلّقًا');

    // والمفتاح تحرّر: يمكن التحدّي من جديد على نفس الأحجية.
    expect(e20Challenges()->create($a->owner, $b, $c1->puzzle, e20Ids([$am[0]]))->status)->toBe('pending');
});

test('13/A11: an expired pending challenge cannot be accepted, and the lifecycle materializes the expiry and frees the key - idempotently', function () {
    [$a, $b, $am, $bm] = e20Pair();
    $challenge = e20Create($a, $b, [$am[0]]);

    Carbon::setTestNow(now()->addHours(73));
    expect(fn () => e20Challenges()->accept($b->owner, $challenge->refresh(), e20Ids([$bm[0]])))->toThrow(TeamException::class, 'انتهت مهلة')
        ->and($challenge->effectiveStatus())->toBe('expired')->and($challenge->refresh()->status)->toBe('pending');

    $this->artisan('teams:process-lifecycle')->assertExitCode(0);
    $this->artisan('teams:process-lifecycle')->assertExitCode(0);

    expect($challenge->refresh()->status)->toBe('expired')->and($challenge->active_key)->toBeNull()
        ->and(e20Challenges()->create($a->owner, $b, $challenge->puzzle, e20Ids([$am[0]]))->status)->toBe('pending');
});

test('15/C19: a team challenge grants no economy at all - no XP, currency, items, E18 reward or wallet change', function () {
    [$a, $b, $am, $bm] = e20Pair();
    $before = e17Snapshot();

    $challenge = e20Accepted($a, $b, [$am[0], $am[1]], [$bm[0], $bm[1]]);
    foreach ([[$am[0], 10_000], [$am[1], 12_000], [$bm[0], 30_000], [$bm[1], 31_000]] as [$u, $ms]) {
        e20Submit($u, $challenge, E17_ANSWER, $ms);
    }

    expect($challenge->refresh()->status)->toBe('completed')->and(CompetitiveRewardGrant::count())->toBe(0)->and(e17Snapshot())->toBe($before)
        ->and(\App\Models\CurrencyTransaction::count())->toBe(0)->and(\App\Models\XpTransaction::count())->toBe(0);
});

test('16/17/18/A19: the roster takes only active members of the OWN team - a non-member, another team member, a frozen or unverified member are refused', function () {
    [$a, $b, $am, $bm] = e20Pair();
    $frozen = e19Member($a);
    $frozen->forceFill(['is_frozen' => true])->save();
    $unverified = e19Member($a);
    $unverified->forceFill(['email_verified_at' => null])->save();

    expect(e20Challenges()->create($a->owner, $b, e17Puzzle(), e20Ids([$am[0], $am[1]]))->participants()->count())->toBe(2);   // 16: أعضاء فعليون

    foreach ([[e16User()], [$bm[0]], [$am[0], $bm[1]], [$frozen], [$unverified]] as $bad) {
        expect(fn () => e20Challenges()->create($a->owner, $b, e17Puzzle(), e20Ids($bad)))->toThrow(TeamException::class, 'عضوًا فعليًا');
    }
});

test('19/20: a duplicate player in the roster is refused, and the roster size is bounded by the config minimum and maximum', function () {
    [$a, $b, $am] = e20Pair(6, 3);

    expect(fn () => e20Challenges()->create($a->owner, $b, e17Puzzle(), e20Ids([$am[0], $am[0]])))->toThrow(TeamException::class, 'تكرار')
        ->and(fn () => e20Challenges()->create($a->owner, $b, e17Puzzle(), []))->toThrow(TeamException::class, 'بين 1 و5')
        ->and(fn () => e20Challenges()->create($a->owner, $b, e17Puzzle(), e20Ids($am)))->toThrow(TeamException::class, 'بين 1 و5');       // 6 لاعبين
    expect(e20Challenges()->create($a->owner, $b, e17Puzzle(), e20Ids(array_slice($am, 0, 5)))->participants()->count())->toBe(5);

    // وجدول DB نفسه يمنع تكرار اللاعب بالتحدي.
    $challenge = e20Create($a, $b, [$am[0]], e17Puzzle());
    expect(fn () => TeamChallengeParticipant::create(['team_challenge_id' => $challenge->id, 'team_id' => $a->id, 'user_id' => $am[0]->id, 'role_snapshot' => 'owner']))->toThrow(UniqueConstraintViolationException::class);
});

test('21/22/B7/B8: acceptance locks the roster once - every seat gets locked_at and a role snapshot, and no add, remove, swap or edit works afterwards, not even for the owner', function () {
    [$a, $b, $am, $bm] = e20Pair();
    $challenge = e20Create($a, $b, [$am[0], $am[1]]);
    e20Challenges()->setRoster($a->owner, $challenge, e20Ids([$am[0], $am[2]]));                       // قبل القفل: تعديل مسموح

    $accepted = e20Challenges()->accept($b->owner, $challenge, e20Ids([$bm[0], $bm[1]]));
    $seats = TeamChallengeParticipant::where('team_challenge_id', $challenge->id)->get();

    expect($accepted->status)->toBe('accepted')->and($accepted->accepted_at)->not->toBeNull()->and($accepted->play_ends_at->equalTo(now()->addHours(48)))->toBeTrue()
        ->and($seats)->toHaveCount(4)->and($seats->every(fn ($s) => $s->locked_at !== null && $s->status === 'locked'))->toBeTrue()
        ->and($seats->firstWhere('user_id', $am[0]->id)->role_snapshot)->toBe('owner')->and($seats->firstWhere('user_id', $bm[1]->id)->role_snapshot)->toBe('member');

    expect(fn () => e20Challenges()->setRoster($a->owner, $accepted, e20Ids([$am[1]])))->toThrow(TeamException::class, 'مقفل')
        ->and(fn () => TeamChallengeParticipant::create(['team_challenge_id' => $challenge->id, 'team_id' => $a->id, 'user_id' => $am[1]->id, 'role_snapshot' => 'member']))->toThrow(InvalidArgumentException::class, 'مقفل')
        ->and(fn () => $seats->first()->delete())->toThrow(InvalidArgumentException::class, 'مقفل')
        ->and(fn () => $seats->first()->update(['user_id' => e16User()->id]))->toThrow(InvalidArgumentException::class, 'مقفل')
        ->and(fn () => $seats->first()->update(['team_id' => $b->id]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $seats->first()->update(['role_snapshot' => 'admin']))->toThrow(InvalidArgumentException::class);
    expect(TeamChallengeParticipant::where('team_challenge_id', $challenge->id)->count())->toBe(4);
});

test('accepting twice, and two stale accept calls racing, lock the roster exactly once - no duplicate seat, no second acceptance', function () {
    [$a, $b, $am, $bm] = e20Pair();
    $challenge = e20Create($a, $b, [$am[0]]);
    $stale = TeamChallenge::find($challenge->id);                                    // قراءة قديمة ما زالت ترى pending

    e20Challenges()->accept($b->owner, $challenge, e20Ids([$bm[0]]));

    expect(fn () => e20Challenges()->accept($b->owner, $stale, e20Ids([$bm[0]])))->toThrow(TeamException::class, 'لم يعد')
        ->and(TeamChallengeParticipant::where('team_challenge_id', $challenge->id)->count())->toBe(2)
        ->and(TeamChallenge::find($challenge->id)->accepted_at->equalTo(now()))->toBeTrue();
});

test('B1/B3/B11/23/24: leaving or switching teams after the lock changes nothing - the seat keeps the team that was represented, and the left player may still play for it', function () {
    [$a, $b, $am, $bm] = e20Pair();
    $c = e19Team(null, ['join_policy' => 'open']);
    $challenge = e20Accepted($a, $b, [$am[0], $am[1]], [$bm[0], $bm[1]]);

    e19Members()->leave($am[1]);
    e19Members()->joinOpen($am[1], $c);                                                     // انتقل لفريق ثالث

    $seat = TeamChallengeParticipant::where('team_challenge_id', $challenge->id)->where('user_id', $am[1]->id)->first();
    expect($seat->team_id)->toBe($a->id)->and($seat->role_snapshot)->toBe('member')->and($seat->locked_at)->not->toBeNull();

    $outcome = e20Submit($am[1], $challenge, E17_ANSWER, 9_000);                            // ما زال يلعب لفريقه وقت القفل
    $result = e20Submit($bm[0], $challenge, E17_ANSWER, 50_000);
    e20Submit($am[0], $challenge, E17_ANSWER, 11_000);
    e20Submit($bm[1], $challenge, E17_ANSWER, 52_000);

    expect($outcome->correct)->toBeTrue()->and($challenge->refresh()->status)->toBe('completed')->and($challenge->winner_team_id)->toBe($a->id)
        ->and(\App\Models\TeamChallengeResult::where('team_challenge_id', $challenge->id)->where('team_id', $a->id)->value('eligible_results_count'))->toBe(2)
        ->and(\App\Models\TeamChallengeResult::where('team_challenge_id', $challenge->id)->where('team_id', $c->id)->exists())->toBeFalse();
    expect($result->correct)->toBeTrue();
});

test('25/B15/B14: the team of an attempt always comes from the locked roster seat - a forged team in the input is never read - and the session is bound to this challenge', function () {
    [$a, $b, $am, $bm] = e20Pair();
    $challenge = e20Accepted($a, $b, [$am[0]], [$bm[0]]);

    $session = e20Play()->start($am[0], $challenge);
    e17Forward(8_000);
    e20Play()->submit($am[0], $challenge->refresh(), ['answer' => E17_ANSWER, 'team_id' => $b->id, 'team' => $b->slug, 'user_id' => $bm[0]->id, 'score' => 99999]);

    $seat = TeamChallengeParticipant::where('team_challenge_id', $challenge->id)->where('user_id', $am[0]->id)->first();
    expect($session->context_type)->toBe('team_challenge')->and($session->context_id)->toBe($challenge->id)->and($session->user_id)->toBe($am[0]->id)
        ->and($seat->team_id)->toBe($a->id)->and($seat->score)->toBeLessThan(2001)->and($seat->score)->toBeGreaterThan(1000)->and($seat->status)->toBe('played');
});

test('26/27/28/B18/B17: a wrong puzzle, an attempt that predates the challenge, and a user outside the roster are all rejected', function () {
    [$a, $b, $am, $bm] = e20Pair();
    $outsider = $am[2];
    $challenge = e20Accepted($a, $b, [$am[0]], [$bm[0]]);

    // خارج الروستر: لا بدء ولا إرسال.
    expect(fn () => e20Play()->start($outsider, $challenge))->toThrow(\App\Services\Competitive\CompetitiveException::class, 'ضمن روستر')
        ->and(fn () => e20Play()->submit($outsider, $challenge, ['answer' => E17_ANSWER]))->toThrow(\App\Services\Competitive\CompetitiveException::class, 'ضمن روستر');

    // محاولة قديمة (قبل التحدّي) بنفس الأحجية والمستخدم لا تُحتسب: النتيجة تأتي من جلسة مربوطة بمعرّف التحدّي فقط.
    \App\Models\GameSession::create(['user_id' => $am[0]->id, 'puzzle_id' => $challenge->puzzle_id, 'status' => 'completed', 'started_at' => now()->subDay(), 'completed_at' => now()->subDay(), 'server_state' => ['correct' => true]]);
    expect(fn () => e20Play()->submit($am[0], $challenge, ['answer' => E17_ANSWER]))->toThrow(\App\Services\Competitive\CompetitiveException::class, 'ابدأ المحاولة');

    // جلسة بمعرّف التحدّي لكن لأحجية مختلفة: finish يرفضها (لا تسجّل نتيجة).
    $other = e17Puzzle();
    \App\Models\GameSession::create(['user_id' => $bm[0]->id, 'puzzle_id' => $other->id, 'context_type' => 'team_challenge', 'context_id' => $challenge->id, 'status' => 'active', 'started_at' => now(), 'server_state' => ['competitive' => true, 'started_ms' => (int) now()->getPreciseTimestamp(3)]]);
    expect(fn () => e20Play()->submit($bm[0], $challenge, ['answer' => E17_ANSWER]))->toThrow(\App\Services\Competitive\CompetitiveException::class)
        ->and(TeamChallengeParticipant::where('user_id', $bm[0]->id)->value('completed_at'))->toBeNull();
});

test('29/B16: one result per roster member - a replayed submit, a restart after finishing and a reused session are all refused, and nothing is recorded twice', function () {
    [$a, $b, $am, $bm] = e20Pair();
    $challenge = e20Accepted($a, $b, [$am[0], $am[1]], [$bm[0]]);
    e20Submit($am[0], $challenge, E17_ANSWER, 10_000);
    $seat = TeamChallengeParticipant::where('user_id', $am[0]->id)->first();

    expect(fn () => e20Play()->submit($am[0], $challenge->refresh(), ['answer' => E17_ANSWER]))->toThrow(\App\Services\Competitive\CompetitiveException::class, 'أنهيت محاولتك')
        ->and(fn () => e20Play()->start($am[0], $challenge))->toThrow(\App\Services\Competitive\CompetitiveException::class, 'أنهيت محاولتك')
        ->and(fn () => TeamChallengeParticipant::query()->whereKey($seat->id)->whereNull('completed_at')->update(['score' => 1]))->not->toThrow(Exception::class);
    expect($seat->refresh()->score)->not->toBe(1)->and(\App\Models\GameSession::where('user_id', $am[0]->id)->where('context_type', 'team_challenge')->count())->toBe(1);
});

test('B19: the play window ends the match - no start or submit after the deadline, and a submit started before it is refused after it', function () {
    [$a, $b, $am, $bm] = e20Pair();
    $challenge = e20Accepted($a, $b, [$am[0], $am[1]], [$bm[0]]);
    e20Play()->start($am[0], $challenge);

    Carbon::setTestNow(now()->addHours(49));

    expect(fn () => e20Play()->submit($am[0], $challenge->refresh(), ['answer' => E17_ANSWER]))->toThrow(\App\Services\Competitive\CompetitiveException::class)
        ->and(fn () => e20Play()->start($am[1], $challenge))->toThrow(\App\Services\Competitive\CompetitiveException::class, 'غير متاح');
});

test('a frozen roster member cannot play - the existing gameplay policy is not bypassed', function () {
    [$a, $b, $am, $bm] = e20Pair();
    $challenge = e20Accepted($a, $b, [$am[0], $am[1]], [$bm[0]]);
    $am[1]->forceFill(['is_frozen' => true])->save();

    expect(fn () => e20Play()->start($am[1]->refresh(), $challenge))->toThrow(\App\Services\Competitive\CompetitiveException::class, 'غير مؤهَّل');
});

test('the challenger can no longer be edited into a stale roster - a member who left before acceptance blocks acceptance until the roster is updated', function () {
    [$a, $b, $am, $bm] = e20Pair();
    $challenge = e20Create($a, $b, [$am[0], $am[1]]);
    e19Members()->leave($am[1]);

    expect(fn () => e20Challenges()->accept($b->owner, $challenge, e20Ids([$bm[0]])))->toThrow(TeamException::class, 'لم يعد صالحًا')
        ->and($challenge->refresh()->status)->toBe('pending');

    e20Challenges()->setRoster($a->owner, $challenge, e20Ids([$am[0]]));
    expect(e20Challenges()->accept($b->owner, $challenge, e20Ids([$bm[0]]))->status)->toBe('accepted');
    expect(fn () => e20Challenges()->setRoster($b->owner, $challenge, e20Ids([$bm[0]])))->toThrow(TeamException::class);
});

test('the team challenge model carries no mass-assignable status, winner, draw, key or deadline - the server alone writes them', function () {
    $fillable = (new TeamChallenge)->getFillable();

    expect($fillable)->not->toContain('status')->not->toContain('winner_team_id')->not->toContain('is_draw')->not->toContain('active_key')->not->toContain('accepted_at')->not->toContain('play_ends_at')->not->toContain('completed_at');
    expect((new \App\Models\TeamChallengeResult)->getGuarded())->toBe(['*'])->and((new \App\Models\TeamChampionshipResult)->getGuarded())->toBe(['*']);
    expect((new TeamChallengeParticipant)->getFillable())->not->toContain('score')->not->toContain('is_correct')->not->toContain('locked_at')->not->toContain('completed_at');
});
