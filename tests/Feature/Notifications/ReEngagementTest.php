<?php

require_once __DIR__.'/NotificationTestHelpers.php';

use App\Models\PlayerStreak;
use App\Models\QuestDefinition;
use App\Models\User;
use App\Models\UserQuestProgress;
use App\Services\Engagement\QuestService;
use App\Services\Notifications\NotificationPreferenceService;
use App\Services\Notifications\NotificationType;
use App\Services\Notifications\ReEngagementService;
use App\Services\PlatformSettingsService;
use Carbon\Carbon;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-10 19:00:00', 'UTC')); // الساعة 19 ≥ نافذة التحذير الافتراضية (18)
    $this->settings = app(PlatformSettingsService::class);
    $this->service = app(ReEngagementService::class);
    $this->today = '2026-10-10';
    $this->yesterday = '2026-10-09';
    QuestDefinition::factory()->daily()->create(['is_active' => true]);
});

afterEach(fn () => Carbon::setTestNow());

function e15Count(string $typeKey): int
{
    return DatabaseNotification::where('type_key', $typeKey)->count();
}

function e15At(string $datetime): void
{
    Carbon::setTestNow(Carbon::parse($datetime, 'UTC'));
}

// ============================ تحذير السلسلة ============================

test('E15-I/200: active streak + no qualifying activity today + inside the warning window -> exactly one notification', function () {
    $user = User::factory()->create();
    e15Streak($user, 5, $this->yesterday);

    $stats = $this->service->dispatchStreakRisk();

    $row = $user->notifications()->first();

    expect($stats['created'])->toBe(1)
        ->and(e15Count('streak_at_risk'))->toBe(1)
        ->and($row->idempotency_key)->toBe("streak-risk:{$user->id}:2026-10-10")
        ->and($row->data['body'])->toContain('5')
        ->and($row->data['action_route'])->toBe('puzzles.index');
});

test('E15-J/201/58: no reminder when the user already qualified today', function () {
    $user = User::factory()->create();
    e15Streak($user, 8, $this->today);

    $this->service->dispatchStreakRisk();

    expect(e15Count('streak_at_risk'))->toBe(0);
});

test('E15-K/202/59: no reminder for users without a meaningful streak (zero, below the minimum, or already broken)', function () {
    e15Streak(User::factory()->create(), 0, $this->yesterday);
    e15Streak(User::factory()->create(), 1, $this->yesterday);          // أقل من الحد الأدنى (2)
    e15Streak(User::factory()->create(), 9, '2026-10-07');               // انقطعت فعلًا (آخر نشاط قبل أمس)
    User::factory()->create();                                           // لا صف سلسلة أصلًا

    $this->service->dispatchStreakRisk();

    expect(e15Count('streak_at_risk'))->toBe(0);
});

test('E15-56: the warning window is configurable and boundary-exact (17:59 no, 18:00 yes)', function () {
    $user = User::factory()->create();
    e15Streak($user, 4, $this->yesterday);

    e15At('2026-10-10 17:59:59');
    expect($this->service->dispatchStreakRisk())->toMatchArray(['created' => 0, 'skipped' => 'before_window']);

    e15At('2026-10-10 18:00:00');
    expect($this->service->dispatchStreakRisk()['created'])->toBe(1);

    $this->settings->set('notifications', 'streak_warning_hour', 21);
    e15At('2026-10-11 20:00:00');
    PlayerStreak::where('user_id', $user->id)->update(['last_active_date' => '2026-10-10']);
    expect($this->service->dispatchStreakRisk()['skipped'])->toBe('before_window');
});

