<?php

use App\Models\Puzzle;
use App\Models\PuzzleAttempt;
use App\Models\QuestDefinition;
use App\Models\User;
use App\Models\UserQuestProgress;
use App\Services\Engagement\QuestService;
use App\Services\PuzzleAttemptService;
use Illuminate\Support\Carbon;

/** E13.1 Final Boundary Patch: نفس اليوم starts_at/ends_at + استمرارية التعطيل وسط الفترة. */
beforeEach(function () {
    $this->quests = app(QuestService::class);
    $this->puzzles = app(PuzzleAttemptService::class);
});

afterEach(function () {
    Carbon::setTestNow();
});

function e133Solve(User $user, PuzzleAttemptService $service): void
{
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح', 'xp_reward' => 0, 'gem_reward' => 0]);
    $service->attempt($user, $puzzle, 'صح');
}

// ===== A/B/C/9/11: Same-day starts_at =====
test('E13.1-FB-A/B/C: a same-day starts_at quest is invisible and non-evaluable before the hour, then works normally after it', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    Carbon::setTestNow(Carbon::parse('2026-10-05 10:00:00', 'UTC'));
    $quest = QuestDefinition::factory()->daily(1)->create(['name' => 'مهمة الساعة 18 الفريدة', 'starts_at' => Carbon::parse('2026-10-05 18:00:00', 'UTC')]);

    $response = $this->actingAs($user)->get(route('quests.show'));
    $response->assertOk()->assertDontSee('مهمة الساعة 18 الفريدة');
    expect(UserQuestProgress::where('quest_definition_id', $quest->id)->count())->toBe(0);

    e133Solve($user, $this->puzzles); // الساعة 10:00 - قبل الموعد فعليًا
    expect(UserQuestProgress::where('quest_definition_id', $quest->id)->count())->toBe(0); // لا Progress إطلاقًا

    Carbon::setTestNow(Carbon::parse('2026-10-05 19:00:00', 'UTC')); // بعد الموعد، نفس اليوم
    e133Solve($user, $this->puzzles);

    $progress = UserQuestProgress::where('quest_definition_id', $quest->id)->first();
    expect($progress)->not->toBeNull()->and($progress->current_value)->toBe(1);
});

// ===== D/E/12: Same-day ends_at =====
test('E13.1-FB-D/E: a same-day ends_at quest counts activity before the deadline then stops counting and disappears after it', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    Carbon::setTestNow(Carbon::parse('2026-10-05 10:00:00', 'UTC'));
    $quest = QuestDefinition::factory()->daily(5)->create(['name' => 'مهمة الساعة 12 الفريدة', 'ends_at' => Carbon::parse('2026-10-05 12:00:00', 'UTC')]);

    e133Solve($user, $this->puzzles); // قبل الموعد
    expect(UserQuestProgress::where('quest_definition_id', $quest->id)->first()->current_value)->toBe(1);

    Carbon::setTestNow(Carbon::parse('2026-10-05 18:00:00', 'UTC')); // بعد الموعد، نفس اليوم

    $response = $this->actingAs($user)->get(route('quests.show'));
    $response->assertOk()->assertDontSee('مهمة الساعة 12 الفريدة');

    e133Solve($user, $this->puzzles); // محاولة لاحقة بعد الموعد

    expect(UserQuestProgress::where('quest_definition_id', $quest->id)->count())->toBe(1) // لا صف جديد
        ->and(UserQuestProgress::where('quest_definition_id', $quest->id)->first()->current_value)->toBe(1); // لم تزد
});

// ===== F/G/H/13: Mid-period deactivation continuation - الاختبار الأهم =====
test('E13.1-FB-F/G/H (CRITICAL): a user who already started a quest can still complete it after mid-period deactivation', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $quest = QuestDefinition::factory()->daily(2)->withXpReward(30)->create();

    Carbon::setTestNow(Carbon::parse('2026-10-05 10:00:00', 'UTC'));
    e133Solve($user, $this->puzzles);
    expect(UserQuestProgress::where('quest_definition_id', $quest->id)->first()->current_value)->toBe(1);

    Carbon::setTestNow(Carbon::parse('2026-10-05 11:00:00', 'UTC'));
    $quest->update(['is_active' => false]);

    $response = $this->actingAs($user)->get(route('quests.show'));
    $response->assertOk()->assertSee($quest->name); // ما زالت ظاهرة - استمرارية

    Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00', 'UTC'));
    e133Solve($user, $this->puzzles); // الحل الثاني بعد التعطيل

    $progress = UserQuestProgress::where('quest_definition_id', $quest->id)->first();
    expect($progress->current_value)->toBe(2)
        ->and($progress->completed_at)->not->toBeNull()
        ->and($progress->reward_granted_at)->not->toBeNull()
        ->and($user->fresh()->playerProgression->total_xp)->toBe(30);
});

