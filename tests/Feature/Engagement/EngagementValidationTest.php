<?php

use App\Models\Currency;
use App\Models\Puzzle;
use App\Models\PuzzleAttempt;
use App\Models\QuestDefinition;
use App\Models\StoreItem;
use App\Models\User;
use App\Models\UserInventoryItem;
use App\Models\UserQuestProgress;
use App\Models\XpTransaction;
use App\Services\Engagement\QuestPeriodService;
use App\Services\Engagement\QuestService;
use App\Services\PuzzleAttemptService;
use Illuminate\Support\Carbon;

/**
 * E13.1 Part 2 — تحقُّق نهائي صارم. هذه اختبارات إضافية تتجاوز
 * EngagementHardeningTest (Part 1) بدقة أكبر: هوية السجل القديم صراحةً،
 * لا تغيير بعدد PuzzleAttempt، أسبوعي + حدود ISO Week، GET /quests
 * الفعلية (لا استدعاء خدمة مباشر) لسيناريوهات الصفحة، وفشل جزئي صريح.
 */
beforeEach(function () {
    $this->quests = app(QuestService::class);
    $this->periods = app(QuestPeriodService::class);
    $this->puzzles = app(PuzzleAttemptService::class);
    $this->user = User::factory()->create(['email_verified_at' => now()]);
});

afterEach(function () {
    Carbon::setTestNow();
});

function e132Solve(User $user, PuzzleAttemptService $service): PuzzleAttempt
{
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح', 'xp_reward' => 0, 'gem_reward' => 0]);
    $service->attempt($user, $puzzle, 'صح');

    return $user->puzzleAttempts()->latest()->first();
}

// ===== 3/4/5/6: Daily late reward - real period boundary + identity + no new gameplay =====
test('E13.1-V3/4/5/6: daily late reward crosses a REAL period boundary, preserves old progress identity, requires zero new gameplay', function () {
    $currency = Currency::factory()->create(['is_earnable' => false, 'is_active' => true]);
    $quest = QuestDefinition::factory()->daily(1)->withXpReward(12)->create(['reward_currency_id' => $currency->id, 'reward_currency_amount' => 22]);

    Carbon::setTestNow(Carbon::parse('2026-10-05 10:00:00', 'UTC'));
    e132Solve($this->user, $this->puzzles);

    $oldProgress = UserQuestProgress::where('user_id', $this->user->id)->where('quest_definition_id', $quest->id)->first();
    $oldProgressId = $oldProgress->id;
    $oldPeriodKey = $oldProgress->period_key;
    $attemptCountBefore = PuzzleAttempt::where('user_id', $this->user->id)->count();

    expect($oldProgress->completed_at)->not->toBeNull()->and($oldProgress->reward_granted_at)->toBeNull();

    // انتقال زمني فعلي حقيقي ليوم مختلف تمامًا.
    Carbon::setTestNow(Carbon::parse('2026-10-06 10:00:00', 'UTC'));
    $newPeriodKey = $this->periods->dailyContext()->periodKey;
    expect($newPeriodKey)->not->toBe($oldPeriodKey); // بند 6: الفترة تغيَّرت فعليًا، لا افتراضًا بالاسم فقط

    $currency->update(['is_earnable' => true]); // إصلاح سبب الفشل فقط - بلا أي لعب جديد

    $this->quests->recoverPendingRewards($this->user);

    $attemptCountAfter = PuzzleAttempt::where('user_id', $this->user->id)->count();

    expect($attemptCountAfter)->toBe($attemptCountBefore) // بند 5: صفر لعب جديد
        ->and(UserQuestProgress::find($oldProgressId)->reward_granted_at)->not->toBeNull() // بند 4: نفس السجل القديم بالذات
        ->and(UserQuestProgress::find($oldProgressId)->period_key)->toBe($oldPeriodKey) // لم تتغيَّر فترته
        ->and(UserQuestProgress::find($oldProgressId)->current_value)->toBe(1) // لم تُفتَح من جديد
        ->and($this->user->fresh()->playerProgression->total_xp)->toBe(12)
        ->and($this->user->wallets()->where('currency_id', $currency->id)->first()->pending_balance)->toBe(22);
});

