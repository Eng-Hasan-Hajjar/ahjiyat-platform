<?php

require_once __DIR__.'/NotificationTestHelpers.php';

use App\Events\AchievementUnlocked;
use App\Models\Achievement;
use App\Models\CurrencyTransaction;
use App\Models\LevelDefinition;
use App\Models\PuzzleAttempt;
use App\Models\User;
use App\Models\UserAchievementProgress;
use App\Models\XpTransaction;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationPreferenceService;
use App\Services\PlatformSettingsService;
use App\Services\Progression\AchievementService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    LevelDefinition::factory()->first()->create();
    $this->user = User::factory()->create();
    $this->achievements = app(AchievementService::class);
});

function e15Unlockable(User $user, array $override = []): Achievement
{
    PuzzleAttempt::factory()->create(['user_id' => $user->id, 'is_correct' => true]);

    return Achievement::factory()->create($override + ['target_value' => 1, 'xp_reward' => 0]);
}

test('E15-H/197/62: unlocking an achievement creates exactly one notification; re-evaluation keeps it at one', function () {
    $achievement = e15Unlockable($this->user, ['name' => 'متسلق الجبال']);

    $this->achievements->evaluateAchievement($this->user, $achievement);
    $this->achievements->evaluateAchievement($this->user, $achievement);
    $this->achievements->evaluateAchievement($this->user, $achievement);

    $rows = DatabaseNotification::where('type_key', 'achievement_unlocked')->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->idempotency_key)->toBe("achievement:{$this->user->id}:{$achievement->id}")
        ->and($rows->first()->data['title'])->toContain('متسلق الجبال')
        ->and($rows->first()->data['refs']['achievement_id'])->toBe($achievement->id);
});

test('E15-210: a user who opted out of achievement notifications gets none (the achievement still unlocks)', function () {
    app(NotificationPreferenceService::class)->update($this->user, ['achievement_enabled' => false]);
    $achievement = e15Unlockable($this->user);

    $this->achievements->evaluateAchievement($this->user, $achievement);

    expect(UserAchievementProgress::where('user_id', $this->user->id)->whereNotNull('unlocked_at')->count())->toBe(1)
        ->and(DatabaseNotification::count())->toBe(0);
});

test('E15-196: with the global switch off gameplay is unaffected - the achievement unlocks and rewards, no notification', function () {
    app(PlatformSettingsService::class)->set('notifications', 'notifications_enabled', false);
    $achievement = e15Unlockable($this->user);

    $this->achievements->evaluateAchievement($this->user, $achievement);

    $progress = UserAchievementProgress::where('user_id', $this->user->id)->first();

    expect($progress->unlocked_at)->not->toBeNull()->and($progress->reward_granted_at)->not->toBeNull()
        ->and(DatabaseNotification::count())->toBe(0);
});

test('E15-P/212/24/27: a notification pipeline that explodes cannot roll back or break the achievement', function () {
    $this->app->bind(NotificationDispatcher::class, fn () => throw new RuntimeException('notification pipeline is down'));
    $achievement = e15Unlockable($this->user, ['xp_reward' => 10]);

    $this->achievements->evaluateAchievement($this->user, $achievement);

    $progress = UserAchievementProgress::where('user_id', $this->user->id)->first();

    expect($progress->unlocked_at)->not->toBeNull()
        ->and($progress->reward_granted_at)->not->toBeNull()
        ->and(XpTransaction::where('user_id', $this->user->id)->sum('amount'))->toBe(10)
        ->and(DatabaseNotification::count())->toBe(0);
});

test('E15-25/26/149: the event fires only AFTER the unlock commits - a rolled-back transaction leaves no event and no unlock', function () {
    Event::fake([AchievementUnlocked::class]);
    $achievement = e15Unlockable($this->user);

    try {
        DB::transaction(function () use ($achievement) {
            $this->achievements->evaluateAchievement($this->user, $achievement);
            throw new RuntimeException('outer transaction fails after the unlock');
        });
    } catch (RuntimeException) {
    }

    Event::assertNotDispatched(AchievementUnlocked::class);
    expect(UserAchievementProgress::where('user_id', $this->user->id)->count())->toBe(0);

    $this->achievements->evaluateAchievement($this->user, $achievement);
    Event::assertDispatchedTimes(AchievementUnlocked::class, 1);
});

test('E15-Q/215: the achievement notification itself grants nothing beyond the achievement own configured reward', function () {
    $achievement = e15Unlockable($this->user, ['xp_reward' => 0]);

    $this->achievements->evaluateAchievement($this->user, $achievement);

    expect(DatabaseNotification::count())->toBe(1)
        ->and(XpTransaction::where('user_id', $this->user->id)->count())->toBe(0)
        ->and(CurrencyTransaction::where('user_id', $this->user->id)->count())->toBe(0);
});

test('E15-211/70: a password reset creates a mandatory security notice, once per reset, with no secret inside', function () {
    app(PlatformSettingsService::class)->set('notifications', 'notifications_enabled', false);

    $this->user->forceFill(['password' => bcrypt('first-new-password')])->save();
    event(new PasswordReset($this->user));
    event(new PasswordReset($this->user)); // نفس الحدث مُعاد = لا تكرار

    expect(DatabaseNotification::where('type_key', 'security_password_reset')->count())->toBe(1);

    $this->user->forceFill(['password' => bcrypt('second-new-password')])->save();
    event(new PasswordReset($this->user));

    $rows = DatabaseNotification::where('type_key', 'security_password_reset')->get();

    $stored = json_encode($rows->map->data).json_encode($rows->pluck('idempotency_key'));

    expect($rows)->toHaveCount(2)
        ->and($stored)->not->toContain('first-new-password')
        ->and($stored)->not->toContain('second-new-password')
        ->and($stored)->not->toContain('$2y$')                       // لا هاش bcrypt
        ->and($stored)->not->toContain((string) $this->user->fresh()->password) // ولا هاش كلمة المرور الحالية
        ->and($stored)->not->toContain((string) $this->user->remember_token);
});
