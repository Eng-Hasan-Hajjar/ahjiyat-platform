<?php

require_once __DIR__.'/NotificationTestHelpers.php';

use App\Models\User;
use App\Services\Notifications\NotificationPreferenceService;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->other = User::factory()->create();
});

test('E15-18/21: the preferences page is clear, shows every optional category enabled by default, and marks security as mandatory', function () {
    $html = $this->actingAs($this->user)->get(route('notifications.preferences'))->assertOk()
        ->assertSee('الإنجازات')->assertSee('السلسلة اليومية')->assertSee('المهام اليومية')->assertSee('إلزامي')->getContent();

    expect(substr_count($html, 'type="checkbox"'))->toBe(3)
        ->and(preg_match_all('/type="checkbox"[^>]*checked/', $html))->toBe(3)
        ->and(\DB::table('notification_preferences')->count())->toBe(0); // العرض لا يُنشئ صفًا
});

test('E15-15/145/146: saving preferences persists one row per user and leaves other users untouched', function () {
    $this->actingAs($this->user)->put(route('notifications.preferences.update'), [
        'quest_enabled' => '0', 'streak_enabled' => '1', 'achievement_enabled' => '0',
    ])->assertRedirect(route('notifications.preferences'));

    $this->put(route('notifications.preferences.update'), ['quest_enabled' => '0', 'streak_enabled' => '1', 'achievement_enabled' => '0']);

    $prefs = app(NotificationPreferenceService::class);

    expect(\DB::table('notification_preferences')->where('user_id', $this->user->id)->count())->toBe(1)
        ->and($prefs->forUser($this->user)->only(['quest_enabled', 'streak_enabled', 'achievement_enabled']))
        ->toBe(['quest_enabled' => false, 'streak_enabled' => true, 'achievement_enabled' => false])
        ->and($prefs->forUser($this->other)->quest_enabled)->toBeTrue();
});

test('E15-16/17/18: security cannot be disabled - a crafted security flag is ignored', function () {
    $this->actingAs($this->user)->put(route('notifications.preferences.update'), [
        'security_enabled' => '0', 'security_notifications_enabled' => '0', 'quest_enabled' => '0',
    ])->assertRedirect();

    $prefs = app(NotificationPreferenceService::class);

    expect(\Schema::hasColumn('notification_preferences', 'security_enabled'))->toBeFalse()
        ->and($prefs->isEnabled($this->user, \App\Services\Notifications\NotificationCategory::Security))->toBeTrue()
        ->and($prefs->forUser($this->user)->quest_enabled)->toBeFalse();
});

test('a partial request only changes the fields it carries', function () {
    app(NotificationPreferenceService::class)->update($this->user, ['quest_enabled' => false]);

    $this->actingAs($this->user)->put(route('notifications.preferences.update'), ['streak_enabled' => '0'])->assertRedirect();

    $pref = app(NotificationPreferenceService::class)->forUser($this->user);

    expect($pref->quest_enabled)->toBeFalse()->and($pref->streak_enabled)->toBeFalse()->and($pref->achievement_enabled)->toBeTrue();
});

test('E15-21: the profile page links to the preferences', function () {
    $this->actingAs($this->user)->get(route('profile.edit'))->assertOk()->assertSee(route('notifications.preferences'), false);
});

test('E15-103: when notifications are globally disabled the preferences page says so honestly', function () {
    app(\App\Services\PlatformSettingsService::class)->set('notifications', 'notifications_enabled', false);

    $this->actingAs($this->user)->get(route('notifications.preferences'))->assertOk()->assertSee('معطَّلة حاليًا من إدارة المنصة');
});