// ===== 7/8: Weekly late reward + ISO week year boundary =====
test('E13.1-V7/8: weekly late reward crosses a REAL week boundary at the ISO year edge with no new gameplay', function () {
    $currency = Currency::factory()->create(['is_earnable' => false, 'is_active' => true]);
    $quest = QuestDefinition::factory()->weekly(1)->withXpReward(40)->create(['reward_currency_id' => $currency->id, 'reward_currency_amount' => 60]);

    // 2026-12-28 الاثنين = أسبوع ISO 53 لسنة 2026 (مُتحقَّق فعليًا بحساب PHP).
    Carbon::setTestNow(Carbon::parse('2026-12-28 10:00:00', 'UTC'));
    e132Solve($this->user, $this->puzzles);

    $oldProgress = UserQuestProgress::where('user_id', $this->user->id)->where('quest_definition_id', $quest->id)->first();
    $oldProgressId = $oldProgress->id;
    expect($oldProgress->period_key)->toBe('weekly:2026-W53')
        ->and($oldProgress->reward_granted_at)->toBeNull();

    // 2027-01-04 الاثنين = أسبوع ISO 01 لسنة 2027 - حدود سنة حقيقية (مُتحقَّق فعليًا).
    Carbon::setTestNow(Carbon::parse('2027-01-04 10:00:00', 'UTC'));
    expect($this->periods->weeklyContext()->periodKey)->toBe('weekly:2027-W01');

    $currency->update(['is_earnable' => true]);
    $this->quests->recoverPendingRewards($this->user);

    expect(UserQuestProgress::find($oldProgressId)->reward_granted_at)->not->toBeNull()
        ->and($this->user->fresh()->playerProgression->total_xp)->toBe(40)
        ->and($this->user->wallets()->where('currency_id', $currency->id)->first()->pending_balance)->toBe(60);
});

// ===== 9/10: Retry after deactivation AND after ends_at specifically =====
test('E13.1-V9: a completed-but-unrewarded quest still grants its reward after the definition is deactivated', function () {
    $currency = Currency::factory()->create(['is_earnable' => false, 'is_active' => true]);
    $quest = QuestDefinition::factory()->daily(1)->create(['reward_currency_id' => $currency->id, 'reward_currency_amount' => 18]);

    e132Solve($this->user, $this->puzzles);
    $quest->update(['is_active' => false]);
    $currency->update(['is_earnable' => true]);

    $this->quests->recoverPendingRewards($this->user);

    expect($this->user->wallets()->where('currency_id', $currency->id)->first()->pending_balance)->toBe(18);
});

test('E13.1-V10: a completed-but-unrewarded quest still grants its reward after real time passes beyond ends_at', function () {
    $currency = Currency::factory()->create(['is_earnable' => false, 'is_active' => true]);
    Carbon::setTestNow(Carbon::parse('2026-10-05 10:00:00', 'UTC'));
    $quest = QuestDefinition::factory()->daily(1)->create([
        'reward_currency_id' => $currency->id, 'reward_currency_amount' => 27,
        'ends_at' => Carbon::parse('2026-10-06 00:00:00', 'UTC'),
    ]);
    e132Solve($this->user, $this->puzzles); // قبل ends_at - تكتمل بنجاح

    Carbon::setTestNow(Carbon::parse('2026-10-15 10:00:00', 'UTC')); // بعيدًا بعد ends_at فعليًا
    $currency->update(['is_earnable' => true]);
    $this->quests->recoverPendingRewards($this->user);

    expect($this->user->wallets()->where('currency_id', $currency->id)->first()->pending_balance)->toBe(27);
});

