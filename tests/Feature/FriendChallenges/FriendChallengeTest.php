<?php

require_once __DIR__.'/../Competitive/CompetitiveTestHelpers.php';

use App\Models\FriendChallenge;
use App\Models\FriendChallengeResult;
use App\Models\GameSession;
use App\Models\PuzzleAttempt;
use App\Services\Competitive\CompetitiveException;
use App\Services\Social\BlockService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Event;

beforeEach(fn () => e17Freeze());
afterEach(fn () => Carbon::setTestNow());

test('1: an accepted friend can challenge a friend - one pending challenge with the opponent, puzzle and a 48h acceptance window', function () {
    [$a, $b] = e17Friends();
    $puzzle = e17Puzzle();

    $c = e17Challenges()->create($a, $b, $puzzle);

    expect($c->status)->toBe('pending')->and($c->challenger_id)->toBe($a->id)->and($c->opponent_id)->toBe($b->id)->and($c->puzzle_id)->toBe($puzzle->id)
        ->and($c->expires_at->equalTo(now()->addHours(48)))->toBeTrue()->and($c->public_id)->toHaveLength(26);
});

test('2: a non-friend and a merely pending friend cannot be challenged', function () {
    [$a, $stranger, $pending] = [e16User(), e16User(), e16User()];
    e16Svc()->sendRequest($a, $pending);

    expect(fn () => e17Challenges()->create($a, $stranger, e17Puzzle()))->toThrow(CompetitiveException::class, 'يمكنك تحدّي أصدقائك فقط.')
        ->and(fn () => e17Challenges()->create($a, $pending, e17Puzzle()))->toThrow(CompetitiveException::class);
    expect(FriendChallenge::count())->toBe(0);
});

test('3/70: a blocked user cannot be challenged in either direction, with the same neutral message', function () {
    [$a, $b] = e17Friends();
    app(BlockService::class)->block($b, $a);

    foreach ([[$a, $b], [$b, $a]] as [$from, $to]) {
        expect(fn () => e17Challenges()->create($from, $to, e17Puzzle()))->toThrow(CompetitiveException::class, 'يمكنك تحدّي أصدقائك فقط.');
    }
});

test('4: challenging yourself is refused', function () {
    $a = e16User();

    expect(fn () => e17Challenges()->create($a, $a, e17Puzzle()))->toThrow(CompetitiveException::class, 'لا يمكنك تحدّي نفسك.');
    expect(fn () => FriendChallenge::create(['challenger_id' => $a->id, 'opponent_id' => $a->id, 'puzzle_id' => e17Puzzle()->id, 'expires_at' => now()]))->toThrow(InvalidArgumentException::class);
});

test('5: only one active challenge per pair (either direction); after it ends a new one is possible', function () {
    [$a, $b] = e17Friends();
    $first = e17Challenges()->create($a, $b, e17Puzzle());

    expect(fn () => e17Challenges()->create($a, $b, e17Puzzle()))->toThrow(CompetitiveException::class, 'يوجد تحدٍّ نشط بينكما بالفعل.')
        ->and(fn () => e17Challenges()->create($b, $a, e17Puzzle()))->toThrow(CompetitiveException::class)
        ->and(FriendChallenge::count())->toBe(1);

    e17Challenges()->decline($b, $first);

    expect(e17Challenges()->create($a, $b, e17Puzzle())->status)->toBe('pending'); // المفتاح تحرّر
});

test('6: only the opponent can accept - the challenger and a third user cannot', function () {
    [$a, $b] = e17Friends();
    $c = e16User();
    $challenge = e17Challenges()->create($a, $b, e17Puzzle());

    expect(fn () => e17Challenges()->accept($a, $challenge))->toThrow(CompetitiveException::class)
        ->and(fn () => e17Challenges()->accept($c, $challenge))->toThrow(CompetitiveException::class)
        ->and($challenge->refresh()->status)->toBe('pending');

    expect(e17Challenges()->accept($b, $challenge)->status)->toBe('accepted');
});