test('E15-L/203/80: running the scheduler repeatedly the same day creates one notification (idempotency independent of the budget)', function () {
    $this->settings->set('notifications', 'max_reengagement_per_day', 5); // الميزانية لا تحمي هنا: المفتاح الدلالي وحده يحمي
    $user = User::factory()->create();
    e15Streak($user, 3, $this->yesterday);

    $first = $this->service->dispatchStreakRisk();
    $second = $this->service->dispatchStreakRisk();
    $this->service->dispatchStreakRisk();

    expect($first['created'])->toBe(1)->and($second['created'])->toBe(0)->and($second['duplicate'])->toBe(1)
        ->and(e15Count('streak_at_risk'))->toBe(1);
});

test('E15-M/204: the next day produces a new reminder if the user is still eligible', function () {
    $user = User::factory()->create();
    e15Streak($user, 5, $this->yesterday);
    $this->service->dispatchStreakRisk();

    PlayerStreak::where('user_id', $user->id)->update(['current_streak' => 6, 'last_active_date' => $this->today]);
    e15At('2026-10-11 19:00:00');
    $this->service->dispatchStreakRisk();

    expect($user->notifications()->pluck('idempotency_key')->sort()->values()->all())
        ->toBe(["streak-risk:{$user->id}:2026-10-10", "streak-risk:{$user->id}:2026-10-11"]);
});

test('E15-E/210/195: opt-out, the global switch, the feature flag and frozen accounts each suppress the warning', function () {
    $opted = User::factory()->create();
    e15Streak($opted, 5, $this->yesterday);
    app(NotificationPreferenceService::class)->update($opted, ['streak_enabled' => false]);

    $frozen = User::factory()->create(['is_frozen' => true]);
    e15Streak($frozen, 5, $this->yesterday);

    $this->service->dispatchStreakRisk();
    expect(e15Count('streak_at_risk'))->toBe(0);

    $normal = User::factory()->create();
    e15Streak($normal, 5, $this->yesterday);

    $this->settings->set('notifications', 'notifications_enabled', false);
    expect($this->service->dispatchStreakRisk()['skipped'])->toBe('disabled');
    $this->settings->set('notifications', 'notifications_enabled', true);

    $this->settings->set('notifications', 'streak_warning_enabled', false);
    expect($this->service->dispatchStreakRisk()['skipped'])->toBe('disabled');
    $this->settings->set('notifications', 'streak_warning_enabled', true);

    expect(e15Count('streak_at_risk'))->toBe(0);
    $this->service->dispatchStreakRisk();
    expect($normal->notifications()->count())->toBe(1);
});

// ============================ تذكير مهام اليوم ============================

test('E15-205/92: a lapsed player gets ONE daily-quests reminder per daily period, once even if the scheduler repeats', function () {
    e15At('2026-10-10 11:00:00');
    $this->settings->set('notifications', 'max_reengagement_per_day', 5);
    $user = User::factory()->create();
    e15Streak($user, 1, '2026-10-07');

    $this->service->dispatchDailyReminders();
    $second = $this->service->dispatchDailyReminders();

    $row = $user->notifications()->first();

    expect(e15Count('daily_quests_available'))->toBe(1)
        ->and($row->idempotency_key)->toBe("daily-quests:{$user->id}:daily:2026-10-10")
        ->and($row->data['action_route'])->toBe('quests.show')
        ->and($second['duplicate'])->toBe(1);

    e15At('2026-10-11 11:00:00');
    $this->service->dispatchDailyReminders();
    expect(e15Count('daily_quests_available'))->toBe(2); // الفترة التالية = تذكير جديد
});

test('E15-51/92: no daily reminder for someone who already played today, never played, or has not played for weeks', function () {
    e15At('2026-10-10 11:00:00');
    e15Streak(User::factory()->create(), 1, $this->today);          // لعب اليوم
    User::factory()->create();                                       // لم يلعب قط (لا صف)
    e15Streak(User::factory()->create(), 1, '2026-09-10');          // غاب شهرًا (خارج نافذة النشاط الحديث)

    $this->service->dispatchDailyReminders();

    expect(e15Count('daily_quests_available'))->toBe(0);
});

