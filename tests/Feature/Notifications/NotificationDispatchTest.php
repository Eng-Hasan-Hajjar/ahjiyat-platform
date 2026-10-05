<?php

require_once __DIR__.'/NotificationTestHelpers.php';

use App\Models\User;
use App\Services\Notifications\DispatchResult;
use App\Services\Notifications\NotificationAnalyticsService;
use App\Services\Notifications\NotificationCategory;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationPreferenceService;
use App\Services\Notifications\NotificationType;
use App\Services\Notifications\NotificationUrlResolver;
use App\Services\PlatformSettingsService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->dispatcher = app(NotificationDispatcher::class);
    $this->settings = app(PlatformSettingsService::class);
});

test('registry integrity: every type has a category, a real internal route, an icon and non-empty texts', function () {
    foreach (NotificationType::cases() as $type) {
        expect($type->category())->toBeInstanceOf(NotificationCategory::class)
            ->and($type->title(['name' => 'س']))->not->toBeEmpty()
            ->and($type->body(['streak' => 3]))->not->toBeEmpty()
            ->and($type->icon())->not->toBeEmpty()
            ->and($type->allowedRoutes())->toContain($type->defaultRoute());

        foreach ($type->allowedRoutes() as $route) {
            expect(Route::has($route))->toBeTrue("route {$route} must exist");
        }
    }

    // التذكيرات الاستعادية بميزانية يومية: السلسلة والمهام وتذكيرا "ينتهي قريبًا" (الأخيران بأولوية بين الاثنين).
    expect(NotificationType::reEngagementKeys())->toEqualCanonicalizing(['streak_at_risk', 'daily_quests_available', 'season_ending_soon', 'campaign_ending_soon'])
        ->and(NotificationType::reEngagementKeys(NotificationType::SeasonEndingSoon))->toEqualCanonicalizing(['streak_at_risk', 'season_ending_soon', 'campaign_ending_soon'])
        ->and(NotificationType::reEngagementKeys(NotificationType::StreakAtRisk))->toBe(['streak_at_risk'])
        ->and(NotificationCategory::Security->isMandatory())->toBeTrue()
        ->and(NotificationCategory::optional())->toHaveCount(6);
});

test('E15-G/198: the same semantic event dispatched 10 times yields exactly one row', function () {
    $results = [];

    for ($i = 0; $i < 10; $i++) {
        $results[] = $this->dispatcher->dispatch($this->user, NotificationType::AchievementUnlocked, ['name' => 'أ'], 'achievement:1:1');
    }

    expect(DatabaseNotification::count())->toBe(1)
        ->and($results[0])->toBe(DispatchResult::Created)
        ->and(array_unique(array_map(fn ($r) => $r->value, array_slice($results, 1))))->toBe(['duplicate']);
});

test('E15-199/33: the unique constraint itself blocks a duplicate insert (not only application logic)', function () {
    $this->dispatcher->dispatch($this->user, NotificationType::DailyQuestsAvailable, [], 'k-1');

    expect(fn () => $this->user->notifications()->create([
        'id' => (string) Str::uuid(), 'type' => 'x', 'data' => ['a' => 1],
        'type_key' => 'daily_quests_available', 'category' => 'quest', 'idempotency_key' => 'k-1',
    ]))->toThrow(UniqueConstraintViolationException::class);
});

test('the same key for DIFFERENT users is independent', function () {
    $other = User::factory()->create();

    $this->dispatcher->dispatch($this->user, NotificationType::DailyQuestsAvailable, [], 'shared-key');
    $this->dispatcher->dispatch($other, NotificationType::DailyQuestsAvailable, [], 'shared-key');

    expect(DatabaseNotification::count())->toBe(2);
});

test('E15-E/195: a disabled optional category suppresses the notification', function () {
    app(NotificationPreferenceService::class)->update($this->user, ['quest_enabled' => false]);

    $quest = $this->dispatcher->dispatch($this->user, NotificationType::DailyQuestsAvailable, [], 'q');
    $ach = $this->dispatcher->dispatch($this->user, NotificationType::AchievementUnlocked, ['name' => 'أ'], 'a');

    expect($quest)->toBe(DispatchResult::Suppressed)->and($ach)->toBe(DispatchResult::Created)
        ->and(DatabaseNotification::count())->toBe(1);
});

test('E15-F/196/103: the global switch suppresses every optional dispatch', function () {
    $this->settings->set('notifications', 'notifications_enabled', false);

    foreach ([NotificationType::AchievementUnlocked, NotificationType::StreakAtRisk, NotificationType::DailyQuestsAvailable] as $i => $type) {
        expect($this->dispatcher->dispatch($this->user, $type, ['name' => 'أ', 'streak' => 4], "k-{$i}"))->toBe(DispatchResult::Suppressed);
    }

    expect(DatabaseNotification::count())->toBe(0);
});

