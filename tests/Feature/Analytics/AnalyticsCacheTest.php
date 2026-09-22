<?php

use App\Models\User;
use App\Services\Analytics\UserAnalyticsService;
use App\Support\AnalyticsCache;
use App\Support\AnalyticsPeriod;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
});

test('refreshing analytics does not delete an unrelated cache key', function () {
    Cache::put('unrelated-key', 'keep-me', now()->addHour());

    $admin = User::factory()->create();
    $admin->assignRole('administrator');

    $this->actingAs($admin);
    $page = \Livewire\Livewire::test(\App\Filament\Pages\AnalyticsCenter::class)->instance();
    $page->refreshData();

    expect(Cache::get('unrelated-key'))->toBe('keep-me');
});

test('refreshing analytics bumps the version so a previously cached value is no longer served', function () {
    $period = AnalyticsPeriod::fromPreset('last_30_days');

    User::factory()->create();
    $before = app(UserAnalyticsService::class)->overview($period)['total_users'];
    expect($before)->toBe(1);

    User::factory()->create();

    $admin = User::factory()->create();
    $admin->assignRole('administrator');
    $this->actingAs($admin);
    \Livewire\Livewire::test(\App\Filament\Pages\AnalyticsCenter::class)->instance()->refreshData();

    $after = app(UserAnalyticsService::class)->overview($period)['total_users'];

    expect($after)->toBe(3);
});

test('the cache version helper only ever increases and never collides across calls', function () {
    $v1 = AnalyticsCache::version();
    AnalyticsCache::bumpVersion();
    $v2 = AnalyticsCache::version();
    AnalyticsCache::bumpVersion();
    $v3 = AnalyticsCache::version();

    expect($v2)->toBeGreaterThan($v1)
        ->and($v3)->toBeGreaterThan($v2);
});

test('analytics cache keys embed the current version and change when the version bumps', function () {
    $period = AnalyticsPeriod::fromPreset('last_30_days');
    $keyBefore = $period->cacheKey('users.overview');

    AnalyticsCache::bumpVersion();

    $keyAfter = $period->cacheKey('users.overview');

    expect($keyBefore)->not->toBe($keyAfter);
});