// ===== 11: Expired incomplete quest never completes from later activity =====
test('E13.1-V11: an incomplete quest whose period has expired does NOT complete or reward from a later-period activity', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 10:00:00', 'UTC'));
    $quest = QuestDefinition::factory()->daily(3)->withXpReward(50)->create();
    e132Solve($this->user, $this->puzzles); // 1/3 فقط - لم تكتمل

    $oldProgress = UserQuestProgress::where('user_id', $this->user->id)->where('quest_definition_id', $quest->id)->first();
    expect($oldProgress->completed_at)->toBeNull();

    Carbon::setTestNow(Carbon::parse('2026-10-06 10:00:00', 'UTC'));
    e132Solve($this->user, $this->puzzles);
    e132Solve($this->user, $this->puzzles);
    e132Solve($this->user, $this->puzzles); // 3 حلول بفترة جديدة كليًا

    expect($oldProgress->fresh()->completed_at)->toBeNull() // السجل القديم يبقى غير مكتمل للأبد
        ->and($oldProgress->fresh()->current_value)->toBe(1) // لم يتغيَّر
        ->and($oldProgress->fresh()->reward_granted_at)->toBeNull();
});

// ===== 12/13: Deactivation - explicit Day1->Day2, no new assignment, historical doesn't keep it alive =====
test('E13.1-V12/13: historical progress from day 1 does not keep a deactivated quest alive or assignable on day 2', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 10:00:00', 'UTC'));
    $quest = QuestDefinition::factory()->daily(5)->create();
    e132Solve($this->user, $this->puzzles);
    expect(UserQuestProgress::where('quest_definition_id', $quest->id)->count())->toBe(1);

    $quest->update(['is_active' => false]);

    Carbon::setTestNow(Carbon::parse('2026-10-06 10:00:00', 'UTC'));
    $response = $this->actingAs($this->user)->get(route('quests.show'));
    $response->assertOk();

    expect(UserQuestProgress::where('quest_definition_id', $quest->id)->count())->toBe(1) // لا صف جديد
        ->and(UserQuestProgress::where('quest_definition_id', $quest->id)->where('period_key', 'daily:2026-10-06')->exists())->toBeFalse()
        ->and($response->getContent())->not->toContain($quest->name); // لا تظهر بالصفحة
});

// ===== 15/16: Future quest - via actual GET /quests =====
test('E13.1-V15/16: a future quest (starts_at tomorrow) does not appear on /quests and creates no progress row', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 10:00:00', 'UTC'));
    $quest = QuestDefinition::factory()->daily(1)->create(['name' => 'مهمة مستقبلية فريدة', 'starts_at' => Carbon::parse('2026-10-06 00:00:00', 'UTC')]);

    $response = $this->actingAs($this->user)->get(route('quests.show'));

    $response->assertOk()->assertDontSee('مهمة مستقبلية فريدة');
    expect(UserQuestProgress::where('quest_definition_id', $quest->id)->exists())->toBeFalse();

    // الحدود: نشاط قبل starts_at لا يُحتسَب، بعدها يُحتسَب.
    e132Solve($this->user, $this->puzzles); // قبل starts_at (نفس اليوم 5 أكتوبر، starts_at=بداية 6 أكتوبر)
    expect(UserQuestProgress::where('quest_definition_id', $quest->id)->exists())->toBeFalse();

    Carbon::setTestNow(Carbon::parse('2026-10-06 10:00:00', 'UTC'));
    e132Solve($this->user, $this->puzzles); // بعد starts_at فعليًا
    $progress = UserQuestProgress::where('quest_definition_id', $quest->id)->first();
    expect($progress->current_value)->toBe(1);
});

// ===== 17/18: Ended quest - via actual GET /quests + boundary =====
test('E13.1-V17/18: an ended quest (ends_at yesterday) does not appear as current and creates no new progress', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 10:00:00', 'UTC'));
    $quest = QuestDefinition::factory()->daily(5)->create(['name' => 'مهمة منتهية فريدة', 'ends_at' => Carbon::parse('2026-10-04 23:59:59', 'UTC')]);

    $response = $this->actingAs($this->user)->get(route('quests.show'));
    $response->assertOk()->assertDontSee('مهمة منتهية فريدة');

    e132Solve($this->user, $this->puzzles); // بعد ends_at فعليًا - لا يُحتسَب
    expect(UserQuestProgress::where('quest_definition_id', $quest->id)->exists())->toBeFalse();
});