test('6b: accepting is atomic and idempotent - one event, the play window restarts at acceptance, a retry changes nothing', function () {
    $accepted = 0;
    Event::listen(\App\Events\FriendChallengeAccepted::class, function () use (&$accepted) {
        $accepted++;
    });
    [$a, $b] = e17Friends();
    $challenge = e17Challenges()->create($a, $b, e17Puzzle());
    e17Forward(3600_000); // بعد ساعة

    e17Challenges()->accept($b, $challenge);
    $deadline = $challenge->refresh()->expires_at;
    e17Forward(60_000);
    e17Challenges()->accept($b, $challenge);

    expect($accepted)->toBe(1)->and($challenge->refresh()->status)->toBe('accepted')->and($challenge->expires_at->equalTo($deadline))->toBeTrue()
        ->and($deadline->equalTo(now()->subMinute()->addHours(48)))->toBeTrue();
});

test('7: only the opponent can decline - and only a pending challenge', function () {
    [$a, $b] = e17Friends();
    $challenge = e17Challenges()->create($a, $b, e17Puzzle());

    expect(fn () => e17Challenges()->decline($a, $challenge))->toThrow(CompetitiveException::class)->and($challenge->refresh()->status)->toBe('pending');

    e17Challenges()->decline($b, $challenge);

    expect($challenge->refresh()->status)->toBe('declined')->and($challenge->active_pair_key)->toBeNull()
        ->and(fn () => e17Challenges()->decline($b, $challenge))->toThrow(CompetitiveException::class);
});

test('8: only the challenger can cancel - and only a pending challenge', function () {
    [$a, $b] = e17Friends();
    $challenge = e17Challenges()->create($a, $b, e17Puzzle());

    expect(fn () => e17Challenges()->cancel($b, $challenge))->toThrow(CompetitiveException::class)->and($challenge->refresh()->status)->toBe('pending');

    e17Challenges()->cancel($a, $challenge);
    expect($challenge->refresh()->status)->toBe('cancelled');

    $accepted = e17Challenge($a, $b);
    expect(fn () => e17Challenges()->cancel($a, $accepted))->toThrow(CompetitiveException::class)->and($accepted->refresh()->status)->toBe('accepted');
});

test('9: a challenge expires by expires_at - derived from time, then materialized idempotently by the command service', function () {
    [$a, $b] = e17Friends();
    $challenge = e17Challenges()->create($a, $b, e17Puzzle());

    expect($challenge->effectiveStatus())->toBe('pending');
    e17Forward(48 * 3600_000 + 1);

    expect($challenge->refresh()->effectiveStatus())->toBe('expired')->and($challenge->status)->toBe('pending'); // مشتقة قبل التجسيد

    expect(e17Challenges()->expireStale())->toBe(1)->and(e17Challenges()->expireStale())->toBe(0);
    expect($challenge->refresh()->status)->toBe('expired')->and($challenge->active_pair_key)->toBeNull();
});

test('10: an expired challenge cannot be accepted or played, and a new one can replace it', function () {
    [$a, $b] = e17Friends();
    $pending = e17Challenges()->create($a, $b, e17Puzzle());
    e17Forward(48 * 3600_000 + 1);

    expect(fn () => e17Challenges()->accept($b, $pending->refresh()))->toThrow(CompetitiveException::class, 'انتهت مهلة هذا التحدي.');
    expect(fn () => e17Challenges()->start($b, $pending->refresh()))->toThrow(CompetitiveException::class);
    expect(e17Challenges()->create($a, $b, e17Puzzle())->status)->toBe('pending'); // الإنشاء يُجسِّد المنتهي ويحرّر المفتاح
});

