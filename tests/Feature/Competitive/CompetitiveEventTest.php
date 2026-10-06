<?php

require_once __DIR__.'/CompetitiveTestHelpers.php';

use App\Models\CompetitiveEvent;
use App\Models\CompetitiveEventParticipant;
use App\Models\CompetitiveEventResult;
use App\Models\GameSession;
use App\Models\PuzzleAttempt;
use App\Models\User;
use App\Services\Competitive\CompetitiveEventFinalizer;
use App\Services\Competitive\CompetitiveEventService;
use App\Services\Competitive\CompetitiveException;
use App\Services\Competitive\CompetitiveRunService;
use App\Services\Competitive\CompetitiveScoringService;
use Carbon\Carbon;

beforeEach(fn () => e17Freeze());
afterEach(fn () => Carbon::setTestNow());

test('25: a draft event accepts no registration and is invisible to the public', function () {
    $event = e17Event([], CompetitiveEvent::STATUS_DRAFT);
    $user = e16User();

    expect(fn () => e17Events()->register($user, $event))->toThrow(CompetitiveException::class)->and(CompetitiveEventParticipant::count())->toBe(0);
    $this->get(route('competitions.show', $event))->assertNotFound();
    $this->get(route('competitions.index'))->assertOk()->assertDontSee($event->title);
});