test('E15-211/17/106: security notices are mandatory - they bypass both the global switch and user preferences', function () {
    $this->settings->set('notifications', 'notifications_enabled', false);
    app(NotificationPreferenceService::class)->update($this->user, ['quest_enabled' => false, 'streak_enabled' => false, 'achievement_enabled' => false]);

    $result = $this->dispatcher->dispatch($this->user, NotificationType::SecurityPasswordReset, [], 'sec-1');

    expect($result)->toBe(DispatchResult::Created)->and($this->user->notifications()->count())->toBe(1);
});

test('E15-147: without a preference row the safe defaults apply (everything enabled) and reading does not create a row', function () {
    $prefs = app(NotificationPreferenceService::class);

    foreach (NotificationCategory::cases() as $category) {
        expect($prefs->isEnabled($this->user, $category))->toBeTrue();
    }

    expect(DB::table('notification_preferences')->count())->toBe(0);
});

test('E15-35/37: stored payload is structured, plain text, with an internal registry action only', function () {
    $this->dispatcher->dispatch($this->user, NotificationType::AchievementUnlocked, ['name' => '<b>إنجاز</b>'], 'p-1', ['achievement_id' => 7]);

    $row = $this->user->notifications()->first();

    expect(array_keys($row->data))->toEqualCanonicalizing(['type', 'category', 'title', 'body', 'icon', 'action_route', 'action_params', 'idempotency_key', 'refs'])
        ->and($row->data['action_route'])->toBe('progress.show')
        ->and($row->data['action_params'])->toBe([])
        ->and($row->type_key)->toBe('achievement_unlocked')->and($row->category)->toBe('achievement')
        ->and(str_contains(json_encode($row->data), 'http'))->toBeFalse();
});

test('E15-212/24/27: a failing dispatch never throws to the caller (returns Failed)', function () {
    $unsaved = new User(['name' => 'x']); // بلا id → الإدراج يفشل

    $result = $this->dispatcher->dispatch($unsaved, NotificationType::AchievementUnlocked, ['name' => 'أ'], 'f-1');

    expect($result)->toBe(DispatchResult::Failed);
});

test('E15-216/166: in-app notifications work without any SMTP configuration', function () {
    config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.invalid.example', 'mail.mailers.smtp.port' => 1]);

    expect($this->dispatcher->dispatch($this->user, NotificationType::AchievementUnlocked, ['name' => 'أ'], 'm-1'))->toBe(DispatchResult::Created);
});

test('E15-217/167: no queue worker is needed - the row is written synchronously and no job is queued', function () {
    config(['queue.default' => 'database']);

    $this->dispatcher->dispatch($this->user, NotificationType::AchievementUnlocked, ['name' => 'أ'], 'qw-1');

    expect($this->user->unreadNotifications()->count())->toBe(1)->and(DB::table('jobs')->count())->toBe(0);
});

test('url resolver: only registry routes with scalar params become relative internal URLs', function () {
    $resolver = app(NotificationUrlResolver::class);

    expect($resolver->resolve(['type' => 'daily_quests_available', 'action_route' => 'quests.show']))->toBe('/quests')
        ->and($resolver->resolve(['type' => 'daily_quests_available', 'action_route' => 'wallet.index']))->toBeNull()
        ->and($resolver->resolve(['type' => 'daily_quests_available', 'action_route' => 'https://evil.example']))->toBeNull()
        ->and($resolver->resolve(['type' => 'nonexistent', 'action_route' => 'quests.show']))->toBeNull()
        ->and($resolver->resolve(['type' => 'daily_quests_available', 'action_route' => 'quests.show', 'action_params' => 'x']))->toBeNull()
        ->and($resolver->resolve(['type' => 'daily_quests_available', 'action_route' => 'quests.show', 'action_params' => [['a']]]))->toBeNull()
        ->and($resolver->resolve([]))->toBeNull();
});

test('E15-121/122: analytics are aggregate only - created, read and read-rate (no click-through claimed)', function () {
    e15Note($this->user, ['type' => NotificationType::AchievementUnlocked, 'read_at' => now()]);
    e15Note($this->user, ['type' => NotificationType::AchievementUnlocked]);
    e15Note($this->user, ['type' => NotificationType::StreakAtRisk]);

    $overview = app(NotificationAnalyticsService::class)->overview();

    expect($overview['created'])->toBe(3)->and($overview['read'])->toBe(1)->and($overview['read_rate'])->toBe(0.3333)
        ->and($overview['by_type']['achievement_unlocked'])->toBe(['created' => 2, 'read' => 1, 'read_rate' => 0.5])
        ->and($overview['by_type']['streak_at_risk']['read_rate'])->toBe(0.0)
        ->and(json_encode($overview))->not->toContain('user');

    expect(app(NotificationAnalyticsService::class)->overview(now()->addDay())['created'])->toBe(0);
});