test('11: the competitive game session is bound server-side to the right challenge, user and puzzle - and writes NO puzzle attempt', function () {
    [$a, $b] = e17Friends();
    $challenge = e17Challenge($a, $b);

    $session = e17Challenges()->start($a, $challenge);

    expect($session->context_type)->toBe('friend_challenge')->and($session->context_id)->toBe($challenge->id)->and($session->user_id)->toBe($a->id)
        ->and($session->puzzle_id)->toBe($challenge->puzzle_id)->and($session->status)->toBe('active')->and($session->isCompetitive())->toBeTrue()
        ->and($session->server_state['competitive'])->toBeTrue()
        ->and(PuzzleAttempt::count())->toBe(0);
    expect(e17Challenges()->start($a, $challenge)->id)->toBe($session->id); // استئناف: نفس الجلسة
});

test('12: user A cannot play in B place - non-participants, wrong sessions and a stolen session are refused', function () {
    [$a, $b] = e17Friends();
    $outsider = e16User();
    $challenge = e17Challenge($a, $b);
    e17Challenges()->start($a, $challenge);

    expect(fn () => e17Challenges()->start($outsider, $challenge))->toThrow(CompetitiveException::class)
        ->and(fn () => e17Challenges()->submit($outsider, $challenge, ['answer' => E17_ANSWER]))->toThrow(CompetitiveException::class)
        ->and(fn () => e17Challenges()->submit($b, $challenge, ['answer' => E17_ANSWER]))->toThrow(CompetitiveException::class, 'ابدأ المحاولة أولًا.'); // لا جلسة لـB

    // جلسة A لا تُستعمل من B حتى لو مُرّرت مباشرة إلى المحرّك.
    $stolen = GameSession::where('user_id', $a->id)->first();
    expect(fn () => app(\App\Services\Competitive\CompetitiveRunService::class)->finish($b, $stolen, $challenge->puzzle, \App\GameEngine\Support\AttemptContext::friendChallenge($challenge->id), ['answer' => E17_ANSWER]))
        ->toThrow(CompetitiveException::class);
    expect(FriendChallengeResult::count())->toBe(0);
});

test('13: client-supplied score, winner, rank, duration, start token and hint flag are ignored - the result is computed by the server', function () {
    [$a, $b] = e17Friends();
    $challenge = e17Challenge($a, $b);
    $this->actingAs($a)->post(route('friends.challenges.start', $challenge));
    e17Forward(30_000);

    $this->actingAs($a)->post(route('friends.challenges.submit', $challenge), [
        'answer' => E17_ANSWER, 'score' => 999999, 'winner' => $a->id, 'winner_user_id' => $a->id, 'rank' => 1, 'duration_ms' => 1,
        'start_token' => 'forged', 'used_hint' => 1, 'is_correct' => 1, 'submission' => '{"elapsed_seconds":1,"score":9999,"winner":1}', // مفاتيح غير مسموحة: تُصفّى وتبقى الإجابة وحدها
    ])->assertSessionHasNoErrors();

    $result = FriendChallengeResult::sole();
    expect($result->duration_ms)->toBe(30000)->and($result->score)->toBe(1950)->and($result->is_correct)->toBeTrue();
});

test('14: one result per side - the same user cannot submit or restart after finishing', function () {
    [$a, $b] = e17Friends();
    $challenge = e17Challenge($a, $b);
    e17PlayChallenge($a, $challenge);

    expect(fn () => e17Challenges()->submit($a, $challenge->refresh(), ['answer' => E17_ANSWER]))->toThrow(CompetitiveException::class, 'سجّلت نتيجتك بالفعل.')
        ->and(fn () => e17Challenges()->start($a, $challenge))->toThrow(CompetitiveException::class)
        ->and(FriendChallengeResult::count())->toBe(1);
});

