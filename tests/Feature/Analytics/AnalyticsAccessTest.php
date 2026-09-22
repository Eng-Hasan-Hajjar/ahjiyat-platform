<?php

use App\Models\Role;
use App\Models\User;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
});

test('super admin can access the Analytics Center', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super-admin');

    $this->actingAs($superAdmin)->get(\App\Filament\Pages\AnalyticsCenter::getUrl())->assertOk();
});

test('a role with analytics.view can access the Analytics Center', function () {
    $admin = User::factory()->create();
    $admin->assignRole('administrator');

    $this->actingAs($admin)->get(\App\Filament\Pages\AnalyticsCenter::getUrl())->assertOk();
});

test('player role is denied access to the Analytics Center', function () {
    $player = User::factory()->create();
    $player->assignRole('player');

    $response = $this->actingAs($player)->get(\App\Filament\Pages\AnalyticsCenter::getUrl());

    expect($response->status())->toBe(403);
});

test('direct URL access is denied for a role with admin.access but no analytics.view', function () {
    $role = Role::create(['name' => 'bare-admin-access', 'guard_name' => 'web']);
    $role->givePermissionTo('admin.access');

    $limited = User::factory()->create();
    $limited->assignRole('bare-admin-access');

    $response = $this->actingAs($limited)->get(\App\Filament\Pages\AnalyticsCenter::getUrl());

    expect($response->status())->toBe(403);
});

test('financial analytics section is hidden for a role lacking analytics.financial', function () {
    $limited = User::factory()->create();
    $limited->assignRole('content-manager');

    $this->actingAs($limited);
    $page = \Livewire\Livewire::test(\App\Filament\Pages\AnalyticsCenter::class)->instance();

    expect($page->economyAnalytics())->toBeNull();
});

test('security analytics section is hidden for a role lacking analytics.security', function () {
    $limited = User::factory()->create();
    $limited->assignRole('content-manager');

    $this->actingAs($limited);
    $page = \Livewire\Livewire::test(\App\Filament\Pages\AnalyticsCenter::class)->instance();

    expect($page->securityAnalytics())->toBeNull();
});

test('export is denied without reports.export permission', function () {
    $limited = User::factory()->create();
    $limited->assignRole('content-manager');

    $this->actingAs($limited);
    $page = \Livewire\Livewire::test(\App\Filament\Pages\AnalyticsCenter::class)->instance();

    expect(fn () => $page->exportUsers())
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});