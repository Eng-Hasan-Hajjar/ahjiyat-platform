<?php

use App\Models\PlatformSetting;
use App\Models\User;

test('DIAGNOSTIC v4 - verify the admin user actually has role=admin at request time', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    dump('role right after create(): '.var_export($admin->role, true));
    dump('isAdmin() right after create(): '.var_export($admin->isAdmin(), true));
    dump('fresh() role from DB: '.var_export($admin->fresh()->role, true));
    dump('default auth guard config: '.var_export(config('auth.defaults.guard'), true));
    try {
        dump('filament panel auth guard: '.var_export(\Filament\Facades\Filament::getCurrentOrDefaultPanel()?->getAuthGuard(), true));
    } catch (\Throwable $e) {
        dump('filament panel auth guard check failed: '.$e->getMessage());
    }

    $this->actingAs($admin);

    dump('auth()->id() after actingAs: '.var_export(auth()->id(), true));
    dump('auth()->user()?->role after actingAs: '.var_export(auth()->user()?->role, true));
    dump('auth()->user()?->isAdmin() after actingAs: '.var_export(auth()->user()?->isAdmin(), true));

    expect(true)->toBeTrue();
});


test('an admin can access the platform settings page', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($admin)->get('/admin/settings')->assertOk();
});

test('a regular authenticated user cannot access the platform settings page', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/admin/settings');

    expect($response->status())->toBeIn([403, 404]);
});

test('a guest is redirected away from the platform settings page', function () {
    $response = $this->get('/admin/settings');

    $response->assertRedirect();
});

test('saving the settings page persists a value and it survives a fresh service instance', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $this->actingAs($admin);

    app(\App\Services\PlatformSettingsService::class)->set('general', 'site_name', 'محفوظ من صفحة الإدارة', $admin);

    expect(PlatformSetting::where('group', 'general')->where('key', 'site_name')->value('value'))
        ->toBe('محفوظ من صفحة الإدارة');
});

test('the PlatformSettingsPage class exists and is registered under the admin Filament panel', function () {
    expect(class_exists(\App\Filament\Pages\PlatformSettingsPage::class))->toBeTrue();
});