// ===== I/14: Other user cannot start a now-inactive quest =====
test('E13.1-FB-I: a different user who never started the quest cannot begin it after deactivation', function () {
    $userA = User::factory()->create(['email_verified_at' => now()]);
    $userB = User::factory()->create(['email_verified_at' => now()]);
    $quest = QuestDefinition::factory()->daily(2)->create();

    e133Solve($userA, $this->puzzles); // A يبدأ
    $quest->update(['is_active' => false]);

    $response = $this->actingAs($userB)->get(route('quests.show'));
    $response->assertOk()->assertDontSee($quest->name);

    e133Solve($userB, $this->puzzles); // B يحاول اللعب بعد التعطيل

    expect(UserQuestProgress::where('user_id', $userB->id)->where('quest_definition_id', $quest->id)->exists())->toBeFalse();
});

// ===== J/15: Next-period regression - inactive quest not assigned in a new period =====
test('E13.1-FB-J: in the next period, a still-inactive quest is not assigned even with prior-period history', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $quest = QuestDefinition::factory()->daily(2)->create();

    Carbon::setTestNow(Carbon::parse('2026-10-05 10:00:00', 'UTC'));
    e133Solve($user, $this->puzzles);
    $quest->update(['is_active' => false]);

    Carbon::setTestNow(Carbon::parse('2026-10-06 10:00:00', 'UTC')); // يوم جديد كليًا
    e133Solve($user, $this->puzzles);

    expect(UserQuestProgress::where('quest_definition_id', $quest->id)->where('period_key', 'daily:2026-10-06')->exists())->toBeFalse();

    $response = $this->actingAs($user)->get(route('quests.show'));
    $response->assertOk()->assertDontSee($quest->name);
});

// ===== K: Late reward recovery regressions remain green =====
test('E13.1-FB-K: late reward recovery still works correctly after this patch (daily, across a real period change)', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $currency = \App\Models\Currency::factory()->create(['is_earnable' => false, 'is_active' => true]);
    $quest = QuestDefinition::factory()->daily(1)->create(['reward_currency_id' => $currency->id, 'reward_currency_amount' => 15]);

    Carbon::setTestNow(Carbon::parse('2026-10-05 10:00:00', 'UTC'));
    e133Solve($user, $this->puzzles);

    Carbon::setTestNow(Carbon::parse('2026-10-20 10:00:00', 'UTC'));
    $currency->update(['is_earnable' => true]);
    $this->quests->recoverPendingRewards($user);

    expect($user->wallets()->where('currency_id', $currency->id)->first()->pending_balance)->toBe(15);
});

// ===== self-healing within/outside window =====
test('E13.1-FB: self-healing repairs progress for a mid-period-deactivated-but-continuing quest via GET /quests', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $quest = QuestDefinition::factory()->daily(2)->create();

    Carbon::setTestNow(Carbon::parse('2026-10-05 10:00:00', 'UTC'));
    e133Solve($user, $this->puzzles); // عبر hook حقيقي - صف موجود فعلًا بقيمة 1
    $quest->update(['is_active' => false]);

    // محاكاة فشل Hook لاحقًا - PuzzleAttempt صحيحة ثانية بلا استدعاء مباشر للخدمة.
    PuzzleAttempt::create([
        'user_id' => $user->id, 'puzzle_id' => Puzzle::factory()->create()->id, 'attempt_number' => 1,
        'is_correct' => true, 'used_hint' => false, 'submission_snapshot' => [], 'context_type' => 'none', 'context_id' => 0,
    ]);

    $this->actingAs($user)->get(route('quests.show'))->assertOk();

    expect(UserQuestProgress::where('quest_definition_id', $quest->id)->first()->current_value)->toBe(2);
});