test('E15-92/49: the reminder needs actually-current daily quests (inactive or expired ones do not count)', function () {
    e15At('2026-10-10 11:00:00');
    QuestDefinition::query()->update(['is_active' => false]);
    e15Streak(User::factory()->create(), 1, '2026-10-07');

    expect($this->service->dispatchDailyReminders()['skipped'])->toBe('no_daily_quests');

    QuestDefinition::query()->update(['is_active' => true, 'ends_at' => now()->subHour()]);
    expect($this->service->dispatchDailyReminders()['skipped'])->toBe('no_daily_quests')
        ->and(e15Count('daily_quests_available'))->toBe(0)
        ->and(app(QuestService::class)->hasCurrentDailyQuests())->toBeFalse();

    QuestDefinition::query()->update(['ends_at' => null]);
    expect(app(QuestService::class)->hasCurrentDailyQuests())->toBeTrue();
});

test('E15-89/156: the daily reminder only runs inside [daily_reminder_hour, streak_warning_hour)', function () {
    e15Streak(User::factory()->create(), 1, '2026-10-07');

    foreach (['2026-10-10 09:59:00', '2026-10-10 18:00:00', '2026-10-10 23:00:00'] as $time) {
        e15At($time);
        expect($this->service->dispatchDailyReminders()['skipped'])->toBe('outside_window');
    }

    e15At('2026-10-10 10:00:00');
    expect($this->service->dispatchDailyReminders()['created'])->toBe(1);
});

test('E15-161/162/157/208: priority - a player with a live streak gets the streak warning (evening) and NEVER the daily reminder', function () {
    $this->settings->set('notifications', 'max_reengagement_per_day', 2); // حتى مع ميزانية 2: لا تكرار للغرض نفسه
    $user = User::factory()->create();
    e15Streak($user, 6, $this->yesterday);

    e15At('2026-10-10 11:00:00');
    $this->service->dispatchDailyReminders();
    expect(e15Count('daily_quests_available'))->toBe(0);

    e15At('2026-10-10 19:00:00');
    $this->service->dispatchStreakRisk();
    e15At('2026-10-10 15:00:00');
    $this->service->dispatchDailyReminders();

    expect($user->notifications()->pluck('type_key')->all())->toBe(['streak_at_risk']);
});

test('E15-162: once a streak warning exists today, a lapsed-audience daily reminder is suppressed even with spare budget', function () {
    e15At('2026-10-10 11:00:00');
    $this->settings->set('notifications', 'max_reengagement_per_day', 5);
    $user = User::factory()->create();
    e15Streak($user, 1, '2026-10-07');
    e15Note($user, ['type' => NotificationType::StreakAtRisk, 'key' => 'streak-risk:x:today']);

    $this->service->dispatchDailyReminders();

    expect(e15Count('daily_quests_available'))->toBe(0);
});

test('E15-N/207/158: the daily re-engagement budget is respected per user', function () {
    e15At('2026-10-10 11:00:00');
    $this->settings->set('notifications', 'max_reengagement_per_day', 2);

    [$full, $one, $none] = [User::factory()->create(), User::factory()->create(), User::factory()->create()];
    foreach ([$full, $one, $none] as $u) {
        e15Streak($u, 1, '2026-10-07');
    }
    e15Note($full, ['type' => NotificationType::DailyQuestsAvailable, 'key' => 'old-1']);
    e15Note($full, ['type' => NotificationType::DailyQuestsAvailable, 'key' => 'old-2']);
    e15Note($one, ['type' => NotificationType::DailyQuestsAvailable, 'key' => 'old-3']);

    $stats = $this->service->dispatchDailyReminders();

    expect($full->notifications()->count())->toBe(2)   // استنفد ميزانيته: لا جديد
        ->and($one->notifications()->count())->toBe(2) // 1 + 1 = 2 مسموح
        ->and($none->notifications()->count())->toBe(1)
        ->and($stats['suppressed'])->toBe(1);

    $this->settings->set('notifications', 'max_reengagement_per_day', 0);
    $this->service->dispatchDailyReminders();
    expect(DatabaseNotification::count())->toBe(5); // ميزانية 0 = لا تذكيرات عودة إطلاقًا
});

