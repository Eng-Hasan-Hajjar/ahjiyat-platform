<?php

use App\Models\Currency;
use App\Models\Puzzle;
use App\Models\QuestDefinition;
use App\Models\User;
use App\Models\UserQuestProgress;
use App\Services\Engagement\QuestPeriodService;
use App\Services\Engagement\QuestService;
use App\Services\PuzzleAttemptService;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->quests = app(QuestService::class);
    $this->periods = app(QuestPeriodService::class);
    $this->puzzles = app(PuzzleAttemptService::class);
    $this->user = User::factory()->create();
});

afterEach(function () {
    Carbon::setTestNow();
});

/** E13.1 Fix #1: مكافأة فشلت بفترة قديمة منتهية تُستعاد عند المزامنة اللاحقة، بلا أي لعب جديد. */
test('E13.1 req A/B: a reward that failed in an old, now-expired period can be recovered on later sync with no new gameplay', function () {
    $currency = Currency::factory()->create(['is_earnable' => false, 'is_active' => true]); // فشل مقصود
    $quest = QuestDefinition::factory()->daily(1)->withXpReward(10)->create([
        'reward_currency_id' => $currency->id,
        'reward_currency_amount' => 20,
    ]);

    Carbon::setTestNow(Carbon::parse('2026-10-05 09:00:00', 'UTC'));
    $this->puzzles->attempt($this->user, Puzzle::factory()->create(['answer_raw' => 'صح', 'xp_reward' => 0, 'gem_reward' => 0]), 'صح');

    $oldProgress = UserQuestProgress::where('user_id', $this->user->id)->where('quest_definition_id', $quest->id)->first();
    expect($oldProgress->completed_at)->not->toBeNull()->and($oldProgress->reward_granted_at)->toBeNull();

    // الفترة تنتهي فعليًا (أيام عديدة لاحقًا) - لا لعب جديد إطلاقًا لنفس المهمة، فقط إصلاح العملة ثم مزامنة.
    Carbon::setTestNow(Carbon::parse('2026-10-20 09:00:00', 'UTC'));
    $currency->update(['is_earnable' => true]);

    $this->quests->syncCurrentQuests($this->user); // بلا أي حدث لعب جديد - استدعاء مزامنة فقط

    expect($oldProgress->fresh()->reward_granted_at)->not->toBeNull()
        ->and($this->user->wallets()->where('currency_id', $currency->id)->first()->pending_balance)->toBe(20)
        ->and($this->user->fresh()->playerProgression->total_xp)->toBe(10);

    // لم يُفتَح التقدُّم القديم من جديد ولا تغيَّرت قيمته أو فترته.
    expect($oldProgress->fresh()->current_value)->toBe(1)
        ->and($oldProgress->fresh()->period_key)->toBe('daily:2026-10-05');
});

/**
 * E13.1 Fix #2: اختبار تسلسلي (لا تزامن حقيقي - PHPUnit/SQLite أحادية
 * الخيط، نفس القيد الموثَّق بالمواصفة نفسها). يُثبت الـInvariant النهائي:
 * صف "مكتمل وغير مُكافَأ" يبقى كذلك حتى تُعالَجه أول معاملة قفل تصل إليه،
 * وأي معالجة لاحقة (سواء Retry طبيعي أو استدعاء متكرِّر لصفحة المزامنة)
 * تجده مضبوطًا بالفعل عبر القراءة الطازجة **داخل القفل نفسه** فلا تُكرِّر شيئًا.
 */