test('15: completion is computed on the server when both sides finish - the faster correct answer wins', function () {
    [$a, $b] = e17Friends();
    $challenge = e17Challenge($a, $b);

    e17PlayChallenge($a, $challenge, E17_ANSWER, 40_000);
    expect($challenge->refresh()->status)->toBe('accepted')->and($challenge->winner_user_id)->toBeNull();

    e17PlayChallenge($b, $challenge, E17_ANSWER, 10_000);
    $challenge->refresh();
    $scores = FriendChallengeResult::pluck('score', 'user_id');

    expect($challenge->status)->toBe('completed')->and($challenge->winner_user_id)->toBe($b->id)->and($challenge->is_draw)->toBeFalse()
        ->and($scores[$b->id])->toBeGreaterThan($scores[$a->id])->and($challenge->completed_at)->not->toBeNull()->and($challenge->active_pair_key)->toBeNull();
});

test('15b: a wrong answer scores zero and loses to a correct one; two wrong answers draw', function () {
    [$a, $b, $c] = [...e17Friends(), e16User()];
    e16Befriend($a, $c);

    $one = e17Challenge($a, $b);
    e17PlayChallenge($a, $one, 'خطأ', 5_000);
    e17PlayChallenge($b, $one, E17_ANSWER, 50_000);
    expect($one->refresh()->winner_user_id)->toBe($b->id)->and(FriendChallengeResult::where('user_id', $a->id)->value('score'))->toBe(0);

    $two = e17Challenge($a, $c);
    e17PlayChallenge($a, $two, 'خطأ', 5_000);
    e17PlayChallenge($c, $two, 'خطأ ثانٍ', 9_000);
    expect($two->refresh()->is_draw)->toBeTrue()->and($two->winner_user_id)->toBeNull();
});

test('16: a draw is supported - equal scores give no winner', function () {
    [$a, $b] = e17Friends();
    $challenge = e17Challenge($a, $b);
    e17Challenges()->start($a, $challenge);
    e17Challenges()->start($b, $challenge);
    e17Forward(30_000); // نفس لحظة البدء ونفس الإرسال => المدة نفسها
    e17Challenges()->submit($a, $challenge->refresh(), ['answer' => E17_ANSWER]);
    e17Challenges()->submit($b, $challenge->refresh(), ['answer' => E17_ANSWER]);

    $challenge->refresh();

    expect($challenge->status)->toBe('completed')->and($challenge->is_draw)->toBeTrue()->and($challenge->winner_user_id)->toBeNull();
});

test('17: results are idempotent - a replayed submit never creates a second result and a session cannot be reused', function () {
    [$a, $b] = e17Friends();
    $challenge = e17Challenge($a, $b);
    $this->actingAs($a)->post(route('friends.challenges.start', $challenge));
    e17Forward(20_000);

    $this->actingAs($a)->post(route('friends.challenges.submit', $challenge), ['answer' => E17_ANSWER])->assertSessionHas('success');
    $this->actingAs($a)->post(route('friends.challenges.submit', $challenge), ['answer' => E17_ANSWER])->assertSessionHas('error');

    expect(FriendChallengeResult::count())->toBe(1)->and(GameSession::where('user_id', $a->id)->count())->toBe(1);
    $dup = fn () => FriendChallengeResult::create(['friend_challenge_id' => $challenge->id, 'user_id' => $b->id, 'game_session_id' => FriendChallengeResult::value('game_session_id'), 'is_correct' => true, 'duration_ms' => 1, 'score' => 1, 'completed_at' => now()]);
    expect($dup)->toThrow(\Illuminate\Database\UniqueConstraintViolationException::class); // UNIQUE(game_session_id)
});

test('opponent result stays hidden until both sides finish; the challenger sees only their own', function () {
    [$a, $b] = e17Friends();
    $challenge = e17Challenge($a, $b);
    e17PlayChallenge($b, $challenge, E17_ANSWER, 10_000);

    $page = $this->actingAs($a)->get(route('friends.challenges.show', $challenge))->assertOk();
    expect($page->viewData('theirs'))->toBeNull()->and($page->viewData('mine'))->toBeNull();

    e17PlayChallenge($a, $challenge, E17_ANSWER, 30_000);
    $done = $this->actingAs($a)->get(route('friends.challenges.show', $challenge))->assertOk();
    expect($done->viewData('theirs'))->not->toBeNull()->and($done->viewData('mine'))->not->toBeNull();
    $done->assertSee('نقاطك')->assertSee($b->name);
});