// ===== 19/20/21: GET /quests ineligible-row behavior + self-healing eligible/ineligible =====
test('E13.1-V19: GET /quests never creates a progress row for inactive, future, or expired quests, but may for an eligible one', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 10:00:00', 'UTC'));
    $inactive = QuestDefinition::factory()->daily(1)->create(['is_active' => false]);
    $future = QuestDefinition::factory()->daily(1)->create(['starts_at' => Carbon::parse('2026-11-01', 'UTC')]);
    $expired = QuestDefinition::factory()->daily(1)->create(['ends_at' => Carbon::parse('2026-01-01', 'UTC')]);
    $eligible = QuestDefinition::factory()->daily(1)->create();

    $this->actingAs($this->user)->get(route('quests.show'))->assertOk();

    expect(UserQuestProgress::where('quest_definition_id', $inactive->id)->exists())->toBeFalse()
        ->and(UserQuestProgress::where('quest_definition_id', $future->id)->exists())->toBeFalse()
        ->and(UserQuestProgress::where('quest_definition_id', $expired->id)->exists())->toBeFalse()
        ->and(UserQuestProgress::where('quest_definition_id', $eligible->id)->exists())->toBeTrue(); // صفرية، لكن مسموحة لمهمة مؤهَّلة
});

test('E13.1-V20: self-healing via GET /quests repairs progress for an eligible quest when the hook never ran', function () {
    $quest = QuestDefinition::factory()->daily(2)->create();
    PuzzleAttempt::create([
        'user_id' => $this->user->id, 'puzzle_id' => Puzzle::factory()->create()->id, 'attempt_number' => 1,
        'is_correct' => true, 'used_hint' => false, 'submission_snapshot' => [], 'context_type' => 'none', 'context_id' => 0,
    ]);

    expect(UserQuestProgress::where('quest_definition_id', $quest->id)->exists())->toBeFalse();

    $this->actingAs($this->user)->get(route('quests.show'))->assertOk();

    expect(UserQuestProgress::where('quest_definition_id', $quest->id)->first()->current_value)->toBe(1);
});

test('E13.1-V21: self-healing via GET /quests does NOT resurrect an inactive quest into a new assignment', function () {
    $quest = QuestDefinition::factory()->daily(2)->create(['is_active' => false]);
    PuzzleAttempt::create([
        'user_id' => $this->user->id, 'puzzle_id' => Puzzle::factory()->create()->id, 'attempt_number' => 1,
        'is_correct' => true, 'used_hint' => false, 'submission_snapshot' => [], 'context_type' => 'none', 'context_id' => 0,
    ]);

    $this->actingAs($this->user)->get(route('quests.show'))->assertOk();

    expect(UserQuestProgress::where('quest_definition_id', $quest->id)->exists())->toBeFalse();
});

// ===== 23/24/25: Reward replay per component + double evaluation + page-sync-then-gameplay scenario =====
test('E13.1-V23: reward replay - XP, currency, inventory, and entitlement each apply exactly once across many recovery calls', function () {
    $currency = Currency::where('is_earnable', true)->first();
    $item = StoreItem::factory()->cosmeticBadge()->create();
    $entitlementItem = StoreItem::factory()->entitlement('quest.replay.perk')->create();

    $xpQuest = QuestDefinition::factory()->daily(1)->withXpReward(33)->create();
    $currencyQuest = QuestDefinition::factory()->daily(1)->create(['reward_currency_id' => $currency->id, 'reward_currency_amount' => 44]);
    $itemQuest = QuestDefinition::factory()->daily(1)->create(['reward_store_item_id' => $item->id, 'reward_item_quantity' => 1]);
    $entitlementQuest = QuestDefinition::factory()->daily(1)->create(['reward_store_item_id' => $entitlementItem->id]);

    e132Solve($this->user, $this->puzzles);

    foreach (range(1, 8) as $i) {
        $this->quests->recoverPendingRewards($this->user);
    }

    expect($this->user->fresh()->playerProgression->total_xp)->toBe(33)
        ->and(XpTransaction::where('user_id', $this->user->id)->where('type', XpTransaction::TYPE_QUEST_REWARD)->count())->toBe(1)
        ->and($this->user->wallets()->where('currency_id', $currency->id)->first()->pending_balance)->toBe(44)
        ->and(\App\Models\CurrencyTransaction::where('user_id', $this->user->id)->where('currency_id', $currency->id)->where('reason', "quest:{$currencyQuest->internal_key}")->count())->toBe(1)
        ->and(UserInventoryItem::where('user_id', $this->user->id)->where('store_item_id', $item->id)->first()->quantity)->toBe(1)
        ->and(\App\Models\UserEntitlement::where('user_id', $this->user->id)->where('store_item_id', $entitlementItem->id)->count())->toBe(1);
});