test('26: a published event accepts registration inside its window - upcoming and live', function () {
    $upcoming = e17Event(['starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2)]);
    $live = e17Event();
    $user = e16User();

    expect(e17Events()->register($user, $upcoming)->status)->toBe('registered')->and(e17Events()->register($user, $live)->status)->toBe('registered');
    expect($upcoming->refresh()->participants_count)->toBe(1)->and($upcoming->phase())->toBe('upcoming')->and($live->refresh()->phase())->toBe('live');
});

test('27/28: registration is refused before the registration window opens and after it closes', function () {
    $user = e16User();
    $before = e17Event(['starts_at' => now()->addDays(3), 'ends_at' => now()->addDays(4), 'registration_starts_at' => now()->addDay(), 'registration_ends_at' => now()->addDays(2)]);

    expect(fn () => e17Events()->register($user, $before))->toThrow(CompetitiveException::class, 'لم يبدأ التسجيل بعد.');

    e17Forward(3 * 86400_000); // بعد إغلاق نافذة التسجيل وقبل البدء الفعلي بساعات
    e17Forward(-3600_000);
    $closedEvent = e17Event(['starts_at' => now()->addHours(5), 'ends_at' => now()->addHours(9), 'registration_ends_at' => now()->subMinute()]);
    expect(fn () => e17Events()->register($user, $closedEvent))->toThrow(CompetitiveException::class, 'انتهى التسجيل في هذه المنافسة.');
    expect(CompetitiveEventParticipant::count())->toBe(0);

    $ended = e17Event(['starts_at' => now()->subDays(2), 'ends_at' => now()->subDay()]);
    expect(fn () => e17Events()->register($user, $ended))->toThrow(CompetitiveException::class);
});

test('29: duplicate registration is idempotent - one row and a counter of one', function () {
    $event = e17Event();
    $user = e16User();

    $first = e17Events()->register($user, $event);
    $second = e17Events()->register($user, $event);
    $this->actingAs($user)->post(route('competitions.register', $event))->assertSessionHas('success');

    expect($second->id)->toBe($first->id)->and(CompetitiveEventParticipant::count())->toBe(1)->and($event->refresh()->participants_count)->toBe(1);
});

test('30: capacity is respected - the event closes when max_participants is reached', function () {
    $event = e17Event(['max_participants' => 2]);
    [$u1, $u2, $u3] = [e16User(), e16User(), e16User()];

    e17Events()->register($u1, $event);
    e17Events()->register($u2, $event);

    expect(fn () => e17Events()->register($u3, $event))->toThrow(CompetitiveException::class, 'اكتمل عدد المشاركين في هذه المنافسة.')
        ->and($event->refresh()->participants_count)->toBe(2)->and(CompetitiveEventParticipant::count())->toBe(2)->and($event->isFull())->toBeTrue();
});

test('31: concurrent-like final slot - even a stale read that still sees a free seat cannot exceed max (the atomic UPDATE decides)', function () {
    $event = e17Event(['max_participants' => 1]);
    [$first, $late] = [e16User(), e16User()];
    $stale = CompetitiveEvent::find($event->id); // نسخة قديمة: participants_count = 0

    e17Events()->register($first, $event); // يملأ المقعد الأخير

    $racing = new class(app(CompetitiveRunService::class)) extends CompetitiveEventService
    {
        public ?CompetitiveEvent $stale = null;

        protected function lockedEvent(CompetitiveEvent $event): CompetitiveEvent
        {
            return $this->stale; // الحاجز يرى مقعدًا فارغًا (فجوة السباق)
        }
    };
    $racing->stale = $stale;

    expect($stale->isFull())->toBeFalse()
        ->and(fn () => $racing->register($late, $event))->toThrow(CompetitiveException::class, 'اكتمل عدد المشاركين في هذه المنافسة.')
        ->and($event->refresh()->participants_count)->toBe(1)->and(CompetitiveEventParticipant::count())->toBe(1);
});

test('31b: a duplicate insert rolls the reserved seat back (the counter never drifts)', function () {
    $event = e17Event(['max_participants' => 5]);
    $user = e16User();
    e17Events()->register($user, $event);

    // اسبق الفحص: صف موجود لكن الحاجز لا يراه => القيد UNIQUE يرفض، والعدّاد يتراجع بالمعاملة.
    $racing = new class(app(CompetitiveRunService::class)) extends CompetitiveEventService
    {
        protected function lockedEvent(CompetitiveEvent $event): CompetitiveEvent
        {
            return CompetitiveEvent::query()->findOrFail($event->getKey());
        }
    };
    $before = $event->refresh()->participants_count;
    $racing->register($user, $event); // يعيد الموجود بلا عدّ مزدوج

    expect($event->refresh()->participants_count)->toBe($before)->and(CompetitiveEventParticipant::count())->toBe(1);
});

test('32: a cancelled event accepts no registration, no start and no result', function () {
    $event = e17Event();
    $user = e16User();
    e17Events()->register($user, $event);
    e17Events()->start($user, $event);
    $event->forceFill(['status' => CompetitiveEvent::STATUS_CANCELLED])->save();

    expect(fn () => e17Events()->register(e16User(), $event))->toThrow(CompetitiveException::class)
        ->and(fn () => e17Events()->submit($user, $event, ['answer' => E17_ANSWER]))->toThrow(CompetitiveException::class)
        ->and(CompetitiveEventResult::count())->toBe(0)->and($event->phase())->toBe('cancelled');
});

test('33: only a registered participant can play', function () {
    $event = e17Event();
    $outsider = e16User();

    expect(fn () => e17Events()->start($outsider, $event))->toThrow(CompetitiveException::class, 'يجب التسجيل في المنافسة أولًا.')
        ->and(fn () => e17Events()->submit($outsider, $event, ['answer' => E17_ANSWER]))->toThrow(CompetitiveException::class)
        ->and(GameSession::count())->toBe(0);
    $this->actingAs($outsider)->post(route('competitions.start', $event))->assertSessionHas('error');
    $this->actingAs($outsider)->get(route('competitions.play', $event))->assertRedirect(route('competitions.show', $event));
});

test('34: no result and no start before starts_at', function () {
    $event = e17Event(['starts_at' => now()->addHour(), 'ends_at' => now()->addHours(3)]);
    $user = e16User();
    e17Events()->register($user, $event);

    expect(fn () => e17Events()->start($user, $event))->toThrow(CompetitiveException::class, 'لم تبدأ المنافسة بعد.')
        ->and(fn () => e17Events()->submit($user, $event, ['answer' => E17_ANSWER]))->toThrow(CompetitiveException::class)
        ->and(CompetitiveEventResult::count())->toBe(0);
});

test('35: a late result after ends_at is rejected even if the run started in time', function () {
    $event = e17Event(['ends_at' => now()->addMinutes(10)]);
    $user = e16User();
    e17Events()->register($user, $event);
    e17Events()->start($user, $event);
    e17Forward(11 * 60_000); // بعد النهاية

    expect(fn () => e17Events()->submit($user, $event, ['answer' => E17_ANSWER]))->toThrow(CompetitiveException::class, 'انتهت المنافسة.')
        ->and(CompetitiveEventResult::count())->toBe(0);
});

test('36: the score is calculated by the server from the measured duration and correctness', function () {
    $event = e17Event();
    [$fast, $slow, $wrong] = [e16User(), e16User(), e16User()];

    $a = e17PlayEvent($fast, $event, E17_ANSWER, 30_000);        // 570000/600000 => 950
    $b = e17PlayEvent($slow, $event, E17_ANSWER, 600_000);       // وصل السقف => 0 مكافأة
    $c = e17PlayEvent($wrong, $event, 'إجابة خاطئة', 1_000);

    expect($a->score)->toBe(1950)->and($a->durationMs)->toBe(30000)->and($b->score)->toBe(1000)->and($c->score)->toBe(0)->and($c->correct)->toBeFalse()
        ->and(CompetitiveEventResult::where('user_id', $fast->id)->first())->toMatchArray(['score' => 1950, 'duration_ms' => 30000, 'is_correct' => true]);

    $scoring = app(CompetitiveScoringService::class);
    expect($scoring->score(true, 30_000, 600_000))->toBe($scoring->score(true, 30_000, 600_000)) // حتمي
        ->and($scoring->score(true, 0, 600_000))->toBe(2000)->and($scoring->score(false, 0, 600_000))->toBe(0)
        ->and(CompetitiveScoringService::HIGHER_IS_BETTER)->toBeTrue();
});

test('36b: a puzzle time limit caps the speed bonus and an over-limit submission is incorrect', function () {
    $event = e17Event(['puzzle_id' => e17Puzzle(['time_limit_seconds' => 60])->id]);
    [$ok, $late] = [e16User(), e16User()];

    $in = e17PlayEvent($ok, $event, E17_ANSWER, 30_000);   // نصف السقف => 1500
    $over = e17PlayEvent($late, $event, E17_ANSWER, 61_000);

    expect($in->score)->toBe(1500)->and($over->timedOut)->toBeTrue()->and($over->correct)->toBeFalse()->and($over->score)->toBe(0);
});

test('37: a tampered score, rank, winner or duration in the request is ignored - HTTP end to end', function () {
    $event = e17Event();
    $user = e16User();
    $this->actingAs($user)->post(route('competitions.register', $event));
    $this->actingAs($user)->post(route('competitions.start', $event))->assertRedirect(route('competitions.play', $event));
    e17Forward(30_000);

    $this->actingAs($user)->post(route('competitions.submit', $event), [
        'answer' => E17_ANSWER, 'score' => 999999, 'rank' => 1, 'winner' => $user->id, 'duration_ms' => 1, 'final_rank' => 1, 'is_correct' => 1, 'start_token' => 'forged', 'used_hint' => 1,
    ])->assertSessionHas('success');

    $result = CompetitiveEventResult::sole();
    expect($result->score)->toBe(1950)->and($result->duration_ms)->toBe(30000)->and($result->final_rank)->toBeNull();
});

test('38: a duplicate result is prevented and a replayed submit is rejected', function () {
    $event = e17Event();
    $user = e16User();
    e17PlayEvent($user, $event);

    expect(fn () => e17Events()->submit($user, $event, ['answer' => E17_ANSWER]))->toThrow(CompetitiveException::class, 'سجّلت نتيجتك بالفعل.')
        ->and(fn () => e17Events()->start($user, $event))->toThrow(CompetitiveException::class)
        ->and(CompetitiveEventResult::count())->toBe(1)->and(GameSession::count())->toBe(1);
    $this->actingAs($user)->post(route('competitions.submit', $event), ['answer' => E17_ANSWER])->assertSessionHas('error');
    expect(CompetitiveEventResult::count())->toBe(1);
});

test('39: old attempts and unrelated sessions never become competitive results - the run itself must start inside the event', function () {
    $puzzle = e17Puzzle();
    $user = e16User();
    PuzzleAttempt::create(['user_id' => $user->id, 'puzzle_id' => $puzzle->id, 'attempt_number' => 1, 'is_correct' => true, 'used_hint' => false]); // حل قديم صحيح
    GameSession::create(['user_id' => $user->id, 'puzzle_id' => $puzzle->id, 'status' => 'completed', 'started_at' => now()->subDay(), 'completed_at' => now()->subDay()]);
    $event = e17Event(['puzzle_id' => $puzzle->id]);
    e17Events()->register($user, $event);

    // لا نتيجة بلا بدء تنافسي داخل الحدث، مهما وُجد من محاولات قديمة بالأحجية نفسها.
    expect(fn () => e17Events()->submit($user, $event, ['answer' => E17_ANSWER]))->toThrow(CompetitiveException::class, 'ابدأ المحاولة أولًا.')
        ->and(CompetitiveEventResult::count())->toBe(0);

    $before = PuzzleAttempt::count();
    e17Events()->start($user, $event);
    e17Forward(10_000);
    e17Events()->submit($user, $event, ['answer' => E17_ANSWER]);

    expect(CompetitiveEventResult::sole()->duration_ms)->toBe(10000)->and(PuzzleAttempt::count())->toBe($before); // لا محاولة جديدة بالجدول القياسي
});

test('leaving: allowed before the start only, never after a result, and the seat is released', function () {
    $event = e17Event(['starts_at' => now()->addHour(), 'ends_at' => now()->addHours(3), 'max_participants' => 1]);
    [$u, $other] = [e16User(), e16User()];
    e17Events()->register($u, $event);
    expect(fn () => e17Events()->register($other, $event))->toThrow(CompetitiveException::class);

    $this->actingAs($u)->delete(route('competitions.leave', $event))->assertSessionHas('success');
    expect($event->refresh()->participants_count)->toBe(0)->and(e17Events()->register($other, $event)->status)->toBe('registered'); // المقعد تحرّر

    e17Forward(2 * 3600_000); // بدأ الحدث
    expect(fn () => e17Events()->leave($other, $event))->toThrow(CompetitiveException::class, 'لا يمكن الانسحاب بعد بدء المنافسة.');

    $live = e17Event();
    e17PlayEvent($u, $live);
    expect(fn () => e17Events()->leave($u, $live))->toThrow(CompetitiveException::class)->and(CompetitiveEventResult::where('user_id', $u->id)->exists())->toBeTrue();
});

test('an unverified or frozen account cannot register or play', function () {
    $event = e17Event();
    $unverified = User::factory()->create(['email_verified_at' => null]);
    $frozen = e16User(['is_frozen' => true]);

    foreach ([$unverified, $frozen] as $bad) {
        expect(fn () => e17Events()->register($bad, $event))->toThrow(CompetitiveException::class, 'حسابك غير مؤهَّل للمشاركة.');
    }
    $this->actingAs($frozen)->post(route('competitions.register', $event))->assertRedirect(); // account.active يمنعه قبل الخدمة
    expect(CompetitiveEventParticipant::count())->toBe(0);
});

test('40/43: finalization ranks deterministically by score, then duration, then completion time, then id', function () {
    $event = e17Event();
    [$u1, $u2, $u3, $u4] = [e16User(), e16User(), e16User(), e16User()];
    e17PlayEvent($u1, $event, E17_ANSWER, 50_000);   // بطيء
    e17PlayEvent($u2, $event, E17_ANSWER, 10_000);   // الأسرع
    e17PlayEvent($u3, $event, 'خطأ', 1_000);         // 0
    e17PlayEvent($u4, $event, E17_ANSWER, 30_000);

    e17Forward(4 * 3600_000); // انتهى الحدث
    expect(app(CompetitiveEventFinalizer::class)->finalize($event->refresh()))->toBeTrue();

    $ranks = CompetitiveEventResult::orderBy('final_rank')->pluck('user_id', 'final_rank')->all();
    expect($ranks)->toBe([1 => $u2->id, 2 => $u4->id, 3 => $u1->id, 4 => $u3->id])
        ->and($event->refresh()->status)->toBe('completed')->and($event->finalized_at)->not->toBeNull()->and($event->phase())->toBe('completed');
});

test('41: finalizing twice changes nothing - no new ranks, no new event, no duplicate notification', function () {
    $fired = 0;
    \Illuminate\Support\Facades\Event::listen(\App\Events\CompetitiveEventFinalized::class, function () use (&$fired) {
        $fired++;
    });
    $event = e17Event();
    $user = e16User();
    e17PlayEvent($user, $event);
    e17Forward(4 * 3600_000);
    $finalizer = app(CompetitiveEventFinalizer::class);

    expect($finalizer->finalize($event->refresh()))->toBeTrue();
    $snapshot = CompetitiveEventResult::pluck('final_rank', 'id')->all();
    $at = $event->refresh()->finalized_at;
    e17Forward(60_000);

    expect($finalizer->finalize($event->refresh()))->toBeFalse()->and(CompetitiveEventResult::pluck('final_rank', 'id')->all())->toBe($snapshot)
        ->and($event->refresh()->finalized_at->equalTo($at))->toBeTrue()->and($fired)->toBe(1)->and(e16Notes('competitive_event_result_ready'))->toHaveCount(1);
});

test('42: tie ordering is stable - equal score and duration fall back to completion time, then id', function () {
    $event = e17Event();
    [$early, $late, $third] = [e16User(), e16User(), e16User()];
    foreach ([$early, $late, $third] as $u) {
        e17Events()->register($u, $event);
        e17Events()->start($u, $event);
    }
    e17Forward(30_000);
    foreach ([$late, $early, $third] as $u) { // الإرسال بترتيب مخالف: النتيجة نفسها لثلاثتهم
        e17Events()->submit($u, $event, ['answer' => E17_ANSWER]);
    }
    // اجعل completed_at مختلفًا لأول اثنين (early أسبق رغم أن id نتيجته أكبر) وبقي الثالث مساويًا لـlate
    CompetitiveEventResult::where('user_id', $early->id)->update(['completed_at' => now()->subSeconds(5)]);

    e17Forward(4 * 3600_000);
    app(CompetitiveEventFinalizer::class)->finalize($event->refresh());
    $order = CompetitiveEventResult::orderBy('final_rank')->pluck('user_id')->all();

    expect($order)->toBe([$early->id, $late->id, $third->id]); // الوقت أولًا، ثم المعرّف (late أُرسل قبل third)
    // وحتمي: إعادة الحساب بنفس الاستعلام تعطي النتيجة نفسها
    expect(CompetitiveEventResult::query()->orderByDesc('score')->orderBy('duration_ms')->orderBy('completed_at')->orderBy('id')->pluck('user_id')->all())->toBe($order);
});

test('no finalization before the end, none for a cancelled event, and sessions left open are closed', function () {
    $event = e17Event();
    $user = e16User();
    e17Events()->register($user, $event);
    e17Events()->start($user, $event); // يبدأ ولا يُرسل

    expect(app(CompetitiveEventFinalizer::class)->finalize($event->refresh()))->toBeFalse()->and($event->refresh()->status)->toBe('published');

    e17Forward(4 * 3600_000);
    expect(app(CompetitiveEventFinalizer::class)->finalize($event->refresh()))->toBeTrue()
        ->and(GameSession::where('user_id', $user->id)->first()->status)->toBe('expired')->and(CompetitiveEventResult::count())->toBe(0);

    $cancelled = e17Event(['ends_at' => now()->subMinute(), 'starts_at' => now()->subDay()], CompetitiveEvent::STATUS_CANCELLED);
    expect(app(CompetitiveEventFinalizer::class)->finalize($cancelled))->toBeFalse();
});

test('fairness fields are locked after publishing at the model level; drafts stay editable', function () {
    $draft = e17Event([], CompetitiveEvent::STATUS_DRAFT);
    $draft->update(['title' => 'عنوان جديد', 'max_participants' => 5]);
    expect($draft->refresh()->max_participants)->toBe(5);

    $published = e17Event();
    foreach ([['puzzle_id' => e17Puzzle()->id], ['starts_at' => now()->addDay()], ['ends_at' => now()->addDays(2)], ['max_participants' => 9]] as $change) {
        expect(fn () => $published->update($change))->toThrow(InvalidArgumentException::class);
        $published->refresh();
    }
    $published->update(['description' => 'وصف جديد', 'is_featured' => true]); // مسموح
    expect($published->refresh()->description)->toBe('وصف جديد')
        ->and(fn () => e17Event(['starts_at' => now()->addDay(), 'ends_at' => now()->addHour()]))->toThrow(InvalidArgumentException::class); // نهاية قبل بداية
});

test('lifecycle phases are derived from time, not from manual statuses', function () {
    $event = e17Event(['starts_at' => now()->addHour(), 'ends_at' => now()->addHours(3)]);

    expect($event->phase())->toBe('upcoming');
    e17Forward(2 * 3600_000);
    expect($event->phase())->toBe('live');
    e17Forward(2 * 3600_000);
    expect($event->phase())->toBe('ended')->and(e17Event([], CompetitiveEvent::STATUS_DRAFT)->phase())->toBe('draft');
});

test('the competitive tables carry no payment or currency columns - there is no paid entry', function () {
    foreach (['competitive_events', 'competitive_event_participants', 'competitive_event_results', 'friend_challenges', 'friend_challenge_results'] as $table) {
        $columns = \Illuminate\Support\Facades\Schema::getColumnListing($table);
        foreach ($columns as $column) {
            expect($column)->not->toMatch('/(fee|price|cost|currency|wallet|stake|bet|prize|reward|amount|payment)/i');
        }
    }
});

test('memory puzzles compete too - the signed start token is always the server own: a forged client token and forged context are ignored', function () {
    $puzzle = e17Puzzle(['game_type' => 'memory', 'game_config' => ['faces' => ['أ', 'ب']], 'time_limit_seconds' => 120]);
    $matches = collect($puzzle->fresh()->game_config['cards'])->groupBy('face')->map(fn ($g) => $g->pluck('id')->values()->all())->values()->all();
    $event = e17Event(['puzzle_id' => $puzzle->id]);
    $user = e16User();
    e17Events()->register($user, $event);
    e17Events()->start($user, $event);
    e17Forward(20_000);

    $outcome = e17Events()->submit($user, $event, ['submission' => ['matches' => $matches, 'start_token' => 'forged', '_context' => ['user_id' => 999999]]]);

    expect($outcome->correct)->toBeTrue()->and($outcome->score)->toBe(1000 + intdiv(1000 * (120_000 - 20_000), 120_000))
        ->and($outcome->durationMs)->toBe(20000);
});

test('defense in depth: finish() itself refuses a completed session, a later duplicate session and another user, whatever the caller checked', function () {
    $event = e17Event();
    $user = e16User();
    $other = e16User();
    $puzzle = $event->puzzle;
    $context = \App\GameEngine\Support\AttemptContext::competitiveEvent($event->id);
    $runs = app(CompetitiveRunService::class);
    $mk = fn (User $u, array $extra = []) => GameSession::create(['user_id' => $u->id, 'puzzle_id' => $puzzle->id, 'context_type' => 'competitive_event', 'context_id' => $event->id, 'status' => 'active',
        'started_at' => now(), 'server_state' => ['competitive' => true, 'started_ms' => (int) now()->getPreciseTimestamp(3), 'start_token' => 'x']] + $extra);

    $first = $mk($user);
    $duplicate = $mk($user); // جلسة لاحقة بنفس السياق (سباق بدءين)

    expect(fn () => $runs->finish($user, $duplicate, $puzzle, $context, ['answer' => E17_ANSWER]))->toThrow(CompetitiveException::class)   // ليست الأقدم
        ->and(fn () => $runs->finish($other, $first, $puzzle, $context, ['answer' => E17_ANSWER]))->toThrow(CompetitiveException::class);   // ليست له

    \Illuminate\Support\Facades\DB::transaction(fn () => $runs->finish($user, $first, $puzzle, $context, ['answer' => E17_ANSWER]));
    expect(fn () => $runs->finish($user, $first->refresh(), $puzzle, $context, ['answer' => E17_ANSWER]))->toThrow(CompetitiveException::class); // مكتملة
});

test('defense in depth: even if the pre-check were bypassed, the in-transaction window re-check rejects a late result', function () {
    $event = e17Event(['ends_at' => now()->addMinutes(10)]);
    $user = e16User();
    e17Events()->register($user, $event);
    e17Events()->start($user, $event);
    e17Forward(11 * 60_000);

    $bypassed = new class(app(CompetitiveRunService::class)) extends CompetitiveEventService
    {
        protected function assertCanPlay(User $user, CompetitiveEvent $event): void {} // الفحص المسبق مُعطَّل
    };

    expect(fn () => $bypassed->submit($user, $event, ['answer' => E17_ANSWER]))->toThrow(CompetitiveException::class, 'انتهت المنافسة أو لم تعد تقبل نتائج.')
        ->and(CompetitiveEventResult::count())->toBe(0)->and(GameSession::where('user_id', $user->id)->value('status'))->toBe('active'); // المعاملة تراجعت
});


test('defense in depth: answerOnly() keeps only order, matches and moves even if the validated data carried forged keys', function () {
    $request = new class extends \App\Http\Requests\CompetitiveSubmitRequest
    {
        public function validated($key = null, $default = null)
        {
            $data = ['answer' => 'x', 'submission' => ['order' => [1, 2], 'matches' => [[1, 2]], 'moves' => 3, 'score' => 999, 'winner' => 1, 'elapsed_seconds' => 1, 'start_token' => 't', 'used_hint' => true]];

            return $key === null ? $data : data_get($data, $key, $default);
        }
    };

    expect($request->answerOnly())->toBe(['answer' => 'x', 'submission' => ['order' => [1, 2], 'matches' => [[1, 2]], 'moves' => 3]]);
});