test('21-24: a friend challenge grants NO XP, currency, quest progress, streak, achievement, wallet change or legacy-challenge score', function () {
    [$a, $b] = e17Friends();
    $before = e17Snapshot();

    $challenge = e17Challenge($a, $b);
    e17PlayChallenge($a, $challenge, E17_ANSWER, 10_000);
    e17PlayChallenge($b, $challenge, E17_ANSWER, 20_000);
    $this->actingAs($a)->get(route('friends.challenges.index'));
    $this->actingAs($a)->get(route('friends.challenges.show', $challenge));

    expect($challenge->refresh()->status)->toBe('completed')->and(e17Snapshot())->toBe($before); // يشمل puzzle_attempts: لا تضخيم للوحة الصدارة العامة
});

test('A4: blocking cancels an active challenge in the same transaction, and unfriending does too', function () {
    [$a, $b, $c] = [...e17Friends(), e16User()];
    e16Befriend($a, $c);
    $one = e17Challenge($a, $b, null, false);
    $two = e17Challenge($a, $c);

    app(BlockService::class)->block($b, $a);
    e16Svc()->remove($c, $a);

    expect($one->refresh()->status)->toBe('cancelled')->and($one->active_pair_key)->toBeNull()
        ->and($two->refresh()->status)->toBe('cancelled');
    expect(fn () => e17Challenges()->accept($b, $one))->toThrow(CompetitiveException::class);
});

test('A4b: a challenge whose pair is no longer valid is cancelled when touched, and by the safety-net sweep', function () {
    [$a, $b] = e17Friends();
    $challenge = e17Challenge($a, $b);
    \Illuminate\Support\Facades\DB::table('friendships')->delete(); // انكسرت العلاقة خارج الخدمات (مثلاً مباشرة)

    expect(e17Challenges()->cancelInvalid())->toBe(1)->and($challenge->refresh()->status)->toBe('cancelled');
});

test('eligibility: puzzles with a hint, inactive puzzles and interactive-session types are not valid targets; the search query agrees', function () {
    [$a, $b] = e17Friends();
    $withHint = e17Puzzle(['hint' => 'تلميح يُشترى بعملة']);
    $inactive = e17Puzzle(['is_active' => false]);
    $spot = e17Puzzle(['game_type' => 'spot_difference']);
    $ok = e17Puzzle();

    foreach ([$withHint, $inactive, $spot] as $bad) {
        expect(fn () => e17Challenges()->create($a, $b, $bad))->toThrow(CompetitiveException::class);
    }

    $ids = app(\App\Services\Competitive\CompetitiveEligibility::class)->eligiblePuzzlesQuery()->pluck('id')->all();
    expect($ids)->toContain($ok->id)->and($ids)->not->toContain($withHint->id)->and($ids)->not->toContain($inactive->id)->and($ids)->not->toContain($spot->id);
    foreach ($ids as $id) {
        expect(app(\App\Services\Competitive\CompetitiveEligibility::class)->reasonIfIneligible(\App\Models\Puzzle::find($id)))->toBeNull();
    }
});

test('a competitive session can never be driven through the standard reveal route', function () {
    [$a, $b] = e17Friends();
    $challenge = e17Challenge($a, $b);
    $session = e17Challenges()->start($a, $challenge);

    $this->actingAs($a)->postJson(route('game-sessions.reveal', $session), ['x' => 0.5, 'y' => 0.5])->assertForbidden();
});