test('E13.1-V24: evaluating/syncing the same completion twice in a row never duplicates the reward', function () {
    $quest = QuestDefinition::factory()->daily(1)->withXpReward(19)->create();
    e132Solve($this->user, $this->puzzles);

    $this->quests->syncCurrentQuests($this->user);
    $this->quests->syncCurrentQuests($this->user);

    expect($this->user->fresh()->playerProgression->total_xp)->toBe(19);
});

test('E13.1-V25: a gameplay-driven completion followed by a page-view sync never grants a second reward', function () {
    $quest = QuestDefinition::factory()->daily(1)->withXpReward(21)->create();

    e132Solve($this->user, $this->puzzles); // يمنح المكافأة تلقائيًا عبر الخطاف

    $this->actingAs($this->user)->get(route('quests.show'))->assertOk(); // مزامنة صفحة لاحقة

    expect($this->user->fresh()->playerProgression->total_xp)->toBe(21);
});

// ===== 29: Partial failure explicit - currency fails, everything rolls back, retry succeeds exactly once =====
test('E13.1-V29: XP+Currency bundle fails together when currency cannot be earned, then retry grants exactly one of each', function () {
    $currency = Currency::factory()->create(['is_earnable' => false, 'is_active' => true]);
    $quest = QuestDefinition::factory()->daily(1)->withXpReward(17)->create(['reward_currency_id' => $currency->id, 'reward_currency_amount' => 29]);

    e132Solve($this->user, $this->puzzles);

    // فشل كامل: XP لم تُمنَح أيضًا (ذرّية الحزمة الواحدة - لا حالة جزئية).
    expect($this->user->fresh()->playerProgression)->toBeNull()
        ->and(XpTransaction::where('user_id', $this->user->id)->count())->toBe(0);

    $currency->update(['is_earnable' => true]);
    $this->quests->recoverPendingRewards($this->user);
    $this->quests->recoverPendingRewards($this->user); // محاولة إضافية - لا تضاعف شيئًا

    expect($this->user->fresh()->playerProgression->total_xp)->toBe(17)
        ->and(XpTransaction::where('user_id', $this->user->id)->where('type', XpTransaction::TYPE_QUEST_REWARD)->count())->toBe(1)
        ->and(\App\Models\CurrencyTransaction::where('user_id', $this->user->id)->where('currency_id', $currency->id)->count())->toBe(1)
        ->and($this->user->wallets()->where('currency_id', $currency->id)->first()->pending_balance)->toBe(29);
});

// ===== 31/32/33: Currency pending, no fake purchase, manual item still rejected =====
test('E13.1-V31/32: quest currency reward enters pending (not available) and grants zero StorePurchase', function () {
    $currency = Currency::where('is_earnable', true)->first();
    $item = StoreItem::factory()->cosmeticBadge()->create();
    QuestDefinition::factory()->daily(1)->create([
        'reward_currency_id' => $currency->id, 'reward_currency_amount' => 8,
        'reward_store_item_id' => $item->id, 'reward_item_quantity' => 1,
    ]);

    e132Solve($this->user, $this->puzzles);

    $wallet = $this->user->wallets()->where('currency_id', $currency->id)->first();
    expect($wallet->pending_balance)->toBe(8)
        ->and($wallet->available_balance)->toBe(0)
        ->and(\App\Models\StorePurchase::where('user_id', $this->user->id)->count())->toBe(0);
});

test('E13.1-V33: a manual-fulfillment item is still rejected as an automatic quest reward after E13.1 changes', function () {
    $manualItem = StoreItem::factory()->manual()->create();

    expect(fn () => QuestDefinition::factory()->create(['reward_store_item_id' => $manualItem->id, 'reward_item_quantity' => 1]))
        ->toThrow(\App\Exceptions\StoreItemInvariantViolation::class);
});