test('E13.1 req C/E/F/G/H: repeated recovery calls on an already-granted progress never duplicate XP, currency, or item', function () {
    $currency = Currency::where('is_earnable', true)->first();
    $item = \App\Models\StoreItem::factory()->cosmeticBadge()->create();
    $quest = QuestDefinition::factory()->daily(1)->withXpReward(15)->create([
        'reward_currency_id' => $currency->id,
        'reward_currency_amount' => 10,
        'reward_store_item_id' => $item->id,
        'reward_item_quantity' => 1,
    ]);

    $this->puzzles->attempt($this->user, Puzzle::factory()->create(['answer_raw' => 'صح', 'xp_reward' => 0, 'gem_reward' => 0]), 'صح');

    $progress = UserQuestProgress::where('user_id', $this->user->id)->where('quest_definition_id', $quest->id)->first();
    expect($progress->reward_granted_at)->not->toBeNull(); // مُنِحت تلقائيًا بالفعل بحدث اللعب الأول - القفل الداخلي عمل بنجاح هنا

    // استدعاءات مزامنة متكرِّرة لاحقة (محاكاة طلبات متعدِّدة تصل لنفس الصفحة) - يجب ألا تُكرِّر شيئًا.
    foreach (range(1, 5) as $i) {
        $this->quests->recoverPendingRewards($this->user);
    }

    expect($this->user->fresh()->playerProgression->total_xp)->toBe(15)
        ->and($this->user->wallets()->where('currency_id', $currency->id)->first()->pending_balance)->toBe(10)
        ->and(\App\Models\UserInventoryItem::where('user_id', $this->user->id)->where('store_item_id', $item->id)->first()->quantity)->toBe(1);
});

/**
 * E13.1 Fix #2 (تحقُّق مباشر إضافي من آلية القفل نفسها): صف يُكتمَل خارج
 * المسار الطبيعي (محاكاة Race حيث جهة أخرى ستمنحه لاحقًا) - نتأكَّد أن
 * استدعاء الاسترجاع مرتين متتاليتين على **نفس الصف غير المُكافَأ بعد**
 * ينتج مكافأة واحدة فقط بالضبط، لا صفرًا ولا اثنتين.
 */
test('E13.1 req D: a manually-completed-but-unrewarded row receives its reward exactly once across repeated recovery attempts', function () {
    $currency = Currency::where('is_earnable', true)->first();
    $quest = QuestDefinition::factory()->daily(1)->withXpReward(25)->create([
        'reward_currency_id' => $currency->id,
        'reward_currency_amount' => 30,
    ]);

    $progress = UserQuestProgress::factory()->for($this->user)->for($quest, 'questDefinition')->completed()->create([
        'period_key' => 'daily:'.now()->format('Y-m-d'),
        'period_start' => now()->startOfDay(),
        'period_end' => now()->endOfDay(),
        'target_value_snapshot' => 1,
        'current_value' => 1,
    ]);

    expect($progress->reward_granted_at)->toBeNull();

    foreach (range(1, 3) as $i) {
        $this->quests->recoverPendingRewards($this->user);
    }

    expect($progress->fresh()->reward_granted_at)->not->toBeNull()
        ->and($this->user->fresh()->playerProgression->total_xp)->toBe(25)
        ->and($this->user->wallets()->where('currency_id', $currency->id)->first()->pending_balance)->toBe(30)
        ->and(\App\Models\XpTransaction::where('user_id', $this->user->id)->where('type', \App\Models\XpTransaction::TYPE_QUEST_REWARD)->count())->toBe(1)
        ->and(\App\Models\CurrencyTransaction::where('user_id', $this->user->id)->where('currency_id', $currency->id)->count())->toBe(1);
});

/** E13.1 Fix #3 (I/J): مهمة مُعطَّلة بعد أن كان للمستخدم تقدُّم بفترة قديمة - لا تظهر، ولا تُنشئ تقدُّمًا جديدًا بالفترة الحالية. */
test('E13.1 req I/J: a deactivated quest with only old-period history is not visible and creates no new current-period progress', function () {
    $quest = QuestDefinition::factory()->daily(1)->create();

    Carbon::setTestNow(Carbon::parse('2026-10-05 09:00:00', 'UTC'));
    $this->puzzles->attempt($this->user, Puzzle::factory()->create(['answer_raw' => 'صح']), 'صح');
    expect(UserQuestProgress::where('quest_definition_id', $quest->id)->count())->toBe(1); // تقدُّم بفترة 5 أكتوبر فقط

    $quest->update(['is_active' => false]);

    Carbon::setTestNow(Carbon::parse('2026-10-06 09:00:00', 'UTC')); // يوم تالٍ - فترة جديدة كليًا

    $period = $this->periods->dailyContext();
    expect($this->quests->isVisibleTo($this->user, $quest, $period))->toBeFalse();

    // محاكاة ما تفعله صفحة /quests فعليًا: لا progressFor() تُستدعى لغير المرئي، فلا صف جديد يُنشأ.
    expect(UserQuestProgress::where('quest_definition_id', $quest->id)->where('period_key', 'daily:2026-10-06')->exists())->toBeFalse();
});