test('history page: pending, active and history sections paginate and show opponent, puzzle, score, result and date', function () {
    [$a, $b] = e17Friends();
    $challenge = e17Challenge($a, $b);
    e17PlayChallenge($a, $challenge, E17_ANSWER, 10_000);
    e17PlayChallenge($b, $challenge, E17_ANSWER, 40_000);

    $page = $this->actingAs($a)->get(route('friends.challenges.index'))->assertOk();
    $page->assertSee('قيد الانتظار')->assertSee('نشطة')->assertSee('السجل')->assertSee($b->name)->assertSee($challenge->puzzle->title)->assertSee('فوز')->assertSee(now()->format('Y-m-d'));
    expect($page->viewData('history')->total())->toBe(1);

    foreach (range(1, 12) as $i) {
        [$x, $y] = e17Friends();
        FriendChallenge::query()->forceCreate(['public_id' => (string) \Illuminate\Support\Str::ulid(), 'challenger_id' => $a->id, 'opponent_id' => $x->id, 'puzzle_id' => e17Puzzle()->id, 'status' => 'declined', 'expires_at' => now()->addDay()]);
    }
    expect($this->actingAs($a)->get(route('friends.challenges.index'))->viewData('history')->count())->toBe(10); // 10 بالصفحة
});

test('the challenge button appears for accepted friends only, and the creation page lists only eligible puzzles with search and pagination', function () {
    [$a, $b] = e17Friends();
    $stranger = e16User();
    e17Puzzle(['title' => 'لغز الشمس']);
    e17Puzzle(['title' => 'لغز القمر', 'hint' => 'تلميح']);
    foreach (range(1, 12) as $i) {
        e17Puzzle(['title' => sprintf('أحجية %02d', $i)]);
    }

    $this->actingAs($a)->get(route('players.show', $b))->assertSee('تحدَّ هذا الصديق');
    $this->actingAs($a)->get(route('players.show', $stranger))->assertDontSee('تحدَّ هذا الصديق');
    $this->actingAs($a)->get(route('friends.challenges.create', $stranger))->assertRedirect(route('friends.index'));

    $page = $this->actingAs($a)->get(route('friends.challenges.create', $b))->assertOk();
    expect($page->viewData('puzzles')->count())->toBe(8)->and($page->viewData('puzzles')->total())->toBe(13);
    $this->actingAs($a)->get(route('friends.challenges.create', [$b, 'q' => 'الشمس']))->assertSee('لغز الشمس')->assertDontSee('لغز القمر');
});

test('challenge creation is rate limited (5 per minute)', function () {
    $a = e16User();
    $statuses = [];

    foreach (range(1, 6) as $i) {
        [$x] = [e16User()];
        e16Befriend($a, $x);
        $statuses[] = $this->actingAs($a)->post(route('friends.challenges.store', $x), ['puzzle_id' => e17Puzzle()->id])->getStatusCode();
    }

    expect(array_slice($statuses, 0, 5))->each->not->toBe(429)->and($statuses[5])->toBe(429)->and(FriendChallenge::count())->toBe(5);
});

test('defense in depth: even if the pre-check were bypassed, the in-transaction check rejects a result after the challenge expired', function () {
    [$a, $b] = e17Friends();
    $challenge = e17Challenge($a, $b);
    e17Challenges()->start($a, $challenge);
    e17Forward(48 * 3600_000 + 1);

    $bypassed = new class(app(\App\Services\Social\FriendshipService::class), app(\App\Services\Competitive\CompetitiveEligibility::class), app(\App\Services\Competitive\CompetitiveRunService::class)) extends \App\Services\Competitive\FriendChallengeService
    {
        protected function assertPlayable(\App\Models\User $actor, FriendChallenge $challenge): void {} // الفحص المسبق مُعطَّل
    };

    expect(fn () => $bypassed->submit($a, $challenge->refresh(), ['answer' => E17_ANSWER]))->toThrow(CompetitiveException::class, 'انتهى هذا التحدي.')
        ->and(FriendChallengeResult::count())->toBe(0);
});