test('E15-159: achievements and security notices do NOT consume the re-engagement budget', function () {
    e15At('2026-10-10 11:00:00');
    $user = User::factory()->create();
    e15Streak($user, 1, '2026-10-07');
    e15Note($user, ['type' => NotificationType::AchievementUnlocked, 'key' => 'a']);
    e15Note($user, ['type' => NotificationType::SecurityPasswordReset, 'key' => 's']);

    $this->service->dispatchDailyReminders();

    expect(e15Count('daily_quests_available'))->toBe(1);
});

// ============================ لا أثر جانبي على اللعب ============================

test('E15-O/206/93/94: the scheduler creates NO gameplay state - no quest rows, no streak rows, no streak mutation', function () {
    e15At('2026-10-10 11:00:00');
    $lapsed = User::factory()->create();
    $streaker = User::factory()->create();
    $never = User::factory()->create();
    e15Streak($lapsed, 1, '2026-10-07');
    e15Streak($streaker, 5, $this->yesterday);

    $streakSnapshot = PlayerStreak::orderBy('id')->get(['user_id', 'current_streak', 'longest_streak', 'last_active_date', 'updated_at'])->toArray();
    $before = [UserQuestProgress::count(), PlayerStreak::count(), DB::table('xp_transactions')->count(), DB::table('currency_transactions')->count()];

    $this->service->run();
    e15At('2026-10-10 19:00:00');
    $this->service->run();

    expect([UserQuestProgress::count(), PlayerStreak::count(), DB::table('xp_transactions')->count(), DB::table('currency_transactions')->count()])->toBe($before)
        ->and(PlayerStreak::orderBy('id')->get(['user_id', 'current_streak', 'longest_streak', 'last_active_date', 'updated_at'])->toArray())->toBe($streakSnapshot)
        ->and(PlayerStreak::where('user_id', $never->id)->exists())->toBeFalse()
        ->and(UserQuestProgress::where('user_id', $never->id)->exists())->toBeFalse();
});

test('E15-81/82/83: the scheduler works in bounded chunks with one batched user load per chunk (no full load, no N+1)', function () {
    e15At('2026-10-10 11:00:00');
    config(['player_notifications.chunk_size' => 7]);
    $this->settings->set('notifications', 'max_reengagement_per_day', 5);

    foreach (User::factory()->count(30)->create() as $user) {
        e15Streak($user, 1, '2026-10-07');
    }

    $userLoads = 0;
    DB::listen(function ($q) use (&$userLoads) {
        if (str_contains($q->sql, 'select * from "users" where "id" in')) {
            $userLoads++;
        }
    });

    $stats = $this->service->dispatchDailyReminders();

    expect($stats['created'])->toBe(30)->and(e15Count('daily_quests_available'))->toBe(30)
        ->and($userLoads)->toBeLessThanOrEqual(5);
});

test('the artisan command is idempotent, exits 0, and reports per-phase stats', function () {
    $user = User::factory()->create();
    e15Streak($user, 4, $this->yesterday);

    $this->artisan('notifications:dispatch-reengagement')->assertExitCode(0)->expectsOutputToContain('streak_risk: candidates=1 created=1');
    $this->artisan('notifications:dispatch-reengagement')->assertExitCode(0);

    expect(e15Count('streak_at_risk'))->toBe(1);
});

test('E15-168/170: without the scheduler nothing is corrupted - gameplay state is untouched and no notification exists', function () {
    $user = User::factory()->create();
    e15Streak($user, 4, $this->yesterday);

    // لا نشغّل المجدوِل إطلاقًا.
    expect(DatabaseNotification::count())->toBe(0)
        ->and(PlayerStreak::where('user_id', $user->id)->first()->current_streak)->toBe(4);
});
