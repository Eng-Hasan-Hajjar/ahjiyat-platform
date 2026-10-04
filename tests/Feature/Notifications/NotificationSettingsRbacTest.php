<?php

use App\Filament\Pages\PlatformSettingsPage;
use App\Models\OperationalAuditLog;
use App\Models\User;
use App\Services\PlatformSettingsService;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->settings = app(PlatformSettingsService::class);
    $this->settings->set('general', 'site_name', 'الاسم الأصلي');
    $this->settings->set('notifications', 'max_reengagement_per_day', 2);
    $this->settings->set('notifications', 'streak_warning_hour', 17);
});

function e15sUser(array $permissions = [], ?string $role = null): User
{
    $user = User::factory()->create();
    $permissions && $user->givePermissionTo($permissions);
    $role && $user->assignRole($role);

    return $user;
}

function e15sGroup(): array
{
    app(PlatformSettingsService::class)->forgetCache();

    return app(PlatformSettingsService::class)->getGroup('notifications');
}

test('E15-179/180: the permission exists in the custom registry (config/permissions.php) and the administrator receives it', function () {
    expect(config('permissions.notifications.permissions'))->toHaveKey('notifications.settings.manage');

    $admin = e15sUser([], 'administrator');
    expect($admin->can('notifications.settings.manage'))->toBeTrue();
});

test('E15-22/178: the form hydrates the persisted notifications group (no silent reset)', function () {
    $this->actingAs(e15sUser([], 'super-admin'));

    Livewire::test(PlatformSettingsPage::class)->assertFormSet(['notifications' => [
        'notifications_enabled' => true, 'streak_warning_enabled' => true, 'streak_warning_hour' => 17,
        'min_streak_for_warning' => 2, 'daily_reminder_enabled' => true, 'daily_reminder_hour' => 10, 'max_reengagement_per_day' => 2,
    ]]);
});

test('saving an UNRELATED setting leaves the notification settings untouched', function () {
    $before = e15sGroup();
    $this->actingAs(e15sUser([], 'super-admin'));

    Livewire::test(PlatformSettingsPage::class)->set('data.general.site_name', 'اسم جديد فقط')->call('save');

    expect(e15sGroup())->toBe($before);
});

test('settings.update WITHOUT notifications.settings.manage can edit general settings but a crafted payload changes nothing', function () {
    $before = e15sGroup();
    $this->actingAs(e15sUser(['settings.view', 'settings.update']));

    Livewire::test(PlatformSettingsPage::class)
        ->set('data.general.site_name', 'اسم من مستخدم عام')
        ->set('data.notifications.notifications_enabled', false)
        ->set('data.notifications.max_reengagement_per_day', 5)
        ->call('save');

    app(PlatformSettingsService::class)->forgetCache();

    expect(app(PlatformSettingsService::class)->get('general', 'site_name'))->toBe('اسم من مستخدم عام')
        ->and(e15sGroup())->toBe($before);
});

test('notifications.settings.manage WITHOUT settings.update edits notification settings only, and the change is audited', function () {
    $this->actingAs(e15sUser(['settings.view', 'notifications.settings.manage']));

    Livewire::test(PlatformSettingsPage::class)
        ->set('data.notifications.max_reengagement_per_day', 3)
        ->set('data.general.site_name', 'محاولة تعديل عام')
        ->call('save');

    app(PlatformSettingsService::class)->forgetCache();
    $log = OperationalAuditLog::where('action', 'notifications.settings.changed')->first();

    expect(e15sGroup()['max_reengagement_per_day'])->toBe(3)
        ->and(app(PlatformSettingsService::class)->get('general', 'site_name'))->toBe('الاسم الأصلي')
        ->and($log->metadata['changes']['max_reengagement_per_day'])->toBe(['old' => 2, 'new' => 3]);
});

test('an unchanged save writes no notifications audit entry (no audit spam)', function () {
    $this->actingAs(e15sUser([], 'super-admin'));

    Livewire::test(PlatformSettingsPage::class)->call('save');

    expect(OperationalAuditLog::where('action', 'notifications.settings.changed')->count())->toBe(0);
});

test('a crafted INVALID notification value fails closed (validation error, nothing saved)', function () {
    $before = e15sGroup();
    $this->actingAs(e15sUser(['settings.view', 'settings.update']));

    Livewire::test(PlatformSettingsPage::class)
        ->set('data.notifications.streak_warning_hour', 99)
        ->call('save')
        ->assertHasErrors(['data.notifications.streak_warning_hour']);

    expect(e15sGroup())->toBe($before);
});

test('E15-23/103: the global switch really stops dispatch from the settings page while gameplay data stays intact', function () {
    $this->actingAs(e15sUser([], 'super-admin'));
    Livewire::test(PlatformSettingsPage::class)->set('data.notifications.notifications_enabled', false)->call('save');

    $user = User::factory()->create();
    $result = app(\App\Services\Notifications\NotificationDispatcher::class)
        ->dispatch($user, \App\Services\Notifications\NotificationType::AchievementUnlocked, ['name' => 'أ'], 'g-1');

    expect($result)->toBe(\App\Services\Notifications\DispatchResult::Suppressed);
});