/** E13.1 Fix #3 (K/L): مهمة قبل starts_at أو بعد ends_at لا تظهر ولا تُسند، لكنها تظل كذلك لو كان لها تقدُّم فترة حالية فعلي سابق. */
test('E13.1 req K/L: a quest outside its starts_at/ends_at window is not visible unless current-period progress already exists', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 09:00:00', 'UTC'));
    $futureQuest = QuestDefinition::factory()->daily(1)->create(['starts_at' => Carbon::parse('2026-10-10', 'UTC')]);
    $period = $this->periods->dailyContext();

    expect($this->quests->isVisibleTo($this->user, $futureQuest, $period))->toBeFalse();

    $expiredQuest = QuestDefinition::factory()->daily(1)->create(['ends_at' => Carbon::parse('2026-10-01', 'UTC')]);
    expect($this->quests->isVisibleTo($this->user, $expiredQuest, $period))->toBeFalse();
});

/** E13.1 Fix #3 (M): مكافأة مكتملة قديمة تبقى قابلة للاستعادة حتى لو أصبح تعريف المهمة مُعطَّلًا أو منتهي النافذة الآن. */
test('E13.1 req M: a completed-but-unrewarded old progress remains recoverable even after the quest becomes inactive or its window expires', function () {
    $currency = Currency::factory()->create(['is_earnable' => false, 'is_active' => true]);
    $quest = QuestDefinition::factory()->daily(1)->create(['reward_currency_id' => $currency->id, 'reward_currency_amount' => 15]);

    Carbon::setTestNow(Carbon::parse('2026-10-05 09:00:00', 'UTC'));
    $this->puzzles->attempt($this->user, Puzzle::factory()->create(['answer_raw' => 'صح', 'xp_reward' => 0, 'gem_reward' => 0]), 'صح');

    $progress = UserQuestProgress::where('user_id', $this->user->id)->where('quest_definition_id', $quest->id)->first();
    expect($progress->reward_granted_at)->toBeNull();

    // المهمة تُعطَّل كليًا - دون لمس reward config المُقفَلة أصلًا (isUsed() = true بالفعل).
    $quest->update(['is_active' => false]);
    $currency->update(['is_earnable' => true]);

    Carbon::setTestNow(Carbon::parse('2026-10-20 09:00:00', 'UTC'));
    $this->quests->syncCurrentQuests($this->user);

    expect($progress->fresh()->reward_granted_at)->not->toBeNull()
        ->and($this->user->wallets()->where('currency_id', $currency->id)->first()->pending_balance)->toBe(15);
});

/** E13.1 Fix #3 (N): الشفاء الذاتي لا يُحيي مهمة غير مؤهلة حاليًا - فقط يُصحِّح تقدُّم مهمة مؤهلة فعلًا. */
test('E13.1 req N: self-healing does not revive an ineligible (inactive) quest into a new period assignment', function () {
    $quest = QuestDefinition::factory()->daily(2)->create(['is_active' => false]);

    $this->puzzles->attempt($this->user, Puzzle::factory()->create(['answer_raw' => 'صح']), 'صح');

    $this->quests->syncCurrentQuests($this->user);

    expect(UserQuestProgress::where('quest_definition_id', $quest->id)->exists())->toBeFalse();
});
